// ==========================================================================
// TAB NAVIGATION + KEYBOARD SHORTCUTS (depends on: state.js)
// ==========================================================================

const tabs = {
    'pos': { title: 'Toma de Pedidos', desc: 'Gestiona y crea nuevas ordenes para el dia.' },
    'active-orders': { title: 'Pedidos Activos', desc: 'Control de mesas e impresion de comandas en preparacion.' },
    'menu-config': { title: 'Platos y Menu', desc: 'Configura platos del dia, platos extras, gaseosas y precios base.' },
    'inventory': { title: 'Control Stock', desc: 'Verifica y ajusta las cantidades en stock del dia.' },
    'reports': { title: 'Ventas e Historial', desc: 'Metricas de recaudacion e impresion de recibos.' },
    'dashboard': { title: 'Dashboard', desc: 'Resumen ejecutivo del rendimiento del restaurante.' },
    'reservations': { title: 'Reservas', desc: 'Gestiona las reservaciones de mesas del restaurante.' },
    'users': { title: 'Usuarios', desc: 'Gestiona los usuarios de tu negocio.' },
    'saas-admin': { title: 'SaaS Admin', desc: 'Gestion central de tenants, sucursales y usuarios del plataforma.' }
};

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

async function switchTab(tabId) {
    if (window.AppAuth && !window.AppAuth.isAuthenticated()) {
        window.AppAuth.showLogin();
        return;
    }

    currentTabId = tabId;

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

    let stateLoaded = false;
    if (typeof loadStateForTab === 'function') {
        stateLoaded = await loadStateForTab(tabId);
    } else {
        stateLoaded = await loadStateFromServer();
    }

    renderCurrentTab(tabId);
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
