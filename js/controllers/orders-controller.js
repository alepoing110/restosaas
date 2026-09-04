// ==========================================================================
// SCREEN 2: ORDERS CONTROLLER
// ==========================================================================

window.openTicketModalById = function(orderId) {
    const order = state.activeOrders.find(o => o.id === orderId);
    if (!order) return;

    const allItemsPaid = order.paid || (order.items || []).every(i => i.paid);
    if (allItemsPaid || order.status === 'pendiente') {
        window.openTicketModal(order, 'client');
    } else {
        window.openTicketModal(order, 'full');
    }
};

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
            if (completedOrder) {
                if (window.TicketPrinter) {
                    window.TicketPrinter.printReceipt(completedOrder);
                } else {
                    window.openTicketModal(completedOrder, 'client');
                }
            }
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
            const unitPrice = Number(item.price || 0) + salsaTotal;
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
                <input type="number" class="form-input cancel-order-item-qty" data-line-no="${index}" min="0.001" max="${quantity}" step="0.001" value="${quantity}">
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
                const cust = o.customer || '';
                const dashIdx = cust.indexOf(' - ');
                return dashIdx > -1 ? cust.substring(0, dashIdx) : cust;
            })
    );

    (state.tables || []).forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.name;
        const isOccupied = occupiedTables.has(t.name);
        if (isOccupied) {
            const activeOrder = state.activeOrders.find(o => {
                if (o.status !== 'pendiente' || o.deliveryType !== 'mesa') return false;
                const cust = o.customer || '';
                const dashIdx = cust.indexOf(' - ');
                const tableName = dashIdx > -1 ? cust.substring(0, dashIdx) : cust;
                return tableName === t.name;
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
    populateAppendCatalogSelect();

    document.getElementById('append-catalog-select').value = '';
    document.getElementById('append-item-qty').value = '1';
    document.getElementById('append-item-options').style.display = 'none';

    // Update button text - always show "Agregar al Pedido"
    const confirmBtn = document.getElementById('btn-confirm-append-items');
    const paymentSection = document.getElementById('append-payment-section');
    if (confirmBtn) {
        confirmBtn.innerHTML = '<i class="fa-solid fa-check"></i> Agregar al Pedido';
        confirmBtn.className = 'btn btn-primary';
    }
    if (paymentSection) paymentSection.style.display = 'none';

    const modal = document.getElementById('modal-append-items');
    if (modal) modal.classList.add('open');
}

function closeAppendItemsModal() {
    const modal = document.getElementById('modal-append-items');
    if (modal) modal.classList.remove('open');
    appendItemsOrderId = null;
    appendItemsPending = [];
}

function populateAppendCatalogSelect() {
    const select = document.getElementById('append-catalog-select');
    if (!select) return;

    const segGroup = select.querySelector('optgroup[label="Segundos"]');
    const sopaGroup = select.querySelector('optgroup[label="Sopas"]');
    const extraGroup = select.querySelector('optgroup[label="Platos Extras"]');
    const bebGroup = select.querySelector('optgroup[label="Bebidas"]');

    if (segGroup) {
        segGroup.innerHTML = '';
        state.seconds.filter(s => Number(s.active) !== 0).forEach(s => {
            segGroup.innerHTML += `<option value="segundo:${s.id}">${escapeHtml(s.name)}</option>`;
        });
    }
    if (sopaGroup) {
        sopaGroup.innerHTML = '';
        state.sopas.filter(s => Number(s.active) !== 0).forEach(s => {
            sopaGroup.innerHTML += `<option value="sopa:${s.id}">${escapeHtml(s.name)}</option>`;
        });
    }
    if (extraGroup) {
        extraGroup.innerHTML = '';
        state.platosExtras.forEach(p => {
            extraGroup.innerHTML += `<option value="plato_extra:${p.id}">${escapeHtml(p.name)}</option>`;
        });
    }
    if (bebGroup) {
        bebGroup.innerHTML = '';
        state.extras.forEach(e => {
            bebGroup.innerHTML += `<option value="extra:${e.id}">${escapeHtml(e.name)}</option>`;
        });
    }
}

function onAppendCatalogChange() {
    const select = document.getElementById('append-catalog-select');
    const optionsPanel = document.getElementById('append-item-options');
    const sopaRow = document.getElementById('append-option-sopa');
    const segundoRow = document.getElementById('append-option-segundo');

    if (!select || !optionsPanel) return;

    const val = select.value;
    if (!val) {
        optionsPanel.style.display = 'none';
        return;
    }

    const [type] = val.split(':');
    sopaRow.style.display = (type === 'almuerzo' || type === 'sopa') ? '' : 'none';
    segundoRow.style.display = (type === 'almuerzo' || type === 'segundo') ? '' : 'none';

    if (type === 'almuerzo' || type === 'sopa') {
        const sopaSelect = document.getElementById('append-select-sopa');
        if (sopaSelect) {
            sopaSelect.innerHTML = '<option value="">-- Seleccionar sopa --</option>';
            state.sopas.filter(s => Number(s.active) !== 0).forEach(s => {
                sopaSelect.innerHTML += `<option value="${s.id}">${escapeHtml(s.name)}</option>`;
            });
        }
    }
    if (type === 'almuerzo' || type === 'segundo') {
        const segSelect = document.getElementById('append-select-segundo');
        if (segSelect) {
            segSelect.innerHTML = '<option value="">-- Seleccionar segundo --</option>';
            state.seconds.filter(s => Number(s.active) !== 0).forEach(s => {
                segSelect.innerHTML += `<option value="${s.id}">${escapeHtml(s.name)}</option>`;
            });
        }
    }

    optionsPanel.style.display = 'block';
}

function addItemToAppendModal() {
    const select = document.getElementById('append-catalog-select');
    if (!select || !select.value) {
        showToast('Selecciona un item del menú.', 'warning');
        return;
    }

    const val = select.value;
    const [type, itemId] = val.split(':');
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
        const platoAvail = getAvailablePlatoExtraStock(itemId);
        if (platoAvail < qty) { showToast(`Stock insuficiente. Disponible: ${platoAvail}`, 'error'); return; }
        const plato = state.platosExtras.find(p => p.id === itemId);
        name = plato?.name || 'Plato Extra';
        price = plato?.price || 0;
        extraFields = { platoId: itemId };
    } else if (type === 'extra') {
        const extAvail = getAvailableExtraStock(itemId);
        if (extAvail < qty) { showToast(`Stock insuficiente. Disponible: ${extAvail}`, 'error'); return; }
        const ext = state.extras.find(e => e.id === itemId);
        name = ext?.name || 'Bebida';
        price = ext?.price || 0;
        extraFields = { extraId: itemId };
    }

    appendItemsPending.push({ type, name, price, quantity: qty, serviceType, ...extraFields });
    renderAppendPendingList();

    select.value = '';
    document.getElementById('append-item-options').style.display = 'none';
    document.getElementById('append-item-qty').value = '1';
}

function removeAppendPendingItem(index) {
    appendItemsPending.splice(index, 1);
    renderAppendPendingList();
}

function renderAppendPendingList() {
    const container = document.getElementById('append-items-pending');
    const totalEl = document.getElementById('append-items-new-total');
    if (!container) return;

    container.innerHTML = '';

    const order = state.activeOrders.find(o => o.id === appendItemsOrderId);
    const currentTotal = order ? order.total : 0;
    let pendingTotal = 0;

    appendItemsPending.forEach((item, idx) => {
        const itemTotal = item.price * item.quantity;
        pendingTotal += itemTotal;
        const div = document.createElement('div');
        div.className = 'append-pending-item';
        div.innerHTML = `
            <span>${item.quantity}x ${escapeHtml(item.name)} <span class="item-detail-badge ${item.serviceType}" style="font-size:10px;">${escapeHtml(item.serviceType)}</span></span>
            <div style="display:flex; align-items:center; gap:6px;">
                <span style="font-weight:600;">${formatCurrency(itemTotal)}</span>
                <button class="btn btn-sm btn-danger-outline" onclick="removeAppendPendingItem(${idx})"><i class="fa-solid fa-xmark"></i></button>
            </div>
        `;
        container.appendChild(div);
    });

    if (appendItemsPending.length === 0) {
        container.innerHTML = '<p class="text-muted" style="text-align:center; padding:12px; font-size:12px;">Agrega items del menú</p>';
    }

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
            platoId: i.platoId || null,
            extraId: i.extraId || null
        }));

        const data = await AppApi.appendOrderItems(appendItemsOrderId, itemsToAppend);
        if (data.status === 'success') {
            order.items = data.items;
            order.total = data.total;
            if (window.AppStore) window.AppStore.emit();
            showToast(`${appendItemsPending.length} item(s) agregado(s).`, 'success');
            closeAppendItemsModal();

            // Print kitchen comanda for food items only (exclude drinks)
            try {
                const foodItems = itemsToAppend.filter(i => 
                    i.type !== 'extra' && i.type !== 'bebida' && i.type !== 'gaseosa'
                );
                if (foodItems.length > 0) {
                    const kitchenOrder = {
                        ...order,
                        items: foodItems,
                        total: foodItems.reduce((sum, i) => sum + i.price * i.quantity, 0)
                    };
                    if (window.TicketPrinter) {
                        window.TicketPrinter.printKitchen(kitchenOrder);
                    } else {
                        window.openTicketModal(kitchenOrder, 'kitchen');
                    }
                }
            } catch (e) {
                console.error('[APPEND] Error abriendo ticket:', e);
            }
        }
    } catch (e) {
        console.error('[APPEND] Error adding items:', e);
        showToast('Error al agregar items al pedido.', 'error');
    }
}

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
        let paymentMethod = order.paymentMethod || 'efectivo';
        const validPayments = ['efectivo', 'qr', 'tarjeta'];
        if (typeof paymentMethod === 'string' && !validPayments.includes(paymentMethod)) {
            paymentMethod = 'efectivo';
        }
        const data = await AppApi.request('complete_order', { id: orderId, paymentMethod: paymentMethod, soldAt: new Date().toISOString() });
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
