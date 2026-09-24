// ==========================================================================
// SCREEN 5: REPORTS CONTROLLER
// ==========================================================================

function sanitizeCsvCell(val) {
    const s = String(val ?? '');
    if (/^[=+\-@\t\r\n]/.test(s)) return "'" + s;
    return s;
}

function switchReportsSubtab(tabId = 'resumen') {
    const allowedTabs = ['resumen', 'caja', 'ventas', 'ranking', 'historico', 'devoluciones'];
    const activeTab = allowedTabs.includes(tabId) ? tabId : 'resumen';
    window.activeReportsSubtab = activeTab;
    if (typeof window.saveUiContext === 'function') {
        const context = typeof window.getUiContext === 'function' ? window.getUiContext() : {};
        window.saveUiContext({ subtabs: { ...(context.subtabs || {}), reports: activeTab } });
    }

    document.querySelectorAll('.reports-subtab').forEach(button => {
        const isActive = button.dataset.reportTab === activeTab;
        button.classList.toggle('active', isActive);
        button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        if (isActive) {
            try {
                button.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            } catch (e) {}
        }
    });

    document.querySelectorAll('[data-report-panel]').forEach(panel => {
        const panelKey = panel.dataset.reportPanel;
        const shouldShow = (panelKey === activeTab);
        panel.classList.toggle('report-panel-hidden', !shouldShow);
    });

    const panelsGrid = document.getElementById('reports-panels-grid');
    if (panelsGrid) {
        panelsGrid.classList.toggle('reports-grid--hidden', activeTab === 'historico' || activeTab === 'devoluciones');
        panelsGrid.classList.toggle('reports-grid--single', activeTab === 'ventas' || activeTab === 'ranking' || activeTab === 'resumen');
    }
}
window.switchReportsSubtab = switchReportsSubtab;

function initReportsSubtabs() {
    const subtabButtons = document.querySelectorAll('.reports-subtab');
    if (subtabButtons.length === 0) return;

    subtabButtons.forEach(button => {
        if (button.dataset.bound === 'true') return;
        button.dataset.bound = 'true';
        button.addEventListener('click', () => {
            switchReportsSubtab(button.dataset.reportTab || 'caja');
        });
    });

    const initialTab = window.activeReportsSubtab
        || document.querySelector('.reports-subtab.active')?.dataset.reportTab
        || 'caja';
    switchReportsSubtab(initialTab);
}
window.initReportsSubtabs = initReportsSubtabs;

window.updateSuggestedQty = function(id, val) {
    const reportData = window.currentDrinksReportData || [];
    const row = reportData.find(r => r.id === id);
    if (row) {
        row.suggested = Math.max(0, parseInt(val) || 0);
        const totalToOrder = reportData.reduce((sum, r) => sum + parseInt(r.suggested || 0), 0);
        const elToOrder = document.getElementById('modal-drinks-to-order-count');
        if (elToOrder) elToOrder.textContent = totalToOrder;
    }
};

function closeDrinksReportModal() {
    closeModal('modal-drinks-report');
}
window.closeDrinksReportModal = closeDrinksReportModal;

function printDrinksReport() {
    const reportData = window.currentDrinksReportData;
    if (!reportData) return;
    runReport('drinksOrder', { mode: 'preview', input: { rows: reportData } });
}
window.printDrinksReport = printDrinksReport;

function downloadDrinksReportCSV() {
    const reportData = window.currentDrinksReportData;
    if (!reportData) return;

    let csvContent = "\uFEFF";
    csvContent += "Refresco/Bebida,Stock Actual Disponible,Ventas de Hoy,Stock Total Registrado,Cantidad Sugerida a Pedir\n";
    reportData.forEach(row => {
        csvContent += `"${row.name}",${row.available},${row.sold},${row.base},${row.suggested}\n`;
    });

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    const today = nowLocal().slice(0, 10);
    link.setAttribute("href", url);
    link.setAttribute("download", `pos_pedido_refrescos_${today}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    showToast('Reporte CSV descargado con éxito.', 'success');
}
window.downloadDrinksReportCSV = downloadDrinksReportCSV;

async function handleSaveCajaApertura(e) {
    e.preventDefault();
    const amount = parseFloat(document.getElementById('caja-monto-apertura').value);
    if (isNaN(amount) || amount < 0) return;

    const newMov = {
        id: generateId(),
        type: 'apertura',
        description: 'Monto de apertura de Caja Chica',
        amount: amount,
        timestamp: nowLocal()
    };

    try {
        const data = await AppApi.request('save_caja_movimiento', newMov);
        if (data.status === 'success') {
            showToast('Apertura de caja registrada.', 'success');
            await loadStateForTab('reports');
            renderReports();
        }
    } catch (err) {
        showToast('Error al registrar apertura.', 'error');
    }
}
window.handleSaveCajaApertura = handleSaveCajaApertura;

async function handleSaveCajaEgreso(e) {
    e.preventDefault();
    const descInput = document.getElementById('caja-egreso-desc');
    const amountInput = document.getElementById('caja-egreso-monto');
    const desc = descInput.value.trim();
    const amount = parseFloat(amountInput.value);

    if (!desc || isNaN(amount) || amount <= 0) return;

    const newMov = {
        id: generateId(),
        type: 'egreso',
        description: desc,
        amount: amount,
        timestamp: nowLocal()
    };

    try {
        const data = await AppApi.request('save_caja_movimiento', newMov);
        if (data.status === 'success') {
            showToast('Egreso registrado correctamente.', 'success');
            descInput.value = '';
            amountInput.value = '';
            await loadStateForTab('reports');
            renderReports();
        }
    } catch (err) {
        showToast('Error al registrar egreso.', 'error');
    }
}
window.handleSaveCajaEgreso = handleSaveCajaEgreso;

window.deleteCajaMovimiento = async function(id) {
    const confirmed = await window.ConfirmDialog.show(
        '¿Eliminar este movimiento de caja?',
        { title: 'Eliminar Movimiento', confirmText: 'Sí, eliminar', type: 'danger' }
    );
    
    if (confirmed) {
        try {
            const data = await AppApi.request('delete_caja_movimiento', { id: id });
            if (data.status === 'success') {
                showToast('Movimiento de caja eliminado.', 'info');
                await loadStateForTab('reports');
                renderReports();
            }
        } catch (e) {
            showToast('Error al eliminar movimiento.', 'error');
        }
    }
};

const _annullingSales = new Set();

window.annulCompletedSale = async function(saleId) {
    if (_annullingSales.has(saleId)) return;
    const sale = state.salesHistory.find(s => s.id === saleId);
    if (!sale) return;

    const reason = prompt('Indique el motivo de la anulación:');
    if (reason === null || !reason.trim()) return showToast('El motivo es obligatorio.', 'warning');

    const confirmed = await window.ConfirmDialog.show(
        `¿Anular la venta cobrada de Bs ${sale.total.toFixed(2)}?`,
        { title: 'Anular Venta', confirmText: 'Sí, anular', type: 'danger' }
    );
    
    if (confirmed) {
        _annullingSales.add(saleId);
        try {
            const data = await AppApi.request('annul_sale', { id: saleId, reason: reason.trim() });
            if (data.status === 'success') {
                showToast('Venta anulada correctamente.', 'info');
                await loadStateForTab('reports');
                renderReports();
            }
        } catch (e) {
            showToast('Error al anular venta.', 'error');
        } finally {
            _annullingSales.delete(saleId);
        }
    }
};

async function saveCajaCierreBeforeReset(closureId = null) {
    const completedSales = state.salesHistory.filter(s => s.status === 'completado' || (s.status === 'pendiente' && s.paid));
    const apertura = state.cajaMovimientos.find(m => m.type === 'apertura');

    let totalRevenue = 0;
    let revenueEfectivo = 0;
    completedSales.forEach(sale => {
        totalRevenue += sale.total;
        const pm = parsePaymentMethod(sale.paymentMethod, sale.total);
        revenueEfectivo += pm.efectivo;
    });

    const totalExpenses = state.cajaMovimientos
        .filter(m => m.type === 'egreso')
        .reduce((sum, m) => sum + m.amount, 0);

    const startingCash = apertura ? apertura.amount : 0;
    const expectedCash = startingCash + revenueEfectivo - totalExpenses;
    const realCashEl = document.getElementById('caja-efectivo-real');
    const realCash = realCashEl ? parseFloat(realCashEl.value) : 0;
    const difference = realCash - expectedCash;
    const netProfit = totalRevenue - totalExpenses;

    let lunchCount = 0, segundoCount = 0, soupCount = 0, platoExtraCount = 0, extraCount = 0;
    completedSales.forEach(sale => {
        if (Array.isArray(sale.items)) {
            sale.items.forEach(item => {
                if (item.type === 'almuerzo') lunchCount++;
                else if (item.type === 'segundo') segundoCount++;
                else if (item.type === 'sopa') soupCount++;
                else if (item.type === 'plato_extra') platoExtraCount++;
                else if (item.type === 'extra') extraCount++;
            });
        }
    });

    const closureIdToUse = closureId || generateId();
    const todayDate = nowLocal().slice(0, 10);

    try {
        const data = await AppApi.request('save_caja_cierre', {
            id: closureIdToUse,
            fecha: todayDate,
            caja_inicial: startingCash,
            ingresos_efectivo: revenueEfectivo,
            egresos: totalExpenses,
            efectivo_esperado: expectedCash,
            efectivo_real: realCash,
            diferencia: difference,
            utilidad_neta: netProfit,
            almuerzos_vendidos: lunchCount,
            segundos_vendidos: segundoCount,
            sopas_vendidas: soupCount,
            extras_vendidos: platoExtraCount + extraCount
        });
        if (data.status !== 'success') {
            console.error("Error al archivar cierre:", data.message);
        }
    } catch (err) {
        console.error("Error de red al archivar cierre:", err);
    }
}

function printCierreHistorico(id) {
    const closure = state.cajaCierres.find(c => c.id === id);
    if (!closure) return;
    runReport('historicalClosure', { mode: 'preview', input: { closure } });
}
window.printCierreHistorico = printCierreHistorico;

function printCajaCierre() {
    runReport('cashClosure', { mode: 'preview' });
}
window.printCajaCierre = printCajaCierre;

function getCsvReportMetadata(title) {
    const context = window.ReportRenderer
        ? ReportRenderer.mergeContext(state.reportContext || null)
        : {
            tenant: state.tenant || {},
            branch: state.branch || {},
            business: {
                name: state.business?.nombre_restaurante || 'RestoCloud',
                address: state.business?.direccion || '',
                phone: state.business?.telefono || ''
            },
            user: state.authUser || {},
            generated_at: nowLocal()
        };

    return [
        `Reporte,${title}`,
        `Negocio,"${String(context.business?.name || '').replace(/"/g, '""')}"`,
        `Tenant,"${String(context.tenant?.name || '').replace(/"/g, '""')}"`,
        `Sucursal,"${String(context.branch?.name || '').replace(/"/g, '""')}"`,
        `Usuario,"${String(context.user?.name || context.user?.email || '').replace(/"/g, '""')}"`,
        `Generado,${new Date(context.generated_at || Date.now()).toLocaleString('es-BO')}`,
        ''
    ].join('\n');
}

function exportCierresToCSV() {
    if (!state.cajaCierres || state.cajaCierres.length === 0) {
        showToast('No hay cierres de caja registrados.', 'warning');
        return;
    }

    let csvContent = "\uFEFF";
    csvContent += getCsvReportMetadata('Historico de cierres de caja') + "\n";
    csvContent += "Fecha,Caja Inicial,Ingresos,Egresos,Esperado,Real,Diferencia,Utilidad\n";
    state.cajaCierres.forEach(c => {
        csvContent += `${c.fecha},${c.caja_inicial.toFixed(2)},${c.ingresos_efectivo.toFixed(2)},${c.egresos.toFixed(2)},${c.efectivo_esperado.toFixed(2)},${c.efectivo_real.toFixed(2)},${c.diferencia.toFixed(2)},${c.utilidad_neta.toFixed(2)}\n`;
    });

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.setAttribute("href", url);
    link.setAttribute("download", "historico_cierres_caja.csv");
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    showToast('Historial de cierres descargado.', 'success');
}
window.exportCierresToCSV = exportCierresToCSV;

function exportSalesToCSV() {
    const filteredSales = typeof getFilteredSales === 'function' ? getFilteredSales() : state.salesHistory;
    if (filteredSales.length === 0) {
        showToast('No hay ventas para exportar con los filtros actuales.', 'warning');
        return;
    }

    let csvContent = "\uFEFF";
    csvContent += getCsvReportMetadata('Ventas del dia (filtrado)') + "\n";
    csvContent += "ID,Hora,Cliente,Items,Metodo,Total,Estado\n";
    filteredSales.forEach(sale => {
        let itemsText = sale.items.map(item => {
            const qty = item.qty || item.quantity || 1;
            let desc = item.name;
            if (item.segundoName) desc += ` (${item.segundoName})`;
            desc += ` [${item.serviceType}]`;
            return `${qty}x ${desc}`;
        }).join(" | ");
        const timeStr = formatTime(getSaleTime(sale) || sale.timestamp);
        const pm = parsePaymentMethod(sale.paymentMethod, sale.total);
        const method = pm.label || 'EFECTIVO';
        const client = sanitizeCsvCell(sale.customer);
        itemsText = sanitizeCsvCell(itemsText).replace(/"/g, '""');
        const statusLabel = sale.status === 'anulado' ? 'ANULADO' : 'COBRADO';
        csvContent += `${sale.id.slice(-6).toUpperCase()},${timeStr},"${client}","${itemsText}",${method},${sale.total.toFixed(2)},${statusLabel}\n`;
    });

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    const today = nowLocal().slice(0, 10);
    link.setAttribute("href", url);
    link.setAttribute("download", `pos_ventas_${today}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    showToast(`CSV descargado (${filteredSales.length} registros).`, 'success');
}
window.exportSalesToCSV = exportSalesToCSV;

let closingCashDay = false;

async function resetAllData() {
    if (closingCashDay) return;
    const realCashEl = document.getElementById('caja-efectivo-real');
    const physicalCash = Number(realCashEl?.value);
    if (!Number.isFinite(physicalCash) || physicalCash < 0) {
        showToast('Ingrese el efectivo físico contado antes de cerrar.', 'warning');
        return;
    }
    const confirmed = await window.ConfirmDialog.show(
        'Se archivarán únicamente los movimientos y ventas cobradas de hoy. Las comandas pendientes seguirán abiertas y conservarán su reserva de stock.',
        { title: 'Cerrar Caja', confirmText: 'Sí, cerrar caja', type: 'danger' }
    );
    if (!confirmed) return;
    closingCashDay = true;
    const payload = { date: nowLocal().slice(0, 10), physical_cash: physicalCash, idempotency_key: generateId() };
    try {
        let data = await AppApi.closeCashDay(payload);
        if (data.requires_pending_review) {
            const pendingList = data.pending_orders.map(order => `#${String(order.id).slice(-6).toUpperCase()} - ${order.customer}`).join('<br>');
            const reviewed = await window.ConfirmDialog.show(
                `Hay ${data.pending_orders.length} comanda(s) pendiente(s). Revise su estado antes de continuar:<br><br>${pendingList}<br><br>Estas comandas permanecerán abiertas.`,
                { title: 'Control de Pendientes', confirmText: 'Revisados, cerrar caja', type: 'warning' }
            );
            if (!reviewed) return;
            data = await AppApi.closeCashDay({ ...payload, pending_reviewed: true });
        }
        await loadStateForTab('reports');
        renderReports();
        showToast(data.already_closed ? 'La caja de hoy ya estaba cerrada.' : 'Caja cerrada correctamente.', 'success');
    } catch (e) {
        showToast(e.message || 'No se pudo cerrar la caja.', 'error');
    } finally {
        closingCashDay = false;
    }
}
window.resetAllData = resetAllData;

// ==========================================================================
// PDF EXPORTS
// ==========================================================================

function runReport(reportKey, { mode = 'preview', input = {} } = {}) {
    if (!window.ReportRenderer || !window.ReportBuilders) {
        showToast('No se pudo preparar el reporte.', 'error');
        return;
    }

    const definition = ReportBuilders.build(reportKey, input);
    if (mode === 'pdf') {
        return ReportRenderer.pdf(definition);
    }
    if (mode === 'print') {
        return ReportRenderer.print(definition);
    }
    return ReportRenderer.preview(definition);
}
window.runReport = runReport;

function exportCierrePDF() {
    runReport('cashClosure', { mode: 'preview' });
}
window.exportCierrePDF = exportCierrePDF;

function exportVentasPDF() {
    const filteredSales = typeof getFilteredSales === 'function' ? getFilteredSales() : state.salesHistory;
    runReport('salesHistory', { mode: 'preview', input: { filteredSales } });
}
window.exportVentasPDF = exportVentasPDF;

function exportDrinksPDF() {
    const reportData = window.currentDrinksReportData;
    runReport('drinksOrder', { mode: 'preview', input: { rows: reportData } });
}
window.exportDrinksPDF = exportDrinksPDF;

function exportTopSellersPDF() {
    const filterType = window.currentRankingFilter || '';
    runReport('topSellers', { mode: 'preview', input: { type: filterType } });
}
window.exportTopSellersPDF = exportTopSellersPDF;

function exportTopSellersCSV() {
    const data = window.currentTopSellersData || [];
    if (data.length === 0) {
        showToast('No hay ventas registradas para exportar ranking.', 'warning');
        return;
    }

    const filterType = window.currentRankingFilter || '';
    const typeLabels = {
        'almuerzo': 'Almuerzos', 'segundo': 'Segundos', 'sopa': 'Sopas',
        'plato_extra': 'Platos Extra', 'extra': 'Bebidas', 'otro': 'Otros'
    };
    const filterLabel = filterType ? typeLabels[filterType] : 'Todos';

    let csvContent = "\uFEFF";
    csvContent += getCsvReportMetadata(`Ranking de ${filterLabel} mas vendidos`) + "\n";
    csvContent += "Posicion,Producto/Plato,Categoria,Unidades Vendidas,Ingresos Generados (Bs)\n";
    data.forEach((stat, idx) => {
        csvContent += `${idx + 1},"${sanitizeCsvCell(stat.name).replace(/"/g, '""')}",${stat.type},${stat.qty},${stat.total.toFixed(2)}\n`;
    });

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    const today = nowLocal().slice(0, 10);
    link.setAttribute("href", url);
    link.setAttribute("download", `ranking_${filterType || 'todos'}_${today}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    showToast('Ranking descargado en CSV.', 'success');
}
window.exportTopSellersCSV = exportTopSellersCSV;

function initReportDateFilter() {
    const startInput = document.getElementById('report-start-date');
    const endInput = document.getElementById('report-end-date');
    if (!startInput || !endInput) return;

    const today = nowLocal().slice(0, 10);
    startInput.value = today;
    endInput.value = today;
}
window.initReportDateFilter = initReportDateFilter;

window.setReportQuickRange = function(mode) {
    const startInput = document.getElementById('report-start-date');
    const endInput = document.getElementById('report-end-date');
    if (!startInput || !endInput) return;

    const today = nowLocal().slice(0, 10);
    let startDate = today;
    let endDate = today;

    switch (mode) {
        case 'today':
            startDate = today;
            endDate = today;
            break;
        case 'week':
            const weekAgo = new Date();
            weekAgo.setDate(weekAgo.getDate() - 6);
            startDate = weekAgo.toISOString().slice(0, 10);
            endDate = today;
            break;
        case 'month':
            const firstDay = new Date();
            firstDay.setDate(1);
            startDate = firstDay.toISOString().slice(0, 10);
            endDate = today;
            break;
        case 'all':
            startDate = '';
            endDate = '';
            break;
    }

    startInput.value = startDate;
    endInput.value = endDate;
    applyReportDateRange();
};

window.applyReportDateRange = async function() {
    const startInput = document.getElementById('report-start-date');
    const endInput = document.getElementById('report-end-date');
    if (!startInput || !endInput) return;

    const startDate = startInput.value;
    const endDate = endInput.value;

    if (!startDate && !endDate) {
        await loadReportsData(null, null);
    } else if (startDate && endDate) {
        if (startDate > endDate) {
            showToast('La fecha de inicio no puede ser mayor que la fecha fin.', 'error');
            return;
        }
        await loadReportsData(startDate, endDate);
    } else {
        showToast('Complete ambas fechas para filtrar.', 'error');
    }
};

async function loadReportsData(startDate, endDate) {
    try {
        const data = await AppApi.getReports(startDate, endDate);
        if (data.status === 'success') {
            state.salesHistory = data.salesHistory || [];
            state.cajaMovimientos = data.cajaMovimientos || [];
            state.cajaCierres = data.cajaCierres || [];
            state.reportContext = data.reportContext || {};
            renderReports();
            showToast('Reporte actualizado.', 'success');
        }
    } catch (err) {
        showToast('Error al cargar reportes: ' + err.message, 'error');
    }
}

// ==========================================================================
// C.1 — RANKING CATEGORY FILTER
// ==========================================================================

window.applyRankingCategoryFilter = function() {
    const select = document.getElementById('ranking-category-filter');
    const type = select ? select.value : '';
    window.currentRankingFilter = type;
    renderTopSellers();
};

// ==========================================================================
// C.3 — SALES HISTORY SEARCH FILTERS
// ==========================================================================

window.applySalesHistoryFilters = function() {
    renderSalesHistory();
};

window.clearSalesHistoryFilters = function() {
    const textEl = document.getElementById('sales-filter-text');
    const statusEl = document.getElementById('sales-filter-status');
    const paymentEl = document.getElementById('sales-filter-payment');
    const minEl = document.getElementById('sales-filter-min');
    const maxEl = document.getElementById('sales-filter-max');
    if (textEl) textEl.value = '';
    if (statusEl) statusEl.value = '';
    if (paymentEl) paymentEl.value = '';
    if (minEl) minEl.value = '';
    if (maxEl) maxEl.value = '';
    renderSalesHistory();
};

function getFilteredSales() {
    const textEl = document.getElementById('sales-filter-text');
    const statusEl = document.getElementById('sales-filter-status');
    const paymentEl = document.getElementById('sales-filter-payment');
    const minEl = document.getElementById('sales-filter-min');
    const maxEl = document.getElementById('sales-filter-max');

    const searchText = textEl ? textEl.value.trim().toLowerCase() : '';
    const statusFilter = statusEl ? statusEl.value : '';
    const paymentFilter = paymentEl ? paymentEl.value : '';
    const minAmount = minEl ? parseFloat(minEl.value) : NaN;
    const maxAmount = maxEl ? parseFloat(maxEl.value) : NaN;

    let filtered = [...state.salesHistory];

    if (searchText) {
        filtered = filtered.filter(sale => {
            const customer = (sale.customer || '').toLowerCase();
            const itemsMatch = (sale.items || []).some(item =>
                (item.name || '').toLowerCase().includes(searchText)
            );
            return customer.includes(searchText) || itemsMatch;
        });
    }

    if (statusFilter) {
        filtered = filtered.filter(sale => {
            if (statusFilter === 'cobrado') return sale.status === 'completado';
            if (statusFilter === 'pendiente_pagado') return sale.status === 'pendiente' && sale.paid;
            if (statusFilter === 'anulado') return sale.status === 'anulado';
            return sale.status === statusFilter;
        });
    }

    if (paymentFilter) {
        filtered = filtered.filter(sale => {
            if (paymentFilter === 'mixto') {
                return sale.paymentMethod && typeof sale.paymentMethod === 'object';
            }
            if (paymentFilter === 'efectivo') {
                return !sale.paymentMethod || sale.paymentMethod === 'efectivo';
            }
            return sale.paymentMethod === paymentFilter;
        });
    }

    if (!isNaN(minAmount)) {
        filtered = filtered.filter(sale => (parseFloat(sale.total) || 0) >= minAmount);
    }

    if (!isNaN(maxAmount)) {
        filtered = filtered.filter(sale => (parseFloat(sale.total) || 0) <= maxAmount);
    }

    return filtered;
}
window.getFilteredSales = getFilteredSales;

function updateSalesHistoryKPIs(filteredSales) {
    const totalEl = document.getElementById('sales-kpi-total');
    const revenueEl = document.getElementById('sales-kpi-revenue');
    const avgEl = document.getElementById('sales-kpi-avg');

    const paidSales = filteredSales.filter(s => s.status !== 'anulado');
    const total = paidSales.length;
    const revenue = paidSales.reduce((sum, s) => sum + (parseFloat(s.total) || 0), 0);
    const avg = total > 0 ? revenue / total : 0;

    if (totalEl) totalEl.textContent = total;
    if (revenueEl) revenueEl.textContent = formatCurrency(revenue);
    if (avgEl) avgEl.textContent = formatCurrency(avg);
}

document.addEventListener('DOMContentLoaded', function() {
    const bindings = [
        ['form-caja-apertura', handleSaveCajaApertura],
        ['form-caja-egreso', handleSaveCajaEgreso],
    ];
    bindings.forEach(([id, handler]) => {
        const form = document.getElementById(id);
        if (form) form.addEventListener('submit', handler);
    });
});
