(function (window) {
    const views = {};
    const dirtyTabs = {};
    let renderScheduled = false;

    function register(tabId, renderFn) {
        views[tabId] = renderFn;
    }

    function markDirty(tabId) {
        dirtyTabs[tabId] = true;
        scheduleRender();
    }

    function markAllDirty() {
        Object.keys(views).forEach(tabId => {
            dirtyTabs[tabId] = true;
        });
        scheduleRender();
    }

    function scheduleRender() {
        if (renderScheduled) return;
        renderScheduled = true;
        requestAnimationFrame(() => {
            renderScheduled = false;
            flushRenders();
        });
    }

    function flushRenders() {
        if (typeof window.updateHeaderMetrics === 'function') {
            window.updateHeaderMetrics();
        }

        Object.keys(dirtyTabs).forEach(tabId => {
            if (dirtyTabs[tabId] && views[tabId]) {
                views[tabId]();
                dirtyTabs[tabId] = false;
            }
        });
    }

    function render(tabId) {
        if (views[tabId]) {
            views[tabId]();
        }
    }

    window.AppViewController = {
        register,
        render,
        markDirty,
        markAllDirty
    };
})(window);
