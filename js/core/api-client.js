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
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), options.timeout || 20000);
        fetchOptions.signal = controller.signal;

        if (method !== 'GET' && payload !== null) {
            fetchOptions.body = JSON.stringify(payload);
        }

        let response;
        try {
            const requestUrl = new URL(API_URL.href);
            requestUrl.searchParams.set('action', action);
            if (options.params) {
                for (const [key, val] of Object.entries(options.params)) {
                    if (val !== null && val !== undefined && val !== '') {
                        requestUrl.searchParams.set(key, val);
                    }
                }
            }
            response = await fetch(requestUrl.href, fetchOptions);
        } catch (error) {
            clearTimeout(timeoutId);
            if (error.name === 'AbortError') throw new Error(`El servidor tardó demasiado en responder (HTTP timeout).`);
            throw new Error(`No se pudo conectar con el API (${API_URL.pathname}).`);
        }
        clearTimeout(timeoutId);

        if (response.status === 401) {
            window.dispatchEvent(new CustomEvent('restocloud:unauthorized'));
        }

        let text;
        try {
            text = await response.text();
        } catch (error) {
            throw new Error(`Error de red (HTTP ${response.status}).`);
        }

        let data;
        try {
            data = JSON.parse(text);
        } catch (error) {
            const snippet = text.substring(0, 120).replace(/\s+/g, ' ').trim();
            throw new Error(`El servidor respondió con HTTP ${response.status}: ${snippet || '(respuesta vacía)'}`);
        }

        if (data.csrf_token) {
            csrfToken = data.csrf_token;
        }

        if (!response.ok || data.status !== 'success') {
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
        getReports: (startDate, endDate) => request('get_reports', null, { method: 'GET', params: startDate && endDate ? { start_date: startDate, end_date: endDate } : {} }),
        getDashboard: (startDate, endDate) => request('get_dashboard', null, { method: 'GET', params: { start_date: startDate, end_date: endDate } }),
        getFinancialReport: (startDate, endDate, branchId = '') => request('get_financial_report', null, { method: 'GET', params: { start_date: startDate, end_date: endDate, branch_id: branchId } }),
        getBranchComparison: (startDate, endDate) => request('get_branch_comparison', null, { method: 'GET', params: { start_date: startDate, end_date: endDate } }),
        getCashFlow: (startDate, endDate) => request('get_cash_flow', null, { method: 'GET', params: { start_date: startDate, end_date: endDate } }),
        getControlReport: (startDate, endDate) => request('get_control_report', null, { method: 'GET', params: { start_date: startDate, end_date: endDate } }),
        getReceivables: (status = '', search = '') => request('get_receivables', null, { method: 'GET', params: { status, search } }),
         getRefundsReport: (startDate = '', endDate = '', branchId = '') => request('get_refunds_report', null, { method: 'GET', params: { start_date: startDate, end_date: endDate, branch_id: branchId } }),
         createRefund: (refund) => request('create_refund', refund),
        suggestPromos: (items, channel = 'pos', couponCode = '') => request('suggest_promos', { items, channel, coupon_code: couponCode }),
        validatePromo: (planId, items, channel = 'pos') => request('validate_promo', { plan_id: planId, items, channel }),
        applyPromoCoupon: (couponCode, items, channel = 'pos', orderId = null, reservationId = null) => request('apply_promo_coupon', { coupon_code: couponCode, items, channel, order_id: orderId, reservation_id: reservationId }),
        getPromoPlans: () => request('get_promo_plans', null, { method: 'GET' }),
        savePromoPlan: (plan) => request('save_promo_plan', plan),
        deletePromoPlan: (id) => request('delete_promo_plan', { id }),
        getCustomers: (search = '') => request('get_customers', null, { method: 'GET', params: { search } }),
        getCustomerProfile: (id) => request('get_customer_profile', null, { method: 'GET', params: { id } }),
        saveCustomer: (customer) => request('save_customer', customer),
        saveCustomerInteraction: (interaction) => request('save_customer_interaction', interaction),
        saveCollectionTask: (task) => request('save_collection_task', task),
        getChatbotConversations: () => request('get_chatbot_conversations', null, { method: 'GET' }),
        getChatbotMessages: (conversationId) => request('get_chatbot_messages', null, { method: 'GET', params: { conversation_id: conversationId } }),
        updateChatbotAttention: (conversationId, attentionMode, assignedUserId = '') => request('update_chatbot_attention', { conversation_id: conversationId, attention_mode: attentionMode, assigned_user_id: assignedUserId }),
        sendHumanWhatsAppReply: (conversationId, content) => request('send_human_whatsapp_reply', { conversation_id: conversationId, content }),
        saveReceivablePayment: (payment) => request('save_receivable_payment', payment),
        getSaasAdmin: () => request('get_saas_admin'),
        login: (email, password) => request('auth_login', { email, password }),
        logout: () => request('auth_logout', {}),
        me: () => request('auth_me'),
        switchBranch: (branchId) => request('switch_branch', { branch_id: branchId }),
        getReservations: (date, status) => request('get_reservations', null, { method: 'GET', params: { date: date, status: status || '' } }),
        saveReservation: (reservation) => request('save_reservation', reservation),
        updateReservationStatus: (id, status) => request('update_reservation_status', { id: id, status: status }),
        deleteReservation: (id) => request('delete_reservation', { id: id }),
        verifyBotReservations: (ids) => request('verify_bot_reservations', { ids }),
        markBotReservationsPrinted: (ids) => request('mark_bot_reservations_printed', { ids }),
        appendOrderItems: (orderId, items, markPaid = false, paymentMethod = null) => request('append_order_items', { id: orderId, items: items, markPaid: markPaid, paymentMethod: paymentMethod }),
        getCategories: () => request('get_categories'),
        saveCategory: (category) => request('save_category', category),
        deleteCategory: (id) => request('delete_category', { id: id }),
        reorderCategories: (order) => request('reorder_categories', { order: order }),
        importTenantData: (data, confirm) => request('import_tenant_data', { data: data, confirm: confirm }),
        getStockHistory: (itemType, itemId, startDate, endDate) => request('get_stock_history', null, { method: 'GET', params: { item_type: itemType, item_id: itemId, start_date: startDate, end_date: endDate } }),
        getStockReport: (startDate, endDate) => request('get_stock_report', null, { method: 'GET', params: { start_date: startDate, end_date: endDate } }),
        getDiscounts: () => request('get_discounts'),
        saveDiscount: (discount) => request('save_discount', discount),
        deleteDiscount: (id) => request('delete_discount', { id: id }),
        calculateDiscount: (items) => request('calculate_discount', { items: items }),
         closeCashDay: (data) => request('close_cash_day', data)
         ,savePrintSettings: (settings) => request('save_print_settings', settings)
    };
})(window);
