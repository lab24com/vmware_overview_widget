<?php declare(strict_types = 0);

namespace Modules\VmwareOverview\Includes;

use Zabbix\Widgets\CWidgetForm;

use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldIntegerBox,
	CWidgetFieldMultiSelectGroup
};

/**
 * Formulario del widget. Los campos nuevos tienen valores por defecto, por lo que los widgets guardados con
 * versiones anteriores siguen funcionando sin volver a configurarse.
 */
class WidgetForm extends CWidgetForm {

	public const DEFAULT_RESOURCE_WARNING = 75;
	public const DEFAULT_RESOURCE_CRITICAL = 90;
	public const DEFAULT_DISK_WARNING = 80;
	public const DEFAULT_DISK_CRITICAL = 90;
	public const DEFAULT_DATASTORE_WARNING = 80;
	public const DEFAULT_DATASTORE_CRITICAL = 90;

	public function addFields(): self {
		return $this
			->addField(
				new CWidgetFieldMultiSelectGroup('groupids', _('Grupos de hosts'))
			)
			->addField(
				(new CWidgetFieldIntegerBox('rows_per_page', _('Filas por página'), 5, 100))
					->setDefault(25)
			)
			->addField(
				(new CWidgetFieldCheckBox('show_hypervisors', _('Mostrar hipervisores')))
					->setDefault(1)
			)
			->addField(
				(new CWidgetFieldCheckBox('show_powered_off', _('Mostrar máquinas apagadas')))
					->setDefault(1)
			)
			->addField(
				(new CWidgetFieldCheckBox('include_maintenance', _('Incluir hosts en mantenimiento')))
					->setDefault(1)
			)
			->addField(
				(new CWidgetFieldIntegerBox('resource_warning', _('CPU/RAM: advertencia (%)'), 1, 99))
					->setDefault(self::DEFAULT_RESOURCE_WARNING)
			)
			->addField(
				(new CWidgetFieldIntegerBox('resource_critical', _('CPU/RAM: crítico (%)'), 2, 100))
					->setDefault(self::DEFAULT_RESOURCE_CRITICAL)
			)
			->addField(
				(new CWidgetFieldIntegerBox('disk_warning', _('Disco invitado: advertencia (%)'), 1, 99))
					->setDefault(self::DEFAULT_DISK_WARNING)
			)
			->addField(
				(new CWidgetFieldIntegerBox('disk_critical', _('Disco invitado: crítico (%)'), 2, 100))
					->setDefault(self::DEFAULT_DISK_CRITICAL)
			)
			->addField(
				(new CWidgetFieldIntegerBox('datastore_warning', _('Datastore: advertencia (%)'), 1, 99))
					->setDefault(self::DEFAULT_DATASTORE_WARNING)
			)
			->addField(
				(new CWidgetFieldIntegerBox('datastore_critical', _('Datastore: crítico (%)'), 2, 100))
					->setDefault(self::DEFAULT_DATASTORE_CRITICAL)
			);
	}
}
