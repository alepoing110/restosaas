// ==========================================================================
// STATE MANAGEMENT (depends on: helpers.js, toast.js, modal.js, cart.js)
// ==========================================================================

let state = {
    prices: {
        almuerzo: 15.00,
        segundo: 12.00,
        sopa: 6.00
    },
    business: {
        nombre_restaurante: 'RestoCloud',
        direccion: 'Calle Sucre #123, Local Central',
        telefono: 'Telf: 4567890 - Cochabamba',
        pais: 'Bolivia'
    },
    features: {
        pos: true,
        reportes: 'basicos',
        reservations: false,
        inventory: true,
        caja: true,
        delivery: false,
        multi_branch: false,
        priority_support: false
    },
    seconds: [],
    sopas: [],
    platosExtras: [],
    extras: [],
    menus: [],
    products: [],
    tables: [],
    cart: [],
    session: null,
    authUser: null,
    tenant: null,
    branch: null,
    permissions: [],
    subscription: null,
    saasAdmin: {
        tenants: [],
        branches: [],
        users: [],
        plans: [],
        summary: {
            tenants: 0,
            branches: 0,
            users: 0,
            activeSubscriptions: 0
        },
        editingTenantId: null,
        editingBranchId: null,
        editingUserId: null
    },
    activeOrders: [],
    salesHistory: [],
    cajaMovimientos: [],
    cajaCierres: [],
    reportContext: null,
    reservations: [],
    reservationsDate: todayLocal(),
    reservationCart: [],
    reservationFilter: 'activas',
    reservationCatalogTab: 'meals',
    reservationCatalogSearch: '',
    salsas: []
};

if (window.AppStore) {
    window.AppStore.create(state);
}

// POS active category tab state
let posCategory = 'meals';
let currentTabId = 'pos';

// Table inline editing variables
let editingSegundoId = null;
let editingSopaId = null;
let editingPlatoId = null;
let editingExtraId = null;
let editingSalsaId = null;

// ==========================================================================
// SERVER STATE SYNCHRONIZATION
// ==========================================================================

function applyServerState(data) {
    if (!data || typeof data !== 'object') return;
    const patch = {};
    [
        'prices',
        'business',
        'features',
        'seconds',
        'sopas',
        'platosExtras',
        'extras',
        'menus',
        'products',
        'tables',
        'session',
        'authUser',
        'tenant',
        'branch',
        'permissions',
        'subscription',
        'saasAdmin',
        'activeOrders',
        'salesHistory',
        'cajaMovimientos',
        'cajaCierres',
        'reportContext',
        'reservations',
        'reservationsDate',
        'salsas'
    ].forEach(key => {
        if (Object.prototype.hasOwnProperty.call(data, key)) {
            patch[key] = data[key];
        }
    });

    patch.cart = readCartFromLocalStorage();

    if (window.AppStore) {
        window.AppStore.set(patch);
    } else {
        Object.assign(state, patch);
    }
    updateBusinessDOM();
    if (typeof window.applyRoleVisibility === 'function') {
        window.applyRoleVisibility();
    }
    if (typeof window.updateHeaderMetrics === 'function') {
        window.updateHeaderMetrics();
    }
    if (typeof window.populateMenuSelector === 'function') {
        window.populateMenuSelector();
    }
}

async function loadStateFromServer() {
    try {
        const data = window.AppApi
            ? await window.AppApi.getState()
            : await fetch('api.php?action=get_state').then(res => res.json());

        applyServerState(data);
        return true;
    } catch (e) {
        console.error("Server connection error: ", e);
        const msg = e.message || '';
        if (msg.includes('Sesión') || msg.includes('sesión') || msg.includes('401')) {
            showToast('Tu sesión expiró. Vuelve a iniciar sesión.', 'error');
            if (window.AppAuth) {
                window.AppAuth.showLogin('Tu sesión expiró. Vuelve a iniciar sesión.');
            }
        } else {
            showToast('Error al cargar datos: ' + msg, 'error');
        }
        return false;
    }
}

async function loadStateForTab(tabId) {
    if (!window.AppApi) {
        return await loadStateFromServer();
    }

    // Dashboard fetches its own data via AppApi.getDashboard(), skip get_state
    if (tabId === 'dashboard') return true;

    try {
        const loaders = {
            'pos': AppApi.getPosState,
            'active-orders': AppApi.getActiveOrders,
            'menu-config': AppApi.getInventory,
            'inventory': AppApi.getInventory,
            'reports': AppApi.getReports,
            'reservations': async () => {
                const date = state.reservationsDate || todayLocal();
                const [catalogData, reservationsData] = await Promise.all([
                    AppApi.getState(),
                    AppApi.getReservations(date)
                ]);
                return { ...catalogData, reservations: reservationsData.reservations || [] };
            },
            'saas-admin': AppApi.getSaasAdmin
        };

        const loader = loaders[tabId] || AppApi.getState;

        const containerMap = {
            'pos': 'pos-catalog-cards',
            'active-orders': 'active-orders-board',
            'menu-config': 'screen-menu-config',
            'inventory': 'screen-inventory',
            'reports': 'screen-reports',
            'reservations': 'screen-reservations',
            'saas-admin': 'screen-saas-admin'
        };

        const containerId = containerMap[tabId];
        if (containerId && window.LoadingState) {
            window.LoadingState.show(containerId, 'Cargando datos...');
        }

        const data = await loader();

        if (containerId && window.LoadingState) {
            window.LoadingState.hide(containerId);
        }

        applyServerState(data);
        return true;
    } catch (e) {
        console.error("Server connection error: ", e);

        const containerMap = {
            'pos': 'pos-catalog-cards',
            'active-orders': 'active-orders-board',
            'menu-config': 'screen-menu-config',
            'inventory': 'screen-inventory',
            'reports': 'screen-reports',
            'reservations': 'screen-reservations',
            'saas-admin': 'screen-saas-admin'
        };

        const containerId = containerMap[tabId];
        if (containerId && window.LoadingState) {
            window.LoadingState.hide(containerId);
        }

        showToast(
            e.message.includes('Sesión') || e.message.includes('sesión')
                ? 'Tu sesión expiró. Vuelve a iniciar sesión.'
                : 'No se pudo cargar la vista solicitada.',
            'error'
        );
        if (e.message.includes('Sesión') || e.message.includes('sesión')) {
            if (window.AppAuth) window.AppAuth.showLogin('Tu sesión expiró.');
        }
        return false;
    }
}

window.loadStateForTab = loadStateForTab;

// ==========================================================================
// BUSINESS DOM UPDATE
// ==========================================================================

function updateBusinessDOM() {
    const sidebarBrand = document.getElementById('sidebar-brand-name');
    if (sidebarBrand) {
        sidebarBrand.textContent = state.business.nombre_restaurante;
    }

    document.title = `${state.business.nombre_restaurante} - Sistema de Gestion de Ventas`;

    const bizNameInput = document.getElementById('biz-name-input');
    const bizAddressInput = document.getElementById('biz-address-input');
    const bizPhoneInput = document.getElementById('biz-phone-input');
    const bizCountryInput = document.getElementById('biz-country-input');

    if (bizNameInput) bizNameInput.value = state.business.nombre_restaurante;
    if (bizAddressInput) bizAddressInput.value = state.business.direccion;
    if (bizPhoneInput) bizPhoneInput.value = state.business.telefono;
    if (bizCountryInput) bizCountryInput.value = state.business.pais || 'Bolivia';
}

function showUpgradePrompt(featureName) {
    const featureLabels = {
        reservations: 'Reservaciones',
        delivery: 'Delivery',
        multi_branch: 'Multi-sucursal',
        priority_support: 'Soporte Prioritario',
        inventory: 'Inventario',
        caja: 'Caja',
        reportes: 'Reportes Completos'
    };
    const label = featureLabels[featureName] || featureName;
    const sub = state.subscription || {};
    const currentPlan = (sub.plan_code || 'starter').toUpperCase();

    if (typeof window.ConfirmDialog !== 'undefined') {
        window.ConfirmDialog.show(
            `La funcionalidad "${label}" requiere un plan superior. Tu plan actual es ${currentPlan}.`,
            { title: 'Actualiza tu Plan', confirmText: 'Entendido', type: 'info' }
        );
    } else {
        showToast(`"${label}" no está disponible en tu plan actual (${currentPlan}). Contacta al administrador para actualizar.`, 'warning');
    }
}

async function handleSaveBusinessInfo(e) {
    e.preventDefault();
    const nombre = document.getElementById('biz-name-input').value.trim();
    const direccion = document.getElementById('biz-address-input').value.trim();
    const telefono = document.getElementById('biz-phone-input').value.trim();
    const pais = document.getElementById('biz-country-input').value || 'Bolivia';

    if (!nombre || !direccion || !telefono) {
        showToast('Complete todos los campos del establecimiento.', 'error');
        return;
    }

    try {
        const data = await AppApi.request('save_business_info', {
            nombre_restaurante: nombre,
            direccion: direccion,
            telefono: telefono,
            pais: pais
        });
        state.business.pais = pais;
        showToast('Datos del establecimiento guardados.', 'success');
        await loadStateForTab('menu-config');
    } catch (err) {
        console.error('save_business_info error:', err);
        showToast(err.message || 'Error al guardar establecimiento.', 'error');
    }
}

// ==========================================================================
// ACTIVE ORDERS MUTATION
// ==========================================================================

function upsertActiveOrder(order) {
    const existingIndex = state.activeOrders.findIndex(activeOrder => activeOrder.id === order.id);
    if (existingIndex >= 0) {
        state.activeOrders[existingIndex] = order;
    } else {
        state.activeOrders.push(order);
    }
    if (window.AppStore) window.AppStore.emit();
}

function updateActiveOrder(orderId, patch) {
    const existingOrder = state.activeOrders.find(activeOrder => activeOrder.id === orderId);
    if (!existingOrder) return null;

    Object.assign(existingOrder, patch);
    if (window.AppStore) window.AppStore.emit();
    return existingOrder;
}

function removeActiveOrder(orderId) {
    const existingOrder = state.activeOrders.find(activeOrder => activeOrder.id === orderId);
    state.activeOrders = state.activeOrders.filter(activeOrder => activeOrder.id !== orderId);
    if (window.AppStore) window.AppStore.emit();
    return existingOrder || null;
}

function prependSaleHistory(order) {
    state.salesHistory = [order, ...state.salesHistory.filter(sale => sale.id !== order.id)];
    if (window.AppStore) window.AppStore.emit();
}

// ==========================================================================
// SERVER CRUD
// ==========================================================================

async function saveItemOnServer(type, item) {
    try {
        const data = await AppApi.request('save_item', { type: type, ...item });

        if (data.status === 'success' && typeof AppApi.request === 'function') {
            const productType = type === 'extra' ? 'refresco' : type;
            const price = item.price || 0;
            const stock = item.stock || 0;

            const existingProduct = (state.products || []).find(p => p.id === item.id);
            const menuId = existingProduct ? existingProduct.menu_id : null;

            try {
                await AppApi.request('save_product', {
                    id: item.id,
                    name: item.name,
                    type: productType,
                    price: price,
                    stock: stock,
                    menu_id: menuId
                });
            } catch (e) {
                console.warn('Failed to save product record:', e);
            }
        }

        return data.status === 'success';
    } catch (e) {
        console.error("Save item error: ", e);
        if (typeof showToast === 'function') {
            showToast(e.message || 'Error al guardar el plato.', 'error');
        }
        return false;
    }
}

async function deleteItemOnServer(type, id) {
    try {
        const data = await AppApi.request('delete_item', { type: type, id: id });
        return data.status === 'success';
    } catch (e) {
        console.error("Delete item error: ", e);
        if (typeof showToast === 'function') {
            showToast(e.message || 'Error al eliminar el plato.', 'error');
        }
        return false;
    }
}

// ==========================================================================
// MENU SELECTOR
// ==========================================================================

function populateMenuSelector() {
    const select = document.getElementById('pos-active-menu-select');
    if (!select) return;

    select.innerHTML = '';

    const addOption = (value, text) => {
        const opt = document.createElement('option');
        opt.value = value;
        opt.textContent = text;
        select.appendChild(opt);
    };

    addOption('', '— Sin filtro de menú —');

    if (state.menus && state.menus.length > 0) {
        state.menus.filter(m => m.active).forEach(menu => {
            let text = menu.name;
            if (menu.start_time && menu.end_time) {
                text += ` (${menu.start_time.substring(0,5)}-${menu.end_time.substring(0,5)})`;
            }
            addOption(menu.id, text);
        });
    }

    const savedMenu = localStorage.getItem('restocloud_active_menu');
    if (savedMenu && select.querySelector(`option[value="${savedMenu}"]`)) {
        select.value = savedMenu;
    }
}

// ==========================================================================
// WINDOW EXPORTS
// ==========================================================================

window.loadStateFromServer = loadStateFromServer;
window.saveItemOnServer = saveItemOnServer;
window.deleteItemOnServer = deleteItemOnServer;
window.upsertActiveOrder = upsertActiveOrder;
window.updateActiveOrder = updateActiveOrder;
window.removeActiveOrder = removeActiveOrder;
window.prependSaleHistory = prependSaleHistory;
window.populateMenuSelector = populateMenuSelector;
window.state = state;

window.notifyStateChanged = function () {
    if (window.AppStore) {
        window.AppStore.emit();
        return;
    }
    if (window.AppViewController && typeof window.AppViewController.markAllDirty === 'function') {
        window.AppViewController.markAllDirty();
        return;
    }
    if (typeof window.renderCurrentTab === 'function') {
        window.renderCurrentTab(currentTabId);
        return;
    }
    if (typeof window.updateHeaderMetrics === 'function') {
        window.updateHeaderMetrics();
    }
};

window.getPosCategory = function () {
    return posCategory;
};

window.setPosCategory = function (category) {
    posCategory = category;
};
