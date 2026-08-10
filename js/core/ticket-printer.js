// ==========================================================================
// RESTOCLOUD TICKET PRINTER CORE MODULE (TicketPrinter)
// Modular helper for generating & printing receipts & kitchen comandas
// ==========================================================================

(function (window) {
    'use strict';

    function parseOrderItems(order) {
        if (!order) return [];
        let items = order.items;
        if (typeof items === 'string') {
            try { items = JSON.parse(items); } catch (e) { items = []; }
        }
        return Array.isArray(items) ? items : [];
    }

    function getFormattedTicketDateTime(order) {
        let raw = order ? (order.timestamp || order.created_at || order.date || order.created_time) : null;
        let d = raw ? new Date(raw) : new Date();
        if (isNaN(d.getTime())) d = new Date();

        const tz = (typeof getBusinessTimezone === 'function') ? getBusinessTimezone() : 'America/La_Paz';

        const dateStr = d.toLocaleDateString('es-BO', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            timeZone: tz
        });

        const timeStr = d.toLocaleTimeString('es-BO', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: true,
            timeZone: tz
        });

        return { dateStr, timeStr };
    }

    /**
     * Build Client Payment Receipt HTML (Recibo de Pago del Cliente)
     */
    function buildClientReceiptHtml(order) {
        if (!order) return '<div style="padding:15px; color:red;">No hay datos de orden.</div>';

        const items = parseOrderItems(order);
        let clientItemsRows = '';

        items.forEach(item => {
            const qty = Math.max(1, parseInt(item.qty || item.quantity || 1, 10));
            const price = parseFloat(item.price || 0);
            const salsaTotal = (item.salsas || []).reduce((acc, s) => acc + (s.salsaPrice || 0), 0);
            const lineTotal = (price + salsaTotal) * qty;
            let desc = item.name || 'Producto';
            if (item.type === 'almuerzo') {
                desc += item.sopaName ? ` - ${item.sopaName}` : '';
                desc += item.segundoName ? ` / ${item.segundoName}` : '';
            } else if (item.type === 'segundo') {
                desc += item.segundoName ? ` - ${item.segundoName}` : '';
            } else if (item.type === 'sopa') {
                desc += item.sopaName ? ` - ${item.sopaName}` : '';
            }
            const itemNote = item.detail || item.notes || item.comment || '';
            const noteHtml = itemNote ? `<div style="font-size:10px; color:#ef4444; font-weight:600; margin-top:1px;">↳ Nota: ${escapeHtml(itemNote)}</div>` : '';

            let salsasHtml = '';
            const salsas = item.salsas || [];
            if (salsas.length > 0) {
                const salsaLines = salsas.map(s => {
                    const modeLabel = s.salsaMode === 'banar' ? 'Bañar' : 'Aparte';
                    const priceLabel = s.salsaPrice > 0 ? ` +${s.salsaPrice.toFixed(2)}` : '';
                    return `${escapeHtml(s.salsaName)} (${modeLabel})${priceLabel}`;
                }).join(', ');
                salsasHtml = `<div style="font-size:10px; color:#92400e; font-weight:600; margin-top:1px;">🥗 ${salsaLines}</div>`;
            }
            
            clientItemsRows += `
                <tr>
                    <td class="ticket-qty-col">${qty}</td>
                    <td class="ticket-desc-col">
                        ${escapeHtml(desc)} (${item.serviceType === 'llevar' ? 'Llevar' : 'Mesa'})
                        ${noteHtml}
                        ${salsasHtml}
                    </td>
                    <td class="ticket-total-col">${lineTotal.toFixed(2)}</td>
                </tr>
            `;
        });

        const titleHeader = 'TICKET DE VENTA';
        const pm = parsePaymentMethod(order.paymentMethod, order.total);
        const pMethod = pm.label.toUpperCase();
        const biz = (window.state && window.state.business) || {};
        const bizName = (biz.nombre_restaurante || 'RESTAURANTE').toUpperCase();
        const bizDireccion = biz.direccion || '';
        const bizTelefono = biz.telefono || '';
        const { dateStr, timeStr } = getFormattedTicketDateTime(order);

        const notes = order.notes || order.observations || order.comment || '';
        const pickupTime = order.reservationTime || order.reservation_time || order.pickupTime || '';

        const notesBlockHtml = (notes || pickupTime) ? `
            <div class="ticket-divider"></div>
            <div style="font-size:10.5px; padding: 4px 0;">
                ${pickupTime ? `<div style="font-weight:700; color:#1e40af;">⏰ HORA DE RECOJO / ATENCIÓN: ${escapeHtml(pickupTime)}</div>` : ''}
                ${notes ? `<div style="font-weight:700; color:#dc2626; margin-top:2px;">📌 OBSERVACIONES: ${escapeHtml(notes)}</div>` : ''}
            </div>
        ` : '';

        return `
            <div class="ticket-client-copy">
                <div class="ticket-header">
                    <div class="ticket-logo">${escapeHtml(bizName)}</div>
                    <div style="font-size:11px;">${escapeHtml(bizDireccion)}</div>
                    <div style="font-size:10px;">${escapeHtml(bizTelefono)}</div>
                    <div class="ticket-divider"></div>
                    <div style="font-weight:700; font-size:11.5px; margin-top:4px;">${titleHeader}</div>
                </div>
                <div class="ticket-meta">
                    <div class="ticket-meta-row">
                        <span><strong>Fecha:</strong> ${dateStr}</span>
                        <span><strong>Hora:</strong> ${timeStr}</span>
                    </div>
                    <div class="ticket-meta-row" style="margin-top:2px;">
                        <span>Cliente/Ubicación: <strong>${escapeHtml(order.customer)}</strong></span>
                        <span>ID: ${(order.id || '').slice(-6).toUpperCase()}</span>
                    </div>
                </div>
                ${notesBlockHtml}
                <div class="ticket-divider"></div>
                <table class="ticket-table">
                    <thead>
                        <tr>
                            <th class="ticket-qty-col">Cant</th>
                            <th class="ticket-desc-col">Descripción</th>
                            <th class="ticket-total-col">Subt. (Bs)</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${clientItemsRows}
                    </tbody>
                </table>
                <div class="ticket-divider"></div>
                <div class="ticket-totals-section">
                    <div class="ticket-total-row">
                        <span>Pago:</span>
                        <span><strong>${pMethod}</strong></span>
                    </div>
                    ${pm.efectivo > 0 && pm.qr > 0 ? `
                    <div class="ticket-total-row" style="font-size:10px; color:#666;">
                        <span>  ↳ Efectivo:</span>
                        <span>Bs ${pm.efectivo.toFixed(2)}</span>
                    </div>
                    <div class="ticket-total-row" style="font-size:10px; color:#666;">
                        <span>  ↳ QR:</span>
                        <span>Bs ${pm.qr.toFixed(2)}</span>
                    </div>
                    ` : ''}
                    ${pm.efectivo > 0 && pm.tarjeta > 0 ? `
                    <div class="ticket-total-row" style="font-size:10px; color:#666;">
                        <span>  ↳ Efectivo:</span>
                        <span>Bs ${pm.efectivo.toFixed(2)}</span>
                    </div>
                    <div class="ticket-total-row" style="font-size:10px; color:#666;">
                        <span>  ↳ Tarjeta:</span>
                        <span>Bs ${pm.tarjeta.toFixed(2)}</span>
                    </div>
                    ` : ''}
                    <div class="ticket-total-row">
                        <span>Subtotal:</span>
                        <span>Bs ${parseFloat(order.total || 0).toFixed(2)}</span>
                    </div>
                    <div class="ticket-total-row grand-total">
                        <span>Total General:</span>
                        <span>Bs ${parseFloat(order.total || 0).toFixed(2)}</span>
                    </div>
                </div>
                <div class="ticket-footer">
                    <div>¡Gracias por su visita y preferencia!</div>
                    <div style="font-size:9px; margin-top:4px; color:#555;">${escapeHtml(bizName)} - Impreso de Sistema POS</div>
                </div>
            </div>
        `;
    }

    /**
     * Build Kitchen Comanda HTML (Comanda de Preparación - Excludes drinks)
     */
    function buildKitchenComandaHtml(order) {
        if (!order) return '<div style="padding:15px; color:red;">No hay datos de orden.</div>';

        const items = parseOrderItems(order);
        let kitchenItemsRows = '';
        let kitchenFoodCount = 0;

        items.forEach(item => {
            const isDrink = item.type === 'extra' || item.type === 'bebida' || item.type === 'gaseosa';
            if (isDrink) return; // Exclude drinks from kitchen comanda

            kitchenFoodCount++;
            const qty = Math.max(1, parseInt(item.qty || item.quantity || 1, 10));
            let desc = item.name || 'Producto';
            if (item.type === 'almuerzo') {
                desc += item.segundoName ? ` / ${item.segundoName}` : '';
                desc += item.sopaName ? ` (Sopa: ${item.sopaName})` : '';
            } else if (item.type === 'segundo') {
                desc += item.segundoName ? ` - ${item.segundoName}` : '';
            } else if (item.type === 'sopa') {
                desc += item.sopaName ? ` - ${item.sopaName}` : '';
            }
            const itemNote = item.detail || item.notes || item.comment || '';
            const noteHtml = itemNote ? `<div style="font-size:10px; color:#ef4444; font-weight:700; margin-top:1px;">↳ PREPARACIÓN: ${escapeHtml(itemNote)}</div>` : '';

            let salsasHtml = '';
            const salsas = item.salsas || [];
            if (salsas.length > 0) {
                const salsaLines = salsas.map(s => {
                    const modeLabel = s.salsaMode === 'banar' ? 'Bañar' : 'Aparte';
                    return `<span style="background:#fff3cd; padding:1px 4px; border-radius:2px; font-size:10px;">🥗 ${escapeHtml(s.salsaName)} (${modeLabel})</span>`;
                }).join(' ');
                salsasHtml = `<div style="font-size:10px; color:#92400e; font-weight:700; margin-top:2px;">SALSAS: ${salsaLines}</div>`;
            }

            kitchenItemsRows += `
                <tr>
                    <td class="ticket-qty-col">${qty}</td>
                    <td class="ticket-desc-col">
                        ${escapeHtml(desc)} (${item.serviceType === 'llevar' ? 'Llevar' : 'Mesa'})
                        ${noteHtml}
                        ${salsasHtml}
                    </td>
                    <td class="ticket-total-col">- - -</td>
                </tr>
            `;
        });

        if (kitchenFoodCount === 0) {
            kitchenItemsRows = `<tr><td colspan="3" style="text-align:center; padding:10px; font-style:italic; color:#666;">Solo Bebidas / Gaseosas (No requiere cocina)</td></tr>`;
        }

        const biz = (window.state && window.state.business) || {};
        const bizName = (biz.nombre_restaurante || 'RESTAURANTE').toUpperCase();
        const { dateStr, timeStr } = getFormattedTicketDateTime(order);

        const notes = order.notes || order.observations || order.comment || '';
        const pickupTime = order.reservationTime || order.reservation_time || order.pickupTime || '';

        const notesBlockHtml = (notes || pickupTime) ? `
            <div class="ticket-divider"></div>
            <div style="font-size:11px; padding: 4px 0; background:#fff3cd; border:1px solid #ffeeba; border-radius:3px; margin:4px 0;">
                ${pickupTime ? `<div style="font-weight:800; color:#000;">⏰ HORA DE RECOJO / ATENCIÓN: ${escapeHtml(pickupTime)}</div>` : ''}
                ${notes ? `<div style="font-weight:800; color:#b91c1c; margin-top:2px;">📌 OBSERVACIONES: ${escapeHtml(notes)}</div>` : ''}
            </div>
        ` : '';

        const paidHtml = order.paid ? `<div style="font-weight:900; font-size:14px; color:#16a34a; background:#dcfce7; border:2px solid #16a34a; padding:4px 8px; border-radius:4px; margin:6px 0; text-align:center;">💰 PAGADO</div>` : '';

        return `
            <div class="ticket-kitchen-copy">
                <div class="ticket-header">
                    <div class="ticket-logo" style="font-size:16px;">${escapeHtml(bizName)} (COCINA)</div>
                    <div class="ticket-divider"></div>
                    <div style="font-weight:700; font-size:12px; margin-top:4px; letter-spacing:1px;">*** COMANDA DE PREPARACIÓN ***</div>
                    ${paidHtml}
                </div>
                <div class="ticket-meta">
                    <div class="ticket-meta-row">
                        <span><strong>Fecha:</strong> ${dateStr}</span>
                        <span><strong>Hora:</strong> ${timeStr}</span>
                    </div>
                    <div class="ticket-meta-row" style="margin-top:2px;">
                        <span>UBICACIÓN: <strong style="font-size: 14px; background:#000; color:#fff; padding: 0 4px;">${escapeHtml(order.customer)}</strong></span>
                        <span>ID: ${(order.id || '').slice(-6).toUpperCase()}</span>
                    </div>
                </div>
                ${notesBlockHtml}
                <div class="ticket-divider"></div>
                <table class="ticket-table">
                    <thead>
                        <tr>
                            <th class="ticket-qty-col">Cant</th>
                            <th class="ticket-desc-col">Descripción (Plato / Preparación)</th>
                            <th class="ticket-total-col">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${kitchenItemsRows}
                    </tbody>
                </table>
                <div class="ticket-divider"></div>
                <div class="ticket-footer" style="margin-top:10px;">
                    <div style="font-weight:bold; font-size:11px;">*** LLEVAR A COCINA ***</div>
                </div>
            </div>
        `;
    }

    /**
     * Build Combined Ticket (Client Receipt + Scissors Cut + Kitchen Comanda)
     */
    function buildFullTicketHtml(order) {
        return `
            ${buildClientReceiptHtml(order)}
            <!-- SCISSORS DIVIDER -->
            <div class="ticket-scissors"></div>
            ${buildKitchenComandaHtml(order)}
        `;
    }

    /**
     * Resolve Ticket Mode automatically
     */
    function resolveTicketMode(order) {
        if (!order) return 'client';
        const isPending = order.status === 'pendiente';
        const isMesa = order.deliveryType === 'mesa' || (order.customer && order.customer.toLowerCase().includes('mesa'));

        if (!isPending) return 'client';          // Completed payment / Cobro / Past sale / Paid reservation
        if (isMesa) return 'kitchen';             // Comanda Mesa -> Only kitchen comanda
        return 'full';                            // Pending Takeout / Delivery / Custom
    }

    /**
     * Generate Ticket HTML by mode ('kitchen' | 'client' | 'full' | 'auto')
     */
    function generateTicketHtml(order, mode = 'auto') {
        const targetMode = mode === 'auto' ? resolveTicketMode(order) : mode;
        if (targetMode === 'kitchen') return buildKitchenComandaHtml(order);
        if (targetMode === 'client') return buildClientReceiptHtml(order);
        return buildFullTicketHtml(order);
    }

    // Public API
    window.TicketPrinter = {
        buildClientReceipt: buildClientReceiptHtml,
        buildKitchenComanda: buildKitchenComandaHtml,
        buildFullTicket: buildFullTicketHtml,
        resolveTicketMode: resolveTicketMode,
        generateHtml: generateTicketHtml,
        
        /**
         * Open preview modal for kitchen comanda
         */
        printKitchen(order) {
            if (typeof window.openTicketModal === 'function') {
                window.openTicketModal(order, 'kitchen');
            }
        },

        /**
         * Open preview modal for client payment receipt (Cobro / Venta / Reserva)
         */
        printReceipt(order) {
            if (typeof window.openTicketModal === 'function') {
                window.openTicketModal(order, 'client');
            }
        },

        /**
         * Open preview modal with full combined ticket
         */
        printFull(order) {
            if (typeof window.openTicketModal === 'function') {
                window.openTicketModal(order, 'full');
            }
        }
    };

})(window);
