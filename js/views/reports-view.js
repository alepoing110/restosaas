// ==========================================================================
// SCREEN 5: REPORTS VIEW
// ==========================================================================

function renderReports() {
    let totalRevenue = 0;
    let revenueEfectivo = 0;
    let revenueQr = 0;
    let revenueTarjeta = 0;
    let mixedCount = 0;
    let mixedEfectivoTotal = 0;
    let mixedQrTotal = 0;

    const completedSales = state.salesHistory.filter(s => s.status === 'completado' || (s.status === 'pendiente' && s.paid));

    completedSales.forEach(sale => {
        totalRevenue += sale.total;
        const pm = parsePaymentMethod(sale.paymentMethod, sale.total);
        revenueEfectivo += pm.efectivo;
        revenueQr += pm.qr;
        revenueTarjeta += pm.tarjeta;
        if (sale.paymentMethod && typeof sale.paymentMethod === 'object') {
            mixedCount++;
            mixedEfectivoTotal += pm.efectivo;
            mixedQrTotal += pm.qr;
        }
    });

    const totalExpenses = state.cajaMovimientos
        .filter(m => m.type === 'egreso')
        .reduce((sum, m) => sum + m.amount, 0);

    const netProfit = totalRevenue - totalExpenses;
    const apertura = state.cajaMovimientos.find(m => m.type === 'apertura');
    const startingCash = apertura ? apertura.amount : 0;
    const expectedCash = startingCash + revenueEfectivo - totalExpenses;

    const reportRevenue = document.getElementById('report-total-revenue');
    const reportExpenses = document.getElementById('report-total-expenses');
    const profitEl = document.getElementById('report-net-profit');
    const cashVaultEl = document.getElementById('report-cash-in-vault');
    const breakdownEl = document.getElementById('report-payment-breakdown');

    const resRevenue = document.getElementById('resumen-total-revenue');
    const resExpenses = document.getElementById('resumen-total-expenses');
    const resProfit = document.getElementById('resumen-net-profit');
    const resCashVault = document.getElementById('resumen-cash-in-vault');

    if (reportRevenue) reportRevenue.textContent = formatCurrency(totalRevenue);
    if (reportExpenses) reportExpenses.textContent = formatCurrency(totalExpenses);
    if (profitEl) {
        profitEl.textContent = formatCurrency(netProfit);
        profitEl.style.color = netProfit >= 0 ? 'var(--success)' : 'var(--danger)';
    }
    if (cashVaultEl) cashVaultEl.textContent = formatCurrency(expectedCash);

    if (resRevenue) resRevenue.textContent = formatCurrency(totalRevenue);
    if (resExpenses) resExpenses.textContent = formatCurrency(totalExpenses);
    if (resProfit) {
        resProfit.textContent = formatCurrency(netProfit);
        resProfit.style.color = netProfit >= 0 ? 'var(--success)' : 'var(--danger)';
    }
    if (resCashVault) resCashVault.textContent = formatCurrency(expectedCash);

    if (breakdownEl) {
        let breakdownText = `💵 Efectivo: Bs ${revenueEfectivo.toFixed(2)} | 📱 QR: Bs ${revenueQr.toFixed(2)} | 💳 Tarjeta: Bs ${revenueTarjeta.toFixed(2)}`;
        if (mixedCount > 0) {
            breakdownText += ` | 🔀 Mixtos: ${mixedCount} (Efec: Bs ${mixedEfectivoTotal.toFixed(2)} + QR: Bs ${mixedQrTotal.toFixed(2)})`;
        }
        breakdownEl.textContent = breakdownText;
    }

    const aperturaWrapper = document.getElementById('caja-apertura-wrapper');
    const estadoAbierta = document.getElementById('caja-estado-abierta');
    if (aperturaWrapper && estadoAbierta) {
        if (apertura) {
            aperturaWrapper.style.display = 'none';
            estadoAbierta.style.display = 'block';
            const montoLbl = document.getElementById('caja-monto-inicial-lbl');
            if (montoLbl) montoLbl.textContent = formatCurrency(startingCash);
        } else {
            aperturaWrapper.style.display = 'block';
            estadoAbierta.style.display = 'none';
        }
    }

    renderCajaMovimientos();
    renderSalesHistory();
    renderTopSellers();
    renderCajaCierresHistory();
    if (typeof renderRefundsReport === 'function') renderRefundsReport();
    runArqueoCalculations();
    if (typeof initReportsSubtabs === 'function') {
        initReportsSubtabs();
    }
    if (typeof initReportDateFilter === 'function') {
        initReportDateFilter();
    }
}

function renderTopSellers() {
    const tbody = document.getElementById('top-sellers-body');
    const emptyState = document.getElementById('empty-top-sellers-state');
    if (!tbody) return;
    tbody.innerHTML = '';

    const completedSales = state.salesHistory.filter(s => s.status === 'completado' || (s.status === 'pendiente' && s.paid));
    if (completedSales.length === 0) {
        if (emptyState) emptyState.style.display = 'flex';
        return;
    }

    const productStats = new Map();
    let grandTotalRevenue = 0;

    completedSales.forEach(sale => {
        (sale.items || []).forEach(item => {
            const name = item.name || 'Producto Sin Nombre';
            const type = item.type || 'otro';
            const qty = Math.max(1, parseInt(item.quantity || 1, 10));
            const price = parseFloat(item.price || 0);
            const total = price * qty;
            grandTotalRevenue += total;

            const key = `${type}:${name}`;
            if (!productStats.has(key)) {
                productStats.set(key, { name, type, qty: 0, total: 0 });
            }
            const stat = productStats.get(key);
            stat.qty += qty;
            stat.total += total;
        });
    });

    let sortedStats = Array.from(productStats.values());
    const filterType = window.currentRankingFilter || '';
    if (filterType) {
        sortedStats = sortedStats.filter(stat => stat.type === filterType);
        grandTotalRevenue = sortedStats.reduce((sum, s) => sum + s.total, 0);
    }
    sortedStats.sort((a, b) => b.qty - a.qty || b.total - a.total);

    if (sortedStats.length === 0) {
        if (emptyState) emptyState.style.display = 'flex';
        return;
    }
    if (emptyState) emptyState.style.display = 'none';

    window.currentTopSellersData = sortedStats;

    const categoryBadges = {
        'almuerzo': '<span class="item-detail-badge servirse">Almuerzo</span>',
        'segundo': '<span class="item-detail-badge eating">Segundo</span>',
        'sopa': '<span class="item-detail-badge waiting-soup">Sopa</span>',
        'plato_extra': '<span class="item-detail-badge llevar">Plato Extra</span>',
        'extra': '<span class="item-detail-badge asking-bill">Gaseosa / Bebida</span>',
        'otro': '<span class="item-detail-badge">Otro</span>'
    };

    sortedStats.forEach((stat, idx) => {
        const tr = document.createElement('tr');
        const rank = idx + 1;
        const percent = grandTotalRevenue > 0 ? Math.round((stat.total / grandTotalRevenue) * 100) : 0;
        const badge = categoryBadges[stat.type] || categoryBadges['otro'];
        
        let medal = `#${rank}`;
        if (rank === 1) medal = '🥇 #1';
        else if (rank === 2) medal = '🥈 #2';
        else if (rank === 3) medal = '🥉 #3';

        const progressBarHtml = `
            <div style="display: flex; align-items: center; gap: 8px; justify-content: center; font-size: 11px;">
                <div style="background: rgba(255,255,255,0.08); border: 1px solid var(--border); width: 60px; height: 8px; border-radius: 4px; overflow: hidden;">
                    <div style="background: ${rank <= 3 ? 'var(--warning)' : 'var(--primary)'}; width: ${Math.min(100, percent)}%; height: 100%;"></div>
                </div>
                <span style="font-weight: 600;">${percent}%</span>
            </div>
        `;

        tr.innerHTML = `
            <td style="text-align:center; font-weight:800; color:${rank <= 3 ? 'var(--warning)' : 'inherit'};">${medal}</td>
            <td><strong>${escapeHtml(stat.name)}</strong></td>
            <td>${badge}</td>
            <td style="text-align:center;"><strong>${stat.qty}</strong> u.</td>
            <td style="text-align:right; font-weight:700; color:var(--success);">${formatCurrency(stat.total)}</td>
            <td style="text-align:center;">${progressBarHtml}</td>
        `;
        tbody.appendChild(tr);
    });
}

function renderCajaMovimientos() {
    const tbodyMovs = document.getElementById('caja-movimientos-body');
    const emptyMovsState = document.getElementById('empty-caja-movimientos-state');
    if (!tbodyMovs) return;
    tbodyMovs.innerHTML = '';

    if (state.cajaMovimientos.length === 0) {
        if (emptyMovsState) emptyMovsState.style.display = 'flex';
    } else {
        if (emptyMovsState) emptyMovsState.style.display = 'none';
        state.cajaMovimientos.forEach(mov => {
            const tr = document.createElement('tr');
            const isApertura = mov.type === 'apertura';
            const badgeClass = isApertura ? 'servirse' : 'llevar';
            const labelText = isApertura ? 'Apertura' : 'Gasto';
            const amountPrefix = isApertura ? '+' : '-';
            const amountColor = isApertura ? 'var(--success)' : 'var(--danger)';
            const actionBtn = !isApertura
                ? `<button class="btn-table-action delete" onclick="deleteCajaMovimiento('${mov.id}')" title="Eliminar Gasto"><i class="fa-solid fa-trash"></i></button>`
                : '';

            tr.innerHTML = `
                <td><strong>${formatTime(mov.timestamp)}</strong></td>
                <td><strong>${escapeHtml(mov.description)}</strong></td>
                <td><span class="item-detail-badge ${badgeClass}">${labelText}</span></td>
                <td style="text-align:right; font-weight:700; color:${amountColor};">${amountPrefix} ${formatCurrency(mov.amount)}</td>
                <td style="text-align:right;">${actionBtn}</td>
            `;
            tbodyMovs.appendChild(tr);
        });
    }
}

function renderSalesHistory() {
    const tbodySales = document.getElementById('sales-history-body');
    const emptySalesState = document.getElementById('empty-sales-state');
    if (!tbodySales) return;
    tbodySales.innerHTML = '';

    const filteredSales = typeof getFilteredSales === 'function' ? getFilteredSales() : [...state.salesHistory];

    if (typeof updateSalesHistoryKPIs === 'function') {
        updateSalesHistoryKPIs(filteredSales);
    }

    if (filteredSales.length === 0) {
        if (emptySalesState) emptySalesState.style.display = 'flex';
        return;
    } else {
        if (emptySalesState) emptySalesState.style.display = 'none';
    }

    const sortedSales = [...filteredSales].sort((a, b) => {
        const ta = getSaleTime(a) || a.timestamp || '';
        const tb = getSaleTime(b) || b.timestamp || '';
        return new Date(tb) - new Date(ta);
    });

    sortedSales.forEach(sale => {
        const tr = document.createElement('tr');
        const isPaid = sale.status === 'completado' || (sale.status === 'pendiente' && sale.paid);

        let itemsSummary = '<div class="sale-items-compact-list">';
        (sale.items || []).forEach(item => {
            const qty = item.qty || item.quantity || 1;
            let details = '';
            if (item.type === 'almuerzo' || item.type === 'segundo') {
                details = item.segundoName ? `(${item.segundoName})` : '';
            }
            itemsSummary += `
                <span class="sale-item-compact-row">
                    <strong>${qty}x</strong> ${escapeHtml(item.name)} ${escapeHtml(details)}
                    <span class="item-detail-badge ${item.serviceType}">${escapeHtml(item.serviceType)}</span>
                </span>
            `;
        });
        itemsSummary += '</div>';

        let statusBadge;
        if (sale.status === 'anulado') {
            statusBadge = '<span class="badge-status refunded">Anulado</span>';
        } else if (sale.status === 'pendiente' && sale.paid) {
            statusBadge = '<span class="badge-status completed">Cobrado</span>';
        } else {
            statusBadge = '<span class="badge-status completed">Cobrado</span>';
        }

        const pm = parsePaymentMethod(sale.paymentMethod, sale.total);
        const isMixed = sale.paymentMethod && typeof sale.paymentMethod === 'object';
        let methodBadgeHtml;
        if (isMixed) {
            const parts = [];
            if (pm.efectivo > 0) parts.push(`Efec: Bs ${pm.efectivo.toFixed(2)}`);
            if (pm.qr > 0) parts.push(`QR: Bs ${pm.qr.toFixed(2)}`);
            if (pm.tarjeta > 0) parts.push(`Tarj: Bs ${pm.tarjeta.toFixed(2)}`);
            methodBadgeHtml = `<div class="mixed-payment-badges"><span class="item-detail-badge" style="background:rgba(255,182,39,0.15);color:var(--secondary);">🔀 Mixto</span><span class="mixed-payment-detail">${escapeHtml(parts.join(' + '))}</span></div>`;
        } else {
            const method = sale.paymentMethod ? sale.paymentMethod : 'efectivo';
            methodBadgeHtml = method === 'efectivo' ? '<span class="item-detail-badge servirse">💵 Efec.</span>' :
                                method === 'qr' ? '<span class="item-detail-badge eating">📱 QR</span>' :
                                '<span class="item-detail-badge llevar">💳 Tarj.</span>';
        }

        const buttons = isPaid
            ? `<div style="display:flex; gap:6px;">
                <button class="btn btn-outline-danger btn-sm" onclick="annulCompletedSale('${sale.id}')" title="Anular e Inventariar">
                    <i class="fa-solid fa-rotate-left"></i> Anular
                </button>
                <button class="btn btn-outline-danger btn-sm" onclick="openRefundModal('${sale.id}')" title="Registrar devolución">
                    <i class="fa-solid fa-money-bill-transfer"></i> Devolver
                </button>
               </div>`
            : '<span class="text-muted" style="font-size:11px;">Devuelto</span>';

        tr.innerHTML = `
            <td><strong>${formatTime(getSaleTime(sale) || sale.timestamp)}</strong></td>
            <td>${escapeHtml(sale.customer)}</td>
            <td>${itemsSummary}</td>
            <td>${methodBadgeHtml}</td>
            <td><strong>${formatCurrency(sale.total)}</strong></td>
            <td>${statusBadge}</td>
            <td>${buttons}</td>
        `;
        tbodySales.appendChild(tr);
    });
}

function runArqueoCalculations() {
    const cashRealInput = document.getElementById('caja-efectivo-real');
    const physicalTotal = parseFloat(cashRealInput ? cashRealInput.value : 0) || 0;

    const completedSales = state.salesHistory.filter(s => s.status === 'completado' || (s.status === 'pendiente' && s.paid));
    let cashSales = 0;
    completedSales.forEach(s => {
        const pm = parsePaymentMethod(s.paymentMethod, s.total);
        cashSales += pm.efectivo;
    });

    const apertura = state.cajaMovimientos.find(m => m.type === 'apertura');
    const startingCash = apertura ? apertura.amount : 0;

    const totalExpenses = state.cajaMovimientos
        .filter(m => m.type === 'egreso')
        .reduce((sum, m) => sum + m.amount, 0);

    const expectedCash = startingCash + cashSales - totalExpenses;

    const expectedEl = document.getElementById('arqueo-efectivo-esperado-lbl');
    if (expectedEl) expectedEl.textContent = formatCurrency(expectedCash);

    const difference = physicalTotal - expectedCash;
    const diffEl = document.getElementById('arqueo-diferencia-lbl');
    if (diffEl) {
        diffEl.textContent = formatCurrency(difference);
        if (difference === 0) {
            diffEl.style.color = 'var(--success)';
        } else if (difference < 0) {
            diffEl.style.color = 'var(--danger)';
        } else {
            diffEl.style.color = 'var(--warning)';
        }
    }
}

function renderCajaCierresHistory() {
    const tbody = document.getElementById('caja-cierres-history-body');
    const emptyState = document.getElementById('empty-cierres-state');
    if (!tbody) return;
    tbody.innerHTML = '';

    const closures = state.cajaCierres || [];
    if (closures.length === 0) {
        if (emptyState) emptyState.style.display = 'flex';
    } else {
        if (emptyState) emptyState.style.display = 'none';
        closures.forEach(c => {
            const tr = document.createElement('tr');
            const diffColor = c.diferencia === 0
                ? 'var(--success)'
                : (c.diferencia < 0 ? 'var(--danger)' : 'var(--primary)');
            const diffText = c.diferencia === 0
                ? 'Bs 0.00'
                : `${c.diferencia > 0 ? '+' : ''}Bs ${c.diferencia.toFixed(2)}`;

            tr.innerHTML = `
                <td><strong>${formatDisplayDate(c.fecha)}</strong></td>
                <td style="text-align:right;">Bs ${c.caja_inicial.toFixed(2)}</td>
                <td style="text-align:right;">Bs ${c.ingresos_efectivo.toFixed(2)}</td>
                <td style="text-align:right; color: var(--danger); font-weight:600;">- Bs ${c.egresos.toFixed(2)}</td>
                <td style="text-align:right; font-weight:600;">Bs ${c.efectivo_esperado.toFixed(2)}</td>
                <td style="text-align:right; font-weight:600;">Bs ${c.efectivo_real.toFixed(2)}</td>
                <td style="text-align:right; font-weight:700; color: ${diffColor};">${diffText}</td>
                <td style="text-align:right; font-weight:800; color: var(--success);">Bs ${c.utilidad_neta.toFixed(2)}</td>
                <td style="text-align:right;">
                    <button class="btn btn-outline btn-sm" onclick="printCierreHistorico('${c.id}')" title="Reimprimir Cierre" style="padding: 4px 8px;">
                        <i class="fa-solid fa-print"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }
}

function formatDisplayDate(dateStr) {
    if (!dateStr) return '';
    const parts = dateStr.split('-');
    if (parts.length !== 3) return dateStr;
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
}

function openDrinksReportModal() {
    if (!state.extras || state.extras.length === 0) {
        showToast('No hay gaseosas registradas en inventario.', 'warning');
        return;
    }

    const searchInput = document.getElementById('search-modal-drinks');
    if (searchInput && !searchInput.dataset.listenerBound) {
        searchInput.dataset.listenerBound = 'true';
        searchInput.value = '';
        searchInput.addEventListener('input', () => {
            renderDrinksReportRows(searchInput.value.trim().toLowerCase());
        });
    } else if (searchInput) {
        searchInput.value = '';
    }

    const reportData = [];
    state.extras.forEach(ext => {
        if (!ext) return;
        const availStock = getAvailableExtraStock(ext.id);

        let soldToday = 0;
        if (Array.isArray(state.salesHistory)) {
            state.salesHistory.forEach(s => {
                if (s && (s.status === 'completado' || (s.status === 'pendiente' && s.paid)) && Array.isArray(s.items)) {
                    s.items.forEach(item => {
                        if (item && item.type === 'extra' && item.extraId === ext.id) {
                            soldToday++;
                        }
                    });
                }
            });
        }

        reportData.push({
            id: ext.id,
            name: ext.name || 'Bebida sin nombre',
            available: availStock,
            sold: soldToday,
            base: ext.stock || 0,
            suggested: soldToday
        });
    });

    window.currentDrinksReportData = reportData;
    renderDrinksReportRows('');

    openModal('modal-drinks-report');
}

function renderDrinksReportRows(filterText = '') {
    const tbody = document.getElementById('drinks-report-modal-body');
    if (!tbody) return;
    tbody.innerHTML = '';

    const reportData = window.currentDrinksReportData || [];
    let filtered = reportData;
    if (filterText) {
        filtered = reportData.filter(row => row.name.toLowerCase().includes(filterText));
    }

    const totalCount = reportData.length;
    const criticalCount = reportData.filter(row => row.available <= 5).length;
    const totalToOrder = reportData.reduce((sum, row) => sum + parseInt(row.suggested || 0), 0);

    const elTotal = document.getElementById('modal-drinks-total-count');
    const elCritical = document.getElementById('modal-drinks-critical-count');
    const elToOrder = document.getElementById('modal-drinks-to-order-count');

    if (elTotal) elTotal.textContent = totalCount;
    if (elCritical) elCritical.textContent = criticalCount;
    if (elToOrder) elToOrder.textContent = totalToOrder;

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-muted" style="text-align:center;">No se encontraron bebidas.</td></tr>`;
        return;
    }

    filtered.forEach(row => {
        const tr = document.createElement('tr');
        const isLow = row.available <= 5;
        const availColor = isLow ? 'color: var(--primary); font-weight:700;' : '';
        const maxCapacity = Math.max(1, row.base);
        const percent = Math.min(100, Math.round((row.available / maxCapacity) * 100));
        const barColor = isLow ? 'var(--primary)' : 'var(--success)';
        const progressBarHtml = `
            <div style="display: flex; align-items: center; gap: 8px; font-size: 11px; color: var(--text-muted);">
                <div style="background: rgba(255,255,255,0.05); border: 1px solid var(--border); width: 60px; height: 8px; border-radius: 4px; overflow: hidden; position: relative;">
                    <div style="background: ${barColor}; width: ${percent}%; height: 100%; transition: width 0.3s ease;"></div>
                </div>
                <span>${percent}%</span>
            </div>
        `;

        tr.innerHTML = `
            <td><strong>${escapeHtml(row.name)}</strong></td>
            <td style="text-align:center; ${availColor}">${row.available} uds.</td>
            <td style="text-align:center;">${progressBarHtml}</td>
            <td style="text-align:center;">${row.sold}</td>
            <td class="drinks-report-suggested-cell">
                <input type="number" class="td-edit-input drinks-report-suggested-input" style="color: ${row.suggested > 0 ? 'var(--success)' : 'inherit'};"
                    value="${row.suggested}" min="0" onchange="updateSuggestedQty('${row.id}', this.value)">
            </td>
        `;
        tbody.appendChild(tr);
    });
}

window.openDrinksReportModal = openDrinksReportModal;
window.renderDrinksReportRows = renderDrinksReportRows;
