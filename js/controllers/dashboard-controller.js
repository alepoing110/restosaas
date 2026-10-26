// ==========================================================================
// SCREEN 6: DASHBOARD CONTROLLER
// ==========================================================================

window.DashboardController = {
    _initialized: false,

    init() {
        // Fix #20: Guard against double initialization
        if (this._initialized) return;
        this._initialized = true;

        const filtersContainer = document.getElementById('dashboard-filters');
        if (!filtersContainer) return;

        // Range button clicks
        filtersContainer.querySelectorAll('[data-range]').forEach(btn => {
            btn.addEventListener('click', () => {
                filtersContainer.querySelectorAll('[data-range]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                const customDates = document.getElementById('dashboard-custom-dates');
                if (customDates) {
                    customDates.style.display = btn.dataset.range === 'custom' ? 'flex' : 'none';
                }

                // Invalidate cache so new date range forces chart refresh
                dashboardDataHash = null;

                if (btn.dataset.range !== 'custom') {
                    // Fix #21: Use debounced render
                    if (typeof debouncedRenderDashboard === 'function') {
                        debouncedRenderDashboard();
                    } else {
                        renderDashboard();
                    }
                }
            });
        });

        // Custom date apply
        const applyBtn = document.getElementById('btn-apply-custom-range');
        if (applyBtn) {
            applyBtn.addEventListener('click', () => {
                // Invalidate cache so new date range forces chart refresh
                dashboardDataHash = null;
                if (typeof debouncedRenderDashboard === 'function') {
                    debouncedRenderDashboard();
                } else {
                    renderDashboard();
                }
            });
        }

        // Export PDF
        const exportBtn = document.getElementById('btn-export-dashboard-pdf');
        if (exportBtn) {
            exportBtn.addEventListener('click', () => DashboardController.exportPDF());
        }

        // Set default dates for custom range (use local dates)
        const startInput = document.getElementById('dashboard-start-date');
        const endInput = document.getElementById('dashboard-end-date');
        if (startInput && endInput) {
            const today = new Date();
            const weekAgo = new Date(today);
            weekAgo.setDate(today.getDate() - 6);
            const localDate = (d) => {
                const yr = d.getFullYear();
                const mo = String(d.getMonth() + 1).padStart(2, '0');
                const dy = String(d.getDate()).padStart(2, '0');
                return `${yr}-${mo}-${dy}`;
            };
            endInput.value = localDate(today);
            startInput.value = localDate(weekAgo);
        }
    },

    async exportPDF() {
        if (!window.ReportRenderer) {
            showToast('No se puede exportar el PDF', 'error');
            return;
        }

        if (!dashboardData) {
            await renderDashboard();
        }

        if (!dashboardData) {
            showToast('No hay datos de dashboard para exportar.', 'info');
            return;
        }

        try {
            showToast('Preparando reporte...', 'info');
            const { startDate, endDate } = getDashboardDateRange();
            const summary = dashboardData.summary || {};
            const rows = [
                ...(dashboardData.salesByDay || []).map(row => ({
                    section: 'Ventas por dia',
                    metric: row.day,
                    detail: `${row.orders || 0} pedidos`,
                    value: formatCurrency(row.revenue || 0)
                })),
                ...(dashboardData.salesByCategory || []).map(row => ({
                    section: 'Categorias',
                    metric: row.category,
                    detail: `${row.count || 0} unidades`,
                    value: formatCurrency(row.revenue || 0)
                })),
                ...(dashboardData.paymentMethods || []).map(row => ({
                    section: 'Metodos de pago',
                    metric: row.method,
                    detail: `${row.count || 0} operaciones`,
                    value: formatCurrency(row.total || 0)
                })),
                ...(dashboardData.topProducts || []).map(row => ({
                    section: 'Productos top',
                    metric: row.name,
                    detail: `${row.count || 0} vendidos`,
                    value: formatCurrency(row.revenue || 0)
                }))
            ];

            ReportRenderer.preview({
                title: 'Dashboard Ejecutivo',
                subtitle: 'Resumen consolidado de rendimiento por tenant y sucursal.',
                filters: { start_date: startDate, end_date: endDate, label: 'Dashboard ejecutivo' },
                columns: [
                    { title: 'Seccion', key: 'section', align: 'left' },
                    { title: 'Indicador', key: 'metric', align: 'left' },
                    { title: 'Detalle', key: 'detail', align: 'left' },
                    { title: 'Valor', key: 'value', align: 'right' }
                ],
                rows,
                totals: [
                    { label: 'Ingresos', value: formatCurrency(summary.totalRevenue || 0) },
                    { label: 'Pedidos', value: summary.totalOrders || 0 },
                    { label: 'Ticket promedio', value: formatCurrency(summary.avgTicket || 0) },
                    { label: 'Anulados', value: summary.totalAnulled || 0 }
                ],
                detailBlocks: [
                    {
                        title: 'Resumen del periodo',
                        items: [
                            { label: 'Desde', value: startDate },
                            { label: 'Hasta', value: endDate },
                            { label: 'Pedidos cobrados', value: summary.totalOrders || 0 },
                            { label: 'Pedidos anulados', value: summary.totalAnulled || 0 }
                        ]
                    }
                ],
                filename: `dashboard_${startDate}_${endDate}.pdf`,
                orientation: 'portrait'
            });
            showToast('Reporte listo para revisar.', 'success');
        } catch (e) {
            console.error('PDF export error:', e);
            showToast('Error al exportar PDF', 'error');
        }
    },

    exportChartPNG(chartKey, filename) {
        const chart = window.dashboardCharts && window.dashboardCharts[chartKey];
        if (!chart) {
            showToast('Grafico no disponible', 'error');
            return;
        }

        const link = document.createElement('a');
        link.download = `${filename}-${new Date().toISOString().slice(0, 10)}.png`;
        link.href = chart.toBase64Image('image/png', 1);
        link.click();

        showToast('PNG descargado', 'success');
    }
};
