(function (window) {
    let currentState = null;
    const listeners = new Set();

    function create(initialState) {
        currentState = initialState;
        return api;
    }

    function get() {
        return currentState;
    }

    function set(patch) {
        if (!currentState) return;
        Object.assign(currentState, patch);
        emit();
    }

    function subscribe(listener) {
        listeners.add(listener);
        return () => listeners.delete(listener);
    }

    function emit() {
        listeners.forEach(listener => {
            try { listener(currentState); } catch(e) { console.error('[AppStore] Listener error:', e); }
        });
    }

    const api = { create, get, set, subscribe, emit };
    window.AppStore = api;
})(window);
