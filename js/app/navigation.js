// ==========================================================================
// TAB NAVIGATION + KEYBOARD SHORTCUTS (depends on: state.js)
// ==========================================================================

const tabs = {
    'pos': { title: 'Toma de Pedidos', desc: 'Gestiona y crea nuevas ordenes para el dia.' },
    'active-orders': { title: 'Pedidos Activos', desc: 'Control de mesas e impresion de comandas en preparacion.' },
    'menu-config': { title: 'Platos y Menu', desc: 'Configura platos del dia, platos extras, gaseosas y precios base.' },
    'inventory': { title: 'Control Stock', desc: 'Verifica y ajusta las cantidades en stock del dia.' },
    'reports': { title: 'Ventas e Historial', desc: 'Metricas de recaudacion e impresion de recibos.' },
    'financial': { title: 'Finanzas del Dueño', desc: 'Ventas, gastos y ganancia operativa por período y sucursal.' },
    'customers': { title: 'Clientes CRM', desc: 'Gestiona clientes, historial comercial y saldo de crédito.' },
    'dashboard': { title: 'Dashboard', desc: 'Resumen ejecutivo del rendimiento del restaurante.' },
    'reservations': { title: 'Reservas', desc: 'Gestiona las reservaciones de mesas del restaurante.' },
    'users': { title: 'Usuarios', desc: 'Gestiona los usuarios de tu negocio.' },
    'saas-admin': { title: 'SaaS Admin', desc: 'Gestion central de tenants, sucursales y usuarios del plataforma.' }
};

const UI_CONTEXT_KEY = 'restocloud_ui_context_v1';

function readUiContext() {
    try {
        const value = JSON.parse(localStorage.getItem(UI_CONTEXT_KEY) || '{}');
        return value && typeof value === 'object' ? value : {};
    } catch (e) {
        return {};
    }
}

function saveUiContext(patch = {}) {
    const current = readUiContext();
    try {
        localStorage.setItem(UI_CONTEXT_KEY, JSON.stringify({ ...current, ...patch, savedAt: Date.now() }));
    } catch (e) {}
}

function restoreUiContext(tabId) {
    const context = readUiContext();
    const subtab = context.subtabs?.[tabId];

    if (tabId === 'menu-config' && subtab && typeof window.switchMenuConfigSubtab === 'function') {
        window.switchMenuConfigSubtab(subtab);
    } else if (tabId === 'reports' && subtab && typeof window.switchReportsSubtab === 'function') {
        window.switchReportsSubtab(subtab);
    } else if (tabId === 'reservations') {
        if (context.reservationsDate) state.reservationsDate = context.reservationsDate;
        if (context.reservationFilter && typeof window.filterReservations === 'function') {
            window.filterReservations(context.reservationFilter);
        }
        if (context.reservationSearch) {
            const input = document.getElementById('reservation-search-input');
            if (input) input.value = context.reservationSearch;
            state.reservationSearch = context.reservationSearch;
        }
        if (context.reservationCatalogTab && typeof window.switchReservationCatalogTab === 'function') {
            window.switchReservationCatalogTab(context.reservationCatalogTab);
        }
        if (context.reservationCatalogSearch && typeof window.setReservationCatalogSearch === 'function') {
            const catalogInput = document.getElementById('reservation-catalog-search');
            if (catalogInput) catalogInput.value = context.reservationCatalogSearch;
            window.setReservationCatalogSearch(context.reservationCatalogSearch);
        }
    } else if (tabId === 'saas-admin' && subtab && typeof window.switchSaasSubtab === 'function') {
        window.switchSaasSubtab(subtab);
    }

    requestAnimationFrame(() => {
        if (Number.isFinite(context.scrollY)) window.scrollTo(0, context.scrollY);
    });
}

function persistCurrentUiContext() {
    const current = readUiContext();
    saveUiContext({
        tab: currentTabId,
        scrollY: window.scrollY,
        subtabs: {
            ...(current.subtabs || {}),
            ...(window.activeMenuConfigSubtab ? { 'menu-config': window.activeMenuConfigSubtab } : {}),
            ...(window.activeReportsSubtab ? { reports: window.activeReportsSubtab } : {}),
            ...(window.activeSaasSubtab ? { 'saas-admin': window.activeSaasSubtab } : {})
        },
        reservationsDate: state.reservationsDate,
        reservationFilter: state.reservationFilter,
        reservationSearch: state.reservationSearch || '',
        reservationCatalogTab: state.reservationCatalogTab,
        reservationCatalogSearch: state.reservationCatalogSearch || ''
    });
}

if (window.AppViewController) {
    AppViewController.register('pos', () => {
        if (window.PosView) {
            window.PosView.render();
            try { if (window.populateTableSelect) populateTableSelect(); } catch (e) {}
        }
    });
    AppViewController.register('active-orders', () => {
        try { renderFloorPlan(); } catch (e) { console.error('renderFloorPlan error:', e); }
        try { renderActiveOrders(); } catch (e) { console.error('renderActiveOrders error:', e); }
        try { if (window.populateTableSelect) populateTableSelect(); } catch (e) { console.error('populateTableSelect error:', e); }
    });
    AppViewController.register('menu-config', renderMenuConfig);
    AppViewController.register('inventory', renderInventoryTab);
    AppViewController.register('reports', renderReports);
    AppViewController.register('financial', renderFinancial);
    AppViewController.register('customers', renderCustomers);
    AppViewController.register('dashboard', () => {
        renderDashboard();
        DashboardController.init();
    });
    AppViewController.register('reservations', renderReservations);
    AppViewController.register('users', renderTenantUsers);
    AppViewController.register('saas-admin', () => {
        renderSaasAdmin();
        if (typeof window.initSaasAdminController === 'function') {
            window.initSaasAdminController();
        }
    });
}

function renderCurrentTab(tabId) {
    try {
        if (window.AppViewController) {
            AppViewController.render(tabId);
            return;
        }
        updateHeaderMetrics();
    } catch (e) {
        console.error('renderCurrentTab error:', e);
    }
}

async function switchTab(tabId, options = {}) {
    if (window.AppAuth && !window.AppAuth.isAuthenticated()) {
        window.AppAuth.showLogin();
        return;
    }

    currentTabId = tabId;
    localStorage.setItem('restocloud_active_tab', tabId);
    if (window.location.hash !== '#' + tabId) {
        history.replaceState(null, '', '#' + tabId);
    }

    const sidebar = document.getElementById('app-sidebar');
    const overlay = document.getElementById('mobile-overlay');
    if (sidebar) sidebar.classList.remove('mobile-open');
    if (overlay) overlay.classList.remove('active');
    document.body.classList.remove('no-scroll');

    document.querySelectorAll('.nav-menu .nav-item').forEach(btn => btn.classList.remove('active'));
    const activeBtn = document.querySelector(`.nav-menu .nav-item[data-tab="${tabId}"]`);
    if (activeBtn) activeBtn.classList.add('active');

    document.querySelectorAll('.screen-section').forEach(section => {
        section.classList.remove('active');
    });
    const targetSection = document.getElementById(`screen-${tabId}`);
    if (targetSection) targetSection.classList.add('active');

    const tabInfo = tabs[tabId];
    if (tabInfo) {
        document.getElementById('current-tab-title').textContent = tabInfo.title;
        document.getElementById('current-tab-desc').textContent = tabInfo.desc;
    }

    if (tabId === 'active-orders') {
        const searchInput = document.getElementById('search-active-orders');
        if (searchInput) {
            searchInput.value = '';
        }
    }

    if (!options.skipLoad) {
        if (typeof loadStateForTab === 'function') {
            await loadStateForTab(tabId);
        } else {
            await loadStateFromServer();
        }
    }

    renderCurrentTab(tabId);
    if (options.restoreContext !== false) restoreUiContext(tabId);
    persistCurrentUiContext();
}

function applyRoleVisibility() {
    const userRole = (state.authUser || {}).role || '';
    const permissions = state.permissions || [];
    const features = state.features || {};
    document.querySelectorAll('.nav-menu .nav-item[data-role], .nav-menu .nav-item[data-permission]').forEach(btn => {
        let visible = true;
        const allowedRoles = btn.getAttribute('data-role');
        if (allowedRoles) {
            const roles = allowedRoles.split(',').map(r => r.trim());
            visible = roles.includes(userRole);
        }
        if (visible && btn.hasAttribute('data-permission')) {
            const requiredPerm = btn.getAttribute('data-permission');
            visible = permissions.includes(requiredPerm);
        }
        if (visible && userRole !== 'super_admin') {
            const tabId = btn.getAttribute('data-tab');
            const featureMap = { reservations: 'reservations', inventory: 'inventory' };
            if (featureMap[tabId] && !features[featureMap[tabId]]) {
                visible = false;
            }
        }
        btn.style.display = visible ? '' : 'none';
    });
}

function initReactiveRendering() {
    if (!window.AppStore) return;

    AppStore.subscribe(() => {
        try {
            // Dashboard fetches its own data, skip reactive re-render
            if (currentTabId === 'dashboard') return;
            if (window.AppViewController && currentTabId) {
                AppViewController.render(currentTabId);
                return;
            }
            updateHeaderMetrics();
        } catch (e) {
            console.error('Reactive render error:', e);
        }
    });
}

function initAutoAnimations() {
    if (!window.AppAutoAnimate) return;

    const animationTargets = [
        document.getElementById('cart-items-container'),
        document.getElementById('pos-catalog-cards'),
        document.getElementById('active-orders-board'),
        document.getElementById('tables-map-grid')
    ];

    animationTargets.forEach(target => {
        window.AppAutoAnimate.enable(target, {
            duration: 180,
            easing: 'ease-out'
        });
    });
}

// ==========================================================================
// KEYBOARD SHORTCUTS
// ==========================================================================

function initKeyboardShortcuts() {
    // Sidebar toggle
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('app-sidebar');
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('restocloud_sidebar_collapsed', sidebar.classList.contains('collapsed'));
        });
        // Restore saved state
        if (localStorage.getItem('restocloud_sidebar_collapsed') === 'true') {
            sidebar.classList.add('collapsed');
        }
    }

    document.addEventListener('keydown', (e) => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
            return;
        }

        if (e.ctrlKey && e.key === 'b') {
            e.preventDefault();
            const sidebar = document.getElementById('app-sidebar');
            if (sidebar) sidebar.classList.toggle('collapsed');
        }

        if (e.altKey) {
            switch(e.key) {
                case '1':
                    e.preventDefault();
                    switchTab('pos');
                    showToast('Atajo: Toma de Pedidos', 'info');
                    break;
                case '2':
                    e.preventDefault();
                    switchTab('active-orders');
                    showToast('Atajo: Pedidos Activos', 'info');
                    break;
                case '3':
                    e.preventDefault();
                    switchTab('menu-config');
                    showToast('Atajo: Platos y Menu', 'info');
                    break;
                case '4':
                    e.preventDefault();
                    switchTab('inventory');
                    showToast('Atajo: Control Stock', 'info');
                    break;
                case '5':
                    e.preventDefault();
                    switchTab('reports');
                    showToast('Atajo: Finanzas y Reportes', 'info');
                    break;
                case '6':
                    e.preventDefault();
                    switchTab('dashboard');
                    showToast('Atajo: Dashboard', 'info');
                    break;
                case '7':
                    e.preventDefault();
                    switchTab('reservations');
                    showToast('Atajo: Reservas', 'info');
                    break;
                case 's':
                    e.preventDefault();
                    const btnToggleSound = document.getElementById('btn-toggle-sound');
                    if (btnToggleSound) btnToggleSound.click();
                    break;
                case 't':
                    e.preventDefault();
                    const btnToggleTheme = document.getElementById('btn-toggle-theme');
                    if (btnToggleTheme) btnToggleTheme.click();
                    break;
            }
        }

        if (e.key === 'F2') {
            e.preventDefault();
            const searchInput = document.getElementById('search-active-orders');
            if (searchInput) {
                searchInput.focus();
            }
        }
    });
}

window.switchTab = switchTab;
window.renderCurrentTab = renderCurrentTab;
window.applyRoleVisibility = applyRoleVisibility;
window.saveUiContext = saveUiContext;
window.getUiContext = readUiContext;
window.persistCurrentUiContext = persistCurrentUiContext;

window.addEventListener('pagehide', persistCurrentUiContext);
window.addEventListener('beforeunload', persistCurrentUiContext);
