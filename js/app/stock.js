// ==========================================================================
// DYNAMIC STOCK CALCULATIONS (depends on: state.js + helpers.js)
// Counting functions (countSegundoUsage, etc.) live in helpers.js
// ==========================================================================

function _getAvailableStock(itemId, stockArray, countFn) {
    const item = stockArray.find(s => s.id === itemId);
    if (!item) return 0;

    let usage = 0;
    const countedOrderIds = new Set();
    const addOrderUsage = order => {
        if (order?.id) {
            if (countedOrderIds.has(order.id)) return;
            countedOrderIds.add(order.id);
        }
        usage += countFn(order?.items, itemId);
    };
    try { usage += countFn(state.cart, itemId); } catch (e) {}
    try {
        (state.activeOrders || []).forEach(order => {
            addOrderUsage(order);
        });
    } catch (e) {}
    try {
        (state.salesHistory || []).forEach(sale => {
            if (sale.status === 'completado' || (sale.status === 'pendiente' && sale.paid)) {
                addOrderUsage(sale);
            }
        });
    } catch (e) {}
    try {
        if (typeof window.getAppendItemsPending === 'function') {
            usage += countFn(window.getAppendItemsPending(), itemId);
        }
    } catch (e) {}
    try {
        (state.reservations || []).forEach(res => {
            if ((res.status === 'pendiente' || res.status === 'confirmada') && Array.isArray(res.items)) {
                usage += countFn(res.items, itemId);
            }
        });
    } catch (e) {}
    return Math.max(0, item.stock - usage);
}

function getAvailableSegundoStock(segundoId) {
    return _getAvailableStock(segundoId, state.seconds, countSegundoUsage);
}

function getAvailablePlatoExtraStock(platoId) {
    return _getAvailableStock(platoId, state.platosExtras, countPlatoExtraUsage);
}

function getAvailableExtraStock(extraId) {
    return _getAvailableStock(extraId, state.extras, countExtraUsage);
}

function getAvailableSopaStock(sopaId) {
    return _getAvailableStock(sopaId, state.sopas, countSopaUsage);
}
