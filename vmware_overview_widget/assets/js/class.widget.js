/*
 * VMware: Vista general — lógica de cliente.
 *
 * Compatible con la API de widgets de Zabbix 7.0, 7.2, 7.4 y 8.0 (CWidget: setContents, hasPadding, onDeactivate,
 * onReferredUpdate, isReferred, broadcast). No depende de librerías externas.
 */
class CWidgetVmwareOverview extends CWidget {

	static RANGES = [
		[3600, '1 h'], [7200, '2 h'], [10800, '3 h'], [21600, '6 h'], [86400, '1 d'], [604800, '7 d'],
		[1296000, '15 d'], [2592000, '30 d']
	];

	/** Criterios generales; además, cada columna con data-sort se añade al selector "Ordenar". */
	static BASE_SORT_OPTIONS = [['consumption', 'Mayor consumo'], ['risk', 'Prioridad / alertas']];

	#root = null;
	#activeTab = 'vms';
	#page = {vms: 1, hypervisors: 1, datastores: 1};
	#sort = {vms: 'consumption', hypervisors: 'consumption', datastores: 'consumption'};
	#sortDirection = {vms: 'desc', hypervisors: 'desc', datastores: 'desc'};
	#search = '';
	#stateFilter = 'all';
	#selectedHostid = null;

	#modal = null;
	#modalReturnFocus = null;
	#escapeHandler = null;
	#modalViewportHandler = null;
	#requestController = null;
	#chartObservers = new Set();
	#chartSequence = 0;

	hasPadding() {
		return false;
	}

	setContents(response) {
		this.#closeModal();
		super.setContents(response);

		this.#root = this._body.querySelector('.vmw-overview-root');
		if (this.#root === null) {
			return;
		}

		this.#restoreControls();
		this.#bindControls();
		this.#selectTab(this.#activeTab);
		this.#syncSelection();
	}

	onDeactivate() {
		this.#closeModal();
	}

	onReferredUpdate() {
		if (this.#root !== null && this.#selectedHostid === null) {
			this.#syncSelection(true);
		}
	}

	// -----------------------------------------------------------------------------------------------------------
	// Lista principal
	// -----------------------------------------------------------------------------------------------------------

	#restoreControls() {
		const search = this.#root.querySelector('.vmw-search');
		const stateFilter = this.#root.querySelector('.vmw-state-filter');

		if (search) search.value = this.#search;
		if (stateFilter) stateFilter.value = this.#stateFilter;
		this.#setActiveSummary(this.#activeTab === 'vms' ? this.#stateFilter : '');
	}

	#bindControls() {
		const search = this.#root.querySelector('.vmw-search');
		const stateFilter = this.#root.querySelector('.vmw-state-filter');
		const sortField = this.#root.querySelector('.vmw-sort-field');
		const sortDirection = this.#root.querySelector('.vmw-sort-direction');

		search?.addEventListener('input', () => {
			this.#search = search.value;
			this.#page[this.#activeTab] = 1;
			this.#updateRows();
		});

		stateFilter?.addEventListener('change', () => {
			this.#stateFilter = stateFilter.value;
			this.#page[this.#activeTab] = 1;
			this.#setActiveSummary(stateFilter.value);
			this.#updateRows();
		});

		sortField?.addEventListener('change', () => {
			this.#sort[this.#activeTab] = sortField.value;
			this.#sortDirection[this.#activeTab] = this.#defaultDirection(sortField.value, this.#activeTab);
			this.#page[this.#activeTab] = 1;
			this.#syncSortControls();
			this.#updateRows();
		});

		sortDirection?.addEventListener('click', () => {
			this.#sortDirection[this.#activeTab] = this.#sortDirection[this.#activeTab] === 'desc' ? 'asc' : 'desc';
			this.#page[this.#activeTab] = 1;
			this.#syncSortControls();
			this.#updateRows();
		});

		for (const tab of this.#root.querySelectorAll('[data-tab]')) {
			tab.addEventListener('click', () => this.#selectTab(tab.dataset.tab));
		}

		// Clic en el título de columna: ordena por ella; un segundo clic invierte el sentido.
		for (const th of this.#root.querySelectorAll('th[data-sort]')) {
			th.querySelector('.vmw-sort-head')?.addEventListener('click', () => {
				const tab = th.closest('[data-panel]')?.dataset.panel ?? this.#activeTab;
				const field = th.dataset.sort;
				if (this.#sort[tab] === field) {
					this.#sortDirection[tab] = this.#sortDirection[tab] === 'desc' ? 'asc' : 'desc';
				}
				else {
					this.#sort[tab] = field;
					this.#sortDirection[tab] = this.#defaultDirection(field, tab);
				}
				this.#page[tab] = 1;
				this.#syncSortControls();
				this.#updateRows();
			});
		}

		for (const card of this.#root.querySelectorAll('.vmw-summary-card')) {
			card.addEventListener('click', () => {
				if (card.dataset.tabTarget) {
					this.#selectTab(card.dataset.tabTarget);
					return;
				}

				this.#selectTab('vms');
				this.#stateFilter = card.dataset.filter;
				if (stateFilter) stateFilter.value = card.dataset.filter;
				this.#page.vms = 1;
				this.#setActiveSummary(card.dataset.filter);
				this.#updateRows();
			});
		}

		for (const panel of this.#root.querySelectorAll('[data-panel]')) {
			panel.querySelector('[data-page-prev]')?.addEventListener('click', () => {
				this.#page[panel.dataset.panel] = Math.max(1, this.#page[panel.dataset.panel] - 1);
				this.#updateRows();
			});
			panel.querySelector('[data-page-next]')?.addEventListener('click', () => {
				this.#page[panel.dataset.panel]++;
				this.#updateRows();
			});
			panel.querySelector('tbody')?.addEventListener('click', event => {
				const row = event.target.closest('tr[data-row]');
				if (row?.dataset.hostid) this.#selectHost(row.dataset.hostid, true);
			});
		}

		const bind = (selector, handler) => {
			for (const element of this.#root.querySelectorAll(selector)) {
				element.addEventListener('click', event => handler(element, event));
			}
		};

		// Sólo botones: las filas <tr> también llevan data-disk/data-cpu… como claves de ordenación.
		bind('button[data-disk]', element => this.#loadDisk(element));
		bind('button[data-vm-details]', element => this.#loadVmDetails(element));
		bind('button[data-vm-resource]', (element, event) => {
			event.stopPropagation();
			this.#selectHost(element.dataset.hostid, true);
			this.#loadVmResource(element);
		});
		bind('button[data-hv-details]', element => this.#loadHvDetails(element));
		bind('button[data-datastore-details]', element => this.#loadDatastoreDetails(element));
	}

	#selectTab(tabName) {
		if (!['vms', 'hypervisors', 'datastores'].includes(tabName)) {
			tabName = 'vms';
		}

		this.#activeTab = tabName;
		if (tabName !== 'vms') {
			const stateFilter = this.#root.querySelector('.vmw-state-filter');
			this.#stateFilter = 'all';
			if (stateFilter) stateFilter.value = 'all';
			this.#setActiveSummary('');
		}
		for (const tab of this.#root.querySelectorAll('[data-tab]')) {
			const active = tab.dataset.tab === tabName;
			tab.classList.toggle('active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
		}
		for (const panel of this.#root.querySelectorAll('[data-panel]')) {
			panel.classList.toggle('active', panel.dataset.panel === tabName);
		}

		this.#configureSortOptions();
		this.#updateRows();
	}

	#sortColumns(tab) {
		return [...this.#root.querySelectorAll(`[data-panel="${tab}"] th[data-sort]`)];
	}

	#sortType(field, tab) {
		const th = this.#sortColumns(tab).find(column => column.dataset.sort === field);
		return th?.dataset.sortType ?? 'number';
	}

	/** Texto: A→Z primero. Números: mayor a menor primero. */
	#defaultDirection(field, tab) {
		return this.#sortType(field, tab) === 'text' ? 'asc' : 'desc';
	}

	#configureSortOptions() {
		const select = this.#root.querySelector('.vmw-sort-field');
		if (!select) return;

		const options = [...CWidgetVmwareOverview.BASE_SORT_OPTIONS,
			...this.#sortColumns(this.#activeTab).map(th => [th.dataset.sort, th.textContent.trim()])];
		select.replaceChildren(...options.map(([value, label]) => {
			const option = document.createElement('option');
			option.value = value;
			option.textContent = label;
			return option;
		}));
		this.#syncSortControls();
	}

	#syncSortControls() {
		const select = this.#root.querySelector('.vmw-sort-field');
		if (select) select.value = this.#sort[this.#activeTab];

		const field = this.#sort[this.#activeTab];
		const descending = this.#sortDirection[this.#activeTab] === 'desc';
		const text = this.#sortType(field, this.#activeTab) === 'text';
		const button = this.#root.querySelector('.vmw-sort-direction');
		if (button) {
			button.textContent = text ? (descending ? '↓ Z → A' : '↑ A → Z') : (descending ? '↓ Mayor a menor' : '↑ Menor a mayor');
			button.title = 'Invertir el orden';
		}

		for (const tab of Object.keys(this.#sort)) {
			for (const th of this.#sortColumns(tab)) {
				const active = th.dataset.sort === this.#sort[tab];
				const direction = this.#sortDirection[tab];
				th.classList.toggle('vmw-sorted', active);
				th.classList.toggle('asc', active && direction === 'asc');
				th.classList.toggle('desc', active && direction === 'desc');
				th.setAttribute('aria-sort', active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none');
			}
		}
	}

	#setActiveSummary(filter) {
		for (const card of this.#root.querySelectorAll('.vmw-summary-card[data-filter]')) {
			card.classList.toggle('active', card.dataset.filter === filter && this.#activeTab === 'vms');
		}
	}

	#updateRows() {
		const panel = this.#root.querySelector(`[data-panel="${this.#activeTab}"]`);
		if (panel === null) return;

		const search = this.#search.trim().toLocaleLowerCase();
		const state = this.#stateFilter;
		const pageSize = Math.max(1, Number(this.#root.dataset.rowsPerPage) || 25);
		const allRows = [...panel.querySelectorAll('tbody [data-row]')];
		const sortField = this.#sort[this.#activeTab];
		const direction = this.#sortDirection[this.#activeTab] === 'desc' ? -1 : 1;
		const key = `sort${sortField.charAt(0).toUpperCase()}${sortField.slice(1)}`;
		const isText = this.#sortType(sortField, this.#activeTab) === 'text';
		const byName = (left, right) => (left.dataset.sortName ?? '').localeCompare(right.dataset.sortName ?? '',
			undefined, {numeric: true, sensitivity: 'base'});

		allRows.sort((left, right) => {
			const a = left.dataset[key] ?? '';
			const b = right.dataset[key] ?? '';
			if (a === '' || b === '') {
				// Sin dato: siempre al final, sea cual sea el sentido.
				return a === b ? byName(left, right) : (a === '' ? 1 : -1);
			}
			const result = isText
				? a.localeCompare(b, undefined, {numeric: true, sensitivity: 'base'})
				: Number(a) - Number(b);
			return result === 0 ? byName(left, right) : direction * result;
		});
		panel.querySelector('tbody')?.append(...allRows);

		const matchingRows = allRows.filter(row => {
			const matchesSearch = search === '' || (row.dataset.search ?? '').toLocaleLowerCase().includes(search);
			const matchesState = this.#activeTab === 'datastores'
				? (state === 'all' || (state === 'problems' && row.dataset.problems === '1'))
				: (state === 'all' || (state === 'problems' ? row.dataset.problems === '1' : row.dataset.state === state));
			return matchesSearch && matchesState;
		});

		const pages = Math.max(1, Math.ceil(matchingRows.length / pageSize));
		this.#page[this.#activeTab] = Math.min(this.#page[this.#activeTab], pages);
		const page = this.#page[this.#activeTab];
		const first = (page - 1) * pageSize;
		const last = first + pageSize;

		for (const row of allRows) row.hidden = true;
		matchingRows.slice(first, last).forEach(row => row.hidden = false);

		const pagination = panel.querySelector('[data-pagination]');
		if (pagination !== null) {
			const shownFrom = matchingRows.length === 0 ? 0 : first + 1;
			const shownTo = Math.min(last, matchingRows.length);
			const filtered = matchingRows.length !== allRows.length ? ` (filtrado de ${allRows.length})` : '';
			pagination.querySelector('.vmw-page-info').textContent =
				`Mostrando ${shownFrom}–${shownTo} de ${matchingRows.length}${filtered}`;
			const number = pagination.querySelector('.vmw-page-number');
			if (number) number.textContent = `${page} / ${pages}`;
			pagination.querySelector('[data-page-prev]').disabled = page <= 1;
			pagination.querySelector('[data-page-next]').disabled = page >= pages;
			pagination.hidden = allRows.length === 0;
		}
	}

	// Selección de host y difusión a otros widgets del dashboard (_hostid / _hostids, declarados en el manifest).

	#selectHost(hostid, broadcast) {
		if (!hostid) return;

		this.#selectedHostid = String(hostid);
		for (const row of this.#root?.querySelectorAll('tr[data-row][data-hostid]') ?? []) {
			row.classList.toggle('vmw-selected', row.dataset.hostid === this.#selectedHostid);
		}

		if (!broadcast) return;

		try {
			const hostType = typeof CWidgetsData !== 'undefined' ? CWidgetsData.DATA_TYPE_HOST_ID : '_hostid';
			const hostsType = typeof CWidgetsData !== 'undefined' ? CWidgetsData.DATA_TYPE_HOST_IDS : '_hostids';
			this.broadcast({[hostType]: [this.#selectedHostid], [hostsType]: [this.#selectedHostid]});
		}
		catch (error) {
			// Versiones o configuraciones sin difusión entre widgets: la selección visual sigue funcionando.
		}
	}

	#syncSelection(forceBroadcast = false) {
		const rows = [...this.#root.querySelectorAll('tbody[data-table-body="vms"] tr[data-row][data-hostid]')];
		const exists = this.#selectedHostid !== null
			&& this.#root.querySelector(`tr[data-row][data-hostid="${CSS.escape(this.#selectedHostid)}"]`) !== null;
		let referred = false;

		try {
			referred = typeof this.isReferred === 'function' && this.isReferred();
		}
		catch (error) {
			referred = false;
		}

		if (exists) {
			this.#selectHost(this.#selectedHostid, forceBroadcast);
		}
		else if (referred || forceBroadcast) {
			const first = rows.find(row => !row.hidden) ?? rows[0];
			if (first) this.#selectHost(first.dataset.hostid, true);
		}
	}

	// -----------------------------------------------------------------------------------------------------------
	// Peticiones
	// -----------------------------------------------------------------------------------------------------------

	#thresholdArguments(curl) {
		curl.setArgument('warning', this.#root.dataset.diskWarning);
		curl.setArgument('critical', this.#root.dataset.diskCritical);
		curl.setArgument('resource_warning', this.#root.dataset.resourceWarning || 75);
		curl.setArgument('resource_critical', this.#root.dataset.resourceCritical || 90);
	}

	async #request(curl, options = {}) {
		this.#requestController?.abort();
		const controller = new AbortController();
		this.#requestController = controller;

		let response;
		try {
			response = await fetch(curl.getUrl(), {
				method: 'GET', credentials: 'same-origin', headers: {'Accept': 'application/json'},
				signal: controller.signal, ...options
			});
		}
		finally {
			if (this.#requestController === controller) this.#requestController = null;
		}

		if (!response.ok) throw new Error(`El servidor respondió HTTP ${response.status}.`);

		const text = await response.text();
		let data;
		try {
			data = JSON.parse(text);
			if (typeof data === 'string') data = JSON.parse(data);
		}
		catch (error) {
			throw new Error('Respuesta no válida del servidor. La sesión pudo haber expirado: recarga la página.');
		}

		if (data?.error) {
			throw new Error(typeof data.error === 'string' ? data.error : 'Error de respuesta del servidor.');
		}

		return data;
	}

	async #load(build, render, errorText) {
		const modal = this.#modal;
		try {
			const curl = new Curl('zabbix.php');
			build(curl);
			const data = await this.#request(curl);
			if (this.#modal !== modal || modal === null) return;
			render(data);
		}
		catch (error) {
			if (error?.name === 'AbortError' || this.#modal !== modal) return;
			this.#renderModalError(error?.message || errorText);
		}
	}

	#loadDisk(button, range = 3600, preserveModal = false) {
		if (!preserveModal) {
			this.#openModal(`${button.dataset.host} · Disco ${button.textContent}`, button.dataset.fs,
				'Cargando información del disco…');
		}
		this.#load(curl => {
			curl.setArgument('action', 'widget.vmwareoverview.disk.get');
			curl.setArgument('hostid', button.dataset.hostid);
			curl.setArgument('fs', button.dataset.fs);
			curl.setArgument('range', range);
			this.#thresholdArguments(curl);
		}, data => this.#renderDisk(data, button), t('Unable to load disk information.'));
	}

	#loadVmDetails(button, range = 3600, preserveModal = false) {
		if (!preserveModal) {
			this.#openModal(button.dataset.host, 'Máquina virtual · vista operativa', 'Organizando los recursos descubiertos…',
				true);
		}
		this.#load(curl => {
			curl.setArgument('action', 'widget.vmwareoverview.vm.get');
			curl.setArgument('hostid', button.dataset.hostid);
			curl.setArgument('range', range);
			this.#thresholdArguments(curl);
		}, data => this.#renderVmDetails(data, button), t('Unable to load VM information.'));
	}

	#loadVmResource(button, range = 3600, preserveModal = false) {
		const cpu = button.dataset.vmResource === 'cpu';
		if (!preserveModal) {
			this.#openModal(button.dataset.host, cpu ? 'Detalle de CPU' : 'Detalle de memoria RAM', 'Cargando historial…',
				true);
		}
		this.#load(curl => {
			curl.setArgument('action', 'widget.vmwareoverview.vm.get');
			curl.setArgument('hostid', button.dataset.hostid);
			curl.setArgument('range', range);
			this.#thresholdArguments(curl);
		}, data => this.#renderVmResource(data, button.dataset.vmResource, button),
			'No se pudo cargar el recurso de la VM.');
	}

	#loadHvDetails(button, range = 3600, preserveModal = false) {
		if (!preserveModal) {
			this.#openModal(button.dataset.host, 'Hipervisor ESXi · vista operativa', 'Organizando los recursos descubiertos…',
				true);
		}
		this.#load(curl => {
			curl.setArgument('action', 'widget.vmwareoverview.hv.get');
			curl.setArgument('name', button.dataset.host);
			if (button.dataset.hostid) curl.setArgument('hostid', button.dataset.hostid);
			curl.setArgument('range', range);
			curl.setArgument('resource_warning', this.#root.dataset.resourceWarning || 75);
			curl.setArgument('resource_critical', this.#root.dataset.resourceCritical || 90);
		}, data => this.#renderHvDetails(data, button), t('Unable to load hypervisor information.'));
	}

	#loadDatastoreDetails(button, range = 3600, preserveModal = false) {
		if (!preserveModal) {
			this.#openModal(button.dataset.datastore, 'Datastore · capacidad y rendimiento', 'Cargando información…', true);
		}
		this.#load(curl => {
			curl.setArgument('action', 'widget.vmwareoverview.datastore.get');
			curl.setArgument('name', button.dataset.datastore);
			curl.setArgument('range', range);
			curl.setArgument('warning', this.#root.dataset.datastoreWarning || 80);
			curl.setArgument('critical', this.#root.dataset.datastoreCritical || 90);
		}, data => this.#renderDatastoreDetails(data, button), 'No se pudo cargar la información del datastore.');
	}

	// -----------------------------------------------------------------------------------------------------------
	// Ventana
	// -----------------------------------------------------------------------------------------------------------

	#openModal(title, subtitle, loadingText, wide = false) {
		this.#closeModal();
		this.#modalReturnFocus = document.activeElement;

		const overlay = document.createElement('div');
		overlay.className = 'vmw-modal-overlay';

		const titleId = `vmw-modal-title-${++this.#chartSequence}`;
		const dialog = this.#el('div', `vmw-modal${wide ? ' vmw-vm-modal' : ''}`);
		dialog.setAttribute('role', 'dialog');
		dialog.setAttribute('aria-modal', 'true');
		dialog.setAttribute('aria-labelledby', titleId);

		const heading = this.#el('div');
		const h3 = this.#el('h3', null, title);
		h3.id = titleId;
		heading.append(h3, this.#el('span', null, subtitle || ''));

		const close = this.#el('button', 'vmw-modal-close', '×');
		close.type = 'button';
		close.title = 'Cerrar (Esc)';
		close.setAttribute('aria-label', 'Cerrar');
		close.addEventListener('click', () => this.#closeModal());

		const header = this.#el('div', 'vmw-modal-header');
		header.append(heading, close);

		const body = this.#el('div', 'vmw-modal-body');
		body.append(this.#el('div', 'vmw-loading', loadingText));

		dialog.append(header, body);
		overlay.append(dialog);
		overlay.addEventListener('mousedown', event => {
			if (event.target === overlay) this.#closeModal();
		});

		document.body.append(overlay);
		this.#modal = overlay;
		this.#bindModalViewport();

		this.#escapeHandler = event => {
			if (event.key === 'Escape') {
				event.stopPropagation();
				this.#closeModal();
			}
			else if (event.key === 'Tab') {
				this.#trapFocus(event, dialog);
			}
		};
		document.addEventListener('keydown', this.#escapeHandler, true);
		close.focus({preventScroll: true});
	}

	#trapFocus(event, dialog) {
		const focusable = [...dialog.querySelectorAll('button:not(:disabled), a[href], summary, [tabindex]:not([tabindex="-1"])')]
			.filter(element => element.offsetParent !== null);
		if (!focusable.length) return;

		const first = focusable[0];
		const last = focusable[focusable.length - 1];
		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		}
		else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	}

	#bindModalViewport() {
		const viewport = window.visualViewport;
		this.#modalViewportHandler = () => {
			if (!this.#modal) return;
			const width = Math.max(280, Math.floor(viewport?.width || window.innerWidth || document.documentElement.clientWidth));
			const height = Math.max(240, Math.floor(viewport?.height || window.innerHeight || document.documentElement.clientHeight));
			const left = Math.max(0, Math.floor(viewport?.offsetLeft || 0));
			const top = Math.max(0, Math.floor(viewport?.offsetTop || 0));
			const padding = width <= 680 ? 6 : Math.min(22, Math.max(8, Math.floor(width * .02)));
			this.#modal.style.setProperty('inset', 'auto', 'important');
			this.#modal.style.setProperty('left', `${left}px`, 'important');
			this.#modal.style.setProperty('top', `${top}px`, 'important');
			this.#modal.style.setProperty('width', `${width}px`, 'important');
			this.#modal.style.setProperty('height', `${height}px`, 'important');
			this.#modal.style.setProperty('padding', `${padding}px`, 'important');
			this.#modal.classList.toggle('vmw-modal-compact', width < 760 && !CSS.supports('container-type: inline-size'));
			const dialog = this.#modal.querySelector('.vmw-modal');
			if (dialog) {
				// Ventana mediana: como máximo 960 px (detalle) / 820 px (disco) y 86 % del alto visible, dejando
				// el dashboard a la vista. En pantallas pequeñas ocupa el espacio disponible.
				const cap = dialog.classList.contains('vmw-vm-modal') ? 960 : 820;
				const maxWidth = Math.min(cap, width - padding * 2);
				const maxHeight = width <= 680 ? height - padding * 2 : Math.min(height - padding * 2, Math.floor(height * .86));
				dialog.style.setProperty('max-width', `${Math.max(260, maxWidth)}px`, 'important');
				dialog.style.setProperty('max-height', `${Math.max(210, maxHeight)}px`, 'important');
			}
		};
		this.#modalViewportHandler();
		window.addEventListener('resize', this.#modalViewportHandler);
		viewport?.addEventListener('resize', this.#modalViewportHandler);
		viewport?.addEventListener('scroll', this.#modalViewportHandler);
	}

	#modalBody() {
		return this.#modal?.querySelector('.vmw-modal-body') ?? null;
	}

	#resetBody() {
		const body = this.#modalBody();
		if (!body) return null;
		this.#disconnectCharts();
		const scroll = body.scrollTop;
		body.replaceChildren();
		requestAnimationFrame(() => body.scrollTop = scroll);
		return body;
	}

	#renderModalError(message) {
		const body = this.#modalBody();
		if (!body) return;
		this.#disconnectCharts();
		body.replaceChildren(this.#el('div', 'vmw-modal-error', message));
	}

	#disconnectCharts() {
		for (const observer of this.#chartObservers) observer.disconnect();
		this.#chartObservers.clear();
	}

	#closeModal() {
		this.#requestController?.abort();
		this.#requestController = null;
		this.#disconnectCharts();

		if (this.#escapeHandler !== null) {
			document.removeEventListener('keydown', this.#escapeHandler, true);
			this.#escapeHandler = null;
		}
		if (this.#modalViewportHandler !== null) {
			window.removeEventListener('resize', this.#modalViewportHandler);
			window.visualViewport?.removeEventListener('resize', this.#modalViewportHandler);
			window.visualViewport?.removeEventListener('scroll', this.#modalViewportHandler);
			this.#modalViewportHandler = null;
		}

		if (this.#modal !== null) {
			this.#modal.remove();
			this.#modal = null;
			if (this.#modalReturnFocus instanceof HTMLElement && this.#modalReturnFocus.isConnected) {
				this.#modalReturnFocus.focus({preventScroll: true});
			}
		}
		this.#modalReturnFocus = null;
	}

	// -----------------------------------------------------------------------------------------------------------
	// Contenido de las ventanas
	// -----------------------------------------------------------------------------------------------------------

	#renderVmDetails(data, button) {
		const body = this.#resetBody();
		if (!body) return;

		const warning = Number(data.resource_warning ?? this.#root.dataset.resourceWarning ?? 75);
		const critical = Number(data.resource_critical ?? this.#root.dataset.resourceCritical ?? 90);
		const s = data.summary;
		const alerts = data.problems.length;

		body.append(this.#makeRangeSelector(data.range, next => this.#loadVmDetails(button, next, true)));
		body.append(this.#makeFacts([
			['Estado', s.power, s.power_class],
			['CPU', `${s.cpu} · ${s.vcpu} vCPU`, this.#tone(s.cpu_raw, warning, critical), true],
			['RAM', `${s.memory} de ${s.memory_total}`, this.#tone(s.memory_raw, warning, critical), true],
			['Almacenamiento', s.storage_committed, ''],
			['Uptime', s.uptime, ''],
			['Alertas activas', String(alerts), alerts > 0 ? 'critical' : 'ok', alerts > 0]
		]));
		body.append(this.#makeMeta(data, [data.address, data.os]));
		body.append(this.#makeActions(data, 'vm'));

		const charts = this.#el('div', 'vmw-chart-grid');
		charts.append(
			this.#makeChartCard({
				title: 'Uso de CPU', period: data.range_label, units: '%', percent: true,
				thresholds: {warning, critical},
				series: [{label: 'CPU', history: data.history.cpu, className: 'vmw-series-1'}]
			}),
			this.#makeChartCard({
				title: data.history.memory_source === 'host' ? 'Memoria (uso sobre el host)' : 'Uso de RAM del invitado',
				period: data.range_label, units: '%', percent: true, thresholds: {warning, critical},
				series: [{label: 'RAM', history: data.history.memory, className: 'vmw-series-1'}]
			})
		);
		body.append(charts);

		const location = this.#makeSection('Ubicación e integración');
		const facts = [
			['Hipervisor', s.hypervisor, ''], ['Clúster', s.cluster, ''], ['Datacenter', s.datacenter, ''],
			['VMware Tools', s.tools_version && s.tools_version !== '—' ? `${s.tools} · ${s.tools_version}` : s.tools,
				/activo/i.test(s.tools ?? '') && !/no activo/i.test(s.tools ?? '') ? 'ok' : 'warning'],
			['Snapshots', s.snapshot_latest && s.snapshot_latest !== '—' ? `${s.snapshots} · ${s.snapshot_latest}` : s.snapshots, ''],
			['Consolidación', s.consolidation ?? '—', s.consolidation_needed ? 'warning' : ''],
			['CPU ready', s.cpu_ready ?? '—', Number(s.cpu_ready_raw) >= 5 ? 'warning' : ''],
			['Memoria balloon', s.memory_ballooned ?? '—', ''],
			['Memoria swap', s.memory_swapped ?? '—', ''],
			['Aprovisionado', s.storage_provisioned, '']
		];
		if (s.hv_maintenance && s.hv_maintenance !== '—') {
			facts.push(['ESXi en mantenimiento', s.hv_maintenance, s.hv_maintenance === 'Sí' ? 'warning' : '']);
		}
		location.append(this.#makeFacts(facts));
		body.append(location);

		const primary = data.disks.filter(disk => disk.primary);
		const secondary = data.disks.filter(disk => !disk.primary);
		body.append(this.#makeDiskSection('Discos y sistemas de archivos', primary,
			'No hay discos principales reportados. VMware Tools debe estar activo.'));
		if (secondary.length) {
			const extra = this.#makeDetails('Montajes adicionales', `${secondary.length} sistemas de archivos`);
			extra.append(this.#makeTable(
				[{text: 'Ruta'}, {text: 'Uso'}, {text: 'Libre', num: true}, {text: 'Total', num: true}],
				secondary, row => [this.#el('span', 'vmw-mono', row.name), this.#barCell(row.used_percent_raw, row.used_percent, row.status), row.free, row.total]
			));
			body.append(extra);
		}

		body.append(this.#makeDataSection('Interfaces de red', data.networks,
			[{text: 'Interfaz'}, {text: 'Recibido', num: true}, {text: 'Enviado', num: true}, {text: 'Uso', num: true},
				{text: 'Actualización'}],
			row => [row.name, row.received, row.sent, row.usage, row.last_update],
			'No hay interfaces reportadas.'));

		const networkCharts = this.#el('div', 'vmw-network-grid');
		for (const network of data.networks) {
			if ((network.received_history?.length ?? 0) < 2 && (network.sent_history?.length ?? 0) < 2) continue;
			networkCharts.append(this.#makeChartCard({
				title: `Tráfico · ${network.name}`, period: data.range_label, units: network.units || 'Bps',
				series: [
					{label: 'Recibido', history: network.received_history, className: 'vmw-series-in'},
					{label: 'Enviado', history: network.sent_history, className: 'vmw-series-out'}
				]
			}));
		}
		if (networkCharts.children.length) body.append(networkCharts);

		body.append(this.#makeProblemsSection(data.problems));
		body.append(this.#makeTechnicalDetails('Datos técnicos descubiertos',
			`${data.supported_count} de ${data.item_count} ítems con datos`, data.technical));
	}

	#renderHvDetails(data, button) {
		const body = this.#resetBody();
		if (!body) return;

		const warning = Number(data.resource_warning ?? this.#root.dataset.resourceWarning ?? 75);
		const critical = Number(data.resource_critical ?? this.#root.dataset.resourceCritical ?? 90);
		const s = data.summary;

		body.append(this.#makeRangeSelector(data.range, next => this.#loadHvDetails(button, next, true)));
		if (!data.resolved) {
			body.append(this.#el('div', 'vmw-notice',
				'Hipervisor inferido por las VMs: el host ESXi descubierto no es visible para este usuario o sus ítems '
				+ 'vmware.hv.* no tienen datos. CPU y RAM se calculan desde las VMs visibles; concede permiso de lectura '
				+ 'sobre el grupo de hosts ESXi para ver capacidad física, salud, versión e históricos reales.'));
		}

		const connectionTone = /^conectado$/i.test(s.connection) ? 'ok' : (/no disponible/i.test(s.connection) ? '' : 'critical');
		const healthTone = /correcto/i.test(s.health) ? 'ok' : (/advertencia/i.test(s.health) ? 'warning'
			: (/crítico/i.test(s.health) ? 'critical' : ''));

		body.append(this.#makeFacts([
			['Conexión', s.connection, connectionTone],
			['Salud', s.health, healthTone],
			['CPU', s.cpu, this.#tone(s.cpu_raw, warning, critical), true],
			['RAM', `${s.memory} · ${s.memory_used} / ${s.memory_total}`, this.#tone(s.memory_raw, warning, critical), true],
			['VMs', `${s.powered_on} encendidas de ${s.vms}`, ''],
			['Uptime', s.uptime, '']
		]));
		body.append(this.#makeMeta(data, [data.address, s.cluster, s.datacenter]));
		body.append(this.#makeActions(data, 'hypervisor'));

		const charts = this.#el('div', 'vmw-chart-grid');
		charts.append(
			this.#makeChartCard({
				title: 'Uso de CPU del hipervisor', period: data.range_label, units: '%', percent: true,
				thresholds: {warning, critical},
				series: [{label: 'CPU', history: data.history.cpu, className: 'vmw-series-1'}]
			}),
			this.#makeChartCard({
				title: 'Uso de RAM del hipervisor', period: data.range_label, units: '%', percent: true,
				thresholds: {warning, critical},
				series: [{label: 'RAM', history: data.history.memory, className: 'vmw-series-1'}]
			})
		);
		body.append(charts);
		body.append(this.#makeChartCard({
			title: 'Tráfico de red del hipervisor', period: data.range_label, units: data.network_units || 'Bps',
			series: [
				{label: 'Entrada', history: data.history.network_in, className: 'vmw-series-in'},
				{label: 'Salida', history: data.history.network_out, className: 'vmw-series-out'}
			]
		}));

		const hardware = this.#makeSection('Capacidad e inventario');
		hardware.append(this.#makeFacts([
			['Modelo', s.model, ''], ['Fabricante', s.vendor, ''], ['Versión', s.version, ''],
			['CPU físico', `${s.cores} núcleos · ${s.threads} hilos`, ''],
			['Procesador', s.cpu_model ?? '—', ''], ['N.º de serie', s.serial ?? '—', ''],
			['vCPU asignadas', String(s.allocated_vcpu), ''], ['RAM asignada', s.allocated_memory, ''],
			['Memoria balloon', s.memory_ballooned ?? '—', '']
		]));
		body.append(hardware);

		body.append(this.#makeDataSection('Máquinas virtuales', data.vms,
			[{text: 'VM'}, {text: 'Estado'}, {text: 'CPU'}, {text: 'vCPU', num: true}, {text: 'RAM', num: true}],
			row => [
				row.name,
				this.#statusNode(row.power, row.power === 'Encendida' ? 'ok' : (row.power === 'Apagada' ? 'off' : 'warning')),
				this.#barCell(row.cpu_raw, row.cpu, this.#tone(row.cpu_raw, warning, critical)),
				row.vcpu, row.memory
			], 'No hay VMs asociadas visibles.'));

		body.append(this.#makeDataSection('Datastores vistos por este ESXi', data.datastores,
			[{text: 'Datastore'}, {text: 'Uso'}, {text: 'Libre', num: true}, {text: 'Capacidad', num: true}],
			row => [
				row.name,
				this.#barCell(row.pused_raw, row.pused_raw === null ? '—' : `${Number(row.pused_raw).toFixed(1)} %`,
					this.#tone(row.pused_raw, Number(this.#root.dataset.datastoreWarning || 80),
						Number(this.#root.dataset.datastoreCritical || 90))),
				row.free, row.total
			], 'Los datastores consolidados se muestran en la pestaña principal Datastores.'));

		body.append(this.#makeProblemsSection(data.problems));
		if (data.technical.length) {
			body.append(this.#makeTechnicalDetails('Datos técnicos ESXi', `${data.technical.length} ítems`,
				[{name: '', rows: data.technical}]));
		}
	}

	#renderVmResource(data, resource, button) {
		const body = this.#resetBody();
		if (!body) return;

		const warning = Number(data.resource_warning ?? this.#root.dataset.resourceWarning ?? 75);
		const critical = Number(data.resource_critical ?? this.#root.dataset.resourceCritical ?? 90);
		const s = data.summary;
		const cpu = resource === 'cpu';

		body.append(this.#makeRangeSelector(data.range, next => this.#loadVmResource(button, next, true)));
		body.append(this.#makeFacts(cpu ? [
			['Uso actual', s.cpu, this.#tone(s.cpu_raw, warning, critical), true],
			['vCPU', s.vcpu, ''],
			['CPU ready', s.cpu_ready ?? '—', Number(s.cpu_ready_raw) >= 5 ? 'warning' : ''],
			['Estado', s.power, s.power_class],
			['Hipervisor', s.hypervisor, ''],
			['Umbrales', `${warning} % / ${critical} %`, '']
		] : [
			['Uso actual', s.memory, this.#tone(s.memory_raw, warning, critical), true],
			['Configurada', s.memory_total, ''],
			['Usada por el invitado', s.memory_guest ?? '—', ''],
			['Balloon / swap', `${s.memory_ballooned ?? '—'} / ${s.memory_swapped ?? '—'}`, ''],
			['Estado', s.power, s.power_class],
			['Umbrales', `${warning} % / ${critical} %`, '']
		]));
		body.append(this.#makeMeta(data, [data.address, data.os]));
		body.append(this.#makeChartCard({
			title: cpu ? 'Uso de CPU' : (data.history.memory_source === 'host' ? 'Memoria (uso sobre el host)' : 'Uso de RAM del invitado'),
			period: data.range_label, units: '%', percent: true, thresholds: {warning, critical}, height: 240,
			series: [{label: cpu ? 'CPU' : 'RAM', history: cpu ? data.history.cpu : data.history.memory, className: 'vmw-series-1'}]
		}));
		body.append(this.#makeActions(data, 'vm'));
	}

	#renderDatastoreDetails(data, button) {
		const body = this.#resetBody();
		if (!body) return;

		const warning = Number(data.warning ?? this.#root.dataset.datastoreWarning ?? 80);
		const critical = Number(data.critical ?? this.#root.dataset.datastoreCritical ?? 90);

		body.append(this.#makeRangeSelector(data.range, next => this.#loadDatastoreDetails(button, next, true)));
		body.append(this.#makeFacts([
			['Estado', data.status_text, data.status],
			['Capacidad', data.total, ''],
			['Usado', `${data.used} · ${data.used_percent}`, data.status === 'ok' ? '' : data.status, data.status !== 'ok'],
			['Libre', `${data.free} · ${data.free_percent}`, ''],
			['No comprometido', data.uncommitted, ''],
			['Latencia L / E', `${data.read_latency} / ${data.write_latency}`, '']
		]));
		body.append(this.#makeGauge('Ocupación consolidada', data.used_percent_raw, data.used_percent, data.status,
			data.status_text, `Umbrales: advertencia ≥ ${warning} % · crítico ≥ ${critical} %`));

		const charts = this.#el('div', 'vmw-chart-grid');
		charts.append(
			this.#makeChartCard({
				title: 'Ocupación del datastore', period: data.range_label, units: '%', percent: true,
				thresholds: {warning, critical},
				series: [{label: 'Uso', history: data.history.usage, className: 'vmw-series-1'}]
			}),
			this.#makeChartCard({
				title: 'Latencia de lectura y escritura', period: data.range_label, units: data.latency_units || 'ms',
				series: [
					{label: 'Lectura', history: data.history.read, className: 'vmw-series-in'},
					{label: 'Escritura', history: data.history.write, className: 'vmw-series-out'}
				]
			})
		);
		body.append(charts);
		if ((data.history.read_iops?.length ?? 0) > 1 || (data.history.write_iops?.length ?? 0) > 1) {
			body.append(this.#makeChartCard({
				title: 'Operaciones por segundo (IOPS)', period: data.range_label, units: 'iops',
				series: [
					{label: 'Lectura', history: data.history.read_iops, className: 'vmw-series-in'},
					{label: 'Escritura', history: data.history.write_iops, className: 'vmw-series-out'}
				]
			}));
		}

		body.append(this.#makeDatastoreActions(data));

		const sources = this.#makeSection('Fuentes que reportan este datastore', data.sources.length);
		if (!data.sources.length) {
			sources.append(this.#el('div', 'vmw-section-empty', 'No hay fuentes visibles.'));
		}
		else {
			const list = this.#el('div', 'vmw-source-list');
			for (const source of data.sources) {
				const link = this.#el(source.url ? 'a' : 'span', null, source.name);
				if (source.url) link.href = source.url;
				list.append(link);
			}
			sources.append(list);
		}
		body.append(sources);
	}

	#renderDisk(data, button) {
		const body = this.#resetBody();
		if (!body) return;

		body.append(this.#makeRangeSelector(data.range, next => this.#loadDisk(button, next, true)));
		body.append(this.#makeGauge('Uso del disco', data.used_percent,
			data.used_percent === null ? '—' : `${Number(data.used_percent).toFixed(1)} %`, data.status, data.status_text,
			`Umbrales del widget: advertencia ≥ ${data.warning} % · crítico ≥ ${data.critical} %`));

		body.append(this.#makeChartCard({
			title: 'Uso del disco', period: data.range_label, units: '%', percent: true, height: 220,
			thresholds: {warning: data.warning, critical: data.critical},
			series: [{label: 'Uso', history: data.history, className: 'vmw-series-1'}]
		}));

		const section = this.#makeSection('Métricas del sistema de archivos');
		section.append(this.#makeTable(
			[{text: 'Métrica'}, {text: 'Última comprobación'}, {text: 'Último valor', num: true}, {text: 'Cambio', num: true}],
			data.rows, row => [row.name, row.last_check, row.value, row.change],
			row => row.supported ? '' : 'unsupported',
			row => row.error || ''
		));
		body.append(section);
		body.append(this.#makeActions(data, 'vm'));
	}

	// -----------------------------------------------------------------------------------------------------------
	// Componentes de la ventana
	// -----------------------------------------------------------------------------------------------------------

	#el(tag, className = null, text = null) {
		const element = document.createElement(tag);
		if (className) element.className = className;
		if (text !== null && text !== undefined) element.textContent = String(text);
		return element;
	}

	#tone(value, warning, critical) {
		if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) return '';
		const number = Number(value);
		return number >= critical ? 'critical' : (number >= warning ? 'warning' : 'ok');
	}

	#makeRangeSelector(active, onSelect) {
		const wrap = this.#el('div', 'vmw-range-selector');
		wrap.append(this.#el('strong', null, 'Período'));
		const group = this.#el('div', 'vmw-range-group');
		group.setAttribute('role', 'group');
		group.setAttribute('aria-label', 'Período histórico');

		for (const [seconds, text] of CWidgetVmwareOverview.RANGES) {
			const item = this.#el('button', 'vmw-range-button', text);
			item.type = 'button';
			const isActive = Number(active) === seconds;
			item.classList.toggle('active', isActive);
			item.setAttribute('aria-pressed', isActive ? 'true' : 'false');
			item.addEventListener('click', () => {
				if (isActive) return;
				for (const sibling of group.querySelectorAll('button')) sibling.disabled = true;
				wrap.classList.add('loading');
				onSelect(seconds);
			});
			group.append(item);
		}
		wrap.append(group, this.#el('span', 'vmw-range-spinner'));
		return wrap;
	}

	#makeFacts(rows) {
		const facts = this.#el('div', 'vmw-vm-facts');
		for (const [label, value, state, emphasize] of rows) {
			const fact = this.#el('div');
			if (state) fact.classList.add(state);
			const text = value === null || value === undefined || value === '' ? '—' : String(value);
			const content = this.#el('strong', emphasize && state && state !== 'ok' ? state : null, text);
			content.title = text;
			fact.append(this.#el('span', null, label), content);
			facts.append(fact);
		}
		return facts;
	}

	#makeMeta(data, parts) {
		const meta = this.#el('div', 'vmw-vm-meta');
		const text = parts.filter(part => part && part !== '—').join(' · ');
		meta.append(this.#el('span', null, text || 'Sin dirección registrada'),
			this.#el('span', null, `Actualizado ${data.last_update}`));
		return meta;
	}

	#makeSection(title, count = null) {
		const section = this.#el('div', 'vmw-resource-section');
		const heading = this.#el('h4', null, title);
		if (count !== null) heading.append(this.#el('span', 'vmw-section-count', String(count)));
		section.append(heading);
		return section;
	}

	#makeDetails(title, summaryText) {
		const details = this.#el('details', 'vmw-detail-category');
		const summary = this.#el('summary');
		summary.append(this.#el('strong', null, title), this.#el('span', null, summaryText));
		details.append(summary);
		return details;
	}

	/**
	 * @param {Array<{text: string, num?: boolean}>} headers
	 * @param {Array}    rows
	 * @param {Function} mapper      fila → celdas (texto o nodos DOM).
	 * @param {Function} rowClass    fila → clase opcional de <tr>.
	 * @param {Function} rowTitle    fila → título opcional de <tr>.
	 */
	#makeTable(headers, rows, mapper, rowClass = null, rowTitle = null, className = '') {
		const wrap = this.#el('div', 'vmw-detail-table-wrap');
		const table = this.#el('table', `vmw-detail-table ${className}`.trim());
		const head = table.createTHead().insertRow();
		for (const header of headers) {
			const th = this.#el('th', header.num ? 'vmw-num' : null, header.text);
			head.append(th);
		}
		const tbody = table.createTBody();
		for (const row of rows) {
			const tr = tbody.insertRow();
			const extraClass = rowClass ? rowClass(row) : '';
			if (extraClass) tr.classList.add(extraClass);
			const title = rowTitle ? rowTitle(row) : '';
			if (title) tr.title = title;
			mapper(row).forEach((value, index) => {
				const td = tr.insertCell();
				if (headers[index]?.num) td.className = 'vmw-num vmw-nowrap';
				if (value instanceof Node) td.append(value);
				else td.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
			});
		}
		wrap.append(table);
		return wrap;
	}

	#makeDataSection(title, rows, headers, mapper, emptyText) {
		const section = this.#makeSection(title, rows.length);
		if (!rows.length) section.append(this.#el('div', 'vmw-section-empty', emptyText));
		else section.append(this.#makeTable(headers, rows, mapper));
		return section;
	}

	#makeDiskSection(title, rows, emptyText) {
		const section = this.#makeSection(title, rows.length);
		if (!rows.length) {
			section.append(this.#el('div', 'vmw-section-empty', emptyText));
			return section;
		}
		section.append(this.#makeTable(
			[{text: 'Unidad'}, {text: 'Ruta'}, {text: 'Uso'}, {text: 'Usado', num: true}, {text: 'Libre', num: true},
				{text: 'Total', num: true}],
			rows,
			row => [row.label, this.#el('span', 'vmw-mono', row.name),
				this.#barCell(row.used_percent_raw, row.used_percent, row.status), row.used, row.free, row.total],
			row => row.status || 'unknown', null, 'vmw-disk-detail-table'
		));
		return section;
	}

	#barCell(raw, text, tone) {
		const cell = this.#el('div', 'vmw-cell-bar');
		const kind = tone || 'unknown';
		cell.append(this.#el('span', kind === 'ok' || kind === 'unknown' ? null : `vmw-metric-value ${kind}`, text ?? '—'));
		const track = this.#el('div', 'vmw-progress-track');
		const fill = this.#el('span', `vmw-progress-fill ${kind}`);
		fill.style.width = `${Math.max(0, Math.min(100, Number(raw) || 0))}%`;
		track.append(fill);
		cell.append(track);
		return cell;
	}

	#statusNode(text, tone) {
		const status = this.#el('span', `vmw-status ${tone}`);
		status.append(this.#el('span', 'vmw-status-dot'), document.createTextNode(text ?? '—'));
		return status;
	}

	#makeGauge(label, raw, text, status, statusText, note) {
		const wrap = this.#el('div');
		const overview = this.#el('div', 'vmw-modal-overview');
		const gauge = this.#el('div', 'vmw-disk-gauge');
		const header = this.#el('div');
		header.append(this.#el('strong', null, label), this.#el('span', null, text ?? '—'));
		const track = this.#el('div', 'vmw-modal-progress');
		const fill = this.#el('span', status || 'unknown');
		fill.style.width = `${Math.max(0, Math.min(100, Number(raw) || 0))}%`;
		track.append(fill);
		gauge.append(header, track);
		overview.append(gauge, this.#el('span', `vmw-modal-status ${status || ''}`, statusText));
		wrap.append(overview);
		if (note) wrap.append(this.#el('div', 'vmw-modal-note', note));
		return wrap;
	}

	#makeProblemsSection(problems) {
		return this.#makeDataSection('Problemas y advertencias activos', problems,
			[{text: 'Severidad'}, {text: 'Problema / advertencia'}, {text: 'Origen'}, {text: 'Desde'}, {text: 'Reconocido'}],
			row => [
				this.#el('span', `vmw-severity sev-${Math.max(0, Math.min(5, Number(row.severity) || 0))}`,
					this.#severityText(row.severity)),
				row.name,
				this.#el('span', 'vmw-source-tag', row.source ?? 'Zabbix'),
				row.since,
				this.#el('span', row.acknowledged ? 'vmw-ack-yes' : 'vmw-ack-no', row.acknowledged ? 'Sí' : 'No')
			],
			'Sin problemas ni advertencias activas.');
	}

	#makeTechnicalDetails(title, summaryText, categories) {
		const details = this.#makeDetails(title, summaryText);
		for (const category of categories) {
			const table = this.#makeTable(
				[{text: 'Ítem'}, {text: 'Valor'}, {text: 'Última comprobación'}, {text: 'Estado'}],
				category.rows,
				row => {
					const item = this.#el('div');
					item.append(this.#el('strong', null, row.name));
					if (row.key) item.append(this.#el('code', 'vmw-item-key', row.key));
					return [item, row.value, row.last_check ?? '—',
						this.#el('span', row.supported ? 'vmw-state-ok' : 'vmw-state-bad', row.supported ? 'Con datos' : 'Sin datos')];
				},
				null,
				row => row.error || '',
				'vmw-inventory-table'
			);
			if (category.name) {
				const section = this.#makeSection(category.name, category.rows.length);
				section.append(table);
				details.append(section);
			}
			else {
				details.append(table);
			}
		}
		return details;
	}

	#makeActions(data, type = 'vm') {
		const section = this.#makeSection('Acciones');
		section.classList.add('vmw-actions');
		const grid = this.#el('div', 'vmw-action-grid');
		const rawTarget = [data.console_target, data.address, data.name, data.host]
			.map(value => String(value || '').trim()).find(value => value && value !== '—') || '';
		const target = /^[A-Za-z0-9_.:\-\[\]]+$/.test(rawTarget) ? rawTarget : '';

		const addLink = (label, href, title = '', primary = false) => {
			if (!href) return;
			const link = this.#el('a', `vmw-action-button${primary ? ' primary' : ''}`, label);
			link.href = href;
			if (title) link.title = title;
			if (href.startsWith('https://')) {
				link.target = '_blank';
				link.rel = 'noopener noreferrer';
			}
			grid.append(link);
		};

		if (target) {
			const urlTarget = target.includes(':') && !target.startsWith('[') ? `[${target}]` : target;
			const uriTarget = encodeURI(urlTarget);
			if (type !== 'hypervisor') this.#addRdpDownload(grid, target, data.name);
			addLink('SSH', `ssh://${uriTarget}`, 'Requiere un cliente SSH asociado al navegador.');
			addLink(type === 'hypervisor' ? 'Consola ESXi' : 'HTTPS', `https://${uriTarget}/`,
				'Abre la administración web en una pestaña nueva.');
			grid.append(this.#copyButton('Copiar IP / nombre', target));
		}
		addLink('Últimos datos', data.latest_url, 'Abre Últimos datos filtrado por este host.', true);
		addLink('Problemas', data.problems_url, 'Abre Problemas filtrado por este host.');
		addLink('Dashboard del host', data.host_url, 'Abre el dashboard de host de Zabbix.');

		if (!grid.children.length) {
			grid.append(this.#el('span', 'vmw-section-empty', 'No hay un destino o enlaces disponibles.'));
		}
		section.append(grid);

		if (target) {
			const diagnostics = this.#el('details', 'vmw-diagnostics');
			diagnostics.append(this.#el('summary', null, 'Diagnóstico de red desde el servidor Zabbix'));
			const diagnosticGrid = this.#el('div', 'vmw-action-grid');
			const output = this.#el('div', 'vmw-diagnostic-output');
			output.setAttribute('aria-live', 'polite');
			const addDiagnostic = (label, operation) => {
				if (!data.hostid) return;
				const button = this.#el('button', 'vmw-action-button', label);
				button.type = 'button';
				button.addEventListener('click', () => this.#runDiagnostic(data.hostid, operation, label, button, output));
				diagnosticGrid.append(button);
			};
			addDiagnostic('Ping', 'ping');
			addDiagnostic('Traceroute', 'traceroute');
			if (this.#root.dataset.canScanPorts === '1') addDiagnostic('Escanear puertos (Nmap)', 'nmap');
			if (!diagnosticGrid.children.length) {
				diagnosticGrid.append(this.#el('span', 'vmw-section-empty',
					'El host debe estar visible en Zabbix para ejecutar diagnósticos.'));
			}
			diagnostics.append(diagnosticGrid, output);
			section.append(diagnostics);
		}

		section.append(this.#el('p', 'vmw-action-note',
			'Los diagnósticos se ejecutan en el servidor del frontend de Zabbix y su salida se muestra aquí.'));
		return section;
	}

	#addRdpDownload(grid, host, name) {
		const button = this.#el('button', 'vmw-action-button', 'Conexión RDP');
		button.type = 'button';
		button.title = 'Descarga un archivo .rdp para este equipo';
		button.addEventListener('click', () => {
			const content = ['screen mode id:i:2', 'use multimon:i:0', `full address:s:${host}`,
				'prompt for credentials:i:1', 'authentication level:i:2', 'redirectclipboard:i:1'].join('\r\n');
			const url = URL.createObjectURL(new Blob([content], {type: 'application/x-rdp'}));
			const anchor = document.createElement('a');
			anchor.href = url;
			anchor.download = `${String(name || 'vmware').replace(/[^A-Za-z0-9_.-]+/g, '_')}.rdp`;
			document.body.append(anchor);
			anchor.click();
			anchor.remove();
			setTimeout(() => URL.revokeObjectURL(url), 1000);
		});
		grid.append(button);
	}

	#copyButton(label, value) {
		const button = this.#el('button', 'vmw-action-button', label);
		button.type = 'button';
		button.title = value;
		button.addEventListener('click', async () => {
			try {
				await navigator.clipboard.writeText(value);
			}
			catch (error) {
				const input = document.createElement('textarea');
				input.value = value;
				input.style.position = 'fixed';
				input.style.opacity = '0';
				document.body.append(input);
				input.select();
				document.execCommand('copy');
				input.remove();
			}
			button.textContent = 'Copiado';
			button.classList.add('copied');
			setTimeout(() => {
				button.textContent = label;
				button.classList.remove('copied');
			}, 1600);
		});
		return button;
	}

	async #runDiagnostic(hostid, operation, label, button, output) {
		const original = button.textContent;
		const grid = button.closest('.vmw-action-grid');
		for (const item of grid.querySelectorAll('button')) item.disabled = true;
		button.textContent = 'Ejecutando…';
		output.classList.add('visible');
		output.replaceChildren(this.#el('div', 'vmw-loading-inline', `${label} desde el servidor de Zabbix…`));

		try {
			const curl = new Curl('zabbix.php');
			curl.setArgument('action', 'widget.vmwareoverview.diagnostic.run');
			const form = new URLSearchParams();
			form.set('hostid', hostid);
			form.set('operation', operation);
			form.set('_csrf_token', this.#root.dataset.diagnosticToken || '');

			const response = await fetch(curl.getUrl(), {
				method: 'POST', credentials: 'same-origin',
				headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'},
				body: form.toString()
			});
			const text = await response.text();
			let data;
			try {
				data = JSON.parse(text);
				if (typeof data === 'string') data = JSON.parse(data);
			}
			catch (error) {
				throw new Error('El servidor rechazó la solicitud (token de seguridad caducado). Recarga el dashboard.');
			}
			if (data.error) throw new Error(data.error);

			const header = this.#el('div', 'vmw-diagnostic-header',
				`${data.host} · ${data.target} · ${data.duration} s · código de salida ${data.exit_code}`
				+ (data.timed_out ? ' · tiempo agotado' : ''));
			const pre = this.#el('pre', null, data.output || 'La herramienta terminó sin generar salida.');
			output.replaceChildren(header, pre);
		}
		catch (error) {
			output.replaceChildren(this.#el('div', 'vmw-modal-error vmw-diagnostic-error',
				error.message || 'No se pudo ejecutar la prueba.'));
		}
		finally {
			for (const item of grid.querySelectorAll('button')) item.disabled = false;
			button.textContent = original;
		}
	}

	#makeDatastoreActions(data) {
		const section = this.#makeSection('Acciones');
		section.classList.add('vmw-actions');
		const grid = this.#el('div', 'vmw-action-grid');
		for (const source of data.sources || []) {
			if (!source.url) continue;
			const link = this.#el('a', 'vmw-action-button', `Últimos datos · ${source.name}`);
			link.href = source.url;
			grid.append(link);
		}
		grid.append(this.#copyButton('Copiar nombre', String(data.name || '')));
		section.append(grid);
		return section;
	}

	#severityText(value) {
		return ['No clasificado', 'Información', 'Advertencia', 'Promedio', 'Alta', 'Desastre'][Number(value)]
			?? 'Desconocida';
	}

	// -----------------------------------------------------------------------------------------------------------
	// Gráficas
	// -----------------------------------------------------------------------------------------------------------

	/**
	 * @param {Object}  options
	 * @param {string}  options.title
	 * @param {string}  options.period
	 * @param {string}  options.units       Unidades Zabbix del ítem: %, B, Bps, bps, ms, !ms, iops…
	 * @param {boolean} options.percent     Escala fija 0–100.
	 * @param {Object}  options.thresholds  {warning, critical} en la misma unidad.
	 * @param {Array}   options.series      [{label, history: [{clock, value}], className}]
	 */
	#makeChartCard(options) {
		const section = this.#el('div', 'vmw-chart-section');
		const head = this.#el('div', 'vmw-chart-title');
		head.append(this.#el('strong', null, options.title), this.#el('span', null, options.period ?? ''));
		section.append(head);

		const series = (options.series || [])
			.map(item => ({...item, history: (item.history || [])
				.map(point => ({clock: Number(point.clock), value: Number(point.value)}))
				.filter(point => Number.isFinite(point.clock) && Number.isFinite(point.value))
				.sort((a, b) => a.clock - b.clock)}))
			.filter(item => item.history.length >= 2);

		if (!series.length) {
			section.append(this.#el('div', 'vmw-chart-empty', t('No history in the selected period.')));
			return section;
		}

		const canvas = this.#el('div', 'vmw-chart-canvas');
		if (options.height) canvas.style.height = `${options.height}px`;
		section.append(canvas, this.#makeLegend(series, options.units));

		let lastWidth = 0;
		const draw = () => {
			const width = Math.floor(canvas.clientWidth);
			if (width < 50 || width === lastWidth) return;
			lastWidth = width;
			this.#drawChart(canvas, series, {...options, width, height: canvas.clientHeight || 200});
		};

		if (typeof ResizeObserver !== 'undefined') {
			const observer = new ResizeObserver(() => draw());
			observer.observe(canvas);
			this.#chartObservers.add(observer);
		}
		requestAnimationFrame(draw);

		return section;
	}

	#makeLegend(series, units) {
		const table = this.#el('table', 'vmw-chart-legend');
		const head = table.createTHead().insertRow();
		for (const text of ['', 'últ.', 'mín.', 'prom.', 'máx.']) head.append(this.#el('th', null, text));
		const body = table.createTBody();
		for (const item of series) {
			const values = item.history.map(point => point.value);
			const avg = values.reduce((sum, value) => sum + value, 0) / values.length;
			const row = body.insertRow();
			const name = row.insertCell();
			name.className = item.className || 'vmw-series-1';
			name.append(this.#el('i'), document.createTextNode(item.label));
			for (const value of [values[values.length - 1], Math.min(...values), avg, Math.max(...values)]) {
				row.insertCell().textContent = this.#formatValue(value, units);
			}
		}
		return table;
	}

	#drawChart(canvas, series, options) {
		const ns = 'http://www.w3.org/2000/svg';
		const make = (tag, attributes = {}, className = null) => {
			const element = document.createElementNS(ns, tag);
			for (const [name, value] of Object.entries(attributes)) element.setAttribute(name, value);
			if (className) element.setAttribute('class', className);
			return element;
		};

		const {width, height, units, percent, thresholds} = options;
		const all = series.flatMap(item => item.history);
		const minClock = Math.min(...all.map(point => point.clock));
		const maxClock = Math.max(...all.map(point => point.clock));
		const span = Math.max(1, maxClock - minClock);

		let yMax;
		if (percent) {
			yMax = 100;
		}
		else {
			const dataMax = Math.max(0, ...all.map(point => point.value));
			yMax = dataMax > 0 ? dataMax * 1.08 : 1;
		}
		const tickCount = Math.max(3, Math.min(5, Math.floor(height / 45)));
		let yTicks;
		if (percent) {
			yTicks = [0, 25, 50, 75, 100];
		}
		else if (/^!?(B|Bps)$/.test(String(units || ''))) {
			// Unidades binarias: marcas redondas en KB/MB/GB (base 1024), igual que convertUnits() de Zabbix.
			const scale = 1024 ** Math.max(0, Math.floor(Math.log(yMax) / Math.log(1024)));
			yTicks = this.#niceTicks(yMax / scale, tickCount).map(value => value * scale);
		}
		else {
			yTicks = this.#niceTicks(yMax, tickCount);
		}
		yMax = yTicks[yTicks.length - 1];

		const tickLabels = yTicks.map(value => percent ? `${value}%` : this.#formatValue(value, units, true));
		const left = Math.ceil(Math.max(...tickLabels.map(label => label.length)) * 5.6) + 10;
		const right = percent && thresholds ? 30 : 10;
		const top = 8;
		const bottom = 22;
		const plotWidth = Math.max(10, width - left - right);
		const plotHeight = Math.max(10, height - top - bottom);
		const xOf = clock => left + (clock - minClock) / span * plotWidth;
		const yOf = value => top + plotHeight - Math.max(0, Math.min(yMax, value)) / yMax * plotHeight;

		const svg = make('svg', {
			viewBox: `0 0 ${width} ${height}`, width, height, role: 'img',
			'aria-label': `${options.title}: ${series.map(item => item.label).join(', ')}`
		}, 'vmw-history-chart');

		// Rejilla y eje Y
		yTicks.forEach((value, index) => {
			const y = Math.round(yOf(value)) + .5;
			svg.append(make('line', {x1: left, x2: left + plotWidth, y1: y, y2: y}, index === 0 ? 'axis' : 'grid'));
			const label = make('text', {x: left - 6, y: y + 3.5, 'text-anchor': 'end'});
			label.textContent = tickLabels[index];
			svg.append(label);
		});

		// Eje X con marcas de tiempo alineadas
		for (const {clock, text} of this.#timeTicks(minClock, maxClock, plotWidth)) {
			const x = Math.round(xOf(clock)) + .5;
			svg.append(make('line', {x1: x, x2: x, y1: top, y2: top + plotHeight}, 'grid'));
			const label = make('text', {x, y: height - 6, 'text-anchor': 'middle'});
			label.textContent = text;
			svg.append(label);
		}

		// Umbrales
		if (percent && thresholds) {
			for (const [value, kind] of [[thresholds.warning, 'warning'], [thresholds.critical, 'critical']]) {
				if (value === undefined || value === null || Number(value) <= 0 || Number(value) >= 100) continue;
				const y = Math.round(yOf(Number(value))) + .5;
				svg.append(make('line', {x1: left, x2: left + plotWidth, y1: y, y2: y}, `threshold ${kind}`));
				const label = make('text', {x: left + plotWidth + 4, y: y + 3}, `threshold-label ${kind}`);
				label.textContent = `${value}%`;
				svg.append(label);
			}
		}

		// Series
		const defs = make('defs');
		svg.append(defs);
		const clipId = `vmw-clip-${++this.#chartSequence}`;
		const clip = make('clipPath', {id: clipId});
		clip.append(make('rect', {x: left, y: top - 1, width: plotWidth, height: plotHeight + 2}));
		defs.append(clip);
		const plot = make('g', {'clip-path': `url(#${clipId})`});
		svg.append(plot);

		const prepared = series.map(item => {
			const buckets = this.#downsample(item.history, plotWidth, minClock, span);
			return {...item, buckets, segments: this.#segments(buckets)};
		});

		for (const item of prepared) {
			const seriesClass = item.className || 'vmw-series-1';
			const gradientId = `vmw-grad-${++this.#chartSequence}`;
			const gradient = make('linearGradient', {id: gradientId, x1: 0, y1: 0, x2: 0, y2: 1}, seriesClass);
			const stopTop = make('stop', {offset: '0%'});
			stopTop.style.stopColor = 'var(--vmw-series-color)';
			stopTop.style.stopOpacity = prepared.length > 1 ? '.10' : '.22';
			const stopBottom = make('stop', {offset: '100%'});
			stopBottom.style.stopColor = 'var(--vmw-series-color)';
			stopBottom.style.stopOpacity = '0';
			gradient.append(stopTop, stopBottom);
			defs.append(gradient);

			const group = make('g', {}, seriesClass);
			for (const segment of item.segments) {
				if (segment.some(point => point.min !== point.max)) {
					const upper = segment.map(point => `${xOf(point.clock).toFixed(1)},${yOf(point.max).toFixed(1)}`);
					const lower = segment.slice().reverse().map(point => `${xOf(point.clock).toFixed(1)},${yOf(point.min).toFixed(1)}`);
					group.append(make('polygon', {points: [...upper, ...lower].join(' ')}, 'series-range'));
				}
				const line = segment.map(point => `${xOf(point.clock).toFixed(1)},${yOf(point.value).toFixed(1)}`);
				if (segment.length > 1) {
					const baseline = yOf(0).toFixed(1);
					const area = make('polygon', {
						points: `${xOf(segment[0].clock).toFixed(1)},${baseline} ${line.join(' ')} `
							+ `${xOf(segment[segment.length - 1].clock).toFixed(1)},${baseline}`,
						fill: `url(#${gradientId})`
					}, 'area');
					group.append(area);
					group.append(make('polyline', {points: line.join(' ')}, 'series'));
				}
				else {
					group.append(make('circle', {cx: xOf(segment[0].clock), cy: yOf(segment[0].value), r: 1.8}, 'series'));
				}
			}
			plot.append(group);
		}

		canvas.replaceChildren(svg);
		this.#attachChartTooltip(canvas, svg, prepared, {left, top, plotWidth, plotHeight, minClock, span, xOf, yOf,
			units, width, make});
	}

	#attachChartTooltip(canvas, svg, series, geometry) {
		const {make, xOf, yOf} = geometry;
		const crosshair = make('line', {y1: geometry.top, y2: geometry.top + geometry.plotHeight}, 'vmw-chart-crosshair');
		svg.append(crosshair);
		const markers = series.map(item => {
			const marker = make('circle', {r: 3.5}, `vmw-chart-marker ${item.className || 'vmw-series-1'}`);
			svg.append(marker);
			return marker;
		});
		const tooltip = this.#el('div', 'vmw-chart-tooltip');
		canvas.append(tooltip);

		const nearest = (history, target) => {
			let low = 0;
			let high = history.length - 1;
			while (low < high) {
				const middle = (low + high) >> 1;
				if (history[middle].clock < target) low = middle + 1;
				else high = middle;
			}
			return low > 0 && Math.abs(history[low - 1].clock - target) < Math.abs(history[low].clock - target)
				? history[low - 1]
				: history[low];
		};

		const hide = () => canvas.classList.remove('tooltip-visible');
		svg.addEventListener('pointerleave', hide);
		svg.addEventListener('pointermove', event => {
			const rect = svg.getBoundingClientRect();
			if (!rect.width) return;
			const x = Math.max(geometry.left, Math.min(geometry.left + geometry.plotWidth,
				(event.clientX - rect.left) * (geometry.width / rect.width)));
			const target = geometry.minClock + (x - geometry.left) / geometry.plotWidth * geometry.span;

			const points = series.map((item, index) => {
				const point = nearest(item.history, target);
				markers[index].setAttribute('cx', xOf(point.clock));
				markers[index].setAttribute('cy', yOf(point.value));
				return {item, point};
			});
			const snapX = xOf(points[0].point.clock);
			crosshair.setAttribute('x1', snapX);
			crosshair.setAttribute('x2', snapX);

			tooltip.replaceChildren(this.#el('strong', null, this.#formatDateTime(points[0].point.clock)));
			for (const {item, point} of points) {
				const row = this.#el('span', item.className || 'vmw-series-1');
				const name = this.#el('span');
				name.append(this.#el('i'), document.createTextNode(item.label));
				row.append(name, this.#el('b', null, this.#formatValue(point.value, geometry.units)));
				tooltip.append(row);
			}

			canvas.classList.add('tooltip-visible');
			const localX = event.clientX - canvas.getBoundingClientRect().left;
			const tooltipWidth = tooltip.offsetWidth || 170;
			const leftPosition = localX + 14 + tooltipWidth > canvas.clientWidth ? localX - tooltipWidth - 14 : localX + 14;
			tooltip.style.left = `${Math.max(0, leftPosition)}px`;
			tooltip.style.top = `${Math.max(0, event.clientY - canvas.getBoundingClientRect().top - tooltip.offsetHeight - 8)}px`;
		});
	}

	/** Agrupa los puntos por píxel (prom./mín./máx.) cuando hay más puntos que ancho disponible. */
	#downsample(history, plotWidth, minClock, span) {
		const target = Math.max(2, Math.floor(plotWidth));
		if (history.length <= target) {
			return history.map(point => ({clock: point.clock, value: point.value, min: point.value, max: point.value}));
		}
		const buckets = new Map();
		for (const point of history) {
			const index = Math.min(target - 1, Math.floor((point.clock - minClock) / span * target));
			const bucket = buckets.get(index);
			if (!bucket) {
				buckets.set(index, {clockSum: point.clock, sum: point.value, count: 1, min: point.value, max: point.value});
			}
			else {
				bucket.clockSum += point.clock;
				bucket.sum += point.value;
				bucket.count++;
				bucket.min = Math.min(bucket.min, point.value);
				bucket.max = Math.max(bucket.max, point.value);
			}
		}
		return [...buckets.entries()].sort((a, b) => a[0] - b[0]).map(([, bucket]) => ({
			clock: bucket.clockSum / bucket.count, value: bucket.sum / bucket.count, min: bucket.min, max: bucket.max
		}));
	}

	/** Divide la serie donde faltan datos para no dibujar líneas engañosas sobre los huecos. */
	#segments(points) {
		if (points.length < 3) return [points];
		const deltas = [];
		for (let i = 1; i < points.length; i++) deltas.push(points[i].clock - points[i - 1].clock);
		const sorted = deltas.slice().sort((a, b) => a - b);
		const median = sorted[Math.floor(sorted.length / 2)] || 1;
		const limit = Math.max(median * 3, median + 120);
		const segments = [[points[0]]];
		for (let i = 1; i < points.length; i++) {
			if (points[i].clock - points[i - 1].clock > limit) segments.push([]);
			segments[segments.length - 1].push(points[i]);
		}
		return segments;
	}

	#niceTicks(max, count) {
		const rawStep = max / count;
		const magnitude = 10 ** Math.floor(Math.log10(rawStep));
		const residual = rawStep / magnitude;
		const step = (residual > 5 ? 10 : residual > 2 ? 5 : residual > 1 ? 2 : 1) * magnitude;
		const ticks = [];
		for (let value = 0; value <= max + step * .001; value += step) ticks.push(+value.toPrecision(12));
		if (ticks[ticks.length - 1] < max) ticks.push(+(ticks[ticks.length - 1] + step).toPrecision(12));
		return ticks;
	}

	#timeTicks(minClock, maxClock, plotWidth) {
		const span = Math.max(1, maxClock - minClock);
		const maxTicks = Math.max(2, Math.floor(plotWidth / 90));
		const steps = [300, 600, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400, 172800, 345600, 604800, 1209600];
		const step = steps.find(candidate => span / candidate <= maxTicks) ?? steps[steps.length - 1];
		const offset = new Date(minClock * 1000).getTimezoneOffset() * 60;
		const ticks = [];
		let clock = Math.ceil((minClock - offset) / step) * step + offset;
		if (step >= 86400) clock = Math.ceil((minClock - offset) / 86400) * 86400 + offset;

		const withDate = span > 86400;
		for (; clock <= maxClock; clock += step) {
			if (clock < minClock) continue;
			// Formato de las gráficas SVG de Zabbix: "HH:MM", "MM-DD HH:MM" o "MM-DD".
			const date = new Date(clock * 1000);
			const pad = value => String(value).padStart(2, '0');
			const hhmm = `${pad(date.getHours())}:${pad(date.getMinutes())}`;
			const mmdd = `${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
			ticks.push({clock, text: step >= 86400 ? mmdd : (withDate ? `${mmdd} ${hhmm}` : hhmm)});
		}
		return ticks;
	}

	/** Mismo formato que usa Zabbix en Últimos datos y Problemas: AAAA-MM-DD HH:MM:SS. */
	#formatDateTime(clock) {
		const date = new Date(clock * 1000);
		const pad = value => String(value).padStart(2, '0');
		return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} `
			+ `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
	}

	/** Conversión de unidades con las mismas reglas que convertUnits() de Zabbix (B y Bps en base 1024). */
	#formatValue(value, units = '', compact = false) {
		if (value === null || value === undefined || Number.isNaN(Number(value))) return '—';
		const number = Number(value);
		const raw = String(units || '');
		const unit = raw.replace(/^!/, '');
		const decimals = abs => abs === 0 ? 0 : (abs < 10 ? 2 : (abs < 100 ? 1 : 0));

		if (unit === '%') {
			return `${number.toFixed(compact ? (Math.abs(number) < 10 ? 1 : 0) : (Math.abs(number) < 10 ? 2 : 1))} %`;
		}

		if (unit === 'ms' || unit === 's') {
			const ms = unit === 's' ? number * 1000 : number;
			if (!raw.startsWith('!') && Math.abs(ms) >= 1000) return `${(ms / 1000).toFixed(2)} s`;
			return `${ms.toFixed(compact ? 0 : decimals(Math.abs(ms)))} ms`;
		}

		const binary = unit === 'B' || unit === 'Bps';
		const base = binary ? 1024 : 1000;
		const prefixes = ['', 'K', 'M', 'G', 'T', 'P'];
		let scaled = Math.abs(number);
		let index = 0;
		if (!raw.startsWith('!')) {
			while (scaled >= base && index < prefixes.length - 1) {
				scaled /= base;
				index++;
			}
		}
		const signed = number < 0 ? -scaled : scaled;
		const text = signed.toFixed(compact ? (scaled < 10 && scaled !== 0 && Math.round(scaled) !== scaled ? 1 : 0) : decimals(scaled));
		const suffix = unit === 'iops'
			? `${prefixes[index]} IOPS`
			: (unit ? ` ${prefixes[index]}${unit}` : (prefixes[index] ? ` ${prefixes[index]}` : ''));
		return `${text}${suffix}`;
	}
}
