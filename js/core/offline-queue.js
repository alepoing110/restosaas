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
        return new Promise((resolve, reject) => {
            const tx = database.transaction(STORE_NAME, 'readwrite');
            tx.objectStore(STORE_NAME).put(order);
            tx.oncomplete = function() { resolve(); };
            tx.onerror = function(e) { reject(e.target.error); };
        });
    }

    async function getAll() {
        const database = await open();
        return new Promise((resolve, reject) => {
            const tx = database.transaction(STORE_NAME, 'readonly');
            const req = tx.objectStore(STORE_NAME).getAll();
            req.onsuccess = function() { resolve(req.result || []); };
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
                    await AppApi.request('save_order', order);
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

    return { enqueue, getAll, remove, count, replayAll };
})();

window.OfflineQueue = OfflineQueue;
