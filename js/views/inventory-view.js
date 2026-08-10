// ==========================================================================
// SCREEN 4: INVENTORY STOCK VIEW
// ==========================================================================

function renderInventoryTab() {
    renderSecondsStockTable();
    renderSopasStockTable();
    renderPlatosExtrasStockTable();
    renderExtrasStockTable();
}

function renderSecondsStockTable() {
    const tbody = document.getElementById('seconds-stock-table-body');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (state.seconds.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-muted" style="text-align:center;">No hay segundos en el menú.</td></tr>`;
    } else {
        state.seconds.forEach(sec => {
            const currentStock = getAvailableSegundoStock(sec.id);
            const isActive = Number(sec.active) !== 0;
            const tr = document.createElement('tr');
            if (!isActive) {
                tr.style.opacity = '0.5';
            } else if (currentStock <= 5) {
                tr.className = 'low-stock-row';
            }
            const statusTag = isActive ? '' : ' <span class="badge badge-danger" style="font-size:9px;">Inactivo</span>';
            tr.innerHTML = `
                <td><strong>${escapeHtml(sec.name)}</strong>${statusTag}</td>
                <td>
                    <input type="number" class="td-edit-input" style="width: 100px;" value="${currentStock}" id="stock-val-sec-${sec.id}" ${!isActive ? 'disabled' : ''}>
                </td>
                <td style="text-align:right;">
                    <button class="btn btn-outline btn-sm" onclick="saveStockInline('${sec.id}', 'segundo')" title="Guardar Stock" ${!isActive ? 'disabled' : ''}>
                        <i class="fa-solid fa-save"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }
}

function renderSopasStockTable() {
    const tbody = document.getElementById('sopas-stock-table-body');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (state.sopas.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-muted" style="text-align:center;">No hay sopas registradas.</td></tr>`;
    } else {
        state.sopas.forEach(sopa => {
            const currentStock = getAvailableSopaStock(sopa.id);
            const isActive = Number(sopa.active) !== 0;
            const tr = document.createElement('tr');
            if (!isActive) {
                tr.style.opacity = '0.5';
            } else if (currentStock <= 5) {
                tr.className = 'low-stock-row';
            }
            const statusTag = isActive ? '' : ' <span class="badge badge-danger" style="font-size:9px;">Inactivo</span>';
            tr.innerHTML = `
                <td><strong>${escapeHtml(sopa.name)}</strong>${statusTag}</td>
                <td style="text-align:center;">${isActive ? '<span class="badge badge-success" style="font-size:9px;">Activo</span>' : '<span class="badge badge-danger" style="font-size:9px;">Inactivo</span>'}</td>
                <td>
                    <input type="number" class="td-edit-input" style="width: 100px;" value="${currentStock}" id="stock-val-sopa-${sopa.id}" ${!isActive ? 'disabled' : ''}>
                </td>
                <td style="text-align:right;">
                    <button class="btn btn-outline btn-sm" onclick="saveStockInline('${sopa.id}', 'sopa')" title="Guardar Stock" ${!isActive ? 'disabled' : ''}>
                        <i class="fa-solid fa-save"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }
}

function renderPlatosExtrasStockTable() {
    const tbody = document.getElementById('platos-extras-stock-table-body');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (state.platosExtras.length === 0) {
        tbody.innerHTML = `<tr><td colspan="3" class="text-muted" style="text-align:center;">No hay platos extras registrados.</td></tr>`;
    } else {
        state.platosExtras.forEach(plato => {
            const currentStock = getAvailablePlatoExtraStock(plato.id);
            const tr = document.createElement('tr');
            if (currentStock <= 5) {
                tr.className = 'low-stock-row';
            }
            tr.innerHTML = `
                <td><strong>${escapeHtml(plato.name)}</strong></td>
                <td>
                    <input type="number" class="td-edit-input" style="width: 100px;" value="${currentStock}" id="stock-val-pe-${plato.id}">
                </td>
                <td style="text-align:right;">
                    <button class="btn btn-outline btn-sm" onclick="saveStockInline('${plato.id}', 'plato_extra')" title="Guardar Stock">
                        <i class="fa-solid fa-save"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }
}

function renderExtrasStockTable() {
    const tbody = document.getElementById('extras-stock-table-body');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (state.extras.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-muted" style="text-align:center;">No hay gaseosas registradas.</td></tr>`;
    } else {
        state.extras.forEach(ext => {
            const currentStock = getAvailableExtraStock(ext.id);
            const tr = document.createElement('tr');
            if (currentStock <= 5) {
                tr.className = 'low-stock-row';
            }
            tr.innerHTML = `
                <td><strong>${escapeHtml(ext.name)}</strong></td>
                <td>
                    <input type="number" class="td-edit-input" style="width: 80px;" value="${currentStock}" id="stock-val-ext-${ext.id}" title="Stock actual (editable)">
                </td>
                <td>
                    <input type="number" class="td-edit-input" style="width: 80px;" placeholder="+ Sumar" id="stock-add-ext-${ext.id}" title="Cantidad a sumar">
                </td>
                <td style="text-align:right;">
                    <button class="btn btn-outline btn-sm" onclick="saveStockInline('${ext.id}', 'extra')" title="Guardar Stock">
                        <i class="fa-solid fa-save"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }
}
