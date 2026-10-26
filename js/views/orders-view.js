// ===============================================================================
// SCREEN 2: FLOOR PLAN & ACTIVE ORDERS BOARD (VIEW)
// ===============================================================================

function renderFloorPlan() {
    const grid = document.getElementById('tables-map-grid');
    if (!grid) return;
    grid.innerHTML = '';

    const matchedOrderIds = new Set();
    const fixedTables = [
        { id: '__llevar', name: 'Llevar', icon: 'fa-bag-shopping', fixed: true },
        { id: '__delivery', name: 'Delivery', icon: 'fa-motorcycle', fixed: true },
        { id: '__personalizado', name: 'Personalizado', icon: 'fa-user', fixed: true }
    ];
    const allTables = [...(state.tables || []), ...fixedTables];

    allTables.forEach(table => {
        const activeOrder = state.activeOrders.find(order => {
            if (order.status !== 'pendiente') return false;
            const customer = order.customer || '';
            return order.tableId === table.id || customer === table.name || (order.deliveryType === 'mesa' && customer.startsWith(table.name + ' - '));
        });
        if (activeOrder) matchedOrderIds.add(activeOrder.id);

        const node = document.createElement('div');
        node.className = `table-node${table.fixed ? ' table-fixed' : ''}`;
        const statusClass = getOrderStateClass(activeOrder);
        if (statusClass) node.classList.add(statusClass);
        node.innerHTML = `
            <i class="fa-solid ${table.icon} table-node-icon"></i>
            <span class="table-node-name">${escapeHtml(table.name)}</span>
            <span class="table-node-status">${getOrderStateLabel(activeOrder)}</span>
            <span style="font-size:11px;font-weight:700;margin-top:2px;">${activeOrder ? formatCurrency(activeOrder.total) : ''}</span>
        `;
        node.addEventListener('click', () => {
            if (activeOrder) {
                focusActiveOrderCard(activeOrder.id);
                return;
            }
            const select = document.getElementById('order-table-select');
            if (select) {
                select.value = table.name;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            const wrapper = document.getElementById('custom-customer-name-wrapper');
            if (wrapper) wrapper.style.display = '';
            document.getElementById('order-customer-name')?.focus();
            switchTab('pos');
            showToast(`${table.name === 'Delivery' ? 'Delivery' : 'Mesa'} pre-seleccionada: ${table.name}`, 'info');
        });
        grid.appendChild(node);
    });

    state.activeOrders
        .filter(order => order.status === 'pendiente' && !matchedOrderIds.has(order.id))
        .forEach(order => {
            const node = document.createElement('div');
            node.className = `table-node ${getOrderStateClass(order)}`;
            node.innerHTML = `
                <i class="fa-solid fa-user table-node-icon"></i>
                <span class="table-node-name">${escapeHtml(order.customer || 'Pedido')}</span>
                <span class="table-node-status">${getOrderStateLabel(order)}</span>
                <span style="font-size:11px;font-weight:700;margin-top:2px;">${formatCurrency(order.total)}</span>
            `;
            node.addEventListener('click', () => focusActiveOrderCard(order.id));
            grid.appendChild(node);
        });
}

function getOrderStateClass(order) {
    if (!order) return '';
    return order.serviceState === 'esperando_sopa' ? 'waiting-soup' :
        order.serviceState === 'esperando_segundo' ? 'waiting-main' :
        order.serviceState === 'comiendo' ? 'eating' :
        order.serviceState === 'esperando_cuenta' ? 'asking-bill' : '';
}

function getOrderStateLabel(order) {
    if (!order) return 'Libre';
    return order.serviceState === 'esperando_sopa' ? 'Esp. Sopa' :
        order.serviceState === 'esperando_segundo' ? 'Esp. Segundo' :
        order.serviceState === 'comiendo' ? 'Comiendo' :
        order.serviceState === 'esperando_cuenta' ? 'Pide Cuenta' : 'Ocupado';
}

function activeOrderItemKey(item) {
    const normalizeOptions = (options, idKey, nameKey, modeKey, priceKey) => (options || [])
        .map(option => ({
            id: option[idKey] || option.id || '',
            name: option[nameKey] || option.name || '',
            mode: option[modeKey] || '',
            price: Number(option[priceKey] || option.price || 0)
        }))
        .sort((a, b) => JSON.stringify(a).localeCompare(JSON.stringify(b)));

    return JSON.stringify({
        type: item.type || '',
        name: item.name || '',
        price: Number(item.price || 0),
        serviceType: item.serviceType || '',
        sopaId: item.sopaId || '',
        sopaName: item.sopaName || '',
        segundoId: item.segundoId || '',
        segundoName: item.segundoName || '',
        platoId: item.platoId || '',
        extraId: item.extraId || '',
        salsaId: item.salsaId || '',
        accompanimentId: item.accompanimentId || '',
        salsaMode: item.salsaMode || '',
        accompanimentMode: item.accompanimentMode || '',
        detail: item.detail || '',
        notes: item.notes || '',
        paid: Boolean(item.paid),
        isAdditional: Boolean(item.isAdditional),
        salsas: normalizeOptions(item.salsas, 'salsaId', 'salsaName', 'salsaMode', 'salsaPrice'),
        accompaniments: normalizeOptions(item.accompaniments, 'accompanimentId', 'accompanimentName', 'accompanimentMode', 'accompanimentPrice')
    });
}

function salsaModeLabel(salsa) {
    const rawMode = String(salsa?.salsaMode ?? salsa?.salsa_mode ?? salsa?.mode ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim()
        .toLowerCase()
        .replace(/[\s-]+/g, '_');
    if (['aparte', 'a_parte', 'separada', 'separado'].includes(rawMode)) return 'Aparte';
    // Older orders stored "incluida" after selecting Bañar.
    if (['banar', 'banado', 'incluida', 'incluido', ''].includes(rawMode)) return 'Bañar';
    return rawMode.replace(/_/g, ' ');
}

function focusActiveOrderCard(orderId) {
    const searchInput = document.getElementById('search-active-orders');
    if (searchInput && searchInput.value) {
        searchInput.value = '';
        renderActiveOrders();
    }
    requestAnimationFrame(() => {
        const selector = `#active-orders-board .order-card[data-order-id="${CSS.escape(String(orderId))}"]`;
        const card = document.querySelector(selector);
        if (!card) return;
        card.tabIndex = -1;
        card.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
        card.classList.remove('map-focus');
        void card.offsetWidth;
        card.classList.add('map-focus');
        card.focus({ preventScroll: true });
        window.setTimeout(() => card.classList.remove('map-focus'), 1800);
    });
}

function renderActiveOrders() {
    const board = document.getElementById('active-orders-board');
    if (!board) return;
    const emptyBoard = document.getElementById('empty-board-state');
    const search = (document.getElementById('search-active-orders')?.value || '').toLowerCase();
    board.innerHTML = '';
    const pendingOrders = state.activeOrders.filter(order => order.status === 'pendiente' && (order.customer || '').toLowerCase().includes(search));

    let pendingCount = 0;
    let dineinCount = 0;
    let takeoutCount = 0;
    state.activeOrders.forEach(order => {
        if (order.status !== 'pendiente') return;
        pendingCount++;
        (order.items || []).forEach(item => {
            const quantity = item.qty || 1;
            if (item.serviceType === 'servirse') dineinCount += quantity;
            if (item.serviceType === 'llevar') takeoutCount += quantity;
        });
    });
    document.getElementById('metrics-pending-count')?.replaceChildren(String(pendingCount));
    document.getElementById('metrics-dinein-count')?.replaceChildren(String(dineinCount));
    document.getElementById('metrics-takeout-count')?.replaceChildren(String(takeoutCount));

    if (!pendingOrders.length) {
        if (emptyBoard) board.appendChild(emptyBoard);
        return;
    }

    pendingOrders.forEach((order, index) => {
        const hasTakeout = (order.items || []).some(item => item.serviceType === 'llevar');
        const card = document.createElement('div');
        card.className = `order-card ${hasTakeout ? 'priority-takeout' : 'priority-dinein'}`;
        card.draggable = true;
        card.tabIndex = 0;
        card.dataset.orderId = order.id;
        card.dataset.orderIndex = index;
        card.addEventListener('dragstart', handleDragStart);
        card.addEventListener('dragend', handleDragEnd);
        card.addEventListener('dragover', handleDragOver);
        card.addEventListener('drop', handleDrop);
        card.addEventListener('dragleave', handleDragLeave);

        const latestAdditionBatch = window.PrintJobs?.getLatestAdditionItems?.(order) || [];
        const waiterCandidateItems = latestAdditionBatch.length
            ? latestAdditionBatch
            : (order.items || []).filter(item => !item.isAdditional);
        const latestAdditionItems = waiterCandidateItems.filter(item => window.PrintJobs?.isWaiterItem?.(item) || item.serviceType === 'servirse');
        const groups = {};
        const orderKeys = [];
        (order.items || []).forEach(item => {
            const details = item.type === 'almuerzo'
                ? [item.sopaName, item.segundoName].filter(Boolean).join(' / ')
                : item.sopaName || item.segundoName || '';
            const key = activeOrderItemKey(item);
            if (!groups[key]) {
                groups[key] = { name: item.name, serviceType: item.serviceType, details, salsas: item.salsas || [], accompaniments: item.accompaniments || [], qty: 0, total: 0, paid: item.paid, isAdditional: item.isAdditional };
                orderKeys.push(key);
            }
            groups[key].qty += item.qty || 1;
            groups[key].total += (Number(item.price) || 0) * (item.qty || 1);
        });
        const itemsHtml = orderKeys.map(key => {
            const item = groups[key];
            const salsaDetails = item.salsas.map(salsa => {
                const name = salsa.salsaName || salsa.name;
                if (!name) return '';
                return `Salsa: ${name} (${salsaModeLabel(salsa)})`;
            }).filter(Boolean);
            const accompanimentDetails = item.accompaniments.map(accompaniment => {
                const name = accompaniment.accompanimentName || accompaniment.name;
                if (!name) return '';
                return `Acomp.: ${name}${accompaniment.accompanimentMode === 'extra' ? ' (Extra)' : ''}`;
            }).filter(Boolean);
            const optionDetails = [...salsaDetails, ...accompanimentDetails];
            return `<div class="order-item-detail"><span class="order-item-qty">${item.qty}x</span><div class="order-item-label"><span>${escapeHtml(item.name)}</span><div class="order-item-subdetails"><span class="item-detail-badge ${item.serviceType}">${escapeHtml(item.serviceType || '')}</span>${item.details ? `<span class="item-detail-badge segundo-name">${escapeHtml(`(${item.details})`)}</span>` : ''}${item.isAdditional ? '<span class="item-detail-badge additional">Agregado</span>' : ''}${item.paid ? '<span class="item-detail-badge paid">Pagado</span>' : ''}${optionDetails.map(detail => `<span class="order-option-detail">${escapeHtml(detail)}</span>`).join('')}</div></div><span class="order-card-price">${formatCurrency(item.total)}</span></div>`;
        }).join('');
        const s = order.serviceState;
        card.innerHTML = `
            <div class="order-card-header">
                <div class="order-card-heading"><span class="order-card-title">${escapeHtml(order.customer || 'Pedido')}</span><span class="order-time"><i class="fa-regular fa-clock"></i> ${formatTime(order.timestamp)}</span></div>
                <div class="order-card-amount"><span>${order.paid ? 'Pagado' : 'A cobrar'}</span><strong>${formatCurrency(order.total)}</strong></div>
            </div>
            <div class="order-card-body"><div class="order-items-list">${itemsHtml}</div></div>
            <div class="order-card-footer">
                <div class="order-state-controls">
                    <button class="btn-state-step ${s === 'esperando_sopa' ? 'active-step soup' : ''}" onclick="setOrderServiceState('${order.id}','esperando_sopa')"><i class="fa-solid fa-bowl-hot"></i> Sopa</button>
                    <button class="btn-state-step ${s === 'esperando_segundo' ? 'active-step main' : ''}" onclick="setOrderServiceState('${order.id}','esperando_segundo')"><i class="fa-solid fa-plate-wheat"></i> Segundo</button>
                    <button class="btn-state-step ${s === 'comiendo' ? 'active-step eat' : ''}" onclick="setOrderServiceState('${order.id}','comiendo')"><i class="fa-solid fa-face-smile"></i> Servido</button>
                    <button class="btn-state-step ${s === 'esperando_cuenta' ? 'active-step bill' : ''}" onclick="setOrderServiceState('${order.id}','esperando_cuenta')"><i class="fa-solid fa-receipt"></i> Cuenta</button>
                </div>
                <div class="order-card-actions"><button class="btn btn-outline btn-sm" onclick="openAppendItemsModal('${order.id}')"><i class="fa-solid fa-cart-plus"></i> Agregar</button>${(state.business?.waiter_ticket_enabled === '1' || state.business?.waiter_ticket_enabled === true) && latestAdditionItems.length ? `<button class="btn btn-outline btn-sm" onclick="PrintJobs.printWaiterTicket(state.activeOrders.find(item => item.id === '${order.id}'), PrintJobs.getLatestAdditionItems(state.activeOrders.find(item => item.id === '${order.id}')))"><i class="fa-solid fa-user-tie"></i> Ticket mesero</button>` : ''}${order.paid ? `<button class="btn btn-success btn-sm" onclick="closePaidOrder('${order.id}')"><i class="fa-solid fa-check-double"></i> Cerrar</button>` : `<button class="btn btn-primary btn-sm" onclick="openActiveOrderPaymentModal('${order.id}')"><i class="fa-solid fa-cash-register"></i> Cobrar</button><button class="btn btn-outline-danger btn-sm" onclick="cancelActiveOrder('${order.id}')"><i class="fa-solid fa-ban"></i> Cancelar</button>`}</div>
            </div>
        `;
        board.appendChild(card);
    });
}

let draggedOrderId = null;
function handleDragStart(event) {
    draggedOrderId = this.dataset.orderId;
    this.classList.add('dragging');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', draggedOrderId);
}
function handleDragEnd() {
    this.classList.remove('dragging');
    document.querySelectorAll('.order-card').forEach(card => card.classList.remove('drag-over'));
}
function handleDragOver(event) {
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    this.classList.add('drag-over');
}
function handleDragLeave() { this.classList.remove('drag-over'); }
function handleDrop(event) {
    event.preventDefault();
    this.classList.remove('drag-over');
    const targetOrderId = this.dataset.orderId;
    if (!draggedOrderId || draggedOrderId === targetOrderId) return;
    const draggedIndex = state.activeOrders.findIndex(order => order.id === draggedOrderId);
    const targetIndex = state.activeOrders.findIndex(order => order.id === targetOrderId);
    if (draggedIndex < 0 || targetIndex < 0) return;
    const [draggedOrder] = state.activeOrders.splice(draggedIndex, 1);
    state.activeOrders.splice(targetIndex, 0, draggedOrder);
    window.AppStore?.emit();
    showToast('Orden de pedidos actualizada', 'info');
}

window.handleDragStart = handleDragStart;
window.handleDragEnd = handleDragEnd;
window.handleDragOver = handleDragOver;
window.handleDrop = handleDrop;
window.handleDragLeave = handleDragLeave;
