// ==========================================================================
// PURE UTILITY FUNCTIONS (no dependencies, loaded early)
// ==========================================================================

function generateId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return 'id_' + crypto.randomUUID();
    }
    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
        return 'id_' + hex.substring(0, 32);
    }
    return 'id_' + Date.now().toString(36) + '_' + Math.random().toString(36).substring(2, 11);
}

function formatCurrency(amount) {
    return 'Bs ' + parseFloat(amount).toFixed(2);
}

const COUNTRY_TIMEZONE_MAP = {
    'Bolivia': 'America/La_Paz',
    'Perú': 'America/Lima',
    'Colombia': 'America/Bogota',
    'Ecuador': 'America/Guayaquil',
    'Argentina': 'America/Argentina/Buenos_Aires',
    'Chile': 'America/Santiago',
    'Venezuela': 'America/Caracas',
    'Brasil': 'America/Sao_Paulo',
    'Paraguay': 'America/Asuncion',
    'Uruguay': 'America/Montevideo',
    'México': 'America/Mexico_City'
};

function getBusinessTimezone() {
    const country = (window.state && window.state.business && window.state.business.pais) || 'Bolivia';
    return COUNTRY_TIMEZONE_MAP[country] || 'America/La_Paz';
}

function hasFeature(feature) {
    const features = (window.state && window.state.features) || {};
    const role = window.state?.authUser?.role || '';
    if (role === 'super_admin') return true;
    return features[feature] === true || features[feature] === 'completos';
}

function hasFeatureLevel(feature, level) {
    const features = (window.state && window.state.features) || {};
    const role = window.state?.authUser?.role || '';
    if (role === 'super_admin') return true;
    return features[feature] === level;
}

function formatTime(isoString) {
    const date = new Date(isoString);
    if (isNaN(date.getTime())) return '--:--';
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: getBusinessTimezone() });
}

function nowLocal() {
    const d = new Date();
    const yr = d.getFullYear();
    const mo = String(d.getMonth() + 1).padStart(2, '0');
    const dy = String(d.getDate()).padStart(2, '0');
    const hr = String(d.getHours()).padStart(2, '0');
    const mn = String(d.getMinutes()).padStart(2, '0');
    const sc = String(d.getSeconds()).padStart(2, '0');
    return `${yr}-${mo}-${dy}T${hr}:${mn}:${sc}`;
}

function getSaleTime(sale) {
    if (sale && sale.soldAt) return sale.soldAt;
    return sale ? sale.timestamp : null;
}

function todayLocal() {
    return nowLocal().slice(0, 10);
}

function escapeHtml(str) {
    if (str == null) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}

function setButtonLoading(btn, loading) {
    if (!btn) return;
    if (loading) {
        btn.classList.add('loading');
        btn.disabled = true;
        if (!btn.querySelector('.btn-spinner')) {
            const spinner = document.createElement('span');
            spinner.className = 'btn-spinner';
            btn.prepend(spinner);
        }
    } else {
        btn.classList.remove('loading');
        btn.disabled = false;
        const spinner = btn.querySelector('.btn-spinner');
        if (spinner) spinner.remove();
    }
}

function debounce(fn, delay) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}

/**
 * Parse payment method from DB/API format.
 * Returns { efectivo: number, qr: number, tarjeta: number, label: string }
 */
window.generateId = generateId;
window.formatCurrency = formatCurrency;
window.formatTime = formatTime;
window.nowLocal = nowLocal;
window.todayLocal = todayLocal;
window.getSaleTime = getSaleTime;
window.escapeHtml = escapeHtml;
window.setButtonLoading = setButtonLoading;
window.debounce = debounce;
window.parsePaymentMethod = parsePaymentMethod;
window.getBusinessTimezone = getBusinessTimezone;
window.COUNTRY_TIMEZONE_MAP = COUNTRY_TIMEZONE_MAP;
window.hasFeature = hasFeature;
window.hasFeatureLevel = hasFeatureLevel;

function parsePaymentMethod(paymentMethod, total) {
    const t = parseFloat(total) || 0;
    if (paymentMethod && typeof paymentMethod === 'object' && !Array.isArray(paymentMethod)) {
        const efectivo = parseFloat(paymentMethod.efectivo) || 0;
        const qr = parseFloat(paymentMethod.qr) || 0;
        const tarjeta = parseFloat(paymentMethod.tarjeta) || 0;
        const parts = [];
        if (efectivo > 0) parts.push(`Efectivo: Bs ${efectivo.toFixed(2)}`);
        if (qr > 0) parts.push(`QR: Bs ${qr.toFixed(2)}`);
        if (tarjeta > 0) parts.push(`Tarjeta: Bs ${tarjeta.toFixed(2)}`);
        return { efectivo, qr, tarjeta, label: parts.join(' + ') || 'Mixto' };
    }
    const method = (typeof paymentMethod === 'string' && paymentMethod) ? paymentMethod : 'efectivo';
    return {
        efectivo: method === 'efectivo' ? t : 0,
        qr: method === 'qr' ? t : 0,
        tarjeta: method === 'tarjeta' ? t : 0,
        label: method === 'efectivo' ? 'Efectivo' : method === 'qr' ? 'QR' : method === 'tarjeta' ? 'Tarjeta' : method
    };
}

// ==========================================================================
// STOCK COUNTING UTILITIES (pure functions, no state dependency)
// ==========================================================================

function countSegundoUsage(items, segundoId) {
    if (!items || !Array.isArray(items)) return 0;
    return items.reduce((acc, item) => {
        if ((item.type === 'almuerzo' || item.type === 'segundo') && item.segundoId === segundoId) {
            return acc + (item.quantity || item.qty || 1);
        }
        return acc;
    }, 0);
}

function countPlatoExtraUsage(items, platoId) {
    if (!items || !Array.isArray(items)) return 0;
    return items.reduce((acc, item) => {
        if (item.type === 'plato_extra' && item.platoId === platoId) {
            return acc + (item.quantity || item.qty || 1);
        }
        return acc;
    }, 0);
}

function countExtraUsage(items, extraId) {
    if (!items || !Array.isArray(items)) return 0;
    return items.reduce((acc, item) => {
        if (item.type === 'extra' && item.extraId === extraId) {
            return acc + (item.quantity || item.qty || 1);
        }
        return acc;
    }, 0);
}

function countSopaUsage(items, sopaId) {
    if (!items || !Array.isArray(items)) return 0;
    return items.reduce((acc, item) => {
        if ((item.type === 'almuerzo' || item.type === 'sopa') && item.sopaId === sopaId) {
            return acc + (item.quantity || item.qty || 1);
        }
        return acc;
    }, 0);
}

window.countSopaUsage = countSopaUsage;

function countSalsaUsage(items, salsaId) {
    if (!items || !Array.isArray(items)) return 0;
    return items.reduce((acc, item) => {
        if (item.salsaId === salsaId) {
            return acc + (item.quantity || item.qty || 1);
        }
        return acc;
    }, 0);
}

window.countSalsaUsage = countSalsaUsage;

function getTableName(tableId) {
    const tables = (typeof state !== 'undefined' && state.tables) ? state.tables : [];
    const table = tables.find(t => t.id === tableId);
    return table ? table.name : tableId;
}

// ==========================================================================
// FOCUS TRAP (for accessible modals)
// ==========================================================================

let _trapActiveElement = null;
let _trapModal = null;

function trapFocus(modal) {
    releaseFocus();
    _trapActiveElement = document.activeElement;
    _trapModal = modal;
    _trapModal.addEventListener('keydown', _handleTrapKeydown);
    const firstFocusable = _trapModal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (firstFocusable) firstFocusable.focus();
}

function releaseFocus() {
    if (_trapModal) {
        _trapModal.removeEventListener('keydown', _handleTrapKeydown);
        _trapModal = null;
    }
    if (_trapActiveElement && typeof _trapActiveElement.focus === 'function') {
        _trapActiveElement.focus();
        _trapActiveElement = null;
    }
}

function _handleTrapKeydown(e) {
    if (e.key !== 'Tab' || !_trapModal) return;
    const focusables = _trapModal.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
    if (focusables.length === 0) return;
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    if (e.shiftKey) {
        if (document.activeElement === first) { e.preventDefault(); last.focus(); }
    } else {
        if (document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
}

window.getTableName = getTableName;
window.trapFocus = trapFocus;
window.releaseFocus = releaseFocus;

function openLowStockModal() {
    const listEl = document.getElementById('low-stock-list');
    if (!listEl) return;

    const items = [];

    (state.sopas || []).forEach(s => {
        if (Number(s.active) !== 0) {
            const avail = getAvailableSopaStock(s.id);
            if (avail <= 5) {
                items.push({ category: 'Sopa', name: s.name, stock: avail, total: s.stock || 0 });
            }
        }
    });
    (state.seconds || []).forEach(s => {
        if (Number(s.active) !== 0) {
            const avail = getAvailableSegundoStock(s.id);
            if (avail <= 5) {
                items.push({ category: 'Segundo', name: s.name, stock: avail, total: s.stock || 0 });
            }
        }
    });
    (state.platosExtras || []).forEach(p => {
        if (Number(p.active) !== 0) {
            const avail = getAvailablePlatoExtraStock(p.id);
            if (avail <= 5) {
                items.push({ category: 'Plato Extra', name: p.name, stock: avail, total: p.stock || 0 });
            }
        }
    });
    (state.extras || []).forEach(e => {
        if (Number(e.active) !== 0) {
            const avail = getAvailableExtraStock(e.id);
            if (avail <= 5) {
                items.push({ category: 'Bebida', name: e.name, stock: avail, total: e.stock || 0 });
            }
        }
    });

    if (items.length === 0) {
        listEl.innerHTML = '<p class="text-muted" style="text-align:center; padding:24px;">No hay productos con bajo stock.</p>';
    } else {
        items.sort((a, b) => a.stock - b.stock);
        listEl.innerHTML = items.map(it => {
            const isAgotado = it.stock === 0;
            const color = isAgotado ? 'var(--danger)' : 'var(--primary)';
            const bg = isAgotado ? 'rgba(239, 71, 111, 0.08)' : 'rgba(255, 107, 53, 0.08)';
            return `
                <div style="display:flex; align-items:center; justify-content:space-between; padding:10px 12px; border-radius:6px; background:${bg}; margin-bottom:6px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <i class="fa-solid ${isAgotado ? 'fa-circle-xmark' : 'fa-triangle-exclamation'}" style="color:${color}; font-size:14px;"></i>
                        <div>
                            <div style="font-weight:600; font-size:13px;">${escapeHtml(it.name)}</div>
                            <div style="font-size:11px; color:var(--text-muted);">${escapeHtml(it.category)}</div>
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-weight:700; font-size:14px; color:${color};">${it.stock}</div>
                        <div style="font-size:10px; color:var(--text-muted);">/ ${it.total}</div>
                    </div>
                </div>
            `;
        }).join('');
    }

    const modal = document.getElementById('modal-low-stock');
    if (modal) modal.classList.add('open');
}

function closeLowStockModal() {
    const modal = document.getElementById('modal-low-stock');
    if (modal) modal.classList.remove('open');
}

window.openLowStockModal = openLowStockModal;
window.closeLowStockModal = closeLowStockModal;

// ==========================================================================
// SALSA SELECTION MODAL
// ==========================================================================

let _salsaSelectCallback = null;
let _salsaSelectProductData = null;

function openSalsaSelectModal(productData, callback) {
    _salsaSelectCallback = callback;
    _salsaSelectProductData = productData;

    const nameEl = document.getElementById('salsa-select-product-name');
    const listEl = document.getElementById('salsa-select-list');
    const priceHint = document.getElementById('salsa-select-price-hint');

    if (nameEl) nameEl.textContent = productData.name || '';

    if (listEl) {
        listEl.innerHTML = '';
        const salsas = (state.salsas || []).filter(s => Number(s.active) !== 0);
        if (salsas.length === 0) {
            listEl.innerHTML = '<p style="color:var(--text-muted); font-size:13px; text-align:center; padding:12px;">No hay salsas disponibles</p>';
        } else {
            salsas.forEach(s => {
                const priceText = ' (Incluida)';
                const row = document.createElement('div');
                row.style.cssText = 'display:flex; align-items:center; gap:8px; padding:8px 4px; border-bottom:1px solid var(--border);';
                row.innerHTML = `
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; flex:1;">
                        <input type="checkbox" class="salsa-check" data-salsa-id="${s.id}" data-salsa-name="${escapeHtml(s.name)}" data-salsa-price="0" onchange="updateSalsaPriceHint()">
                        <span>${escapeHtml(s.name)}<span style="color:var(--text-muted); font-size:11px;">${priceText}</span></span>
                    </label>
                    <select class="form-select salsa-mode-select" style="width:auto; font-size:11px; padding:2px 4px;" data-salsa-id="${s.id}">
                        <option value="banar">Bañar</option>
                        <option value="aparte">A parte</option>
                    </select>
                `;
                listEl.appendChild(row);
            });
        }
    }

    if (priceHint) priceHint.style.display = 'none';

    const modal = document.getElementById('modal-salsa-select');
    if (modal) modal.classList.add('open');
}

function updateSalsaPriceHint() {
    const priceHint = document.getElementById('salsa-select-price-hint');
    const priceValue = document.getElementById('salsa-select-price-value');
    const checks = document.querySelectorAll('.salsa-check:checked');
    let total = 0;
    checks.forEach(cb => { total += parseFloat(cb.dataset.salsaPrice) || 0; });
    if (priceHint && priceValue) {
        if (total > 0) {
            priceValue.textContent = formatCurrency(total);
            priceHint.style.display = 'block';
        } else {
            priceHint.style.display = 'none';
        }
    }
}

function closeSalsaSelectModal() {
    const modal = document.getElementById('modal-salsa-select');
    if (modal) modal.classList.remove('open');
    _salsaSelectCallback = null;
    _salsaSelectProductData = null;
}

function confirmSalsaSelection() {
    const checks = document.querySelectorAll('.salsa-check:checked');
    const salsas = [];

    checks.forEach(cb => {
        const salsaId = cb.dataset.salsaId;
        const modeSelect = document.querySelector(`.salsa-mode-select[data-salsa-id="${salsaId}"]`);
        salsas.push({
            salsaId: salsaId,
            salsaName: cb.dataset.salsaName,
            salsaMode: modeSelect ? modeSelect.value : 'banar',
            salsaPrice: 0
        });
    });

    const callback = _salsaSelectCallback;
    closeSalsaSelectModal();

    if (callback) {
        callback(salsas.length > 0 ? salsas : null);
    }
}

window.openSalsaSelectModal = openSalsaSelectModal;
window.updateSalsaPriceHint = updateSalsaPriceHint;
window.closeSalsaSelectModal = closeSalsaSelectModal;
window.confirmSalsaSelection = confirmSalsaSelection;

function openAccompanimentSelectModal(productData, callback) {
    const accompaniments = (state.accompaniments || []).filter(item => Number(item.active) !== 0);
    const maxIncluded = Math.max(0, Number(productData.max_included_accompaniments || 0));
    const modal = document.createElement('div');
    modal.className = 'modal-backdrop open';
    modal.innerHTML = `<div class="modal-content modal-content--sm" style="max-width:460px;"><div class="modal-header"><h3><i class="fa-solid fa-bowl-rice"></i> Acompañamientos</h3><button class="btn-close-modal" type="button" aria-label="Cerrar">&times;</button></div><div class="modal-body"><p class="modal-subtitle"><strong>${escapeHtml(productData.name || '')}</strong></p><p class="text-muted" style="font-size:12px;">Los primeros ${maxIncluded} acompañamiento(s) son incluidos. Los adicionales se cobran como extra.</p><div class="accompaniment-select-list">${accompaniments.length ? accompaniments.map(item => `<label style="display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid var(--border);"><span><input type="checkbox" data-id="${item.id}" data-name="${escapeHtml(item.name)}" data-price="${Number(item.price_extra || 0)}"> ${escapeHtml(item.name)}</span><strong>Extra: ${formatCurrency(item.price_extra || 0)}</strong></label>`).join('') : '<p class="text-muted">No hay acompañamientos disponibles.</p>'}</div></div><div class="modal-actions"><button class="btn btn-outline" type="button" data-cancel>Cancelar</button><button class="btn btn-primary" type="button" data-confirm>Confirmar</button></div></div>`;
    const close = () => modal.remove();
    modal.querySelector('.btn-close-modal').addEventListener('click', () => { close(); callback(null); });
    modal.querySelector('[data-cancel]').addEventListener('click', () => { close(); callback(null); });
    modal.querySelector('[data-confirm]').addEventListener('click', () => {
        const selected = Array.from(modal.querySelectorAll('input:checked')).map((input, index) => ({ accompanimentId: input.dataset.id, accompanimentName: input.dataset.name, accompanimentMode: index < maxIncluded ? 'included' : 'extra', accompanimentPrice: index < maxIncluded ? 0 : Number(input.dataset.price || 0) }));
        showToast(selected.length ? `${selected.length} acompañamiento(s) seleccionado(s).` : 'Sin acompañamientos seleccionados.', selected.length ? 'success' : 'info');
        close();
        callback(selected);
    });
    document.body.appendChild(modal);
}

window.openAccompanimentSelectModal = openAccompanimentSelectModal;
