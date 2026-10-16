<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

$tag = static function(string $name, $content = null, ?string $class = null, array $attributes = []): CTag {
	$element = new CTag($name, !in_array($name, ['input'], true), $content);
	if ($class !== null && $class !== '') {
		$element->addClass($class);
	}
	foreach ($attributes as $attribute => $value) {
		$element->setAttribute($attribute, $value);
	}
	return $element;
};

$resource_warning = (float) $data['resource_warning'];
$resource_critical = (float) $data['resource_critical'];

$tone = static function(?float $value, float $warning, float $critical): string {
	return $value === null ? 'unknown' : ($value >= $critical ? 'critical' : ($value >= $warning ? 'warning' : 'ok'));
};

$progress = static function(?float $value, string $kind) use ($tag): CTag {
	$width = $value !== null ? max(0, min(100, $value)) : 0;
	return $tag('div', [
		$tag('span', null, 'vmw-progress-fill '.$kind, ['style' => 'width: '.round($width, 1).'%'])
	], 'vmw-progress-track', ['aria-hidden' => 'true']);
};

$metric = static function(?float $value, string $formatted, string $detail, float $warning, float $critical)
		use ($tag, $progress, $tone): CTag {
	$kind = $tone($value, $warning, $critical);
	return $tag('div', [
		$tag('div', [
			$tag('span', $formatted, 'vmw-metric-value '.($kind === 'ok' ? '' : $kind)),
			$detail !== '' ? $tag('span', $detail, 'vmw-metric-detail') : null
		], 'vmw-metric-line'),
		$progress($value, $kind)
	], 'vmw-metric');
};

$status = static function(string $text, string $class) use ($tag): CTag {
	return $tag('span', [$tag('span', null, 'vmw-status-dot'), $text], 'vmw-status '.$class);
};

// Ordenación: cada columna ordenable declara data-sort (campo) y data-sort-type (number|text); cada fila lleva
// data-sort-<campo>. Un valor vacío significa "sin dato" y siempre queda al final, en cualquier dirección.
$sort_value = static fn($value): string => $value === null || $value === '' || $value === '—' ? '' : (string) $value;

$th = static function(string $label, ?string $field = null, string $type = 'number', ?string $class = null,
		array $attributes = []) use ($tag): CTag {
	if ($field === null) {
		return $tag('th', $label, $class, $attributes);
	}
	return $tag('th', $tag('button', [$label, $tag('span', null, 'vmw-sort-icon', ['aria-hidden' => 'true'])],
		'vmw-sort-head', [
			'type' => 'button',
			'title' => $type === 'text' ? 'Ordenar alfabéticamente (clic de nuevo para invertir)'
				: 'Ordenar de mayor a menor (clic de nuevo para invertir)'
		]), $class, $attributes + ['data-sort' => $field, 'data-sort-type' => $type, 'aria-sort' => 'none']);
};

$format_bytes = static function(float $bytes): string {
	$units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
	$index = 0;
	while ($bytes >= 1024 && $index < count($units) - 1) {
		$bytes /= 1024;
		$index++;
	}
	return number_format($bytes, $index > 1 ? 1 : 0, '.', '').' '.$units[$index];
};

$summary = $data['summary'];
$percent_of = static fn(int $part, int $total): float => $total > 0 ? round($part / $total * 100, 1) : 0.0;

// KPI --------------------------------------------------------------------------------------------------------------

$card = static function(string $label, $value, string $tone_class, array $attributes, string $sub = '',
		string $sub_class = '', ?float $bar = null) use ($tag): CTag {
	return $tag('button', [
		$tag('span', $label, 'vmw-card-label'),
		$tag('span', [
			$tag('span', (string) $value, 'vmw-card-value'),
			$sub !== '' ? $tag('span', $sub, 'vmw-card-sub '.$sub_class) : null
		], 'vmw-card-main'),
		$bar !== null
			? $tag('span', $tag('span', null, null, ['style' => 'width: '.$bar.'%']), 'vmw-card-bar', ['aria-hidden' => 'true'])
			: null
	], 'vmw-summary-card '.$tone_class, ['type' => 'button'] + $attributes);
};

$summary_cards = $tag('div', [
	$card('Máquinas virtuales', $summary['total'], 'accent active', ['data-filter' => 'all'],
		$summary['total'] > 0 ? $percent_of($summary['on'], $summary['total']).' % activas' : ''),
	$card('Encendidas', $summary['on'], 'ok', ['data-filter' => 'on'], '', '',
		$percent_of($summary['on'], $summary['total'])),
	$card('Apagadas', $summary['off'], 'muted', ['data-filter' => 'off'], '', '',
		$percent_of($summary['off'], $summary['total'])),
	$card('Suspendidas', $summary['suspended'], $summary['suspended'] > 0 ? 'warning' : 'muted',
		['data-filter' => 'suspended'], '', '', $percent_of($summary['suspended'], $summary['total'])),
	$card('Con alertas', $summary['problems'], $summary['problems'] > 0 ? 'critical' : 'ok', ['data-filter' => 'problems'],
		$summary['consolidation'] > 0 ? $summary['consolidation'].' consolidar' : '', 'warning',
		$percent_of($summary['problems'], $summary['total'])),
	$card('Hipervisores', $summary['hypervisors'], $summary['hypervisor_alerts'] > 0 ? 'critical' : 'ok',
		['data-tab-target' => 'hypervisors'],
		$summary['hypervisor_alerts'] > 0 ? $summary['hypervisor_alerts'].' con alertas' : 'sin alertas',
		$summary['hypervisor_alerts'] > 0 ? 'critical' : ''),
	$card('Datastores', $summary['datastores'],
		$summary['datastore_critical'] > 0 ? 'critical' : ($summary['datastore_alerts'] > 0 ? 'warning' : 'ok'),
		['data-tab-target' => 'datastores'],
		$summary['datastore_alerts'] > 0 ? $summary['datastore_alerts'].' sobre umbral' : 'sin alertas',
		$summary['datastore_critical'] > 0 ? 'critical' : ($summary['datastore_alerts'] > 0 ? 'warning' : ''))
], 'vmw-summary');

// Barra de herramientas --------------------------------------------------------------------------------------------

$resource_total = static fn(string $value, string $label): CTag =>
	$tag('span', [$tag('strong', $value), $label], 'vmw-resource-total');

$toolbar = $tag('div', [
	$tag('div', [
		$tag('div', $tag('input', null, 'vmw-search', [
			'type' => 'search',
			'placeholder' => 'Buscar VM, IP, hipervisor, clúster o datacenter…',
			'aria-label' => 'Buscar recursos VMware',
			'autocomplete' => 'off'
		]), 'vmw-search-wrap'),
		$tag('select', [
			$tag('option', 'Todos los estados', null, ['value' => 'all']),
			$tag('option', 'Encendidas', null, ['value' => 'on']),
			$tag('option', 'Apagadas', null, ['value' => 'off']),
			$tag('option', 'Suspendidas', null, ['value' => 'suspended']),
			$tag('option', 'Con alertas', null, ['value' => 'problems'])
		], 'vmw-state-filter', ['aria-label' => 'Filtrar por estado']),
		$tag('span', 'Ordenar', 'vmw-sort-label'),
		$tag('select', [
			$tag('option', 'Mayor consumo', null, ['value' => 'consumption']),
			$tag('option', 'Prioridad / alertas', null, ['value' => 'risk'])
		], 'vmw-sort-field', ['aria-label' => 'Ordenar recursos por']),
		$tag('button', '↓ Mayor a menor', 'vmw-sort-direction', [
			'type' => 'button', 'title' => 'Invertir el orden'
		])
	], 'vmw-filter-tools'),
	$tag('div', [
		$resource_total((string) $summary['vcpu'], 'vCPU'),
		$resource_total($format_bytes((float) $summary['memory']), 'RAM'),
		$resource_total((string) $summary['filesystems'], 'discos/FS'),
		$resource_total((string) $summary['networks'], 'interfaces')
	], 'vmw-resource-totals')
], 'vmw-toolbar');

// Pestañas ---------------------------------------------------------------------------------------------------------

$tab = static function(string $id, string $label, int $count, bool $alert, bool $active) use ($tag): CTag {
	return $tag('button', [
		$label,
		$tag('span', (string) $count, 'vmw-tab-count'),
		$alert ? $tag('span', null, 'vmw-tab-alert', ['title' => 'Hay elementos con alertas']) : null
	], 'vmw-tab'.($active ? ' active' : ''), [
		'type' => 'button', 'data-tab' => $id, 'role' => 'tab', 'aria-selected' => $active ? 'true' : 'false'
	]);
};

$tabs = $tag('div', [
	$tab('vms', 'Máquinas virtuales', (int) $summary['total'], $summary['problems'] > 0, true),
	$tab('hypervisors', 'Hipervisores', (int) $summary['hypervisors'], $summary['hypervisor_alerts'] > 0, false),
	$tab('datastores', 'Datastores únicos', (int) $summary['datastores'], $summary['datastore_alerts'] > 0, false)
], 'vmw-tabs', ['role' => 'tablist']);

// Máquinas virtuales -----------------------------------------------------------------------------------------------

$vm_tbody = $tag('tbody', null, null, ['data-table-body' => 'vms']);
foreach ($data['vms'] as $vm) {
	$disk_cells = [];
	foreach ($vm['disk_badges'] as $disk) {
		$disk_cells[] = $tag('button', $disk['label'], 'vmw-disk '.$disk['status'], [
			'type' => 'button',
			'data-disk' => '1',
			'data-hostid' => $vm['hostid'],
			'data-host' => $vm['name'],
			'data-fs' => $disk['filesystem'],
			'title' => $disk['filesystem'].' · uso '.$disk['used_percent']
		]);
	}
	if ($vm['hidden_disks'] > 0) {
		$disk_cells[] = $tag('button', '+'.$vm['hidden_disks'], 'vmw-more-disks', [
			'type' => 'button',
			'data-vm-details' => '1',
			'data-hostid' => $vm['hostid'],
			'data-host' => $vm['name'],
			'title' => 'Ver los '.$vm['filesystem_count'].' sistemas de archivos descubiertos'
		]);
	}
	if (!$disk_cells) {
		$disk_cells[] = $tag('span', 'Sin datos de invitado', 'vmw-no-disks',
			['title' => 'VMware Tools debe estar activo para que vCenter entregue los sistemas de archivos']);
	}

	$host_badges = [];
	if (!$vm['enabled']) {
		$host_badges[] = $tag('span', 'Deshabilitada', 'vmw-inline-badge muted');
	}
	if ($vm['maintenance']) {
		$host_badges[] = $tag('span', 'Mantenimiento', 'vmw-inline-badge warning');
	}
	if (!empty($vm['hv_maintenance'])) {
		$host_badges[] = $tag('span', 'ESXi en mant.', 'vmw-inline-badge warning',
			['title' => 'El hipervisor de esta VM está en modo mantenimiento']);
	}

	$search_text = implode(' ', [
		$vm['name'], $vm['ip'], $vm['os'], $vm['hypervisor'], $vm['cluster'], $vm['datacenter'],
		implode(' ', array_column($vm['disks'], 'filesystem'))
	]);

	$disk_values = array_filter(array_map(static fn(array $disk): ?float => $disk['used_percent_raw'] ?? null,
		$vm['disks']), static fn(?float $value): bool => $value !== null);
	$vm_disk_max = $disk_values ? max($disk_values) : null;
	$consumption_values = array_filter([$vm['cpu_raw'], $vm['memory_percent_raw'], $vm_disk_max],
		static fn($value): bool => $value !== null);
	$vm_consumption = $consumption_values ? max($consumption_values) : null;

	$tools_class = $vm['tools_value'] === 2 ? 'ok' : ($vm['tools_value'] === 1 ? 'warning' : 'unknown');
	$snapshot_title = $vm['snapshots'] !== null ? (int) $vm['snapshots'].' instantánea(s)' : '';
	if (!empty($vm['consolidation_needed'])) {
		$snapshot_title .= ' · requiere consolidación de discos';
	}

	$vm_tbody->addItem($tag('tr', [
		$tag('td', [
			$tag('button', $vm['name'], 'vmw-resource-link', [
				'type' => 'button', 'data-vm-details' => '1', 'data-hostid' => $vm['hostid'], 'data-host' => $vm['name'],
				'title' => $vm['name']
			]),
			$tag('div', array_merge([$tag('span', $vm['ip'], 'vmw-subtext vmw-mono')], $host_badges), 'vmw-host-meta')
		], 'vmw-host-cell'),
		$tag('td', $status($vm['power_text'], $vm['power_class'])),
		$tag('td', $vm['os_family'] !== '—'
			? $tag('span', $vm['os_family'], 'vmw-os-badge '.mb_strtolower($vm['os_family']), ['title' => $vm['os']])
			: $tag('span', '—', 'vmw-subtext', ['title' => $vm['os'] ?: 'Sistema operativo no reportado'])),
		$tag('td', $tag('button',
			$metric($vm['cpu_raw'], $vm['cpu'], $vm['vcpu'].' vCPU', $resource_warning, $resource_critical),
			'vmw-metric-button', [
				'type' => 'button', 'data-vm-resource' => 'cpu', 'data-hostid' => $vm['hostid'],
				'data-host' => $vm['name'], 'title' => 'Abrir historial de CPU'
			]), 'vmw-bar-cell'),
		$tag('td', $tag('button',
			$metric($vm['memory_percent_raw'], $vm['memory_percent'], $vm['memory_total'], $resource_warning,
				$resource_critical),
			'vmw-metric-button', [
				'type' => 'button', 'data-vm-resource' => 'memory', 'data-hostid' => $vm['hostid'],
				'data-host' => $vm['name'], 'title' => 'Abrir historial de RAM'
			]), 'vmw-bar-cell'),
		$tag('td', [
			$tag('span', $vm['hypervisor'], 'vmw-primary-text', ['title' => $vm['hypervisor']]),
			$vm['cluster'] !== '—' ? $tag('span', $vm['cluster'], 'vmw-subtext', ['title' => $vm['cluster']]) : null
		]),
		$tag('td', [
			$tag('span', $vm['storage'], 'vmw-primary-text'),
			$vm['storage_total'] !== '—' ? $tag('span', $vm['storage_total'].' aprov.', 'vmw-subtext') : null
		], 'vmw-num vmw-nowrap'),
		$tag('td', $tag('div', $disk_cells, 'vmw-disk-list'), 'vmw-disk-cell'),
		$tag('td', $tag('span', $status($vm['tools'], $tools_class), null, [
			'title' => $vm['tools_version'] !== '—' ? 'Versión '.$vm['tools_version'] : 'Versión no disponible'
		])),
		$tag('td', $vm['uptime'], 'vmw-num vmw-nowrap'),
		$tag('td', $vm['snapshots'] !== null
			? $tag('span', (string) (int) $vm['snapshots'],
				'vmw-snapshot-count'.(!empty($vm['consolidation_needed']) ? ' warning' : ''), ['title' => $snapshot_title])
			: $tag('span', '—', 'vmw-subtext'), 'vmw-center'),
		$tag('td', $vm['problems'] > 0
			? $tag('button', (string) $vm['problems'],
				'vmw-problem-count'.($vm['zabbix_problems'] > 0 ? '' : ' warning'), [
				'type' => 'button', 'data-vm-details' => '1', 'data-hostid' => $vm['hostid'], 'data-host' => $vm['name'],
				'title' => $vm['zabbix_problems'].' problema(s) Zabbix · '.$vm['resource_alerts'].' advertencia(s) de recursos'
			])
			: $tag('span', '0', 'vmw-zero-problems'), 'vmw-center'),
		$tag('td', $tag('button', 'Ver', 'vmw-details-button', [
			'type' => 'button',
			'data-vm-details' => '1',
			'data-hostid' => $vm['hostid'],
			'data-host' => $vm['name'],
			'title' => $vm['item_count'].' ítems VMware · última actualización '.$vm['last_update']
		]), 'vmw-center')
	], ($vm['row_tone'] ?? '') !== '' ? 'vmw-row-'.$vm['row_tone'] : null, [
		'data-row' => '1',
		'data-hostid' => $vm['hostid'],
		'data-state' => match ($vm['power_value']) {0 => 'off', 1 => 'on', 2 => 'suspended', default => 'unknown'},
		'data-problems' => $vm['problems'] > 0 ? '1' : '0',
		'data-sort-risk' => $vm['risk_score'],
		'data-sort-consumption' => $sort_value($vm_consumption),
		'data-sort-name' => mb_strtolower($vm['name']),
		'data-sort-state' => match ($vm['power_value']) {1 => 3, 2 => 2, 0 => 1, default => 0},
		'data-sort-os' => $sort_value($vm['os_family'] !== '—' ? mb_strtolower($vm['os_family'].' '.$vm['os']) : ''),
		'data-sort-cpu' => $sort_value($vm['cpu_raw']),
		'data-sort-memory' => $sort_value($vm['memory_percent_raw']),
		'data-sort-hypervisor' => $sort_value($vm['hypervisor'] !== '—' ? mb_strtolower($vm['hypervisor']) : ''),
		'data-sort-storage' => $sort_value($vm['storage_raw'] ?? null),
		'data-sort-disk' => $sort_value($vm_disk_max),
		'data-sort-tools' => $sort_value($vm['tools_value']),
		'data-sort-uptime' => $sort_value($vm['uptime_raw'] ?? null),
		'data-sort-snapshots' => $sort_value($vm['snapshots']),
		'data-sort-alerts' => $vm['problems'],
		'data-search' => $search_text
	]));
}

$vm_table = $tag('table', [
	$tag('thead', $tag('tr', [
		$th('Máquina virtual', 'name', 'text'),
		$th('Estado', 'state'),
		$th('SO', 'os', 'text'),
		$th('CPU', 'cpu'),
		$th('RAM', 'memory'),
		$th('Hipervisor / clúster', 'hypervisor', 'text'),
		$th('Almacenamiento', 'storage', 'number', 'vmw-num'),
		$th('Discos invitado', 'disk'),
		$th('VMware Tools', 'tools'),
		$th('Uptime', 'uptime', 'number', 'vmw-num'),
		$th('Snapshots', 'snapshots', 'number', 'vmw-center'),
		$th('Alertas', 'alerts', 'number', 'vmw-center'),
		$th('', null, 'number', 'vmw-center', ['aria-label' => 'Detalle'])
	])),
	$vm_tbody
], 'vmw-table');

// Hipervisores -----------------------------------------------------------------------------------------------------

$hv_tbody = $tag('tbody', null, null, ['data-table-body' => 'hypervisors']);
foreach ($data['hypervisors'] as $hv) {
	$host_badges = [];
	if (!$hv['enabled']) {
		$host_badges[] = $tag('span', 'Deshabilitado', 'vmw-inline-badge muted');
	}
	if ($hv['maintenance']) {
		$host_badges[] = $tag('span', 'Mantenimiento', 'vmw-inline-badge warning');
	}
	if ($hv['inferred']) {
		$host_badges[] = $tag('span', 'Inferido', 'vmw-inline-badge muted',
			['title' => 'Datos calculados a partir de las VMs visibles']);
	}

	$search_text = implode(' ', [
		$hv['name'], $hv['ip'], $hv['cluster'], $hv['datacenter'], $hv['version'], $hv['model'], $hv['vendor']
	]);

	$hv_tbody->addItem($tag('tr', [
		$tag('td', [
			$tag('button', $hv['name'], 'vmw-resource-link', [
				'type' => 'button', 'data-hv-details' => '1', 'data-hostid' => $hv['hostid'] ?? '',
				'data-host' => $hv['name'], 'title' => $hv['name']
			]),
			$tag('div', array_merge([$tag('span', $hv['ip'], 'vmw-subtext vmw-mono')], $host_badges), 'vmw-host-meta')
		], 'vmw-host-cell'),
		$tag('td', $status($hv['connection'], $hv['connection_class'])),
		$tag('td', $status($hv['health'], $hv['health_class'])),
		$tag('td', $metric($hv['cpu_raw'], $hv['cpu'], $hv['inferred']
			? $hv['allocated_vcpu'].' vCPU asignadas'
			: $hv['cores'].' núcleos · '.$hv['threads'].' hilos', $resource_warning, $resource_critical), 'vmw-bar-cell'),
		$tag('td', $metric($hv['memory_percent_raw'], $hv['memory_percent'],
			$hv['memory_used'].' / '.$hv['memory_total'], $resource_warning, $resource_critical), 'vmw-bar-cell'),
		$tag('td', [
			$tag('span', $hv['model'], 'vmw-primary-text', ['title' => $hv['vendor'].' '.$hv['model']]),
			$tag('span', $hv['version'], 'vmw-subtext', ['title' => $hv['version']])
		]),
		$tag('td', [
			$tag('span', $hv['cluster'], 'vmw-primary-text'),
			$tag('span', $hv['datacenter'], 'vmw-subtext')
		]),
		$tag('td', [
			$tag('span', $hv['vms'], 'vmw-primary-text'),
			$hv['powered_on'] !== null ? $tag('span', $hv['powered_on'].' encendidas', 'vmw-subtext') : null
		], 'vmw-num'),
		$tag('td', $hv['uptime'], 'vmw-num vmw-nowrap'),
		$tag('td', $hv['problems'] > 0
			? $tag('button', (string) $hv['problems'],
				'vmw-problem-count'.($hv['zabbix_problems'] > 0 ? '' : ' warning'), [
				'type' => 'button', 'data-hv-details' => '1', 'data-hostid' => $hv['hostid'] ?? '',
				'data-host' => $hv['name'],
				'title' => $hv['zabbix_problems'].' problema(s) Zabbix · '.$hv['resource_alerts'].' advertencia(s) de recursos'
			])
			: $tag('span', '0', 'vmw-zero-problems'), 'vmw-center'),
		$tag('td', $tag('button', 'Ver', 'vmw-details-button', [
			'type' => 'button', 'data-hv-details' => '1', 'data-hostid' => $hv['hostid'] ?? '', 'data-host' => $hv['name']
		]), 'vmw-center')
	], ($hv['row_tone'] ?? '') !== '' ? 'vmw-row-'.$hv['row_tone'] : null, [
		'data-row' => '1',
		'data-hostid' => $hv['hostid'] ?? '',
		'data-state' => $hv['connection_value'] === 0 ? 'on' : 'off',
		'data-problems' => $hv['problems'] > 0 ? '1' : '0',
		'data-sort-risk' => $hv['risk_score'],
		'data-sort-consumption' => $sort_value(($hv['cpu_raw'] ?? null) === null && ($hv['memory_percent_raw'] ?? null) === null
			? null : max((float) ($hv['cpu_raw'] ?? 0), (float) ($hv['memory_percent_raw'] ?? 0))),
		'data-sort-name' => mb_strtolower($hv['name']),
		'data-sort-connection' => match ($hv['connection_class']) {'ok' => 2, 'unknown' => 1, default => 0},
		'data-sort-health' => $sort_value($hv['health_value']),
		'data-sort-cpu' => $sort_value($hv['cpu_raw']),
		'data-sort-memory' => $sort_value($hv['memory_percent_raw']),
		'data-sort-model' => $sort_value($hv['model'] !== '—' ? mb_strtolower($hv['model']) : ''),
		'data-sort-cluster' => $sort_value($hv['cluster'] !== '—' ? mb_strtolower($hv['cluster']) : ''),
		'data-sort-vms' => $sort_value(is_numeric($hv['vms']) ? $hv['vms'] : null),
		'data-sort-uptime' => $sort_value($hv['uptime_raw'] ?? null),
		'data-sort-alerts' => $hv['problems'],
		'data-search' => $search_text
	]));
}

$hv_table = $tag('table', [
	$tag('thead', $tag('tr', [
		$th('Hipervisor', 'name', 'text'),
		$th('Conexión', 'connection'),
		$th('Salud', 'health'),
		$th('CPU', 'cpu'),
		$th('RAM', 'memory'),
		$th('Modelo / versión', 'model', 'text'),
		$th('Clúster / datacenter', 'cluster', 'text'),
		$th('VMs', 'vms', 'number', 'vmw-num'),
		$th('Uptime', 'uptime', 'number', 'vmw-num'),
		$th('Alertas', 'alerts', 'number', 'vmw-center'),
		$th('', null, 'number', 'vmw-center', ['aria-label' => 'Detalle'])
	])),
	$hv_tbody
], 'vmw-table');

// Datastores -------------------------------------------------------------------------------------------------------

$ds_tbody = $tag('tbody', null, null, ['data-table-body' => 'datastores']);
foreach ($data['datastores'] as $datastore) {
	$source_preview = $datastore['sources'] ? implode(', ', array_slice($datastore['sources'], 0, 3)) : 'Sin origen visible';
	if (count($datastore['sources']) > 3) {
		$source_preview .= ' +'.(count($datastore['sources']) - 3);
	}
	$search_text = implode(' ', [$datastore['name'], $datastore['type'], implode(' ', $datastore['sources'])]);
	$ds_tbody->addItem($tag('tr', [
		$tag('td', [
			$tag('button', $datastore['name'], 'vmw-resource-link', [
				'type' => 'button', 'data-datastore-details' => '1', 'data-datastore' => $datastore['name'],
				'title' => $datastore['name']
			]),
			$tag('span', $datastore['central'] ? 'vCenter' : 'Consolidado desde ESXi', 'vmw-subtext')
		], 'vmw-host-cell'),
		$tag('td', $status(
			match ($datastore['status']) {'critical' => 'Crítico', 'warning' => 'Advertencia', 'ok' => 'Correcto', default => 'Sin datos'},
			$datastore['status']
		)),
		$tag('td', $metric($datastore['pused_raw'], $datastore['pused'], $datastore['pfree'].' libre',
			(float) $data['datastore_warning'], (float) $data['datastore_critical']), 'vmw-bar-cell'),
		$tag('td', $datastore['total'], 'vmw-num vmw-nowrap'),
		$tag('td', $datastore['free'], 'vmw-num vmw-nowrap'),
		$tag('td', $datastore['type']),
		$tag('td', [
			$tag('span', $datastore['shared_count'].' origen(es)', 'vmw-primary-text'),
			$tag('span', $source_preview, 'vmw-subtext', ['title' => implode(', ', $datastore['sources'])])
		]),
		$tag('td', $datastore['last_update'], 'vmw-nowrap vmw-subtext')
	], in_array($datastore['status'], ['warning', 'critical'], true) ? 'vmw-row-'.$datastore['status'] : null, [
		'data-row' => '1', 'data-state' => $datastore['status'],
		'data-problems' => in_array($datastore['status'], ['warning', 'critical'], true) ? '1' : '0',
		'data-sort-risk' => (match ($datastore['status']) {'critical' => 3000000, 'warning' => 2000000, 'unknown' => 1000000, default => 0})
			+ (float) ($datastore['pused_raw'] ?? 0) * 100,
		'data-sort-consumption' => $sort_value($datastore['pused_raw']),
		'data-sort-name' => mb_strtolower($datastore['name']),
		'data-sort-state' => match ($datastore['status']) {'critical' => 3, 'warning' => 2, 'ok' => 1, default => 0},
		'data-sort-usage' => $sort_value($datastore['pused_raw']),
		'data-sort-total' => $sort_value($datastore['total_raw']),
		'data-sort-free' => $sort_value($datastore['free_raw'] ?? null),
		'data-sort-dstype' => $sort_value($datastore['type'] !== '—' ? mb_strtolower($datastore['type']) : ''),
		'data-sort-sources' => $datastore['shared_count'],
		'data-sort-updated' => $sort_value($datastore['lastclock'] ?: null),
		'data-search' => $search_text
	]));
}

$ds_table = $tag('table', [
	$tag('thead', $tag('tr', [
		$th('Datastore', 'name', 'text'), $th('Estado', 'state'), $th('Uso', 'usage'),
		$th('Capacidad', 'total', 'number', 'vmw-num'), $th('Libre', 'free', 'number', 'vmw-num'), $th('Tipo', 'dstype', 'text'),
		$th('Compartido / origen', 'sources'), $th('Actualización', 'updated')
	])),
	$ds_tbody
], 'vmw-table vmw-datastore-table');

// Estados vacíos, paginación y montaje ----------------------------------------------------------------------------

$empty = static fn(string $title, string $text) => $tag('div', [$tag('strong', $title), $tag('span', $text)], 'vmw-empty');

$empty_vms = $data['vms'] ? null : $empty('No se encontraron máquinas virtuales VMware.',
	'Verifica que la plantilla oficial “VMware” haya descubierto hosts con el ítem vmware.vm.powerstate[].');
$empty_hv = $data['hypervisors'] ? null : $empty('No se encontraron hipervisores VMware.',
	'Activa “Mostrar hipervisores” y verifica el descubrimiento de la plantilla oficial.');
$empty_ds = $data['datastores'] ? null : $empty('No se encontraron datastores VMware.',
	'Verifica el descubrimiento de datastores en el host vCenter o en los prototipos de ESXi.');

$pagination = static function(string $table) use ($tag): CTag {
	return $tag('div', [
		$tag('span', '', 'vmw-page-info', ['aria-live' => 'polite']),
		$tag('div', [
			$tag('button', '‹ Anterior', 'vmw-page-button', ['type' => 'button', 'data-page-prev' => '1']),
			$tag('span', '', 'vmw-page-number'),
			$tag('button', 'Siguiente ›', 'vmw-page-button', ['type' => 'button', 'data-page-next' => '1'])
		], 'vmw-page-actions')
	], 'vmw-pagination', ['data-pagination' => $table]);
};

$panels = $tag('div', [
	$tag('div', [
		$tag('div', $vm_table, 'vmw-table-scroll'),
		$empty_vms,
		$pagination('vms')
	], 'vmw-panel active', ['data-panel' => 'vms', 'role' => 'tabpanel']),
	$tag('div', [
		$tag('div', $hv_table, 'vmw-table-scroll'),
		$empty_hv,
		$pagination('hypervisors')
	], 'vmw-panel', ['data-panel' => 'hypervisors', 'role' => 'tabpanel']),
	$tag('div', [
		$tag('div', $ds_table, 'vmw-table-scroll'),
		$empty_ds,
		$tag('div', 'Vista consolidada: cada datastore aparece una sola vez aunque lo utilicen varios hipervisores.',
			'vmw-consolidation-note'),
		$pagination('datastores')
	], 'vmw-panel', ['data-panel' => 'datastores', 'role' => 'tabpanel'])
], 'vmw-panels');

$footer = $tag('div', [
	$tag('span', 'Actualizado '.$data['generated']),
	$tag('span', '© LAKA Soluciones Tecnológicas')
], 'vmw-copyright');

$root = $tag('div', [
	$summary_cards,
	$toolbar,
	$tabs,
	$panels,
	$footer
], 'vmw-overview-root', [
	'data-rows-per-page' => $data['rows_per_page'],
	'data-disk-warning' => $data['disk_warning'],
	'data-disk-critical' => $data['disk_critical'],
	'data-resource-warning' => $data['resource_warning'],
	'data-resource-critical' => $data['resource_critical'],
	'data-datastore-warning' => $data['datastore_warning'],
	'data-datastore-critical' => $data['datastore_critical'],
	'data-diagnostic-token' => $data['diagnostic_token'],
	'data-can-scan-ports' => $data['can_scan_ports'] ? '1' : '0'
]);

(new CWidgetView($data))
	->addItem($root)
	->show();
