(function (window) {
    const JSON_HEADERS = { 'Content-Type': 'application/json' };
    const currentScript = document.currentScript || document.querySelector('script[src*="js/core/api-client.js"]');

    let csrfToken = null;

    function setCsrfToken(token) {
        csrfToken = token;
    }

    function getCsrfToken() {
        return csrfToken;
    }

    function resolveApiUrl() {
        if (window.APP_CONFIG && window.APP_CONFIG.API_BASE_URL) {
            return new URL(window.APP_CONFIG.API_BASE_URL, window.location.href);
        }

        if (window.location.protocol === 'http:' || window.location.protocol === 'https:') {
            if (currentScript) {
                return new URL('../../api.php', currentScript.src);
            }
            return new URL('api.php', window.location.href);
        }

        const projectName = 'RestoCloud';
        return new URL(`/` + projectName + `/api.php`, 'http://localhost');
    }

    const API_URL = resolveApiUrl();

    async function request(action, payload = null, options = {}) {
        const method = options.method || (payload === null ? 'GET' : 'POST');
        const headers = { ...JSON_HEADERS };
        if (csrfToken && method === 'POST') {
            headers['X-CSRF-Token'] = csrfToken;
        }
        const fetchOptions = {
            method,
            headers: method === 'GET' ? undefined : headers,
            credentials: 'same-origin'
        };

        if (method !== 'GET' && payload !== null) {
            fetchOptions.body = JSON.stringify(payload);
        }

        let response;
        try {
            const requestUrl = new URL(API_URL.href);
            requestUrl.searchParams.set('action', action);
            response = await fetch(requestUrl.href, fetchOptions);
        } catch (error) {
            throw new Error(`No se pudo conectar con el API (${API_URL.pathname}).`);
        }

        let data;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error('La respuesta del servidor no es JSON valido.');
        }

        if (data.csrf_token) {
            csrfToken = data.csrf_token;
        }

        if (!response.ok || data.status !== 'success') {
            if (response.status === 401) {
                window.dispatchEvent(new CustomEvent('restocloud:unauthorized'));
            }
            throw new Error(data.message || `Error ejecutando accion: ${action}`);
        }

        return data;
    }

    window.AppApi = {
        request,
        setCsrfToken,
        getCsrfToken,
        getState: () => request('get_state'),
        getPosState: () => request('get_pos_state'),
        getActiveOrders: () => request('get_active_orders'),
        getInventory: () => request('get_inventory'),
        getReports: (startDate, endDate) => request('get_reports', startDate && endDate ? { start_date: startDate, end_date: endDate } : null),
        getDashboard: (startDate, endDate) => request('get_dashboard', { start_date: startDate, end_date: endDate }),
        getSaasAdmin: () => request('get_saas_admin'),
        login: (email, password) => request('auth_login', { email, password }),
        logout: () => request('auth_logout', {}),
        me: () => request('auth_me'),
        getReservations: (date, status) => request('get_reservations', { date: date, status: status || '' }),
        saveReservation: (reservation) => request('save_reservation', reservation),
        updateReservationStatus: (id, status) => request('update_reservation_status', { id: id, status: status }),
        deleteReservation: (id) => request('delete_reservation', { id: id }),
        appendOrderItems: (orderId, items, markPaid = false) => request('append_order_items', { id: orderId, items: items, markPaid: markPaid }),
        getCategories: () => request('get_categories'),
        saveCategory: (category) => request('save_category', category),
        deleteCategory: (id) => request('delete_category', { id: id }),
        reorderCategories: (order) => request('reorder_categories', { order: order }),
        importTenantData: (data, confirm) => request('import_tenant_data', { data: data, confirm: confirm }),
        getStockHistory: (itemType, itemId, startDate, endDate) => request('get_stock_history', { item_type: itemType, item_id: itemId, start_date: startDate, end_date: endDate }),
        getStockReport: (startDate, endDate) => request('get_stock_report', { start_date: startDate, end_date: endDate }),
        getDiscounts: () => request('get_discounts'),
        saveDiscount: (discount) => request('save_discount', discount),
        deleteDiscount: (id) => request('delete_discount', { id: id }),
        calculateDiscount: (items) => request('calculate_discount', { items: items })
    };
})(window);
