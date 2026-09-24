// ==========================================================================
// RESERVATIONS VIEW
// ==========================================================================

function renderReservations() {
    const dateInput = document.getElementById('reservations-date-picker');
    if (dateInput) {
        dateInput.value = state.reservationsDate || todayLocal();
    }

    populateReservationTableSelect();
    if (typeof window.populateReservationCustomerSelect === 'function') window.populateReservationCustomerSelect();
    renderReservationCatalog();

    const reservations = state.reservations || [];
    const filter = state.reservationFilter || 'activas';
    const isBotQueue = filter === 'bot';
    const eligibleBotIds = new Set(reservations
        .filter(r => r.source === 'bot' && ['pendiente', 'confirmada'].includes(r.status) && !r.kitchen_printed_at)
        .map(r => r.id));
    botReservationSelection.forEach(id => {
        if (!eligibleBotIds.has(id)) botReservationSelection.delete(id);
    });
    const filteredByStatus = filter === 'activas'
        ? reservations.filter(r => r.status === 'pendiente' || r.status === 'confirmada')
        : isBotQueue
            ? reservations.filter(r => r.source === 'bot' && (r.status === 'pendiente' || r.status === 'confirmada') && !r.kitchen_printed_at)
            : reservations.filter(r => r.status === 'completada' || r.status === 'cancelada');
    const searchQuery = (state.reservationSearch || '').toLowerCase();
    const filtered = searchQuery
        ? filteredByStatus.filter(r => (r.customer_name || '').toLowerCase().includes(searchQuery) || (r.phone || '').includes(searchQuery))
        : filteredByStatus;

    const tbody = document.getElementById('reservations-table-body');
    if (!tbody) return;
    const batchActions = document.getElementById('bot-reservations-batch-actions');
    const selectionCount = document.getElementById('bot-reservations-selection-count');
    const selectHeader = document.getElementById('bot-reservations-select-header');
    const verificationHeader = document.getElementById('bot-reservations-verification-header');
    if (batchActions) batchActions.style.display = isBotQueue ? 'flex' : 'none';
    if (selectHeader) selectHeader.style.display = isBotQueue ? '' : 'none';
    if (verificationHeader) verificationHeader.style.display = isBotQueue ? '' : 'none';
    if (selectionCount) selectionCount.textContent = `${botReservationSelection.size} seleccionada(s)`;
    tbody.innerHTML = '';

    if (filtered.length === 0) {
        const emptyMsg = filter === 'activas'
            ? (searchQuery ? 'No se encontraron reservas para esta búsqueda.' : 'No hay reservas activas para esta fecha.')
            : filter === 'bot'
                ? (searchQuery ? 'No se encontraron reservas del bot para esta búsqueda.' : 'No hay reservas del bot pendientes de verificación o impresión.')
                : (searchQuery ? 'No se encontraron reservas para esta búsqueda.' : 'No hay reservas entregadas para esta fecha.');
        tbody.innerHTML = `<tr><td colspan="11" class="text-muted" style="text-align:center; padding:32px;">${emptyMsg}</td></tr>`;
        updateReservationSummary(reservations);
        return;
    }

    filtered.forEach(res => {
        const tr = document.createElement('tr');
        tr.className = 'reservation-row status-' + res.status;

        const statusBadge = getReservationStatusBadge(res.status);
        const isBot = res.source === 'bot';
        const isPendingVerification = isBot && res.verification_status !== 'verificada';
        const canSelect = isBot && ['pendiente', 'confirmada'].includes(res.status) && !res.kitchen_printed_at;
        const verificationBadge = isPendingVerification
            ? '<span class="badge badge-warning" style="font-size:10px;">Pendiente</span>'
            : '<span class="badge badge-success" style="font-size:10px;">Verificada</span>';
        const timeStr = (res.reservation_time || '').substring(0, 5);
        const deliveryType = res.delivery_type || 'para_servirse';
        const deliveryBadge = deliveryType === 'para_llevar'
            ? '<span class="badge badge-warning" style="font-size:10px;">🥡 Llevar</span>'
            : '<span class="badge badge-info" style="font-size:10px;">🍽️ Servirse</span>';
        const items = res.items || [];
        const itemsSummary = items.length > 0
            ? items.map(it => `${it.quantity || 1}x ${escapeHtml(it.name)}`).join(', ')
            : '<span style="color:var(--text-muted);">Sin pedido</span>';

        tr.innerHTML = `
            ${isBotQueue ? `<td style="text-align:center;"><input type="checkbox" ${canSelect && botReservationSelection.has(res.id) ? 'checked' : ''} ${canSelect ? '' : 'disabled'} onchange="toggleBotReservationSelection('${res.id}', this.checked)" aria-label="Seleccionar reserva de ${escapeHtml(res.customer_name)}"></td>` : ''}
            <td><strong>${escapeHtml(res.customer_name)}</strong>${res.phone ? '<br><small style="color:var(--text-muted);">' + escapeHtml(res.phone) + '</small>' : ''}</td>
            <td style="text-align:center;">${timeStr}</td>
            <td style="text-align:center;"><span class="badge badge-info">${res.party_size}</span></td>
            <td style="text-align:center;">${deliveryBadge}</td>
            <td style="font-size:12px;">${res.table_id ? '<i class="fa-solid fa-chair" style="color:var(--info);"></i> ' + escapeHtml(getTableName(res.table_id)) : (deliveryType === 'para_servirse' ? '<span style="color:var(--warning);">Sin mesa</span>' : '<span style="color:var(--text-muted);">—</span>')}</td>
            <td style="font-size:12px; max-width:200px;">${itemsSummary}</td>
            <td style="text-align:right; font-weight:600;">${formatCurrency(res.total || 0)}</td>
            <td style="text-align:center;">${statusBadge}</td>
            ${isBotQueue ? `<td style="text-align:center;">${verificationBadge}</td>` : ''}
            <td style="text-align:center;">
                <div class="reservation-actions">
                    ${res.status === 'pendiente' && !isPendingVerification ? `
                        <button class="btn btn-sm btn-success" onclick="confirmReservation('${res.id}')" title="${res.delivery_type === 'para_servirse' ? 'Confirmar y asignar mesa' : 'Cobrar reserva'}">
                            <i class="fa-solid fa-${res.delivery_type === 'para_servirse' ? 'chair' : 'cash-register'}"></i>
                        </button>
                    ` : ''}
                    ${res.status !== 'cancelada' && res.status !== 'completada' ? `
                        <button class="btn btn-sm btn-outline" onclick="cancelReservation('${res.id}')" title="Cancelar">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    ` : ''}
                    <button class="btn btn-sm btn-outline" onclick="editReservation('${res.id}')" title="Editar">
                        <i class="fa-solid fa-pen"></i>
                    </button>
                    ${res.status !== 'completada' ? `
                        <button class="btn btn-sm btn-danger-outline" onclick="deleteReservation('${res.id}')" title="Eliminar">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    ` : ''}
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });

    updateReservationSummary(reservations);
}

function populateReservationTableSelect() {
    const select = document.getElementById('reservation-form-table');
    if (!select) return;

    const currentVal = select.value;
    select.innerHTML = '<option value="">Sin asignar</option>';

    const tables = state.tables || [];
    tables.filter(t => Number(t.active) !== 0).forEach(table => {
        const opt = document.createElement('option');
        opt.value = table.id;
        opt.textContent = table.name;
        select.appendChild(opt);
    });

    if (currentVal && select.querySelector(`option[value="${currentVal}"]`)) {
        select.value = currentVal;
    }
}

function renderReservationCatalog() {
    const container = document.getElementById('reservation-catalog-cards');
    if (!container) return;

    const tab = state.reservationCatalogTab || 'meals';
    const query = (state.reservationCatalogSearch || '').trim().toLowerCase();

    document.querySelectorAll('.reservation-catalog-tab').forEach(button => {
        button.classList.toggle('active', button.dataset.resCatalogTab === tab);
    });

    if (tab === 'extras') {
        renderReservationPlatosExtrasCatalog(container, query);
        return;
    }
    if (tab === 'drinks') {
        renderReservationDrinksCatalog(container, query);
        return;
    }
    renderReservationMealsCatalog(container, query);
}

function buildReservationOptions(items, getStockFn, placeholder, filterText = '') {
    const activeItems = (items || [])
        .filter(item => Number(item.active) !== 0)
        .filter(item => !filterText || (item.name || '').toLowerCase().includes(filterText));

    if (activeItems.length === 0) {
        return `<option value="">-- Sin productos disponibles --</option>`;
    }

    return `<option value="">${placeholder}</option>` + activeItems.map(item => {
        const stock = getStockFn(item.id);
        const disabled = stock <= 0 ? 'disabled' : '';
        const stockLabel = stock > 0 && stock <= 5 ? `Stock bajo: ${stock}` : `Stock: ${stock}`;
        return `<option value="${item.id}" ${disabled}>${escapeHtml(item.name)} (${stockLabel})</option>`;
    }).join('');
}

function getReservationAvailableStock(type, itemId) {
    const cart = state.reservationCart || [];
    let baseStock = 0;
    let reservedQty = 0;

    if (type === 'sopa') {
        baseStock = getAvailableSopaStock(itemId);
        reservedQty = countSopaUsage(cart, itemId);
    } else if (type === 'segundo') {
        baseStock = getAvailableSegundoStock(itemId);
        reservedQty = countSegundoUsage(cart, itemId);
    } else if (type === 'plato_extra') {
        baseStock = getAvailablePlatoExtraStock(itemId);
        reservedQty = countPlatoExtraUsage(cart, itemId);
    } else if (type === 'extra') {
        baseStock = getAvailableExtraStock(itemId);
        reservedQty = countExtraUsage(cart, itemId);
    }

    return Math.max(0, baseStock - reservedQty);
}

function getReservationStockLabel(stock) {
    if (stock <= 0) return '<span class="reservation-stock-chip danger">Agotado</span>';
    if (stock <= 5) return `<span class="reservation-stock-chip warning">Stock ${stock}</span>`;
    return `<span class="reservation-stock-chip">Stock ${stock}</span>`;
}

function getReservationQtyControl(inputId) {
    return `
        <label class="reservation-card-qty" for="${inputId}">
            <span>Cant.</span>
            <input type="number" class="card-qty-input-top" id="${inputId}" min="1" max="99" value="1">
        </label>
    `;
}

function getReservationProductCard({ title, description, price, stock, icon, tone = '', inputId, buttonClass = 'btn-primary', buttonOnclick, buttonText = 'Agregar', optionsHtml = '', tag = '' }) {
    const isOut = typeof stock === 'number' && stock <= 0;
    return `
        <div class="reservation-catalog-card ${isOut ? 'disabled-by-stock' : ''}">
            <div class="reservation-card-main">
                <div class="card-icon-wrapper ${tone}"><i class="fa-solid ${icon}"></i></div>
                <div class="reservation-card-info">
                    <div class="reservation-card-title-row">
                        <h4>${escapeHtml(title)}</h4>
                        ${tag ? `<span class="reservation-card-tag">${escapeHtml(tag)}</span>` : ''}
                    </div>
                    <p class="card-description">${escapeHtml(description || '')}</p>
                    <div class="reservation-card-meta">
                        <strong>${formatCurrency(price || 0)}</strong>
                        ${typeof stock === 'number' ? getReservationStockLabel(stock) : ''}
                    </div>
                </div>
            </div>
            ${optionsHtml ? `<div class="reservation-card-options">${optionsHtml}</div>` : ''}
            <div class="reservation-card-actions">
                ${getReservationQtyControl(inputId)}
                <button type="button" class="btn ${buttonClass} btn-add-cart" onclick="${buttonOnclick}" ${isOut ? 'disabled' : ''}>
                    <i class="fa-solid fa-plus"></i> ${buttonText}
                </button>
            </div>
        </div>
    `;
}

function renderReservationMealsCatalog(container, query) {
    const activeSopas = (state.sopas || []).filter(s => Number(s.active) !== 0);
    const activeSeconds = (state.seconds || []).filter(s => Number(s.active) !== 0);
    const visibleSopas = activeSopas.filter(s => !query || (s.name || '').toLowerCase().includes(query));
    const visibleSeconds = activeSeconds.filter(s => !query || (s.name || '').toLowerCase().includes(query));
    const mealMatches = !query || 'almuerzo completo'.includes(query) || visibleSopas.length > 0 || visibleSeconds.length > 0;
    const segundoMatches = !query || 'segundo suelto'.includes(query) || visibleSeconds.length > 0;
    const sopaMatches = !query || 'sopa suelta'.includes(query) || visibleSopas.length > 0;

    const sopaOptions = buildReservationOptions(activeSopas, id => getReservationAvailableStock('sopa', id), '-- Seleccionar sopa --');
    const segundoOptions = buildReservationOptions(activeSeconds, id => getReservationAvailableStock('segundo', id), '-- Seleccionar segundo --');
    
    const sopaStockTotal = activeSopas.reduce((sum, s) => sum + getReservationAvailableStock('sopa', s.id), 0);
    const segundoStockTotal = activeSeconds.reduce((sum, s) => sum + getReservationAvailableStock('segundo', s.id), 0);
    const almuerzoStock = activeSopas.length > 0 ? Math.min(sopaStockTotal, segundoStockTotal) : segundoStockTotal;

    const cards = [];
    if (mealMatches) {
        cards.push(getReservationProductCard({
            title: 'Almuerzo Completo',
            description: 'Sopa + segundo a elección.',
            price: state.prices?.almuerzo || 15,
            stock: almuerzoStock,
            icon: 'fa-bowl-food',
            inputId: 'res-qty-almuerzo',
            tag: 'Combo',
            optionsHtml: `
                <div class="option-group"><label>Sopa</label><select id="res-select-sopa-almuerzo" class="form-select">${sopaOptions}</select></div>
                <div class="option-group"><label>Segundo</label><select id="res-select-segundo-almuerzo" class="form-select">${segundoOptions}</select></div>
            `,
            buttonOnclick: "addReservationMealToCart('almuerzo', document.getElementById('res-qty-almuerzo')?.value)",
            buttonClass: 'btn-primary'
        }));
    }
    if (segundoMatches) {
        cards.push(getReservationProductCard({
            title: 'Segundo Suelto',
            description: 'Plato de segundo individual.',
            price: state.prices?.segundo || 12,
            stock: segundoStockTotal,
            icon: 'fa-plate-wheat',
            tone: 'secondary-color',
            inputId: 'res-qty-segundo',
            optionsHtml: `<div class="option-group"><label>Segundo</label><select id="res-select-segundo-suelto" class="form-select">${segundoOptions}</select></div>`,
            buttonOnclick: "addReservationMealToCart('segundo', document.getElementById('res-qty-segundo')?.value)",
            buttonClass: 'btn-secondary'
        }));
    }
    if (sopaMatches) {
        cards.push(getReservationProductCard({
            title: 'Sopa Suelta',
            description: 'Sopa servida individualmente.',
            price: state.prices?.sopa || 6,
            stock: sopaStockTotal,
            icon: 'fa-bowl-hot',
            tone: 'accent-color',
            inputId: 'res-qty-sopa',
            optionsHtml: `<div class="option-group"><label>Sopa</label><select id="res-select-sopa-suelta" class="form-select">${sopaOptions}</select></div>`,
            buttonOnclick: "addReservationMealToCart('sopa', document.getElementById('res-qty-sopa')?.value)",
            buttonClass: 'btn-accent'
        }));
    }

    container.innerHTML = cards.length > 0 ? cards.join('') : getReservationCatalogEmptyHtml('No hay almuerzos que coincidan con la búsqueda.');
}

function renderReservationPlatosExtrasCatalog(container, query) {
    const platos = (state.platosExtras || [])
        .filter(p => Number(p.active) !== 0)
        .filter(p => !query || (p.name || '').toLowerCase().includes(query));

    if (platos.length === 0) {
        container.innerHTML = getReservationCatalogEmptyHtml('No hay platos extras disponibles.');
        return;
    }

    container.innerHTML = platos.map(plato => {
        const stock = getReservationAvailableStock('plato_extra', plato.id);
        return getReservationProductCard({
            title: plato.name,
            description: plato.accepts_salsa ? 'Plato extra con opción de salsa.' : 'Plato extra individual.',
            price: plato.price || 0,
            stock,
            icon: 'fa-utensils',
            tone: 'secondary-color',
            inputId: `res-qty-plato-${plato.id}`,
            tag: plato.accepts_salsa ? 'Salsa' : '',
            buttonOnclick: `addReservationPlatoExtraToCart('${plato.id}', document.getElementById('res-qty-plato-${plato.id}')?.value)`,
            buttonClass: 'btn-secondary'
        });
    }).join('');
}

function renderReservationDrinksCatalog(container, query) {
    const drinks = (state.extras || [])
        .filter(e => Number(e.active) !== 0)
        .filter(e => !query || (e.name || '').toLowerCase().includes(query));

    if (drinks.length === 0) {
        container.innerHTML = getReservationCatalogEmptyHtml('No hay bebidas disponibles.');
        return;
    }

    container.innerHTML = drinks.map(extra => {
        const stock = getReservationAvailableStock('extra', extra.id);
        return getReservationProductCard({
            title: extra.name,
            description: 'Bebida o refresco para la reserva.',
            price: extra.price || 0,
            stock,
            icon: 'fa-bottle-water',
            tone: 'accent-color',
            inputId: `res-qty-extra-${extra.id}`,
            buttonOnclick: `addReservationExtraToCart('${extra.id}', document.getElementById('res-qty-extra-${extra.id}')?.value)`,
            buttonClass: 'btn-accent'
        });
    }).join('');
}

function getReservationCatalogEmptyHtml(message) {
    return `
        <div class="reservation-catalog-empty">
            <i class="fa-solid fa-box-open"></i>
            <p>${escapeHtml(message)}</p>
        </div>
    `;
}

function getReservationStatusBadge(status) {
    const map = {
        'pendiente': '<span class="badge badge-warning">Pendiente</span>',
        'confirmada': '<span class="badge badge-success">Confirmada</span>',
        'cancelada': '<span class="badge badge-danger">Cancelada</span>',
        'completada': '<span class="badge badge-info">Completada</span>'
    };
    return map[status] || '<span class="badge badge-secondary">Desconocido</span>';
}

function updateReservationSummary(reservations) {
    const total = reservations.length;
    const pending = reservations.filter(r => r.status === 'pendiente').length;
    const confirmed = reservations.filter(r => r.status === 'confirmada').length;
    const totalPeople = reservations
        .filter(r => r.status === 'pendiente' || r.status === 'confirmada')
        .reduce((sum, r) => sum + r.party_size, 0);

    const summaryEl = document.getElementById('reservations-summary');
    if (summaryEl) {
        summaryEl.innerHTML = `
            <span class="summary-item"><strong>${total}</strong> reservas</span>
            <span class="summary-item"><span style="color:var(--warning);">${pending}</span> pendientes</span>
            <span class="summary-item"><span style="color:var(--success);">${confirmed}</span> confirmadas</span>
            <span class="summary-item"><strong>${totalPeople}</strong> personas</span>
        `;
    }
}

// ==========================================================================
// RESERVATION CART (mini POS inside the form)
// ==========================================================================

function renderReservationCart() {
    const container = document.getElementById('reservation-cart-items');
    const totalEl = document.getElementById('reservation-cart-total-value');
    if (!container) return;

    const cart = state.reservationCart || [];
    container.innerHTML = '';

    if (cart.length === 0) {
        container.innerHTML = '<p class="text-muted" style="text-align:center; padding:20px; font-size:12px;">Agrega items del menú al pedido</p>';
        if (totalEl) totalEl.textContent = formatCurrency(0);
        return;
    }

    cart.forEach((item, idx) => {
        const div = document.createElement('div');
        div.className = 'reservation-cart-item';
        const detail = item.detail ? `<small style="color:var(--text-muted);">${escapeHtml(item.detail)}</small>` : '';
        const salsaInfo = item.salsaName
            ? `<small style="color:var(--primary);"> + ${escapeHtml(item.salsaName)} (${item.salsaMode === 'banar' ? 'Bañar' : 'A parte'})${item.salsaPrice > 0 ? ' +' + formatCurrency(item.salsaPrice) : ''}</small>`
            : '';
        const lineTotal = ((item.price || 0) + (item.salsaPrice || 0)) * (item.quantity || 1);
        div.innerHTML = `
            <div class="res-cart-item-info">
                <strong>${item.quantity || 1}x ${escapeHtml(item.name)}</strong>
                ${detail}${salsaInfo}
            </div>
            <div class="res-cart-item-right">
                <span style="font-weight:600;">${formatCurrency(lineTotal)}</span>
                <button type="button" class="btn btn-sm btn-danger-outline" onclick="removeReservationItem(${idx})" title="Quitar">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        `;
        container.appendChild(div);
    });

    const total = cart.reduce((sum, it) => sum + ((it.price || 0) + (it.salsaPrice || 0)) * (it.quantity || 1), 0);
    if (totalEl) totalEl.textContent = formatCurrency(total);
}
