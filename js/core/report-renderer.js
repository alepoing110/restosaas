(function (window) {
    function esc(value) {
        return typeof window.escapeHtml === 'function' ? window.escapeHtml(value) : String(value ?? '');
    }

    function getFallbackContext(extraFilters = {}) {
        const appState = window.state || {};
        const business = appState.business || {};
        const tenant = appState.tenant || {};
        const branch = appState.branch || {};
        const authUser = appState.authUser || {};

        return {
            generated_at: new Date().toISOString(),
            tenant: { id: tenant.id || '', slug: tenant.slug || '', name: tenant.name || business.nombre_restaurante || 'RestoCloud' },
            branch: { id: branch.id || '', name: branch.name || 'Principal' },
            business: {
                name: business.nombre_restaurante || tenant.name || 'RestoCloud',
                address: business.direccion || '',
                phone: business.telefono || ''
            },
            user: {
                id: authUser.id || '',
                name: authUser.name || '',
                email: authUser.email || '',
                role: authUser.role || ''
            },
            filters: extraFilters
        };
    }

    function mergeContext(context, filters) {
        const base = getFallbackContext(filters);
        const incoming = context || (window.state && window.state.reportContext) || {};
        return {
            ...base,
            ...incoming,
            tenant: { ...base.tenant, ...(incoming.tenant || {}) },
            branch: { ...base.branch, ...(incoming.branch || {}) },
            business: { ...base.business, ...(incoming.business || {}) },
            user: { ...base.user, ...(incoming.user || {}) },
            filters: { ...base.filters, ...(incoming.filters || {}), ...(filters || {}) }
        };
    }

    function formatDateTime(value) {
        const date = value ? new Date(value) : new Date();
        return date.toLocaleString('es-BO', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function renderFilterLine(filters) {
        const pairs = Object.entries(filters || {})
            .filter(([key, value]) => value !== '' && value != null && key !== 'label')
            .map(([key, value]) => `${key.replace(/_/g, ' ')}: ${value}`);
        return pairs.length ? pairs.join(' | ') : 'Sin filtros adicionales';
    }

    function getInitials(value) {
        const words = String(value || 'RestoCloud')
            .replace(/[^a-zA-Z0-9\s]/g, ' ')
            .trim()
            .split(/\s+/)
            .filter(Boolean);
        if (!words.length) return 'RC';
        return words.slice(0, 2).map(word => word.charAt(0).toUpperCase()).join('');
    }

    function buildHtml(options) {
        const context = mergeContext(options.context, options.filters);
        const columns = options.columns || [];
        const rows = options.rows || [];
        const title = options.title || 'Reporte';
        const subtitle = options.subtitle || context.filters.label || '';
        const generatedAt = formatDateTime(context.generated_at);
        const businessName = context.business.name || context.tenant.name || 'RestoCloud';
        const reportCode = (options.reportCode || context.filters.label || 'OPERACION')
            .toString()
            .replace(/\s+/g, '-')
            .toUpperCase();

        const tableRows = rows.length
            ? rows.map((row, index) => `
                <tr>
                    ${columns.map(col => `<td class="rc-report-${col.align || 'left'}">${esc(row[col.key] ?? '')}</td>`).join('')}
                </tr>
            `).join('')
            : `<tr><td colspan="${columns.length || 1}" class="rc-report-empty">Sin datos para mostrar.</td></tr>`;

        const totals = options.totals || [];
        const totalsHtml = totals.length ? `
            <section class="rc-report-totals">
                ${totals.map(total => `
                    <div class="rc-report-total">
                        <span>${esc(total.label)}</span>
                        <strong>${total.value}</strong>
                    </div>
                `).join('')}
            </section>
        ` : '';

        const detailBlocks = (options.detailBlocks || []).map(block => `
            <section class="rc-report-section">
                <h3>${esc(block.title)}</h3>
                <div class="rc-report-detail-grid">
                    ${(block.items || []).map(item => `
                        <div>
                            <span>${esc(item.label)}</span>
                            <strong>${esc(item.value)}</strong>
                        </div>
                    `).join('')}
                </div>
            </section>
        `).join('');

        const logoHtml = context.business.logo || context.business.logo_url
            ? `<img src="${esc(context.business.logo || context.business.logo_url)}" alt="Logo" class="rc-report-logo-img">`
            : `<div class="rc-report-logo">${esc(getInitials(businessName))}</div>`;

        const signaturesHtml = options.showSignatures !== false ? `
            <section class="rc-report-signatures">
                <div class="rc-signature-box">
                    <div class="rc-signature-line"></div>
                    <p class="rc-signature-title">Firma Cajero / Operador</p>
                    <p class="rc-signature-subtitle">Aclaración: ${esc(context.user.name || context.user.email || 'Responsable de Caja')}</p>
                </div>
                <div class="rc-signature-box">
                    <div class="rc-signature-line"></div>
                    <p class="rc-signature-title">Firma Administrador / Gerente</p>
                    <p class="rc-signature-subtitle">V°B° Arqueo y Conformidad de Cierre</p>
                </div>
            </section>
        ` : '';

        return `
            <article class="rc-letter-report">
                <header class="rc-report-header">
                    <div class="rc-report-brand">
                        ${logoHtml}
                        <div class="rc-report-company">
                            <p class="rc-report-kicker">RestoCloud POS — Reporte Ejecutivo</p>
                            <h1>${esc(businessName)}</h1>
                            <p>${esc(context.business.address || 'Direccion no registrada')}</p>
                            <p>${esc(context.business.phone || 'Telefono no registrado')}</p>
                        </div>
                    </div>
                    <div class="rc-report-doc-meta">
                        <span class="rc-report-doc-label">Documento Oficial Carta</span>
                        <strong>${esc(reportCode)}</strong>
                        <span>Tenant: ${esc(context.tenant.name)}</span>
                        <span>Sucursal: ${esc(context.branch.name)}</span>
                        <span>Generado: ${esc(generatedAt)}</span>
                    </div>
                </header>

                <section class="rc-report-title">
                    <div>
                        <h2>${esc(title)}</h2>
                        ${subtitle ? `<p>${esc(subtitle)}</p>` : ''}
                    </div>
                    <aside>
                        <span>Filtros / Contexto</span>
                        <strong>${esc(renderFilterLine(context.filters))}</strong>
                    </aside>
                </section>

                ${detailBlocks}
                ${totalsHtml}

                <section class="rc-report-section">
                    <table class="rc-report-table">
                        <thead>
                            <tr>${columns.map(col => `<th class="rc-report-${col.align || 'left'}">${esc(col.title)}</th>`).join('')}</tr>
                        </thead>
                        <tbody>${tableRows}</tbody>
                    </table>
                </section>

                ${signaturesHtml}

                <footer class="rc-report-footer">
                    <span>RestoCloud POS | Usuario: ${esc(context.user.name || context.user.email || 'Sistema')}</span>
                    <span>${esc(context.branch.name)} | Tamaño Carta (8.5" x 11") | ${esc(title)}</span>
                </footer>
            </article>
        `;
    }

    function ensureContainer(html) {
        const container = document.createElement('div');
        container.className = 'rc-report-export-container';
        container.innerHTML = `<style>${getReportCss()}</style>` + html;
        container.style.position = 'absolute';
        container.style.left = '0';
        container.style.top = '0';
        container.style.width = '199.9mm';
        container.style.background = '#ffffff';
        container.style.color = '#1f2937';
        container.style.zIndex = '-9999';
        container.style.pointerEvents = 'none';
        container.style.opacity = '1';
        container.style.boxSizing = 'border-box';
        return container;
    }

    async function pdf(options) {
        if (typeof window.html2pdf === 'undefined') {
            if (typeof window.showToast === 'function') window.showToast('No se encontro la libreria para PDF.', 'error');
            return;
        }

        const previewContainer = document.getElementById('report-preview-document-container');
        const modalPreview = document.getElementById('modal-report-preview');
        let targetEl;
        let isTemp = false;

        if (modalPreview && modalPreview.classList.contains('open') && previewContainer && previewContainer.children.length > 0) {
            targetEl = previewContainer;
        } else {
            const html = buildHtml(options);
            targetEl = document.createElement('div');
            targetEl.className = 'rc-report-export-container';
            targetEl.innerHTML = `<style>${getReportCss()}</style>` + html;
            targetEl.style.position = 'fixed';
            targetEl.style.left = '0';
            targetEl.style.top = '0';
            targetEl.style.width = '215.9mm';
            targetEl.style.background = '#ffffff';
            targetEl.style.color = '#1f2937';
            targetEl.style.zIndex = '99999';
            targetEl.style.pointerEvents = 'none';
            targetEl.style.opacity = '1';
            document.body.appendChild(targetEl);
            isTemp = true;
        }

        const filename = options.filename || `reporte_${new Date().toISOString().slice(0, 10)}.pdf`;

        try {
            await new Promise(resolve => setTimeout(resolve, 150));
            await window.html2pdf().from(targetEl).set({
                margin: [8, 8, 8, 8],
                filename,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    backgroundColor: '#ffffff',
                    scrollX: 0,
                    scrollY: 0,
                    logging: false
                },
                jsPDF: { unit: 'mm', format: 'letter', orientation: options.orientation || 'portrait' },
                pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', '.rc-report-total', '.rc-report-signatures'] }
            }).save();
        } catch (err) {
            console.error('[ReportRenderer] PDF generation failed:', err);
            if (typeof window.showToast === 'function') window.showToast('Error al generar el PDF. Intenta de nuevo.', 'error');
        } finally {
            if (isTemp && targetEl) targetEl.remove();
        }
    }

    function print(options) {
        const html = buildHtml(options);
        const printArea = document.getElementById('report-print-area');
        if (!printArea) {
            if (typeof window.showToast === 'function') window.showToast('No se encontro el area de impresion.', 'error');
            return;
        }
        const pageOrientation = options.orientation === 'landscape' ? 'landscape' : 'portrait';
        const styleId = 'rc-dynamic-report-print-style';
        const previousHtml = printArea.innerHTML;
        let printStyle = document.getElementById(styleId);

        if (!printStyle) {
            printStyle = document.createElement('style');
            printStyle.id = styleId;
            document.head.appendChild(printStyle);
        }

        printStyle.textContent = `
            ${getReportCss()}
            @media print {
                @page { size: letter ${pageOrientation}; margin: 8mm; }
                body.rc-report-printing style,
                body.rc-report-printing script {
                    display: none !important;
                }
                body.rc-report-printing * { visibility: hidden !important; }
                body.rc-report-printing #report-print-area,
                body.rc-report-printing #report-print-area * {
                    visibility: visible !important;
                }
                body.rc-report-printing #report-print-area style,
                body.rc-report-printing #report-print-area script {
                    display: none !important;
                }
                body.rc-report-printing #report-print-area .rc-report-header,
                body.rc-report-printing #report-print-area .rc-report-title,
                body.rc-report-printing #report-print-area .rc-report-brand,
                body.rc-report-printing #report-print-area .rc-report-footer {
                    display: flex !important;
                }
                body.rc-report-printing #report-print-area .rc-report-detail-grid,
                body.rc-report-printing #report-print-area .rc-report-totals,
                body.rc-report-printing #report-print-area .rc-report-signatures {
                    display: grid !important;
                }
                body.rc-report-printing #report-print-area .rc-report-table {
                    display: table !important;
                }
                body.rc-report-printing #report-print-area tr {
                    display: table-row !important;
                }
                body.rc-report-printing #report-print-area th,
                body.rc-report-printing #report-print-area td {
                    display: table-cell !important;
                }
                body.rc-report-printing #report-print-area {
                    display: block !important;
                    position: absolute !important;
                    left: 0 !important;
                    top: 0 !important;
                    width: 100% !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    background: #ffffff !important;
                    color: #1f2937 !important;
                }
                body.rc-report-printing .modal-backdrop,
                body.rc-report-printing .sidebar,
                body.rc-report-printing .main-header,
                body.rc-report-printing .screen-container,
                body.rc-report-printing .toast-container {
                    display: none !important;
                }
            }
        `;

        printArea.className = 'print-only letter-print-area';
        printArea.innerHTML = html;
        document.body.classList.remove('rc-ticket-printing');
        document.body.classList.add('rc-report-printing');

        const cleanup = () => {
            document.body.classList.remove('rc-report-printing');
            printArea.innerHTML = '';
            window.removeEventListener('afterprint', cleanup);
        };

        window.addEventListener('afterprint', cleanup);
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                try {
                    window.print();
                } catch (err) {
                    console.error('[ReportRenderer] Print failed:', err);
                    cleanup();
                    return;
                }
                setTimeout(() => {
                    if (document.body.classList.contains('rc-report-printing')) cleanup();
                }, 60000);
            });
        });
    }

    function getReportCss() {
        return `
            .rc-letter-report {
                background: #ffffff;
                color: #1f2937;
                font-family: Arial, Helvetica, sans-serif;
                box-sizing: border-box;
                width: 100%;
                margin: 0;
                padding: 0;
            }
            .rc-report-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 16px;
                padding-bottom: 10px;
                border-bottom: 3px solid #1f2937;
                position: relative;
            }
            .rc-report-header:before {
                content: "";
                position: absolute;
                left: 0;
                right: 0;
                bottom: -5px;
                height: 2px;
                background: #ef4444;
            }
            .rc-report-brand {
                display: flex;
                align-items: center;
                gap: 12px;
                min-width: 0;
            }
            .rc-report-logo {
                width: 46px;
                height: 46px;
                border-radius: 8px;
                background: #1f2937;
                color: #ffffff;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
                font-weight: 900;
                letter-spacing: 0.04em;
                flex: 0 0 auto;
            }
            .rc-report-company { min-width: 0; }
            .rc-report-kicker {
                margin: 0 0 2px;
                color: #ef4444;
                font-size: 8.5px;
                font-weight: 800;
                letter-spacing: 0.1em;
                text-transform: uppercase;
            }
            .rc-report-company h1 {
                margin: 0 0 2px;
                color: #111827;
                font-size: 18px;
                line-height: 1.1;
            }
            .rc-report-company p, .rc-report-doc-meta span {
                margin: 1px 0;
                display: block;
                color: #4b5563;
                font-size: 9.5px;
            }
            .rc-report-doc-meta {
                background: #f9fafb;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                padding: 6px 10px;
                text-align: right;
                font-size: 9.5px;
                min-width: 170px;
                flex: 0 0 auto;
            }
            .rc-report-doc-meta .rc-report-doc-label {
                color: #ef4444;
                font-size: 8px;
                font-weight: 800;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }
            .rc-report-doc-meta strong {
                display: block;
                margin: 2px 0 3px;
                color: #111827;
                font-size: 11.5px;
            }
            .rc-report-title {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 16px;
                padding: 12px 0 8px;
            }
            .rc-report-title h2 {
                margin: 0;
                color: #111827;
                font-size: 15px;
                letter-spacing: 0.02em;
                text-transform: uppercase;
            }
            .rc-report-title p {
                margin: 3px 0 0;
                color: #374151;
                font-size: 10px;
            }
            .rc-report-title aside {
                background: #f3f4f6;
                border-left: 3px solid #ef4444;
                padding: 6px 10px;
                color: #374151;
                font-size: 9px;
                min-width: 170px;
                flex: 0 0 auto;
            }
            .rc-report-title aside span {
                display: block;
                color: #6b7280;
                font-size: 7.5px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                margin-bottom: 2px;
            }
            .rc-report-title aside strong {
                display: block;
                color: #1f2937;
                font-size: 9px;
                line-height: 1.3;
            }
            .rc-report-section {
                margin-top: 10px;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .rc-report-section h3 {
                margin: 0 0 6px;
                color: #111827;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.03em;
            }
            .rc-report-detail-grid, .rc-report-totals {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 8px;
                margin-bottom: 10px;
            }
            .rc-report-detail-grid div, .rc-report-total {
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                padding: 6px 8px;
                background: #f9fafb;
                box-sizing: border-box;
            }
            .rc-report-detail-grid span, .rc-report-total span {
                display: block;
                color: #6b7280;
                font-size: 8px;
                font-weight: 700;
                text-transform: uppercase;
            }
            .rc-report-detail-grid strong, .rc-report-total strong {
                display: block;
                margin-top: 2px;
                color: #111827;
                font-size: 11.5px;
            }
            .rc-report-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 9px;
                margin-bottom: 8px;
                box-sizing: border-box;
            }
            .rc-report-table th {
                padding: 5px 6px;
                color: #111827;
                background: #f3f4f6;
                border: 1px solid #e5e7eb;
                font-size: 8px;
                text-transform: uppercase;
                font-weight: 700;
            }
            .rc-report-table td {
                padding: 4px 6px;
                border: 1px solid #edf0f3;
                color: #1f2937;
                vertical-align: middle;
            }
            .rc-report-table tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .rc-report-right { text-align: right; }
            .rc-report-center { text-align: center; }
            .rc-report-left { text-align: left; }
            .rc-report-empty { text-align: center; color: #6b7280; padding: 10px; }
            .rc-report-logo-img { max-width: 48px; max-height: 48px; border-radius: 6px; object-fit: contain; flex: 0 0 auto; }
            .rc-report-signatures {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 32px;
                margin-top: 22px;
                margin-bottom: 8px;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .rc-signature-box { text-align: center; }
            .rc-signature-line {
                border-top: 1.5px dashed #4b5563;
                margin-bottom: 4px;
                width: 65%;
                margin-left: auto;
                margin-right: auto;
            }
            .rc-signature-title { margin: 0; font-size: 9px; font-weight: 700; color: #111827; }
            .rc-signature-subtitle { margin: 2px 0 0; font-size: 8px; color: #6b7280; }
            .rc-report-footer {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                margin-top: 10px;
                padding-top: 6px;
                border-top: 1px solid #d1d5db;
                color: #6b7280;
                font-size: 8px;
            }
        `;
    }

    let currentActiveReportDefinition = null;

    function preview(options) {
        currentActiveReportDefinition = options;
        const modal = document.getElementById('modal-report-preview');
        const container = document.getElementById('report-preview-document-container');
        const titleEl = document.getElementById('modal-report-preview-title');
        
        if (!modal || !container) {
            print(options);
            return;
        }

        if (titleEl) {
            titleEl.innerHTML = `<i class="fa-solid fa-file-invoice"></i> Previsualización: ${esc(options.title || 'Reporte')}`;
        }

        container.classList.toggle('landscape', options.orientation === 'landscape');
        const html = `<style>${getReportCss()}</style>` + buildHtml(options);
        container.innerHTML = html;

        if (typeof window.openModal === 'function') {
            window.openModal('modal-report-preview');
        }
    }

    function getActiveDefinition() {
        return currentActiveReportDefinition;
    }

    window.ReportRenderer = { buildHtml, pdf, print, preview, getActiveDefinition, mergeContext };
})(window);
