// Thin ES module re-export for test compatibility.
// Actual implementations live in helpers.js, stock.js, state.js (loaded as global scripts).

export function generateId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return 'id_' + crypto.randomUUID();
    }
    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
        return 'id_' + hex.substring(0, 32);
    }
    return 'id_' + Date.now().toString(36) + '_' + Math.random().toString(36).substring(2, 11);
}

export function formatCurrency(amount) {
    return 'Bs ' + parseFloat(amount).toFixed(2);
}

export function escapeHtml(str) {
    if (str == null) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}

export function countSopaUsage(items, sopaId) {
    if (!items || !Array.isArray(items)) return 0;
    return items.reduce((acc, item) => {
        if ((item.type === 'almuerzo' || item.type === 'sopa') && item.sopaId === sopaId) {
            return acc + (item.quantity || item.qty || 1);
        }
        return acc;
    }, 0);
}

export function countSegundoUsage(items, segundoId) {
    if (!items || !Array.isArray(items)) return 0;
    return items.reduce((acc, item) => {
        if ((item.type === 'almuerzo' || item.type === 'segundo') && item.segundoId === segundoId) {
            return acc + (item.quantity || item.qty || 1);
        }
        return acc;
    }, 0);
}

export function countPlatoExtraUsage(items, platoId) {
    if (!items || !Array.isArray(items)) return 0;
    return items.reduce((acc, item) => {
        if (item.type === 'plato_extra' && item.platoId === platoId) {
            return acc + (item.quantity || item.qty || 1);
        }
        return acc;
    }, 0);
}

export function countExtraUsage(items, extraId) {
    if (!items || !Array.isArray(items)) return 0;
    return items.reduce((acc, item) => {
        if (item.type === 'extra' && item.extraId === extraId) {
            return acc + (item.quantity || item.qty || 1);
        }
        return acc;
    }, 0);
}

export function createInitialState() {
    return {
        soupStock: { total: 50, used: 0 },
        seconds: [],
        sopas: [],
        platosExtras: [],
        extras: [],
        cart: [],
        activeOrders: [],
        salesHistory: []
    };
}

export function getAvailableSoupStock(st) {
    const total = (st.soupStock && st.soupStock.total) || 50;
    let used = 0;

    (st.cart || []).forEach(item => {
        if (item.type === 'almuerzo' || item.type === 'sopa') used++;
    });

    (st.activeOrders || []).forEach(order => {
        (order.items || []).forEach(item => {
            if (item.type === 'almuerzo' || item.type === 'sopa') used++;
        });
    });

    (st.salesHistory || []).forEach(sale => {
        if (sale.status === 'completado' || (sale.status === 'pendiente' && sale.paid)) {
            (sale.items || []).forEach(item => {
                if (item.type === 'almuerzo' || item.type === 'sopa') used++;
            });
        }
    });

    return Math.max(0, total - used);
}

export function getAvailableSegundoStock(st, segundoId) {
    const segundo = (st.seconds || []).find(s => s.id === segundoId);
    if (!segundo) return 0;
    const total = segundo.stock || 0;
    let used = 0;

    (st.cart || []).forEach(item => {
        if ((item.type === 'almuerzo' || item.type === 'segundo') && item.segundoId === segundoId) used++;
    });

    (st.activeOrders || []).forEach(order => {
        (order.items || []).forEach(item => {
            if ((item.type === 'almuerzo' || item.type === 'segundo') && item.segundoId === segundoId) used++;
        });
    });

    (st.salesHistory || []).forEach(sale => {
        if (sale.status === 'completado' || (sale.status === 'pendiente' && sale.paid)) {
            (sale.items || []).forEach(item => {
                if ((item.type === 'almuerzo' || item.type === 'segundo') && item.segundoId === segundoId) used++;
            });
        }
    });

    return Math.max(0, total - used);
}

export function getAvailablePlatoExtraStock(st, platoId) {
    const plato = (st.platosExtras || []).find(p => p.id === platoId);
    if (!plato) return 0;
    const total = plato.stock || 0;
    let used = 0;

    (st.cart || []).forEach(item => {
        if (item.type === 'plato_extra' && item.platoId === platoId) used++;
    });

    (st.activeOrders || []).forEach(order => {
        (order.items || []).forEach(item => {
            if (item.type === 'plato_extra' && item.platoId === platoId) used++;
        });
    });

    (st.salesHistory || []).forEach(sale => {
        if (sale.status === 'completado' || (sale.status === 'pendiente' && sale.paid)) {
            (sale.items || []).forEach(item => {
                if (item.type === 'plato_extra' && item.platoId === platoId) used++;
            });
        }
    });

    return Math.max(0, total - used);
}

export function getAvailableExtraStock(st, extraId) {
    const extra = (st.extras || []).find(e => e.id === extraId);
    if (!extra) return 0;
    const total = extra.stock || 0;
    let used = 0;

    (st.cart || []).forEach(item => {
        if (item.type === 'extra' && item.extraId === extraId) used++;
    });

    (st.activeOrders || []).forEach(order => {
        (order.items || []).forEach(item => {
            if (item.type === 'extra' && item.extraId === extraId) used++;
        });
    });

    (st.salesHistory || []).forEach(sale => {
        if (sale.status === 'completado' || (sale.status === 'pendiente' && sale.paid)) {
            (sale.items || []).forEach(item => {
                if (item.type === 'extra' && item.extraId === extraId) used++;
            });
        }
    });

    return Math.max(0, total - used);
}

export function upsertActiveOrder(st, order) {
    const idx = st.activeOrders.findIndex(o => o.id === order.id);
    if (idx >= 0) {
        Object.assign(st.activeOrders[idx], order);
    } else {
        st.activeOrders.push(order);
    }
}

export function updateActiveOrder(st, orderId, patch) {
    const order = st.activeOrders.find(o => o.id === orderId);
    if (!order) return null;
    Object.assign(order, patch);
    return order;
}

export function removeActiveOrder(st, orderId) {
    const idx = st.activeOrders.findIndex(o => o.id === orderId);
    if (idx < 0) return null;
    return st.activeOrders.splice(idx, 1)[0];
}

export function prependSaleHistory(st, order) {
    st.salesHistory = [order, ...st.salesHistory.filter(o => o.id !== order.id)];
}
