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
    const printQueue = [];
    let processingPrintQueue = false;
    let printWindow = null;
    let printSequence = 0;

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

    function isBeverage(item) {
        return ['extra', 'refresco', 'gaseosa', 'bebida'].includes(String(item?.type || '').toLowerCase());
    }

    function isWaiterItem(item) {
        return item?.serviceType === 'servirse' || isBeverage(item) || ['salsa', 'acompanamiento'].includes(String(item?.type || '').toLowerCase());
    }

    function isCustomerItem(item) {
        return item?.serviceType === 'llevar';
    }

    function itemLines(order, { showAmounts = true, showService = true } = {}) {
        return (order.items || []).map(item => {
            const qty = Number(item.qty || item.quantity || 1);
            const salsas = (item.salsas || []).map(salsa => {
                const name = salsa.name || salsa.salsaName;
                if (!name) return '';
                return `${name} (${salsa.salsaMode === 'aparte' ? 'Aparte' : 'Bañar'})`;
            }).filter(Boolean).join(', ');
            const accompaniments = (item.accompaniments || []).map(item => item.name || item.accompanimentName).filter(Boolean).join(', ');
            const details = [item.sopaName, item.segundoName].filter(Boolean).join(' / ');
            const service = isBeverage(item) ? '' : item.serviceType === 'llevar' ? 'Llevar' : item.serviceType === 'servirse' ? 'Servirse' : '';
            const options = [showService ? service : '', details, salsas ? `Salsas: ${salsas}` : '', accompaniments ? `Acompañamientos: ${accompaniments}` : '', item.notes ? `Nota: ${item.notes}` : ''].filter(Boolean);
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
        const customer = String(order.customer || '').replace(/^(?:Para Llevar|Delivery)\s*-\s*/i, '').trim();
        if (order.deliveryType === 'mesa') return String(order.customer || 'Mesa sin nombre').replace(/\s+-\s+/, ' / ');
        if (order.deliveryType === 'llevar') return `Para llevar${customer ? ` / ${customer}` : ''}`;
        if (order.deliveryType === 'delivery') return `Delivery${customer ? ` / ${customer}` : ''}`;
        return customer || 'Cliente';
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
        return new Promise((resolve, reject) => {
            try {
                if (!printWindow || printWindow.closed) {
                    printWindow = window.open('', 'restocloud-print', 'width=420,height=700');
                }
                if (!printWindow) throw new Error('El navegador bloqueó la ventana de impresión. Permite ventanas emergentes.');
                let settled = false;
                let printMediaQuery = null;
                const onPrintMediaChange = event => {
                    if (!event.matches) finish();
                };
                const finish = () => {
                    if (settled) return;
                    settled = true;
                    if (printMediaQuery?.removeEventListener) printMediaQuery.removeEventListener('change', onPrintMediaChange);
                    resolve();
                };
                printWindow.document.open();
                printWindow.document.write(html);
                printWindow.document.close();
                printWindow.onafterprint = finish;
                printMediaQuery = printWindow.matchMedia?.('print') || null;
                printMediaQuery?.addEventListener?.('change', onPrintMediaChange);
                printWindow.focus();
                window.setTimeout(() => {
                    try {
                        printWindow.focus();
                        printWindow.print();
                    } catch (error) {
                        reject(error);
                    }
                }, 120);
            } catch (error) {
                reject(error);
            }
        });
    }

    async function processPrintQueue() {
        if (processingPrintQueue) return;
        processingPrintQueue = true;
        try {
            while (printQueue.length) {
                const job = printQueue.shift();
                try {
                    if (job.printer) {
                        await agentRequest('/print-raw', { method: 'POST', body: JSON.stringify({ printer: job.printer, data: job.data }) });
                    } else {
                        await openPrintDocument(job.html);
                    }
                } catch (error) {
                    if (job.printer && job.html) {
                        try {
                            await openPrintDocument(job.html);
                        } catch (fallbackError) {
                            console.error('[PRINT] No se pudo imprimir el ticket:', fallbackError);
                            window.showToast?.(`No se pudo imprimir ${job.label}.`, 'error');
                        }
                    } else {
                        console.error('[PRINT] No se pudo imprimir el ticket:', error);
                        window.showToast?.(`No se pudo imprimir ${job.label}.`, 'error');
                    }
                }
            }
        } finally {
            if (printWindow && !printWindow.closed) {
                try { printWindow.close(); } catch (_) { /* ventana controlada por el navegador */ }
            }
            printWindow = null;
            processingPrintQueue = false;
        }
    }

    // Thermal ticket template: no business header/footer, no internal padding, black ink only.
    function thermalFrame(title, order, body) {
        const paperWidth = settings().print_paper_width === '58' ? '58mm' : '80mm';
        const branchName = order.branchName || state.branch?.name || 'Sucursal';
        const createdBy = order.createdByName || order.created_by_name || state.authUser?.name || 'Usuario no disponible';
        const paidBy = order.paidByName || order.paid_by_name || createdBy;
        const cashier = title === 'RECIBO DE PAGO' ? `<br><b>Cobrado por:</b> ${escape(paidBy)}` : '';
        const bottomMargin = title === 'TICKET CLIENTE' ? '5mm' : '0';
        const bodyFontSize = title === 'TICKET CLIENTE' ? 10 : 11;
        const titleFontSize = title === 'TICKET CLIENTE' ? 13 : 14;
        const metaFontSize = title === 'TICKET CLIENTE' ? 9 : 10;
        const headerFontSize = title === 'TICKET CLIENTE' ? 8 : 9;
        return `<!doctype html><html><head><meta charset="utf-8"><title>${escape(title)}</title><style>@page{size:${paperWidth} auto;margin:0 1cm ${bottomMargin} 0}*{box-sizing:border-box}html,body{margin:0;padding:0}body{font-family:Arial,sans-serif;width:calc(${paperWidth} - 1cm);padding:0;color:#000;background:#fff;font-size:${bodyFontSize}px;line-height:1.3}.ticket-kind{text-align:center;font-size:${titleFontSize}px;font-weight:800;padding:0 0 2mm;margin:0 0 2mm;border-bottom:1px solid #000}.ticket-rule{border-top:1px dashed #000;margin:2mm 0}.ticket-meta{font-size:${metaFontSize}px;line-height:1.4}.ticket-column-head{display:grid;grid-template-columns:8mm 1fr 18mm;gap:1mm;font-size:${headerFontSize}px;font-weight:800;padding-bottom:1mm;border-bottom:1px solid #000}.ticket-column-head span:last-child{text-align:right}.ticket-item{padding:2mm 0;border-bottom:1px dashed #000;page-break-inside:avoid}.ticket-item-main{display:grid;grid-template-columns:8mm 1fr 18mm;gap:1mm;align-items:start}.ticket-item-main--kitchen{grid-template-columns:8mm 1fr}.ticket-item-qty,.ticket-item-name,.ticket-item-total{font-weight:800}.ticket-item-total{text-align:right;font-size:${metaFontSize}px}.ticket-item-option{margin:1mm 0 0 8mm;font-size:${headerFontSize}px}.ticket-summary{margin-top:3mm;width:100%}.ticket-summary-row{display:flex;justify-content:space-between;gap:3mm;padding:.5mm 0}.ticket-summary-row span:last-child{text-align:right}.ticket-grand-total{display:flex;justify-content:space-between;gap:3mm;border-top:2px solid #000;border-bottom:2px solid #000;margin-top:1mm;padding:1.5mm 0;font-size:${titleFontSize}px;font-weight:800}.ticket-note{margin-top:3mm;padding:2mm;border:1px dashed #000;font-size:${headerFontSize}px}body{print-color-adjust:exact}</style></head><body><div class="ticket-kind">${escape(title)}</div><div class="ticket-meta"><b>Sucursal:</b> ${escape(branchName)}<br><b>Pedido:</b> ${displayOrderNumber(order)}<br><b>Mesa / entrega:</b> ${escape(orderReference(order))}<br><b>Fecha:</b> ${escape(new Date(order.timestamp || Date.now()).toLocaleString('es-BO'))}<br><b>Registrado por:</b> ${escape(createdBy)}${cashier}${order.pickupTime ? `<br><b>Hora de recojo:</b> ${escape(order.pickupTime)}` : ''}</div><div class="ticket-rule"></div>${body}</body></html>`;
    }

    const TICKET_PROFILES = Object.freeze({
        kitchen: { title: 'COMANDA COCINA', amounts: false, cashier: false },
        customer: { title: 'TICKET CLIENTE', amounts: true, cashier: false },
        payment: { title: 'RECIBO DE PAGO', amounts: true, cashier: true },
        waiter: { title: 'TICKET MESERO', amounts: false, cashier: false }
    });

    function escText(value) {
        return String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[\r\n]+/g, ' ').trim();
    }

    function escWrap(value, width) {
        const text = escText(value);
        if (!text) return [''];
        const words = text.split(/\s+/);
        const lines = [];
        let line = '';
        words.forEach(word => {
            if (!line) line = word;
            else if ((line + ' ' + word).length <= width) line += ' ' + word;
            else { lines.push(line); line = word; }
        });
        if (line) lines.push(line);
        return lines;
    }

    function escPosTicket(profileName, order, items) {
        const profile = TICKET_PROFILES[profileName];
        const width = settings().print_paper_width === '58' ? 32 : 48;
        const lines = [];
        const title = escText(profile.title);
        const centeredTitle = title.padStart(Math.floor((width + title.length) / 2), ' ');
        const push = value => lines.push(...escWrap(value, width));
        const separator = () => lines.push('-'.repeat(width));
        const qtyWidth = 5;
        const amountWidth = profile.amounts ? 10 : 0;
        const productWidth = width - qtyWidth - amountWidth - (profile.amounts ? 1 : 0);
        const itemList = items || order.items || [];
        const branchName = order.branchName || state.branch?.name || 'Sucursal';
        const createdBy = order.createdByName || order.created_by_name || state.authUser?.name || 'Usuario no disponible';
        const paidBy = order.paidByName || order.paid_by_name || createdBy;
        // Center with spaces because some generic ESC/POS printers ignore ESC a alignment.
        // Commands remain together to avoid feeding blank lines before the title.
        lines.push(`\x1b@${profileName === 'customer' ? '\x1bM\x01' : ''}\x1ba\x00\x1bE\x01${centeredTitle}\x1bE\x00`);
        separator();
        push(`Sucursal: ${branchName}`);
        push(`Pedido: ${displayOrderNumber(order).replace(/^#/, '')}`);
        push(`Mesa / entrega: ${orderReference(order)}`);
        push(`Fecha: ${new Date(order.timestamp || Date.now()).toLocaleString('es-BO')}`);
        push(`Registrado por: ${createdBy}`);
        if (profile.cashier) push(`Cobrado por: ${paidBy}`);
        if (order.pickupTime) push(`Hora de recojo: ${order.pickupTime}`);
        separator();
        const productHeader = profileName === 'kitchen'
            ? 'PRODUCTO'.padStart(Math.floor((productWidth + 'PRODUCTO'.length) / 2)).padEnd(productWidth, ' ')
            : 'PRODUCTO'.padEnd(productWidth, ' ');
        const header = profile.amounts
            ? 'CANT.'.padEnd(qtyWidth, ' ') + productHeader + 'IMPORTE'.padStart(amountWidth, ' ')
            : 'CANT.'.padEnd(qtyWidth, ' ') + productHeader;
        lines.push(header);
        separator();
        itemList.forEach(item => {
            const qty = Number(item.qty || item.quantity || 1);
            const name = escText(item.name || 'Producto');
            const amount = profile.amounts ? money(itemUnitPrice(item) * qty) : '';
            const nameLines = escWrap(name, productWidth);
            const quantityLabel = `${qty}x`;
            lines.push(profile.amounts ? quantityLabel.padEnd(qtyWidth, ' ') + nameLines[0].padEnd(productWidth, ' ') + amount.padStart(amountWidth, ' ') : quantityLabel.padEnd(qtyWidth, ' ') + nameLines[0]);
            nameLines.slice(1).forEach(line => lines.push(' '.repeat(qtyWidth) + line));
            const details = [
                profileName === 'customer' || isBeverage(item) ? '' : item.serviceType === 'llevar' ? 'Llevar' : item.serviceType === 'servirse' ? 'Servirse' : '',
                [item.sopaName, item.segundoName].filter(Boolean).join(' / '),
                (item.salsas || []).map(salsa => {
                    const name = salsa.salsaName || salsa.name;
                    if (!name) return '';
                    return `${name} (${salsa.salsaMode === 'aparte' ? 'Aparte' : 'Bañar'})`;
                }).filter(Boolean).join(', '),
                (item.accompaniments || []).map(item => item.accompanimentName || item.name).filter(Boolean).join(', '),
                item.notes ? `Nota: ${item.notes}` : ''
            ].filter(Boolean);
            details.forEach(detail => escWrap(detail, width - 2).forEach(line => lines.push(`  ${line}`)));
            separator();
        });
        if (profileName === 'kitchen' && order.kitchenNote) push(`Nota: ${order.kitchenNote}`);
        if (profileName === 'waiter' && order.waiterNote) push(`Nota general: ${order.waiterNote}`);
        if (profile.amounts) {
            const subtotal = lineSubtotal(itemList);
            const discount = itemList.length === (order.items || []).length ? Number(order.discountTotal || 0) : 0;
            lines.push(`Subtotal`.padEnd(width - money(subtotal).length, ' ') + money(subtotal));
            if (discount) lines.push(`Descuento`.padEnd(width - money(discount).length - 1, ' ') + `-${money(discount)}`);
            const total = money(Math.max(0, subtotal - discount));
            // Formatting commands stay on the same physical line as the total.
            lines.push(`\x1bE\x01${`TOTAL`.padEnd(width - total.length, ' ') + total}\x1bE\x00`);
            if (profileName === 'payment') {
                if (typeof order.paymentMethod === 'object') Object.entries(order.paymentMethod).filter(([, value]) => Number(value) > 0).forEach(([method, value]) => push(`${paymentLabel(method)}: ${money(value)}`));
                else push(`Forma de pago: ${paymentLabel(order.paymentMethod)}`);
            }
        }
        // This printer needs five lines after the last text before the cutter reaches it.
        // The feed is emitted only at the end of the current ticket.
        // No feed is emitted before the next ticket begins.
        return `${lines.join('\n')}\n\x1bd\x04\x1dV\x01`;
    }

    function dispatch(html, destination) {
        const printer = document.getElementById(printerInputId(destination))?.value || settings()[{ cocina: 'printer_kitchen', pagos: 'printer_payment', cliente: 'printer_customer', mesero: 'printer_waiter' }[destination]] || '';
        if (!printer) return openPrintDocument(html);
        const printableHtml = html.match(/<body[^>]*>([\s\S]*?)<\/body>/i)?.[1] || html;
        const text = printableHtml
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

    function dispatchTicket(profile, order, items, destination, html) {
        const printer = document.getElementById(printerInputId(destination))?.value || settings()[{ cocina: 'printer_kitchen', pagos: 'printer_payment', cliente: 'printer_customer', mesero: 'printer_waiter' }[destination]] || '';
        const raw = escPosTicket(profile, order, items);
        const data = btoa(unescape(encodeURIComponent(raw)));
        printQueue.push({
            id: `print_${Date.now()}_${++printSequence}`,
            label: TICKET_PROFILES[profile]?.title || profile,
            printer,
            data,
            html
        });
        processPrintQueue();
    }

    function printWaiterTicket(order, items = null) {
        if (!isEnabled()) return window.showToast?.('La impresión está desactivada en Configuración.', 'warning');
        if (settings().waiter_ticket_enabled === '0' || settings().waiter_ticket_enabled === false) return window.showToast?.('Activa el ticket para mesero en Configuración > Impresión.', 'warning');
        const sourceItems = Array.isArray(items) && items.length ? items : (order.items || []);
        const ticketItems = sourceItems.filter(isWaiterItem);
        if (!ticketItems.length) return window.showToast?.('No hay productos agregados para imprimir al mesero.', 'info');
        const body = `<div class="print-note"><b>Atender esta mesa:</b> ${escape(order.customer || 'Mesa sin nombre')}</div>${itemLines({ items: ticketItems }, { showAmounts: false })}${order.waiterNote ? `<div class="print-note"><b>Nota general:</b> ${escape(order.waiterNote)}</div>` : ''}`;
        dispatchTicket('waiter', { ...order, items: ticketItems }, ticketItems, 'mesero', thermalFrame('TICKET MESERO', { ...order, items: ticketItems }, body));
    }

    function getLatestAdditionItems(order) {
        const additionalItems = (order?.items || []).filter(item => item.isAdditional === true || Number(item.isAdditional) === 1);
        if (!additionalItems.length) return [];
        const latest = additionalItems[additionalItems.length - 1];
        if (latest.additionBatchId) return additionalItems.filter(item => item.additionBatchId === latest.additionBatchId);
        if (latest.addedAt) return additionalItems.filter(item => item.addedAt === latest.addedAt);
        return [latest];
    }

    function printKitchen(order, items) {
        if (isEnabled()) dispatchTicket('kitchen', order, items, 'cocina', buildKitchen(order, items));
    }

    function printCustomer(order, items) {
        if (isEnabled()) dispatchTicket('customer', order, items, 'cliente', buildCustomer(order, items));
    }

    function printPayment(order, items) {
        if (isEnabled()) dispatchTicket('payment', order, items, 'pagos', buildPayment(order, items));
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
        const body = `<div class="ticket-column-head ticket-column-head--kitchen" style="grid-template-columns:8mm 1fr"><span>CANT.</span><span style="text-align:center">PRODUCTO</span></div>${itemLines({ items }, { showAmounts: false })}${order.kitchenNote ? `<div class="ticket-note"><b>NOTA DE PREPARACIÓN:</b><br>${escape(order.kitchenNote)}</div>` : ''}`;
        return thermalFrame('COMANDA COCINA', { ...order, items }, body);
    }
    function buildCustomer(order, items = order.items || []) {
        const subtotal = lineSubtotal(items);
        const discount = items.length === (order.items || []).length ? Number(order.discountTotal || 0) : 0;
        const total = Math.max(0, subtotal - discount);
        const body = `<div class="ticket-column-head"><span>CANT.</span><span>PRODUCTO</span><span>IMPORTE</span></div>${itemLines({ items }, { showService: false })}<section class="ticket-summary"><div class="ticket-summary-row"><span>Subtotal</span><span>${money(subtotal)}</span></div>${discount ? `<div class="ticket-summary-row"><span>Descuento</span><span>-${money(discount)}</span></div>` : ''}<div class="ticket-grand-total"><span>TOTAL</span><span>${money(total)}</span></div></section>`;
        return thermalFrame('TICKET CLIENTE', { ...order, items }, body);
    }
    function buildPayment(order, items = order.items || []) {
        const subtotal = lineSubtotal(items);
        const discount = items.length === (order.items || []).length ? Number(order.discountTotal || 0) : 0;
        const total = Math.max(0, subtotal - discount);
        const body = `<div class="ticket-column-head"><span>CANT.</span><span>PRODUCTO</span><span>IMPORTE</span></div>${itemLines({ items })}<section class="ticket-summary"><div class="ticket-summary-row"><span>Subtotal</span><span>${money(subtotal)}</span></div>${discount ? `<div class="ticket-summary-row"><span>Descuento</span><span>-${money(discount)}</span></div>` : ''}<div class="ticket-grand-total"><span>TOTAL PAGADO</span><span>${money(total)}</span></div>${paymentLines(order.paymentMethod)}</section>`;
        return thermalFrame('RECIBO DE PAGO', { ...order, items }, body);
    }

    window.PrintJobs = { printWaiterTicket, isWaiterItem, isCustomerItem, getLatestAdditionItems, printKitchen, printCustomer, printPayment, printTest, refreshPrinters, downloadInstaller, buildKitchen, buildCustomer, buildPayment };
})(window);
