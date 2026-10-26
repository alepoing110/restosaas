// ==========================================================================
// REFUNDS REPORT VIEW
// ==========================================================================

function renderRefundsReport() {
    const report = state.refundsReport;
    if (!report || !report.refunds) {
        ['refunds-kpi-total', 'refunds-kpi-amount', 'refunds-kpi-cash', 'refunds-kpi-digital'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = id === 'refunds-kpi-total' ? '0' : 'Bs 0.00';
        });
        return;
    }

    const summary = report.summary || {};
    const byMethod = report.by_method || {};
    const byScope = report.by_scope || {};
    const byBranch = report.by_branch || [];
    const topProducts = report.top_returned_products || [];
    const topReasons = report.top_reasons || [];
    const daily = report.daily || [];
    const refunds = report.refunds || [];

    setText('refunds-kpi-total', summary.refund_count || 0);
    setText('refunds-kpi-amount', formatCurrency(summary.total_refunded || 0));
    setText('refunds-kpi-cash', formatCurrency(summary.cash_refunds || 0));
    setText('refunds-kpi-digital', formatCurrency(summary.digital_refunds || 0));

    renderRefundsBreakdown('refunds-by-scope', 'refunds-empty-scope', byScope, {
        'producto_parcial': 'Producto parcial',
        'producto_total': 'Producto total',
        'pedido_completo': 'Pedido completo',
        'venta_completa': 'Venta completa'
    });

    renderRefundsBreakdown('refunds-by-method', 'refunds-empty-method', byMethod, {
        'efectivo': 'Efectivo',
        'qr': 'QR',
        'tarjeta': 'Tarjeta',
        'transferencia': 'Transferencia',
        'otro': 'Otro'
    });

    renderRefundsTopProducts(topProducts);
    renderRefundsTopReasons(topReasons);
    renderRefundsDaily(daily);
    renderRefundsByBranch(byBranch);
    renderRefundsDetail(refunds);
}

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

function renderRefundsBreakdown(containerId, emptyId, data, labels) {
    const container = document.getElementById(containerId);
    const empty = document.getElementById(emptyId);
    if (!container) return;
    container.innerHTML = '';

    const entries = Object.entries(data).filter(([, v]) => v > 0);
    if (entries.length === 0) {
        if (empty) empty.style.display = 'flex';
        return;
    }
    if (empty) empty.style.display = 'none';

    const total = entries.reduce((s, [, v]) => s + v, 0);
    entries.forEach(([key, value]) => {
        const label = labels[key] || key;
        const percent = total > 0 ? Math.round((value / total) * 100) : 0;
        const row = document.createElement('div');
        row.className = 'financial-breakdown-row';
        row.innerHTML = `
            <span>${escapeHtml(label)}</span>
            <span style="font-weight:700; color:var(--danger);">${formatCurrency(value)}</span>
        `;
        container.appendChild(row);
    });
}

function renderRefundsTopProducts(products) {
    const container = document.getElementById('refunds-top-products');
    const empty = document.getElementById('refunds-empty-products');
    if (!container) return;
    container.innerHTML = '';

    if (products.length === 0) {
        if (empty) empty.style.display = 'flex';
        return;
    }
    if (empty) empty.style.display = 'none';

    products.forEach(p => {
        const row = document.createElement('div');
        row.className = 'financial-breakdown-row';
        row.innerHTML = `
            <span>${escapeHtml(p.name)}</span>
            <span style="font-weight:700;">${p.quantity} u.</span>
        `;
        container.appendChild(row);
    });
}

function renderRefundsTopReasons(reasons) {
    const container = document.getElementById('refunds-top-reasons');
    const empty = document.getElementById('refunds-empty-reasons');
    if (!container) return;
    container.innerHTML = '';

    if (reasons.length === 0) {
        if (empty) empty.style.display = 'flex';
        return;
    }
    if (empty) empty.style.display = 'none';

    reasons.forEach(r => {
        const row = document.createElement('div');
        row.className = 'financial-breakdown-row';
        row.innerHTML = `
            <span>${escapeHtml(r.reason)}</span>
            <span style="font-weight:700;">${r.count}x</span>
        `;
        container.appendChild(row);
    });
}

function renderRefundsDaily(daily) {
    const container = document.getElementById('refunds-daily-table');
    const empty = document.getElementById('refunds-empty-daily');
    if (!container) return;
    container.innerHTML = '';

    if (daily.length === 0) {
        if (empty) empty.style.display = 'flex';
        return;
    }
    if (empty) empty.style.display = 'none';

    daily.forEach(d => {
        const row = document.createElement('div');
        row.className = 'financial-daily-row';
        row.innerHTML = `
            <span>${escapeHtml(d.date)}</span>
            <span style="color:var(--danger); font-weight:700;">-${formatCurrency(d.total)}</span>
            <span>${d.count} devoluciones</span>
        `;
        container.appendChild(row);
    });
}

function renderRefundsByBranch(branches) {
    const tbody = document.getElementById('refunds-by-branch-body');
    const empty = document.getElementById('refunds-empty-branch');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (branches.length === 0) {
        if (empty) empty.style.display = 'flex';
        return;
    }
    if (empty) empty.style.display = 'none';

    branches.forEach(b => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><strong>${escapeHtml(b.branch_name)}</strong></td>
            <td style="text-align:right;">${b.count}</td>
            <td style="text-align:right; font-weight:700; color:var(--danger);">-${formatCurrency(b.total_refunded)}</td>
        `;
        tbody.appendChild(tr);
    });
}

function renderRefundsDetail(refunds) {
    const tbody = document.getElementById('refunds-detail-body');
    const empty = document.getElementById('refunds-empty-detail');
    if (!tbody) return;
    tbody.innerHTML = '';

    const filtered = typeof getFilteredRefunds === 'function' ? getFilteredRefunds() : refunds;

    if (filtered.length === 0) {
        if (empty) empty.style.display = 'flex';
        return;
    }
    if (empty) empty.style.display = 'none';

    const scopeLabels = {
        'producto_parcial': '<span class="item-detail-badge eating">Producto parcial</span>',
        'producto_total': '<span class="item-detail-badge llevar">Producto total</span>',
        'pedido_completo': '<span class="item-detail-badge servirse">Pedido completo</span>',
        'venta_completa': '<span class="item-detail-badge waiting-soup">Venta completa</span>'
    };

    const methodLabels = {
        'efectivo': '<span class="item-detail-badge servirse">Efectivo</span>',
        'qr': '<span class="item-detail-badge eating">QR</span>',
        'tarjeta': '<span class="item-detail-badge llevar">Tarjeta</span>',
        'transferencia': '<span class="item-detail-badge asking-bill">Transferencia</span>',
        'otro': '<span class="item-detail-badge">Otro</span>'
    };

    filtered.forEach(refund => {
        const tr = document.createElement('tr');
        const time = formatTime(refund.created_at);
        const items = (refund.items || []).map(i => `${i.quantity}x ${i.item_name}`).join(', ') || '-';
        const scope = scopeLabels[refund.scope] || refund.scope;
        const method = methodLabels[refund.refund_method] || refund.refund_method;

        tr.innerHTML = `
            <td><strong>${time}</strong></td>
            <td style="font-size:11px;">${refund.order_id.slice(-6).toUpperCase()}</td>
            <td>${escapeHtml(refund.customer || '-')}</td>
            <td style="font-size:11px; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(items)}">${escapeHtml(items)}</td>
            <td>${scope}</td>
            <td style="text-align:right; font-weight:700; color:var(--danger);">-${formatCurrency(refund.total_refunded)}</td>
            <td>${method}</td>
            <td style="font-size:11px; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(refund.reason || 'Solicitud del cliente')}">${escapeHtml(refund.reason || 'Solicitud del cliente')}</td>
        `;
        tbody.appendChild(tr);
    });
}

function getFilteredRefunds() {
    const report = state.refundsReport;
    if (!report || !report.refunds) return [];

    const textEl = document.getElementById('refunds-filter-text');
    const scopeEl = document.getElementById('refunds-filter-scope');
    const methodEl = document.getElementById('refunds-filter-method');

    const searchText = textEl ? textEl.value.trim().toLowerCase() : '';
    const scopeFilter = scopeEl ? scopeEl.value : '';
    const methodFilter = methodEl ? methodEl.value : '';

    let filtered = [...report.refunds];

    if (searchText) {
        filtered = filtered.filter(r => {
            const customer = (r.customer || '').toLowerCase();
            const reason = (r.reason || '').toLowerCase();
            const itemsMatch = (r.items || []).some(i => (i.item_name || '').toLowerCase().includes(searchText));
            return customer.includes(searchText) || reason.includes(searchText) || itemsMatch;
        });
    }

    if (scopeFilter) {
        filtered = filtered.filter(r => r.scope === scopeFilter);
    }

    if (methodFilter) {
        filtered = filtered.filter(r => r.refund_method === methodFilter);
    }

    return filtered;
}

window.applyRefundsFilters = function () {
    renderRefundsReport();
};

window.clearRefundsFilters = function () {
    const textEl = document.getElementById('refunds-filter-text');
    const scopeEl = document.getElementById('refunds-filter-scope');
    const methodEl = document.getElementById('refunds-filter-method');
    if (textEl) textEl.value = '';
    if (scopeEl) scopeEl.value = '';
    if (methodEl) methodEl.value = '';
    renderRefundsReport();
};

window.renderRefundsReport = renderRefundsReport;
