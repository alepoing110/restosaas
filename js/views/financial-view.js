function financialMoney(value) {
    return `Bs ${Number(value || 0).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function financialLabel(value) {
    const labels = { caja: 'Egreso de caja', compras: 'Compras', sueldos: 'Sueldos', alquiler: 'Alquiler', servicios: 'Servicios', transporte: 'Transporte', impuestos: 'Impuestos', marketing: 'Marketing', mantenimiento: 'Mantenimiento', otros: 'Otros', efectivo: 'Efectivo', qr: 'QR', tarjeta: 'Tarjeta', transferencia: 'Transferencia', otro: 'Otro' };
    return labels[value] || value || 'Sin categoría';
}

function renderFinancial() {
    const report = state.financialReport;
    if (!report) return;
    const summary = report.summary || {};
    const set = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value; };
    set('financial-net-sales', financialMoney(summary.net_sales));
    set('financial-total-expenses', financialMoney(summary.total_expenses));
    set('financial-operating-profit', financialMoney(summary.operating_profit));
    set('financial-average-ticket', financialMoney(summary.average_ticket));
    set('financial-sales-count', `${summary.sales_count || 0} ventas`);
    set('financial-expense-count', `${(report.expenses || []).length} movimientos`);
    set('financial-annulled', `${summary.annulled_count || 0} anulados · ${financialMoney(summary.annulled_amount)} · Devoluciones: ${financialMoney(summary.refund_amount || 0)}`);
    set('financial-period-label', `${report.start_date || ''} al ${report.end_date || ''}${report.branch_id ? '' : ' · Todas las sucursales'}`);

    const comparisonBody = document.getElementById('financial-branch-comparison-body');
    const comparisonEmpty = document.getElementById('financial-empty-branch-comparison');
    const comparisonRows = state.branchComparison?.branches || [];
    if (comparisonBody) comparisonBody.innerHTML = comparisonRows.map(branch => `<tr><td><strong>${escapeHtml(branch.branch_name || '')}</strong></td><td style="text-align:right">${financialMoney(branch.net_sales)}</td><td style="text-align:right">${financialMoney(branch.expenses)}</td><td style="text-align:right">${financialMoney(branch.operating_profit)}</td><td style="text-align:right">${financialMoney(branch.average_ticket)}</td><td style="text-align:right">${branch.annulled_count || 0} · ${financialMoney(branch.annulled_amount)}</td></tr>`).join('');
    if (comparisonEmpty) comparisonEmpty.style.display = comparisonRows.length ? 'none' : '';

    const cashFlowBody = document.getElementById('financial-cash-flow-body');
    const cashFlowEmpty = document.getElementById('financial-empty-cash-flow');
    const cashFlowRows = state.cashFlow?.daily || [];
    if (cashFlowBody) cashFlowBody.innerHTML = cashFlowRows.map(row => `<tr><td>${escapeHtml(row.date || '')}</td><td style="text-align:right">${financialMoney(row.opening)}</td><td style="text-align:right">${financialMoney(row.cash_sales)}</td><td style="text-align:right">${financialMoney(row.cash_expenses)}</td><td style="text-align:right">${financialMoney(row.expected_closing)}</td><td style="text-align:right">${row.actual_closing === null ? '—' : financialMoney(row.actual_closing)}</td><td style="text-align:right">${row.variance === null ? '—' : financialMoney(row.variance)}</td></tr>`).join('');
    if (cashFlowEmpty) cashFlowEmpty.style.display = cashFlowRows.some(row => Number(row.opening) || Number(row.cash_sales) || Number(row.cash_expenses) || row.actual_closing !== null) ? 'none' : '';

    const receivableBody = document.getElementById('financial-receivables-body');
    const receivableEmpty = document.getElementById('financial-empty-receivables');
    const receivables = state.receivables?.receivables || [];
    const receivableSummary = document.getElementById('financial-receivables-summary');
    if (receivableSummary) receivableSummary.textContent = `${receivables.length} cuentas · Saldo pendiente: ${financialMoney(state.receivables?.summary?.balance || 0)}`;
    if (receivableBody) receivableBody.innerHTML = receivables.map((row, index) => `<tr><td><strong>${escapeHtml(row.customer_name || '')}</strong>${row.phone ? `<small class="financial-reference">${escapeHtml(row.phone)}</small>` : ''}</td><td>${escapeHtml(row.order_id || '')}</td><td>${escapeHtml(row.due_date || '')}</td><td><span class="badge-status">${escapeHtml(row.status || '')}</span></td><td style="text-align:right">${financialMoney(row.balance)}</td><td style="text-align:right"><button class="btn btn-sm btn-primary" onclick="FinanceController.collectReceivable(${index})">Cobrar</button></td></tr>`).join('');
    if (receivableEmpty) receivableEmpty.style.display = receivables.length ? 'none' : '';

    const control = state.controlReport || {};
    const controlSummary = document.getElementById('financial-control-summary');
    if (controlSummary) controlSummary.textContent = `Descuentos: ${financialMoney(control.discount_summary?.amount || 0)} · Anulaciones: ${financialMoney(control.annulled_summary?.amount || 0)} · Devoluciones: ${financialMoney(control.refund_summary?.amount || 0)}`;
    const discountsBody = document.getElementById('financial-discounts-body');
    const discounts = control.discounts || [];
    if (discountsBody) discountsBody.innerHTML = discounts.map(row => `<tr><td>${escapeHtml(row.order_id || '')}</td><td>${escapeHtml(row.customer || '')}</td><td>${escapeHtml(row.discount_name || row.discount_id || 'Descuento')}</td><td style="text-align:right">${financialMoney(row.amount)}</td></tr>`).join('');
    const emptyDiscounts = document.getElementById('financial-empty-discounts');
    if (emptyDiscounts) emptyDiscounts.style.display = discounts.length ? 'none' : '';
    const annulledBody = document.getElementById('financial-annulled-body');
    const annulled = control.annulled || [];
    if (annulledBody) annulledBody.innerHTML = annulled.map(row => `<tr><td>${escapeHtml(row.id || '')}</td><td>${escapeHtml(row.customer || '')}</td><td>${escapeHtml(row.reason || 'Sin motivo registrado')}</td><td style="text-align:right">${financialMoney(row.total)}</td></tr>`).join('');
    const emptyAnnulled = document.getElementById('financial-empty-annulled');
    if (emptyAnnulled) emptyAnnulled.style.display = annulled.length ? 'none' : '';

    const branches = report.branches || state.authorizedBranches || [];
    ['financial-branch-filter', 'expense-branch'].forEach(id => {
        const select = document.getElementById(id);
        if (!select) return;
        const current = select.value;
        select.innerHTML = id === 'financial-branch-filter' ? '<option value="">Todas las sucursales</option>' : '<option value="">Seleccione sucursal</option>';
        branches.forEach(branch => { select.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(branch.id)}">${escapeHtml(branch.name)}</option>`); });
        if (current && [...select.options].some(option => option.value === current)) select.value = current;
        if (id === 'financial-branch-filter' && report.branch_id) select.value = report.branch_id;
    });

    const daily = document.getElementById('financial-daily-table');
    if (daily) daily.innerHTML = (report.daily || []).map(row => `<div class="financial-daily-row"><span>${escapeHtml(row.date)}</span><strong>${financialMoney(row.sales)}</strong><em>${financialMoney(row.expenses)}</em></div>`).join('') || '<p class="muted-empty">Sin movimientos para este período.</p>';
    const renderBreakdown = (id, values) => { const el = document.getElementById(id); if (el) el.innerHTML = Object.entries(values || {}).filter(([, value]) => Number(value) > 0).sort((a, b) => b[1] - a[1]).map(([key, value]) => `<div class="financial-breakdown-row"><span>${escapeHtml(financialLabel(key))}</span><strong>${financialMoney(value)}</strong></div>`).join('') || '<p class="muted-empty">Sin datos.</p>'; };
    renderBreakdown('financial-category-list', report.expenses_by_category);
    renderBreakdown('financial-payment-list', report.expenses_by_payment);
    renderBreakdown('financial-sales-payment-list', report.payments);
    const products = document.getElementById('financial-top-products');
    if (products) products.innerHTML = (report.top_products || []).map(product => `<div class="financial-breakdown-row"><span>${escapeHtml(product.name)}</span><strong>${financialMoney(product.revenue)}</strong></div>`).join('') || '<p class="muted-empty">Sin ventas.</p>';

    const body = document.getElementById('financial-expenses-body');
    const empty = document.getElementById('financial-empty-expenses');
    const rows = report.expenses || [];
    if (body) body.innerHTML = rows.map((expense, index) => {
        const descExtra = expense.category === 'sueldos' && expense.employee_name ? `<br><small class="financial-reference">Empleado: ${escapeHtml(expense.employee_name)} · Periodo: ${escapeHtml(expense.payment_period || '')}</small>` : '';
        return `<tr><td>${escapeHtml(expense.date || '')}</td><td>${escapeHtml(expense.description || '')}${expense.reference ? `<br><small class="financial-reference">${escapeHtml(expense.reference)}</small>` : ''}${descExtra}</td><td>${escapeHtml(financialLabel(expense.category))}</td><td>${escapeHtml(financialLabel(expense.payment_method))}</td><td style="text-align:right">${financialMoney(expense.amount)}</td><td style="text-align:right;white-space:nowrap"><button class="btn-icon" title="Imprimir recibo" onclick="FinanceController.printExpense(${index})"><i class="fa-solid fa-print"></i></button> ${expense.legacy ? '<span class="badge-status">Histórico</span>' : `<button class="btn-icon btn-icon--danger" title="Eliminar gasto" onclick="FinanceController.deleteExpense('${escapeHtml(expense.id)}', '${escapeHtml(expense.branch_id || '')}')"><i class="fa-solid fa-trash"></i></button>`}</td></tr>`;
    }).join('');
    if (empty) empty.style.display = rows.length ? 'none' : '';
}

window.renderFinancial = renderFinancial;
