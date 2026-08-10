// ==========================================================================
// TOAST NOTIFICATIONS (standalone, no dependencies)
// ==========================================================================

function showToast(message, type = 'info') {
    if (!message) return;
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    let icon = 'fa-info-circle';
    if (type === 'success') icon = 'fa-check-circle';
    else if (type === 'warning') icon = 'fa-exclamation-triangle';
    else if (type === 'error') icon = 'fa-exclamation-circle';

    const safeMessage = (typeof escapeHtml === 'function') ? escapeHtml(message) : String(message);

    toast.innerHTML = `
        <i class="fa-solid ${icon}"></i>
        <span>${safeMessage}</span>
    `;
    container.appendChild(toast);

    setTimeout(() => {
        if (toast && toast.parentNode) {
            toast.remove();
        }
    }, 3200);
}

window.showToast = showToast;
