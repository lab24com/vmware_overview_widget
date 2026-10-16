<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

(new CWidgetFormView($data))
	->addField(new CWidgetFieldMultiSelectGroupView($data['fields']['groupids']))
	->addField(new CWidgetFieldIntegerBoxView($data['fields']['rows_per_page']))
	->addField(new CWidgetFieldCheckBoxView($data['fields']['show_hypervisors']))
	->addField(new CWidgetFieldCheckBoxView($data['fields']['show_powered_off']))
	->addField(new CWidgetFieldCheckBoxView($data['fields']['include_maintenance']))
	->addFieldset(
		(new CWidgetFormFieldsetCollapsibleView(_('Umbrales de uso')))
			->addField(new CWidgetFieldIntegerBoxView($data['fields']['resource_warning']))
			->addField(new CWidgetFieldIntegerBoxView($data['fields']['resource_critical']))
			->addField(new CWidgetFieldIntegerBoxView($data['fields']['disk_warning']))
			->addField(new CWidgetFieldIntegerBoxView($data['fields']['disk_critical']))
			->addField(new CWidgetFieldIntegerBoxView($data['fields']['datastore_warning']))
			->addField(new CWidgetFieldIntegerBoxView($data['fields']['datastore_critical']))
	)
	->includeJsFile('widget.edit.js.php')
	->addJavaScript('widget_vmware_overview_form.init();')
	->show();
