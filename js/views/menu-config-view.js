// ==========================================================================
// SCREEN 3: MENU & PLATOS CONFIG VIEW
// ==========================================================================

function renderMenuConfig() {
    const priceAlm = document.getElementById('price-almuerzo-input');
    const priceSec = document.getElementById('price-segundo-input');
    const priceSopa = document.getElementById('price-sopa-input');
    if (priceAlm) priceAlm.value = state.prices.almuerzo;
    if (priceSec) priceSec.value = state.prices.segundo;
    if (priceSopa) priceSopa.value = state.prices.sopa;

    renderMenusTable();
    renderSecondsTable();
    renderSopasTable();
    renderPlatosExtrasTable();
    renderExtrasTable();
    renderSalsasTable();

    const activeSubtab = window.activeMenuConfigSubtab || 'menu-dia';
    if (typeof window.switchMenuConfigSubtab === 'function') {
        window.switchMenuConfigSubtab(activeSubtab);
    }
}

function getCatalogFilters() {
    return window.catalogFilters || { search: '', type: 'all', status: 'all', stock: 'all' };
}

function matchesCatalogFilter(item, type) {
    const filters = getCatalogFilters();
    const search = (filters.search || '').toLowerCase();
    const active = Number(item.active) !== 0;
    const stock = Number(item.stock || 0);
    return (!search || String(item.name || '').toLowerCase().includes(search)) &&
        (filters.type === 'all' || filters.type === type) &&
        (filters.status === 'all' || (filters.status === 'active' ? active : !active)) &&
        (filters.stock === 'all' || (filters.stock === 'available' ? stock > 0 : stock <= 0));
}

window.setCatalogFilter = function(field, value) {
    window.catalogFilters = { ...getCatalogFilters(), [field]: value || (field === 'search' ? '' : 'all') };
    if (field === 'type' && value && value !== 'all' && typeof window.switchMenuConfigSubtab === 'function') {
        const subtabByType = { segundo: 'a-la-carta', sopa: 'sopas', plato_extra: 'extras', extra: 'gaseosas', salsa: 'salsas' };
        if (subtabByType[value]) window.switchMenuConfigSubtab(subtabByType[value]);
    }
    renderMenuConfig();
};

window.clearCatalogFilters = function() {
    window.catalogFilters = { search: '', type: 'all', status: 'all', stock: 'all' };
    const toolbar = document.getElementById('catalog-filter-toolbar');
    if (toolbar) toolbar.querySelectorAll('input, select').forEach(input => { input.value = input.tagName === 'SELECT' ? 'all' : ''; });
    renderMenuConfig();
};

function renderMenusTable() {
    const tbody = document.getElementById('menus-crud-body');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!state.menus || state.menus.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-muted" style="text-align:center;">No hay menús creados.</td></tr>`;
        return;
    }

    state.menus.forEach(menu => {
        const tr = document.createElement('tr');
        const timeRange = (menu.start_time && menu.end_time) 
            ? `${menu.start_time.substring(0,5)} - ${menu.end_time.substring(0,5)}` 
            : 'Todo el día';
        const statusBadge = menu.active 
            ? '<span class="badge badge-success" style="font-size:10px;">Activo</span>' 
            : '<span class="badge badge-muted" style="font-size:10px;">Inactivo</span>';

        tr.innerHTML = `
            <td><strong>${escapeHtml(menu.name)}</strong></td>
            <td style="font-size:11px; color: var(--text-muted);">${timeRange}</td>
            <td>${statusBadge}</td>
            <td style="text-align:right;">
                <button class="btn-table-action edit" onclick="openMenuProductsModal('${menu.id}')" title="Gestionar Productos">
                    <i class="fa-solid fa-boxes-stacked"></i>
                </button>
                <button class="btn-table-action edit" onclick="toggleMenuActive('${menu.id}', ${!menu.active})" title="${menu.active ? 'Desactivar' : 'Activar'}">
                    <i class="fa-solid ${menu.active ? 'fa-eye-slash' : 'fa-eye'}"></i>
                </button>
                <button class="btn-table-action delete" onclick="deleteMenu('${menu.id}')" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function renderSecondsTable() {
    const tbody = document.getElementById('seconds-crud-body');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (state.seconds.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-muted" style="text-align:center;">No hay segundos registrados.</td></tr>`;
        return;
    }

    state.seconds.filter(sec => matchesCatalogFilter(sec, 'segundo')).forEach(sec => {
        const tr = document.createElement('tr');
        const isEditing = editingSegundoId === sec.id;
        const isActive = Number(sec.active) !== 0;
        const statusBadge = isActive
            ? '<span class="badge badge-success" style="font-size:10px;">Activo</span>'
            : '<span class="badge badge-danger" style="font-size:10px;">Inactivo</span>';
        const hasSalsa = sec.accepts_salsa;

        if (isEditing) {
            tr.innerHTML = `
                <td><input type="text" class="td-edit-input" id="edit-sec-name-${sec.id}" value="${escapeHtml(sec.name)}"></td>
                <td style="text-align:center;">${statusBadge}</td>
                <td style="text-align:center;">
                    <input type="checkbox" class="form-check" id="edit-sec-salsa-${sec.id}" ${hasSalsa ? 'checked' : ''}>
                </td>
                <td style="text-align:right;">
                    <button class="btn btn-primary btn-sm" onclick="saveSegundoInline('${sec.id}')" title="Guardar"><i class="fa-solid fa-check"></i></button>
                    <button class="btn btn-outline btn-sm" onclick="cancelSegundoInline()" title="Cancelar"><i class="fa-solid fa-xmark"></i></button>
                </td>
            `;
        } else {
            tr.innerHTML = `
                <td><strong>${escapeHtml(sec.name)}</strong></td>
                <td style="text-align:center;">${statusBadge}</td>
                <td style="text-align:center;">
                    <input type="checkbox" class="form-check" ${hasSalsa ? 'checked' : ''} onchange="toggleAcceptsSalsa('segundo', '${sec.id}', this.checked)" title="${hasSalsa ? 'Quitar salsa' : 'Permitir salsa'}">
                </td>
                <td style="text-align:right;">
                    <button class="btn-table-action edit" onclick="startEditSegundo('${sec.id}')" title="Editar"><i class="fa-solid fa-pen-to-square"></i></button>
                    <button class="btn-table-action edit" onclick="toggleSegundoActive('${sec.id}', ${!isActive})" title="${isActive ? 'Desactivar' : 'Activar'}">
                        <i class="fa-solid ${isActive ? 'fa-eye-slash' : 'fa-eye'}"></i>
                    </button>
                    <button class="btn-table-action delete" onclick="deleteSegundo('${sec.id}')" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </td>
            `;
        }
        tbody.appendChild(tr);
    });
}

function renderSopasTable() {
    const tbody = document.getElementById('sopas-crud-body');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (state.sopas.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-muted" style="text-align:center;">No hay sopas registradas.</td></tr>`;
        return;
    }

    state.sopas.filter(sopa => matchesCatalogFilter(sopa, 'sopa')).forEach(sopa => {
        const tr = document.createElement('tr');
        const isEditing = editingSopaId === sopa.id;
        const isActive = Number(sopa.active) !== 0;
        const statusBadge = isActive
            ? '<span class="badge badge-success" style="font-size:10px;">Activo</span>'
            : '<span class="badge badge-danger" style="font-size:10px;">Inactivo</span>';
        const hasSalsa = sopa.accepts_salsa;

        if (isEditing) {
            tr.innerHTML = `
                <td><input type="text" class="td-edit-input" id="edit-sopa-name-${sopa.id}" value="${escapeHtml(sopa.name)}"></td>
                <td style="text-align:center;">${statusBadge}</td>
                <td style="text-align:center;">
                    <input type="checkbox" class="form-check" id="edit-sopa-salsa-${sopa.id}" ${hasSalsa ? 'checked' : ''}>
                </td>
                <td style="text-align:right;">
                    <button class="btn btn-primary btn-sm" onclick="saveSopaInline('${sopa.id}')" title="Guardar"><i class="fa-solid fa-check"></i></button>
                    <button class="btn btn-outline btn-sm" onclick="cancelSopaInline()" title="Cancelar"><i class="fa-solid fa-xmark"></i></button>
                </td>
            `;
        } else {
            tr.innerHTML = `
                <td><strong>${escapeHtml(sopa.name)}</strong></td>
                <td style="text-align:center;">${statusBadge}</td>
                <td style="text-align:center;">
                    <input type="checkbox" class="form-check" ${hasSalsa ? 'checked' : ''} onchange="toggleAcceptsSalsa('sopa', '${sopa.id}', this.checked)" title="${hasSalsa ? 'Quitar salsa' : 'Permitir salsa'}">
                </td>
                <td style="text-align:right;">
                    <button class="btn-table-action edit" onclick="startEditSopa('${sopa.id}')" title="Editar"><i class="fa-solid fa-pen-to-square"></i></button>
                    <button class="btn-table-action edit" onclick="toggleSopaActive('${sopa.id}', ${!isActive})" title="${isActive ? 'Desactivar' : 'Activar'}">
                        <i class="fa-solid ${isActive ? 'fa-eye-slash' : 'fa-eye'}"></i>
                    </button>
                    <button class="btn-table-action delete" onclick="deleteSopa('${sopa.id}')" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </td>
            `;
        }
        tbody.appendChild(tr);
    });
}

function renderPlatosExtrasTable() {
    const tbody = document.getElementById('platos-extras-crud-body');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (state.platosExtras.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-muted" style="text-align:center;">No hay platos extras registrados.</td></tr>`;
        return;
    }

    state.platosExtras.filter(plato => matchesCatalogFilter(plato, 'plato_extra')).forEach(plato => {
        const tr = document.createElement('tr');
        const isEditing = editingPlatoId === plato.id;
        const hasSalsa = plato.accepts_salsa;

        if (isEditing) {
            tr.innerHTML = `
                <td><input type="text" class="td-edit-input" id="edit-plato-name-${plato.id}" value="${escapeHtml(plato.name)}"></td>
                <td><input type="number" step="0.5" class="td-edit-input" id="edit-plato-price-${plato.id}" value="${plato.price}"></td>
                <td style="text-align:center;">
                    <input type="checkbox" class="form-check" id="edit-plato-salsa-${plato.id}" ${hasSalsa ? 'checked' : ''}>
                </td>
                <td style="text-align:right;">
                    <button class="btn btn-primary btn-sm" onclick="savePlatoInline('${plato.id}')" title="Guardar"><i class="fa-solid fa-check"></i></button>
                    <button class="btn btn-outline btn-sm" onclick="cancelPlatoInline()" title="Cancelar"><i class="fa-solid fa-xmark"></i></button>
                </td>
            `;
        } else {
            tr.innerHTML = `
                <td><strong>${escapeHtml(plato.name)}</strong></td>
                <td>Bs ${parseFloat(plato.price).toFixed(2)}</td>
                <td style="text-align:center;">
                    <input type="checkbox" class="form-check" ${hasSalsa ? 'checked' : ''} onchange="toggleAcceptsSalsa('plato_extra', '${plato.id}', this.checked)" title="${hasSalsa ? 'Quitar salsa' : 'Permitir salsa'}">
                </td>
                <td style="text-align:right;">
                    <button class="btn-table-action edit" onclick="startEditPlato('${plato.id}')" title="Editar"><i class="fa-solid fa-pen-to-square"></i></button>
                    <button class="btn-table-action delete" onclick="deletePlatoExtra('${plato.id}')" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </td>
            `;
        }
        tbody.appendChild(tr);
    });
}

function renderExtrasTable() {
    const tbody = document.getElementById('extras-crud-body');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (state.extras.length === 0) {
        tbody.innerHTML = `<tr><td colspan="3" class="text-muted" style="text-align:center;">No hay gaseosas registradas.</td></tr>`;
        return;
    }

    state.extras.filter(ext => matchesCatalogFilter(ext, 'extra')).forEach(ext => {
        const tr = document.createElement('tr');
        const isEditing = editingExtraId === ext.id;

        if (isEditing) {
            tr.innerHTML = `
                <td><input type="text" class="td-edit-input" id="edit-extra-name-${ext.id}" value="${escapeHtml(ext.name)}"></td>
                <td><input type="number" step="0.5" class="td-edit-input" id="edit-extra-price-${ext.id}" value="${ext.price}"></td>
                <td style="text-align:right;">
                    <button class="btn btn-primary btn-sm" onclick="saveExtraInline('${ext.id}')" title="Guardar"><i class="fa-solid fa-check"></i></button>
                    <button class="btn btn-outline btn-sm" onclick="cancelExtraInline()" title="Cancelar"><i class="fa-solid fa-xmark"></i></button>
                </td>
            `;
        } else {
            tr.innerHTML = `
                <td><strong>${escapeHtml(ext.name)}</strong></td>
                <td>Bs ${parseFloat(ext.price).toFixed(2)}</td>
                <td style="text-align:right;">
                    <button class="btn-table-action edit" onclick="startEditExtra('${ext.id}')" title="Editar"><i class="fa-solid fa-pen-to-square"></i></button>
                    <button class="btn-table-action delete" onclick="deleteExtra('${ext.id}')" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </td>
            `;
        }
        tbody.appendChild(tr);
    });
}

function renderSalsasTable() {
    const tbody = document.getElementById('salsas-crud-body');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!state.salsas || state.salsas.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-muted" style="text-align:center;">No hay salsas registradas.</td></tr>`;
        return;
    }

    state.salsas.filter(salsa => matchesCatalogFilter(salsa, 'salsa')).forEach(salsa => {
        const tr = document.createElement('tr');
        const isEditing = editingSalsaId === salsa.id;
        const isActive = Number(salsa.active) !== 0;
        const statusBadge = isActive
            ? '<span class="badge badge-success" style="font-size:10px;">Activa</span>'
            : '<span class="badge badge-danger" style="font-size:10px;">Inactiva</span>';

        if (isEditing) {
            tr.innerHTML = `
                <td><input type="text" class="td-edit-input" id="edit-salsa-name-${salsa.id}" value="${escapeHtml(salsa.name)}"></td>
                <td><input type="number" step="0.5" class="td-edit-input" id="edit-salsa-price-${salsa.id}" value="${salsa.price}"></td>
                <td style="text-align:center;">${statusBadge}</td>
                <td style="text-align:right;">
                    <button class="btn btn-primary btn-sm" onclick="saveSalsaInline('${salsa.id}')" title="Guardar"><i class="fa-solid fa-check"></i></button>
                    <button class="btn btn-outline btn-sm" onclick="cancelSalsaInline()" title="Cancelar"><i class="fa-solid fa-xmark"></i></button>
                </td>
            `;
        } else {
            tr.innerHTML = `
                <td><strong>${escapeHtml(salsa.name)}</strong></td>
                <td>Bs ${parseFloat(salsa.price).toFixed(2)}</td>
                <td style="text-align:center;">${statusBadge}</td>
                <td style="text-align:right;">
                    <button class="btn-table-action edit" onclick="startEditSalsa('${salsa.id}')" title="Editar"><i class="fa-solid fa-pen-to-square"></i></button>
                    <button class="btn-table-action edit" onclick="toggleSalsaActive('${salsa.id}', ${!isActive})" title="${isActive ? 'Desactivar' : 'Activar'}">
                        <i class="fa-solid ${isActive ? 'fa-eye-slash' : 'fa-eye'}"></i>
                    </button>
                    <button class="btn-table-action delete" onclick="deleteSalsa('${salsa.id}')" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </td>
            `;
        }
        tbody.appendChild(tr);
    });
}
