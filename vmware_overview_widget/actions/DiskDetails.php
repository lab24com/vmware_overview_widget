<?php declare(strict_types = 0);

namespace Modules\VmwareOverview\Actions;

use API,
	CController,
	CControllerResponseData;

class DiskDetails extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function checkInput(): bool {
		$valid = $this->validateInput([
			'hostid' => 'int32|required',
			'fs' => 'string|required',
			'warning' => 'int32|required',
			'critical' => 'int32|required',
			'range' => 'int32'
		]);

		if (!$valid) {
			$this->sendJson([
				'error' => implode(' ', array_column(get_and_clear_messages(), 'message'))
			]);
		}

		return $valid;
	}

	protected function doAction(): void {
		$hostid = $this->getInput('hostid');
		$filesystem = $this->getInput('fs');
		$warning = max(1, min(99, (int) $this->getInput('warning')));
		$critical = max($warning + 1, min(100, (int) $this->getInput('critical')));
		$range = $this->historyRange((int) $this->getInput('range', 3600));

		$hosts = API::Host()->get([
			'output' => ['hostid', 'name'], 'hostids' => [$hostid],
			'selectInterfaces' => ['ip', 'dns', 'useip', 'main'],
			'selectInventory' => ['os', 'os_full', 'type_full']
		]);

		if (!$hosts) {
			$this->sendJson(['error' => 'Host no encontrado o sin permisos de lectura.']);
			return;
		}

		$items = API::Item()->get([
			'output' => [
				'itemid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type', 'state', 'status', 'error'
			],
			'hostids' => [$hostid],
			'search' => ['key_' => 'vmware.vm.vfs.fs.size['],
			'selectTags' => ['tag', 'value'],
			'templated' => false, 'webitems' => true
		]);

		$metrics = [];
		foreach ($items as $item) {
			if (!$this->belongsToFilesystem($item, $filesystem)
					|| !preg_match('/,(free|pfree|pused|total|used)\]$/', $item['key_'], $matches)) {
				continue;
			}

			$metrics[$matches[1]] = $item;
		}

		if (!$metrics) {
			$this->sendJson(['error' => 'No se encontraron ítems de este sistema de archivos.']);
			return;
		}

		$total = $this->numericValue($metrics['total'] ?? null);
		$free = $this->numericValue($metrics['free'] ?? null);
		$used = $this->numericValue($metrics['used'] ?? null);
		$pfree = $this->numericValue($metrics['pfree'] ?? null);
		$pused = $this->numericValue($metrics['pused'] ?? null);

		if ($pused === null && $pfree !== null) {
			$pused = 100 - $pfree;
		}
		if ($pused === null && $total > 0 && $used !== null) {
			$pused = ($used / $total) * 100;
		}
		if ($pfree === null && $pused !== null) {
			$pfree = 100 - $pused;
		}

		$pused = $pused !== null ? max(0, min(100, $pused)) : null;
		$status = $pused === null
			? 'unknown'
			: ($pused >= $critical ? 'critical' : ($pused >= $warning ? 'warning' : 'ok'));

		$rows = [];
		foreach ([
			'total' => 'Capacidad total',
			'used' => 'Espacio utilizado',
			'free' => 'Espacio disponible',
			'pfree' => 'Espacio disponible (%)'
		] as $metric => $label) {
			if (!array_key_exists($metric, $metrics)) {
				continue;
			}

			$item = $metrics[$metric];
			$value = $this->numericValue($item);
			$previous = $this->getPreviousValue($item);
			$change = $value !== null && $previous !== null ? $value - $previous : null;

			$rows[] = [
				'name' => $label,
				'last_check' => (int) $item['lastclock'] > 0
					? zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $item['lastclock'])
					: '—',
				'value' => $this->formatMetric($metric, $value),
				'change' => $this->formatChange($metric, $change),
				'supported' => $this->isAvailable($item),
				'error' => (int) $item['state'] === ITEM_STATE_NOTSUPPORTED ? $item['error'] : ''
			];
		}

		$history_item = $metrics['pused'] ?? ($metrics['pfree'] ?? null);
		$history = $history_item !== null ? $this->getHistory($history_item, $range) : [];
		if ($history_item !== null && array_key_exists('pfree', $metrics) && $history_item['itemid'] === $metrics['pfree']['itemid']) {
			foreach ($history as &$point) {
				$point['value'] = max(0, min(100, 100 - $point['value']));
			}
			unset($point);
		}

		$host = $hosts[0];
		$inventory = $host['inventory'] ?? [];
		$os = ($inventory['os_full'] ?? '') ?: (($inventory['os'] ?? '') ?: (($inventory['type_full'] ?? '') ?: '—'));
		$this->sendJson([
			'host' => $hosts[0]['name'],
			'hostid' => $hostid,
			'address' => $this->getHostAddress($host['interfaces'] ?? []),
			'os' => $os,
			'host_url' => 'zabbix.php?action=host.dashboard.view&hostid='.$hostid,
			'latest_url' => 'zabbix.php?action=latest.view&filter_set=1&filter_show_without_data=1&filter_hostids%5B0%5D='.$hostid,
			'problems_url' => 'zabbix.php?action=problem.view&filter_set=1&filter_show_suppressed=1&filter_hostids%5B0%5D='.$hostid,
			'filesystem' => $filesystem,
			'label' => $this->filesystemLabel($filesystem),
			'status' => $status,
			'status_text' => $this->statusText($status),
			'used_percent' => $pused !== null ? round($pused, 1) : null,
			'free_percent' => $pfree !== null ? round($pfree, 1) : null,
			'warning' => $warning,
			'critical' => $critical,
			'range' => $range,
			'range_label' => $this->rangeLabel($range),
			'rows' => $rows,
			'history' => $history
		]);
	}

	private function belongsToFilesystem(array $item, string $filesystem): bool {
		foreach ($item['tags'] ?? [] as $tag) {
			if ($tag['tag'] === 'filesystem') {
				return $tag['value'] === $filesystem;
			}
		}

		return strpos($item['name'], '['.$filesystem.']') !== false;
	}

	private function getPreviousValue(array $item): ?float {
		if (!$this->isAvailable($item)) {
			return null;
		}

		$values = API::History()->get([
			'output' => ['clock', 'value'],
			'history' => (int) $item['value_type'],
			'itemids' => [$item['itemid']],
			'sortfield' => 'clock',
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => 2
		]);

		return count($values) > 1 && is_numeric($values[1]['value']) ? (float) $values[1]['value'] : null;
	}

	private function getHistory(array $item, int $range): array {
		if (!$this->isAvailable($item)) {
			return [];
		}

		$values = [];
		if ($range > 604800) {
			try {
				$trends = API::Trend()->get([
					'output' => ['clock', 'value_avg'], 'itemids' => [$item['itemid']],
					'time_from' => time() - $range, 'sortfield' => 'clock', 'sortorder' => ZBX_SORT_UP, 'limit' => 1500
				]);
				$values = array_map(static fn(array $row): array => ['clock' => $row['clock'], 'value' => $row['value_avg']], $trends);
			}
			catch (\Throwable $e) {
				$values = [];
			}
		}
		if (!$values) {
			$values = API::History()->get([
				'output' => ['clock', 'value'], 'history' => (int) $item['value_type'], 'itemids' => [$item['itemid']],
				'time_from' => time() - $range, 'sortfield' => 'clock', 'sortorder' => ZBX_SORT_DOWN, 'limit' => 2500
			]);
			$values = array_reverse($values);
		}

		$history = [];
		foreach ($values as $value) {
			if (is_numeric($value['value'])) {
				$history[] = [
					'clock' => (int) $value['clock'],
					'value' => (float) $value['value']
				];
			}
		}

		return $history;
	}

	private function historyRange(int $range): int {
		return in_array($range, [3600, 7200, 10800, 21600, 86400, 604800, 1296000, 2592000], true)
			? $range : 3600;
	}

	private function rangeLabel(int $range): string {
		return [3600 => '1 hora', 7200 => '2 horas', 10800 => '3 horas', 21600 => '6 horas', 86400 => '1 día',
			604800 => '7 días', 1296000 => '15 días', 2592000 => '30 días'][$range] ?? '1 hora';
	}

	private function isAvailable(?array $item): bool {
		return $item !== null
			&& (int) $item['state'] === ITEM_STATE_NORMAL
			&& (int) $item['status'] === ITEM_STATUS_ACTIVE
			&& (int) $item['lastclock'] > 0
			&& $item['lastvalue'] !== '';
	}

	private function numericValue(?array $item): ?float {
		return $this->isAvailable($item) && is_numeric($item['lastvalue'])
			? (float) $item['lastvalue']
			: null;
	}

	private function getHostAddress(array $interfaces): string {
		usort($interfaces, static fn(array $a, array $b): int => (int) $b['main'] <=> (int) $a['main']);
		foreach ($interfaces as $interface) {
			$address = (int) $interface['useip'] === 1 ? $interface['ip'] : $interface['dns'];
			if ($address !== '') return $address;
		}
		return '—';
	}

	private function formatMetric(string $metric, ?float $value): string {
		if ($value === null) {
			return '—';
		}

		return in_array($metric, ['pfree', 'pused'], true)
			? number_format($value, 1, '.', '').' %'
			: $this->formatBytes($value);
	}

	private function formatChange(string $metric, ?float $change): string {
		if ($change === null) {
			return '—';
		}

		$sign = $change > 0 ? '+' : '';
		return in_array($metric, ['pfree', 'pused'], true)
			? $sign.number_format($change, 1, '.', '').' pp'
			: $sign.$this->formatBytes($change);
	}

	private function formatBytes(float $bytes): string {
		$units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
		$index = 0;
		$value = abs($bytes);
		while ($value >= 1024 && $index < count($units) - 1) {
			$value /= 1024;
			$index++;
		}

		$value = $bytes < 0 ? -$value : $value;
		return number_format($value, $index > 1 ? 1 : 0, '.', '').' '.$units[$index];
	}

	private function filesystemLabel(string $filesystem): string {
		return preg_match('/^([A-Za-z]):(?:\\\\)?$/', $filesystem, $matches)
			? strtoupper($matches[1])
			: $filesystem;
	}

	private function statusText(string $status): string {
		return match ($status) {
			'ok' => 'Correcto',
			'warning' => 'Advertencia',
			'critical' => 'Crítico',
			default => 'Sin datos'
		};
	}

	private function sendJson(array $data): void {
		$this->setResponse(
			(new CControllerResponseData([
				'main_block' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
			]))->disableView()
		);
	}
}
