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
        pais: 'Bolivia',
        whatsapp_number: '',
        whatsapp_phone_id: ''
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
    cartPromo: null,
    cartSuggestedPromos: [],
    cartCouponCode: '',
    cartDiscountAmount: 0,
    cartDiscountLabel: '',
    session: null,
    authUser: null,
    tenant: null,
    branch: null,
    authorizedBranches: [],
    permissions: [],
    subscription: null,
    saasAdmin: {
        tenants: [],
        branches: [],
        users: [],
        plans: [],
        chatbotConversations: [],
        chatbotMessages: [],
        chatbotSelectedConversationId: null,
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
    financialReport: null,
    branchComparison: null,
    cashFlow: null,
    receivables: null,
    customers: [],
    selectedCustomerProfile: null,
    crmConversations: [],
    crmConversationMessages: [],
    selectedCrmConversationId: null,
    customerSegment: 'all',
    controlReport: null,
    refundsReport: null,
    promoPlans: [],
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
        'authorizedBranches',
        'permissions',
        'subscription',
        'saasAdmin',
        'activeOrders',
        'salesHistory',
        'cajaMovimientos',
        'cajaCierres',
        'reportContext',
        'financialReport',
        'branchComparison',
        'cashFlow',
        'receivables',
        'customers',
        'crmConversations',
        'controlReport',
        'refundsReport',
        'promoPlans',
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
        if (tabId === 'financial') {
            const containerId = 'screen-financial';
            if (window.LoadingState) window.LoadingState.show(containerId, 'Cargando datos...');
            const [financialReport, branchComparison, cashFlow, receivables, controlReport] = await Promise.all([
                AppApi.getFinancialReport(),
                AppApi.getBranchComparison(),
                AppApi.getCashFlow(),
                AppApi.getReceivables(),
                AppApi.getControlReport()
            ]);
            applyServerState({ financialReport, branchComparison, cashFlow, receivables, controlReport });
            if (window.LoadingState) window.LoadingState.hide(containerId);
            const retry = document.getElementById('financial-retry');
            if (retry) retry.style.display = 'none';
            if (typeof window.renderFinancial === 'function') window.renderFinancial();
            return true;
        }

        const loaders = {
            'pos': AppApi.getPosState,
            'active-orders': AppApi.getActiveOrders,
            'menu-config': async () => {
                const inventory = await AppApi.getInventory();
                try {
                    const promoPlans = await AppApi.getPromoPlans();
                    return { ...inventory, promoPlans: promoPlans.plans || promoPlans };
                } catch (_) {
                    return inventory;
                }
            },
            'inventory': AppApi.getInventory,
            'reports': async () => {
                const data = await AppApi.getReports();
                try {
                    const refundsData = await AppApi.getRefundsReport();
                    return { ...data, refundsReport: refundsData };
                } catch (_) {
                    return data;
                }
            },
            'customers': async () => {
                const [customers, conversations] = await Promise.all([AppApi.getCustomers(), AppApi.getChatbotConversations()]);
                return { customers: customers.customers || [], crmConversations: conversations.conversations || [] };
            },
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
            'financial': 'screen-financial',
            'customers': 'screen-customers',
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
            'financial': 'screen-financial',
            'customers': 'screen-customers',
            'reservations': 'screen-reservations',
            'saas-admin': 'screen-saas-admin'
        };

        const containerId = containerMap[tabId];
        if (containerId && window.LoadingState) {
            window.LoadingState.hide(containerId);
        }

        if (tabId === 'financial') {
            const periodLabel = document.getElementById('financial-period-label');
            if (periodLabel) periodLabel.textContent = `No se pudo cargar Finanzas: ${e.message || 'error desconocido'}`;
            const retry = document.getElementById('financial-retry');
            if (retry) retry.style.display = '';
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
    const bizWhatsappInput = document.getElementById('biz-whatsapp-input');
    const bizWhatsappPhoneIdInput = document.getElementById('biz-whatsapp-phone-id-input');

    if (bizNameInput) bizNameInput.value = state.business.nombre_restaurante;
    if (bizAddressInput) bizAddressInput.value = state.business.direccion;
    if (bizPhoneInput) bizPhoneInput.value = state.business.telefono;
    if (bizCountryInput) bizCountryInput.value = state.business.pais || 'Bolivia';
    if (bizWhatsappInput) bizWhatsappInput.value = state.business.whatsapp_number || '';
    if (bizWhatsappPhoneIdInput) bizWhatsappPhoneIdInput.value = state.business.whatsapp_phone_id || '';
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
    const whatsappNumber = document.getElementById('biz-whatsapp-input').value.trim();
    const whatsappPhoneId = document.getElementById('biz-whatsapp-phone-id-input').value.trim();

    if (!nombre || !direccion || !telefono) {
        showToast('Complete todos los campos del establecimiento.', 'error');
        return;
    }

    try {
        const data = await AppApi.request('save_business_info', {
            nombre_restaurante: nombre,
            direccion: direccion,
            telefono: telefono,
            pais: pais,
            whatsapp_number: whatsappNumber,
            whatsapp_phone_id: whatsappPhoneId
        });
        state.business.pais = pais;
        state.business.whatsapp_number = whatsappNumber;
        state.business.whatsapp_phone_id = whatsappPhoneId;
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
