// ==========================================================================
// SCREEN 6: DASHBOARD VIEW
// ==========================================================================

var dashboardCharts = {};
let dashboardData = null;
let dashboardRendering = false;
let dashboardDataHash = null;

const CHART_COLORS = {
    primary: '#6366f1',
    primaryLight: 'rgba(99,102,241,0.15)',
    blue: '#3b82f6',
    green: '#10b981',
    amber: '#f59e0b',
    red: '#ef4444',
    purple: '#8b5cf6',
    cyan: '#06b6d4',
    pink: '#ec4899',
    palette: ['#6366f1', '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#14b8a6', '#f97316']
};

function getDashboardDateRange() {
    const activeBtn = document.querySelector('#dashboard-filters .btn.active[data-range]');
    const range = activeBtn ? activeBtn.dataset.range : '7d';
    const today = new Date();
    let startDate, endDate;

    // Fix #21: Use local dates instead of UTC to avoid timezone edge cases
    const localDate = (d) => {
        const yr = d.getFullYear();
        const mo = String(d.getMonth() + 1).padStart(2, '0');
        const dy = String(d.getDate()).padStart(2, '0');
        return `${yr}-${mo}-${dy}`;
    };

    endDate = localDate(today);

    switch (range) {
        case '7d':
            startDate = new Date(today);
            startDate.setDate(today.getDate() - 6);
            break;
        case '30d':
            startDate = new Date(today);
            startDate.setDate(today.getDate() - 29);
            break;
        case 'month':
            startDate = new Date(today.getFullYear(), today.getMonth(), 1);
            break;
        case 'custom':
            // Fix #1: Custom range returns early since values are already strings
            return {
                startDate: document.getElementById('dashboard-start-date')?.value || endDate,
                endDate: document.getElementById('dashboard-end-date')?.value || endDate
            };
        default:
            startDate = new Date(today);
            startDate.setDate(today.getDate() - 6);
    }

    return { startDate: localDate(startDate), endDate };
}

function destroyDashboardCharts() {
    Object.values(dashboardCharts).forEach(chart => {
        if (chart && typeof chart.destroy === 'function') chart.destroy();
    });
    dashboardCharts = {};
}

// Fix #21: Debounce wrapper
let dashboardRenderTimer = null;
function debouncedRenderDashboard() {
    if (dashboardRenderTimer) clearTimeout(dashboardRenderTimer);
    dashboardRenderTimer = setTimeout(() => renderDashboard(), 300);
}

async function renderDashboard() {
    // Fix #21: Prevent concurrent renders
    if (dashboardRendering) return;
    dashboardRendering = true;

    const { startDate, endDate } = getDashboardDateRange();
    const emptyState = document.getElementById('dashboard-empty-state');
    if (emptyState) emptyState.style.display = 'none';

    try {
        const data = await AppApi.getDashboard(startDate, endDate);

        if (!data || !data.summary) {
            showToast('Sin datos para el rango seleccionado', 'info');
            dashboardRendering = false;
            return;
        }

        // Cache check: skip chart recreation if data is identical
        const newHash = JSON.stringify(data);
        if (newHash === dashboardDataHash) {
            dashboardRendering = false;
            return;
        }
        dashboardDataHash = newHash;
        dashboardData = data;
        if (data.reportContext) {
            state.reportContext = data.reportContext;
            if (window.AppStore) window.AppStore.set({ reportContext: data.reportContext });
        }

        // Only destroy/recreate charts when data actually changed
        destroyDashboardCharts();

        renderDashboardKPIs(data.summary);

        // Fix #5: Independent try/catch per chart so one failure doesn't abort others
        try { renderSalesByDayChart(data.salesByDay || []); } catch (e) { console.error('Chart salesByDay error:', e); }
        try { renderSalesByCategoryChart(data.salesByCategory || []); } catch (e) { console.error('Chart category error:', e); }
        try { renderPaymentMethodsChart(data.paymentMethods || []); } catch (e) { console.error('Chart payment error:', e); }
        try { renderPeakHoursChart(data.peakHours || []); } catch (e) { console.error('Chart peakHours error:', e); }
        try { renderWeeklyTrendChart(data.weeklyTrend || []); } catch (e) { console.error('Chart weeklyTrend error:', e); }
        try { renderTopProducts(data.topProducts || []); } catch (e) { console.error('Chart topProducts error:', e); }
    } catch (e) {
        console.error('Dashboard load error:', e);
        showToast('Error al cargar el dashboard', 'error');
    } finally {
        dashboardRendering = false;
    }
}

function renderDashboardKPIs(summary) {
    const kpiRevenue = document.getElementById('kpi-total-revenue');
    const kpiOrders = document.getElementById('kpi-total-orders');
    const kpiTicket = document.getElementById('kpi-avg-ticket');
    const kpiAnulled = document.getElementById('kpi-total-anulled');

    if (kpiRevenue) kpiRevenue.textContent = formatCurrency(summary.totalRevenue || 0);
    if (kpiOrders) kpiOrders.textContent = summary.totalOrders || 0;
    if (kpiTicket) kpiTicket.textContent = formatCurrency(summary.avgTicket || 0);
    if (kpiAnulled) kpiAnulled.textContent = summary.totalAnulled || 0;
}

function renderSalesByDayChart(data) {
    const ctx = document.getElementById('chart-sales-by-day');
    if (!ctx) return;

    // Fix #14: Hide canvas on empty data
    if (!data || !data.length) {
        ctx.style.display = 'none';
        return;
    }
    ctx.style.display = '';

    const labels = data.map(d => {
        const date = new Date(d.day + 'T12:00:00');
        return date.toLocaleDateString('es-BO', { weekday: 'short', day: 'numeric', month: 'short' });
    });

    dashboardCharts.salesByDay = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Ingresos (Bs)',
                data: data.map(d => d.revenue),
                backgroundColor: CHART_COLORS.primaryLight,
                borderColor: CHART_COLORS.primary,
                borderWidth: 2,
                borderRadius: 6,
                barPercentage: 0.6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (tip) => `Bs ${tip.parsed.y.toFixed(2)}`
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: v => 'Bs ' + v, font: { size: 11 } },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                x: {
                    ticks: { font: { size: 10 } },
                    grid: { display: false }
                }
            }
        }
    });
}

function renderSalesByCategoryChart(data) {
    const ctx = document.getElementById('chart-category');
    if (!ctx) return;

    if (!data || !data.length) {
        ctx.style.display = 'none';
        return;
    }
    ctx.style.display = '';

    dashboardCharts.category = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: data.map(d => d.category),
            datasets: [{
                data: data.map(d => d.revenue),
                backgroundColor: CHART_COLORS.palette.slice(0, data.length),
                borderWidth: 0,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { padding: 16, usePointStyle: true, pointStyleWidth: 10, font: { size: 12 } }
                },
                tooltip: {
                    callbacks: {
                        label: (tip) => {
                            const total = tip.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = total > 0 ? ((tip.parsed / total) * 100).toFixed(1) : 0;
                            return `${tip.label}: Bs ${tip.parsed.toFixed(2)} (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
}

function renderPaymentMethodsChart(data) {
    const ctx = document.getElementById('chart-payment-methods');
    if (!ctx) return;

    if (!data || !data.length) {
        ctx.style.display = 'none';
        return;
    }
    ctx.style.display = '';

    const methodLabels = { efectivo: 'Efectivo', qr: 'QR', tarjeta: 'Tarjeta' };
    const methodColors = { efectivo: CHART_COLORS.green, qr: CHART_COLORS.blue, tarjeta: CHART_COLORS.purple };

    dashboardCharts.paymentMethods = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: data.map(d => methodLabels[d.method] || d.method),
            datasets: [{
                data: data.map(d => d.total),
                backgroundColor: data.map(d => methodColors[d.method] || CHART_COLORS.amber),
                borderWidth: 0,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { padding: 16, usePointStyle: true, pointStyleWidth: 10, font: { size: 12 } }
                },
                tooltip: {
                    callbacks: {
                        label: (tip) => `${tip.label}: Bs ${tip.parsed.toFixed(2)}`
                    }
                }
            }
        }
    });
}

function renderPeakHoursChart(data) {
    const ctx = document.getElementById('chart-peak-hours');
    if (!ctx) return;

    // Fix #15: Hide on empty/null data
    if (!data || !data.length) {
        ctx.style.display = 'none';
        return;
    }
    ctx.style.display = '';

    const allHours = [];
    for (let h = 6; h <= 22; h++) allHours.push(h);
    const hourMap = {};
    data.forEach(d => { hourMap[d.hour] = d.orders; });

    dashboardCharts.peakHours = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: allHours.map(h => `${h}:00`),
            datasets: [{
                label: 'Pedidos',
                data: allHours.map(h => hourMap[h] || 0),
                backgroundColor: allHours.map(h => (hourMap[h] || 0) > 0 ? CHART_COLORS.cyan : 'rgba(6,182,212,0.1)'),
                borderRadius: 4,
                barPercentage: 0.7
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (tip) => `${tip.parsed.x} pedidos`
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, font: { size: 11 } },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                y: {
                    ticks: { font: { size: 10 } },
                    grid: { display: false }
                }
            }
        }
    });
}

function renderWeeklyTrendChart(data) {
    const ctx = document.getElementById('chart-weekly-trend');
    if (!ctx) return;

    if (!data || !data.length) {
        ctx.style.display = 'none';
        return;
    }
    ctx.style.display = '';

    const labels = data.map(d => {
        const wk = String(d.week).slice(-2);
        const yr = String(d.week).slice(0, 4);
        return `S${wk} ${yr}`;
    });

    dashboardCharts.weeklyTrend = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Ingresos (Bs)',
                data: data.map(d => d.revenue),
                borderColor: CHART_COLORS.primary,
                backgroundColor: CHART_COLORS.primaryLight,
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: CHART_COLORS.primary,
                borderWidth: 2.5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (tip) => `Bs ${tip.parsed.y.toFixed(2)}`
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: v => 'Bs ' + v, font: { size: 11 } },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                x: {
                    ticks: { font: { size: 10 } },
                    grid: { display: false }
                }
            }
        }
    });
}

function renderTopProducts(data) {
    const tbody = document.getElementById('dashboard-top-products-body');
    const emptyState = document.getElementById('dashboard-empty-state');
    if (!tbody) return;

    if (!data || !data.length) {
        tbody.innerHTML = '';
        if (emptyState) emptyState.style.display = '';
        return;
    }
    if (emptyState) emptyState.style.display = 'none';

    tbody.innerHTML = data.map((p, i) => `
        <tr>
            <td style="font-weight:600; color:var(--text-muted);">${i + 1}</td>
            <td>${escapeHtml(p.name || 'N/A')}</td>
            <td style="font-weight:600;">${p.count || 0}</td>
            <td style="font-weight:600; color:var(--primary);">${formatCurrency(p.revenue || 0)}</td>
        </tr>
    `).join('');
}

// ==========================================================================
// DETAIL MODALS (KPI card click)
// ==========================================================================

function openDashboardDetail(type) {
    if (!dashboardData) return;
    // Fix #12: Guard orderDetails
    const details = dashboardData.orderDetails || [];
    if (!details.length) {
        showToast('No hay datos de pedidos para este rango', 'info');
        return;
    }

    let title = '';
    let rows = '';

    const fmtCurrency = typeof formatCurrency === 'function' ? formatCurrency : v => 'Bs ' + parseFloat(v).toFixed(2);
    const fmtTime = ts => {
        const d = new Date(ts);
        const tz = (typeof getBusinessTimezone === 'function') ? getBusinessTimezone() : 'America/La_Paz';
        return d.toLocaleDateString('es-BO', { day: '2-digit', month: 'short', timeZone: tz }) + ' ' +
               d.toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit', timeZone: tz });
    };

    switch (type) {
        case 'revenue':
            title = 'Detalle de Ingresos';
            rows = details.map(o => {
                const pm = parsePaymentMethod(o.payment_method, o.total);
                const isMixed = o.payment_method && typeof o.payment_method === 'object';
                const badgeClass = isMixed ? 'badge-secondary' : (o.payment_method === 'efectivo' ? 'badge-success' : o.payment_method === 'qr' ? 'badge-info' : 'badge-warning');
                return `
                <tr>
                    <td style="white-space:nowrap;">${fmtTime(o.timestamp)}</td>
                    <td>${escapeHtml(o.customer || 'N/A')}</td>
                    <td style="font-weight:600; color:var(--primary);">${fmtCurrency(o.total)}</td>
                    <td><span class="badge ${badgeClass}">${escapeHtml(pm.label)}</span></td>
                </tr>`;
            }).join('');
            break;

        case 'orders':
            title = 'Detalle de Pedidos';
            rows = details.map(o => {
                // Fix #13: Null-safe items access
                const itemCount = (o.items && Array.isArray(o.items)) ? o.items.length : 0;
                return `
                <tr>
                    <td style="white-space:nowrap;">${fmtTime(o.timestamp)}</td>
                    <td>${escapeHtml(o.customer || 'N/A')}</td>
                    <td>${itemCount} item${itemCount !== 1 ? 's' : ''}</td>
                    <td style="font-weight:600;">${fmtCurrency(o.total)}</td>
                </tr>`;
            }).join('');
            break;

        case 'ticket':
            title = 'Detalle de Ticket Promedio';
            const sorted = [...details].sort((a, b) => b.total - a.total);
            rows = sorted.slice(0, 20).map(o => `
                <tr>
                    <td style="white-space:nowrap;">${fmtTime(o.timestamp)}</td>
                    <td>${escapeHtml(o.customer || 'N/A')}</td>
                    <td style="font-weight:600;">${fmtCurrency(o.total)}</td>
                </tr>
            `).join('');
            break;

        case 'anulled':
            title = 'Detalle de Anulados';
            const anulled = details.filter(o => o.status === 'anulado');
            if (!anulled.length) {
                rows = '<tr><td colspan="3" style="text-align:center; color:var(--text-muted); padding:24px;">No hay pedidos anulados en este rango</td></tr>';
            } else {
                rows = anulled.map(o => `
                    <tr>
                        <td style="white-space:nowrap;">${fmtTime(o.timestamp)}</td>
                        <td>${escapeHtml(o.customer || 'N/A')}</td>
                        <td style="font-weight:600; color:var(--danger);">${fmtCurrency(o.total)}</td>
                    </tr>
                `).join('');
            }
            break;

        default:
            return;
    }

    const summary = dashboardData.summary || {};
    const summaryHtml = `
        <div style="display:flex; gap:16px; margin-bottom:16px; flex-wrap:wrap;">
            <div style="flex:1; min-width:120px; padding:12px; background:var(--bg-surface); border-radius:var(--radius-md); border:1px solid var(--border);">
                <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Ingresos</div>
                <div style="font-size:16px; font-weight:700; color:var(--primary);">${fmtCurrency(summary.totalRevenue || 0)}</div>
            </div>
            <div style="flex:1; min-width:120px; padding:12px; background:var(--bg-surface); border-radius:var(--radius-md); border:1px solid var(--border);">
                <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Pedidos</div>
                <div style="font-size:16px; font-weight:700;">${summary.totalOrders || 0}</div>
            </div>
        </div>
    `;

    // Remove existing modal if present
    const existing = document.getElementById('modal-dashboard-detail');
    if (existing) existing.remove();

    // Fix #2: Use app's modal pattern (.modal-backdrop + .open class)
    const modal = document.createElement('div');
    modal.className = 'modal-backdrop';
    modal.id = 'modal-dashboard-detail';
    modal.innerHTML = `
        <div class="modal-content" style="max-width:720px; width:95%; max-height:85vh;">
            <div class="modal-header">
                <h3>${escapeHtml(title)}</h3>
                <button class="btn-close-modal" id="btn-close-dashboard-detail"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body" style="overflow-y:auto; max-height:calc(85vh - 120px);">
                ${summaryHtml}
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Fecha/Hora</th>
                            <th>Cliente</th>
                            <th>${type === 'revenue' ? 'Monto' : type === 'orders' ? 'Items' : 'Monto'}</th>
                            ${type === 'revenue' ? '<th>Pago</th>' : ''}
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
            <div class="modal-actions">
                <button class="btn btn-outline" id="btn-close-dashboard-detail-bottom">Cerrar</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    // Bind close buttons
    const closeBtn = document.getElementById('btn-close-dashboard-detail');
    const closeBtnBottom = document.getElementById('btn-close-dashboard-detail-bottom');
    if (closeBtn) closeBtn.addEventListener('click', closeDashboardDetail);
    if (closeBtnBottom) closeBtnBottom.addEventListener('click', closeDashboardDetail);

    // Open modal using app pattern
    openModal('modal-dashboard-detail');
}

function closeDashboardDetail() {
    closeModal('modal-dashboard-detail');
    // Remove from DOM after animation
    setTimeout(() => {
        const modal = document.getElementById('modal-dashboard-detail');
        if (modal) modal.remove();
    }, 300);
}

// KPI card click listeners + keyboard accessibility
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.kpi-card[data-detail]').forEach(card => {
        card.addEventListener('click', () => {
            openDashboardDetail(card.dataset.detail);
        });
        // Fix #16: Keyboard accessibility
        card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openDashboardDetail(card.dataset.detail);
            }
        });
    });
});

// Expose
window.renderDashboard = renderDashboard;
window.debouncedRenderDashboard = debouncedRenderDashboard;
window.openDashboardDetail = openDashboardDetail;
window.closeDashboardDetail = closeDashboardDetail;
