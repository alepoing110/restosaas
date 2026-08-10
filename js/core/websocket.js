// ==========================================================================
// WEBSOCKET CLIENT - Real-time multi-user updates
// ==========================================================================

const AppWebSocket = (function() {
    let ws = null;
    let reconnectAttempts = 0;
    const MAX_RECONNECT = 10;
    const BASE_DELAY = 1000;
    let tenantId = null;
    let branchId = null;
    let listeners = {};
    let connected = false;

    function connect(tid, bid) {
        tenantId = tid;
        branchId = bid;

        const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const host = window.location.hostname;
        const wsPort = 8080;
        const url = `${protocol}//${host}:${wsPort}`;

        try {
            ws = new WebSocket(url);
        } catch (e) {
            console.warn('[WS] Connection failed:', e.message);
            scheduleReconnect();
            return;
        }

        ws.onopen = function() {
            console.log('[WS] Connected');
            connected = true;
            reconnectAttempts = 0;

            ws.send(JSON.stringify({
                type: 'auth',
                tenant_id: tenantId,
                branch_id: branchId
            }));

            emit('connected', {});
        };

        ws.onmessage = function(event) {
            let message;
            try {
                message = JSON.parse(event.data);
            } catch (e) {
                return;
            }

            const type = message.type || '';

            if (type === 'auth_ok') {
                console.log('[WS] Authenticated for tenant:', message.tenant_id);
                return;
            }

            if (type === 'pong') return;

            if (type === 'connected') {
                console.log('[WS] Server:', message.message);
                return;
            }

            if (type.startsWith('order.')) {
                handleOrderEvent(type, message.data || {});
            } else if (type === 'stock.changed') {
                handleStockEvent(message.data || {});
            } else if (type === 'reservation.changed') {
                handleReservationEvent(message.data || {});
            }

            emit(type, message.data || {});
        };

        ws.onclose = function() {
            console.log('[WS] Disconnected');
            connected = false;
            emit('disconnected', {});
            scheduleReconnect();
        };

        ws.onerror = function(error) {
            console.warn('[WS] Error:', error);
        };

        startHeartbeat();
    }

    function scheduleReconnect() {
        if (reconnectAttempts >= MAX_RECONNECT) {
            console.warn('[WS] Max reconnect attempts reached');
            emit('reconnect_failed', {});
            return;
        }

        reconnectAttempts++;
        const delay = Math.min(BASE_DELAY * Math.pow(2, reconnectAttempts - 1), 30000);
        console.log(`[WS] Reconnecting in ${delay}ms (attempt ${reconnectAttempts})`);

        setTimeout(function() {
            if (!connected && tenantId && branchId) {
                connect(tenantId, branchId);
            }
        }, delay);
    }

    let heartbeatInterval = null;
    function startHeartbeat() {
        if (heartbeatInterval) clearInterval(heartbeatInterval);
        heartbeatInterval = setInterval(function() {
            if (ws && ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({ type: 'ping' }));
            }
        }, 30000);
    }

    function disconnect() {
        if (heartbeatInterval) clearInterval(heartbeatInterval);
        reconnectAttempts = MAX_RECONNECT;
        if (ws) {
            ws.close();
            ws = null;
        }
        connected = false;
        tenantId = null;
        branchId = null;
        listeners = {};
    }

    function send(type, data) {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ type: type, data: data }));
        }
    }

    function on(event, callback) {
        if (!listeners[event]) listeners[event] = [];
        listeners[event].push(callback);
        return function off() {
            listeners[event] = listeners[event].filter(function(cb) { return cb !== callback; });
        };
    }

    function emit(event, data) {
        if (listeners[event]) {
            listeners[event].forEach(function(cb) {
                try { cb(data); } catch(e) { console.error('[WS] Listener error:', e); }
            });
        }
    }

    function handleOrderEvent(type, data) {
        if (typeof window.state === 'undefined') return;

        if (type === 'order.created' || type === 'order.updated' || type === 'order.appended') {
            if (data && data.id) {
                if (data.status === 'completado' || data.status === 'anulado') {
                    if (type === 'order.created') return;
                }
                if (type === 'order.appended' && typeof window.loadStateForTab === 'function') {
                    window.loadStateForTab('active-orders');
                    return;
                }
                if (typeof window.AppStore !== 'undefined') {
                    const state = window.AppStore.get();
                    if (state) {
                        const idx = (state.activeOrders || []).findIndex(function(o) { return o.id === data.id; });
                        if (idx >= 0) {
                            Object.assign(state.activeOrders[idx], data);
                        } else {
                            state.activeOrders.unshift(data);
                        }
                    }
                }
            }
        } else if (type === 'order.completed' || type === 'order.cancelled') {
            if (data && data.id) {
                if (typeof window.AppStore !== 'undefined') {
                    const state = window.AppStore.get();
                    if (state) {
                        state.activeOrders = state.activeOrders.filter(function(o) { return o.id !== data.id; });
                    }
                }
                if (typeof window.loadStateForTab === 'function') {
                    window.loadStateForTab('pos');
                }
            }
        }

        if (typeof window.updateHeaderMetrics === 'function') {
            window.updateHeaderMetrics();
        }
        if (typeof window.AppStore !== 'undefined') {
            window.AppStore.emit();
        }
    }

    function handleStockEvent(data) {
        if (typeof window.state === 'undefined') return;
        if (typeof window.loadStateForTab === 'function') {
            window.loadStateForTab('pos');
        }
    }

    function handleReservationEvent(data) {
        if (typeof window.state === 'undefined') return;
        if (typeof window.loadStateForTab === 'function') {
            window.loadStateForTab('reservations');
        }
    }

    return {
        connect: connect,
        disconnect: disconnect,
        send: send,
        on: on,
        isConnected: function() { return connected; }
    };
})();

window.AppWebSocket = AppWebSocket;
