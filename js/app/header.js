// ==========================================================================
// HEADER METRICS + THEME/SOUND TOGGLES (depends on: state.js, stock.js)
// ==========================================================================

function updateHeaderMetrics() {
    let totalSopaStock = 0;
    let totalSopaAvailable = 0;
    (state.sopas || []).forEach(s => {
        if (Number(s.active) !== 0) {
            totalSopaStock += s.stock || 0;
            totalSopaAvailable += getAvailableSopaStock(s.id);
        }
    });
    const soupStockEl = document.getElementById('header-soup-stock');
    if (soupStockEl) soupStockEl.textContent = `${totalSopaAvailable}/${totalSopaStock}`;

    const activeCount = state.activeOrders.filter(o => o.status === 'pendiente').length;
    const badge = document.getElementById('active-orders-count');
    if (badge) {
        badge.textContent = activeCount;
        badge.style.display = activeCount > 0 ? 'inline-block' : 'none';
    }

    // Calculate low stock items count (threshold = 5)
    let lowStockCount = 0;
    state.seconds.forEach(sec => {
        if (getAvailableSegundoStock(sec.id) <= 5) lowStockCount++;
    });
    state.platosExtras.forEach(plato => {
        if (getAvailablePlatoExtraStock(plato.id) <= 5) lowStockCount++;
    });
    state.extras.forEach(ext => {
        if (getAvailableExtraStock(ext.id) <= 5) lowStockCount++;
    });
    (state.sopas || []).forEach(s => {
        if (Number(s.active) !== 0 && getAvailableSopaStock(s.id) <= 5) lowStockCount++;
    });
    (state.salsas || []).forEach(s => {
        if (Number(s.active) !== 0 && getAvailableSalsaStock(s.id) <= 5) lowStockCount++;
    });
    (state.accompaniments || []).forEach(a => {
        if (Number(a.active) !== 0 && getAvailableAccompanimentStock(a.id) <= 5) lowStockCount++;
    });

    const alertPill = document.getElementById('header-low-stock-alert');
    if (alertPill) {
        if (lowStockCount > 0) {
            alertPill.style.display = 'flex';
            const countEl = document.getElementById('low-stock-count-header');
            if (countEl) countEl.textContent = `${lowStockCount} prod.`;
        } else {
            alertPill.style.display = 'none';
        }
    }
}

function initThemeToggle() {
    const btnToggleTheme = document.getElementById('btn-toggle-theme');
    if (!btnToggleTheme) return;

    const savedTheme = localStorage.getItem('restocloud_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeIcon(savedTheme);

    btnToggleTheme.addEventListener('click', () => {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('restocloud_theme', newTheme);
        updateThemeIcon(newTheme);
        showToast(newTheme === 'dark' ? 'Modo oscuro activado' : 'Modo claro activado', 'info');
    });
}

function updateThemeIcon(theme) {
    const btnToggleTheme = document.getElementById('btn-toggle-theme');
    if (btnToggleTheme) {
        btnToggleTheme.innerHTML = theme === 'dark'
            ? '<i class="fa-solid fa-moon"></i>'
            : '<i class="fa-solid fa-sun"></i>';
    }
}

function initSoundToggle() {
    const btnToggleSound = document.getElementById('btn-toggle-sound');
    if (!btnToggleSound) return;

    btnToggleSound.addEventListener('click', () => {
        const enabled = window.Notifications.toggleSound();
        btnToggleSound.innerHTML = enabled
            ? '<i class="fa-solid fa-bell"></i>'
            : '<i class="fa-solid fa-bell-slash"></i>';
        showToast(enabled ? 'Sonido de notificaciones activado' : 'Sonido de notificaciones desactivado', 'info');
    });
}

window.updateHeaderMetrics = updateHeaderMetrics;
