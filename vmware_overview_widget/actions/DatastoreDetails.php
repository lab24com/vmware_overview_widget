<?php declare(strict_types = 0);

namespace Modules\VmwareOverview\Actions;

use API, CController, CControllerResponseData;

class DatastoreDetails extends CController {
	protected function init(): void { $this->disableCsrfValidation(); }
	protected function checkPermissions(): bool { return $this->getUserType() >= USER_TYPE_ZABBIX_USER; }
	protected function checkInput(): bool {
		$valid = $this->validateInput([
			'name' => 'string|required', 'range' => 'int32', 'warning' => 'int32', 'critical' => 'int32'
		]);
		if (!$valid) $this->sendJson(['error' => implode(' ', array_column(get_and_clear_messages(), 'message'))]);
		return $valid;
	}

	protected function doAction(): void {
		$name = trim($this->getInput('name'));
		$range = $this->historyRange((int) $this->getInput('range', 3600));
		$warning = max(1, min(99, (int) $this->getInput('warning', 80)));
		$critical = max($warning + 1, min(100, (int) $this->getInput('critical', 90)));
		$items = [];
		foreach ([
			'vmware.datastore.size[', 'vmware.hv.datastore.size[', 'vmware.datastore.read[',
			'vmware.datastore.write[', 'vmware.hv.datastore.read[', 'vmware.hv.datastore.write['
		] as $marker) {
			$items = array_merge($items, API::Item()->get([
				'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type', 'state', 'status', 'error'],
				'search' => ['key_' => $marker], 'selectTags' => ['tag', 'value'],
				'selectHosts' => ['hostid', 'name'], 'templated' => false, 'webitems' => true
			]));
		}
		$items = array_values(array_filter($items, fn(array $item): bool => $this->matchesDatastore($item, $name)));
		if (!$items) { $this->sendJson(['error' => 'Datastore no encontrado o sin permisos de lectura.']); return; }

		$metrics = []; $sources = []; $lastclock = 0;
		foreach ($items as $item) {
			$key = (string) $item['key_']; $central = !str_starts_with($key, 'vmware.hv.');
			// read/write admiten modo latency (por defecto), rps y wps: se separan para no mezclar ms con IOPS.
			if (str_contains($key, '.read[')) {
				$metric = str_ends_with($key, ',rps]') ? 'read_iops' : 'read';
			}
			elseif (str_contains($key, '.write[')) {
				$metric = str_ends_with($key, ',wps]') ? 'write_iops' : 'write';
			}
			else {
				$metric = preg_match('/,(free|pfree|uncommitted)\]$/', $key, $match) ? $match[1] : 'total';
			}
			if (!isset($metrics[$metric]) || $central || !$metrics[$metric]['central']) $metrics[$metric] = ['item' => $item, 'central' => $central];
			$source = (string) ($item['hosts'][0]['name'] ?? ''); if ($source !== '') $sources[$source] = $item['hosts'][0]['hostid'] ?? null;
			$lastclock = max($lastclock, (int) $item['lastclock']);
		}

		$total = $this->numberValue($metrics['total']['item'] ?? null);
		$free = $this->numberValue($metrics['free']['item'] ?? null);
		$pfree = $this->numberValue($metrics['pfree']['item'] ?? null);
		$uncommitted = $this->numberValue($metrics['uncommitted']['item'] ?? null);
		if ($pfree === null && $total > 0 && $free !== null) $pfree = $free / $total * 100;
		if ($free === null && $total > 0 && $pfree !== null) $free = $total * $pfree / 100;
		$used = $total !== null && $free !== null ? max(0, $total - $free) : null;
		$pused = $pfree !== null ? max(0, min(100, 100 - $pfree)) : null;
		$status = $pused === null ? 'unknown' : ($pused >= $critical ? 'critical' : ($pused >= $warning ? 'warning' : 'ok'));

		$source_rows = [];
		foreach ($sources as $source_name => $source_hostid) $source_rows[] = [
			'name' => $source_name,
				'url' => $source_hostid ? 'zabbix.php?action=latest.view&filter_set=1&filter_show_without_data=1&filter_hostids%5B0%5D='.$source_hostid : ''
		];

		$history = $this->getHistory($metrics['pfree']['item'] ?? null, $range);
		foreach ($history as &$point) $point['value'] = max(0, min(100, 100 - $point['value']));
		unset($point);

		$this->sendJson([
			'name' => $name, 'range' => $range, 'range_label' => $this->rangeLabel($range),
			'status' => $status, 'status_text' => match ($status) {'critical' => 'Crítico', 'warning' => 'Advertencia', 'ok' => 'Correcto', default => 'Sin datos'},
			'total' => $this->formatBytes($total), 'used' => $this->formatBytes($used), 'free' => $this->formatBytes($free),
			'uncommitted' => $this->formatBytes($uncommitted), 'used_percent' => $this->formatPercent($pused),
			'used_percent_raw' => $pused, 'free_percent' => $this->formatPercent($pfree),
			'read_latency' => $this->formatValue($metrics['read']['item'] ?? null),
			'write_latency' => $this->formatValue($metrics['write']['item'] ?? null),
			'read_iops' => $this->formatValue($metrics['read_iops']['item'] ?? null),
			'write_iops' => $this->formatValue($metrics['write_iops']['item'] ?? null),
			'last_update' => $lastclock > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $lastclock) : '—',
			'sources' => $source_rows,
			'history' => [
				'usage' => $history,
				'read' => $this->getHistory($metrics['read']['item'] ?? null, $range),
				'write' => $this->getHistory($metrics['write']['item'] ?? null, $range),
				'read_iops' => $this->getHistory($metrics['read_iops']['item'] ?? null, $range),
				'write_iops' => $this->getHistory($metrics['write_iops']['item'] ?? null, $range)
			],
			'latency_units' => (string) (($metrics['read']['item']['units'] ?? '') ?: 'ms'),
			'warning' => $warning, 'critical' => $critical
		]);
	}

	private function matchesDatastore(array $item, string $name): bool {
		foreach ($item['tags'] ?? [] as $tag) {
			if ($tag['tag'] === 'datastore') return strcasecmp((string) $tag['value'], $name) === 0;
		}
		// Sin etiqueta: sólo el nombre exacto entre corchetes ("ds1" no debe coincidir con "ds10").
		return stripos((string) $item['name'], '['.$name.']') !== false;
	}
	private function isAvailable(?array $item): bool { return $item !== null && (int) $item['state'] === ITEM_STATE_NORMAL && (int) $item['status'] === ITEM_STATUS_ACTIVE && (int) $item['lastclock'] > 0 && $item['lastvalue'] !== ''; }
	private function numberValue(?array $item): ?float { return $this->isAvailable($item) && is_numeric($item['lastvalue']) ? (float) $item['lastvalue'] : null; }
	private function formatValue(?array $item): string { if (!$this->isAvailable($item)) return '—'; return is_numeric($item['lastvalue']) ? convertUnits(['value' => $item['lastvalue'], 'units' => $item['units'], 'decimals' => 2]) : (string) $item['lastvalue']; }
	private function getHistory(?array $item, int $range): array {
		if (!$this->isAvailable($item) || !in_array((int) $item['value_type'], [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64], true)) return [];
		if ($range > 604800) {
			try {
				$rows = API::Trend()->get(['output' => ['clock', 'value_avg'], 'itemids' => [$item['itemid']], 'time_from' => time() - $range,
					'sortfield' => 'clock', 'sortorder' => 'ASC', 'limit' => 1500]);
				if ($rows) return array_map(static fn(array $r): array => ['clock' => (int) $r['clock'], 'value' => (float) $r['value_avg']], $rows);
			}
			catch (\Throwable $e) {}
		}
		try {
			$rows = API::History()->get(['output' => ['clock', 'value'], 'history' => (int) $item['value_type'], 'itemids' => [$item['itemid']],
				'time_from' => time() - $range, 'sortfield' => 'clock', 'sortorder' => 'DESC', 'limit' => 2500]);
			$rows = array_reverse($rows);
			return array_map(static fn(array $r): array => ['clock' => (int) $r['clock'], 'value' => (float) $r['value']], $rows);
		}
		catch (\Throwable $e) { return []; }
	}
	private function historyRange(int $range): int { return in_array($range, [3600,7200,10800,21600,86400,604800,1296000,2592000], true) ? $range : 3600; }
	private function rangeLabel(int $range): string { return [3600=>'1 hora',7200=>'2 horas',10800=>'3 horas',21600=>'6 horas',86400=>'1 día',604800=>'7 días',1296000=>'15 días',2592000=>'30 días'][$range] ?? '1 hora'; }
	private function formatPercent(?float $value): string { return $value !== null ? number_format($value, 1, '.', '').' %' : '—'; }
	private function formatBytes(?float $bytes): string { if ($bytes === null) return '—'; $u=['B','KB','MB','GB','TB','PB'];$i=0;$v=abs($bytes);while($v>=1024&&$i<count($u)-1){$v/=1024;$i++;}return number_format($bytes<0?-$v:$v,$i>1?1:0,'.','').' '.$u[$i]; }
	private function sendJson(array $data): void { $this->setResponse((new CControllerResponseData(['main_block' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]))->disableView()); }
}
