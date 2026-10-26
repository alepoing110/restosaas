// ==========================================================================
// CART LOCALSTORAGE HELPERS (depends on: state.js)
// ==========================================================================

function saveCartToLocalStorage() {
    localStorage.setItem('pos_pension_cart', JSON.stringify(state.cart));
    if (typeof window.updateHeaderMetrics === 'function') {
        window.updateHeaderMetrics();
    }
}

function readCartFromLocalStorage() {
    try {
        const saved = localStorage.getItem('pos_pension_cart');
        return saved ? JSON.parse(saved) : [];
    } catch (error) {
        console.warn('Cart localStorage parsing error: ', error);
        localStorage.removeItem('pos_pension_cart');
        showToast('Se reinicio el carrito local por datos invalidos.', 'warning');
        return [];
    }
}

function loadCartFromLocalStorage() {
    state.cart = readCartFromLocalStorage();
    return state.cart;
}

window.saveCartToLocalStorage = saveCartToLocalStorage;
window.loadCartFromLocalStorage = loadCartFromLocalStorage;
