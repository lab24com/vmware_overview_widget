<?php declare(strict_types = 0);

namespace Modules\VmwareOverview;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

	public function getDefaultName(): string {
		return _('VMware: Vista general');
	}

	public function getTranslationStrings(): array {
		return [
			'class.widget.js' => [
				'Unable to load disk information.' => _('No se pudo cargar la información del disco.'),
				'Unable to load VM information.' => _('No se pudo cargar la información de la máquina virtual.'),
				'Unable to load hypervisor information.' => _('No se pudo cargar la información del hipervisor.'),
				'No history in the selected period.' => _('No existe historial en el periodo seleccionado.')
			]
		];
	}
}
