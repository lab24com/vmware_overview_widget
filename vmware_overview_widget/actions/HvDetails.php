<?php declare(strict_types = 0);

namespace Modules\VmwareOverview\Actions;

use API, CController, CControllerResponseData;

class HvDetails extends CController {
	protected function init(): void { $this->disableCsrfValidation(); }
	protected function checkPermissions(): bool { return $this->getUserType() >= USER_TYPE_ZABBIX_USER; }
	protected function checkInput(): bool {
		$valid = $this->validateInput(['hostid' => 'int32', 'name' => 'string|required', 'range' => 'int32',
			'resource_warning' => 'int32', 'resource_critical' => 'int32']);
		if (!$valid) $this->sendJson(['error' => implode(' ', array_column(get_and_clear_messages(), 'message'))]);
		return $valid;
	}

	protected function doAction(): void {
		$name = trim($this->getInput('name'));
		$hostid = (string) $this->getInput('hostid', '');
		$range = $this->historyRange((int) $this->getInput('range', 3600));
		$resource_warning = max(1, min(99, (int) $this->getInput('resource_warning', 75)));
		$resource_critical = max($resource_warning + 1, min(100, (int) $this->getInput('resource_critical', 90)));
		$host_options = [
			'output' => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
			'selectInterfaces' => ['ip', 'dns', 'useip', 'main'], 'selectHostGroups' => ['name'],
			'selectMacros' => ['macro', 'value']
		];
		$host = null;
		$host_items = [];
		if ($hostid !== '') {
			$host = API::Host()->get($host_options + ['hostids' => [$hostid]])[0] ?? null;
			$host_items = $host !== null ? $this->getHostHypervisorItems((string) $host['hostid']) : [];
		}

		// Si el host recibido no tiene ítems vmware.hv.* con datos (o no se recibió), se busca el host ESXi por
		// nombre admitiendo prefijos/sufijos ("DCP-HV-esx01.dominio" ↔ "esx01.dominio") y se elige el candidato
		// con más ítems VMware con datos. Así no se confunde con un host homónimo monitoreado por agente o ICMP.
		if ($this->availableHypervisorItems($host_items) === 0) {
			$short = filter_var($name, FILTER_VALIDATE_IP) !== false ? $name : explode('.', $name, 2)[0];
			$candidates = $short !== '' ? API::Host()->get($host_options + ['search' => ['name' => $short]]) : [];
			$candidates = array_values(array_filter($candidates, fn(array $candidate): bool =>
				$this->sameHypervisorName((string) $candidate['name'], $name)
					|| $this->sameHypervisorName((string) ($candidate['host'] ?? ''), $name)));
			$best_score = 0;
			foreach (array_slice($candidates, 0, 10) as $candidate) {
				$candidate_items = $this->getHostHypervisorItems((string) $candidate['hostid']);
				$score = $this->availableHypervisorItems($candidate_items);
				if ($score > $best_score) {
					$best_score = $score;
					$host = $candidate;
					$host_items = $candidate_items;
				}
			}
			if ($host === null && $candidates) {
				$host = $candidates[0];
			}
		}

		$hypervisor_identifiers = $host !== null ? $this->hostIdentifiers($host) : [];
		$items = [];
		if ($host !== null) {
			$hostid = (string) $host['hostid'];
			$host_is_entity = $this->hostMatchesHypervisor($host, $name, $hypervisor_identifiers);
			$items = $host_is_entity
				? $host_items
				: array_values(array_filter($host_items,
					fn(array $item): bool => $this->itemMatchesHypervisor($item, $name, $hypervisor_identifiers)));
		}
		try {
			$global_items = API::Item()->get([
				'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type', 'state', 'status', 'error'],
				'search' => ['key_' => 'vmware.hv.'], 'selectTags' => ['tag', 'value'],
				'selectHosts' => ['hostid', 'host', 'name'], 'templated' => false, 'webitems' => true
			]);
			$matched_items = array_values(array_filter($global_items,
				fn(array $item): bool => $this->itemMatchesHypervisor($item, $name, $hypervisor_identifiers)));
			$items = $this->mergeHypervisorItems($items, $matched_items);
			if ($matched_items && $host === null && !empty($matched_items[0]['hosts'][0])) {
				$hostid = (string) $matched_items[0]['hosts'][0]['hostid'];
				$host = [
					'hostid' => $hostid, 'host' => $matched_items[0]['hosts'][0]['host'] ?? '',
					'name' => $matched_items[0]['hosts'][0]['name'], 'status' => HOST_STATUS_MONITORED,
					'maintenance_status' => HOST_MAINTENANCE_STATUS_OFF, 'interfaces' => [], 'hostgroups' => []
				];
			}
		}
		catch (\Throwable $e) {
			// The directly resolved host items and VM-derived fallback remain available.
		}

		$vms = $this->getVirtualMachines($name);
		$memory_total = $this->numberValue($this->findItem($items, 'vmware.hv.hw.memory['));
		$memory_used = $this->numberValue($this->findItem($items, 'vmware.hv.memory.used['));
		$memory_percent = $memory_total > 0 && $memory_used !== null ? $memory_used / $memory_total * 100 : null;
		$cpu_item = $this->findItem($items, 'vmware.hv.cpu.usage.perf[')
			?? $this->findItem($items, 'vmware.hv.cpu.utilization[');
		$cpu = $this->numberValue($cpu_item);
		$cpu_capacity_hz = $this->cpuCapacityHz($items);
		$cpu_hz_item = $this->findItem($items, 'vmware.hv.cpu.usage[');
		if ($cpu === null && $cpu_capacity_hz !== null && ($usage_hz = $this->numberValue($cpu_hz_item)) !== null) {
			// Respaldo: CPU usage (Hz) / (frecuencia × núcleos).
			$cpu = max(0.0, min(100.0, $usage_hz / $cpu_capacity_hz * 100));
		}
		$resolved_metrics = count(array_filter($items, fn(array $item): bool => $this->isAvailable($item)));
		if ($vms && ($cpu === null || $memory_total === null || $memory_used === null)) {
			$allocated_vcpu = array_sum(array_column($vms, 'vcpu_raw'));
			$allocated_memory = array_sum(array_column($vms, 'memory_raw'));
			$used_memory = array_sum(array_column($vms, 'memory_used_raw'));
			$weighted_cpu = array_sum(array_map(static fn(array $vm): float =>
				(float) ($vm['cpu_raw'] ?? 0) * max(1, (int) $vm['vcpu_raw']), $vms));
			if ($cpu === null && $allocated_vcpu > 0) $cpu = $weighted_cpu / $allocated_vcpu;
			if ($memory_total === null && $allocated_memory > 0) $memory_total = (float) $allocated_memory;
			if ($memory_used === null && $used_memory > 0) $memory_used = (float) $used_memory;
			$memory_percent = $memory_total > 0 && $memory_used !== null ? $memory_used / $memory_total * 100 : null;
		}
		$network_in_item = $this->findItem($items, 'vmware.hv.network.in[');
		$network_out_item = $this->findItem($items, 'vmware.hv.network.out[');
		$connection_value = $this->normalizedValue($this->findItem($items, 'vmware.hv.connectionstate['));
		$connection_text = null;
		if ($connection_value === null) {
			// Respaldo: "Hypervisor ping" (icmpping[]) de la plantilla.
			$ping = $this->numberValue($this->findItem($items, 'icmpping'));
			if ($ping !== null) {
				$connection_value = (int) $ping === 1 ? '0' : '2';
				$connection_text = (int) $ping === 1 ? 'Responde a ping' : 'Sin respuesta a ping';
			}
		}
		$health_value = $this->normalizedValue(
			$this->findItem($items, 'vmware.hv.status[')
				?? $this->findItem($items, 'vmware.hv.sensor.health.state[')
		);
		$lastclock = 0; foreach ($items as $item) $lastclock = max($lastclock, (int) $item['lastclock']);

		$host_is_entity = $host !== null && $this->hostMatchesHypervisor($host, $name, $hypervisor_identifiers);
		$address = $host_is_entity ? $this->getHostAddress($host['interfaces'] ?? []) : '—';
		$console_target = $address !== '—' ? $address : $name;
		$problems = $this->getProblems(
			$hostid,
			$name,
			$host !== null && !$host_is_entity
		);
		$problems = array_merge($problems, $this->getResourceAlerts(
			$cpu, $memory_percent, $connection_value, $health_value, $lastclock,
			(float) $resource_warning, (float) $resource_critical
		));
		usort($problems, static fn(array $a, array $b): int => ((int) $b['severity'] <=> (int) $a['severity'])
			?: strnatcasecmp($a['name'], $b['name']));

		$this->sendJson([
			'name' => $name, 'resolved' => $resolved_metrics > 0, 'hostid' => $hostid !== '' ? $hostid : null,
			'address' => $address, 'console_target' => $console_target,
			'groups' => $host !== null ? array_values(array_column($host['hostgroups'] ?? [], 'name')) : [],
			'host_url' => $host !== null ? 'zabbix.php?action=host.dashboard.view&hostid='.$hostid : '',
			'latest_url' => $host !== null ? 'zabbix.php?action=latest.view&filter_set=1&filter_show_without_data=1&filter_hostids%5B0%5D='.$hostid : '',
			'problems_url' => $host !== null ? 'zabbix.php?action=problem.view&filter_set=1&filter_show_suppressed=1&filter_hostids%5B0%5D='.$hostid : '',
			'enabled' => $host === null || (int) $host['status'] === HOST_STATUS_MONITORED,
			'maintenance' => $host !== null && (int) $host['maintenance_status'] === HOST_MAINTENANCE_STATUS_ON,
			'last_update' => $lastclock > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $lastclock) : '—',
			'summary' => [
				'connection' => $connection_text ?? $this->stateText($connection_value, 'connection'),
				'health' => $this->stateText($health_value, 'health'),
				'cpu' => $this->formatPercent($cpu), 'cpu_raw' => $cpu,
				'cores' => $this->formatInteger($this->numberValue($this->findItem($items, 'vmware.hv.hw.cpu.num['))),
				'threads' => $this->formatInteger($this->numberValue($this->findItem($items, 'vmware.hv.hw.cpu.threads['))),
				'memory' => $this->formatPercent($memory_percent), 'memory_raw' => $memory_percent,
				'memory_used' => $this->formatBytes($memory_used), 'memory_total' => $this->formatBytes($memory_total),
				'vms' => count($vms), 'powered_on' => count(array_filter($vms, static fn(array $vm): bool => $vm['power'] === 'Encendida')),
				'allocated_vcpu' => array_sum(array_column($vms, 'vcpu_raw')),
				'allocated_memory' => $this->formatBytes(array_sum(array_column($vms, 'memory_raw'))),
				'cluster' => $this->textValue($this->findItem($items, 'vmware.hv.cluster.name[')),
				'datacenter' => $this->textValue($this->findItem($items, 'vmware.hv.datacenter.name[')),
				'model' => $this->textValue($this->findItem($items, 'vmware.hv.hw.model[')),
				'vendor' => $this->textValue($this->findItem($items, 'vmware.hv.hw.vendor[')),
				'version' => $this->textValue(
					$this->findItem($items, 'vmware.hv.fullname[')
						?? $this->findItem($items, 'vmware.hv.version[')
				),
				'uptime' => $this->formatUptime($this->numberValue($this->findItem($items, 'vmware.hv.uptime['))),
				'cpu_model' => $this->textValue($this->findItem($items, 'vmware.hv.hw.cpu.model[')),
				'serial' => $this->textValue($this->findItem($items, 'vmware.hv.hw.serialnumber[')),
				'memory_ballooned' => $this->formatBytes($this->numberValue($this->findItem($items, 'vmware.hv.memory.size.ballooned[')))
			],
			'range' => $range, 'range_label' => $this->rangeLabel($range),
			'resource_warning' => $resource_warning, 'resource_critical' => $resource_critical,
			'network_units' => $network_in_item !== null ? (string) $network_in_item['units'] : 'Bps',
			'vms' => $vms, 'datastores' => $this->getDatastores($items), 'problems' => $problems,
			'history' => [
				'cpu' => $cpu_item !== null
					? $this->getHistory($cpu_item, $range)
					: ($cpu_capacity_hz !== null ? $this->getPercentHistory($cpu_hz_item, $cpu_capacity_hz, $range) : []),
				'memory' => $this->getPercentHistory($this->findItem($items, 'vmware.hv.memory.used['), $memory_total, $range),
				'network_in' => $this->getHistory($network_in_item, $range),
				'network_out' => $this->getHistory($network_out_item, $range)
			],
			'technical' => $this->getTechnicalItems($items)
		]);
	}

	private function getVirtualMachines(string $hypervisor): array {
		$location_items = API::Item()->get([
			'output' => ['hostid', 'lastvalue'], 'search' => ['key_' => 'vmware.vm.hv.name['],
			'selectHosts' => ['hostid', 'name'], 'templated' => false, 'webitems' => true
		]);
		$location_items = array_values(array_filter($location_items,
			fn(array $item): bool => $this->sameHypervisorName(trim((string) $item['lastvalue']), $hypervisor)));
		$hostids = array_values(array_unique(array_column($location_items, 'hostid')));
		if (!$hostids) return [];
		$items = API::Item()->get([
			'output' => ['hostid', 'key_', 'lastvalue', 'lastclock', 'state', 'status'], 'hostids' => $hostids,
			'search' => ['key_' => 'vmware.vm.'], 'templated' => false, 'webitems' => true
		]);
		$by_host = []; foreach ($items as $item) $by_host[$item['hostid']][] = $item;
		$names = []; foreach ($location_items as $item) if (!empty($item['hosts'][0])) $names[$item['hostid']] = $item['hosts'][0]['name'];
		$result = [];
		foreach ($hostids as $id) {
			$rows = $by_host[$id] ?? []; $power = $this->normalizedValue($this->findItem($rows, 'vmware.vm.powerstate['));
			$cpu_raw = $this->numberValue($this->findItem($rows, 'vmware.vm.cpu.usage.perf['));
			$memory_raw = $this->numberValue($this->findItem($rows, 'vmware.vm.memory.size['));
			$memory_used_raw = $this->numberValue($this->findItem($rows, 'vmware.vm.memory.size.usage.guest['));
			if ($memory_used_raw === null && $memory_raw > 0) {
				$memory_percent = $this->numberValue($this->findItem($rows, 'vmware.vm.memory.usage['));
				if ($memory_percent !== null) $memory_used_raw = $memory_raw * $memory_percent / 100;
			}
			$result[] = [
				'hostid' => $id, 'name' => $names[$id] ?? ('Host '.$id), 'power' => $this->powerText($power),
				'cpu' => $this->formatPercent($cpu_raw), 'cpu_raw' => $cpu_raw,
				'vcpu' => $this->formatInteger($this->numberValue($this->findItem($rows, 'vmware.vm.cpu.num['))),
				'vcpu_raw' => (int) ($this->numberValue($this->findItem($rows, 'vmware.vm.cpu.num[')) ?? 0),
				'memory' => $this->formatBytes($memory_raw),
				'memory_raw' => (float) ($memory_raw ?? 0), 'memory_used_raw' => (float) ($memory_used_raw ?? 0)
			];
		}
		usort($result, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name'])); return $result;
	}

	private function getDatastores(array $items): array {
		$data = [];
		foreach ($items as $item) {
			if (!str_starts_with($item['key_'], 'vmware.hv.datastore.size[') || !preg_match('/,(free|pfree|uncommitted)\]$/', $item['key_'], $m) && !str_ends_with($item['key_'], ']')) continue;
			$name = $this->tagValue($item, 'datastore'); if ($name === '' && preg_match('/\[([^\]]+)\]/', $item['name'], $n)) $name = $n[1]; if ($name === '') continue;
			$metric = preg_match('/,(free|pfree|uncommitted)\]$/', $item['key_'], $m) ? $m[1] : 'total';
			$data[$name]['name'] = $name; $data[$name][$metric] = $this->numberValue($item);
		}
		$result = [];
		foreach ($data as $row) {
			$pfree = $row['pfree'] ?? ((($row['total'] ?? 0) > 0 && isset($row['free'])) ? $row['free'] / $row['total'] * 100 : null);
			if (!isset($row['free']) && ($row['total'] ?? 0) > 0 && $pfree !== null) $row['free'] = $row['total'] * $pfree / 100;
			$result[] = ['name' => $row['name'], 'total' => $this->formatBytes($row['total'] ?? null), 'free' => $this->formatBytes($row['free'] ?? null), 'pfree' => $this->formatPercent($pfree), 'pused_raw' => $pfree !== null ? 100 - $pfree : null];
		}
		return $result;
	}

	private function getTechnicalItems(array $items): array {
		$result = [];
		foreach ($items as $item) {
			if (str_starts_with($item['key_'], 'vmware.hv.datastore.')) continue;
			$result[] = ['name' => $item['name'], 'key' => $item['key_'], 'value' => $this->formatValue($item), 'supported' => $this->isAvailable($item)];
		}
		usort($result, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name'])); return $result;
	}

	private function getHistory(?array $item, int $range): array {
		if (!$this->isAvailable($item) || !in_array((int) $item['value_type'], [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64], true)) return [];
		if ($range > 604800) {
			try {
				$rows = API::Trend()->get(['output' => ['clock', 'value_avg'], 'itemids' => [$item['itemid']],
					'time_from' => time() - $range, 'sortfield' => 'clock', 'sortorder' => 'ASC', 'limit' => 1500]);
				if ($rows) return array_map(static fn(array $r): array => ['clock' => (int) $r['clock'], 'value' => (float) $r['value_avg']], $rows);
			}
			catch (\Throwable $e) {}
		}
		try {
			$rows = API::History()->get(['output' => ['clock', 'value'], 'history' => (int) $item['value_type'],
				'itemids' => [$item['itemid']], 'time_from' => time() - $range, 'sortfield' => 'clock', 'sortorder' => 'DESC', 'limit' => 2500]);
			$rows = array_reverse($rows);
			return array_map(static fn(array $r): array => ['clock' => (int) $r['clock'], 'value' => (float) $r['value']], $rows);
		}
		catch (\Throwable $e) { return []; }
		return [];
	}

	private function getPercentHistory(?array $item, ?float $total, int $range): array {
		if ($total === null || $total <= 0) return [];
		$history = $this->getHistory($item, $range);
		foreach ($history as &$point) $point['value'] = max(0, min(100, $point['value'] / $total * 100));
		unset($point);
		return $history;
	}

	private function getHostHypervisorItems(string $hostid): array {
		$items = API::Item()->get([
			'output' => ['itemid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type', 'state', 'status', 'error'],
			'hostids' => [$hostid], 'search' => ['key_' => ['vmware.hv.', 'icmpping']], 'searchByAny' => true,
			'selectTags' => ['tag', 'value'], 'templated' => false, 'webitems' => true
		]);
		return array_values(array_filter($items, static fn(array $item): bool =>
			str_starts_with((string) $item['key_'], 'vmware.hv.') || str_starts_with((string) $item['key_'], 'icmpping')));
	}

	private function availableHypervisorItems(array $items): int {
		return count(array_filter($items, fn(array $item): bool =>
			str_starts_with((string) $item['key_'], 'vmware.hv.') && $this->isAvailable($item)));
	}

	/** UUID del ESXi: nombre técnico del host ({#HV.UUID}) o macro {$VMWARE.HV.UUID}. */
	private function hostIdentifiers(array $host): array {
		$result = [];
		$candidates = [(string) ($host['host'] ?? '')];
		foreach ($host['macros'] ?? [] as $macro) {
			if (str_contains(mb_strtoupper((string) ($macro['macro'] ?? '')), 'HV.UUID')) $candidates[] = (string) $macro['value'];
		}
		foreach ($candidates as $value) {
			$value = mb_strtolower(trim($value));
			if (preg_match('/^[0-9a-f]{8}(?:-?[0-9a-f]{4}){3}-?[0-9a-f]{12}$/', $value) === 1) $result[$value] = true;
		}
		return array_keys($result);
	}

	private function cpuCapacityHz(array $items): ?float {
		$freq = $this->numberValue($this->findItem($items, 'vmware.hv.hw.cpu.freq['));
		$cores = $this->numberValue($this->findItem($items, 'vmware.hv.hw.cpu.num['));
		return $freq && $cores ? $freq * $cores : null;
	}

	private function getHypervisorIdentifiers(string $name): array {
		try {
			$items = API::Item()->get([
				'output' => ['key_', 'lastvalue', 'lastclock', 'state', 'status'],
				'search' => ['key_' => 'vmware.hv.discovery['],
				'templated' => false, 'webitems' => true
			]);
		}
		catch (\Throwable $e) {
			return [];
		}

		$result = [];
		foreach ($items as $item) {
			if (!$this->isAvailable($item)) continue;
			$rows = json_decode((string) $item['lastvalue'], true);
			if (!is_array($rows)) continue;
			if (isset($rows['data']) && is_array($rows['data'])) $rows = $rows['data'];
			foreach ($rows as $row) {
				if (!is_array($row)) continue;
				$discovered_name = trim((string) ($row['{#HV.NAME}'] ?? $row['name'] ?? ''));
				$uuid = mb_strtolower(trim((string) ($row['{#HV.UUID}'] ?? $row['uuid'] ?? '')));
				$id = mb_strtolower(trim((string) ($row['{#HV.ID}'] ?? $row['id'] ?? '')));
				if (!$this->sameHypervisorName($discovered_name, $name)) continue;
				if ($uuid !== '') $result[$uuid] = true;
				if ($id !== '') $result[$id] = true;
			}
		}
		return array_keys($result);
	}

	private function mergeHypervisorItems(array $direct, array $matched): array {
		$merged = [];
		foreach (array_merge($direct, $matched) as $item) $merged[(string) $item['itemid']] = $item;
		$merged = array_values($merged);
		usort($merged, function(array $left, array $right): int {
			$available = (int) $this->isAvailable($right) <=> (int) $this->isAvailable($left);
			return $available !== 0 ? $available : ((int) $right['lastclock'] <=> (int) $left['lastclock']);
		});
		return $merged;
	}

	private function hostMatchesHypervisor(array $host, string $name, array $identifiers): bool {
		if ($this->sameHypervisorName((string) ($host['name'] ?? ''), $name)
				|| $this->sameHypervisorName((string) ($host['host'] ?? ''), $name)) return true;
		foreach ([(string) ($host['name'] ?? ''), (string) ($host['host'] ?? '')] as $host_name) {
			if (in_array(mb_strtolower(trim($host_name)), $identifiers, true)) return true;
		}
		foreach ($host['macros'] ?? [] as $macro) {
			$macro_name = mb_strtoupper((string) ($macro['macro'] ?? ''));
			$value = mb_strtolower(trim((string) ($macro['value'] ?? '')));
			if (str_contains($macro_name, 'HV.UUID') && in_array($value, $identifiers, true)) return true;
		}
		return false;
	}

	private function itemMatchesHypervisor(array $item, string $name, array $identifiers = []): bool {
		$key = mb_strtolower((string) ($item['key_'] ?? ''));
		foreach ($identifiers as $identifier) {
			if ($identifier !== '' && str_contains($key, $identifier)) return true;
		}
		foreach ($item['tags'] ?? [] as $tag) {
			if (in_array(mb_strtolower((string) $tag['tag']), ['hypervisor', 'hv', 'esxi', 'host'], true)
					&& $this->sameHypervisorName((string) $tag['value'], $name)) return true;
			$value = mb_strtolower(trim((string) ($tag['value'] ?? '')));
			if ($value !== '' && in_array($value, $identifiers, true)) return true;
		}
		if (preg_match('/(?:hypervisor|hipervisor|esxi)\s*\[([^\]]+)\]/iu', (string) $item['name'], $match)) {
			return $this->sameHypervisorName(trim($match[1]), $name);
		}
		foreach ($item['hosts'] ?? [] as $item_host) {
			if ($this->sameHypervisorName((string) ($item_host['name'] ?? ''), $name)
					|| $this->sameHypervisorName((string) ($item_host['host'] ?? ''), $name)) return true;
			foreach ([(string) ($item_host['name'] ?? ''), (string) ($item_host['host'] ?? '')] as $host_name) {
				if (in_array(mb_strtolower(trim($host_name)), $identifiers, true)) return true;
			}
		}
		return false;
	}

	private function sameHypervisorName(string $left, string $right): bool {
		$normalize = static fn(string $value): string => mb_strtolower(rtrim(trim($value), '.'));
		$left = $normalize($left); $right = $normalize($right);
		if ($left === '' || $right === '') return false;
		if ($left === $right) return true;
		// Las direcciones IP sólo coinciden completas: "10.0.0.5" no es el mismo ESXi que "10.0.0.6".
		if (filter_var($left, FILTER_VALIDATE_IP) !== false || filter_var($right, FILTER_VALIDATE_IP) !== false) return false;
		if (explode('.', $left, 2)[0] === explode('.', $right, 2)[0]) return true;
		// Nombres con prefijo o sufijo propio de la instalación: "dcp-hv-esx01.dominio" ↔ "esx01.dominio".
		return self::containsName($left, $right) || self::containsName($right, $left);
	}

	private static function containsName(string $haystack, string $needle): bool {
		if (mb_strlen($needle) < 4 || $haystack === $needle) return false;
		if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}_-])/u', $haystack) === 1) return true;
		$short_needle = explode('.', $needle, 2)[0];
		$short_haystack = explode('.', $haystack, 2)[0];
		return mb_strlen($short_needle) >= 4 && $short_haystack !== $short_needle
			&& preg_match('/[^\p{L}\p{N}]'.preg_quote($short_needle, '/').'$/u', $short_haystack) === 1;
	}

	private function getProblems(string $hostid, string $hypervisor, bool $filter_by_name): array {
		$problems = [];
		$aliases = [mb_strtolower($hypervisor)];
		if (filter_var($hypervisor, FILTER_VALIDATE_IP) === false) $aliases[] = mb_strtolower(explode('.', $hypervisor, 2)[0]);
		if ($hostid !== '') {
			try {
				$rows = API::Problem()->get([
				'output' => ['eventid', 'name', 'severity', 'clock', 'acknowledged'], 'hostids' => [$hostid],
				'source' => EVENT_SOURCE_TRIGGERS, 'object' => EVENT_OBJECT_TRIGGER, 'recent' => false,
				'selectTags' => ['tag', 'value'], 'sortfield' => ['severity', 'clock'],
				'sortorder' => 'DESC', 'limit' => 100
				]);
				if ($filter_by_name) {
					$rows = array_values(array_filter($rows, static function(array $row) use ($aliases): bool {
					$haystack = mb_strtolower((string) $row['name']);
					foreach ($row['tags'] ?? [] as $tag) $haystack .= ' '.mb_strtolower((string) $tag['value']);
					foreach ($aliases as $alias) if (self::mentions($haystack, $alias)) return true;
					return false;
					}));
				}
				foreach ($rows as $row) $problems[(string) $row['eventid']] = $row;
			}
			catch (\Throwable $e) {
				// Compatibility fallback for roles without access to problem.get.
			}
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
				foreach ($rows as $row) {
					$haystack = mb_strtolower((string) $row['name']);
					foreach ($row['tags'] ?? [] as $tag) $haystack .= ' '.mb_strtolower((string) $tag['value']);
					foreach ($aliases as $alias) {
						if (self::mentions($haystack, $alias)) {
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
		if ($hostid === '') return [];

		try {
			$rows = API::Trigger()->get([
				'output' => ['triggerid', 'description', 'priority', 'lastchange'], 'hostids' => [$hostid],
				'filter' => ['value' => TRIGGER_VALUE_TRUE, 'status' => TRIGGER_STATUS_ENABLED],
				'sortfield' => ['priority', 'lastchange'], 'sortorder' => 'DESC', 'limit' => 50,
				'skipDependent' => true
			]);
			if ($filter_by_name) {
				$rows = array_values(array_filter($rows, static function(array $row) use ($aliases): bool {
					$name = mb_strtolower((string) $row['description']);
					foreach ($aliases as $alias) if (self::mentions($name, $alias)) return true;
					return false;
				}));
			}
			return array_map(static fn(array $row): array => [
				'name' => $row['description'], 'severity' => (int) $row['priority'],
				'since' => zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $row['lastchange']),
				'acknowledged' => false, 'source' => 'Zabbix'
			], $rows);
		}
		catch (\Throwable $e) { return []; }
	}

	private static function mentions(string $haystack, string $alias): bool {
		if ($alias === '') return false;
		return preg_match('/(?<![\p{L}\p{N}_-])'.preg_quote($alias, '/').'(?![\p{L}\p{N}_-])/u', $haystack) === 1;
	}

	private function getResourceAlerts(?float $cpu, ?float $memory, ?string $connection, ?string $health, int $lastclock,
			float $resource_warning = 75.0, float $resource_critical = 90.0): array {
		$alerts = [];
		$since = $lastclock > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $lastclock) : 'Valor actual inferido';
		foreach ([['Uso de CPU', $cpu], ['Uso de RAM', $memory]] as [$label, $value]) {
			if ($value === null || $value < $resource_warning) continue;
			$alerts[] = [
				'name' => $label.' en '.number_format($value, 1, '.', '').' %',
				'severity' => $value >= $resource_critical ? 4 : 3,
				'since' => $since,
				'acknowledged' => false,
				'source' => 'Umbral del widget'
			];
		}
		if ($connection !== null && !in_array($connection, ['0', 'connected'], true)) {
			$alerts[] = ['name' => 'Estado de conexión: '.$this->stateText($connection, 'connection'), 'severity' => 4,
				'since' => $since, 'acknowledged' => false, 'source' => 'Estado VMware'];
		}
		if ($health !== null && !in_array($health, ['1', 'green'], true)) {
			$alerts[] = ['name' => 'Salud VMware: '.$this->stateText($health, 'health'),
				'severity' => in_array($health, ['3', 'red'], true) ? 4 : 3,
				'since' => $since, 'acknowledged' => false, 'source' => 'Estado VMware'];
		}
		return $alerts;
	}

	private function historyRange(int $range): int {
		return in_array($range, [3600, 7200, 10800, 21600, 86400, 604800, 1296000, 2592000], true) ? $range : 3600;
	}
	private function rangeLabel(int $range): string {
		return [3600 => '1 hora', 7200 => '2 horas', 10800 => '3 horas', 21600 => '6 horas', 86400 => '1 día',
			604800 => '7 días', 1296000 => '15 días', 2592000 => '30 días'][$range] ?? '1 hora';
	}

	private function findItem(array $items, string $prefix): ?array { foreach ($items as $item) if (str_starts_with($item['key_'], $prefix)) return $item; return null; }
	private function tagValue(array $item, string $name): string { foreach ($item['tags'] ?? [] as $tag) if ($tag['tag'] === $name) return (string) $tag['value']; return ''; }
	private function isAvailable(?array $item): bool { return $item !== null && (int) $item['state'] === ITEM_STATE_NORMAL && (int) $item['status'] === ITEM_STATUS_ACTIVE && (int) $item['lastclock'] > 0 && $item['lastvalue'] !== ''; }
	private function numberValue(?array $item): ?float { return $this->isAvailable($item) && is_numeric($item['lastvalue']) ? (float) $item['lastvalue'] : null; }
	private function normalizedValue(?array $item): ?string { return $this->isAvailable($item) ? strtolower(trim((string) $item['lastvalue'])) : null; }
	private function textValue(?array $item): string { return $this->isAvailable($item) ? (string) $item['lastvalue'] : '—'; }
	private function formatValue(array $item): string { if (!$this->isAvailable($item)) return '—'; return is_numeric($item['lastvalue']) ? convertUnits(['value' => $item['lastvalue'], 'units' => $item['units'], 'decimals' => 2]) : (string) $item['lastvalue']; }
	private function powerText(?string $value): string { return match ($value) {'1', 'poweredon', 'on' => 'Encendida', '0', 'poweredoff', 'off' => 'Apagada', '2', 'suspended' => 'Suspendida', default => 'Desconocido'}; }
	private function stateText(?string $value, string $type): string { if ($value === null) return 'No disponible'; if ($type === 'connection') return match ($value) {'0', 'connected' => 'Conectado', '1', 'disconnected' => 'Desconectado', '2', 'notresponding' => 'Sin respuesta', default => $value}; return match ($value) {'1', 'green' => 'Correcto', '2', 'yellow' => 'Advertencia', '3', 'red' => 'Crítico', default => $value}; }
	private function formatInteger(?float $value): string { return $value !== null ? (string) (int) $value : '—'; }
	private function formatPercent(?float $value): string { return $value !== null ? number_format($value, 1, '.', '').' %' : '—'; }
	private function formatBytes(?float $bytes): string { if ($bytes === null) return '—'; $u = ['B','KB','MB','GB','TB','PB']; $i=0; $v=abs($bytes); while ($v>=1024 && $i<count($u)-1){$v/=1024;$i++;} return number_format($bytes<0?-$v:$v,$i>1?1:0,'.','').' '.$u[$i]; }
	private function formatUptime(?float $s): string { if ($s===null) return '—'; $s=max(0,(int)$s); return (intdiv($s,86400)>0?intdiv($s,86400).'d ':'').intdiv($s%86400,3600).'h '.intdiv($s%3600,60).'m'; }
	private function getHostAddress(array $interfaces): string { usort($interfaces, static fn(array $a,array $b):int=>(int)$b['main']<=>(int)$a['main']); foreach($interfaces as $i){$a=(int)$i['useip']===1?$i['ip']:$i['dns'];if($a!=='')return $a;}return '—'; }
	private function sendJson(array $data): void { $this->setResponse((new CControllerResponseData(['main_block' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]))->disableView()); }
}
