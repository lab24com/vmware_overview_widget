<?php declare(strict_types = 0);

namespace Modules\VmwareOverview\Actions;

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData,
	CCsrfTokenHelper,
	Modules\VmwareOverview\Includes\WidgetForm;

class WidgetView extends CControllerDashboardWidgetView {

	private const VM_MARKER = 'vmware.vm.powerstate[';
	private const HV_MARKER = 'vmware.hv.';

	private float $resource_warning = WidgetForm::DEFAULT_RESOURCE_WARNING;
	private float $resource_critical = WidgetForm::DEFAULT_RESOURCE_CRITICAL;

	protected function doAction(): void {
		$groupids = $this->fields_values['groupids']
			? getSubGroups($this->fields_values['groupids'])
			: null;

		[$warning, $critical] = $this->thresholdPair('disk_warning', 'disk_critical',
			WidgetForm::DEFAULT_DISK_WARNING, WidgetForm::DEFAULT_DISK_CRITICAL);
		[$resource_warning, $resource_critical] = $this->thresholdPair('resource_warning', 'resource_critical',
			WidgetForm::DEFAULT_RESOURCE_WARNING, WidgetForm::DEFAULT_RESOURCE_CRITICAL);
		[$datastore_warning, $datastore_critical] = $this->thresholdPair('datastore_warning', 'datastore_critical',
			WidgetForm::DEFAULT_DATASTORE_WARNING, WidgetForm::DEFAULT_DATASTORE_CRITICAL);
		$this->resource_warning = (float) $resource_warning;
		$this->resource_critical = (float) $resource_critical;
		$include_maintenance = (bool) $this->fields_values['include_maintenance'];
		$show_hypervisors = (bool) $this->fields_values['show_hypervisors'];

		$vm_hostids = $this->getHostIdsByMarker(self::VM_MARKER, $groupids);
		$hv_hostids = $show_hypervisors
			? $this->getHostIdsByMarker(self::HV_MARKER, null)
			: [];

		$all_hostids = array_values(array_unique(array_merge($vm_hostids, $hv_hostids)));
		$hosts = $this->getHosts($all_hostids, $include_maintenance);
		$visible_hostids = array_keys($hosts);

		$vm_hostids = array_values(array_intersect($vm_hostids, $visible_hostids));
		$hv_hostids = array_values(array_intersect($hv_hostids, $visible_hostids));
		$items_by_host = $this->getItemsByHost($visible_hostids);
		$problem_counts = $this->getProblemCounts($visible_hostids);
		$vmware_alarm_problems = $this->getVmwareAlarmProblems();
		$hypervisor_discovery = $show_hypervisors ? $this->getHypervisorUuidMap($hosts, $hv_hostids) : [];
		$hypervisor_item_sets = $show_hypervisors
			? $this->getHypervisorItemSets($hypervisor_discovery)
			: [];

		$vms = [];
		foreach ($vm_hostids as $hostid) {
			$vm = $this->makeVmRow(
				$hosts[$hostid],
				$items_by_host[$hostid] ?? [],
				$problem_counts[$hostid] ?? 0,
				$warning,
				$critical
			);
			$central_problems = $this->countEntityProblems($vm['name'], $vmware_alarm_problems);
			$vm['zabbix_problems'] += $central_problems;
			$vm['problems'] += $central_problems;
			$vm['risk_score'] += $central_problems * 1000000;

			if (!$this->fields_values['show_powered_off'] && $vm['power_value'] === 0) {
				continue;
			}

			$vms[] = $vm;
		}

		$referenced_hypervisors = [];
		foreach ($vms as $vm) {
			if ($vm['hypervisor'] === '—') {
				continue;
			}

			$key = $this->normalizeName($vm['hypervisor']);
			if (!array_key_exists($key, $referenced_hypervisors)) {
				$referenced_hypervisors[$key] = [
					'name' => $vm['hypervisor'],
					'cluster' => $vm['cluster'],
					'datacenter' => $vm['datacenter'],
					'vms' => 0,
					'powered_on' => 0,
					'vcpu' => 0,
					'memory' => 0,
					'memory_used' => 0,
					'cpu_weight' => 0
				];
			}
			$referenced_hypervisors[$key]['vms']++;
			$referenced_hypervisors[$key]['powered_on'] += $vm['power_value'] === 1 ? 1 : 0;
			$referenced_hypervisors[$key]['vcpu'] += $vm['vcpu_raw'];
			$referenced_hypervisors[$key]['memory'] += $vm['memory_total_raw'];
			$referenced_hypervisors[$key]['memory_used'] += $vm['memory_used_raw'];
			$referenced_hypervisors[$key]['cpu_weight'] += ($vm['cpu_raw'] ?? 0) * max(1, $vm['vcpu_raw']);
		}

		// Some roles can read the VM hosts but the item search does not return the ESXi host prototype.
		// Resolve the visible host by its display name and then read its vmware.hv.* items directly.
		if ($show_hypervisors && $referenced_hypervisors) {
			$matched_hosts = $this->getMatchingHypervisorHosts($referenced_hypervisors, $include_maintenance);
			$extra_hostids = array_values(array_diff(array_keys($matched_hosts), $hv_hostids));
			$extra_items = $extra_hostids ? $this->getHypervisorItemsByHost($extra_hostids) : [];
			// Un host con el mismo nombre pero sin ítems vmware.hv.* (p. ej. monitoreado por agente o ICMP) no es
			// el ESXi de la plantilla VMware: no se usa.
			$extra_hostids = array_values(array_filter($extra_hostids, static fn($hostid): bool =>
				array_filter($extra_items[(string) $hostid] ?? [], static fn(array $item): bool =>
					str_starts_with((string) $item['key_'], self::HV_MARKER)) !== []));
			if ($extra_hostids) {
				$hosts += array_intersect_key($matched_hosts, array_flip($extra_hostids));
				foreach ($extra_items as $hostid => $host_items) $items_by_host[$hostid] = $host_items;
				$problem_counts += $this->getProblemCounts($extra_hostids);
				$hv_hostids = array_values(array_unique(array_merge($hv_hostids, $extra_hostids)));
			}
		}

		$hypervisors = [];
		$resolved_hypervisors = [];
		foreach ($hv_hostids as $hostid) {
			$host_items = $items_by_host[$hostid] ?? [];
			$canonical_name = $this->hypervisorNameFromHost($hosts[$hostid], $hypervisor_discovery);
			if ($canonical_name === '') {
				$canonical_name = $this->hypervisorNameFromItems(
					$host_items,
					(string) $hosts[$hostid]['name'],
					$hypervisor_discovery
				);
			}
			$central_set = $this->findHypervisorItemSet($canonical_name, $hypervisor_item_sets);
			if ($central_set !== null) {
				$host_items = $this->mergeHypervisorItems($host_items, $central_set['items']);
			}
			$hypervisor = $this->makeHypervisorRow(
				$hosts[$hostid],
				$host_items,
				$problem_counts[$hostid] ?? 0
			);
			$central_problems = $this->countEntityProblems($hypervisor['name'], $vmware_alarm_problems);
			$hypervisor['zabbix_problems'] += $central_problems;
			$hypervisor['problems'] += $central_problems;
			$hypervisor['risk_score'] += $central_problems * 1000000;
			$key = $this->normalizeName($canonical_name);
			$reference_key = $this->matchingNameKey($canonical_name, $referenced_hypervisors);
			if ($reference_key !== null) {
				$hypervisor['name'] = $referenced_hypervisors[$reference_key]['name'];
			}

			if ($groupids !== null && $reference_key === null) {
				continue;
			}

			if ($reference_key !== null) {
				$hypervisor = $this->applyHypervisorVmFallback(
					$hypervisor,
					$referenced_hypervisors[$reference_key]
				);
			}

			$slot = $reference_key ?? 'host:'.$key;
			$hypervisor['available_items'] = count(array_filter($host_items, fn(array $item): bool =>
				$this->isItemValueAvailable($item)));
			if (!isset($hypervisors[$slot]) || $hypervisor['available_items'] > $hypervisors[$slot]['available_items']) {
				$hypervisors[$slot] = $hypervisor;
			}
			$resolved_hypervisors[$slot] = true;
		}
		$hypervisors = array_values($hypervisors);

		if ($show_hypervisors) {
			foreach ($referenced_hypervisors as $key => $reference) {
				if (!array_key_exists($key, $resolved_hypervisors)) {
					$set = $this->findHypervisorItemSet($reference['name'], $hypervisor_item_sets);
					if ($set !== null) {
						$pseudo_host = [
							'hostid' => $set['hostid'],
							'name' => $reference['name'],
							'status' => HOST_STATUS_MONITORED,
							'maintenance_status' => HOST_MAINTENANCE_STATUS_OFF,
							'interfaces' => []
						];
						$hypervisor = $this->makeHypervisorRow(
							$pseudo_host,
							$set['items'],
							$problem_counts[$set['hostid']] ?? 0
						);
						$hypervisor['name'] = $reference['name'];
						$central_problems = $this->countEntityProblems($reference['name'], $vmware_alarm_problems);
						$hypervisor['zabbix_problems'] += $central_problems;
						$hypervisor['problems'] += $central_problems;
						$hypervisor['risk_score'] += $central_problems * 1000000;
						$hypervisor = $this->applyHypervisorVmFallback($hypervisor, $reference);
						$hypervisors[] = $hypervisor;
					}
					else {
						$hypervisor = $this->makeInferredHypervisorRow($reference);
						$central_problems = $this->countEntityProblems($reference['name'], $vmware_alarm_problems);
						$hypervisor['zabbix_problems'] = $central_problems;
						$hypervisor['problems'] += $central_problems;
						$hypervisor['risk_score'] += $central_problems * 1000000;
						$hypervisors[] = $hypervisor;
					}
				}
			}
		}

		foreach ($vms as &$vm) {
			if ($vm['zabbix_problems'] > 0) $vm['row_tone'] = 'critical';
		}
		unset($vm);

		foreach ($hypervisors as &$hypervisor) {
			$worst = max((float) ($hypervisor['cpu_raw'] ?? 0), (float) ($hypervisor['memory_percent_raw'] ?? 0));
			$hypervisor['row_tone'] = ($hypervisor['zabbix_problems'] > 0 || $worst >= $this->resource_critical
					|| ($hypervisor['connection_value'] !== null && $hypervisor['connection_value'] !== 0)
					|| $hypervisor['health_value'] === 3)
				? 'critical'
				: ($hypervisor['resource_alerts'] > 0 ? 'warning' : '');
		}
		unset($hypervisor);

		usort($vms, static fn(array $a, array $b): int => ($b['risk_score'] <=> $a['risk_score'])
			?: strnatcasecmp($a['name'], $b['name']));
		usort($hypervisors, static fn(array $a, array $b): int => ($b['risk_score'] <=> $a['risk_score'])
			?: strnatcasecmp($a['name'], $b['name']));

		$datastores = $this->getUniqueDatastores($datastore_warning, $datastore_critical);

		$summary = [
			'total' => count($vms),
			'on' => count(array_filter($vms, static fn(array $vm): bool => $vm['power_value'] === 1)),
			'off' => count(array_filter($vms, static fn(array $vm): bool => $vm['power_value'] === 0)),
			'suspended' => count(array_filter($vms, static fn(array $vm): bool => $vm['power_value'] === 2)),
			'problems' => count(array_filter($vms, static fn(array $vm): bool => $vm['problems'] > 0)),
			'hypervisors' => count($hypervisors),
			'vcpu' => array_sum(array_column($vms, 'vcpu_raw')),
			'memory' => array_sum(array_column($vms, 'memory_total_raw')),
			'filesystems' => array_sum(array_column($vms, 'filesystem_count')),
			'networks' => array_sum(array_column($vms, 'network_count')),
			'datastores' => count($datastores),
			'datastore_alerts' => count(array_filter(
				$datastores,
				static fn(array $datastore): bool => in_array($datastore['status'], ['warning', 'critical'], true)
			)),
			'datastore_critical' => count(array_filter(
				$datastores, static fn(array $datastore): bool => $datastore['status'] === 'critical'
			)),
			'hypervisor_alerts' => count(array_filter($hypervisors, static fn(array $hv): bool => $hv['problems'] > 0)),
			'snapshots' => count(array_filter($vms, static fn(array $vm): bool => ($vm['snapshots'] ?? 0) > 0)),
			'consolidation' => count(array_filter($vms, static fn(array $vm): bool => $vm['consolidation_needed']))
		];

		$this->setResponse(new CControllerResponseData([
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'vms' => $vms,
			'hypervisors' => $hypervisors,
			'datastores' => $datastores,
			'summary' => $summary,
			'rows_per_page' => (int) $this->fields_values['rows_per_page'],
			'disk_warning' => $warning,
			'disk_critical' => $critical,
			'resource_warning' => $resource_warning,
			'resource_critical' => $resource_critical,
			'datastore_warning' => $datastore_warning,
			'datastore_critical' => $datastore_critical,
			'diagnostic_token' => CCsrfTokenHelper::get('widget.vmwareoverview.diagnostic.run'),
			'can_scan_ports' => $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN,
			'generated' => zbx_date2str(DATE_TIME_FORMAT_SECONDS, time()),
			'user' => [
				'debug_mode' => $this->getDebugMode()
			]
		]));
	}

	/**
	 * Normaliza un par de umbrales (advertencia < crítico). Usa los valores por defecto si el widget fue guardado
	 * con una versión anterior que no tenía el campo.
	 */
	private function thresholdPair(string $warning_field, string $critical_field, int $default_warning,
			int $default_critical): array {
		$warning = (int) ($this->fields_values[$warning_field] ?? $default_warning);
		$critical = (int) ($this->fields_values[$critical_field] ?? $default_critical);
		$warning = max(1, min(99, $warning ?: $default_warning));
		$critical = max($warning + 1, min(100, $critical ?: $default_critical));

		return [$warning, $critical];
	}

	private function getHostIdsByMarker(string $marker, ?array $groupids): array {
		$options = [
			'output' => ['hostid'],
			'search' => ['key_' => $marker],
			'templated' => false, 'webitems' => true
		];

		if ($groupids !== null) {
			$options['groupids'] = $groupids;
		}

		$items = API::Item()->get($options);

		return array_values(array_unique(array_column($items, 'hostid')));
	}

	private function getHosts(array $hostids, bool $include_maintenance): array {
		if (!$hostids) {
			return [];
		}

		$options = [
			'output' => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
			'hostids' => $hostids,
			'selectInterfaces' => ['interfaceid', 'ip', 'dns', 'useip', 'main', 'type'],
			'selectInventory' => ['os', 'os_full', 'type_full'],
			'selectMacros' => ['macro', 'value'],
			'preservekeys' => true
		];

		if (!$include_maintenance) {
			$options['filter'] = ['maintenance_status' => HOST_MAINTENANCE_STATUS_OFF];
		}

		return API::Host()->get($options);
	}

	private function getItemsByHost(array $hostids): array {
		if (!$hostids) {
			return [];
		}

		$items = API::Item()->get([
			'output' => [
				'itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type', 'state', 'status',
				'error'
			],
			'hostids' => $hostids,
			'search' => ['key_' => ['vmware.', 'icmpping']],
			'searchByAny' => true,
			'selectTags' => ['tag', 'value'],
			'templated' => false, 'webitems' => true
		]);

		$result = [];
		foreach ($items as $item) {
			$result[$item['hostid']][] = $item;
		}

		return $result;
	}

	private function getHypervisorItemSets(array $discovery): array {
		$items = API::Item()->get([
			'output' => [
				'itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type', 'state', 'status',
				'error'
			],
			'search' => ['key_' => 'vmware.hv.'],
			'selectTags' => ['tag', 'value'],
			'selectHosts' => ['hostid', 'host', 'name'],
			'templated' => false, 'webitems' => true
		]);

		$result = [];
		foreach ($items as $item) {
			$name = $this->hypervisorNameFromItem($item, $discovery);
			if ($name === '') {
				continue;
			}
			foreach ($this->nameAliases($name) as $key) {
				$result[$key]['hostid'] = $item['hostid'];
				$result[$key]['items'][] = $item;
			}
		}

		return $result;
	}

	/**
	 * UUID del ESXi → nombre visible del host en Zabbix. Se obtiene del nombre técnico del host (la plantilla usa
	 * {#HV.UUID}) o de la macro {$VMWARE.HV.UUID}. La regla LLD vmware.hv.discovery no sirve para esto: item.get
	 * nunca devuelve reglas de descubrimiento.
	 */
	private function getHypervisorUuidMap(array $hosts, array $hv_hostids): array {
		$map = [];
		foreach ($hv_hostids as $hostid) {
			$host = $hosts[$hostid] ?? null;
			if ($host === null) continue;
			$name = trim((string) $host['name']);
			$candidates = [(string) ($host['host'] ?? '')];
			foreach ($host['macros'] ?? [] as $macro) {
				if (str_contains(mb_strtoupper((string) ($macro['macro'] ?? '')), 'HV.UUID')) {
					$candidates[] = (string) ($macro['value'] ?? '');
				}
			}
			foreach ($candidates as $uuid) {
				$uuid = mb_strtolower(trim($uuid));
				if ($uuid !== '' && $this->looksLikeUuid($uuid) && $name !== '') $map[$uuid] = $name;
			}
		}
		return $map;
	}

	private function getHypervisorDiscoveryMap(): array {
		try {
			$items = API::Item()->get([
				'output' => ['itemid', 'key_', 'lastvalue', 'lastclock', 'state', 'status'],
				'search' => ['key_' => 'vmware.hv.discovery['],
				'templated' => false, 'webitems' => true
			]);
		}
		catch (\Throwable $e) {
			return [];
		}

		$map = [];
		foreach ($items as $item) {
			if (!$this->isItemValueAvailable($item)) continue;
			$rows = json_decode((string) $item['lastvalue'], true);
			if (!is_array($rows)) continue;
			if (isset($rows['data']) && is_array($rows['data'])) $rows = $rows['data'];

			foreach ($rows as $row) {
				if (!is_array($row)) continue;
				$uuid = trim((string) ($row['{#HV.UUID}'] ?? $row['uuid'] ?? ''));
				$name = trim((string) ($row['{#HV.NAME}'] ?? $row['name'] ?? ''));
				if ($uuid === '' || $name === '') continue;
				$map[mb_strtolower($uuid)] = $name;
			}
		}

		return $map;
	}

	private function getMatchingHypervisorHosts(array $references, bool $include_maintenance): array {
		// Antes se leían todos los hosts de la instalación en cada refresco. Ahora se buscan sólo los candidatos
		// cuyo nombre visible o técnico empieza por el nombre corto de los ESXi referenciados por las VMs.
		$prefixes = [];
		foreach ($references as $reference) {
			$short = explode('.', $this->normalizeName((string) $reference['name']), 2)[0];
			if ($short !== '') $prefixes[$short] = true;
		}
		if (!$prefixes) return [];

		$options = [
			'output' => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
			'selectInterfaces' => ['interfaceid', 'ip', 'dns', 'useip', 'main', 'type'],
			'selectMacros' => ['macro', 'value'],
			'search' => ['name' => array_keys($prefixes)],
			'searchByAny' => true,
			'preservekeys' => true
		];
		if (!$include_maintenance) $options['filter'] = ['maintenance_status' => HOST_MAINTENANCE_STATUS_OFF];

		try {
			$candidates = API::Host()->get($options);
		}
		catch (\Throwable $e) {
			return [];
		}

		$result = [];
		foreach ($candidates as $hostid => $candidate) {
			foreach ($references as $reference) {
				$name = (string) $reference['name'];
				if ($this->sameName((string) $candidate['name'], $name)
						|| $this->sameName((string) ($candidate['host'] ?? ''), $name)) {
					$result[(string) $hostid] = $candidate;
					break;
				}
			}
		}
		return $result;
	}

	private function getHypervisorItemsByHost(array $hostids): array {
		if (!$hostids) return [];
		try {
			$items = API::Item()->get([
				'output' => [
					'itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type', 'state',
					'status', 'error'
				],
				'hostids' => $hostids,
				'selectTags' => ['tag', 'value'],
				'templated' => false, 'webitems' => true
			]);
		}
		catch (\Throwable $e) {
			return [];
		}

		$result = [];
		foreach ($items as $item) {
			if (str_starts_with((string) $item['key_'], self::HV_MARKER) || str_starts_with((string) $item['key_'], 'icmpping')) {
				$result[(string) $item['hostid']][] = $item;
			}
		}
		return $result;
	}

	private function getProblemCounts(array $hostids): array {
		if (!$hostids) {
			return [];
		}

		$counts = array_fill_keys($hostids, 0);

		try {
			$problems = API::Problem()->get([
				'output' => ['eventid', 'objectid'],
				'hostids' => $hostids,
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'recent' => false
			]);
			$events_by_trigger = [];
			foreach ($problems as $problem) {
				$events_by_trigger[(string) $problem['objectid']][(string) $problem['eventid']] = true;
			}
			if ($events_by_trigger) {
				$triggers = API::Trigger()->get([
					'output' => ['triggerid'],
					'triggerids' => array_keys($events_by_trigger),
					'selectHosts' => ['hostid'],
					'filter' => ['status' => TRIGGER_STATUS_ENABLED]
				]);
				foreach ($triggers as $trigger) {
					$event_count = count($events_by_trigger[(string) $trigger['triggerid']] ?? []);
					foreach ($trigger['hosts'] ?? [] as $host) {
						if (array_key_exists($host['hostid'], $counts)) {
							$counts[$host['hostid']] += $event_count;
						}
					}
				}
			}
		}
		catch (\Throwable $e) {
			// Trigger API below is also used as a compatibility fallback.
		}

		try {
			$trigger_counts = array_fill_keys($hostids, 0);
			$triggers = API::Trigger()->get([
				'output' => ['triggerid'], 'hostids' => $hostids,
				'filter' => ['value' => TRIGGER_VALUE_TRUE, 'status' => TRIGGER_STATUS_ENABLED],
				'selectHosts' => ['hostid'], 'skipDependent' => true
			]);
			foreach ($triggers as $trigger) {
				foreach ($trigger['hosts'] ?? [] as $host) {
					if (array_key_exists($host['hostid'], $trigger_counts)) $trigger_counts[$host['hostid']]++;
				}
			}
			foreach ($hostids as $hostid) $counts[$hostid] = max($counts[$hostid], $trigger_counts[$hostid]);
		}
		catch (\Throwable $ignored) {
			// Keep the resource table available when the current role cannot read problems.
		}

		return $counts;
	}

	private function getVmwareAlarmProblems(): array {
		try {
			$alarm_items = API::Item()->get([
				'output' => ['hostid'], 'search' => ['key_' => 'vmware.alarms.get['], 'templated' => false, 'webitems' => true
			]);
			$hostids = array_values(array_unique(array_column($alarm_items, 'hostid')));
			if (!$hostids) return [];

			return API::Problem()->get([
				'output' => ['eventid', 'name', 'severity', 'clock', 'acknowledged'],
				'hostids' => $hostids, 'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER, 'recent' => false,
				'selectTags' => ['tag', 'value']
			]);
		}
		catch (\Throwable $e) {
			return [];
		}
	}

	private function countEntityProblems(string $entity, array $problems): int {
		$count = 0;
		foreach ($problems as $problem) {
			$haystack = (string) ($problem['name'] ?? '');
			foreach ($problem['tags'] ?? [] as $tag) $haystack .= ' '.(string) ($tag['value'] ?? '');
			$haystack = mb_strtolower($haystack);
			foreach ($this->nameAliases($entity) as $alias) {
				if ($this->textMentions($haystack, $alias)) {
					$count++;
					break;
				}
			}
		}
		return $count;
	}

	private function getUniqueDatastores(int $disk_warning, int $disk_critical): array {
		$items = [];
		foreach (['vmware.datastore.size[', 'vmware.hv.datastore.size['] as $marker) {
			$items = array_merge($items, API::Item()->get([
				'output' => [
					'itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type', 'state',
					'status', 'error'
				],
				'search' => ['key_' => $marker],
				'selectTags' => ['tag', 'value'],
				'selectHosts' => ['hostid', 'name'],
				'templated' => false, 'webitems' => true
			]));
		}

		$datastores = [];
		foreach ($items as $item) {
			$name = $this->itemTagValue($item, 'datastore');
			if ($name === '' && preg_match('/\[([^\]]+)\]/', $item['name'], $matches)) {
				$name = $matches[1];
			}
			if ($name === '') {
				continue;
			}

			$key = mb_strtolower($name);
			$is_central = strpos($item['key_'], 'vmware.datastore.size[') === 0;
			$metric = 'total';
			if (preg_match('/,(free|pfree|uncommitted)\]$/', $item['key_'], $matches)) {
				$metric = $matches[1];
			}

			if (!array_key_exists($key, $datastores)) {
				$datastores[$key] = [
					'name' => $name,
					'type' => $this->itemTagValue($item, 'type') ?: '—',
					'total_raw' => null,
					'free_raw' => null,
					'pfree_raw' => null,
					'uncommitted_raw' => null,
					'lastclock' => 0,
					'sources' => [],
					'central' => false
				];
			}

			$host_name = $item['hosts'][0]['name'] ?? '';
			if ($host_name !== '') {
				$datastores[$key]['sources'][$host_name] = true;
			}
			if ($datastores[$key]['type'] === '—') {
				$datastores[$key]['type'] = $this->itemTagValue($item, 'type') ?: '—';
			}

			if ($is_central || !$datastores[$key]['central']) {
				if ($is_central && !$datastores[$key]['central']) {
					$datastores[$key]['total_raw'] = null;
					$datastores[$key]['free_raw'] = null;
					$datastores[$key]['pfree_raw'] = null;
					$datastores[$key]['uncommitted_raw'] = null;
				}
				$datastores[$key][$metric.'_raw'] = $this->numberValue($item);
				$datastores[$key]['central'] = $is_central || $datastores[$key]['central'];
				$datastores[$key]['lastclock'] = max($datastores[$key]['lastclock'], (int) $item['lastclock']);
			}
		}

		$warning_free = max(0, 100 - $disk_warning);
		$critical_free = max(0, 100 - $disk_critical);
		$result = [];
		foreach ($datastores as $datastore) {
			if ($datastore['pfree_raw'] === null && $datastore['total_raw'] > 0 && $datastore['free_raw'] !== null) {
				$datastore['pfree_raw'] = ($datastore['free_raw'] / $datastore['total_raw']) * 100;
			}
			if ($datastore['free_raw'] === null && $datastore['total_raw'] > 0 && $datastore['pfree_raw'] !== null) {
				$datastore['free_raw'] = $datastore['total_raw'] * $datastore['pfree_raw'] / 100;
			}
			$datastore['pused_raw'] = $datastore['pfree_raw'] !== null
				? max(0, min(100, 100 - $datastore['pfree_raw']))
				: null;
			$datastore['status'] = $datastore['pfree_raw'] === null
				? 'unknown'
				: ($datastore['pfree_raw'] <= $critical_free
					? 'critical'
					: ($datastore['pfree_raw'] <= $warning_free ? 'warning' : 'ok'));
			$datastore['total'] = $this->formatBytes($datastore['total_raw']);
			$datastore['free'] = $this->formatBytes($datastore['free_raw']);
			$datastore['pfree'] = $this->formatPercent($datastore['pfree_raw']);
			$datastore['pused'] = $this->formatPercent($datastore['pused_raw']);
			$datastore['sources'] = array_keys($datastore['sources']);
			$datastore['shared_count'] = count($datastore['sources']);
			$datastore['last_update'] = $datastore['lastclock'] > 0
				? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $datastore['lastclock'])
				: '—';
			$result[] = $datastore;
		}

		usort($result, static function(array $a, array $b): int {
			$weight = ['critical' => 0, 'warning' => 1, 'unknown' => 2, 'ok' => 3];
			$status = ($weight[$a['status']] ?? 9) <=> ($weight[$b['status']] ?? 9);
			return $status !== 0 ? $status : strnatcasecmp($a['name'], $b['name']);
		});

		return $result;
	}

	private function itemTagValue(array $item, string $name): string {
		foreach ($item['tags'] ?? [] as $tag) {
			if ($tag['tag'] === $name) {
				return (string) $tag['value'];
			}
		}

		return '';
	}

	private function hypervisorNameFromItems(array $items, string $fallback, array $discovery = []): string {
		foreach ($this->nameAliases($fallback) as $alias) {
			foreach ($discovery as $discovered_name) {
				if ($this->sameName($alias, $discovered_name)) return $discovered_name;
			}
		}

		foreach ($items as $item) {
			$name = $this->hypervisorNameFromItem($item, $discovery);
			if ($name !== '') {
				return $name;
			}
		}

		return $fallback;
	}

	private function hypervisorNameFromHost(array $host, array $discovery): string {
		foreach ([(string) ($host['name'] ?? ''), (string) ($host['host'] ?? '')] as $host_name) {
			foreach ($discovery as $uuid => $discovered_name) {
				if ($this->sameName($host_name, $discovered_name)
						|| mb_strtolower(trim($host_name)) === $uuid) return $discovered_name;
			}
		}
		foreach ($host['macros'] ?? [] as $macro) {
			$macro_name = mb_strtoupper((string) ($macro['macro'] ?? ''));
			$value = mb_strtolower(trim((string) ($macro['value'] ?? '')));
			if (str_contains($macro_name, 'HV.UUID') && isset($discovery[$value])) return $discovery[$value];
		}
		return '';
	}

	private function hypervisorNameFromItem(array $item, array $discovery = []): string {
		$host_name = trim((string) ($item['hosts'][0]['name'] ?? ''));
		$technical_name = trim((string) ($item['hosts'][0]['host'] ?? ''));

		foreach ($discovery as $uuid => $discovered_name) {
			if (($host_name !== '' && $this->sameName($host_name, $discovered_name))
					|| mb_strtolower($host_name) === $uuid || mb_strtolower($technical_name) === $uuid) {
				return $discovered_name;
			}
			if (str_contains(mb_strtolower((string) $item['key_']), $uuid)) return $discovered_name;
			foreach ($item['tags'] ?? [] as $tag) {
				if (mb_strtolower(trim((string) ($tag['value'] ?? ''))) === $uuid) return $discovered_name;
			}
		}

		foreach (['hypervisor', 'hv', 'esxi', 'host'] as $tag_name) {
			$value = $this->itemTagValue($item, $tag_name);
			if ($value !== '' && !$this->looksLikeUuid($value)) {
				return $value;
			}
		}
		if (preg_match('/(?:hypervisor|hipervisor|esxi)\s*\[([^\]]+)\]/iu', (string) $item['name'], $matches)) {
			return trim($matches[1]);
		}

		return $host_name;
	}

	private function looksLikeUuid(string $value): bool {
		$value = trim($value);
		return preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $value) === 1
			|| preg_match('/^[0-9a-f]{32}$/i', $value) === 1;
	}

	private function normalizeName(string $name): string {
		return mb_strtolower(rtrim(trim($name), '.'));
	}

	private function nameAliases(string $name): array {
		$normalized = $this->normalizeName($name);
		if ($normalized === '') return [];
		$aliases = [$normalized => true];
		// Un ESXi registrado por IP (10.0.0.5) no tiene nombre corto: recortarlo a "10" mezclaría hipervisores.
		if (filter_var($normalized, FILTER_VALIDATE_IP) === false) {
			$short = explode('.', $normalized, 2)[0];
			if ($short !== '') $aliases[$short] = true;
		}
		return array_keys($aliases);
	}

	/**
	 * Coincidencia de una entidad dentro de un texto respetando límites de palabra: "web" no debe coincidir con
	 * "web01" ni con "webserver", pero "esx01" sí con "esx01.dominio.local".
	 */
	private function textMentions(string $haystack, string $alias): bool {
		if ($alias === '') return false;
		return preg_match('/(?<![\p{L}\p{N}_-])'.preg_quote($alias, '/').'(?![\p{L}\p{N}_-])/u', $haystack) === 1;
	}

	private function matchingNameKey(string $name, array $references): ?string {
		$aliases = $this->nameAliases($name);
		foreach ($references as $key => $reference) {
			if (array_intersect($aliases, $this->nameAliases((string) $reference['name']))) return (string) $key;
		}
		foreach ($references as $key => $reference) {
			if ($this->sameName($name, (string) $reference['name'])) return (string) $key;
		}
		return null;
	}

	private function sameName(string $left, string $right): bool {
		if (array_intersect($this->nameAliases($left), $this->nameAliases($right))) return true;
		return $this->containsName($left, $right) || $this->containsName($right, $left);
	}

	/**
	 * true si $haystack contiene el nombre $needle completo como "palabra", p. ej. el host
	 * "DCP-HV-prodvmware01.ypfbtr.local" contiene al ESXi "prodvmware01.ypfbtr.local". También acepta que la
	 * parte corta del host termine en la parte corta del ESXi ("DCP-HV-prodvmware01" ↔ "prodvmware01").
	 * No se aplica a direcciones IP para no confundir 10.0.0.5 con 10.0.0.55.
	 */
	private function containsName(string $haystack, string $needle): bool {
		$haystack = $this->normalizeName($haystack);
		$needle = $this->normalizeName($needle);
		if ($haystack === '' || mb_strlen($needle) < 4 || $haystack === $needle
				|| filter_var($needle, FILTER_VALIDATE_IP) !== false) {
			return false;
		}
		if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}_-])/u', $haystack) === 1) {
			return true;
		}
		$short_needle = explode('.', $needle, 2)[0];
		$short_haystack = explode('.', $haystack, 2)[0];
		return mb_strlen($short_needle) >= 4 && $short_haystack !== $short_needle
			&& preg_match('/[^\p{L}\p{N}]'.preg_quote($short_needle, '/').'$/u', $short_haystack) === 1;
	}

	private function findHypervisorItemSet(string $name, array $sets): ?array {
		foreach ($this->nameAliases($name) as $alias) if (array_key_exists($alias, $sets)) return $sets[$alias];
		foreach ($sets as $key => $set) {
			if ($this->sameName($name, (string) $key)) return $set;
		}
		return null;
	}

	private function mergeHypervisorItems(array $direct, array $central): array {
		$merged = [];
		foreach (array_merge($direct, $central) as $item) {
			$merged[(string) $item['itemid']] = $item;
		}
		$merged = array_values($merged);
		usort($merged, function(array $left, array $right): int {
			$available = (int) $this->isItemValueAvailable($right) <=> (int) $this->isItemValueAvailable($left);
			return $available !== 0 ? $available : ((int) $right['lastclock'] <=> (int) $left['lastclock']);
		});
		return $merged;
	}

	private function makeVmRow(array $host, array $items, int $problems, int $warning, int $critical): array {
		$power = $this->powerValue($this->findItem($items, 'vmware.vm.powerstate['));
		$cpu = $this->numberValue($this->findItem($items, 'vmware.vm.cpu.usage.perf['));
		$vcpu = $this->numberValue($this->findItem($items, 'vmware.vm.cpu.num['));
		$memory_total = $this->numberValue($this->findItem($items, 'vmware.vm.memory.size['));
		$memory_guest = $this->numberValue($this->findItem($items, 'vmware.vm.memory.size.usage.guest['));
		$memory_percent = $memory_total > 0 && $memory_guest !== null
			? ($memory_guest / $memory_total) * 100
			: $this->numberValue($this->findItem($items, 'vmware.vm.memory.usage['));
		$memory_used = $memory_guest ?? ($memory_total > 0 && $memory_percent !== null
			? $memory_total * $memory_percent / 100
			: null);
		$storage = $this->numberValue($this->findItem($items, 'vmware.vm.storage.committed['));
		$storage_uncommitted = $this->numberValue($this->findItem($items, 'vmware.vm.storage.uncommitted['));
		$storage_total = $storage !== null || $storage_uncommitted !== null
			? ($storage ?? 0) + ($storage_uncommitted ?? 0)
			: null;
		$tools_status_item = $this->findToolsItem($items, 'status');
		$tools_version_item = $this->findToolsItem($items, 'version');
		$tools = $this->toolsValue($tools_status_item);
		$tools_inferred = false;
		if ($tools === null && $this->hasGuestToolsEvidence($items)) {
			$tools = 2.0;
			$tools_inferred = true;
		}
		$snapshots = $this->snapshotCount($items);
		$uptime = $this->numberValue($this->findItem($items, 'vmware.vm.guest.osuptime['));

		if ($uptime === null) {
			$uptime = $this->numberValue($this->findItem($items, 'vmware.vm.uptime['));
		}

		$disks = $this->getVmDisks($items, $warning, $critical);
		$disk_badges = $this->getDiskBadges($disks);
		$resource_alerts = $this->resourceAlertCount($cpu, $memory_percent, $disk_badges);
		$max_disk_usage = 0.0;
		foreach ($disk_badges as $disk) $max_disk_usage = max($max_disk_usage, (float) ($disk['used_percent_raw'] ?? 0));
		$risk_score = ($problems + $resource_alerts) * 1000000
			+ max((float) ($cpu ?? 0), (float) ($memory_percent ?? 0), $max_disk_usage) * 100;
		$network_count = $this->countDiscoveredNetworks($items);
		$last_update = $this->lastUpdate($items);
		$inventory = $host['inventory'] ?? [];
		$os = $inventory['os_full'] ?? '';
		if ($os === '') {
			$os = $inventory['os'] ?? '';
		}
		if ($os === '') {
			$os = $inventory['type_full'] ?? '';
		}
		$guest_os = $this->textValue($this->findGuestOsItem($items));
		if ($guest_os !== '—') $os = $guest_os;

		// Plantilla oficial (7.0–8.0): mapa "VMware Consolidation" → 0 = True (requiere consolidar), 1 = False.
		$consolidation = $this->normalizedValue($this->findItem($items, 'vmware.vm.consolidationneeded['));
		$consolidation_needed = in_array($consolidation, ['0', 'true'], true);
		// Sólo existe en la plantilla de Zabbix 8.0: 1 = el ESXi de la VM está en modo mantenimiento.
		$hv_maintenance = $this->normalizedValue($this->findItem($items, 'vmware.vm.hv.maintenance['));
		$cpu_ready = $this->numberValue($this->findItem($items, 'vmware.vm.cpu.readiness['));

		$worst = max((float) ($cpu ?? 0), (float) ($memory_percent ?? 0));
		$row_tone = ($problems > 0 || $worst >= $this->resource_critical
				|| in_array('critical', array_column($disk_badges, 'status'), true))
			? 'critical'
			: (($resource_alerts > 0 || $consolidation_needed) ? 'warning' : '');

		return [
			'hostid' => $host['hostid'],
			'name' => $host['name'],
			'host_url' => 'zabbix.php?action=host.dashboard.view&hostid='.$host['hostid'],
			'enabled' => (int) $host['status'] === HOST_STATUS_MONITORED,
			'maintenance' => (int) $host['maintenance_status'] === HOST_MAINTENANCE_STATUS_ON,
			'ip' => $this->getHostAddress($host['interfaces'] ?? []),
			'os' => $os,
			'os_family' => $this->osFamily($os),
			'power_value' => $power !== null ? (int) $power : null,
			'power_text' => $this->vmPowerText($power),
			'power_class' => $this->vmPowerClass($power),
			'cpu_raw' => $cpu,
			'cpu' => $this->formatPercent($cpu),
			'vcpu_raw' => (int) ($vcpu ?? 0),
			'vcpu' => $vcpu !== null ? (string) (int) $vcpu : '—',
			'memory_percent_raw' => $memory_percent,
			'memory_percent' => $this->formatPercent($memory_percent),
			'memory_used_raw' => (float) ($memory_used ?? 0),
			'memory_total_raw' => (float) ($memory_total ?? 0),
			'memory_total' => $this->formatBytes($memory_total),
			'storage' => $this->formatBytes($storage),
			'storage_raw' => $storage,
			'storage_total' => $this->formatBytes($storage_total),
			'hypervisor' => $this->textValue($this->findItem($items, 'vmware.vm.hv.name[')),
			'cluster' => $this->textValue($this->findItem($items, 'vmware.vm.cluster.name[')),
			'datacenter' => $this->textValue($this->findItem($items, 'vmware.vm.datacenter.name[')),
			'tools_value' => $tools !== null ? (int) $tools : null,
			'tools' => $tools_inferred ? 'Activo (inferido)' : $this->toolsText($tools),
			'tools_version' => $this->textValue($tools_version_item),
			'uptime' => $this->formatUptime($uptime),
			'uptime_raw' => $uptime,
			'snapshots' => $snapshots,
			'consolidation_needed' => $consolidation_needed,
			'hv_maintenance' => in_array($hv_maintenance, ['1', 'maintenance mode'], true),
			'cpu_ready_raw' => $cpu_ready,
			'row_tone' => $row_tone,
			'last_update' => $last_update,
			'item_count' => count($items),
			'filesystem_count' => count($disks),
			'network_count' => $network_count,
			'zabbix_problems' => $problems,
			'resource_alerts' => $resource_alerts,
			'problems' => $problems + $resource_alerts,
			'risk_score' => $risk_score,
			'disks' => $disks,
			'disk_badges' => $disk_badges,
			'hidden_disks' => max(0, count($disks) - count($disk_badges))
		];
	}

	private function makeHypervisorRow(array $host, array $items, int $problems): array {
		$connection = $this->connectionValue($this->findItem($items, 'vmware.hv.connectionstate['));
		$connection_from_ping = false;
		if ($connection === null) {
			// Respaldo: ítem "Hypervisor ping" (icmpping[]) de la plantilla VMware Hypervisor.
			$ping = $this->numberValue($this->findItem($items, 'icmpping'));
			if ($ping !== null) {
				$connection = (int) $ping === 1 ? 0.0 : 2.0;
				$connection_from_ping = true;
			}
		}
		$health = $this->healthValue(
			$this->findItem($items, 'vmware.hv.status[')
				?? $this->findItem($items, 'vmware.hv.sensor.health.state[')
		);
		$memory_total = $this->numberValue($this->findItem($items, 'vmware.hv.hw.memory['));
		$memory_used = $this->numberValue($this->findItem($items, 'vmware.hv.memory.used['));
		$memory_percent = $memory_total > 0 && $memory_used !== null ? ($memory_used / $memory_total) * 100 : null;
		$cpu_item = $this->findItem($items, 'vmware.hv.cpu.usage.perf[')
			?? $this->findItem($items, 'vmware.hv.cpu.utilization[');
		$cpu = $this->numberValue($cpu_item);
		if ($cpu === null) {
			$cpu = $this->cpuPercentFromHz($items);
		}
		$resource_alerts = $this->resourceAlertCount($cpu, $memory_percent);
		if ($connection !== null && (int) $connection !== 0) $resource_alerts++;
		if ($health !== null && (int) $health > 1) $resource_alerts++;
		$risk_score = ($problems + $resource_alerts) * 1000000
			+ max((float) ($cpu ?? 0), (float) ($memory_percent ?? 0)) * 100;

		return [
			'hostid' => $host['hostid'],
			'name' => $host['name'],
			'host_url' => 'zabbix.php?action=host.dashboard.view&hostid='.$host['hostid'],
			'enabled' => (int) $host['status'] === HOST_STATUS_MONITORED,
			'maintenance' => (int) $host['maintenance_status'] === HOST_MAINTENANCE_STATUS_ON,
			'ip' => $this->getHostAddress($host['interfaces'] ?? []),
			'connection_value' => $connection !== null ? (int) $connection : null,
			'connection' => $connection_from_ping
				? ((int) $connection === 0 ? 'Responde a ping' : 'Sin respuesta a ping')
				: $this->connectionText($connection),
			'connection_class' => $connection === null ? 'unknown' : ((int) $connection === 0 ? 'ok' : 'critical'),
			'health_value' => $health !== null ? (int) $health : null,
			'health' => $this->healthText($health),
			'health_class' => $this->healthClass($health),
			'cpu_raw' => $cpu,
			'cpu' => $this->formatPercent($cpu),
			'cores' => $this->textNumber($this->findItem($items, 'vmware.hv.hw.cpu.num[')),
			'threads' => $this->textNumber($this->findItem($items, 'vmware.hv.hw.cpu.threads[')),
			'memory_percent_raw' => $memory_percent,
			'memory_percent' => $this->formatPercent($memory_percent),
			'memory_used' => $this->formatBytes($memory_used),
			'memory_total' => $this->formatBytes($memory_total),
			'vms' => $this->textNumber($this->findItem($items, 'vmware.hv.vm.num[')),
			'cluster' => $this->textValue($this->findItem($items, 'vmware.hv.cluster.name[')),
			'datacenter' => $this->textValue($this->findItem($items, 'vmware.hv.datacenter.name[')),
			'version' => $this->textValue(
				$this->findItem($items, 'vmware.hv.fullname[')
					?? $this->findItem($items, 'vmware.hv.version[')
			),
			'model' => $this->textValue($this->findItem($items, 'vmware.hv.hw.model[')),
			'vendor' => $this->textValue($this->findItem($items, 'vmware.hv.hw.vendor[')),
			'uptime' => $this->formatUptime($this->numberValue($this->findItem($items, 'vmware.hv.uptime['))),
			'uptime_raw' => $this->numberValue($this->findItem($items, 'vmware.hv.uptime[')),
			'powered_on' => null,
			'allocated_vcpu' => null,
			'allocated_memory' => '—',
			'zabbix_problems' => $problems,
			'resource_alerts' => $resource_alerts,
			'problems' => $problems + $resource_alerts,
			'risk_score' => $risk_score,
			'inferred' => false
		];
	}

	/**
	 * CPU % = vmware.hv.cpu.usage (Hz) / (vmware.hv.hw.cpu.freq (Hz) × vmware.hv.hw.cpu.num núcleos).
	 */
	private function cpuPercentFromHz(array $items): ?float {
		$usage = $this->numberValue($this->findItem($items, 'vmware.hv.cpu.usage['));
		$freq = $this->numberValue($this->findItem($items, 'vmware.hv.hw.cpu.freq['));
		$cores = $this->numberValue($this->findItem($items, 'vmware.hv.hw.cpu.num['));
		if ($usage === null || !$freq || !$cores) return null;
		return max(0.0, min(100.0, $usage / ($freq * $cores) * 100));
	}

	private function makeInferredHypervisorRow(array $reference): array {
		$cpu = $reference['vcpu'] > 0 ? $reference['cpu_weight'] / $reference['vcpu'] : null;
		$memory_percent = $reference['memory'] > 0 ? $reference['memory_used'] / $reference['memory'] * 100 : null;
		$resource_alerts = $this->resourceAlertCount($cpu, $memory_percent);
		return [
			'hostid' => null,
			'name' => $reference['name'],
			'host_url' => '',
			'enabled' => true,
			'maintenance' => false,
			'ip' => '—',
			'connection_value' => null,
			'connection' => 'Inferido por VMs',
			'connection_class' => 'unknown',
			'health_value' => null,
			'health' => 'ESXi sin permiso/visibilidad',
			'health_class' => 'unknown',
			'cpu_raw' => $cpu,
			'cpu' => $this->formatPercent($cpu),
			'cores' => (string) $reference['vcpu'],
			'threads' => '—',
			'memory_percent_raw' => $memory_percent,
			'memory_percent' => $this->formatPercent($memory_percent),
			'memory_used' => $this->formatBytes($reference['memory_used']),
			'memory_total' => $this->formatBytes($reference['memory']),
			'vms' => (string) $reference['vms'],
			'cluster' => $reference['cluster'],
			'datacenter' => $reference['datacenter'],
			'version' => '—',
			'model' => '—',
			'vendor' => '—',
			'uptime' => '—',
			'uptime_raw' => null,
			'powered_on' => $reference['powered_on'],
			'allocated_vcpu' => $reference['vcpu'],
			'allocated_memory' => $this->formatBytes($reference['memory']),
			'zabbix_problems' => 0,
			'resource_alerts' => $resource_alerts,
			'problems' => $resource_alerts,
			'risk_score' => $resource_alerts * 1000000
				+ max((float) ($cpu ?? 0), (float) ($memory_percent ?? 0)) * 100,
			'inferred' => true
		];
	}

	private function applyHypervisorVmFallback(array $hypervisor, array $reference): array {
		$reference_cpu = $reference['vcpu'] > 0
			? $reference['cpu_weight'] / $reference['vcpu']
			: null;
		$reference_memory = $reference['memory'] > 0
			? $reference['memory_used'] / $reference['memory'] * 100
			: null;
		$inferred = false;

		if ($hypervisor['cpu_raw'] === null && $reference_cpu !== null) {
			$hypervisor['cpu_raw'] = $reference_cpu;
			$hypervisor['cpu'] = $this->formatPercent($reference_cpu);
			$inferred = true;
		}
		if ($hypervisor['memory_percent_raw'] === null && $reference_memory !== null) {
			$hypervisor['memory_percent_raw'] = $reference_memory;
			$hypervisor['memory_percent'] = $this->formatPercent($reference_memory);
			$hypervisor['memory_used'] = $this->formatBytes($reference['memory_used']);
			$hypervisor['memory_total'] = $this->formatBytes($reference['memory']);
			$inferred = true;
		}

		$hypervisor['vms'] = (string) $reference['vms'];
		$hypervisor['powered_on'] = $reference['powered_on'];
		$hypervisor['allocated_vcpu'] = $reference['vcpu'];
		$hypervisor['allocated_memory'] = $this->formatBytes($reference['memory']);
		if ($hypervisor['cluster'] === '—') $hypervisor['cluster'] = $reference['cluster'];
		if ($hypervisor['datacenter'] === '—') $hypervisor['datacenter'] = $reference['datacenter'];

		if ($inferred) {
			$hypervisor['inferred'] = true;
			if ($hypervisor['connection_value'] === null) {
				$hypervisor['connection'] = 'Inferido por VMs';
				$hypervisor['connection_class'] = 'unknown';
			}
			if ($hypervisor['health_value'] === null) {
				$hypervisor['health'] = 'ESXi sin datos VMware';
				$hypervisor['health_class'] = 'unknown';
			}
			$state_alerts = ($hypervisor['connection_value'] !== null && $hypervisor['connection_value'] !== 0 ? 1 : 0)
				+ ($hypervisor['health_value'] !== null && $hypervisor['health_value'] > 1 ? 1 : 0);
			$hypervisor['resource_alerts'] = $this->resourceAlertCount(
				$hypervisor['cpu_raw'],
				$hypervisor['memory_percent_raw']
			) + $state_alerts;
			$hypervisor['problems'] = $hypervisor['zabbix_problems'] + $hypervisor['resource_alerts'];
			$hypervisor['risk_score'] = $hypervisor['problems'] * 1000000
				+ max((float) ($hypervisor['cpu_raw'] ?? 0), (float) ($hypervisor['memory_percent_raw'] ?? 0)) * 100;
		}

		return $hypervisor;
	}

	private function resourceAlertCount(?float $cpu, ?float $memory, array $disks = []): int {
		$count = 0;
		if ($cpu !== null && $cpu >= $this->resource_warning) $count++;
		if ($memory !== null && $memory >= $this->resource_warning) $count++;
		foreach ($disks as $disk) {
			if (in_array($disk['status'] ?? '', ['warning', 'critical'], true)) $count++;
		}
		return $count;
	}

	private function getVmDisks(array $items, int $warning, int $critical): array {
		$disks = [];

		foreach ($items as $item) {
			if (strpos($item['key_'], 'vmware.vm.vfs.fs.size[') !== 0
					|| !preg_match('/,(free|pfree|total|used)\]$/', $item['key_'], $matches)) {
				continue;
			}

			$filesystem = '';
			foreach ($item['tags'] ?? [] as $tag) {
				if ($tag['tag'] === 'filesystem') {
					$filesystem = $tag['value'];
					break;
				}
			}

			if ($filesystem === '' && preg_match('/\[(.+)\]/', $item['name'], $name_match)) {
				$filesystem = $name_match[1];
			}

			if ($filesystem === '') {
				continue;
			}

			$metric = $matches[1];
			$disks[$filesystem]['filesystem'] = $filesystem;
			$disks[$filesystem][$metric] = $this->numberValue($item);
			$disks[$filesystem][$metric.'_itemid'] = $item['itemid'];
			$disks[$filesystem][$metric.'_supported'] = $this->isItemValueAvailable($item);
		}

		foreach ($disks as &$disk) {
			$free_percent = $disk['pfree'] ?? null;
			if ($free_percent === null && !empty($disk['total']) && array_key_exists('free', $disk)) {
				$free_percent = ($disk['free'] / $disk['total']) * 100;
			}

			$used_percent = $free_percent !== null ? max(0, min(100, 100 - $free_percent)) : null;
			$disk['used_percent_raw'] = $used_percent;
			$disk['used_percent'] = $this->formatPercent($used_percent);
			$disk['label'] = $this->filesystemLabel($disk['filesystem']);
			$disk['status'] = $used_percent === null
				? 'unknown'
				: ($used_percent >= $critical ? 'critical' : ($used_percent >= $warning ? 'warning' : 'ok'));
		}
		unset($disk);

		$disks = array_values($disks);
		usort($disks, static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

		return $disks;
	}

	private function getDiskBadges(array $disks): array {
		$preferred = [];
		$secondary = [];

		foreach ($disks as $disk) {
			$filesystem = $disk['filesystem'];
			$is_windows_drive = preg_match('/^[A-Za-z]:[\\\\\/]?$/', $filesystem) === 1;
			$is_linux_primary = $filesystem === '/'
				|| preg_match('#^/(boot|home|opt|srv|tmp|usr|var)(/)?$#', rtrim($filesystem, '/')) === 1;

			if ($is_windows_drive || $is_linux_primary) {
				$preferred[] = $disk;
			}
			else {
				$secondary[] = $disk;
			}
		}

		$status_weight = ['critical' => 0, 'warning' => 1, 'unknown' => 2, 'ok' => 3];
		$sort = static function(array $a, array $b) use ($status_weight): int {
			$status = ($status_weight[$a['status']] ?? 9) <=> ($status_weight[$b['status']] ?? 9);
			return $status !== 0 ? $status : strnatcasecmp($a['label'], $b['label']);
		};
		usort($preferred, $sort);
		usort($secondary, $sort);

		if ($preferred) {
			return array_slice($preferred, 0, 5);
		}

		return array_slice($secondary, 0, 1);
	}

	private function countDiscoveredNetworks(array $items): int {
		$interfaces = [];
		foreach ($items as $item) {
			if (strpos($item['key_'], 'vmware.vm.net.if.') !== 0 || strpos($item['key_'], '.discovery[') !== false) {
				continue;
			}

			foreach ($item['tags'] ?? [] as $tag) {
				if (in_array($tag['tag'], ['interface', 'network'], true) && $tag['value'] !== '') {
					$interfaces[$tag['value']] = true;
				}
			}

			if (preg_match('/vmware\.vm\.net\.if\.[^\[]+\[[^,]+,[^,]+,([^,\]]+)/', $item['key_'], $matches)) {
				$interfaces[trim($matches[1], '"')] = true;
			}
		}

		return count($interfaces);
	}

	private function lastUpdate(array $items): string {
		$lastclock = 0;
		foreach ($items as $item) {
			$lastclock = max($lastclock, (int) $item['lastclock']);
		}

		return $lastclock > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $lastclock) : '—';
	}

	private function findItem(array $items, string $prefix, ?string $suffix = null): ?array {
		foreach ($items as $item) {
			if (strpos($item['key_'], $prefix) === 0 && ($suffix === null || substr($item['key_'], -strlen($suffix)) === $suffix)) {
				return $item;
			}
		}

		return null;
	}

	private function findGuestOsItem(array $items): ?array {
		foreach ($items as $item) {
			$key = mb_strtolower((string) $item['key_']);
			if (str_starts_with($key, 'vmware.vm.property[') && str_contains($key, 'guest.guestfullname')) return $item;
		}
		return null;
	}

	private function osFamily(string $value): string {
		$value = mb_strtolower($value);
		if (str_contains($value, 'windows')) return 'Windows';
		foreach (['linux', 'ubuntu', 'debian', 'centos', 'red hat', 'rhel', 'rocky', 'alma', 'suse', 'oracle linux'] as $marker) {
			if (str_contains($value, $marker)) return 'Linux';
		}
		return '—';
	}

	private function findToolsItem(array $items, string $mode): ?array {
		$fallback = null;
		foreach ($items as $item) {
			if (strpos($item['key_'], 'vmware.vm.tools[') !== 0) {
				continue;
			}
			if ($mode === 'version' && str_ends_with($item['key_'], ',version]')) {
				return $item;
			}
			if ($mode === 'status') {
				if (str_ends_with($item['key_'], ',status]')) {
					return $item;
				}
				if (!str_ends_with($item['key_'], ',version]')) {
					$fallback = $item;
				}
			}
		}

		return $fallback;
	}

	private function hasGuestToolsEvidence(array $items): bool {
		foreach ($items as $item) {
			$key = (string) $item['key_'];
			if ((str_starts_with($key, 'vmware.vm.guest.osuptime[')
					|| str_starts_with($key, 'vmware.vm.vfs.fs.size[')) && $this->isItemValueAvailable($item)) {
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
		if (!$this->isItemValueAvailable($item)) {
			return null;
		}
		$data = json_decode((string) $item['lastvalue'], true);
		if (!is_array($data)) {
			return null;
		}

		if (isset($data['count']) && is_numeric($data['count'])) return (float) $data['count'];
		if (isset($data['snapshot']) && is_array($data['snapshot'])) return (float) count($data['snapshot']);
		$is_list = $data === [] || array_keys($data) === range(0, count($data) - 1);
		return $is_list ? (float) count($data) : null;
	}

	private function isItemValueAvailable(?array $item): bool {
		return $item !== null
			&& (int) $item['state'] === ITEM_STATE_NORMAL
			&& (int) $item['status'] === ITEM_STATUS_ACTIVE
			&& (int) $item['lastclock'] > 0
			&& $item['lastvalue'] !== '';
	}

	private function numberValue(?array $item): ?float {
		return $this->isItemValueAvailable($item) && is_numeric($item['lastvalue'])
			? (float) $item['lastvalue']
			: null;
	}

	private function normalizedValue(?array $item): ?string {
		return $this->isItemValueAvailable($item) ? strtolower(trim((string) $item['lastvalue'])) : null;
	}

	private function powerValue(?array $item): ?float {
		$value = $this->normalizedValue($item);
		if ($value === null) {
			return null;
		}
		if (is_numeric($value)) {
			return (float) $value;
		}

		return match ($value) {
			'poweredoff', 'off' => 0.0,
			'poweredon', 'on' => 1.0,
			'suspended', 'suspend' => 2.0,
			default => null
		};
	}

	private function toolsValue(?array $item): ?float {
		$value = $this->normalizedValue($item);
		if ($value === null) {
			return null;
		}
		if (is_numeric($value)) {
			return (float) $value;
		}

		return match ($value) {
			'guesttoolsexecutingscripts', 'starting' => 0.0,
			'guesttoolsnotrunning', 'notrunning', 'not running' => 1.0,
			'guesttoolsrunning', 'running' => 2.0,
			default => null
		};
	}

	private function connectionValue(?array $item): ?float {
		$value = $this->normalizedValue($item);
		if ($value === null) {
			return null;
		}
		if (is_numeric($value)) {
			return (float) $value;
		}

		return match ($value) {
			'connected' => 0.0,
			'disconnected' => 1.0,
			'notresponding', 'not responding' => 2.0,
			default => null
		};
	}

	private function healthValue(?array $item): ?float {
		$value = $this->normalizedValue($item);
		if ($value === null) {
			return null;
		}
		if (is_numeric($value)) {
			return (float) $value;
		}

		return match ($value) {
			'gray', 'grey' => 0.0,
			'green' => 1.0,
			'yellow' => 2.0,
			'red' => 3.0,
			default => null
		};
	}

	private function textValue(?array $item): string {
		return $this->isItemValueAvailable($item) ? (string) $item['lastvalue'] : '—';
	}

	private function textNumber(?array $item): string {
		$value = $this->numberValue($item);
		return $value !== null ? (string) (int) $value : '—';
	}

	private function getHostAddress(array $interfaces): string {
		if (!$interfaces) {
			return '—';
		}

		usort($interfaces, static fn(array $a, array $b): int => (int) $b['main'] <=> (int) $a['main']);
		foreach ($interfaces as $interface) {
			$address = (int) $interface['useip'] === 1 ? $interface['ip'] : $interface['dns'];
			if ($address !== '') {
				return $address;
			}
		}

		return '—';
	}

	private function filesystemLabel(string $filesystem): string {
		if (preg_match('/^([A-Za-z]):(?:\\\\)?$/', $filesystem, $matches)) {
			return strtoupper($matches[1]);
		}

		return $filesystem;
	}

	private function vmPowerText(?float $value): string {
		return match ($value !== null ? (int) $value : null) {
			0 => 'Apagada',
			1 => 'Encendida',
			2 => 'Suspendida',
			default => 'Desconocido'
		};
	}

	private function vmPowerClass(?float $value): string {
		return match ($value !== null ? (int) $value : null) {
			0 => 'off',
			1 => 'ok',
			2 => 'warning',
			default => 'unknown'
		};
	}

	private function toolsText(?float $value): string {
		return match ($value !== null ? (int) $value : null) {
			0 => 'Iniciando',
			1 => 'No activo',
			2 => 'Activo',
			default => 'Desconocido'
		};
	}

	private function connectionText(?float $value): string {
		return match ($value !== null ? (int) $value : null) {
			0 => 'Conectado',
			1 => 'Desconectado',
			2 => 'Sin respuesta',
			default => 'Desconocido'
		};
	}

	private function healthText(?float $value): string {
		return match ($value !== null ? (int) $value : null) {
			0 => 'Gris',
			1 => 'Correcto',
			2 => 'Advertencia',
			3 => 'Crítico',
			default => 'Desconocido'
		};
	}

	private function healthClass(?float $value): string {
		return match ($value !== null ? (int) $value : null) {
			1 => 'ok',
			2 => 'warning',
			3 => 'critical',
			default => 'unknown'
		};
	}

	private function formatPercent(?float $value): string {
		return $value !== null ? number_format($value, 1, '.', '').' %' : '—';
	}

	private function formatBytes(?float $bytes): string {
		if ($bytes === null) {
			return '—';
		}

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

	private function formatUptime(?float $seconds): string {
		if ($seconds === null) {
			return '—';
		}

		$seconds = max(0, (int) $seconds);
		$days = intdiv($seconds, 86400);
		$hours = intdiv($seconds % 86400, 3600);
		$minutes = intdiv($seconds % 3600, 60);

		return ($days > 0 ? $days.'d ' : '').$hours.'h '.$minutes.'m';
	}
}
