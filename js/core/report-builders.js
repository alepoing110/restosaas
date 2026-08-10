(function (window) {
    const registry = {};

    function register(key, builder) {
        registry[key] = builder;
    }

    function build(key, input = {}) {
        if (!registry[key]) {
            throw new Error(`Reporte no registrado: ${key}`);
        }
        return registry[key](input);
    }

    function downloadCsv({ filename, headers, rows, metadataTitle }) {
        const meta = getCsvMetadata(metadataTitle || filename || 'Reporte');
        const csvRows = rows.map(row => headers.map(header => csvCell(row[header.key])).join(','));
        const csvContent = "\uFEFF" + meta + "\n" + headers.map(header => csvCell(header.title)).join(',') + "\n" + csvRows.join("\n");
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename || `reporte_${today()}.csv`;
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    function csvCell(value) {
        const text = String(value ?? '').replace(/"/g, '""');
        if (/^[=+\-@\t\r]/.test(text)) return `\t${text}`;
        return `"${text}"`;
    }

    function getCsvMetadata(title) {
        const appState = window.state || {};
        const context = window.ReportRenderer
            ? window.ReportRenderer.mergeContext(appState.reportContext || null)
            : {
                tenant: appState.tenant || {},
                branch: appState.branch || {},
                business: {
                    name: appState.business?.nombre_restaurante || 'RestoCloud'
                },
                user: appState.authUser || {},
                generated_at: nowLocal()
            };

        return [
            `Reporte,${csvCell(title)}`,
            `Negocio,${csvCell(context.business?.name || '')}`,
            `Tenant,${csvCell(context.tenant?.name || '')}`,
            `Sucursal,${csvCell(context.branch?.name || '')}`,
            `Usuario,${csvCell(context.user?.name || context.user?.email || '')}`,
            `Generado,${csvCell(new Date(context.generated_at || Date.now()).toLocaleString('es-BO'))}`,
            ''
        ].join('\n');
    }

    function today() {
        return nowLocal().slice(0, 10);
    }

    function reportDay() {
        const appState = window.state || {};
        const timestamps = [
            ...(appState.salesHistory || []).map(sale => sale.timestamp),
            ...(appState.cajaMovimientos || []).map(mov => mov.timestamp)
        ].filter(Boolean);

        if (!timestamps.length) return today();
        const validDates = timestamps
            .map(value => new Date(value))
            .filter(date => !Number.isNaN(date.getTime()))
            .sort((a, b) => a - b);
        return validDates.length ? validDates[0].toISOString().slice(0, 10) : today();
    }

    function completedSales() {
        return ((window.state && window.state.salesHistory) || []).filter(sale => sale.status === 'completado');
    }

    function differenceLabel(amount) {
        if (Math.abs(amount) < 0.01) return 'Cuadrado';
        return amount < 0 ? 'Faltante' : 'Sobrante';
    }

    function money(amount) {
        return typeof window.formatCurrency === 'function'
            ? window.formatCurrency(amount || 0)
            : `Bs ${(parseFloat(amount) || 0).toFixed(2)}`;
    }

    function time(value) {
        return typeof window.formatTime === 'function' ? window.formatTime(value) : value;
    }

    function summarizeCashDay() {
        const appState = window.state || {};
        const sales = completedSales();
        const annulledSales = (appState.salesHistory || []).filter(sale => sale.status === 'anulado');
        const movements = appState.cajaMovimientos || [];
        const apertura = movements.find(mov => mov.type === 'apertura');
        const egresos = movements.filter(mov => mov.type === 'egreso');

        const summary = {
            completedSales: sales,
            annulledSales,
            egresos,
            totalRevenue: 0,
            annulledAmount: annulledSales.reduce((sum, sale) => sum + (parseFloat(sale.total) || 0), 0),
            revenueEfectivo: 0,
            revenueQr: 0,
            revenueTarjeta: 0,
            totalExpenses: egresos.reduce((sum, mov) => sum + (parseFloat(mov.amount) || 0), 0),
            startingCash: apertura ? (parseFloat(apertura.amount) || 0) : 0,
            itemCounts: { almuerzo: 0, segundo: 0, sopa: 0, plato_extra: 0, extra: 0, otro: 0 },
            productMap: new Map()
        };

        sales.forEach(sale => {
            const saleTotal = parseFloat(sale.total) || 0;
            summary.totalRevenue += saleTotal;
            const pm = window.parsePaymentMethod ? window.parsePaymentMethod(sale.paymentMethod, saleTotal) : { efectivo: saleTotal, qr: 0, tarjeta: 0 };
            summary.revenueEfectivo += pm.efectivo;
            summary.revenueQr += pm.qr;
            summary.revenueTarjeta += pm.tarjeta;

            (sale.items || []).forEach(item => {
                const qty = Math.max(1, parseInt(item.quantity || 1, 10));
                const type = item.type || 'otro';
                summary.itemCounts[type] = (summary.itemCounts[type] || 0) + qty;

                const key = `${type}:${item.name || 'Producto'}`;
                const current = summary.productMap.get(key) || { type, name: item.name || 'Producto', qty: 0, total: 0 };
                current.qty += qty;
                current.total += (parseFloat(item.price) || 0) * qty;
                summary.productMap.set(key, current);
            });
        });

        summary.netProfit = summary.totalRevenue - summary.totalExpenses;
        summary.expectedCash = summary.startingCash + summary.revenueEfectivo - summary.totalExpenses;
        const realCashEl = document.getElementById('caja-efectivo-real');
        summary.realCash = realCashEl && realCashEl.value !== '' ? parseFloat(realCashEl.value) || 0 : summary.expectedCash;
        summary.difference = summary.realCash - summary.expectedCash;
        summary.totalPayments = summary.revenueEfectivo + summary.revenueQr + summary.revenueTarjeta;
        summary.paymentDifference = summary.totalPayments - summary.totalRevenue;
        return summary;
    }

    function describeSaleItems(items) {
        return (items || []).map(item => {
            const qty = Math.max(1, parseInt(item.quantity || 1, 10));
            const details = [];
            if (item.segundoName) details.push(item.segundoName);
            if (item.sopaName) details.push(item.sopaName);
            if (item.serviceType) details.push(item.serviceType);
            return `${qty}x ${item.name || 'Producto'}${details.length ? ` (${details.join(' / ')})` : ''}`;
        }).join(' | ');
    }

    function buildCashClosure() {
        const summary = summarizeCashDay();
        const date = reportDay();
        const diffLabel = differenceLabel(summary.difference);

        return {
            title: 'Informe de Cierre Diario',
            subtitle: 'Resumen oficial de caja con arqueo, ventas, pagos, egresos y validaciones.',
            filename: `cierre_caja_${date}.pdf`,
            filters: { fecha: date, label: 'Cierre diario actual' },
            columns: [
                { title: 'Grupo', key: 'group', align: 'left' },
                { title: 'Concepto', key: 'concept', align: 'left' },
                { title: 'Detalle', key: 'details', align: 'left' },
                { title: 'Importe', key: 'amount', align: 'right' }
            ],
            rows: [
                { group: 'Caja', concept: 'Apertura', details: 'Dinero inicial registrado', amount: money(summary.startingCash) },
                { group: 'Caja', concept: 'Egresos', details: `${summary.egresos.length} movimientos de gasto`, amount: '-' + money(summary.totalExpenses) },
                { group: 'Caja', concept: 'Efectivo esperado', details: 'Apertura + efectivo cobrado - egresos', amount: money(summary.expectedCash) },
                { group: 'Caja', concept: 'Efectivo real', details: 'Monto contado en arqueo', amount: money(summary.realCash) },
                { group: 'Caja', concept: 'Diferencia', details: diffLabel, amount: money(summary.difference) },
                { group: 'Ventas', concept: 'Ventas brutas', details: `${summary.completedSales.length} ventas cobradas`, amount: money(summary.totalRevenue) },
                { group: 'Ventas', concept: 'Ventas anuladas', details: `${summary.annulledSales.length} ventas anuladas`, amount: money(summary.annulledAmount) },
                { group: 'Ventas', concept: 'Utilidad neta', details: 'Ventas brutas menos egresos', amount: money(summary.netProfit) },
                { group: 'Pagos', concept: 'Efectivo', details: 'Recaudado en efectivo', amount: money(summary.revenueEfectivo) },
                { group: 'Pagos', concept: 'QR', details: 'Transferencias QR', amount: money(summary.revenueQr) },
                { group: 'Pagos', concept: 'Tarjeta/POS', details: 'Cobros con tarjeta/POS', amount: money(summary.revenueTarjeta) },
                { group: 'Validacion', concept: 'Pagos vs ventas', details: 'Debe quedar en Bs 0.00', amount: money(summary.paymentDifference) },
                { group: 'Consumo', concept: 'Almuerzos', details: `${summary.itemCounts.almuerzo || 0} vendidos`, amount: '-' },
                { group: 'Consumo', concept: 'Segundos', details: `${summary.itemCounts.segundo || 0} vendidos`, amount: '-' },
                { group: 'Consumo', concept: 'Sopas', details: `${summary.itemCounts.sopa || 0} vendidas`, amount: '-' },
                { group: 'Consumo', concept: 'Platos extras', details: `${summary.itemCounts.plato_extra || 0} vendidos`, amount: '-' },
                { group: 'Consumo', concept: 'Refrescos', details: `${summary.itemCounts.extra || 0} vendidos`, amount: '-' },
                ...summary.egresos.map(mov => ({
                    group: 'Detalle egresos',
                    concept: mov.description || 'Egreso',
                    details: time(mov.timestamp),
                    amount: '-' + money(mov.amount)
                }))
            ],
            totals: [
                { label: 'Ventas Brutas', value: money(summary.totalRevenue) },
                { label: 'Efectivo Real', value: money(summary.realCash) },
                { label: 'Diferencia', value: `${money(summary.difference)} (${diffLabel})` },
                { label: 'Utilidad Neta', value: money(summary.netProfit) }
            ],
            detailBlocks: [
                {
                    title: 'Arqueo de caja',
                    items: [
                        { label: 'Caja inicial', value: money(summary.startingCash) },
                        { label: 'Efectivo esperado', value: money(summary.expectedCash) },
                        { label: 'Efectivo real', value: money(summary.realCash) },
                        { label: 'Diferencia', value: `${money(summary.difference)} (${diffLabel})` }
                    ]
                },
                {
                    title: 'Distribucion de pagos',
                    items: [
                        { label: 'Efectivo', value: money(summary.revenueEfectivo) },
                        { label: 'QR', value: money(summary.revenueQr) },
                        { label: 'Tarjeta', value: money(summary.revenueTarjeta) },
                        { label: 'Pagos registrados', value: money(summary.totalPayments) }
                    ]
                },
                {
                    title: 'Operacion',
                    items: [
                        { label: 'Ventas cobradas', value: summary.completedSales.length },
                        { label: 'Ventas anuladas', value: summary.annulledSales.length },
                        { label: 'Egresos', value: summary.egresos.length },
                        { label: 'Utilidad neta', value: money(summary.netProfit) }
                    ]
                }
            ]
        };
    }

    function buildSalesHistory(input = {}) {
        const appState = window.state || {};
        const salesSource = input.filteredSales || appState.salesHistory || [];
        const sortedSales = [...salesSource].sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));
        const summary = summarizeCashDay();
        const date = reportDay();
        const hasFilter = !!input.filteredSales;

        return {
            title: 'Reporte de Ventas del Dia',
            subtitle: hasFilter
                ? `Bitacora filtrada: ${sortedSales.length} registros seleccionados.`
                : 'Bitacora completa de ventas del historial, con productos, pagos y estados.',
            filename: `reporte_ventas_${date}.pdf`,
            orientation: 'landscape',
            filters: { fecha: date, label: hasFilter ? 'Ventas filtradas' : 'Ventas del dia' },
            columns: [
                { title: 'Nro', key: 'number', align: 'center' },
                { title: 'ID', key: 'id', align: 'left' },
                { title: 'Hora', key: 'time', align: 'left' },
                { title: 'Cliente/Mesa', key: 'customer', align: 'left' },
                { title: 'Detalle de productos', key: 'items', align: 'left' },
                { title: 'Pago', key: 'payment', align: 'center' },
                { title: 'Estado', key: 'status', align: 'center' },
                { title: 'Total', key: 'total', align: 'right' }
            ],
            rows: sortedSales.map((sale, index) => {
                const pm = window.parsePaymentMethod ? window.parsePaymentMethod(sale.paymentMethod, sale.total) : { label: 'Efectivo' };
                return {
                    number: index + 1,
                    id: sale.id ? sale.id.slice(-8).toUpperCase() : '',
                    time: time(sale.timestamp),
                    customer: sale.customer,
                    items: describeSaleItems(sale.items),
                    payment: pm.label,
                    status: sale.status === 'completado' ? 'COBRADO' : 'ANULADO',
                    total: money(sale.total)
                };
            }),
            totals: [
                { label: 'Ventas cobradas', value: money(sortedSales.filter(s => s.status === 'completado').reduce((sum, s) => sum + (parseFloat(s.total) || 0), 0)) },
                { label: 'Anulado', value: money(sortedSales.filter(s => s.status === 'anulado').reduce((sum, s) => sum + (parseFloat(s.total) || 0), 0)) },
                { label: 'Registros', value: sortedSales.length },
                { label: 'Pago total', value: money(sortedSales.reduce((sum, s) => sum + (parseFloat(s.total) || 0), 0)) }
            ],
            detailBlocks: [
                {
                    title: 'Resumen de ventas',
                    items: [
                        { label: 'Total registros', value: sortedSales.length },
                        { label: 'Ventas cobradas', value: sortedSales.filter(s => s.status === 'completado').length },
                        { label: 'Ventas anuladas', value: sortedSales.filter(s => s.status === 'anulado').length },
                        { label: 'Total cobrado', value: money(sortedSales.filter(s => s.status === 'completado').reduce((sum, s) => sum + (parseFloat(s.total) || 0), 0)) }
                    ]
                }
            ]
        };
    }

    function buildDrinksOrder(input = {}) {
        const data = input.rows || window.currentDrinksReportData || [];
        const date = today();
        const rows = data.map(row => ({
            name: row.name,
            available: row.available,
            sold: row.sold,
            base: row.base,
            suggested: row.suggested
        }));

        return {
            title: 'Pedido de Refrescos',
            subtitle: 'Sugerencias de compra y reposicion.',
            filename: `pedido_bebidas_${date}.pdf`,
            filters: { fecha: date, label: 'Pedido de bebidas' },
            columns: [
                { title: 'Bebida', key: 'name', align: 'left' },
                { title: 'Disponible', key: 'available', align: 'center' },
                { title: 'Vendido', key: 'sold', align: 'center' },
                { title: 'Stock base', key: 'base', align: 'center' },
                { title: 'Sugerido', key: 'suggested', align: 'right' }
            ],
            rows,
            totals: [
                { label: 'Bebidas listadas', value: rows.length },
                { label: 'Total sugerido', value: rows.reduce((sum, row) => sum + (parseInt(row.suggested, 10) || 0), 0) + ' uds' }
            ]
        };
    }

    function buildHistoricalClosure(input = {}) {
        const closure = input.closure;
        if (!closure) throw new Error('Cierre historico no encontrado.');
        const diffText = Math.abs(closure.diferencia) < 0.01
            ? 'Bs 0.00 (CUADRADO)'
            : `${closure.diferencia > 0 ? '+' : ''}Bs ${closure.diferencia.toFixed(2)} ${closure.diferencia > 0 ? '(SOBRANTE)' : '(FALTANTE)'}`;

        return {
            title: 'Copia de Cierre de Caja Diario',
            subtitle: 'Arqueo historico archivado en el sistema.',
            filters: { fecha: closure.fecha, label: 'Cierre historico' },
            columns: [
                { title: 'Concepto', key: 'concept', align: 'left' },
                { title: 'Detalle', key: 'detail', align: 'left' },
                { title: 'Importe', key: 'amount', align: 'right' }
            ],
            rows: [
                { concept: 'Caja inicial', detail: 'Monto de apertura del dia', amount: money(closure.caja_inicial) },
                { concept: 'Ingresos efectivo', detail: 'Ventas cobradas en efectivo', amount: money(closure.ingresos_efectivo) },
                { concept: 'Egresos', detail: 'Gastos registrados', amount: '-' + money(closure.egresos) },
                { concept: 'Efectivo esperado', detail: 'Caja inicial + efectivo - egresos', amount: money(closure.efectivo_esperado) },
                { concept: 'Efectivo real', detail: 'Conteo fisico declarado', amount: money(closure.efectivo_real) },
                { concept: 'Diferencia', detail: diffText, amount: money(closure.diferencia) },
                { concept: 'Utilidad neta', detail: 'Ingresos menos egresos', amount: money(closure.utilidad_neta) }
            ],
            totals: [
                { label: 'Diferencia', value: diffText },
                { label: 'Utilidad neta', value: money(closure.utilidad_neta) }
            ],
            detailBlocks: [
                {
                    title: 'Resumen del cierre',
                    items: [
                        { label: 'Fecha cierre', value: closure.fecha },
                        { label: 'Caja inicial', value: money(closure.caja_inicial) },
                        { label: 'Ventas efectivo', value: money(closure.ingresos_efectivo) },
                        { label: 'Utilidad neta', value: money(closure.utilidad_neta) }
                    ]
                }
            ]
        };
    }

    function buildTopSellers(input = {}) {
        const summary = summarizeCashDay();
        const date = reportDay();
        const filterType = input.type || null;
        let productStats = Array.from(summary.productMap.values());

        if (filterType) {
            productStats = productStats.filter(stat => stat.type === filterType);
        }

        productStats.sort((a, b) => b.qty - a.qty || b.total - a.total);

        const typeLabels = {
            'almuerzo': 'Almuerzo Completo',
            'segundo': 'Segundo del Dia',
            'sopa': 'Sopa Extra',
            'plato_extra': 'Plato Especial a la Carta',
            'extra': 'Gaseosa / Bebida',
            'otro': 'Otro Producto'
        };

        const filterLabel = filterType ? (typeLabels[filterType] || filterType) : null;
        const title = filterLabel
            ? `Ranking de ${filterLabel}s Mas Vendidos`
            : 'Ranking de Productos Mas Vendidos';
        const subtitle = filterLabel
            ? `Demandas de ${filterLabel.toLowerCase()} para el periodo seleccionado.`
            : 'Informe ejecutivo de demanda de platos, gaseosas y volumen de venta en hoja Carta.';

        return {
            title,
            subtitle,
            filename: `ranking_${filterType || 'todos'}_${date}.pdf`,
            filters: { fecha: date, label: filterLabel ? `Ranking de ${filterLabel}` : 'Ranking diario de demanda' },
            columns: [
                { title: '#', key: 'rank', align: 'center' },
                { title: 'Producto / Plato', key: 'name', align: 'left' },
                ...(filterType ? [] : [{ title: 'Categoria', key: 'type', align: 'left' }]),
                { title: 'Unidades Vendidas', key: 'qty', align: 'center' },
                { title: 'Ingresos (Bs)', key: 'total', align: 'right' }
            ],
            rows: productStats.map((stat, idx) => ({
                rank: `#${idx + 1}`,
                name: stat.name,
                ...(filterType ? {} : { type: typeLabels[stat.type] || stat.type }),
                qty: `${stat.qty} u.`,
                total: money(stat.total)
            })),
            totals: [
                { label: filterLabel ? `${filterLabel}s distintos` : 'Productos Distintos', value: productStats.length },
                { label: 'Unidades Vendidas', value: productStats.reduce((sum, s) => sum + s.qty, 0) },
                { label: 'Recaudacion Total', value: money(productStats.reduce((sum, s) => sum + s.total, 0)) }
            ],
            detailBlocks: [
                {
                    title: 'Desglose por tipo de consumo',
                    items: [
                        { label: 'Almuerzos', value: `${summary.itemCounts.almuerzo || 0} u.` },
                        { label: 'Segundos', value: `${summary.itemCounts.segundo || 0} u.` },
                        { label: 'Sopas', value: `${summary.itemCounts.sopa || 0} u.` },
                        { label: 'Refrescos / Extras', value: `${(summary.itemCounts.extra || 0) + (summary.itemCounts.plato_extra || 0)} u.` }
                    ]
                }
            ]
        };
    }

    function buildSalesByWeekday() {
        const sales = completedSales();
        const weekdayNames = ['Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];
        const buckets = weekdayNames.map((name, idx) => ({ name, idx, qty: 0, total: 0, dates: new Set() }));

        sales.forEach(sale => {
            if (!sale.timestamp) return;
            const d = new Date(sale.timestamp);
            if (isNaN(d.getTime())) return;
            const bucket = buckets[d.getDay()];
            bucket.qty += 1;
            bucket.total += parseFloat(sale.total) || 0;
            bucket.dates.add(sale.timestamp.slice(0, 10));
        });

        const maxBucket = buckets.reduce((max, b) => b.total > max.total ? b : max, buckets[0]);
        const totalAll = buckets.reduce((sum, b) => sum + b.total, 0);
        const ticketPromedio = b => b.qty ? b.total / b.qty : 0;

        const orderedBuckets = buckets.slice(1).concat(buckets.slice(0, 1));

        const periodLabel = state.reportContext?.start_date && state.reportContext?.end_date
            ? `${state.reportContext.start_date} al ${state.reportContext.end_date}`
            : (state.reportContext?.label || 'periodo seleccionado');

        return {
            title: 'Ventas por Dia de la Semana',
            subtitle: `Distribucion de ventas segun el dia, para ${periodLabel}.`,
            filename: `ventas_por_dia_semana.pdf`,
            filters: { label: periodLabel },
            columns: [
                { title: 'Dia', key: 'name', align: 'left' },
                { title: 'Pedidos', key: 'qty', align: 'center' },
                { title: 'Fechas en el periodo', key: 'dates', align: 'center' },
                { title: 'Ticket Promedio', key: 'avg', align: 'right' },
                { title: 'Total Vendido', key: 'total', align: 'right' },
                { title: 'Participacion', key: 'percent', align: 'center' }
            ],
            rows: orderedBuckets.map(b => {
                const dateCount = b.dates.size;
                const isMax = b.idx === maxBucket.idx && b.total > 0;
                const percent = totalAll > 0 ? Math.round((b.total / totalAll) * 100) : 0;
                return {
                    name: b.name + (isMax ? ' (pico)' : ''),
                    qty: b.qty,
                    dates: `${dateCount} fecha${dateCount !== 1 ? 's' : ''}`,
                    avg: money(ticketPromedio(b)),
                    total: money(b.total),
                    percent: `${percent}%`
                };
            }),
            totals: [
                { label: 'Dia de mayor venta', value: `${maxBucket.name} (${money(maxBucket.total)})` },
                { label: 'Total del periodo', value: money(totalAll) },
                { label: 'Total pedidos', value: sales.length }
            ],
            detailBlocks: [
                {
                    title: 'Resumen por dia',
                    items: orderedBuckets.map(b => ({
                        label: b.name,
                        value: `${b.qty} pedidos | ${money(b.total)}`
                    }))
                }
            ]
        };
    }

    register('cashClosure', buildCashClosure);
    register('salesHistory', buildSalesHistory);
    register('drinksOrder', buildDrinksOrder);
    register('historicalClosure', buildHistoricalClosure);
    register('topSellers', buildTopSellers);
    register('salesByWeekday', buildSalesByWeekday);

    window.ReportBuilders = {
        register,
        build,
        downloadCsv,
        summarizeCashDay,
        describeSaleItems,
        keys: () => Object.keys(registry)
    };
})(window);
