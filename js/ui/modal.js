// ==========================================================================
// MODAL HELPERS + CONFIRM DIALOG + LOADING STATE (standalone, no dependencies)
// ==========================================================================

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('open');
        if (typeof window.trapFocus === 'function') trapFocus(modal);
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('open');
        if (typeof window.releaseFocus === 'function') releaseFocus();
    }
}

const LoadingState = {
    show(containerId, message = 'Cargando...') {
        const container = document.getElementById(containerId);
        if (!container) return;

        const loader = document.createElement('div');
        loader.className = 'loading-spinner';
        loader.id = `loader-${containerId}`;
        loader.innerHTML = `
            <div class="spinner"></div>
            <div class="loading-text">${escapeHtml(message)}</div>
        `;

        container.style.position = 'relative';
        container.appendChild(loader);
    },

    hide(containerId) {
        const loader = document.getElementById(`loader-${containerId}`);
        if (loader) loader.remove();
    },

    skeleton(type = 'card', count = 3) {
        const skeletons = [];
        for (let i = 0; i < count; i++) {
            if (type === 'card') {
                skeletons.push(`
                    <div class="skeleton skeleton-card"></div>
                `);
            } else if (type === 'text') {
                skeletons.push(`
                    <div class="skeleton skeleton-text" style="width: ${70 + Math.random() * 30}%"></div>
                `);
            } else if (type === 'circle') {
                skeletons.push(`
                    <div class="skeleton skeleton-circle"></div>
                `);
            }
        }
        return skeletons.join('');
    }
};

const ConfirmDialog = {
    show(message, options = {}) {
        return new Promise((resolve) => {
            const {
                title = 'Confirmar',
                confirmText = 'Confirmar',
                cancelText = 'Cancelar',
                type = 'warning' // warning, danger, info
            } = options;

            const existingDialog = document.getElementById('custom-confirm-dialog');
            if (existingDialog) existingDialog.remove();

            const typeColors = {
                warning: 'var(--warning)',
                danger: 'var(--danger)',
                info: 'var(--accent)'
            };

            const typeIcons = {
                warning: 'fa-exclamation-triangle',
                danger: 'fa-trash',
                info: 'fa-info-circle'
            };

            const dialog = document.createElement('div');
            dialog.id = 'custom-confirm-dialog';
            dialog.className = 'modal-backdrop';
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.innerHTML = `
                <div class="modal-content" style="max-width: 400px; padding: 24px;">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                        <div style="width: 40px; height: 40px; border-radius: 50%; background: ${typeColors[type]}20; display: flex; align-items: center; justify-content: center;">
                            <i class="fa-solid ${typeIcons[type]}" style="color: ${typeColors[type]}; font-size: 18px;"></i>
                        </div>
                        <h3 style="margin: 0; font-size: 18px; font-weight: 600;">${escapeHtml(title)}</h3>
                    </div>
                    <p style="margin: 0 0 24px 0; color: var(--text-muted); line-height: 1.5;">${escapeHtml(message)}</p>
                    <div style="display: flex; gap: 12px; justify-content: flex-end;">
                        <button class="btn btn-outline" id="confirm-dialog-cancel">${escapeHtml(cancelText)}</button>
                        <button class="btn ${type === 'danger' ? 'btn-danger' : 'btn-primary'}" id="confirm-dialog-confirm">${escapeHtml(confirmText)}</button>
                    </div>
                </div>
            `;

            document.body.appendChild(dialog);
            dialog.classList.add('open');

            const confirmBtn = document.getElementById('confirm-dialog-confirm');
            const cancelBtn = document.getElementById('confirm-dialog-cancel');

            const cleanup = () => {
                if (typeof window.releaseFocus === 'function') window.releaseFocus();
                dialog.remove();
            };

            if (typeof window.trapFocus === 'function') window.trapFocus(dialog);

            confirmBtn.addEventListener('click', () => {
                cleanup();
                resolve(true);
            });

            cancelBtn.addEventListener('click', () => {
                cleanup();
                resolve(false);
            });

            dialog.addEventListener('click', (e) => {
                if (e.target === dialog) {
                    cleanup();
                    resolve(false);
                }
            });
        });
    }
};

window.openModal = openModal;
window.closeModal = closeModal;
window.LoadingState = LoadingState;
window.ConfirmDialog = ConfirmDialog;
