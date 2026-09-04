// ==========================================================================
// RESERVATIONS CONTROLLER
// ==========================================================================

function changeReservationsDate(dateStr) {
    state.reservationsDate = dateStr;
    if (typeof window.saveUiContext === 'function') window.saveUiContext({ reservationsDate: dateStr });
    loadReservationsForDate(dateStr);
}

function searchReservations(query) {
    state.reservationSearch = query;
    if (typeof window.saveUiContext === 'function') window.saveUiContext({ reservationSearch: query });
    renderReservations();
}

function filterReservations(filter) {
    state.reservationFilter = filter;
    if (typeof window.saveUiContext === 'function') window.saveUiContext({ reservationFilter: filter });

    document.querySelectorAll('.reservation-subtab').forEach(tab => tab.classList.remove('active'));
    const activeTab = document.getElementById('res-subtab-' + filter);
    if (activeTab) activeTab.classList.add('active');

    renderReservations();
}

function onReservationTypeChange() {
    const type = document.getElementById('reservation-form-type').value;
    const tableGroup = document.getElementById('reservation-form-table-group');
    if (tableGroup) {
        tableGroup.style.display = type === 'para_servirse' ? '' : 'none';
    }
    if (type !== 'para_servirse') {
        document.getElementById('reservation-form-table').value = '';
    }
}

async function loadReservationsForDate(dateStr) {
    try {
        const data = await AppApi.getReservations(dateStr);
        if (data.status === 'success') {
            state.reservations = data.reservations || [];
            renderReservations();
        }
    } catch (e) {
        showToast('Error al cargar reservas.', 'error');
    }
}

function validateReservationItems(items) {
    const invalid = [];
    const catalogMap = {
        'segundo': state.seconds || [],
        'sopa': state.sopas || [],
        'plato_extra': state.platosExtras || [],
        'extra': state.extras || [],
        'salsa': state.salsas || []
    };

    for (const item of items) {
        const type = item.type || '';
        const id = item.id || item.product_id || '';

        if (type === 'almuerzo') {
            if (!item.sopaId || !item.segundoId) {
                invalid.push(item.name || 'Almuerzo incompleto');
            }
            continue;
        }

        const catalog = catalogMap[type];
        if (!catalog || !id) {
            invalid.push(item.name || '(sin nombre)');
            continue;
        }
        const found = catalog.find(c => c.id === id && Number(c.active) !== 0);
        if (!found) {
            invalid.push(item.name || id);
        }
    }
    return invalid;
}

function sanitizeReservationDetail(value) {
    const detail = String(value || '').trim();
    return ['servirse', 'llevar', 'mesa'].includes(detail.toLowerCase()) ? '' : detail;
}

function buildReservationOrder(reservation, overrides = {}) {
    const isDineIn = reservation.delivery_type === 'para_servirse';
    const tableName = reservation.table_id ? getTableName(reservation.table_id) : 'Sin mesa';
    const deliveryType = isDineIn ? 'mesa' : 'llevar';
    const fallbackServiceType = isDineIn ? 'servirse' : 'llevar';
    const items = (reservation.items || []).map(it => {
        return {
        type: it.type || 'segundo',
        name: it.name || '',
        quantity: it.quantity || it.qty || 1,
        price: it.price || 0,
        id: it.id || it.product_id || '',
        sopaId: it.sopaId || null,
        sopaName: it.sopaName || null,
        segundoId: it.segundoId || null,
        segundoName: it.segundoName || null,
        platoId: it.platoId || null,
        extraId: it.extraId || null,
        salsas: it.salsas || [],
        // Older reservations stored the service mode as a preparation note.
        detail: sanitizeReservationDetail(it.detail),
        serviceType: it.serviceType || fallbackServiceType
        };
    });

    return {
        id: reservation.id || generateId(),
        customer: isDineIn
            ? `${tableName} - ${reservation.customer_name}`
            : `${reservation.customer_name} (Reserva - Llevar)`,
        deliveryType,
        customerId: reservation.customer_id || null,
        items,
        total: reservation.total || 0,
        status: 'pendiente',
        notes: reservation.notes || '',
        reservationTime: reservation.reservation_time || '',
        reservationDate: reservation.reservation_date || '',
        timestamp: reservation.created_at || nowLocal(),
        ...overrides
    };
}

async function confirmReservation(id) {
    const res = (state.reservations || []).find(r => r.id === id);
    if (!res) return;

    if (!res.items || res.items.length === 0) {
        showToast('La reserva no tiene items para enviar a comanda.', 'warning');
        return;
    }

    const invalidItems = validateReservationItems(res.items);
    if (invalidItems.length > 0) {
        showToast(`Productos no encontrados o inactivos: ${invalidItems.join(', ')}. Edite la reserva antes de confirmar.`, 'error');
        return;
    }

    if (res.delivery_type === 'para_servirse') {
        await confirmReservationForMesa(res);
    } else {
        openReservationPaymentModal(res.id);
    }
}

async function confirmReservationForMesa(res) {
    if (!res.table_id) {
        openReservationTableModal(res.id);
        return;
    }

    const tableName = getTableName(res.table_id);
    const occupiedTables = new Set(
        (state.activeOrders || [])
            .filter(o => o.status === 'pendiente' && o.deliveryType === 'mesa')
            .map(o => {
                const cust = o.customer || '';
                const dashIdx = cust.indexOf(' - ');
                return dashIdx > -1 ? cust.substring(0, dashIdx) : cust;
            })
    );
    if (occupiedTables.has(tableName)) {
        showToast(`La mesa "${tableName}" ya está ocupada. Seleccione otra mesa.`, 'error');
        return;
    }

    const confirmed = await window.ConfirmDialog.show(
        `¿Confirmar reserva de ${res.customer_name} en mesa "${tableName}"?`,
        { title: 'Confirmar Reserva', confirmText: 'Sí, enviar a comanda', type: 'success' }
    );
    if (!confirmed) return;

    try {
        const newOrder = buildReservationOrder(res, { id: generateId() });

        const hasSoup = newOrder.items.some(i => i.type === 'almuerzo' || i.type === 'sopa');
        const initialServiceState = hasSoup ? 'esperando_sopa' : 'esperando_segundo';
        newOrder.paymentMethod = 'efectivo';
        newOrder.serviceState = initialServiceState;

        const orderData = await AppApi.request('save_order', newOrder);
        if (orderData.status !== 'success') {
            showToast('Error al crear pedido en comanda.', 'error');
            return;
        }

        const statusData = await AppApi.updateReservationStatus(res.id, 'completada');
        if (statusData.status !== 'success') {
            showToast('Pedido creado pero error al actualizar reserva.', 'warning');
        }

        if (typeof window.upsertActiveOrder === 'function') {
            window.upsertActiveOrder(newOrder);
        }

        Notifications.notify(`📋 Reserva confirmada: ${res.customer_name} → Mesa ${tableName}`, 'success');
        showToast(`Reserva enviada a comanda. Mesa ${tableName} asignada.`, 'success');
        await loadReservationsForDate(state.reservationsDate);

        try {
            if (window.TicketPrinter) {
                window.TicketPrinter.printKitchen(newOrder);
            } else {
                window.openTicketModal(newOrder, 'kitchen');
            }
        } catch (e) {
            console.error('[RESERVATIONS] Error abriendo ticket de cocina:', e);
        }
    } catch (e) {
        showToast('Error al confirmar reserva.', 'error');
    }
}

async function cancelReservation(id) {
    const confirmed = await window.ConfirmDialog.show(
        '¿Cancelar esta reserva?',
        { title: 'Cancelar Reserva', confirmText: 'Sí, cancelar', type: 'warning' }
    );
    if (!confirmed) return;
    try {
        const data = await AppApi.updateReservationStatus(id, 'cancelada');
        if (data.status === 'success') {
            showToast('Reserva cancelada.', 'info');
            await loadReservationsForDate(state.reservationsDate);
        }
    } catch (e) {
        showToast('Error al cancelar reserva.', 'error');
    }
}

async function deleteReservation(id) {
    const confirmed = await window.ConfirmDialog.show(
        '¿Eliminar esta reserva permanentemente?',
        { title: 'Eliminar Reserva', confirmText: 'Sí, eliminar', type: 'danger' }
    );
    if (!confirmed) return;
    try {
        const data = await AppApi.deleteReservation(id);
        if (data.status === 'success') {
            showToast('Reserva eliminada.', 'info');
            await loadReservationsForDate(state.reservationsDate);
        }
    } catch (e) {
        showToast('Error al eliminar reserva.', 'error');
    }
}

function editReservation(id) {
    const res = (state.reservations || []).find(r => r.id === id);
    if (!res) return;

    document.getElementById('reservation-form-id').value = res.id;
    document.getElementById('reservation-form-name').value = res.customer_name;
    document.getElementById('reservation-form-phone').value = res.phone || '';
    document.getElementById('reservation-form-customer-id').value = res.customer_id || '';
    document.getElementById('reservation-form-party').value = res.party_size;
    document.getElementById('reservation-form-type').value = res.delivery_type || 'para_servirse';
    document.getElementById('reservation-form-date').value = res.reservation_date;
    document.getElementById('reservation-form-time').value = (res.reservation_time || '').substring(0, 5);
    document.getElementById('reservation-form-table').value = res.table_id || '';
    document.getElementById('reservation-form-notes').value = res.notes || '';

    const tableGroup = document.getElementById('reservation-form-table-group');
    if (tableGroup) {
        tableGroup.style.display = (res.delivery_type === 'para_servirse') ? '' : 'none';
    }

    state.reservationCart = (res.items || []).map(it => ({
        type: it.type || 'segundo',
        id: it.id || '',
        name: it.name || '',
        price: it.price || 0,
        quantity: it.quantity || 1,
        detail: sanitizeReservationDetail(it.detail),
        sopaId: it.sopaId || null,
        sopaName: it.sopaName || null,
        segundoId: it.segundoId || null,
        segundoName: it.segundoName || null,
        platoId: it.platoId || null,
        extraId: it.extraId || null,
        salsas: it.salsas || [],
        serviceType: it.serviceType || (res.delivery_type === 'para_llevar' ? 'llevar' : 'servirse')
    }));
    renderReservationCart();
    renderReservationCatalog();

    const formTitle = document.getElementById('reservation-form-title');
    if (formTitle) formTitle.textContent = 'Editar Reserva';

    const formSection = document.getElementById('reservation-form-section');
    if (formSection) formSection.style.display = 'block';
}

function resetReservationForm() {
    document.getElementById('reservation-form-id').value = '';
    document.getElementById('reservation-form-name').value = '';
    document.getElementById('reservation-form-phone').value = '';
    document.getElementById('reservation-form-customer-id').value = '';
    document.getElementById('reservation-form-party').value = 1;
    document.getElementById('reservation-form-type').value = 'para_servirse';
    document.getElementById('reservation-form-date').value = state.reservationsDate || nowLocal().slice(0, 10);
    document.getElementById('reservation-form-time').value = '';
    document.getElementById('reservation-form-table').value = '';
    document.getElementById('reservation-form-notes').value = '';

    const tableGroup = document.getElementById('reservation-form-table-group');
    if (tableGroup) tableGroup.style.display = '';

    state.reservationCart = [];
    renderReservationCart();
    state.reservationCatalogTab = state.reservationCatalogTab || 'meals';
    state.reservationCatalogSearch = '';
    const catalogSearch = document.getElementById('reservation-catalog-search');
    if (catalogSearch) catalogSearch.value = '';
    renderReservationCatalog();

    const formTitle = document.getElementById('reservation-form-title');
    if (formTitle) formTitle.textContent = 'Nueva Reserva';

    const formSection = document.getElementById('reservation-form-section');
    if (formSection) formSection.style.display = 'none';
}

function toggleReservationForm() {
    const formSection = document.getElementById('reservation-form-section');
    if (!formSection) return;

    if (formSection.style.display === 'none' || formSection.style.display === '') {
        resetReservationForm();
        state.reservationCart = [];
        renderReservationCart();
        renderReservationCatalog();
        formSection.style.display = 'block';
    } else {
        formSection.style.display = 'none';
    }
}

// ==========================================================================
// RESERVATION CART OPERATIONS
// ==========================================================================

function switchReservationCatalogTab(tab) {
    const validTabs = ['meals', 'extras', 'drinks'];
    state.reservationCatalogTab = validTabs.includes(tab) ? tab : 'meals';
    if (typeof window.saveUiContext === 'function') {
        window.saveUiContext({ reservationCatalogTab: state.reservationCatalogTab });
    }
    renderReservationCatalog();
    document.querySelectorAll('.reservation-catalog-tab').forEach(btn => {
        if (btn.dataset.resCatalogTab === state.reservationCatalogTab) {
            try {
                btn.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            } catch (e) {}
        }
    });
}

function setReservationCatalogSearch(query) {
    state.reservationCatalogSearch = query || '';
    if (typeof window.saveUiContext === 'function') {
        window.saveUiContext({ reservationCatalogSearch: state.reservationCatalogSearch });
    }
    renderReservationCatalog();
}

function normalizeReservationQty(value) {
    return Math.max(1, Math.min(99, parseInt(value, 10) || 1));
}

function getReservationItemServiceType() {
    const deliveryType = document.getElementById('reservation-form-type')?.value || 'para_servirse';
    return deliveryType === 'para_servirse' ? 'servirse' : 'llevar';
}

function appendReservationCartItem(itemData, successMessage) {
    if (!state.reservationCart) state.reservationCart = [];
    state.reservationCart.push(itemData);
    renderReservationCart();
    renderReservationCatalog();
    if (successMessage) showToast(successMessage, 'success');
}

function addReservationMealToCart(type, qtyValue = 1) {
    const qty = normalizeReservationQty(qtyValue);

    if (type === 'almuerzo') {
        const sopaId = document.getElementById('res-select-sopa-almuerzo')?.value || '';
        const segundoId = document.getElementById('res-select-segundo-almuerzo')?.value || '';
        if (!sopaId || !segundoId) {
            showToast('Selecciona sopa y segundo para el almuerzo.', 'warning');
            return;
        }

        const sopaAvail = getReservationAvailableStock('sopa', sopaId);
        const segundoAvail = getReservationAvailableStock('segundo', segundoId);
        if (sopaAvail < qty) { showToast(`Stock insuficiente de sopa. Disponible: ${sopaAvail}`, 'error'); return; }
        if (segundoAvail < qty) { showToast(`Stock insuficiente de segundo. Disponible: ${segundoAvail}`, 'error'); return; }

        const sopa = (state.sopas || []).find(s => s.id === sopaId);
        const segundo = (state.seconds || []).find(s => s.id === segundoId);
        const itemData = {
            type: 'almuerzo',
            id: generateId(),
            name: 'Almuerzo Completo',
            price: state.prices?.almuerzo || 15,
            quantity: qty,
            detail: '',
            serviceType: getReservationItemServiceType(),
            sopaId,
            sopaName: sopa?.name || '',
            segundoId,
            segundoName: segundo?.name || ''
        };

        const acceptsSalsa = (sopa && sopa.accepts_salsa) || (segundo && segundo.accepts_salsa);
        if (acceptsSalsa) {
            openSalsaSelectModal({ name: 'Almuerzo Completo' }, salsas => {
                if (salsas) itemData.salsas = salsas;
                appendReservationCartItem(itemData, `${qty} almuerzo${qty !== 1 ? 's' : ''} agregado(s) a la reserva.`);
            });
            return;
        }
        appendReservationCartItem(itemData, `${qty} almuerzo${qty !== 1 ? 's' : ''} agregado(s) a la reserva.`);
        return;
    }

    if (type === 'segundo') {
        const segundoId = document.getElementById('res-select-segundo-suelto')?.value || '';
        if (!segundoId) { showToast('Selecciona un segundo.', 'warning'); return; }
        const stock = getReservationAvailableStock('segundo', segundoId);
        if (stock < qty) { showToast(`Stock insuficiente. Disponible: ${stock}`, 'error'); return; }

        const segundo = (state.seconds || []).find(s => s.id === segundoId);
        const itemData = {
            type: 'segundo',
            id: segundoId,
            name: segundo?.name || 'Segundo Suelto',
            price: state.prices?.segundo || 12,
            quantity: qty,
            detail: '',
            serviceType: getReservationItemServiceType(),
            segundoId,
            segundoName: segundo?.name || ''
        };

        if (segundo && segundo.accepts_salsa) {
            openSalsaSelectModal({ name: segundo.name }, salsas => {
                if (salsas) itemData.salsas = salsas;
                appendReservationCartItem(itemData, `${qty} segundo${qty !== 1 ? 's' : ''} agregado(s) a la reserva.`);
            });
            return;
        }
        appendReservationCartItem(itemData, `${qty} segundo${qty !== 1 ? 's' : ''} agregado(s) a la reserva.`);
        return;
    }

    if (type === 'sopa') {
        const sopaId = document.getElementById('res-select-sopa-suelta')?.value || '';
        if (!sopaId) { showToast('Selecciona una sopa.', 'warning'); return; }
        const stock = getReservationAvailableStock('sopa', sopaId);
        if (stock < qty) { showToast(`Stock insuficiente. Disponible: ${stock}`, 'error'); return; }

        const sopa = (state.sopas || []).find(s => s.id === sopaId);
        const itemData = {
            type: 'sopa',
            id: sopaId,
            name: sopa?.name || 'Sopa',
            price: state.prices?.sopa || 6,
            quantity: qty,
            detail: '',
            serviceType: getReservationItemServiceType(),
            sopaId,
            sopaName: sopa?.name || ''
        };

        if (sopa && sopa.accepts_salsa) {
            openSalsaSelectModal({ name: sopa.name }, salsas => {
                if (salsas) itemData.salsas = salsas;
                appendReservationCartItem(itemData, `${qty} sopa${qty !== 1 ? 's' : ''} agregada(s) a la reserva.`);
            });
            return;
        }
        appendReservationCartItem(itemData, `${qty} sopa${qty !== 1 ? 's' : ''} agregada(s) a la reserva.`);
    }
}

function addReservationPlatoExtraToCart(platoId, qtyValue = 1) {
    const qty = normalizeReservationQty(qtyValue);
    const plato = (state.platosExtras || []).find(p => p.id === platoId);
    if (!plato) return;

    const stock = getReservationAvailableStock('plato_extra', platoId);
    if (stock < qty) {
        showToast(`Stock insuficiente de "${plato.name}". Disponible: ${stock}`, 'error');
        return;
    }

    const itemData = {
        type: 'plato_extra',
        id: platoId,
        name: plato.name,
        price: plato.price || 0,
        quantity: qty,
        detail: '',
        serviceType: getReservationItemServiceType(),
        platoId
    };

    if (plato.accepts_salsa) {
        openSalsaSelectModal({ name: plato.name }, salsas => {
            if (salsas) itemData.salsas = salsas;
            appendReservationCartItem(itemData, `${qty}x "${plato.name}" agregado(s) a la reserva.`);
        });
        return;
    }
    appendReservationCartItem(itemData, `${qty}x "${plato.name}" agregado(s) a la reserva.`);
}

function addReservationExtraToCart(extraId, qtyValue = 1) {
    const qty = normalizeReservationQty(qtyValue);
    const extra = (state.extras || []).find(e => e.id === extraId);
    if (!extra) return;

    const stock = getReservationAvailableStock('extra', extraId);
    if (stock < qty) {
        showToast(`Stock insuficiente de "${extra.name}". Disponible: ${stock}`, 'error');
        return;
    }

    appendReservationCartItem({
        type: 'extra',
        id: extraId,
        name: extra.name,
        price: extra.price || 0,
        quantity: qty,
        detail: '',
        serviceType: getReservationItemServiceType(),
        extraId
    }, `${qty}x bebida "${extra.name}" agregada(s) a la reserva.`);
}

function clearReservationCart() {
    state.reservationCart = [];
    renderReservationCart();
    renderReservationCatalog();
}

function removeReservationItem(index) {
    if (!state.reservationCart) return;
    state.reservationCart.splice(index, 1);
    renderReservationCart();
}

// ==========================================================================
// SAVE RESERVATION
// ==========================================================================

async function handleSaveReservation(e) {
    e.preventDefault();

    const id = document.getElementById('reservation-form-id').value;
    const name = document.getElementById('reservation-form-name').value.trim();
    const phone = document.getElementById('reservation-form-phone').value.trim();
    const customerId = document.getElementById('reservation-form-customer-id').value;
    const partySize = parseInt(document.getElementById('reservation-form-party').value);
    const deliveryType = document.getElementById('reservation-form-type').value;
    const date = document.getElementById('reservation-form-date').value;
    const time = document.getElementById('reservation-form-time').value;
    const tableId = deliveryType === 'para_servirse' ? document.getElementById('reservation-form-table').value : '';
    const notes = document.getElementById('reservation-form-notes').value.trim();
    const cart = (state.reservationCart || []).map(item => ({
        ...item,
        detail: sanitizeReservationDetail(item.detail)
    }));
    const total = cart.reduce((sum, it) => {
        const salsaTotal = (it.salsas || []).reduce((acc, s) => acc + (s.salsaPrice || 0), 0);
        return sum + ((it.price || 0) + salsaTotal) * (it.quantity || 1);
    }, 0);

    if (!name || !date || !time) {
        showToast('Completa nombre, fecha y hora.', 'error');
        return;
    }
    if (isNaN(partySize) || partySize < 1) {
        showToast('Cantidad de personas inválida.', 'error');
        return;
    }

    try {
        const data = await AppApi.saveReservation({
            id: id || '',
            customer_name: name,
            phone: phone,
            customer_id: customerId,
            party_size: partySize,
            delivery_type: deliveryType,
            reservation_date: date,
            reservation_time: time,
            table_id: tableId,
            items: cart,
            total: total,
            notes: notes
        });
        if (data.status === 'success') {
            showToast(id ? 'Reserva actualizada.' : 'Reserva creada con éxito.', 'success');

            if (!id) {
                const reservationOrder = buildReservationOrder({
                    id: (data && data.id) || generateId(),
                    customer_name: name,
                    delivery_type: deliveryType,
                    table_id: tableId,
                    items: cart,
                    total,
                    notes,
                    reservation_time: time,
                    reservation_date: date
                });

                try {
                    if (window.TicketPrinter) {
                        window.TicketPrinter.printKitchen(reservationOrder);
                    } else if (typeof window.openTicketModal === 'function') {
                        window.openTicketModal(reservationOrder, 'kitchen');
                    }
                } catch (ticketErr) {
                    console.error('[RESERVATIONS] Error al previsualizar comanda de reserva:', ticketErr);
                }
            }

            resetReservationForm();
            state.reservationsDate = date;
            await loadReservationsForDate(date);
        } else {
            showToast(data.message || 'Error al guardar reserva.', 'error');
        }
    } catch (e) {
        showToast('Error al guardar reserva.', 'error');
    }
}

// ==========================================================================
// RESERVATION PAYMENT MODAL
// ==========================================================================

let _resPaymentReservationId = null;

function openReservationPaymentModal(reservationId) {
    const res = (state.reservations || []).find(r => r.id === reservationId);
    if (!res) return;

    _resPaymentReservationId = reservationId;

    const clientEl = document.getElementById('res-pay-client');
    const totalEl = document.getElementById('res-pay-total');
    const methodEl = document.getElementById('res-pay-method');
    const mixedEl = document.getElementById('res-pay-mixed');

    if (clientEl) clientEl.textContent = res.customer_name;
    if (totalEl) totalEl.textContent = formatCurrency(res.total || 0);
    if (methodEl) methodEl.value = 'efectivo';
    if (mixedEl) mixedEl.style.display = 'none';

    const efectivoInput = document.getElementById('res-pay-efectivo');
    const qrInput = document.getElementById('res-pay-qr');
    if (efectivoInput) efectivoInput.value = '0';
    if (qrInput) qrInput.value = '0';

    const modal = document.getElementById('modal-reservation-payment');
    if (modal) modal.classList.add('open');
}

function closeReservationPaymentModal() {
    const modal = document.getElementById('modal-reservation-payment');
    if (modal) modal.classList.remove('open');
    _resPaymentReservationId = null;
}

function onReservationPaymentMethodChange() {
    const method = document.getElementById('res-pay-method')?.value;
    const mixedSection = document.getElementById('res-pay-mixed');
    if (mixedSection) {
        mixedSection.style.display = method === 'mixto' ? 'block' : 'none';
    }
    updateReservationPaymentSum();
}

function updateReservationPaymentSum() {
    const total = (state.reservations || []).find(r => r.id === _resPaymentReservationId)?.total || 0;
    const efectivo = parseFloat(document.getElementById('res-pay-efectivo')?.value) || 0;
    const qr = parseFloat(document.getElementById('res-pay-qr')?.value) || 0;
    const sum = efectivo + qr;
    const sumEl = document.getElementById('res-pay-sum');
    if (sumEl) {
        sumEl.textContent = `${formatCurrency(sum)} / ${formatCurrency(total)}`;
        sumEl.style.color = Math.abs(sum - total) < 0.01 ? 'var(--success)' : 'var(--danger)';
    }
}

async function confirmReservationPayment() {
    if (!_resPaymentReservationId) {
        showToast('Error: no se identificó la reserva.', 'error');
        return;
    }

    const res = (state.reservations || []).find(r => r.id === _resPaymentReservationId);
    if (!res) {
        showToast('Reserva no encontrada.', 'error');
        return;
    }

    const methodEl = document.getElementById('res-pay-method');
    const paymentMethod = methodEl?.value || 'efectivo';
    const total = res.total || 0;

    const invalidItems = validateReservationItems(res.items);
    if (invalidItems.length > 0) {
        showToast(`Productos no encontrados o inactivos: ${invalidItems.join(', ')}. Edite la reserva antes de cobrar.`, 'error');
        return;
    }

    let payment;
    if (paymentMethod === 'mixto') {
        const efectivo = parseFloat(document.getElementById('res-pay-efectivo')?.value) || 0;
        const qr = parseFloat(document.getElementById('res-pay-qr')?.value) || 0;
        if (Math.abs(efectivo + qr - total) > 0.01) {
            showToast(`La suma de pagos (Bs ${(efectivo + qr).toFixed(2)}) no coincide con el total (Bs ${total.toFixed(2)}).`, 'warning');
            return;
        }
        payment = { efectivo, qr };
    } else {
        payment = paymentMethod;
    }

    const confirmBtn = document.getElementById('btn-confirm-reservation-payment');
    setButtonLoading(confirmBtn, true);

    try {
        const newOrder = buildReservationOrder(res, {
            id: generateId(),
            paymentMethod: payment,
            paid: true,
            soldAt: new Date().toISOString(),
            status: 'completado',
            timestamp: nowLocal()
        });

        const orderData = await AppApi.request('save_order', newOrder);
        if (orderData.status !== 'success') {
            showToast('Error al crear el pedido.', 'error');
            return;
        }

        const statusData = await AppApi.updateReservationStatus(_resPaymentReservationId, 'completada');
        if (statusData.status !== 'success') {
            showToast('Pedido creado pero error al actualizar reserva.', 'warning');
        }

        Notifications.notify(`💰 Reserva cobrada: ${res.customer_name} — ${formatCurrency(total)}`, 'success');
        closeReservationPaymentModal();
        showToast('Reserva cobrada y registrada.', 'success');
        await loadReservationsForDate(state.reservationsDate);

        try {
            if (window.TicketPrinter) {
                window.TicketPrinter.printReceipt(newOrder);
            } else {
                window.openTicketModal(newOrder, 'client');
            }
        } catch (e) {
            console.error('Error abriendo ticket modal:', e);
        }
    } catch (e) {
        console.error('[RESERVATIONS] Error al cobrar reserva:', e);
        showToast(e.message || 'Error al procesar cobro de reserva.', 'error');
    } finally {
        setButtonLoading(confirmBtn, false);
    }
}

// ==========================================================================
// RESERVATION TABLE SELECTION MODAL
// ==========================================================================

let _resTableReservationId = null;

function openReservationTableModal(reservationId) {
    const res = (state.reservations || []).find(r => r.id === reservationId);
    if (!res) return;

    _resTableReservationId = reservationId;

    const clientEl = document.getElementById('res-table-client');
    const partyEl = document.getElementById('res-table-party');
    const gridEl = document.getElementById('res-free-tables-grid');
    const noTablesEl = document.getElementById('res-no-free-tables');

    if (clientEl) clientEl.textContent = res.customer_name;
    if (partyEl) partyEl.textContent = res.party_size;

    const occupiedTables = new Set(
        (state.activeOrders || [])
            .filter(o => o.status === 'pendiente' && o.deliveryType === 'mesa')
            .map(o => {
                const cust = o.customer || '';
                const dashIdx = cust.indexOf(' - ');
                return dashIdx > -1 ? cust.substring(0, dashIdx) : cust;
            })
    );

    const freeTables = (state.tables || []).filter(t =>
        Number(t.active) !== 0 && !occupiedTables.has(t.name)
    );

    if (gridEl) gridEl.innerHTML = '';

    if (freeTables.length === 0) {
        if (noTablesEl) noTablesEl.style.display = 'block';
        if (gridEl) gridEl.style.display = 'none';
    } else {
        if (noTablesEl) noTablesEl.style.display = 'none';
        if (gridEl) gridEl.style.display = '';
        freeTables.forEach(table => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-outline free-table-btn';
            btn.innerHTML = `<i class="fa-solid fa-chair"></i> ${escapeHtml(table.name)}`;
            btn.onclick = () => selectReservationTable(table.id);
            gridEl.appendChild(btn);
        });
    }

    const modal = document.getElementById('modal-reservation-select-table');
    if (modal) modal.classList.add('open');
}

function closeReservationTableModal() {
    const modal = document.getElementById('modal-reservation-select-table');
    if (modal) modal.classList.remove('open');
    _resTableReservationId = null;
}

async function selectReservationTable(tableId) {
    if (!_resTableReservationId) return;

    const res = (state.reservations || []).find(r => r.id === _resTableReservationId);
    if (!res) return;

    const tableName = getTableName(tableId);
    const confirmed = await window.ConfirmDialog.show(
        `¿Asignar mesa "${tableName}" a la reserva de ${res.customer_name}?`,
        { title: 'Asignar Mesa', confirmText: 'Sí, asignar y confirmar', type: 'success' }
    );
    if (!confirmed) return;

    try {
        const updateData = await AppApi.saveReservation({
            id: res.id,
            customer_name: res.customer_name,
            phone: res.phone || '',
            party_size: res.party_size,
            delivery_type: res.delivery_type || 'para_servirse',
            reservation_date: res.reservation_date,
            reservation_time: res.reservation_time,
            table_id: tableId,
            items: res.items || [],
            total: res.total || 0,
            notes: res.notes || ''
        });

        if (updateData.status !== 'success') {
            showToast('Error al asignar mesa.', 'error');
            return;
        }

        res.table_id = tableId;
        closeReservationTableModal();

        await confirmReservationForMesa(res);
    } catch (e) {
        showToast('Error al asignar mesa.', 'error');
    }
}

function printReservationComanda(reservationId) {
    const reservation = (state.reservations || []).find(r => r.id === reservationId);
    if (!reservation) {
        showToast('Reserva no encontrada.', 'error');
        return;
    }

    const items = reservation.items || [];
    if (items.length === 0) {
        showToast('La reserva no tiene items para imprimir.', 'warning');
        return;
    }

    const order = buildReservationOrder(reservation);

    try {
        if (window.TicketPrinter) {
            window.TicketPrinter.printKitchen(order);
        } else if (typeof window.openTicketModal === 'function') {
            window.openTicketModal(order, 'kitchen');
        } else {
            showToast('Impresora no disponible.', 'warning');
        }
    } catch (ticketErr) {
        console.error('[RESERVATIONS] Error al imprimir comanda:', ticketErr);
        showToast('Error al imprimir comanda.', 'error');
    }
}

window.changeReservationsDate = changeReservationsDate;
window.searchReservations = searchReservations;
window.filterReservations = filterReservations;
window.toggleBotReservationSelection = toggleBotReservationSelection;
window.toggleAllBotReservations = toggleAllBotReservations;
window.verifyAndPrintBotReservations = verifyAndPrintBotReservations;
window.onReservationTypeChange = onReservationTypeChange;
window.toggleReservationForm = toggleReservationForm;
window.resetReservationForm = resetReservationForm;
window.handleSaveReservation = handleSaveReservation;
window.confirmReservation = confirmReservation;
window.cancelReservation = cancelReservation;
window.deleteReservation = deleteReservation;
window.editReservation = editReservation;
window.printReservationComanda = printReservationComanda;
window.switchReservationCatalogTab = switchReservationCatalogTab;
window.setReservationCatalogSearch = setReservationCatalogSearch;
window.addReservationMealToCart = addReservationMealToCart;
window.addReservationPlatoExtraToCart = addReservationPlatoExtraToCart;
window.addReservationExtraToCart = addReservationExtraToCart;
window.clearReservationCart = clearReservationCart;
window.removeReservationItem = removeReservationItem;
window.openReservationPaymentModal = openReservationPaymentModal;
window.closeReservationPaymentModal = closeReservationPaymentModal;
window.onReservationPaymentMethodChange = onReservationPaymentMethodChange;
window.confirmReservationPayment = confirmReservationPayment;
window.openReservationTableModal = openReservationTableModal;
window.closeReservationTableModal = closeReservationTableModal;
window.selectReservationTable = selectReservationTable;

document.addEventListener('DOMContentLoaded', function () {
    const resPayEfectivo = document.getElementById('res-pay-efectivo');
    const resPayQr = document.getElementById('res-pay-qr');
    if (resPayEfectivo) resPayEfectivo.addEventListener('input', updateReservationPaymentSum);
    if (resPayQr) resPayQr.addEventListener('input', updateReservationPaymentSum);
});
