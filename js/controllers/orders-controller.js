// ==========================================================================
// SCREEN 2: ORDERS CONTROLLER
// ==========================================================================

window.setOrderServiceState = async function(orderId, newState) {
    try {
        const data = await AppApi.request('update_order_state', { id: orderId, state: newState });
        if (data.status === 'success') {
            const order = state.activeOrders.find(o => o.id === orderId);
            if (typeof window.updateActiveOrder === 'function') {
                window.updateActiveOrder(orderId, { serviceState: newState });
            }
            const stateLabels = {
                'esperando_sopa': 'Sopa',
                'esperando_segundo': 'Segundo',
                'comiendo': 'Servido',
                'esperando_cuenta': 'Cuenta'
            };
            const customerName = order ? order.customer : 'Cliente';
            const stateLabel = stateLabels[newState] || newState;
            Notifications.notify(`📋 ${customerName}: ${stateLabel}`, 'info');
        }
    } catch (e) {
        showToast('Error al cambiar el estado del pedido.', 'error');
    }
};

window.completeActiveOrder = async function(orderId, chosenPaymentMethod = 'efectivo') {
    let data;
    try {
        data = await AppApi.request('complete_order', { id: orderId, paymentMethod: chosenPaymentMethod, soldAt: new Date().toISOString() });
    } catch (e) {
        console.error('[ORDERS] Error al completar cobro:', e);
        showToast(e.message || 'Error al completar el cobro.', 'error');
        return;
    }

    if (data.status === 'success') {
        const activeOrder = window.findLatestOrderSnapshot(orderId);
        const completedOrder = {
            ...(activeOrder || {}),
            id: orderId,
            paymentMethod: chosenPaymentMethod,
            paid: true,
            soldAt: new Date().toISOString(),
            status: 'completado'
        };
        try {
            if (typeof window.removeActiveOrder === 'function') {
                window.removeActiveOrder(orderId);
            }
            if (typeof window.prependSaleHistory === 'function') {
                window.prependSaleHistory(completedOrder);
            }
            const customerName = activeOrder ? activeOrder.customer : 'Cliente';
            const payLabel = (typeof chosenPaymentMethod === 'object' && chosenPaymentMethod !== null) ? 'Mixto' : chosenPaymentMethod.toUpperCase();
            Notifications.notify(`💰 ${customerName}: Cobrado ${payLabel}`, 'success');
        } catch (e) {
            console.error('[ORDERS] El cobro se completó, pero falló la actualización visual:', e);
            showToast('Cobro registrado. No se pudo actualizar una parte de la vista.', 'warning');
        }
    }
};

const _cancellingOrders = new Set();
let cancelModalOrder = null;

window.cancelActiveOrder = async function(orderId) {
    if (_cancellingOrders.has(orderId)) return;
    const order = state.activeOrders.find(o => o.id === orderId);
    if (!order) return;
    cancelModalOrder = order;
    const orderIdInput = document.getElementById('cancel-order-id');
    const summary = document.getElementById('cancel-order-summary');
    const reason = document.getElementById('cancel-order-reason');
    if (orderIdInput) orderIdInput.value = orderId;
    if (summary) summary.textContent = `${order.customer || 'Cliente'} · Total ${formatCurrency(order.total || 0)}`;
    if (reason) reason.value = '';
    renderCancelOrderItems();
    openModal('modal-cancel-order');
};

function renderCancelOrderItems() {
    const container = document.getElementById('cancel-order-items');
    if (!container || !cancelModalOrder) return;
    const items = cancelModalOrder.items || [];
    container.innerHTML = `
        <label class="cancel-order-select-all"><input type="checkbox" id="cancel-order-select-all" checked> Seleccionar todos</label>
        ${items.map((item, index) => {
            const quantity = Number(item.qty || item.quantity || 1);
            const details = [item.sopaName, item.segundoName].filter(Boolean).join(' / ');
            const serviceLabel = item.serviceType === 'llevar' ? 'Para llevar' : 'Servirse';
            const serviceClass = item.serviceType === 'llevar' ? 'llevar' : 'servirse';
            const salsaTotal = (item.salsas || []).reduce((total, salsa) => total + Number(salsa.salsaPrice || 0), 0);
            const accompanimentTotal = (item.accompaniments || []).reduce((total, acc) => total + Number(acc.accompanimentPrice || 0), 0);
            const unitPrice = Number(item.price || 0) + salsaTotal + accompanimentTotal;
            const lineTotal = unitPrice * quantity;
            return `<label class="cancel-order-item">
                <input type="checkbox" class="cancel-order-check" data-line-no="${index}" checked>
                <span class="cancel-order-item-name">
                    <strong>${quantity}x ${escapeHtml(item.name || 'Producto')}</strong>
                    <span class="cancel-order-item-meta">
                        <span class="item-detail-badge ${serviceClass}"><i class="fa-solid ${serviceClass === 'llevar' ? 'fa-bag-shopping' : 'fa-plate-wheat'}"></i> ${serviceLabel}</span>
                        <span>${formatCurrency(unitPrice)} c/u</span>
                        <span>${formatCurrency(lineTotal)}</span>
                    </span>
                    ${details ? `<small>${escapeHtml(details)}</small>` : ''}
                </span>
                <input type="number" class="form-input cancel-order-item-qty" data-line-no="${index}" min="1" max="${quantity}" step="1" value="${quantity}">
            </label>`;
        }).join('')}`;
    const selectAll = document.getElementById('cancel-order-select-all');
    const checks = Array.from(document.querySelectorAll('.cancel-order-check'));
    const syncSelectAll = () => {
        const selectedCount = checks.filter(check => check.checked).length;
        if (selectAll) {
            selectAll.checked = selectedCount === checks.length && checks.length > 0;
            selectAll.indeterminate = selectedCount > 0 && selectedCount < checks.length;
        }
    };
    selectAll?.addEventListener('change', event => {
        checks.forEach(check => { check.checked = event.target.checked; });
        syncSelectAll();
    });
    checks.forEach(check => check.addEventListener('change', syncSelectAll));
    syncSelectAll();
}

window.closeCancelOrderModal = function() {
    closeModal('modal-cancel-order');
    cancelModalOrder = null;
};

async function submitCancelOrder() {
    if (!cancelModalOrder || _cancellingOrders.has(cancelModalOrder.id)) return;
    const reason = document.getElementById('cancel-order-reason')?.value.trim() || '';
    if (!reason) return showToast('El motivo es obligatorio.', 'warning');
    const items = Array.from(document.querySelectorAll('.cancel-order-check:checked')).map(check => {
        const lineNo = Number(check.dataset.lineNo);
        const input = document.querySelector(`.cancel-order-item-qty[data-line-no="${lineNo}"]`);
        return { line_no: lineNo, quantity: Number(input?.value || 0) };
    }).filter(item => item.quantity > 0);
    if (!items.length) return showToast('Seleccione al menos un producto.', 'warning');
    if (!(await ConfirmDialog.show('La cancelación se aplicará a los productos seleccionados. ¿Continuar?', { title: 'Confirmar cancelación', confirmText: 'Cancelar productos', type: 'danger' }))) return;

    const order = cancelModalOrder;
    _cancellingOrders.add(order.id);
    try {
        const data = await AppApi.request('cancel_order', { id: order.id, reason, items });
        if (data.status === 'success') {
            if (data.full_cancelled) {
                window.removeActiveOrder?.(order.id);
                Notifications.notify(`❌ ${order.customer}: Pedido cancelado`, 'warning');
            } else {
                window.updateActiveOrder?.(order.id, { items: data.order.items, total: data.order.total });
                Notifications.notify(`❌ ${order.customer}: Productos cancelados`, 'warning');
            }
            window.closeCancelOrderModal();
        }
    } catch (e) {
        showToast(e.message || 'Error al cancelar los productos.', 'error');
    } finally {
        _cancellingOrders.delete(order.id);
    }
}

document.getElementById('btn-confirm-cancel-order')?.addEventListener('click', submitCancelOrder);

// ==========================================================================
// TABLES CONFIGURATION (Dynamic Floor Plan)
// ==========================================================================

let selectedTableIcon = 'fa-chair';
let editingTableId = null;

window.openTablesConfigModal = function() {
    openModal('modal-tables-config');
    editingTableId = null;
    selectedTableIcon = 'fa-chair';
    document.getElementById('table-config-id').value = '';
    document.getElementById('table-config-name').value = '';
    document.getElementById('btn-cancel-table-edit').style.display = 'none';
    document.getElementById('btn-save-table-config').innerHTML = '<i class="fa-solid fa-plus"></i> Agregar';
    updateIconSelectorUI();
    renderTablesConfigList();
};

window.closeTablesConfigModal = function() {
    closeModal('modal-tables-config');
};

window.handleTableIconSelect = function(icon) {
    selectedTableIcon = icon;
    updateIconSelectorUI();
};

function updateIconSelectorUI() {
    document.querySelectorAll('#table-icon-selector .icon-option').forEach(btn => {
        btn.classList.toggle('selected', btn.dataset.icon === selectedTableIcon);
    });
}

function renderTablesConfigList() {
    const list = document.getElementById('tables-config-list');
    if (!list) return;
    const tables = state.tables || [];

    if (tables.length === 0) {
        list.innerHTML = '<p style="text-align:center;color:var(--text-muted);padding:20px;">No hay mesas configuradas. Agrega una arriba.</p>';
        return;
    }

    list.innerHTML = tables.map((t, i) => `
        <div class="table-config-row" data-id="${t.id}">
            <span class="table-config-drag" title="Reordenar"><i class="fa-solid fa-grip-vertical"></i></span>
            <i class="fa-solid ${t.icon} table-config-icon"></i>
            <span class="table-config-name">${escapeHtml(t.name)}</span>
            <div class="table-config-actions">
                <button class="btn btn-xs btn-ghost" onclick="reorderTable('${t.id}', 'up')" title="Mover arriba" ${i === 0 ? 'disabled' : ''}><i class="fa-solid fa-arrow-up"></i></button>
                <button class="btn btn-xs btn-ghost" onclick="reorderTable('${t.id}', 'down')" title="Mover abajo" ${i === tables.length - 1 ? 'disabled' : ''}><i class="fa-solid fa-arrow-down"></i></button>
                <button class="btn btn-xs btn-ghost" onclick="editTable('${t.id}')" title="Editar"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-xs btn-ghost btn-danger" onclick="deleteTable('${t.id}')" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
    `).join('');
}

window.editTable = function(id) {
    const table = (state.tables || []).find(t => t.id === id);
    if (!table) return;
    editingTableId = id;
    document.getElementById('table-config-id').value = id;
    document.getElementById('table-config-name').value = table.name;
    selectedTableIcon = table.icon;
    updateIconSelectorUI();
    document.getElementById('btn-cancel-table-edit').style.display = 'inline-flex';
    document.getElementById('btn-save-table-config').innerHTML = '<i class="fa-solid fa-check"></i> Guardar';
    document.getElementById('table-config-name').focus();
};

window.reorderTable = async function(id, direction) {
    const tables = [...(state.tables || [])];
    const idx = tables.findIndex(t => t.id === id);
    if (idx === -1) return;
    const newIdx = direction === 'up' ? idx - 1 : idx + 1;
    if (newIdx < 0 || newIdx >= tables.length) return;

    const temp = tables[idx];
    tables[idx] = tables[newIdx];
    tables[newIdx] = temp;

    const order = tables.map(t => t.id);
    try {
        const data = await AppApi.request('reorder_tables', { order });
        if (data.status === 'success') {
            state.tables = tables;
            renderTablesConfigList();
            renderFloorPlan();
            populateTableSelect();
        }
    } catch (e) {
        showToast('Error al reordenar.', 'error');
    }
};

window.deleteTable = async function(id) {
    const table = (state.tables || []).find(t => t.id === id);
    if (!table) return;

    const confirmed = await window.ConfirmDialog.show(
        `¿Eliminar mesa "${table.name}"?`,
        { title: 'Eliminar Mesa', confirmText: 'Sí, eliminar', type: 'danger' }
    );

    if (confirmed) {
        try {
            const data = await AppApi.request('delete_table', { id });
            if (data.status === 'success') {
                state.tables = state.tables.filter(t => t.id !== id);
                showToast(`Mesa "${table.name}" eliminada.`, 'info');
                renderTablesConfigList();
                renderFloorPlan();
                populateTableSelect();
            }
        } catch (e) {
            showToast('Error al eliminar mesa.', 'error');
        }
    }
};

window.handleSaveTableConfig = async function(e) {
    e.preventDefault();
    const nameInput = document.getElementById('table-config-name');
    const name = nameInput.value.trim();
    if (!name) {
        showToast('Ingresa un nombre para la mesa.', 'error');
        return;
    }

    const payload = {
        name: name,
        icon: selectedTableIcon
    };

    if (editingTableId) {
        payload.id = editingTableId;
    } else {
        payload.sort_order = (state.tables || []).length + 1;
    }

    try {
        const data = await AppApi.request('save_table', payload);
        if (data.status === 'success') {
            showToast(editingTableId ? `Mesa "${name}" actualizada.` : `Mesa "${name}" agregada.`, 'success');
            editingTableId = null;
            nameInput.value = '';
            selectedTableIcon = 'fa-chair';
            updateIconSelectorUI();
            document.getElementById('btn-cancel-table-edit').style.display = 'none';
            document.getElementById('btn-save-table-config').innerHTML = '<i class="fa-solid fa-plus"></i> Agregar';

            await loadStateForTab('active-orders');
            renderTablesConfigList();
            renderFloorPlan();
            populateTableSelect();
        }
    } catch (e) {
        showToast('Error al guardar mesa.', 'error');
    }
};

window.cancelTableEdit = function() {
    editingTableId = null;
    document.getElementById('table-config-id').value = '';
    document.getElementById('table-config-name').value = '';
    selectedTableIcon = 'fa-chair';
    updateIconSelectorUI();
    document.getElementById('btn-cancel-table-edit').style.display = 'none';
    document.getElementById('btn-save-table-config').innerHTML = '<i class="fa-solid fa-plus"></i> Agregar';
};

window.populateTableSelect = function() {
    const select = document.getElementById('order-table-select');
    if (!select) return;
    const currentVal = select.value;
    select.innerHTML = '';

    const occupiedTables = new Set(
        (state.activeOrders || [])
            .filter(o => o.status === 'pendiente' && o.deliveryType === 'mesa')
        .map(o => {
            if (o.tableId) return o.tableId;
            const cust = o.customer || '';
                const dashIdx = cust.indexOf(' - ');
                return dashIdx > -1 ? cust.substring(0, dashIdx) : cust;
            })
    );

    (state.tables || []).forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.name;
        const isOccupied = occupiedTables.has(t.id) || occupiedTables.has(t.name);
        if (isOccupied) {
            const activeOrder = state.activeOrders.find(o => {
                if (o.status !== 'pendiente' || o.deliveryType !== 'mesa') return false;
                const cust = o.customer || '';
                const dashIdx = cust.indexOf(' - ');
                const tableName = dashIdx > -1 ? cust.substring(0, dashIdx) : cust;
                return o.tableId === t.id || tableName === t.name;
            });
            opt.textContent = `${t.name} [OCUPADA - ${formatCurrency(activeOrder ? activeOrder.total : 0)}]`;
            opt.disabled = true;
            opt.style.color = 'var(--danger)';
            opt.style.fontWeight = '700';
        } else {
            opt.textContent = t.name;
        }
        select.appendChild(opt);
    });

    const llevarOpt = document.createElement('option');
    llevarOpt.value = 'Llevar';
    llevarOpt.textContent = '🥡 Para Llevar';
    select.appendChild(llevarOpt);

    const deliveryOpt = document.createElement('option');
    deliveryOpt.value = 'Delivery';
    deliveryOpt.textContent = '🚗 Delivery';
    select.appendChild(deliveryOpt);

    const customOpt = document.createElement('option');
    customOpt.value = 'Personalizado';
    customOpt.textContent = 'Otro (Ingresar Nombre...)';
    select.appendChild(customOpt);

    if ([...select.options].some(o => o.value === currentVal && !o.disabled)) {
        select.value = currentVal;
    } else {
        select.value = 'Llevar';
    }
};

// ==========================================================================
// APPEND ITEMS TO EXISTING ORDER
// ==========================================================================

let appendItemsOrderId = null;
let appendItemsPending = [];
window.getAppendItemsPending = function() { return appendItemsPending; };

function openAppendItemsModal(orderId) {
    const order = state.activeOrders.find(o => o.id === orderId);
    if (!order) return;

    appendItemsOrderId = orderId;
    appendItemsPending = [];

    document.getElementById('append-items-customer').textContent = order.customer;
    document.getElementById('append-items-current-total').textContent = formatCurrency(order.total);

    const currentList = document.getElementById('append-items-current-list');
    currentList.innerHTML = '';
    (order.items || []).forEach(item => {
        const div = document.createElement('div');
        div.className = 'append-current-item';
        div.innerHTML = `<span>${escapeHtml(item.name)}</span><span style="color:var(--text-muted);">${formatCurrency(item.price)}</span>`;
        currentList.appendChild(div);
    });

    renderAppendPendingList();

    document.getElementById('append-catalog-select').value = '';
    document.getElementById('append-item-qty').value = '1';
    document.getElementById('append-item-options').style.display = 'none';

    // Update button text - always show "Agregar al Pedido"
    const confirmBtn = document.getElementById('btn-confirm-append-items');
    const paymentSection = document.getElementById('append-payment-section');
    if (confirmBtn) {
        confirmBtn.innerHTML = order.paid
            ? '<i class="fa-solid fa-cash-register"></i> Cobrar y agregar'
            : '<i class="fa-solid fa-check"></i> Agregar al Pedido';
        confirmBtn.className = 'btn btn-primary';
    }
    if (paymentSection) paymentSection.style.display = order.paid ? '' : 'none';

    if (typeof window.openModal === 'function') window.openModal('modal-append-items');
    else document.getElementById('modal-append-items')?.classList.add('open');
}

function closeAppendItemsModal() {
    if (typeof window.closeModal === 'function') window.closeModal('modal-append-items');
    else document.getElementById('modal-append-items')?.classList.remove('open');
    appendItemsOrderId = null;
    appendItemsPending = [];
}

function onAppendCatalogChange() {
    const select = document.getElementById('append-catalog-select');
    const optionsPanel = document.getElementById('append-item-options');
    const sopaRow = document.getElementById('append-option-sopa');
    const segundoRow = document.getElementById('append-option-segundo');
    const productRow = document.getElementById('append-option-product');
    const productLabel = document.getElementById('append-product-label');
    const productSelect = document.getElementById('append-select-product');

    if (!select || !optionsPanel) return;

    const val = select.value;
    if (!val) {
        optionsPanel.style.display = 'none';
        return;
    }

    const type = val;
    sopaRow.style.display = (type === 'almuerzo' || type === 'sopa') ? '' : 'none';
    segundoRow.style.display = (type === 'almuerzo' || type === 'segundo') ? '' : 'none';
    productRow.style.display = (type === 'plato_extra' || type === 'extra' || type === 'salsa' || type === 'acompanamiento') ? '' : 'none';

    if (type === 'almuerzo' || type === 'sopa') {
        const sopaSelect = document.getElementById('append-select-sopa');
        if (sopaSelect) {
            sopaSelect.innerHTML = '<option value="">-- Seleccionar sopa --</option>';
            state.sopas.filter(s => Number(s.active) !== 0).forEach(s => {
                const stock = getAvailableSopaStock(s.id);
                sopaSelect.innerHTML += appendStockOption(s, stock);
            });
        }
    }
    if (type === 'almuerzo' || type === 'segundo') {
        const segSelect = document.getElementById('append-select-segundo');
        if (segSelect) {
            segSelect.innerHTML = '<option value="">-- Seleccionar segundo --</option>';
            state.seconds.filter(s => Number(s.active) !== 0).forEach(s => {
                const stock = getAvailableSegundoStock(s.id);
                segSelect.innerHTML += appendStockOption(s, stock);
            });
        }
    }
    if (type === 'plato_extra' || type === 'extra' || type === 'salsa' || type === 'acompanamiento') {
        const products = type === 'plato_extra' ? state.platosExtras : type === 'extra' ? state.extras : type === 'salsa' ? state.salsas : state.accompaniments;
        const label = type === 'plato_extra' ? 'Plato extra' : type === 'extra' ? 'Bebida' : type === 'salsa' ? 'Salsa' : 'Acompañamiento extra';
        if (productLabel) productLabel.textContent = `${label}:`;
        if (productSelect) {
            productSelect.innerHTML = `<option value="">-- Seleccionar ${label.toLowerCase()} --</option>`;
            products.filter(product => Number(product.active) !== 0).forEach(product => {
                const availableStock = type === 'salsa'
                    ? getAvailableSalsaStock(product.id)
                    : type === 'acompanamiento'
                        ? getAvailableAccompanimentStock(product.id)
                        : type === 'plato_extra'
                            ? getAvailablePlatoExtraStock(product.id)
                            : getAvailableExtraStock(product.id);
                productSelect.innerHTML += appendStockOption(product, availableStock);
            });
        }
    }

    optionsPanel.style.display = 'block';
}

function appendStockOption(product, availableStock) {
    const stock = Number.isFinite(Number(availableStock)) ? Math.max(0, Number(availableStock)) : 0;
    const stockLabel = ` · Stock: ${stock}`;
    const disabled = stock <= 0 ? ' disabled' : '';
    return `<option value="${escapeHtml(String(product.id))}"${disabled}>${escapeHtml(product.name || 'Producto')}${stockLabel}</option>`;
}

function addItemToAppendModal() {
    const select = document.getElementById('append-catalog-select');
    if (!select || !select.value) {
        showToast('Selecciona un item del menú.', 'warning');
        return;
    }

    const type = select.value;
    let itemId = '';
    const qty = parseInt(document.getElementById('append-item-qty')?.value) || 1;
    const serviceType = document.getElementById('append-item-service-type')?.value || 'servirse';
    let name = '';
    let price = 0;

    let extraFields = {};

    if (type === 'almuerzo') {
        const sopaId = document.getElementById('append-select-sopa')?.value || '';
        const segId = document.getElementById('append-select-segundo')?.value || '';
        if (!sopaId || !segId) { showToast('Selecciona sopa y segundo.', 'warning'); return; }
        const sopaAvail = getAvailableSopaStock(sopaId);
        const segAvail = getAvailableSegundoStock(segId);
        if (sopaAvail < qty) { showToast(`Stock insuficiente de sopa. Disponible: ${sopaAvail}`, 'error'); return; }
        if (segAvail < qty) { showToast(`Stock insuficiente de segundo. Disponible: ${segAvail}`, 'error'); return; }
        const sopa = state.sopas.find(s => s.id === sopaId);
        const seg = state.seconds.find(s => s.id === segId);
        name = 'Almuerzo Completo';
        price = state.prices?.almuerzo || 15;
        extraFields = { sopaId, sopaName: sopa?.name || '', segundoId: segId, segundoName: seg?.name || '' };
    } else if (type === 'segundo') {
        const segId = document.getElementById('append-select-segundo')?.value || '';
        if (!segId) { showToast('Selecciona un segundo.', 'warning'); return; }
        const segAvail = getAvailableSegundoStock(segId);
        if (segAvail < qty) { showToast(`Stock insuficiente. Disponible: ${segAvail}`, 'error'); return; }
        const seg = state.seconds.find(s => s.id === segId);
        name = seg?.name || 'Segundo';
        price = state.prices?.segundo || 12;
        extraFields = { segundoId: segId, segundoName: seg?.name || '' };
    } else if (type === 'sopa') {
        const sopaId = document.getElementById('append-select-sopa')?.value || '';
        if (!sopaId) { showToast('Selecciona una sopa.', 'warning'); return; }
        const sopaAvail = getAvailableSopaStock(sopaId);
        if (sopaAvail < qty) { showToast(`Stock insuficiente. Disponible: ${sopaAvail}`, 'error'); return; }
        const sopa = state.sopas.find(s => s.id === sopaId);
        name = sopa?.name || 'Sopa';
        price = state.prices?.sopa || 6;
        extraFields = { sopaId, sopaName: sopa?.name || '' };
    } else if (type === 'plato_extra') {
        itemId = document.getElementById('append-select-product')?.value || '';
        if (!itemId) { showToast('Selecciona un plato extra.', 'warning'); return; }
        const platoAvail = getAvailablePlatoExtraStock(itemId);
        if (platoAvail < qty) { showToast(`Stock insuficiente. Disponible: ${platoAvail}`, 'error'); return; }
        const plato = state.platosExtras.find(p => p.id === itemId);
        name = plato?.name || 'Plato Extra';
        price = plato?.price || 0;
        extraFields = { platoId: itemId };
    } else if (type === 'extra') {
        itemId = document.getElementById('append-select-product')?.value || '';
        if (!itemId) { showToast('Selecciona una bebida.', 'warning'); return; }
        const extAvail = getAvailableExtraStock(itemId);
        if (extAvail < qty) { showToast(`Stock insuficiente. Disponible: ${extAvail}`, 'error'); return; }
        const ext = state.extras.find(e => e.id === itemId);
        name = ext?.name || 'Bebida';
        price = ext?.price || 0;
        extraFields = { extraId: itemId };
    } else if (type === 'salsa') {
        itemId = document.getElementById('append-select-product')?.value || '';
        if (!itemId) { showToast('Selecciona una salsa.', 'warning'); return; }
        const salsa = (state.salsas || []).find(item => item.id === itemId);
        const salsaAvail = getAvailableSalsaStock(itemId);
        if (salsaAvail < qty) { showToast(`Stock insuficiente de salsa. Disponible: ${salsaAvail}`, 'error'); return; }
        name = salsa?.name || 'Salsa';
        price = Number(salsa?.price || 0);
        extraFields = { salsaId: itemId };
    } else if (type === 'acompanamiento') {
        itemId = document.getElementById('append-select-product')?.value || '';
        if (!itemId) { showToast('Selecciona un acompañamiento.', 'warning'); return; }
        const accompaniment = (state.accompaniments || []).find(item => item.id === itemId);
        const accompanimentAvail = getAvailableAccompanimentStock(itemId);
        if (accompanimentAvail < qty) { showToast(`Stock insuficiente de acompañamiento. Disponible: ${accompanimentAvail}`, 'error'); return; }
        name = accompaniment?.name || 'Acompañamiento';
        price = Number(accompaniment?.price_extra || 0);
        extraFields = { accompanimentId: itemId };
    }

    const pendingItem = { type, name, price, quantity: qty, serviceType, ...extraFields };
    const catalogProduct = type === 'almuerzo'
        ? ((state.seconds || []).find(product => product.id === pendingItem.segundoId) || (state.sopas || []).find(product => product.id === pendingItem.sopaId))
        : type === 'segundo' ? (state.seconds || []).find(product => product.id === pendingItem.segundoId)
            : type === 'sopa' ? (state.sopas || []).find(product => product.id === pendingItem.sopaId)
                : type === 'plato_extra' ? (state.platosExtras || []).find(product => product.id === pendingItem.platoId)
                    : null;
    const canonicalProduct = (state.products || []).find(product => product.id === pendingItem.segundoId || product.id === pendingItem.sopaId || product.id === pendingItem.platoId);
    const optionProduct = { ...(catalogProduct || {}), ...(canonicalProduct || {}), selectionQuantity: qty };
    const finish = () => {
        appendItemsPending.push(pendingItem);
        renderAppendPendingList();
        select.value = '';
        document.getElementById('append-item-options').style.display = 'none';
        document.getElementById('append-item-qty').value = '1';
    };
    const optionEnabled = value => value === true || value === 1 || value === '1';
    const acceptsSalsa = optionEnabled(optionProduct?.accepts_salsa);
    const acceptsAccompaniment = optionEnabled(optionProduct?.accepts_accompaniment);
    const selectAccompaniments = () => {
        if (!acceptsAccompaniment || typeof window.openAccompanimentSelectModal !== 'function') return finish();
        window.openAccompanimentSelectModal(optionProduct, selected => {
            if (selected === null) return;
            if (selected?.length) pendingItem.accompaniments = selected;
            finish();
        });
    };
    if (acceptsSalsa && typeof window.openSalsaSelectModal === 'function') {
        window.openSalsaSelectModal(optionProduct, selected => {
            if (selected === null) return;
            if (selected?.length) pendingItem.salsas = selected;
            selectAccompaniments();
        });
    } else {
        selectAccompaniments();
    }
}

function removeAppendPendingItem(index) {
    appendItemsPending.splice(index, 1);
    renderAppendPendingList();
}

function renderAppendPendingList() {
    const container = document.getElementById('append-items-pending');
    const totalEl = document.getElementById('append-items-new-total');
    const countEl = document.getElementById('append-items-pending-count');
    if (!container) return;

    container.innerHTML = '';

    const order = state.activeOrders.find(o => o.id === appendItemsOrderId);
    const currentTotal = order ? order.total : 0;
    let pendingTotal = 0;

    appendItemsPending.forEach((item, idx) => {
        const optionTotal = (item.salsas || []).reduce((sum, salsa) => sum + Number(salsa.salsaPrice || 0), 0)
            + (item.accompaniments || []).reduce((sum, accompaniment) => sum + Number(accompaniment.accompanimentPrice || 0), 0);
        const itemTotal = (Number(item.price || 0) + optionTotal) * item.quantity;
        pendingTotal += itemTotal;
        const details = [item.sopaName, item.segundoName].filter(Boolean).join(' / ');
        const options = [
            details,
            (item.salsas || []).map(salsa => salsa.salsaName).filter(Boolean).join(', '),
            (item.accompaniments || []).map(accompaniment => accompaniment.accompanimentName).filter(Boolean).join(', ')
        ].filter(Boolean);
        const serviceLabel = item.serviceType === 'llevar' ? 'Para llevar' : 'Servirse';
        const serviceIcon = item.serviceType === 'llevar' ? 'fa-bag-shopping' : 'fa-plate-wheat';
        const div = document.createElement('div');
        div.className = 'append-pending-item';
        div.innerHTML = `
            <span class="append-pending-qty">${item.quantity}x</span>
            <div class="append-pending-content">
                <strong class="append-pending-name">${escapeHtml(item.name)}</strong>
                <div class="append-pending-meta">
                    <span class="item-detail-badge ${item.serviceType}"><i class="fa-solid ${serviceIcon}"></i> ${serviceLabel}</span>
                    <span>${formatCurrency(item.price)} c/u</span>
                    ${options.map(option => `<span>${escapeHtml(option)}</span>`).join('')}
                </div>
            </div>
            <div class="append-pending-actions">
                <strong>${formatCurrency(itemTotal)}</strong>
                <button class="btn btn-sm btn-danger-outline" onclick="removeAppendPendingItem(${idx})" aria-label="Quitar ${escapeHtml(item.name)}" title="Quitar"><i class="fa-solid fa-xmark"></i></button>
            </div>
        `;
        container.appendChild(div);
    });

    if (appendItemsPending.length === 0) {
        container.innerHTML = '<p class="text-muted" style="text-align:center; padding:12px; font-size:12px;">Agrega items del menú</p>';
    }

    if (countEl) countEl.textContent = String(appendItemsPending.length);
    if (totalEl) totalEl.textContent = formatCurrency(currentTotal + pendingTotal);
}

async function confirmAppendItems() {
    if (!appendItemsOrderId || appendItemsPending.length === 0) {
        showToast('Agrega al menos un item.', 'warning');
        return;
    }

    const order = state.activeOrders.find(o => o.id === appendItemsOrderId);
    if (!order) {
        showToast('Pedido no encontrado.', 'error');
        return;
    }

    // Always merge items into existing order
    try {
        const itemsToAppend = appendItemsPending.map(i => ({
            type: i.type,
            name: i.name,
            price: i.price,
            quantity: i.quantity,
            serviceType: i.serviceType,
            sopaId: i.sopaId || null,
            sopaName: i.sopaName || null,
            segundoId: i.segundoId || null,
            segundoName: i.segundoName || null,
            salsas: i.salsas || [],
            accompaniments: i.accompaniments || [],
            platoId: i.platoId || null,
            extraId: i.extraId || null,
            salsaId: i.salsaId || null,
            accompanimentId: i.accompanimentId || null
        }));

        const isAlreadyPaid = Boolean(order.paid);
        const paymentMethod = document.getElementById('append-payment-select')?.value || '';
        if (isAlreadyPaid && !paymentMethod) {
            showToast('Seleccione el método de pago de los nuevos productos.', 'warning');
            return;
        }

        const data = await AppApi.appendOrderItems(
            appendItemsOrderId,
            itemsToAppend,
            isAlreadyPaid,
            isAlreadyPaid ? paymentMethod : null
        );
        if (data.status === 'success') {
            const addedCount = appendItemsPending.length;
            // Close immediately after the server confirms the append/payment.
            closeAppendItemsModal();
            const addedItems = itemsToAppend.map(item => ({ ...item, qty: item.quantity || 1 }));
            order.items = data.items;
            order.total = data.total;
            order.latestAdditionBatchId = data.additionBatchId || null;
            order.paid = isAlreadyPaid || Boolean(data.paid);
            order.paymentMethod = data.paymentMethod || (isAlreadyPaid ? paymentMethod : order.paymentMethod);
            order.status = data.statusValue || (order.paid ? 'completado' : 'pendiente');
            if (window.PrintJobs) {
                const kitchenItems = addedItems.filter(item => !['extra', 'refresco', 'gaseosa', 'bebida'].includes(item.type));
                if (kitchenItems.length) PrintJobs.printKitchen(order, kitchenItems);
                if (isAlreadyPaid) PrintJobs.printPayment(order, addedItems);
                const takeoutItems = addedItems.filter(item => PrintJobs.isCustomerItem(item));
                if (takeoutItems.length) PrintJobs.printCustomer(order, takeoutItems);
            }
            if (window.AppStore) window.AppStore.emit();
            showToast(isAlreadyPaid
                ? `${addedCount} item(s) agregado(s) y cobro validado. La mesa sigue activa hasta cerrar el pedido.`
                : `${addedCount} item(s) agregado(s).`, 'success');

        }
    } catch (e) {
        console.error('[APPEND] Error adding items:', e);
        showToast(e.message || 'Error al agregar items al pedido.', 'error');
    }
}

let activePaymentOrderId = null;

function activePaymentItemTotal(item) {
    const salsaTotal = (item.salsas || []).reduce((sum, salsa) => sum + Number(salsa.salsaPrice || 0), 0);
    const accompanimentTotal = (item.accompaniments || []).reduce((sum, accompaniment) => sum + Number(accompaniment.accompanimentPrice || 0), 0);
    return (Number(item.price || 0) + salsaTotal + accompanimentTotal) * Number(item.qty || item.quantity || 1);
}

function renderActivePaymentItems(order) {
    const items = order.items || [];
    const subtotal = items.reduce((sum, item) => sum + activePaymentItemTotal(item), 0);
    const total = Number(order.total ?? subtotal);
    const discount = Math.max(0, subtotal - total);
    const container = document.getElementById('active-payment-items');
    if (container) {
        container.innerHTML = items.map(item => {
            const quantity = Number(item.qty || item.quantity || 1);
            const details = [
                item.sopaName,
                item.segundoName,
                ...(item.salsas || []).map(salsa => `${salsa.salsaName || salsa.name || 'Salsa'} (${salsa.salsaMode === 'aparte' ? 'Aparte' : 'Bañar'})`),
                ...(item.accompaniments || []).map(accompaniment => `${accompaniment.accompanimentName || accompaniment.name || 'Acompañamiento'}${accompaniment.accompanimentMode === 'extra' ? ' (Extra)' : ''}`),
                item.serviceType === 'llevar' ? 'Para llevar' : item.serviceType === 'servirse' ? 'Para servirse' : '',
                item.notes || item.note
            ].filter(Boolean);
            return `<div class="active-payment-item"><span class="active-payment-item-qty">${quantity}x</span><div><strong>${escapeHtml(item.name || 'Producto')}</strong>${details.length ? `<small>${escapeHtml(details.join(' · '))}</small>` : ''}</div><strong>${formatCurrency(activePaymentItemTotal(item))}</strong></div>`;
        }).join('') || '<p class="text-muted">El pedido no tiene productos.</p>';
    }
    document.getElementById('active-payment-subtotal').textContent = formatCurrency(subtotal);
    document.getElementById('active-payment-total').textContent = formatCurrency(total);
    document.getElementById('active-payment-footer-total').textContent = formatCurrency(total);
    const units = items.reduce((sum, item) => sum + Number(item.qty || item.quantity || 1), 0);
    document.getElementById('active-payment-count').textContent = `${units} ${units === 1 ? 'unidad' : 'unidades'}`;
    const discountRow = document.getElementById('active-payment-discount-row');
    if (discountRow) discountRow.style.display = discount > 0 ? '' : 'none';
    document.getElementById('active-payment-discount').textContent = `-${formatCurrency(discount)}`;
    return total;
}

window.updateActivePaymentMixedTotal = function() {
    const order = state.activeOrders.find(item => item.id === activePaymentOrderId);
    if (!order) return;
    const total = Number(order.total || 0);
    const cash = Number(document.getElementById('active-payment-cash')?.value || 0);
    const qr = Number(document.getElementById('active-payment-qr')?.value || 0);
    const summary = document.getElementById('active-payment-mixed-total');
    if (!summary) return;
    const matches = Math.abs(cash + qr - total) < 0.01;
    const difference = total - cash - qr;
    summary.setAttribute('aria-live', 'polite');
    summary.textContent = cash < 0 || qr < 0 ? 'Los importes no pueden ser negativos.' : matches ? 'Distribución completa: coincide con el total.' : difference > 0 ? `Falta asignar ${formatCurrency(difference)}` : `El importe supera el total por ${formatCurrency(-difference)}`;
    summary.classList.toggle('is-valid', matches);
    summary.classList.toggle('is-invalid', !matches);
};

window.updateActivePaymentFields = function() {
    const method = document.getElementById('active-payment-method')?.value || 'efectivo';
    const mixed = document.getElementById('active-payment-mixed-section');
    const credit = document.getElementById('active-payment-credit-section');
    if (mixed) mixed.style.display = method === 'mixto' ? '' : 'none';
    if (credit) credit.style.display = method === 'credito' ? '' : 'none';
    document.getElementById('active-payment-confirm-label').textContent = method === 'credito' ? 'Registrar crédito' : 'Cobrar e imprimir';
    const hints = {
        efectivo: 'Confirma el cobro cuando hayas recibido el efectivo.',
        qr: 'Verifica la recepción de la transferencia antes de confirmar.',
        tarjeta: 'Confirma cuando el pago con tarjeta haya sido aprobado.',
        mixto: 'Distribuye el total entre efectivo y QR. Los importes deben coincidir con el pedido.',
        credito: 'Se registrará una cuenta por cobrar con la fecha de vencimiento indicada.'
    };
    document.getElementById('active-payment-hint').textContent = hints[method] || '';
    if (method === 'mixto') window.updateActivePaymentMixedTotal();
};

window.openActiveOrderPaymentModal = function(orderId) {
    const order = state.activeOrders.find(item => item.id === orderId);
    if (!order) return showToast('Pedido no encontrado.', 'error');
    if (order.paid) return showToast('Este pedido ya está pagado.', 'info');
    activePaymentOrderId = orderId;
    const client = document.getElementById('active-payment-client');
    const method = document.getElementById('active-payment-method');
    if (client) client.textContent = order.customer || 'Cliente';
    const delivery = order.deliveryType || order.delivery_type;
    document.getElementById('active-payment-context').textContent = ({ mesa: 'En mesa', llevar: 'Para llevar', delivery: 'Delivery' })[delivery] || '';
    if (method) method.value = 'efectivo';
    document.getElementById('active-payment-cash').value = Number(order.total || 0).toFixed(2);
    document.getElementById('active-payment-qr').value = '0';
    document.getElementById('active-payment-due-date').value = '';
    document.getElementById('active-payment-credit-reason').value = '';
    renderActivePaymentItems(order);
    window.updateActivePaymentFields();
    openModal('modal-active-order-payment');
};

window.confirmActiveOrderPayment = async function() {
    const order = state.activeOrders.find(item => item.id === activePaymentOrderId);
    const method = document.getElementById('active-payment-method')?.value || 'efectivo';
    const button = document.getElementById('btn-confirm-active-payment');
    if (!order || order.paid) return;
    if (button?.disabled) return;
    if (button) button.disabled = true;
    try {
        let paymentMethod = method;
        let paid = true;
        let dueDate = null;
        if (method === 'mixto') {
            const cash = Number(document.getElementById('active-payment-cash')?.value || 0);
            const qr = Number(document.getElementById('active-payment-qr')?.value || 0);
            if (Math.abs(cash + qr - Number(order.total || 0)) > 0.01) throw new Error('La suma de pagos mixtos debe coincidir con el total.');
            paymentMethod = { efectivo: cash, qr };
        }
        if (method === 'credito') {
            dueDate = document.getElementById('active-payment-due-date')?.value || '';
            if (!dueDate) throw new Error('Indica la fecha de vencimiento para la venta a crédito.');
            paid = false;
        }
        const payload = {
            ...order,
            status: 'pendiente',
            paid,
            paymentMethod,
            dueDate,
            credit_override_reason: document.getElementById('active-payment-credit-reason')?.value.trim() || '',
            soldAt: new Date().toISOString(),
            tableId: order.tableId || null
        };
        const data = await AppApi.request('save_order', payload);
        if (data.status !== 'success') throw new Error(data.message || 'No se pudo registrar el cobro.');
        order.paid = paid;
        order.paymentMethod = paymentMethod;
        order.soldAt = payload.soldAt;
        order.dueDate = dueDate;
        if (method === 'credito') order.status = 'completado';
        order.paidByName = state.authUser?.name || order.paidByName;
        window.updateActiveOrder?.(order.id, { paid, paymentMethod, soldAt: order.soldAt, dueDate, status: order.status, paidByName: order.paidByName });
        if (paid) PrintJobs.printPayment(order);
        closeModal('modal-active-order-payment');
        showToast(method === 'credito' ? 'Venta a crédito registrada.' : 'Cobro registrado. La mesa continúa ocupada hasta cerrar el pedido.', 'success');
    } catch (error) {
        console.error('[ORDER] active payment error:', error);
        showToast(error.message || 'No se pudo registrar el cobro.', 'error');
    } finally {
        if (button) button.disabled = false;
    }
};

window.closePaidOrder = async function(orderId) {
    const order = state.activeOrders.find(o => o.id === orderId);
    if (!order) {
        showToast('Pedido no encontrado.', 'error');
        return;
    }

    if (!order.paid) {
        showToast('Este pedido no está pagado. Use "Cobrar / Ticket" para cobrar.', 'warning');
        return;
    }

    try {
        const paymentMethod = order.paymentMethod || 'efectivo';
        const data = await AppApi.request('complete_order', { id: orderId, paymentMethod, soldAt: order.soldAt || new Date().toISOString() });
        if (data.status === 'success') {
            const completedOrder = {
                ...order,
                status: 'completado'
            };
            window.removeActiveOrder(orderId);
            window.prependSaleHistory(completedOrder);
            showToast('Pedido cerrado y movido a ventas realizadas.', 'success');
        } else {
            showToast('Error al cerrar el pedido.', 'error');
        }
    } catch (e) {
        console.error('[ORDER] closePaidOrder error:', e);
        showToast('Error al cerrar el pedido.', 'error');
    }
};

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('form-table-config');
    if (form) form.addEventListener('submit', handleSaveTableConfig);
});
