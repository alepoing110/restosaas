(function (window) {
    'use strict';

    function escape(value) {
        return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
    }

    function settings() {
        return state.business || {};
    }

    function displayOrderNumber(order) {
        if (order.orderNumber || order.order_number) return `#${escape(order.orderNumber || order.order_number)}`;
        const rawId = String(order.id || '').replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
        return `#${escape(rawId.slice(-6) || '000001')}`;
    }

    function isEnabled() {
        return settings().print_enabled !== '0' && settings().print_enabled !== false;
    }

    const AGENT_URL = 'http://127.0.0.1:3210';

    async function agentRequest(path, options = {}) {
        const response = await fetch(`${AGENT_URL}${path}`, { ...options, headers: { 'Content-Type': 'application/json', ...(options.headers || {}) } });
        const data = await response.json();
        if (!response.ok || data.status !== 'success') throw new Error(data.message || 'El agente local rechazó la solicitud.');
        return data;
    }

    function printerInputId(destination) {
        return { cocina: 'printer-kitchen-input', pagos: 'printer-payment-input', cliente: 'printer-customer-input', mesero: 'printer-waiter-input' }[destination];
    }

    async function refreshPrinters() {
        const status = document.getElementById('print-agent-status');
        try {
            const data = await agentRequest('/printers');
            document.querySelectorAll('.printer-select').forEach(select => {
                const current = select.value || '';
                select.innerHTML = '<option value="">Seleccionar impresora</option>' + data.printers.map(printer => `<option value="${escape(printer.name)}">${escape(printer.name)}${printer.isDefault ? ' (predeterminada)' : ''}</option>`).join('');
                const configured = current || (select.id === 'printer-kitchen-input' ? settings().printer_kitchen : select.id === 'printer-payment-input' ? settings().printer_payment : select.id === 'printer-customer-input' ? settings().printer_customer : settings().printer_waiter);
                if (configured && data.printers.some(printer => printer.name === configured)) select.value = configured;
            });
            if (status) status.textContent = `${data.printers.length} impresora(s) detectada(s)`;
            return data.printers;
        } catch (error) {
            if (status) status.textContent = 'Agente local no disponible';
            return [];
        }
    }

    async function downloadInstaller() {
        try {
            const response = await fetch('print-agent/install-windows.ps1?v=1');
            if (!response.ok) throw new Error('No se pudo descargar el instalador.');
            const template = await response.text();
            const baseUrl = `${window.location.origin}${window.location.pathname.replace(/\/[^/]*$/, '')}/print-agent`;
            const installer = template.replace('__RESTOCLOUD_AGENT_BASE_URL__', baseUrl);
            const blob = new Blob([installer], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'Instalar-RestoCloud-Print-Agent.ps1';
            link.click();
            URL.revokeObjectURL(url);
            window.showToast?.('Instalador descargado. Ejecútalo con PowerShell como usuario de Windows.', 'success');
        } catch (error) {
            window.showToast?.(error.message || 'No se pudo descargar el instalador.', 'error');
        }
    }

    function itemUnitPrice(item) {
        const salsas = (item.salsas || []).reduce((sum, salsa) => sum + Number(salsa.salsaPrice || salsa.price || 0), 0);
        const accompaniments = (item.accompaniments || []).reduce((sum, item) => sum + Number(item.accompanimentPrice || item.price || 0), 0);
        return Number(item.price || 0) + salsas + accompaniments;
    }

    function itemLines(order, { showAmounts = true } = {}) {
        return (order.items || []).map(item => {
            const qty = Number(item.qty || item.quantity || 1);
            const salsas = (item.salsas || []).map(salsa => salsa.name || salsa.salsaName).filter(Boolean).join(', ');
            const accompaniments = (item.accompaniments || []).map(item => item.name || item.accompanimentName).filter(Boolean).join(', ');
            const details = [item.sopaName, item.segundoName].filter(Boolean).join(' / ');
            const options = [details, salsas ? `Salsas: ${salsas}` : '', accompaniments ? `Acompañamientos: ${accompaniments}` : '', item.notes ? `Nota: ${item.notes}` : ''].filter(Boolean);
            const lineTotal = itemUnitPrice(item) * qty;
            return `<div class="ticket-item"><div class="ticket-item-main${showAmounts ? '' : ' ticket-item-main--kitchen'}"><span class="ticket-item-qty">${qty}x</span><span class="ticket-item-name">${escape(item.name || 'Producto')}</span>${showAmounts ? `<strong class="ticket-item-total">${money(lineTotal)}</strong>` : ''}</div>${options.map(option => `<div class="ticket-item-option">${escape(option)}</div>`).join('')}</div>`;
        }).join('');
    }

    function paymentLines(paymentMethod) {
        if (paymentMethod && typeof paymentMethod === 'object') {
            const entries = Object.entries(paymentMethod).filter(([, amount]) => Number(amount) > 0);
            return entries.length
                ? entries.map(([method, amount]) => `<div class="ticket-summary-row"><span>${escape(paymentLabel(method))}</span><span>${money(amount)}</span></div>`).join('')
                : '<div class="ticket-summary-row"><span>Forma de pago</span><span>Mixto</span></div>';
        }
        return `<div class="ticket-summary-row"><span>Forma de pago</span><span>${escape(paymentLabel(paymentMethod))}</span></div>`;
    }

    function paymentLabel(method) {
        return ({ efectivo: 'Efectivo', qr: 'QR', tarjeta: 'Tarjeta', credito: 'Crédito', mixto: 'Mixto' })[String(method || '').toLowerCase()] || String(method || 'Efectivo');
    }

    function orderReference(order) {
        if (order.deliveryType === 'mesa') return order.customer || 'Mesa sin nombre';
        if (order.deliveryType === 'llevar') return `Para llevar${order.customer ? ` · ${order.customer}` : ''}`;
        return order.customer || 'Cliente';
    }

    function frame(title, order, body, options = {}) {
        const width = settings().print_paper_width === '58' ? '58mm' : '80mm';
        const businessName = settings().nombre_restaurante || settings().business_name || 'RestoCloud';
        const branchName = order.branchName || state.branch?.name || 'Sucursal';
        const createdBy = order.createdByName || order.created_by_name || state.authUser?.name || 'Usuario no disponible';
        const paidBy = order.paidByName || order.paid_by_name || createdBy;
        const showCashier = options.showCashier ?? title === 'RECIBO DE PAGO';
        return `<!doctype html><html><head><meta charset="utf-8"><title>${escape(title)}</title><style>@page{size:${width} auto;margin:0}*{box-sizing:border-box}html,body{margin:0;padding:0}body{font-family:Arial,sans-serif;width:${width};padding:3mm;color:#111;font-size:11px;line-height:1.3}.ticket-head{text-align:center;border-bottom:2px solid #111;padding:1mm 0 2mm}.ticket-business{font-size:16px;font-weight:800;text-transform:uppercase;line-height:1.15}.ticket-branch{font-size:10px;margin-top:1mm}.ticket-kind{font-size:10px;font-weight:800;letter-spacing:.7px;margin-top:2mm}.ticket-rule{border-top:1px dashed #555;margin:2.5mm 0}.ticket-meta{font-size:10px;line-height:1.45}.ticket-meta-row{display:flex;justify-content:space-between;gap:3mm}.ticket-meta-row span:last-child{text-align:right}.ticket-column-head{display:grid;grid-template-columns:8mm 1fr 18mm;gap:1mm;font-size:9px;font-weight:800;padding-bottom:1mm;border-bottom:1px solid #111}.ticket-column-head span:last-child{text-align:right}.ticket-item{padding:2mm 0;border-bottom:1px dashed #aaa;page-break-inside:avoid}.ticket-item-main{display:grid;grid-template-columns:8mm 1fr 18mm;gap:1mm;align-items:start}.ticket-item-main--kitchen{grid-template-columns:8mm 1fr}.ticket-item-qty{font-weight:800}.ticket-item-name{font-weight:700;overflow-wrap:anywhere}.ticket-item-total{text-align:right;font-size:10px}.ticket-item-option{margin:1mm 0 0 8mm;font-size:9px;color:#333;overflow-wrap:anywhere}.ticket-summary{margin-top:3mm;margin-left:auto;width:100%;max-width:52mm}.ticket-summary-row{display:flex;justify-content:space-between;gap:3mm;padding:.5mm 0}.ticket-summary-row span:last-child{text-align:right}.ticket-grand-total{display:flex;justify-content:space-between;gap:3mm;border-top:2px solid #111;border-bottom:2px solid #111;margin-top:1mm;padding:1.5mm 0;font-size:14px;font-weight:800}.ticket-note{margin-top:3mm;padding:2mm;border:1px dashed #666;font-size:9px}.ticket-footer{text-align:center;margin-top:4mm;font-size:9px}.ticket-footer strong{display:block;font-size:10px;margin-bottom:1mm}body{print-color-adjust:exact;-webkit-print-color-adjust:exact}</style></head><body><header class="ticket-head"><div class="ticket-business">${escape(businessName)}</div><div class="ticket-branch">${escape(branchName)}</div><div class="ticket-kind">${escape(title)}</div></header><div class="ticket-meta"><div class="ticket-meta-row"><span><b>Pedido:</b> ${displayOrderNumber(order)}</span><span>${escape(new Date(order.timestamp || Date.now()).toLocaleString('es-BO'))}</span></div><div class="ticket-meta-row"><span><b>Atención:</b> ${escape(orderReference(order))}</span></div><div class="ticket-meta-row"><span><b>Registró:</b> ${escape(createdBy)}</span></div>${showCashier ? `<div class="ticket-meta-row"><span><b>Cobró:</b> ${escape(paidBy)}</span></div>` : ''}${order.pickupTime ? `<div class="ticket-meta-row"><span><b>Recojo:</b> ${escape(order.pickupTime)}</span></div>` : ''}</div><div class="ticket-rule"></div>${body}<footer class="ticket-footer"><strong>Gracias por su preferencia</strong><span>${escape(options.footerText || 'Conserve este comprobante')}</span></footer></body></html>`;
    }

    function openPrintDocument(html) {
        // The print document must remain script-accessible so the browser can render it before printing.
        const printWindow = window.open('', '_blank', 'width=420,height=700');
        if (!printWindow) throw new Error('El navegador bloqueó la ventana de impresión. Permite ventanas emergentes.');
        printWindow.document.open();
        printWindow.document.write(html);
        printWindow.document.close();
        printWindow.focus();
        window.setTimeout(() => {
            try {
                printWindow.focus();
                printWindow.print();
            } catch (error) {
                console.error('[PRINT] No se pudo abrir el diálogo de impresión:', error);
            }
        }, 250);
    }

    function dispatch(html, destination) {
        const printer = document.getElementById(printerInputId(destination))?.value || settings()[{ cocina: 'printer_kitchen', pagos: 'printer_payment', cliente: 'printer_customer', mesero: 'printer_waiter' }[destination]] || '';
        if (!printer) return openPrintDocument(html);
        const text = html
            .replace(/<style[\s\S]*?<\/style>/gi, '')
            .replace(/<script[\s\S]*?<\/script>/gi, '')
            .replace(/<\/(?:div|header|footer|p|h\d|tr)>/gi, '\n')
            .replace(/<br\s*\/?\s*>/gi, '\n')
            .replace(/<\/td>/gi, '  ')
            .replace(/<br\s*\/?\s*>/gi, '\n')
            .replace(/<[^>]+>/g, '')
            .replace(/&nbsp;/g, ' ')
            .replace(/&amp;/g, '&')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&#39;/g, "'")
            .replace(/&quot;/g, '"')
            .replace(/[ \t]+\n/g, '\n')
            .replace(/\n{3,}/g, '\n\n')
            .trim() + '\n';
        agentRequest('/print', { method: 'POST', body: JSON.stringify({ printer, text }) }).catch(() => openPrintDocument(html));
    }

    function printWaiterTicket(order) {
        if (!isEnabled()) return window.showToast?.('La impresión está desactivada en Configuración.', 'warning');
        if (settings().waiter_ticket_enabled === '0' || settings().waiter_ticket_enabled === false) return window.showToast?.('Activa el ticket para mesero en Configuración > Impresión.', 'warning');
        const body = `<div class="print-note"><b>Atender esta mesa:</b> ${escape(order.customer || 'Mesa sin nombre')}</div>${itemLines(order)}${order.waiterNote ? `<div class="print-note"><b>Nota general:</b> ${escape(order.waiterNote)}</div>` : ''}`;
        dispatch(frame('TICKET MESERO', order, body), 'mesero');
    }

    function printKitchen(order, items) {
        if (isEnabled()) dispatch(buildKitchen(order, items), 'cocina');
    }

    function printCustomer(order, items) {
        if (isEnabled()) dispatch(buildCustomer(order, items), 'cliente');
    }

    function printPayment(order, items) {
        if (isEnabled()) dispatch(buildPayment(order, items), 'pagos');
    }

    function printTest(destination) {
        const labels = { cocina: 'COCINA', pagos: 'CAJA / PAGOS', cliente: 'CLIENTE', mesero: 'MESERO' };
        const printerKey = { cocina: 'printer_kitchen', pagos: 'printer_payment', cliente: 'printer_customer', mesero: 'printer_waiter' }[destination];
        const printerName = document.getElementById(printerInputId(destination))?.value || settings()[printerKey] || '';
        if (printerName) {
            const text = `PRUEBA DE IMPRESION\n\nDestino: ${labels[destination] || destination}\nImpresora: ${printerName}\nPapel: ${settings().print_paper_width === '58' ? '58 mm' : '80 mm'}\n\nProducto de prueba\n1x Comanda de ejemplo\n\nSi este texto sale correctamente, la impresora esta lista.\n`;
            agentRequest('/print', { method: 'POST', body: JSON.stringify({ printer: printerName, text }) })
                .then(() => window.showToast?.(`Prueba enviada a ${printerName}.`, 'success'))
                .catch(() => openPrintDocument(buildTestHtml(destination, printerName)));
            return;
        }
        const width = settings().print_paper_width === '58' ? '58mm' : '80mm';
        openPrintDocument(buildTestHtml(destination, 'Sin nombre configurado'));
    }

    function buildTestHtml(destination, printerName) {
        const labels = { cocina: 'COCINA', pagos: 'CAJA / PAGOS', cliente: 'CLIENTE', mesero: 'MESERO' };
        const width = settings().print_paper_width === '58' ? '58mm' : '80mm';
        return `<!doctype html><html><head><meta charset="utf-8"><title>Prueba ${escape(labels[destination] || destination)}</title><style>@page{size:${width} auto;margin:0}body{font-family:Arial,sans-serif;width:${width};margin:0;padding:4mm 3mm;text-align:center;color:#000}.title{font-size:18px;font-weight:800;border-bottom:2px solid #000;padding-bottom:8px}.line{font-size:12px;margin:12px 0}.sample{border:1px dashed #000;padding:10px;text-align:left;font-size:13px}small{display:block;margin-top:16px}</style></head><body><div class="title">PRUEBA DE IMPRESIÓN</div><div class="line"><b>Destino:</b> ${escape(labels[destination] || destination)}<br><b>Impresora:</b> ${escape(printerName)}<br><b>Papel:</b> ${width}</div><div class="sample"><b>Producto de prueba</b><br>1x Comanda de ejemplo<br><br>Si este texto sale correctamente, la impresora está lista.</div><small>${escape(new Date().toLocaleString('es-BO'))}</small></body></html>`;
    }

    function lineSubtotal(items) {
        return (items || []).reduce((sum, item) => sum + itemUnitPrice(item) * Number(item.qty || item.quantity || 1), 0);
    }
    function money(value) { return `Bs ${Number(value || 0).toFixed(2)}`; }
    function buildKitchen(order, items = order.items || []) {
        const body = `<div class="ticket-column-head" style="grid-template-columns:8mm 1fr"><span>CANT.</span><span>PRODUCTO</span></div>${itemLines({ items }, { showAmounts: false })}${order.kitchenNote ? `<div class="ticket-note"><b>NOTA DE PREPARACIÓN:</b><br>${escape(order.kitchenNote)}</div>` : ''}`;
        return frame('COMANDA COCINA', { ...order, items }, body, { footerText: 'Preparar según detalle', showCashier: false });
    }
    function buildCustomer(order, items = order.items || []) {
        const subtotal = lineSubtotal(items);
        const discount = items.length === (order.items || []).length ? Number(order.discountTotal || 0) : 0;
        const total = Math.max(0, subtotal - discount);
        const body = `<div class="ticket-column-head"><span>CANT.</span><span>PRODUCTO</span><span>IMPORTE</span></div>${itemLines({ items })}<section class="ticket-summary"><div class="ticket-summary-row"><span>Subtotal</span><span>${money(subtotal)}</span></div>${discount ? `<div class="ticket-summary-row"><span>Descuento</span><span>-${money(discount)}</span></div>` : ''}<div class="ticket-grand-total"><span>TOTAL</span><span>${money(total)}</span></div></section>`;
        return frame('TICKET CLIENTE', { ...order, items }, body, { footerText: 'Documento de control interno' });
    }
    function buildPayment(order, items = order.items || []) {
        const subtotal = lineSubtotal(items);
        const discount = items.length === (order.items || []).length ? Number(order.discountTotal || 0) : 0;
        const total = Math.max(0, subtotal - discount);
        const body = `<div class="ticket-column-head"><span>CANT.</span><span>PRODUCTO</span><span>IMPORTE</span></div>${itemLines({ items })}<section class="ticket-summary"><div class="ticket-summary-row"><span>Subtotal</span><span>${money(subtotal)}</span></div>${discount ? `<div class="ticket-summary-row"><span>Descuento</span><span>-${money(discount)}</span></div>` : ''}<div class="ticket-grand-total"><span>TOTAL PAGADO</span><span>${money(total)}</span></div>${paymentLines(order.paymentMethod)}</section>`;
        return frame('RECIBO DE PAGO', { ...order, items }, body, { footerText: 'Pago registrado correctamente', showCashier: true });
    }

    window.PrintJobs = { printWaiterTicket, printKitchen, printCustomer, printPayment, printTest, refreshPrinters, downloadInstaller, buildKitchen, buildCustomer, buildPayment };
})(window);
