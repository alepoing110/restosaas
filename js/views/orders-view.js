// ==========================================================================
// SCREEN 2: FLOOR PLAN & ACTIVE ORDERS BOARD (VIEW)
// ==========================================================================

function renderFloorPlan() {
    const grid = document.getElementById('tables-map-grid');
    if (!grid) return;
    grid.innerHTML = '';

    const matchedOrderIds = new Set();

    // Fixed tables that always appear (not in DB, not editable)
    const fixedTables = [
        { id: '__llevar', name: 'Llevar', icon: 'fa-bag-shopping', fixed: true },
        { id: '__delivery', name: 'Delivery', icon: 'fa-motorcycle', fixed: true },
        { id: '__personalizado', name: 'Personalizado', icon: 'fa-user', fixed: true }
    ];

    // Render DB tables + fixed tables
    const allTables = [...(state.tables || []), ...fixedTables];

    allTables.forEach(table => {
        const activeOrder = state.activeOrders.find(o => {
            if (o.status !== 'pendiente') return false;
            const cust = o.customer || '';
            if (cust === table.name) return true;
            if (o.deliveryType === 'mesa' && cust.startsWith(table.name + ' - ')) return true;
            return false;
        });
        if (activeOrder) matchedOrderIds.add(activeOrder.id);
        
        const node = document.createElement('div');
        node.className = 'table-node';
        if (table.fixed) node.classList.add('table-fixed');
        
        let statusClass = '';
        let statusText = 'Libre';
        let detailText = '';

        if (activeOrder) {
            statusClass = activeOrder.serviceState === 'esperando_sopa' ? 'waiting-soup' :
                          activeOrder.serviceState === 'esperando_segundo' ? 'waiting-main' :
                          activeOrder.serviceState === 'comiendo' ? 'eating' :
                          activeOrder.serviceState === 'esperando_cuenta' ? 'asking-bill' : '';
            
            if (statusClass) node.classList.add(statusClass);
            
            statusText = activeOrder.serviceState === 'esperando_sopa' ? 'Esp. Sopa' :
                         activeOrder.serviceState === 'esperando_segundo' ? 'Esp. Segundo' :
                         activeOrder.serviceState === 'comiendo' ? 'Comiendo' :
                         activeOrder.serviceState === 'esperando_cuenta' ? 'Pide Cuenta' : 'Ocupado';
            
            detailText = formatCurrency(activeOrder.total);
        }

        node.innerHTML = `
            <i class="fa-solid ${table.icon} table-node-icon"></i>
            <span class="table-node-name">${escapeHtml(table.name)}</span>
            <span class="table-node-status">${statusText}</span>
            <span style="font-size: 11px; font-weight:700; margin-top:2px;">${activeOrder ? detailText : ''}</span>
        `;

        node.addEventListener('click', () => {
            if (activeOrder) {
                window.openTicketModal(activeOrder);
            } else {
                const selectElement = document.getElementById('order-table-select');
                if (selectElement) selectElement.value = table.name;
                const wrapper = document.getElementById('custom-customer-name-wrapper');
                const needsName = table.name === 'Personalizado' || table.name === 'Delivery';
                if (wrapper) wrapper.style.display = needsName ? 'block' : 'none';
                switchTab('pos');
                showToast(`${table.name === 'Delivery' ? 'Delivery' : 'Mesa'} pre-seleccionada: ${table.name}`, 'info');
            }
        });

        grid.appendChild(node);
    });

    const unmatchedOrders = state.activeOrders.filter(o => o.status === 'pendiente' && !matchedOrderIds.has(o.id));
    unmatchedOrders.forEach(activeOrder => {
        const node = document.createElement('div');
        node.className = 'table-node';

        let statusClass = '';
        let statusText = 'Ocupado';

        if (activeOrder.serviceState) {
            statusClass = activeOrder.serviceState === 'esperando_sopa' ? 'waiting-soup' :
                          activeOrder.serviceState === 'esperando_segundo' ? 'waiting-main' :
                          activeOrder.serviceState === 'comiendo' ? 'eating' :
                          activeOrder.serviceState === 'esperando_cuenta' ? 'asking-bill' : '';
            statusText = activeOrder.serviceState === 'esperando_sopa' ? 'Esp. Sopa' :
                         activeOrder.serviceState === 'esperando_segundo' ? 'Esp. Segundo' :
                         activeOrder.serviceState === 'comiendo' ? 'Comiendo' :
                         activeOrder.serviceState === 'esperando_cuenta' ? 'Pide Cuenta' : 'Ocupado';
        }

        if (statusClass) node.classList.add(statusClass);
        node.innerHTML = `
            <i class="fa-solid fa-user table-node-icon"></i>
            <span class="table-node-name">${escapeHtml(activeOrder.customer)}</span>
            <span class="table-node-status">${statusText}</span>
            <span style="font-size: 11px; font-weight:700; margin-top:2px;">${formatCurrency(activeOrder.total)}</span>
        `;

        node.addEventListener('click', () => {
            window.openTicketModal(activeOrder);
        });

        grid.appendChild(node);
    });
}

function renderActiveOrders() {
    const board = document.getElementById('active-orders-board');
    if (!board) return;
    const emptyBoard = document.getElementById('empty-board-state');
    const searchInput = document.getElementById('search-active-orders');
    const searchVal = searchInput ? searchInput.value.toLowerCase() : '';
    board.innerHTML = '';

    const pendingOrders = state.activeOrders.filter(order => {
        const matchesSearch = order.customer ? order.customer.toLowerCase().includes(searchVal) : true;
        return order.status === 'pendiente' && matchesSearch;
    });

    let pendingCount = 0;
    let dineinCount = 0;
    let takeoutCount = 0;

    state.activeOrders.forEach(o => {
        if (o.status === 'pendiente') {
            pendingCount++;
            (o.items || []).forEach(i => {
                const qty = i.qty || 1;
                if (i.serviceType === 'servirse') dineinCount += qty;
                if (i.serviceType === 'llevar') takeoutCount += qty;
            });
        }
    });

    const pendingEl = document.getElementById('metrics-pending-count');
    const dineinEl = document.getElementById('metrics-dinein-count');
    const takeoutEl = document.getElementById('metrics-takeout-count');
    if (pendingEl) pendingEl.textContent = pendingCount;
    if (dineinEl) dineinEl.textContent = dineinCount;
    if (takeoutEl) takeoutEl.textContent = takeoutCount;

    if (pendingOrders.length === 0) {
        if (emptyBoard) board.appendChild(emptyBoard);
        return;
    }

    pendingOrders.forEach((order, index) => {
        const hasTakeout = (order.items || []).some(i => i.serviceType === 'llevar');
        const priorityClass = hasTakeout ? 'priority-takeout' : 'priority-dinein';
        
        const card = document.createElement('div');
        card.className = `order-card ${priorityClass}`;
        card.draggable = true;
        card.dataset.orderId = order.id;
        card.dataset.orderIndex = index;
        
        card.addEventListener('dragstart', handleDragStart);
        card.addEventListener('dragend', handleDragEnd);
        card.addEventListener('dragover', handleDragOver);
        card.addEventListener('drop', handleDrop);
        card.addEventListener('dragleave', handleDragLeave);

        let itemsHtml = '';
        const itemsGrouped = {};
        const itemsOrder = [];
        (order.items || []).forEach(item => {
            let detailsText = '';
            if (item.type === 'almuerzo') {
                const parts = [];
                if (item.sopaName) parts.push(item.sopaName);
                if (item.segundoName) parts.push(item.segundoName);
                detailsText = parts.length ? `(${parts.join(' / ')})` : '';
            } else if (item.type === 'segundo') {
                detailsText = item.segundoName ? `(${item.segundoName})` : '';
            } else if (item.type === 'sopa') {
                detailsText = item.sopaName ? `(${item.sopaName})` : '';
            }

            const qty = item.qty || 1;
            const itemPaid = item.paid ? 'paid' : 'unpaid';
            const groupKey = `${item.name}|${item.type}|${item.serviceType}|${detailsText}|${itemPaid}`;
            if (!itemsGrouped[groupKey]) {
                itemsGrouped[groupKey] = { name: item.name, type: item.type, serviceType: item.serviceType, detailsText, unitPrice: item.price, totalQty: 0, totalCost: 0, isPaid: item.paid };
                itemsOrder.push(groupKey);
            }
            itemsGrouped[groupKey].totalQty += qty;
            itemsGrouped[groupKey].totalCost += item.price * qty;
        });

        itemsOrder.forEach(groupKey => {
            const g = itemsGrouped[groupKey];
            const paidBadge = g.isPaid ? '<span class="item-detail-badge paid" style="background:#dcfce7; color:#16a34a; font-size:9px;">💰 Pagado</span>' : '';
            itemsHtml += `
                <div class="order-item-detail">
                    <span class="order-item-qty">${g.totalQty}x</span>
                    <div class="order-item-label">
                        <span>${escapeHtml(g.name)}</span>
                        <div class="order-item-subdetails">
                            <span class="item-detail-badge ${g.serviceType}">${escapeHtml(g.serviceType)}</span>
                            ${g.detailsText ? `<span class="item-detail-badge segundo-name">${escapeHtml(g.detailsText)}</span>` : ''}
                            ${paidBadge}
                        </div>
                    </div>
                    <span class="order-card-price">${formatCurrency(g.totalCost)}</span>
                </div>
            `;
        });

        const s = order.serviceState;
        const progressHtml = `
            <div class="order-state-controls">
                <button class="btn-state-step ${s==='esperando_sopa'?'active-step soup':''}" onclick="setOrderServiceState('${order.id}','esperando_sopa')">
                    <i class="fa-solid fa-bowl-hot"></i> Sopa
                </button>
                <button class="btn-state-step ${s==='esperando_segundo'?'active-step main':''}" onclick="setOrderServiceState('${order.id}','esperando_segundo')">
                    <i class="fa-solid fa-plate-wheat"></i> Segundo
                </button>
                <button class="btn-state-step ${s==='comiendo'?'active-step eat':''}" onclick="setOrderServiceState('${order.id}','comiendo')">
                    <i class="fa-solid fa-face-smile"></i> Servido
                </button>
                <button class="btn-state-step ${s==='esperando_cuenta'?'active-step bill':''}" onclick="setOrderServiceState('${order.id}','esperando_cuenta')">
                    <i class="fa-solid fa-receipt"></i> Cuenta
                </button>
            </div>
        `;

        card.innerHTML = `
            <div class="order-card-header">
                <span class="order-card-title">${escapeHtml(order.customer)} ${order.paid ? '<span style="background:#16a34a; color:#fff; padding:2px 6px; border-radius:4px; font-size:10px; margin-left:4px;">💰 PAGADO</span>' : ''}</span>
                <span class="order-time"><i class="fa-regular fa-clock"></i> ${formatTime(order.timestamp)}</span>
            </div>
            <div class="order-card-body">
                <div class="order-items-list">
                    ${itemsHtml}
                </div>
            </div>
            <div class="order-card-footer">
                ${progressHtml}
                <div class="order-card-total-row">
                    <span>Total a cobrar:</span>
                    <span>${formatCurrency(order.total)}</span>
                </div>
                <div class="order-card-actions">
                    <button class="btn btn-outline btn-sm" onclick="openAppendItemsModal('${order.id}')">
                        <i class="fa-solid fa-cart-plus"></i> Agregar
                    </button>
                    ${order.paid || (order.items || []).every(i => i.paid) ? `
                        <button class="btn btn-success btn-sm" onclick="closePaidOrder('${order.id}')">
                            <i class="fa-solid fa-check-double"></i> Cerrar
                        </button>
                    ` : ''}
                    <button class="btn btn-outline-danger btn-sm" onclick="cancelActiveOrder('${order.id}')">
                        <i class="fa-solid fa-ban"></i> Cancelar
                    </button>
                    <button class="btn btn-primary btn-sm" onclick="openTicketModalById('${order.id}')">
                        <i class="fa-solid fa-receipt"></i> ${(order.paid || (order.items || []).every(i => i.paid)) ? 'Imprimir' : 'Cobrar / Ticket'}
                    </button>
                </div>
            </div>
        `;
        board.appendChild(card);
    });
}

// ==========================================================================
// TICKET PREVIEW MODAL LOGIC (DOUBLE COMBINED TICKET)
// ==========================================================================

let activeTicketPreviewOrder = null;
let activeTicketContext = null;

function findLatestOrderSnapshot(orderId) {
    if (!orderId) return null;

    const activeOrder = state.activeOrders.find(order => order.id === orderId);
    if (activeOrder) return activeOrder;

    const completedOrder = state.salesHistory.find(order => order.id === orderId);
    if (completedOrder) return completedOrder;

    return null;
}

function generateTicketHtml(order) {
    if (!order) return '<div style="padding:20px; color:red;">No hay datos de orden.</div>';

    let items = order.items;
    if (typeof items === 'string') {
        try { items = JSON.parse(items); } catch (e) { items = []; }
    }
    if (!Array.isArray(items)) items = [];

    // 1. Client Items (All items included: food + drinks)
    let clientItemsRows = '';
    items.forEach(item => {
        const qty = Math.max(1, parseInt(item.qty || item.quantity || 1, 10));
        const price = parseFloat(item.price || 0);
        const lineTotal = price * qty;
        let desc = item.name || 'Producto';
        if (item.type === 'almuerzo') {
            desc += item.sopaName ? ` - ${item.sopaName}` : '';
            desc += item.segundoName ? ` / ${item.segundoName}` : '';
        } else if (item.type === 'segundo') {
            desc += item.segundoName ? ` - ${item.segundoName}` : '';
        } else if (item.type === 'sopa') {
            desc += item.sopaName ? ` - ${item.sopaName}` : '';
        }
        clientItemsRows += `
            <tr>
                <td class="ticket-qty-col">${qty}</td>
                <td class="ticket-desc-col">${escapeHtml(desc)} (${item.serviceType === 'llevar' ? 'Llevar' : 'Mesa'})</td>
                <td class="ticket-total-col">${lineTotal.toFixed(2)}</td>
            </tr>
        `;
    });

    // 2. Kitchen Items (ONLY Food items: almuerzo, segundo, sopa, plato_extra. Exclude drinks/extras)
    let kitchenItemsRows = '';
    let kitchenFoodCount = 0;
    items.forEach(item => {
        const isDrink = item.type === 'extra' || item.type === 'bebida' || item.type === 'gaseosa';
        if (isDrink) return; // Skip drinks/gaseosas for kitchen ticket

        kitchenFoodCount++;
        const qty = Math.max(1, parseInt(item.qty || item.quantity || 1, 10));
        let desc = item.name || 'Producto';
        if (item.type === 'almuerzo') {
            desc += item.segundoName ? ` / ${item.segundoName}` : '';
            desc += item.sopaName ? ` (Sopa: ${item.sopaName})` : '';
        } else if (item.type === 'segundo') {
            desc += item.segundoName ? ` - ${item.segundoName}` : '';
        } else if (item.type === 'sopa') {
            desc += item.sopaName ? ` - ${item.sopaName}` : '';
        }
        kitchenItemsRows += `
            <tr>
                <td class="ticket-qty-col">${qty}</td>
                <td class="ticket-desc-col">${escapeHtml(desc)} (${item.serviceType === 'llevar' ? 'Llevar' : 'Mesa'})</td>
                <td class="ticket-total-col">- - -</td>
            </tr>
        `;
    });

    if (kitchenFoodCount === 0) {
        kitchenItemsRows = `<tr><td colspan="3" style="text-align:center; padding:10px; font-style:italic; color:#666;">Solo Bebidas / Gaseosas (No requiere cocina)</td></tr>`;
    }

    const titleHeader = 'TICKET DE VENTA';
    const pm = parsePaymentMethod(order.paymentMethod, order.total);
    const pMethod = pm.label.toUpperCase();
    const biz = state.business || {};
    const bizName = (biz.nombre_restaurante || 'RESTAURANTE').toUpperCase();
    const bizDireccion = biz.direccion || '';
    const bizTelefono = biz.telefono || '';

    // Check if order is a Table Comanda (Pending Table Order)
    const isPending = order.status === 'pendiente';
    const isMesa = order.deliveryType === 'mesa' || (order.customer && order.customer.toLowerCase().includes('mesa'));
    const isTableComandaOnly = isPending && isMesa;

    const clientReceiptHtml = `
        <!-- PART 1: CLIENT RECEIPT -->
        <div class="ticket-client-copy">
            <div class="ticket-header">
                <div class="ticket-logo">${escapeHtml(bizName)}</div>
                <div style="font-size:11px;">${escapeHtml(bizDireccion)}</div>
                <div style="font-size:10px;">${escapeHtml(bizTelefono)}</div>
                <div class="ticket-divider"></div>
                <div style="font-weight:700; font-size:11.5px; margin-top:4px;">${titleHeader}</div>
            </div>
            <div class="ticket-meta">
                <div class="ticket-meta-row">
                    <span>Fecha: ${(() => { const tz = (typeof getBusinessTimezone === 'function') ? getBusinessTimezone() : 'America/La_Paz'; return new Date(order.timestamp).toLocaleDateString('es-BO', { timeZone: tz }); })()}</span>
                    <span>Hora: ${formatTime(order.timestamp)}</span>
                </div>
                <div class="ticket-meta-row" style="margin-top:2px;">
                    <span>Cliente/Ubicación: <strong>${escapeHtml(order.customer)}</strong></span>
                    <span>ID: ${order.id.slice(-6).toUpperCase()}</span>
                </div>
            </div>
            <div class="ticket-divider"></div>
            <table class="ticket-table">
                <thead>
                    <tr>
                        <th class="ticket-qty-col">Cant</th>
                        <th class="ticket-desc-col">Descripción</th>
                        <th class="ticket-total-col">Subt. (Bs)</th>
                    </tr>
                </thead>
                <tbody>
                    ${clientItemsRows}
                </tbody>
            </table>
            <div class="ticket-divider"></div>
            <div class="ticket-totals-section">
                <div class="ticket-total-row">
                    <span>Pago:</span>
                    <span><strong>${pMethod}</strong></span>
                </div>
                ${pm.efectivo > 0 && pm.qr > 0 ? `
                <div class="ticket-total-row" style="font-size:10px; color:#666;">
                    <span>  ↳ Efectivo:</span>
                    <span>Bs ${pm.efectivo.toFixed(2)}</span>
                </div>
                <div class="ticket-total-row" style="font-size:10px; color:#666;">
                    <span>  ↳ QR:</span>
                    <span>Bs ${pm.qr.toFixed(2)}</span>
                </div>
                ` : ''}
                ${pm.efectivo > 0 && pm.tarjeta > 0 ? `
                <div class="ticket-total-row" style="font-size:10px; color:#666;">
                    <span>  ↳ Efectivo:</span>
                    <span>Bs ${pm.efectivo.toFixed(2)}</span>
                </div>
                <div class="ticket-total-row" style="font-size:10px; color:#666;">
                    <span>  ↳ Tarjeta:</span>
                    <span>Bs ${pm.tarjeta.toFixed(2)}</span>
                </div>
                ` : ''}
                <div class="ticket-total-row">
                    <span>Subtotal:</span>
                    <span>Bs ${parseFloat(order.total).toFixed(2)}</span>
                </div>
                <div class="ticket-total-row grand-total">
                    <span>Total General:</span>
                    <span>Bs ${parseFloat(order.total).toFixed(2)}</span>
                </div>
            </div>
            <div class="ticket-footer">
                <div>¡Gracias por su visita y preferencia!</div>
                <div style="font-size:9px; margin-top:4px; color:#555;">${escapeHtml(bizName)} - Impreso de Sistema POS</div>
            </div>
        </div>
    `;

    const kitchenComandaHtml = `
        <!-- PART 2: KITCHEN COMANDA -->
        <div class="ticket-kitchen-copy">
            <div class="ticket-header">
                <div class="ticket-logo" style="font-size:16px;">${escapeHtml(bizName)} (COCINA)</div>
                <div class="ticket-divider"></div>
                <div style="font-weight:700; font-size:12px; margin-top:4px; letter-spacing:1px;">*** COMANDA DE PREPARACIÓN ***</div>
            </div>
            <div class="ticket-meta">
                <div class="ticket-meta-row">
                    <span>Fecha: ${(() => { const tz = (typeof getBusinessTimezone === 'function') ? getBusinessTimezone() : 'America/La_Paz'; return new Date(order.timestamp).toLocaleDateString('es-BO', { timeZone: tz }); })()}</span>
                    <span>Hora: ${formatTime(order.timestamp)}</span>
                </div>
                <div class="ticket-meta-row" style="margin-top:2px;">
                    <span>UBICACIÓN: <strong style="font-size: 14px; background:#000; color:#fff; padding: 0 4px;">${escapeHtml(order.customer)}</strong></span>
                    <span>ID: ${order.id.slice(-6).toUpperCase()}</span>
                </div>
            </div>
            <div class="ticket-divider"></div>
            <table class="ticket-table">
                <thead>
                    <tr>
                        <th class="ticket-qty-col">Cant</th>
                        <th class="ticket-desc-col">Descripción (Plato / Preparación)</th>
                        <th class="ticket-total-col">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    ${kitchenItemsRows}
                </tbody>
            </table>
            <div class="ticket-divider"></div>
            <div class="ticket-footer" style="margin-top:10px;">
                <div style="font-weight:bold; font-size:11px;">*** LLEVAR A COCINA ***</div>
            </div>
        </div>
    `;

    // 1. If order is completed/paid (status === 'completado'):
    // Print ONLY the Client Receipt (No kitchen comanda at payment time)
    if (!isPending) {
        return clientReceiptHtml;
    }

    // 2. If Table Comanda (Pending Table Order):
    // Print ONLY Kitchen Comanda
    if (isTableComandaOnly) {
        return kitchenComandaHtml;
    }

    // 3. If Pending Takeout / Delivery / Personalizado Order:
    // Print Full Combined Ticket (Client Receipt + Kitchen Comanda)
    return `
        ${clientReceiptHtml}
        <!-- SCISSORS DIVIDER -->
        <div class="ticket-scissors"></div>
        ${kitchenComandaHtml}
    `;
}

function openTicketModal(order, mode = 'auto') {
    console.log('[TICKET] openTicketModal called with order:', order ? order.id : 'null', order ? order.status : '', 'mode:', mode);
    const modal = document.getElementById('modal-ticket-preview');
    const content = document.getElementById('ticket-thermal-content');
    const printArea = document.getElementById('ticket-print-area');
    if (!modal || !content || !printArea) {
        console.error('[TICKET] Modal elements missing:', { modal: !!modal, content: !!content, printArea: !!printArea });
        showToast('Error: elementos del ticket no encontrados en el DOM.', 'error');
        return;
    }

    const orderSnapshot = { ...order };

    activeTicketPreviewOrder = orderSnapshot;
    activeTicketContext = {
        openedFromTab: typeof currentTabId !== 'undefined' ? currentTabId : null,
        orderId: orderSnapshot.id,
        status: orderSnapshot.status,
        mode: mode
    };

    let ticketHtml;
    try {
        if (window.TicketPrinter) {
            ticketHtml = window.TicketPrinter.generateHtml(orderSnapshot, mode);
        } else {
            ticketHtml = generateTicketHtml(orderSnapshot, mode);
        }
    } catch (e) {
        console.error('[TICKET] generateTicketHtml error:', e, orderSnapshot);
        ticketHtml = `<div style="padding:20px; color:red;">Error generando ticket: ${escapeHtml(e.message)}</div>`;
    }
    content.innerHTML = ticketHtml;
    printArea.innerHTML = ticketHtml;

    const actions = modal.querySelector('.modal-actions');
    if (actions) {
    actions.innerHTML = '';
    actions.className = 'modal-actions no-print ticket-modal-actions';

    const isPending = orderSnapshot.status === 'pendiente';
    const isPaid = orderSnapshot.paid === true;

    // Payment selector row (only for pending unpaid orders)
    let selectPaymentMethodEl = null;
    if (isPending && !isPaid) {
        const paymentRow = document.createElement('div');
        paymentRow.className = 'ticket-payment-row';
        paymentRow.innerHTML = `
            <label><i class="fa-solid fa-credit-card"></i> Pago:</label>
            <select id="modal-order-payment-select" class="form-select" style="padding: 7px 10px; font-size: 12px; width: 140px;">
                <option value="efectivo" selected>💵 Efectivo</option>
                <option value="qr">📱 QR / Transf.</option>
                <option value="tarjeta">💳 Tarjeta</option>
                <option value="mixto">🔀 Mixto</option>
            </select>
        `;
        actions.appendChild(paymentRow);
        selectPaymentMethodEl = paymentRow.querySelector('select');

        // Mixed payment inputs
        const mixedSection = document.createElement('div');
        mixedSection.className = 'ticket-mixed-inputs';
        mixedSection.id = 'modal-mixed-payment-section';
        mixedSection.innerHTML = `
            <div class="ticket-mixed-row">
                <label>💵 Efectivo:</label>
                <input type="number" id="modal-mixed-efectivo" class="form-input" min="0" step="0.50" placeholder="0.00" value="0" style="padding:6px 10px; font-size:12px; flex:1;">
            </div>
            <div class="ticket-mixed-row">
                <label>📱 QR:</label>
                <input type="number" id="modal-mixed-qr" class="form-input" min="0" step="0.50" placeholder="0.00" value="0" style="padding:6px 10px; font-size:12px; flex:1;">
            </div>
        `;
        actions.appendChild(mixedSection);

        selectPaymentMethodEl.addEventListener('change', (e) => {
            mixedSection.classList.toggle('visible', e.target.value === 'mixto');
            orderSnapshot.paymentMethod = e.target.value === 'mixto' ? { efectivo: 0, qr: 0 } : e.target.value;
            const updatedHtml = generateTicketHtml(orderSnapshot);
            content.innerHTML = updatedHtml;
            printArea.innerHTML = updatedHtml;
        });
    }

    // Buttons row
    const buttonsRow = document.createElement('div');
    buttonsRow.className = 'ticket-actions-row';

    const closeBtn = document.createElement('button');
    closeBtn.id = 'btn-close-ticket-preview';
    closeBtn.className = 'btn btn-outline btn-sm';
    closeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i> Cerrar';
    closeBtn.addEventListener('click', async () => {
        await closeModalTicket();
    });
    buttonsRow.appendChild(closeBtn);

    const printBtn = document.createElement('button');
    printBtn.id = 'btn-print-ticket-trigger';
    printBtn.className = 'btn btn-primary btn-sm';
    printBtn.innerHTML = '<i class="fa-solid fa-print"></i> Imprimir';
    printBtn.addEventListener('click', () => {
        const reportArea = document.getElementById('report-print-area');
        if (reportArea) reportArea.innerHTML = '';
        document.body.classList.remove('rc-report-printing');
        document.body.classList.add('rc-ticket-printing');
        window.print();
    });
    buttonsRow.appendChild(printBtn);

    if (isPending && !isPaid) {
        const payBtn = document.createElement('button');
        payBtn.className = 'btn btn-primary btn-sm';
        payBtn.innerHTML = '<i class="fa-solid fa-check-double"></i> Cobrar';
        payBtn.addEventListener('click', () => {
            const selectedMethod = selectPaymentMethodEl ? selectPaymentMethodEl.value : 'efectivo';
            let pMethod;
            if (selectedMethod === 'mixto') {
                const efectivo = parseFloat(document.getElementById('modal-mixed-efectivo').value) || 0;
                const qr = parseFloat(document.getElementById('modal-mixed-qr').value) || 0;
                const total = orderSnapshot.total || 0;
                if (Math.abs(efectivo + qr - total) > 0.01) {
                    showToast('La suma de pagos no coincide con el total.', 'warning');
                    return;
                }
                pMethod = { efectivo: efectivo, qr: qr };
            } else {
                pMethod = selectedMethod;
            }
            completeActiveOrder(orderSnapshot.id, pMethod);
        });
        buttonsRow.appendChild(payBtn);
    }

    actions.appendChild(buttonsRow);
    }

    try {
        openModal('modal-ticket-preview');
        console.log('[TICKET] Modal opened for order:', orderSnapshot.id);
        if (mode === 'kitchen') {
            setTimeout(() => {
                const reportArea = document.getElementById('report-print-area');
                if (reportArea) reportArea.innerHTML = '';
                document.body.classList.remove('rc-report-printing');
                document.body.classList.add('rc-ticket-printing');
                window.print();
            }, 500);
        }
    } catch (e) {
        console.error('[TICKET] openModal failed:', e);
        showToast('Error al abrir modal de ticket.', 'error');
    }
}

async function closeModalTicket() {
    closeModal('modal-ticket-preview');

    const ticketContext = activeTicketContext;
    activeTicketPreviewOrder = null;
    activeTicketContext = null;

    if (!ticketContext) return;

    await refreshTicketContext();
    if (ticketContext.status === 'completado') {
        switchTab('reports');
        return;
    }

    if (ticketContext.openedFromTab === 'active-orders' && currentTabId !== 'active-orders') {
        switchTab('active-orders');
    }
}

async function refreshTicketContext() {
    try {
        if (typeof currentTabId !== 'undefined' && typeof loadStateForTab === 'function' && currentTabId) {
            const loaded = await loadStateForTab(currentTabId);
            if (!loaded && typeof window.renderCurrentTab === 'function') {
                window.renderCurrentTab(currentTabId);
            }
            return;
        }

        if (typeof window.notifyStateChanged === 'function') {
            window.notifyStateChanged();
        }
    } catch (e) {
        console.error('Error refrescando contexto del ticket:', e);
    }
}

window.generateTicketHtml = generateTicketHtml;
window.openTicketModal = openTicketModal;
window.closeModalTicket = closeModalTicket;
window.findLatestOrderSnapshot = findLatestOrderSnapshot;

// ==========================================================================
// DRAG & DROP FOR ORDER PRIORITY
// ==========================================================================
let draggedOrderId = null;

function handleDragStart(e) {
    draggedOrderId = this.dataset.orderId;
    this.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', this.dataset.orderId);
}

function handleDragEnd(e) {
    this.classList.remove('dragging');
    document.querySelectorAll('.order-card').forEach(card => {
        card.classList.remove('drag-over');
    });
}

function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    this.classList.add('drag-over');
}

function handleDragLeave(e) {
    this.classList.remove('drag-over');
}

function handleDrop(e) {
    e.preventDefault();
    this.classList.remove('drag-over');
    
    const targetOrderId = this.dataset.orderId;
    if (draggedOrderId === targetOrderId) return;
    
    const draggedIndex = state.activeOrders.findIndex(o => o.id === draggedOrderId);
    const targetIndex = state.activeOrders.findIndex(o => o.id === targetOrderId);
    
    if (draggedIndex === -1 || targetIndex === -1) return;
    
    const draggedOrder = state.activeOrders[draggedIndex];
    state.activeOrders.splice(draggedIndex, 1);
    state.activeOrders.splice(targetIndex, 0, draggedOrder);
    
    if (window.AppStore) window.AppStore.emit();
    
    showToast('Orden de pedidos actualizada', 'info');
}

window.handleDragStart = handleDragStart;
window.handleDragEnd = handleDragEnd;
window.handleDragOver = handleDragOver;
window.handleDrop = handleDrop;
window.handleDragLeave = handleDragLeave;
