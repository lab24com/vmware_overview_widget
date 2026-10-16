<?php declare(strict_types = 0);

namespace Modules\VmwareOverview\Actions;

use API, CController, CControllerResponseData;

class VmDetails extends CController {
	protected function init(): void { $this->disableCsrfValidation(); }
	protected function checkPermissions(): bool { return $this->getUserType() >= USER_TYPE_ZABBIX_USER; }
	protected function checkInput(): bool {
		$valid = $this->validateInput([
			'hostid' => 'int32|required', 'warning' => 'int32', 'critical' => 'int32', 'range' => 'int32',
			'resource_warning' => 'int32', 'resource_critical' => 'int32'
		]);
		if (!$valid) $this->sendJson(['error' => implode(' ', array_column(get_and_clear_messages(), 'message'))]);
		return $valid;
	}

	protected function doAction(): void {
		$hostid = $this->getInput('hostid');
		$warning = max(1, min(99, (int) $this->getInput('warning', 80)));
		$critical = max($warning + 1, min(100, (int) $this->getInput('critical', 90)));
		$range = $this->historyRange((int) $this->getInput('range', 3600));
		$resource_warning = max(1, min(99, (int) $this->getInput('resource_warning', 75)));
		$resource_critical = max($resource_warning + 1, min(100, (int) $this->getInput('resource_critical', 90)));
		$hosts = API::Host()->get([
			'output' => ['hostid', 'name', 'status', 'maintenance_status'], 'hostids' => [$hostid],
			'selectInterfaces' => ['ip', 'dns', 'useip', 'main'],
			'selectInventory' => ['os', 'os_full', 'type_full'], 'selectHostGroups' => ['name']
		]);
		if (!$hosts) { $this->sendJson(['error' => 'Host no encontrado o sin permisos de lectura.']); return; }

		$items = API::Item()->get([
			'output' => ['itemid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type', 'state', 'status', 'error'],
			'hostids' => [$hostid], 'search' => ['key_' => 'vmware.'], 'selectTags' => ['tag', 'value'], 'templated' => false, 'webitems' => true
		]);

		$host = $hosts[0];
		$inventory = $host['inventory'] ?? [];
		$os = ($inventory['os_full'] ?? '') ?: (($inventory['os'] ?? '') ?: (($inventory['type_full'] ?? '') ?: '—'));
		foreach ($items as $item) {
			$key = mb_strtolower((string) $item['key_']);
			if (str_starts_with($key, 'vmware.vm.property[') && str_contains($key, 'guest.guestfullname') && $this->isAvailable($item)) {
				$os = (string) $item['lastvalue'];
				break;
			}
		}
		$cpu_item = $this->findItem($items, 'vmware.vm.cpu.usage.perf[');
		$memory_item = $this->findItem($items, 'vmware.vm.memory.usage[');
		$memory_total = $this->numberValue($this->findItem($items, 'vmware.vm.memory.size['));
		$memory_guest_item = $this->findItem($items, 'vmware.vm.memory.size.usage.guest[');
		$memory_guest = $this->numberValue($memory_guest_item);
		$memory_percent = $memory_total > 0 && $memory_guest !== null ? $memory_guest / $memory_total * 100 : $this->numberValue($memory_item);
		$power = $this->normalizedValue($this->findItem($items, 'vmware.vm.powerstate['));
		$storage_committed = $this->numberValue($this->findItem($items, 'vmware.vm.storage.committed['));
		$storage_uncommitted = $this->numberValue($this->findItem($items, 'vmware.vm.storage.uncommitted['));
		$uptime = $this->numberValue($this->findItem($items, 'vmware.vm.guest.osuptime['))
			?? $this->numberValue($this->findItem($items, 'vmware.vm.uptime['));
		$tools_status_item = $this->findToolsItem($items, 'status');
		$tools_version_item = $this->findToolsItem($items, 'version');
		$tools_value = $this->toolsValue($tools_status_item);
		$tools_inferred = false;
		if ($tools_value === null && $this->hasGuestToolsEvidence($items)) {
			$tools_value = 2.0;
			$tools_inferred = true;
		}
		$snapshot_count = $this->snapshotCount($items);
		$address = $this->getHostAddress($host['interfaces'] ?? []);

		$lastclock = 0; $supported = 0;
		foreach ($items as $item) { $lastclock = max($lastclock, (int) $item['lastclock']); $supported += $this->isAvailable($item) ? 1 : 0; }
		$disks = $this->getDisks($items, $warning, $critical);
		$problems = $this->getProblems((string) $hostid, (string) $host['name']);
		$problems = array_merge($problems, $this->getResourceAlerts(
			$this->numberValue($cpu_item),
			$memory_percent,
			$disks,
			$lastclock,
			(float) $resource_warning,
			(float) $resource_critical
		));
		$consolidation = $this->normalizedValue($this->findItem($items, 'vmware.vm.consolidationneeded['));
		$hv_maintenance = $this->normalizedValue($this->findItem($items, 'vmware.vm.hv.maintenance['));
		$cpu_ready = $this->numberValue($this->findItem($items, 'vmware.vm.cpu.readiness['));
		usort($problems, static fn(array $a, array $b): int => ((int) $b['severity'] <=> (int) $a['severity'])
			?: strnatcasecmp($a['name'], $b['name']));

		$this->sendJson([
			'hostid' => $hostid, 'name' => $host['name'], 'address' => $address,
			'os' => $os, 'groups' => array_values(array_column($host['hostgroups'] ?? [], 'name')),
			'host_url' => 'zabbix.php?action=host.dashboard.view&hostid='.$hostid,
			'latest_url' => 'zabbix.php?action=latest.view&filter_set=1&filter_show_without_data=1&filter_hostids%5B0%5D='.$hostid,
			'problems_url' => 'zabbix.php?action=problem.view&filter_set=1&filter_show_suppressed=1&filter_hostids%5B0%5D='.$hostid,
			'enabled' => (int) $host['status'] === HOST_STATUS_MONITORED,
			'maintenance' => (int) $host['maintenance_status'] === HOST_MAINTENANCE_STATUS_ON,
			'item_count' => count($items), 'supported_count' => $supported,
			'last_update' => $lastclock > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $lastclock) : '—',
			'summary' => [
				'power' => $this->powerText($power), 'power_class' => $this->powerClass($power),
				'cpu' => $this->formatPercent($this->numberValue($cpu_item)), 'cpu_raw' => $this->numberValue($cpu_item),
				'vcpu' => $this->formatInteger($this->numberValue($this->findItem($items, 'vmware.vm.cpu.num['))),
				'memory' => $this->formatPercent($memory_percent), 'memory_raw' => $memory_percent,
				'memory_total' => $this->formatBytes($memory_total),
				'storage_committed' => $this->formatBytes($storage_committed),
				'storage_provisioned' => $this->formatBytes($storage_committed !== null || $storage_uncommitted !== null ? ($storage_committed ?? 0) + ($storage_uncommitted ?? 0) : null),
				'hypervisor' => $this->textValue($this->findItem($items, 'vmware.vm.hv.name[')),
				'cluster' => $this->textValue($this->findItem($items, 'vmware.vm.cluster.name[')),
				'datacenter' => $this->textValue($this->findItem($items, 'vmware.vm.datacenter.name[')),
				'tools' => $tools_inferred ? 'Activo (inferido)' : $this->toolsTextValue($tools_value),
				'tools_version' => $this->textValue($tools_version_item),
				'uptime' => $this->formatUptime($uptime),
				'snapshots' => $this->formatInteger($snapshot_count),
				'snapshot_latest' => $this->textValue($this->findItem($items, 'vmware.vm.snapshot.latestdate')),
				// Mapa "VMware Consolidation" de la plantilla: 0 = True, 1 = False, 2 = Unknown.
				'consolidation' => match ($consolidation) {
					'0', 'true' => 'Requerida', '1', 'false' => 'No requerida', default => '—'
				},
				'consolidation_needed' => in_array($consolidation, ['0', 'true'], true),
				'hv_maintenance' => match ($hv_maintenance) {'1' => 'Sí', '0' => 'No', default => '—'},
				'cpu_ready' => $this->formatPercent($cpu_ready), 'cpu_ready_raw' => $cpu_ready,
				'memory_ballooned' => $this->formatBytes($this->numberValue($this->findItem($items, 'vmware.vm.memory.size.ballooned['))),
				'memory_swapped' => $this->formatBytes($this->numberValue($this->findItem($items, 'vmware.vm.memory.size.swapped['))),
				'memory_guest' => $this->formatBytes($memory_guest)
			],
			'range' => $range, 'range_label' => $this->rangeLabel($range),
			'disk_warning' => $warning, 'disk_critical' => $critical,
			'resource_warning' => $resource_warning, 'resource_critical' => $resource_critical,
			'disks' => $disks, 'networks' => $this->getNetworks($items, $range),
			'problems' => $problems,
			'history' => [
				'cpu' => $this->getHistory($cpu_item, $range),
				// Mismo criterio que la tabla: memoria usada por el invitado / memoria configurada. Si la plantilla
				// no entrega el uso invitado se recurre a vmware.vm.memory.usage (porcentaje sobre el host).
				'memory' => $memory_total > 0 && $memory_guest_item !== null && $memory_guest !== null
					? $this->getPercentHistory($memory_guest_item, $memory_total, $range)
					: $this->getHistory($memory_item, $range),
				'memory_source' => $memory_total > 0 && $memory_guest !== null ? 'guest' : 'host'
			],
			'technical' => $this->getTechnicalItems($items)
		]);
	}

	private function getDisks(array $items, int $warning, int $critical): array {
		$disks = [];
		foreach ($items as $item) {
			if (!str_starts_with($item['key_'], 'vmware.vm.vfs.fs.size[') || !preg_match('/,(free|pfree|total|used)\]$/', $item['key_'], $metric)) continue;
			$name = $this->tagValue($item, 'filesystem');
			if ($name === '' && preg_match('/\[([^\]]+)\]/', $item['name'], $match)) $name = $match[1];
			if ($name === '') continue;
			$disks[$name]['name'] = $name; $disks[$name][$metric[1]] = $this->numberValue($item);
			$disks[$name]['lastclock'] = max($disks[$name]['lastclock'] ?? 0, (int) $item['lastclock']);
		}
		$result = [];
		foreach ($disks as $disk) {
			$pfree = $disk['pfree'] ?? null;
			if ($pfree === null && ($disk['total'] ?? 0) > 0 && isset($disk['free'])) $pfree = $disk['free'] / $disk['total'] * 100;
			$pused = $pfree !== null ? max(0, min(100, 100 - $pfree)) : null; $path = $disk['name'];
			$primary = preg_match('/^[A-Za-z]:[\\\\\/]?$/', $path) === 1 || $path === '/' || preg_match('#^/(boot|home|opt|srv|tmp|usr|var)/?$#', $path) === 1;
			$result[] = [
				'name' => $path, 'label' => preg_match('/^([A-Za-z]):/', $path, $label) ? strtoupper($label[1]) : $path,
				'primary' => $primary, 'total' => $this->formatBytes($disk['total'] ?? null),
				'used' => $this->formatBytes($disk['used'] ?? (($disk['total'] ?? null) !== null && ($disk['free'] ?? null) !== null ? $disk['total'] - $disk['free'] : null)),
				'free' => $this->formatBytes($disk['free'] ?? null), 'used_percent' => $this->formatPercent($pused), 'used_percent_raw' => $pused,
				'status' => $pused === null ? 'unknown' : ($pused >= $critical ? 'critical' : ($pused >= $warning ? 'warning' : 'ok')),
				'last_update' => ($disk['lastclock'] ?? 0) > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $disk['lastclock']) : '—'
			];
		}
		usort($result, static fn(array $a, array $b): int => ($b['primary'] <=> $a['primary']) ?: strnatcasecmp($a['label'], $b['label']));
		return $result;
	}

	private function getNetworks(array $items, int $range): array {
		$interfaces = [];
		foreach ($items as $item) {
			if (!str_starts_with($item['key_'], 'vmware.vm.net.if.') || str_contains($item['key_'], '.discovery[')) continue;
			$name = $this->tagValue($item, 'interface') ?: $this->tagValue($item, 'network');
			if ($name === '' && preg_match('/\[[^,]+,[^,]+,([^,\]]+)/', $item['key_'], $match)) $name = trim($match[1], '"');
			if ($name === '') $name = 'Interfaz '.(count($interfaces) + 1);
			$metric = str_contains($item['key_'], '.in[') ? 'received' : (str_contains($item['key_'], '.out[') ? 'sent' : (str_contains($item['key_'], '.usage[') ? 'usage' : 'other'));
			if ($metric !== 'other') {
				$interfaces[$name][$metric] = $this->formatValue($item);
				$interfaces[$name][$metric.'_history'] = $this->getHistory($item, $range);
				if ($metric !== 'usage') $interfaces[$name]['units'] = (string) $item['units'];
			}
			$interfaces[$name]['name'] = $name; $interfaces[$name]['lastclock'] = max($interfaces[$name]['lastclock'] ?? 0, (int) $item['lastclock']);
		}
		$result = [];
		foreach ($interfaces as $interface) $result[] = [
			'name' => $interface['name'], 'received' => $interface['received'] ?? '—', 'sent' => $interface['sent'] ?? '—', 'usage' => $interface['usage'] ?? '—',
			'received_history' => $interface['received_history'] ?? [], 'sent_history' => $interface['sent_history'] ?? [],
			'usage_history' => $interface['usage_history'] ?? [], 'units' => $interface['units'] ?? 'Bps',
			'last_update' => ($interface['lastclock'] ?? 0) > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $interface['lastclock']) : '—'
		];
		usort($result, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name'])); return $result;
	}

	private function getProblems(string $hostid, string $entity): array {
		$problems = [];
		try {
			$rows = API::Problem()->get([
				'output' => ['eventid', 'name', 'severity', 'clock', 'acknowledged'],
				'hostids' => [$hostid], 'source' => EVENT_SOURCE_TRIGGERS, 'object' => EVENT_OBJECT_TRIGGER,
				'recent' => false, 'sortfield' => ['severity', 'clock'],
				'sortorder' => 'DESC', 'limit' => 50
			]);
			foreach ($rows as $row) $problems[(string) $row['eventid']] = $row;
		}
		catch (\Throwable $e) {
			// Compatibility fallback for installations where problem.get is restricted by the role.
		}
		try {
			$alarm_items = API::Item()->get([
				'output' => ['hostid'], 'search' => ['key_' => 'vmware.alarms.get['], 'templated' => false, 'webitems' => true
			]);
			$alarm_hostids = array_values(array_unique(array_column($alarm_items, 'hostid')));
			if ($alarm_hostids) {
				$rows = API::Problem()->get([
					'output' => ['eventid', 'name', 'severity', 'clock', 'acknowledged'],
					'hostids' => $alarm_hostids, 'source' => EVENT_SOURCE_TRIGGERS,
					'object' => EVENT_OBJECT_TRIGGER, 'recent' => false,
					'selectTags' => ['tag', 'value']
				]);
				$aliases = [mb_strtolower($entity)];
				if (filter_var($entity, FILTER_VALIDATE_IP) === false) $aliases[] = mb_strtolower(explode('.', $entity, 2)[0]);
				foreach ($rows as $row) {
					$haystack = mb_strtolower((string) $row['name']);
					foreach ($row['tags'] ?? [] as $tag) $haystack .= ' '.mb_strtolower((string) $tag['value']);
					foreach ($aliases as $alias) {
						if ($alias !== '' && preg_match('/(?<![\p{L}\p{N}_-])'.preg_quote($alias, '/').'(?![\p{L}\p{N}_-])/u', $haystack) === 1) {
							$problems[(string) $row['eventid']] = $row;
							break;
						}
					}
				}
			}
		}
		catch (\Throwable $e) {}

		if ($problems) {
			$rows = array_values($problems);
			usort($rows, static fn(array $a, array $b): int => ((int) $b['severity'] <=> (int) $a['severity']) ?: ((int) $b['clock'] <=> (int) $a['clock']));
			return array_map(static fn(array $row): array => [
				'name' => $row['name'], 'severity' => (int) $row['severity'],
				'since' => zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $row['clock']),
				'acknowledged' => (int) ($row['acknowledged'] ?? 0) === 1, 'source' => 'Zabbix'
			], array_slice($rows, 0, 50));
		}

		try {
			$rows = API::Trigger()->get([
				'output' => ['triggerid', 'description', 'priority', 'lastchange'], 'hostids' => [$hostid],
				'filter' => ['value' => TRIGGER_VALUE_TRUE, 'status' => TRIGGER_STATUS_ENABLED],
				'sortfield' => ['priority', 'lastchange'], 'sortorder' => 'DESC', 'limit' => 20, 'skipDependent' => true
			]);
			return array_map(static fn(array $row): array => ['name' => $row['description'], 'severity' => (int) $row['priority'], 'since' => zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $row['lastchange']), 'acknowledged' => false, 'source' => 'Zabbix'], $rows);
		}
		catch (\Throwable $e) { return []; }
	}

	private function getResourceAlerts(?float $cpu, ?float $memory, array $disks, int $lastclock,
			float $resource_warning = 75.0, float $resource_critical = 90.0): array {
		$alerts = [];
		$since = $lastclock > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $lastclock) : 'Valor actual';
		$append = static function(array &$rows, string $name, float $value, float $warning, float $critical, string $since): void {
			if ($value < $warning) return;
			$rows[] = [
				'name' => $name.' en '.number_format($value, 1, '.', '').' %',
				'severity' => $value >= $critical ? 4 : 3,
				'since' => $since,
				'acknowledged' => false,
				'source' => 'Umbral del widget'
			];
		};
		if ($cpu !== null) $append($alerts, 'Uso de CPU', $cpu, $resource_warning, $resource_critical, $since);
		if ($memory !== null) $append($alerts, 'Uso de RAM', $memory, $resource_warning, $resource_critical, $since);
		foreach ($disks as $disk) {
			$value = $disk['used_percent_raw'] ?? null;
			if (empty($disk['primary']) || $value === null
					|| !in_array($disk['status'], ['warning', 'critical'], true)) continue;
			$alerts[] = [
				'name' => 'Disco '.$disk['label'].' en '.$disk['used_percent'],
				'severity' => $disk['status'] === 'critical' ? 4 : 3,
				'since' => $disk['last_update'] ?? $since,
				'acknowledged' => false,
				'source' => 'Umbral del widget'
			];
		}
		return $alerts;
	}

	private function getHistory(?array $item, int $range): array {
		if (!$this->isAvailable($item) || !in_array((int) $item['value_type'], [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64], true)) return [];
		if ($range > 604800) {
			try {
				$rows = API::Trend()->get([
					'output' => ['clock', 'value_avg'], 'itemids' => [$item['itemid']],
					'time_from' => time() - $range, 'sortfield' => 'clock', 'sortorder' => 'ASC', 'limit' => 1500
				]);
				if ($rows) {
					return array_map(static fn(array $row): array => ['clock' => (int) $row['clock'], 'value' => (float) $row['value_avg']], $rows);
				}
			}
			catch (\Throwable $e) {
				// Some installations do not expose trend.get to frontend roles; history remains the fallback.
			}
		}
		try {
			$rows = API::History()->get([
				'output' => ['clock', 'value'], 'history' => (int) $item['value_type'], 'itemids' => [$item['itemid']],
				'time_from' => time() - $range, 'sortfield' => 'clock', 'sortorder' => 'DESC', 'limit' => 2500
			]);
			$rows = array_reverse($rows);
			return array_map(static fn(array $row): array => ['clock' => (int) $row['clock'], 'value' => (float) $row['value']], $rows);
		}
		catch (\Throwable $e) {
			return [];
		}
	}

	private function getPercentHistory(array $item, float $total, int $range): array {
		$history = $this->getHistory($item, $range);
		foreach ($history as &$point) $point['value'] = max(0, min(100, $point['value'] / $total * 100));
		unset($point);
		return $history;
	}

	private function getTechnicalItems(array $items): array {
		$groups = ['Ubicación y estado' => [], 'CPU' => [], 'Memoria' => [], 'Otros datos VMware' => []];
		foreach ($items as $item) {
			$key = (string) $item['key_'];
			if (str_starts_with($key, 'vmware.vm.vfs.fs.') || str_starts_with($key, 'vmware.vm.net.')) continue;
			$group = str_starts_with($key, 'vmware.vm.cpu.') ? 'CPU'
				: ((str_starts_with($key, 'vmware.vm.memory.') || str_starts_with($key, 'vmware.vm.guest.memory.')) ? 'Memoria'
					: (preg_match('/^vmware\.vm\.(powerstate|state|uptime|guest\.osuptime|property|tools|hv\.|cluster\.|datacenter\.|snapshot|consolidation)/', $key) ? 'Ubicación y estado' : 'Otros datos VMware'));
			$groups[$group][] = ['name' => $item['name'], 'key' => $key, 'value' => $this->formatValue($item),
				'last_check' => (int) $item['lastclock'] > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $item['lastclock']) : '—',
				'supported' => $this->isAvailable($item), 'error' => (string) ($item['error'] ?? '')];
		}
		$result = [];
		foreach ($groups as $name => $rows) { if (!$rows) continue; usort($rows, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name'])); $result[] = ['name' => $name, 'rows' => $rows]; }
		return $result;
	}

	private function findItem(array $items, string $prefix, ?string $suffix = null): ?array {
		foreach ($items as $item) if (str_starts_with($item['key_'], $prefix) && ($suffix === null || str_ends_with($item['key_'], $suffix))) return $item;
		return null;
	}
	private function findToolsItem(array $items, string $mode): ?array {
		$fallback = null;
		foreach ($items as $item) {
			if (!str_starts_with($item['key_'], 'vmware.vm.tools[')) continue;
			if ($mode === 'version' && str_ends_with($item['key_'], ',version]')) return $item;
			if ($mode === 'status') {
				if (str_ends_with($item['key_'], ',status]')) return $item;
				if (!str_ends_with($item['key_'], ',version]')) $fallback = $item;
			}
		}
		return $fallback;
	}
	private function hasGuestToolsEvidence(array $items): bool {
		foreach ($items as $item) {
			$key = (string) $item['key_'];
			if (($key === 'vmware.vm.snapshot.count'
					|| str_starts_with($key, 'vmware.vm.guest.osuptime[')
					|| str_starts_with($key, 'vmware.vm.vfs.fs.size[')) && $this->isAvailable($item)) {
				return true;
			}
		}
		return false;
	}
	private function snapshotCount(array $items): ?float {
		foreach ($items as $item) {
			$key = mb_strtolower((string) $item['key_']);
			$name = mb_strtolower((string) $item['name']);
			if ((str_contains($key, 'snapshot.count')
					|| (str_contains($name, 'snapshot') && str_contains($name, 'count'))
					|| str_contains($name, 'recuento de instant')) && ($count = $this->numberValue($item)) !== null) {
				return $count;
			}
		}
		$item = null;
		foreach ($items as $candidate) {
			if (str_contains(mb_strtolower((string) $candidate['key_']), 'snapshot.get')
					|| str_contains(mb_strtolower((string) $candidate['name']), 'get snapshots')) {
				$item = $candidate;
				break;
			}
		}
		if (!$this->isAvailable($item)) return null;
		$data = json_decode((string) $item['lastvalue'], true);
		if (!is_array($data)) return null;
		if (isset($data['count']) && is_numeric($data['count'])) return (float) $data['count'];
		if (isset($data['snapshot']) && is_array($data['snapshot'])) return (float) count($data['snapshot']);
		$is_list = $data === [] || array_keys($data) === range(0, count($data) - 1);
		return $is_list ? (float) count($data) : null;
	}
	private function historyRange(int $range): int {
		return in_array($range, [3600, 7200, 10800, 21600, 86400, 604800, 1296000, 2592000], true)
			? $range : 3600;
	}
	private function rangeLabel(int $range): string {
		return [3600 => '1 hora', 7200 => '2 horas', 10800 => '3 horas', 21600 => '6 horas', 86400 => '1 día',
			604800 => '7 días', 1296000 => '15 días', 2592000 => '30 días'][$range] ?? '1 hora';
	}
	private function tagValue(array $item, string $name): string { foreach ($item['tags'] ?? [] as $tag) if ($tag['tag'] === $name) return (string) $tag['value']; return ''; }
	private function isAvailable(?array $item): bool { return $item !== null && (int) $item['state'] === ITEM_STATE_NORMAL && (int) $item['status'] === ITEM_STATUS_ACTIVE && (int) $item['lastclock'] > 0 && $item['lastvalue'] !== ''; }
	private function numberValue(?array $item): ?float { return $this->isAvailable($item) && is_numeric($item['lastvalue']) ? (float) $item['lastvalue'] : null; }
	private function normalizedValue(?array $item): ?string { return $this->isAvailable($item) ? strtolower(trim((string) $item['lastvalue'])) : null; }
	private function textValue(?array $item): string { return $this->isAvailable($item) ? (string) $item['lastvalue'] : '—'; }
	private function formatValue(array $item): string { if (!$this->isAvailable($item)) return '—'; return is_numeric($item['lastvalue']) ? convertUnits(['value' => $item['lastvalue'], 'units' => $item['units'], 'decimals' => 2]) : (string) $item['lastvalue']; }
	private function powerText(?string $value): string { return match ($value) {'1', 'poweredon', 'on' => 'Encendida', '0', 'poweredoff', 'off' => 'Apagada', '2', 'suspended' => 'Suspendida', default => 'Desconocido'}; }
	private function powerClass(?string $value): string { return match ($value) {'1', 'poweredon', 'on' => 'ok', '2', 'suspended' => 'warning', '0', 'poweredoff', 'off' => 'off', default => 'unknown'}; }
	private function toolsValue(?array $item): ?float { $value = $this->normalizedValue($item); if ($value === null) return null; if (is_numeric($value)) return (float) $value; return match ($value) {'guesttoolsrunning', 'running', 'activo', 'vmware tools is running' => 2.0, 'guesttoolsnotrunning', 'notrunning', 'not running', 'no activo', 'vmware tools is not running' => 1.0, 'guesttoolsexecutingscripts', 'starting', 'iniciando' => 0.0, default => null}; }
	private function toolsTextValue(?float $value): string { return match ($value !== null ? (int) $value : null) {2 => 'Activo', 1 => 'No activo', 0 => 'Iniciando', default => 'Desconocido'}; }
	private function formatInteger(?float $value): string { return $value !== null ? (string) (int) $value : '—'; }
	private function formatPercent(?float $value): string { return $value !== null ? number_format($value, 1, '.', '').' %' : '—'; }
	private function formatBytes(?float $bytes): string { if ($bytes === null) return '—'; $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']; $i = 0; $value = abs($bytes); while ($value >= 1024 && $i < count($units) - 1) { $value /= 1024; $i++; } return number_format($bytes < 0 ? -$value : $value, $i > 1 ? 1 : 0, '.', '').' '.$units[$i]; }
	private function formatUptime(?float $seconds): string { if ($seconds === null) return '—'; $seconds = max(0, (int) $seconds); return (intdiv($seconds, 86400) > 0 ? intdiv($seconds, 86400).'d ' : '').intdiv($seconds % 86400, 3600).'h '.intdiv($seconds % 3600, 60).'m'; }
	private function getHostAddress(array $interfaces): string { usort($interfaces, static fn(array $a, array $b): int => (int) $b['main'] <=> (int) $a['main']); foreach ($interfaces as $interface) { $address = (int) $interface['useip'] === 1 ? $interface['ip'] : $interface['dns']; if ($address !== '') return $address; } return '—'; }
	private function sendJson(array $data): void { $this->setResponse((new CControllerResponseData(['main_block' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]))->disableView()); }
}
