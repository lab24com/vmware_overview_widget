<?php declare(strict_types = 0);

namespace Modules\VmwareOverview\Actions;

use API, CController, CControllerResponseData;

/**
 * Ejecuta ping, traceroute o nmap desde el servidor del frontend contra la interfaz principal de un host visible.
 *
 * Seguridad: la acción exige POST con el token CSRF nativo de Zabbix (CCsrfTokenHelper, por acción), por lo que
 * otra página no puede provocar ejecuciones usando la sesión del usuario. El escaneo de puertos queda reservado a
 * usuarios con rol Admin o Super admin.
 */
class Diagnostics extends CController {
	protected function checkPermissions(): bool { return $this->getUserType() >= USER_TYPE_ZABBIX_USER; }
	protected function checkInput(): bool {
		$valid = $this->validateInput(['hostid' => 'int32|required', 'operation' => 'string|required']);
		if (!$valid) $this->sendJson(['error' => implode(' ', array_column(get_and_clear_messages(), 'message'))]);
		return $valid;
	}

	protected function doAction(): void {
		$hostid = (string) $this->getInput('hostid');
		$operation = strtolower(trim((string) $this->getInput('operation')));
		if (!in_array($operation, ['ping', 'traceroute', 'nmap'], true)) {
			$this->sendJson(['error' => 'Operación de diagnóstico no permitida.']);
			return;
		}
		if ($operation === 'nmap' && $this->getUserType() < USER_TYPE_ZABBIX_ADMIN) {
			$this->sendJson(['error' => 'El escaneo de puertos requiere un rol Admin o Super admin en Zabbix.']);
			return;
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'name'], 'hostids' => [$hostid],
			'selectInterfaces' => ['ip', 'dns', 'useip', 'main']
		]);
		if (!$hosts) {
			$this->sendJson(['error' => 'Host no encontrado o sin permisos de lectura.']);
			return;
		}

		$target = $this->hostAddress($hosts[0]['interfaces'] ?? []);
		if ($target === '' || !$this->validTarget($target)) {
			$this->sendJson(['error' => 'El host no tiene una dirección IP o DNS válida para ejecutar la prueba.']);
			return;
		}

		$definition = match ($operation) {
			'ping' => [['/usr/bin/ping', '/bin/ping'], ['-c', '4', '-W', '2', $target], 15],
			'traceroute' => [['/usr/bin/traceroute', '/bin/traceroute'], ['-m', '15', '-w', '2', $target], 35],
			'nmap' => [['/usr/bin/nmap', '/bin/nmap'], ['-sT', '-Pn', '--top-ports', '30', '--host-timeout', '20s', $target], 30]
		};
		$binary = $this->firstExecutable($definition[0]);
		if ($binary === null) {
			$this->sendJson([
				'error' => 'La herramienta '.$operation.' no está instalada o no es ejecutable por el usuario del frontend.',
				'setup_required' => true
			]);
			return;
		}

		try {
			$result = $this->runCommand(array_merge([$binary], $definition[1]), $definition[2]);
		}
		catch (\Throwable $e) {
			$this->sendJson(['error' => $e->getMessage(), 'setup_required' => true]);
			return;
		}
		$this->sendJson([
			'host' => $hosts[0]['name'], 'target' => $target, 'operation' => $operation,
			'command' => basename($binary).' '.implode(' ', array_map('escapeshellarg', $definition[1])),
			'output' => $result['output'], 'exit_code' => $result['exit_code'],
			'duration' => $result['duration'], 'timed_out' => $result['timed_out']
		]);
	}

	private function hostAddress(array $interfaces): string {
		usort($interfaces, static fn(array $a, array $b): int => (int) ($b['main'] ?? 0) <=> (int) ($a['main'] ?? 0));
		foreach ($interfaces as $interface) {
			$value = (int) ($interface['useip'] ?? 1) === 1 ? ($interface['ip'] ?? '') : ($interface['dns'] ?? '');
			if (trim((string) $value) !== '') return trim((string) $value);
		}
		return '';
	}

	private function validTarget(string $target): bool {
		return filter_var($target, FILTER_VALIDATE_IP) !== false
			|| preg_match('/^(?=.{1,253}$)(?!-)[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/', $target) === 1;
	}

	private function firstExecutable(array $paths): ?string {
		foreach ($paths as $path) if (is_file($path) && is_executable($path)) return $path;
		return null;
	}

	private function runCommand(array $command, int $timeout): array {
		if (!function_exists('proc_open')) throw new \RuntimeException('PHP tiene deshabilitada la función proc_open.');
		$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
		if (!is_resource($process)) throw new \RuntimeException('No se pudo iniciar la herramienta de diagnóstico.');
		foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
		$started = microtime(true); $output = ''; $timed_out = false; $observed_exit = null;
		do {
			$output .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
			$status = proc_get_status($process);
			if (!$status['running']) { $observed_exit = (int) $status['exitcode']; break; }
			if (microtime(true) - $started >= $timeout) {
				$timed_out = true; proc_terminate($process, 15); break;
			}
			usleep(100000);
		} while (true);
		$output .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
		foreach ($pipes as $pipe) fclose($pipe);
		$exit_code = proc_close($process);
		if ($exit_code === -1 && $observed_exit !== null) $exit_code = $observed_exit;
		return [
			'output' => mb_substr(trim($output), 0, 32768), 'exit_code' => $exit_code,
			'duration' => round(microtime(true) - $started, 2), 'timed_out' => $timed_out
		];
	}

	private function sendJson(array $data): void {
		$this->setResponse(
			(new CControllerResponseData([
				'main_block' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
			]))->disableView()
		);
	}
}
