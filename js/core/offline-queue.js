// ==========================================================================
// OFFLINE ORDER QUEUE — IndexedDB-backed retry for network failures
// ==========================================================================

const OfflineQueue = (function() {
    const DB_NAME = 'restocloud-offline';
    const DB_VERSION = 1;
    const STORE_NAME = 'pending-orders';
    let db = null;

    function open() {
        return new Promise((resolve, reject) => {
            if (db) { resolve(db); return; }
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = function(e) {
                const database = e.target.result;
                if (!database.objectStoreNames.contains(STORE_NAME)) {
                    database.createObjectStore(STORE_NAME, { keyPath: 'id' });
                }
            };
            req.onsuccess = function(e) { db = e.target.result; resolve(db); };
            req.onerror = function(e) { reject(e.target.error); };
        });
    }

    async function enqueue(order) {
        const database = await open();
        const session = window.state?.session || {};
        const tenantId = session.tenant_id || '';
        const branchId = session.branch_id || '';
        const userId = window.state?.authUser?.id || '';
        const orderWithMeta = {
            ...order,
            _tenant_id: tenantId,
            _branch_id: branchId,
            _user_id: userId,
            _queued_at: Date.now()
        };
        return new Promise((resolve, reject) => {
            const tx = database.transaction(STORE_NAME, 'readwrite');
            tx.objectStore(STORE_NAME).put(orderWithMeta);
            tx.oncomplete = function() { resolve(); };
            tx.onerror = function(e) { reject(e.target.error); };
        });
    }

    async function getAll() {
        const database = await open();
        const session = window.state?.session || {};
        const tenantId = session.tenant_id || '';
        const branchId = session.branch_id || '';
        const userId = window.state?.authUser?.id || '';
        return new Promise((resolve, reject) => {
            const tx = database.transaction(STORE_NAME, 'readonly');
            const req = tx.objectStore(STORE_NAME).getAll();
            req.onsuccess = function() {
                const all = req.result || [];
                resolve(all.filter(o => o._tenant_id === tenantId && o._branch_id === branchId && o._user_id === userId));
            };
            req.onerror = function(e) { reject(e.target.error); };
        });
    }

    async function remove(orderId) {
        const database = await open();
        return new Promise((resolve, reject) => {
            const tx = database.transaction(STORE_NAME, 'readwrite');
            tx.objectStore(STORE_NAME).delete(orderId);
            tx.oncomplete = function() { resolve(); };
            tx.onerror = function(e) { reject(e.target.error); };
        });
    }

    async function count() {
        const database = await open();
        return new Promise((resolve, reject) => {
            const tx = database.transaction(STORE_NAME, 'readonly');
            const req = tx.objectStore(STORE_NAME).count();
            req.onsuccess = function() { resolve(req.result); };
            req.onerror = function(e) { reject(e.target.error); };
        });
    }

    let replayInProgress = false;

    async function replayAll(onSuccess, onError) {
        if (replayInProgress) return 0;
        replayInProgress = true;
        try {
            const orders = await getAll();
            let replayed = 0;
            for (const order of orders) {
                try {
                    const { _tenant_id, _branch_id, _user_id, _queued_at, ...cleanOrder } = order;
                    await AppApi.request('save_order', cleanOrder);
                    await remove(order.id);
                    replayed++;
                    if (onSuccess) onSuccess(order);
                } catch (e) {
                    if (onError) onError(order, e);
                }
            }
            return replayed;
        } finally {
            replayInProgress = false;
        }
    }

    async function purgeStale(maxAgeMs) {
        const database = await open();
        const all = await getAll();
        const now = Date.now();
        const staleOrders = all.filter(o => o._queued_at && (now - o._queued_at) > maxAgeMs);
        for (const o of staleOrders) {
            await remove(o.id);
        }
        return staleOrders.length;
    }

    return { enqueue, getAll, remove, count, replayAll, purgeStale };
})();

window.OfflineQueue = OfflineQueue;
