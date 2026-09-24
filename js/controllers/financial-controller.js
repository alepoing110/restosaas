(function (window) {
    function sanitizeCsvCell(val) {
        const s = String(val ?? '');
        if (/^[=+\-@\t\r\n]/.test(s)) return "'" + s;
        return s;
    }
    const Controller = {
        initialized: false,
        range: '7d',
        branchId: '',
        dates() {
            const now = new Date();
            const local = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
            if (this.range === 'month') return { start: local(new Date(now.getFullYear(), now.getMonth(), 1)), end: local(now) };
            const days = this.range === '30d' ? 29 : 6;
            const start = new Date(now); start.setDate(now.getDate() - days);
            return { start: local(start), end: local(now) };
        },
        async load(start, end) {
            try {
                const [data, comparison, cashFlow, receivables, controlReport] = await Promise.all([
                    AppApi.getFinancialReport(start, end, this.branchId),
                    AppApi.getBranchComparison(start, end),
                    AppApi.getCashFlow(start, end),
                    AppApi.getReceivables(),
                    AppApi.getControlReport(start, end)
                ]);
                if (window.AppStore) AppStore.set({ financialReport: data, branchComparison: comparison, cashFlow, receivables, controlReport }); else { state.financialReport = data; state.branchComparison = comparison; state.cashFlow = cashFlow; state.receivables = receivables; state.controlReport = controlReport; }
                renderFinancial();
            } catch (error) { showToast(error.message || 'No se pudo cargar Finanzas.', 'error'); }
        },
        async applyRange() {
            const custom = this.range === 'custom';
            const dates = custom ? { start: document.getElementById('financial-start-date').value, end: document.getElementById('financial-end-date').value } : this.dates();
            if (!dates.start || !dates.end) return showToast('Seleccione ambas fechas.', 'warning');
            await this.load(dates.start, dates.end);
        },
        init() {
            if (this.initialized) return;
            this.initialized = true;
            document.querySelectorAll('[data-financial-range]').forEach(button => button.addEventListener('click', () => {
                document.querySelectorAll('[data-financial-range]').forEach(item => item.classList.remove('active'));
                button.classList.add('active'); this.range = button.dataset.financialRange;
                document.getElementById('financial-custom-dates').style.display = this.range === 'custom' ? 'flex' : 'none';
                if (this.range !== 'custom') this.applyRange();
            }));
            document.getElementById('btn-apply-financial-range')?.addEventListener('click', () => this.applyRange());
            document.getElementById('financial-branch-filter')?.addEventListener('change', event => { this.branchId = event.target.value; this.applyRange(); });
            document.getElementById('btn-open-expense-form')?.addEventListener('click', () => { document.getElementById('expense-date').value = new Date().toISOString().slice(0, 10); openModal('modal-financial-expense'); });
            document.getElementById('btn-cancel-expense')?.addEventListener('click', () => closeModal('modal-financial-expense'));
            document.getElementById('btn-close-expense')?.addEventListener('click', () => closeModal('modal-financial-expense'));
            document.getElementById('modal-financial-expense')?.addEventListener('click', event => { if (event.target.id === 'modal-financial-expense') closeModal('modal-financial-expense'); });
            document.addEventListener('keydown', event => { if (event.key === 'Escape') closeModal('modal-financial-expense'); });
            document.getElementById('form-financial-expense')?.addEventListener('submit', event => { event.preventDefault(); this.saveExpense(); });
            document.getElementById('expense-category')?.addEventListener('change', () => { const show = document.getElementById('expense-category').value === 'sueldos'; document.getElementById('expense-sueldo-fields').style.display = show ? 'contents' : 'none'; document.getElementById('expense-employee-name').required = show; document.getElementById('expense-payment-period').required = show; });
            document.getElementById('btn-export-financial-csv')?.addEventListener('click', () => this.exportCsv());
            document.getElementById('btn-print-financial')?.addEventListener('click', () => this.printReport());
            document.getElementById('financial-retry')?.addEventListener('click', () => window.switchTab('financial', { skipLoad: false }));
        },
        async saveExpense() {
            const branchId = document.getElementById('expense-branch').value;
            if (!branchId) return showToast('Seleccione una sucursal.', 'warning');
            const expenseId = `exp_${Date.now()}`;
            try {
                await AppApi.request('save_financial_expense', { id: expenseId, date: document.getElementById('expense-date').value, description: document.getElementById('expense-description').value, category: document.getElementById('expense-category').value, amount: document.getElementById('expense-amount').value, payment_method: document.getElementById('expense-payment').value, branch_id: branchId, reference: document.getElementById('expense-reference').value, employee_name: document.getElementById('expense-employee-name')?.value || '', payment_period: document.getElementById('expense-payment-period')?.value || '' });
                showToast('Gasto registrado.', 'success'); document.getElementById('form-financial-expense').reset(); closeModal('modal-financial-expense'); await this.applyRange();
                if (await ConfirmDialog.show('¿Desea imprimir el recibo de este gasto?', { title: 'Gasto registrado', confirmText: 'Imprimir recibo', type: 'info' })) {
                    const index = (state.financialReport?.expenses || []).findIndex(item => item.id === expenseId);
                    if (index >= 0) this.printExpense(index);
                }
            } catch (error) { showToast(error.message || 'No se pudo registrar el gasto.', 'error'); }
        },
        async collectReceivable(index) {
            const receivable = state.receivables?.receivables?.[index];
            if (!receivable) return;
            const rawAmount = prompt(`Saldo pendiente: Bs ${Number(receivable.balance).toFixed(2)}\nMonto a cobrar:`, Number(receivable.balance).toFixed(2));
            if (rawAmount === null) return;
            const amount = Number(rawAmount);
            const paymentMethod = prompt('Método de pago: efectivo, qr, tarjeta, transferencia u otro', 'efectivo');
            if (!Number.isFinite(amount) || amount <= 0 || !paymentMethod) return showToast('Datos del cobro inválidos.', 'warning');
            try {
                await AppApi.saveReceivablePayment({ receivable_id: receivable.id, amount, payment_method: paymentMethod.trim().toLowerCase() });
                showToast('Cobro registrado.', 'success');
                const dates = this.range === 'custom' ? { start: document.getElementById('financial-start-date').value, end: document.getElementById('financial-end-date').value } : this.dates();
                await this.load(dates.start, dates.end);
            } catch (error) { showToast(error.message || 'No se pudo registrar el cobro.', 'error'); }
        },
        async deleteExpense(id, branchId = '') {
            if (!confirm('¿Eliminar este gasto?')) return;
            try { await AppApi.request('delete_financial_expense', { id, branch_id: branchId || this.branchId }); showToast('Gasto eliminado.', 'success'); await this.applyRange(); } catch (error) { showToast(error.message || 'No se pudo eliminar el gasto.', 'error'); }
        },
        printExpense(index) {
            const expense = state.financialReport?.expenses?.[index];
            if (!expense) return showToast('No se encontró el gasto para imprimir.', 'warning');
            const business = state.business || {};
            const branch = (state.authorizedBranches || []).find(item => item.id === expense.branch_id) || state.branch || {};
            const popup = window.open('', '_blank', 'width=820,height=720');
            if (!popup) return showToast('Permita ventanas emergentes para imprimir.', 'warning');
            const isSueldo = expense.category === 'sueldos';
            const title = isSueldo ? 'Recibo de pago de sueldo' : (expense.legacy ? 'Comprobante de egreso de caja' : 'Comprobante de gasto');
            const css = `body{margin:0;background:#f1f5f9;color:#1e293b;font:14px Arial,sans-serif}.receipt{width:760px;max-width:calc(100% - 32px);margin:28px auto;background:#fff;padding:34px;box-sizing:border-box;border:1px solid #dbe3ec;box-shadow:0 8px 24px #0f172a18}.brand{border-bottom:2px solid #4f46e5;padding-bottom:18px;margin-bottom:22px}.brand h1{margin:0 0 6px;color:#312e81;font-size:24px}.brand p{margin:3px 0;color:#64748b}.title{text-align:center;text-transform:uppercase;letter-spacing:1px;margin:22px 0;font-size:18px}.meta{display:grid;grid-template-columns:1fr 1fr;gap:12px}.item{border:1px solid #e2e8f0;border-radius:6px;padding:12px}.item small{display:block;text-transform:uppercase;color:#64748b;font-size:10px;margin-bottom:5px}.item strong{font-size:14px}.amount{background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:18px;text-align:right;margin:20px 0}.amount small{display:block;color:#4338ca;text-transform:uppercase}.amount strong{display:block;color:#312e81;font-size:28px;margin-top:5px}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:50px;text-align:center}.sig-line{border-top:1px solid #1e293b;margin-top:60px;padding-top:8px;font-size:12px;color:#64748b}.footer{border-top:1px solid #e2e8f0;padding-top:18px;color:#64748b;font-size:11px;display:flex;justify-content:space-between}@media print{body{background:#fff}.receipt{width:100%;max-width:none;margin:0;border:0;box-shadow:none}}@media(max-width:600px){.meta{grid-template-columns:1fr}.receipt{padding:22px}}`;
            const metaRows = `<div class="item"><small>Fecha de pago</small><strong>${escapeHtml(expense.date || '')}</strong></div><div class="item"><small>Sucursal</small><strong>${escapeHtml(branch.name || 'Sucursal')}</strong></div>`;
            let extraMeta = '';
            if (isSueldo) {
                extraMeta = `<div class="item"><small>Empleado</small><strong>${escapeHtml(expense.employee_name || '—')}</strong></div><div class="item"><small>Periodo pagado</small><strong>${escapeHtml(expense.payment_period || '—')}</strong></div>`;
            }
            const bottomMeta = `<div class="item"><small>Categoría</small><strong>${escapeHtml(financialLabel(expense.category))}</strong></div><div class="item"><small>Método de pago</small><strong>${escapeHtml(financialLabel(expense.payment_method))}</strong></div>`;
            const refRow = expense.reference ? `<div class="item" style="grid-column:1/-1"><small>Referencia</small><strong>${escapeHtml(expense.reference)}</strong></div>` : '';
            const descRow = `<div class="item" style="grid-column:1/-1"><small>Descripción</small><strong>${escapeHtml(expense.description || '')}</strong></div>`;
            const signatures = isSueldo ? `<div class="signatures"><div class="sig-line">Firma de quien entrega</div><div class="sig-line">Firma de quien recibe</div></div>` : '';
            popup.document.write(`<html><head><title>${escapeHtml(title)}</title><style>${css}</style></head><body><main class="receipt"><header class="brand"><h1>${escapeHtml(business.nombre_restaurante || 'RestoCloud')}</h1><p>${escapeHtml(business.direccion || '')}</p><p>${escapeHtml(business.telefono || '')}</p></header><h2 class="title">${escapeHtml(title)}</h2><section class="meta">${metaRows}${extraMeta}${bottomMeta}${refRow}${descRow}</section><div class="amount"><small>Total</small><strong>${financialMoney(expense.amount)}</strong></div>${signatures}<footer class="footer"><span>RestoCloud · Comprobante</span><span>${escapeHtml(expense.date || '')}</span></footer></main><script>window.onload=()=>window.print();<\/script></body></html>`);
            popup.document.close();
        },
        exportCsv() {
            const rows = state.financialReport?.expenses || [];
            const csv = [['Fecha', 'Descripción', 'Categoría', 'Método de pago', 'Monto'], ...rows.map(row => [row.date, row.description, financialLabel(row.category), financialLabel(row.payment_method), row.amount])].map(row => row.map(value => `"${sanitizeCsvCell(value).replace(/"/g, '""')}"`).join(',')).join('\n');
            const link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([`\ufeff${csv}`], { type: 'text/csv;charset=utf-8' })); link.download = `finanzas-${state.financialReport?.start_date || 'reporte'}-${state.financialReport?.end_date || ''}.csv`; link.click(); URL.revokeObjectURL(link.href);
        },
        printReport() {
            const report = state.financialReport;
            if (!report) return;
            const summary = report.summary || {};
            const rows = (report.expenses || []).map(row => `<tr><td>${escapeHtml(row.date)}</td><td>${escapeHtml(row.description)}</td><td>${escapeHtml(financialLabel(row.category))}</td><td>${financialMoney(row.amount)}</td></tr>`).join('');
            const popup = window.open('', '_blank', 'width=900,height=700');
            if (!popup) return showToast('Permita ventanas emergentes para imprimir.', 'warning');
            popup.document.write(`<html><head><title>Reporte financiero</title><style>body{font:14px Arial;color:#1f2937;padding:28px}h1{margin-bottom:4px}p{color:#64748b}.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:22px 0}.card{border:1px solid #ddd;border-radius:8px;padding:12px}.card b{display:block;font-size:20px;margin-top:8px}table{width:100%;border-collapse:collapse;margin-top:18px}th,td{text-align:left;padding:8px;border-bottom:1px solid #ddd}th{background:#f3f4f6}</style></head><body><h1>Reporte financiero</h1><p>${escapeHtml(report.start_date)} al ${escapeHtml(report.end_date)}</p><div class="summary"><div class="card">Ventas netas<b>${financialMoney(summary.net_sales)}</b></div><div class="card">Gastos totales<b>${financialMoney(summary.total_expenses)}</b></div><div class="card">Ganancia operativa<b>${financialMoney(summary.operating_profit)}</b></div></div><h2>Gastos</h2><table><thead><tr><th>Fecha</th><th>Descripción</th><th>Categoría</th><th>Monto</th></tr></thead><tbody>${rows || '<tr><td colspan="4">Sin gastos registrados</td></tr>'}</tbody></table><script>window.onload=()=>window.print();</script></body></html>`);
            popup.document.close();
        }
    };
    window.FinanceController = Controller;
    window.addEventListener('DOMContentLoaded', () => Controller.init());
})(window);
