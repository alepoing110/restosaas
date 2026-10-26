(function (window) {
    let autoAnimateLoader = null;

    async function loadAutoAnimate() {
        if (autoAnimateLoader === null) {
            autoAnimateLoader = import('../vendor/auto-animate.min.js')
                .then(module => module.autoAnimate)
                .catch(error => {
                    console.error('AutoAnimate load error: ', error);
                    autoAnimateLoader = undefined;
                    return null;
                });
        }
        if (autoAnimateLoader === undefined) return null;
        return autoAnimateLoader;
    }

    async function enable(target, options) {
        if (!target) return null;

        const autoAnimate = await loadAutoAnimate();
        if (!autoAnimate) return null;

        return autoAnimate(target, options);
    }

    window.AppAutoAnimate = {
        enable
    };
})(window);
