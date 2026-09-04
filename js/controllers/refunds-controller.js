// ==========================================================================
// REFUNDS REPORT CONTROLLER
// ==========================================================================

window.exportRefundsToCSV = function () {
    const report = state.refundsReport;
    if (!report || !report.refunds || report.refunds.length === 0) {
        showToast('No hay devoluciones para exportar.', 'warning');
        return;
    }

    const filtered = typeof getFilteredRefunds === 'function' ? getFilteredRefunds() : report.refunds;

    let csvContent = "\uFEFF";
    csvContent += "Fecha,Hora,Venta,Cliente,Productos,Tipo,Monto,Metodo,Motivo\n";
    filtered.forEach(r => {
        const items = (r.items || []).map(i => `${i.quantity}x ${i.item_name}`).join(' | ');
        const time = formatTime(r.created_at);
        const reason = (r.reason || 'Solicitud del cliente').replace(/"/g, '""');
        const customer = (r.customer || '').replace(/"/g, '""');
        csvContent += `"${r.created_at.slice(0,10)}","${time}","${r.order_id.slice(-6).toUpperCase()}","${customer}","${items.replace(/"/g, '""')}","${r.scope}",${r.total_refunded.toFixed(2)},"${r.refund_method}","${reason}"\n`;
    });

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    const today = nowLocal().slice(0, 10);
    link.setAttribute("href", url);
    link.setAttribute("download", `devoluciones_${today}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    showToast(`CSV de devoluciones descargado (${filtered.length} registros).`, 'success');
};

let refundModalSale = null;

function refundSaleById(orderId) {
    return (state.salesHistory || []).find(sale => sale.id === orderId) || null;
}

function renderRefundItemsPicker() {
    const picker = document.getElementById('refund-items-picker');
    if (!picker || !refundModalSale) return;
    const scope = document.getElementById('refund-scope').value;
    const show = scope === 'producto_parcial' || scope === 'producto_total';
    picker.style.display = show ? '' : 'none';
    if (!show) { picker.innerHTML = ''; return; }
    picker.innerHTML = '<label class="label-overline">Productos a devolver</label>' + (refundModalSale.items || []).map((item, index) => {
        const quantity = Number(item.qty || item.quantity || 1);
        return `<label style="display:flex; align-items:center; gap:8px; margin:8px 0; font-size:12px;">
            <input type="checkbox" class="refund-item-check" data-line-no="${index}" ${scope === 'producto_total' ? 'checked' : ''}>
            <span style="flex:1;">${quantity}x ${escapeHtml(item.name || 'Producto')} · ${formatCurrency(item.price || 0)}</span>
            <input type="number" class="form-input refund-item-qty" data-line-no="${index}" min="0.001" max="${quantity}" step="0.001" value="${scope === 'producto_total' ? quantity : 1}" style="width:90px;">
        </label>`;
    }).join('');
}

window.openRefundModal = function (orderId) {
    const sale = refundSaleById(orderId);
    if (!sale) return showToast('Venta no encontrada.', 'error');
    refundModalSale = sale;
    document.getElementById('refund-order-id').value = sale.id;
    document.getElementById('refund-order-summary').textContent = `${sale.customer || 'Cliente'} · Total ${formatCurrency(sale.total || 0)}`;
    document.getElementById('refund-scope').value = 'producto_parcial';
    document.getElementById('refund-method').value = 'efectivo';
    document.getElementById('refund-reason').value = '';
    renderRefundItemsPicker();
    openModal('modal-refund');
};

window.closeRefundModal = function () {
    closeModal('modal-refund');
    refundModalSale = null;
};

async function submitRefund() {
    if (!refundModalSale) return;
    const scope = document.getElementById('refund-scope').value;
    const reason = document.getElementById('refund-reason').value.trim();
    if (!reason) return showToast('Indique el motivo de la devolución.', 'warning');
    const items = [];
    if (scope === 'producto_parcial' || scope === 'producto_total') {
        document.querySelectorAll('.refund-item-check:checked').forEach(check => {
            const lineNo = Number(check.dataset.lineNo);
            const qtyInput = document.querySelector(`.refund-item-qty[data-line-no="${lineNo}"]`);
            items.push({ line_no: lineNo, quantity: Number(qtyInput?.value || 0) });
        });
        if (!items.length) return showToast('Seleccione al menos un producto.', 'warning');
    }
    if (!(await ConfirmDialog.show('La devolución se aplicará inmediatamente. ¿Continuar?', { title: 'Confirmar devolución', confirmText: 'Aplicar', type: 'danger' }))) return;
    try {
        await AppApi.createRefund({ order_id: refundModalSale.id, scope, refund_method: document.getElementById('refund-method').value, reason, items });
        showToast('Devolución aplicada correctamente.', 'success');
        closeRefundModal();
        await loadStateForTab('reports');
        if (typeof renderReports === 'function') renderReports();
    } catch (error) {
        showToast(error.message || 'No se pudo aplicar la devolución.', 'error');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const csvBtn = document.getElementById('btn-export-refunds-csv');
    if (csvBtn) csvBtn.addEventListener('click', window.exportRefundsToCSV);
    document.getElementById('refund-scope')?.addEventListener('change', renderRefundItemsPicker);
    document.getElementById('btn-submit-refund')?.addEventListener('click', submitRefund);
});
