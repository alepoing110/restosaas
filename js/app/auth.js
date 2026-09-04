(function (window) {
    let authenticated = false;

    function getOverlay() {
        return document.getElementById('auth-overlay');
    }

    function getForm() {
        return document.getElementById('auth-login-form');
    }

    function updateAuthSummary(payload) {
        const userName = payload?.authUser?.name || 'Sin sesión';
        const branchName = payload?.branch?.name || 'Sucursal no disponible';
        const tenantName = payload?.tenant?.name || 'Tenant no disponible';
        const roleName = payload?.authUser?.role || '-';

        const userEl = document.getElementById('header-auth-user');
        const metaEl = document.getElementById('header-auth-meta');
        const branchSelect = document.getElementById('header-branch-switcher');
        if (userEl) userEl.textContent = userName;
        if (metaEl) metaEl.textContent = `${tenantName} · ${branchName} · ${roleName}`;
        if (branchSelect) {
            const branches = payload?.authorizedBranches || [];
            branchSelect.innerHTML = branches.map(branch => `<option value="${branch.id}">${branch.name}</option>`).join('');
            branchSelect.value = payload?.branch?.id || '';
            branchSelect.hidden = branches.length < 2;
        }
    }

    function applyAuthPayload(payload) {
        authenticated = !!payload?.authenticated;
        if (window.AppStore) {
            window.AppStore.set({
                session: payload?.session || null,
                authUser: payload?.authUser || null,
                tenant: payload?.tenant || null,
                branch: payload?.branch || null,
                authorizedBranches: payload?.authorizedBranches || [],
                permissions: payload?.permissions || [],
                subscription: payload?.subscription || null
            });
        } else if (window.state) {
            window.state.session = payload?.session || null;
            window.state.authUser = payload?.authUser || null;
            window.state.tenant = payload?.tenant || null;
            window.state.branch = payload?.branch || null;
            window.state.authorizedBranches = payload?.authorizedBranches || [];
            window.state.permissions = payload?.permissions || [];
            window.state.subscription = payload?.subscription || null;
        }
        updateAuthSummary(payload || {});
        if (typeof window.applyRoleVisibility === 'function') {
            window.applyRoleVisibility();
        }
    }

    function syncPermissionVisibility(payload) {
        const permissions = payload?.permissions || [];
        const saasNav = document.getElementById('nav-saas-admin');
        if (saasNav) {
            saasNav.style.display = permissions.includes('saas_admin') ? '' : 'none';
        }
    }

    function syncFeatureVisibility(features) {
        if (!features) return;
        const role = window.state?.authUser?.role || '';
        if (role === 'super_admin') return;
        const featureNavMap = {
            reservations: 'nav-reservations',
            inventory: 'nav-inventory',
            delivery: 'nav-pos'
        };
        Object.entries(featureNavMap).forEach(([feature, navId]) => {
            const nav = document.getElementById(navId);
            if (nav) {
                nav.style.display = features[feature] ? '' : 'none';
            }
        });
    }

    window.syncFeatureVisibility = syncFeatureVisibility;

    function showLogin(message) {
        const overlay = getOverlay();
        if (overlay) overlay.classList.add('open');
        const messageEl = document.getElementById('auth-login-message');
        if (messageEl) {
            messageEl.textContent = message || 'Inicia sesión para entrar al POS.';
        }
        const form = getForm();
        if (form) {
            form.reset();
            const emailInput = form.querySelector('[name="email"]');
            const passInput = form.querySelector('[name="password"]');
            if (emailInput) emailInput.value = '';
            if (passInput) passInput.value = '';
        }
    }

    function hideLogin() {
        const overlay = getOverlay();
        if (overlay) overlay.classList.remove('open');
    }

    async function bootstrap() {
        try {
            const payload = await window.AppApi.me();
            if (!payload.authenticated) {
                authenticated = false;
                showLogin();
                return false;
            }
            applyAuthPayload(payload);
            hideLogin();
            if (window.AppWebSocket && payload.session) {
                try {
                    AppWebSocket.connect(payload.session.tenant_id, payload.session.branch_id);
                } catch (wsErr) {
                    console.warn('[WS] Connect failed on bootstrap:', wsErr.message);
                }
            }
            startHeartbeat();
            return true;
        } catch (error) {
            authenticated = false;
            showLogin('No pudimos validar la sesión actual.');
            return false;
        }
    }

    async function submitLogin(event) {
        event.preventDefault();

        const form = getForm();
        const email = form?.querySelector('[name="email"]')?.value?.trim() || '';
        const password = form?.querySelector('[name="password"]')?.value || '';

        if (!email || !password) {
            if (window.showToast) showToast('Completa email y contraseña.', 'error');
            return;
        }

        const submitButton = document.getElementById('auth-login-submit');
        if (submitButton) submitButton.disabled = true;
        loginInProgress = true;

        try {
            const payload = await window.AppApi.login(email, password);
            applyAuthPayload(payload);
            hideLogin();
            if (window.showToast) showToast(`Bienvenido, ${payload.authUser.name}.`, 'success');
            if (window.AppWebSocket && payload.session) {
                try {
                    AppWebSocket.connect(payload.session.tenant_id, payload.session.branch_id);
                } catch (wsErr) {
                    console.warn('[WS] Connect failed:', wsErr.message);
                }
            }
            startHeartbeat();
            if (typeof window.startAuthenticatedApp === 'function') {
                await window.startAuthenticatedApp(true);
            }
        } catch (error) {
            showLogin(error.message || 'No se pudo iniciar sesión.');
            if (window.showToast) showToast(error.message || 'No se pudo iniciar sesión.', 'error');
            console.error('Auth login error:', error);
        } finally {
            loginInProgress = false;
            if (submitButton) submitButton.disabled = false;
        }
    }

    async function logout() {
        stopHeartbeat();
        try {
            await window.AppApi.logout();
        } catch (error) {
            // best-effort
        }

        authenticated = false;
        applyAuthPayload({ authenticated: false });
        showLogin('Tu sesión se cerró.');
        if (window.showToast) showToast('Sesión cerrada.', 'info');
    }

    async function switchBranch(branchId) {
        if (!branchId || branchId === window.state?.branch?.id) return;
        try {
            const payload = await window.AppApi.switchBranch(branchId);
            if (window.AppWebSocket) AppWebSocket.disconnect();
            applyAuthPayload(payload);
            if (window.AppWebSocket && payload.session) {
                AppWebSocket.connect(payload.session.tenant_id, payload.session.branch_id);
            }
            if (typeof window.loadStateFromServer === 'function') await window.loadStateFromServer();
            if (typeof window.switchTab === 'function') window.switchTab('pos');
            if (window.showToast) showToast(`Sucursal activa: ${payload.branch.name}`, 'success');
        } catch (error) {
            if (window.showToast) showToast(error.message || 'No se pudo cambiar de sucursal.', 'error');
            const select = document.getElementById('header-branch-switcher');
            if (select) select.value = window.state?.branch?.id || '';
        }
    }

    function bind() {
        const form = getForm();
        if (form && !form.dataset.bound) {
            form.dataset.bound = 'true';
            form.addEventListener('submit', submitLogin);
        }

        const logoutButton = document.getElementById('btn-auth-logout');
        if (logoutButton && !logoutButton.dataset.bound) {
            logoutButton.dataset.bound = 'true';
            logoutButton.addEventListener('click', logout);
        }
        const branchSelect = document.getElementById('header-branch-switcher');
        if (branchSelect && !branchSelect.dataset.bound) {
            branchSelect.dataset.bound = 'true';
            branchSelect.addEventListener('change', () => switchBranch(branchSelect.value));
        }

        if (!window._authUnauthorizedBound) {
            window._authUnauthorizedBound = true;
            window.addEventListener('restocloud:unauthorized', () => {
                authenticated = false;
                stopHeartbeat();
                if (window.AppWebSocket) {
                    try { AppWebSocket.disconnect(); } catch (e) {}
                }
                showLogin('La sesión expiró. Vuelve a iniciar sesión.');
            });
        }
    }

    let heartbeatTimer = null;
    let loginInProgress = false;

    function startHeartbeat() {
        stopHeartbeat();
        heartbeatTimer = setInterval(async () => {
            if (!authenticated || loginInProgress) return;
            try {
                const payload = await window.AppApi.me();
                if (loginInProgress) return;
                if (!payload.authenticated) {
                    authenticated = false;
                    showLogin('Tu sesión expiró por inactividad.');
                    if (window.showToast) showToast('Sesión expirada. Vuelve a iniciar sesión.', 'warning');
                }
            } catch (e) {
                console.warn('[AUTH] Heartbeat check failed:', e.message);
            }
        }, 5 * 60 * 1000);
    }

    function stopHeartbeat() {
        if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
    }

    window.AppAuth = {
        bind,
        bootstrap,
        logout,
        showLogin,
        hideLogin,
        isAuthenticated: () => authenticated,
        applyAuthPayload,
        switchBranch,
        startHeartbeat,
        stopHeartbeat
    };
})(window);
