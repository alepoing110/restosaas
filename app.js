// ==========================================================================
// APP INIT — Event Listeners + DOMContentLoaded (last file loaded)
// ==========================================================================

async function replayOfflineOrders() {
    if (!window.OfflineQueue || !window.AppApi) return;
    try {
        const count = await OfflineQueue.count();
        if (count === 0) return;
        showToast(`Reintentando ${count} pedido(s) offline...`, 'info');
        const replayed = await OfflineQueue.replayAll(
            (order) => { console.log('[OFFLINE] Replayed:', order.id); },
            (order, err) => { console.warn('[OFFLINE] Failed:', order.id, err.message); }
        );
        if (replayed > 0) {
            showToast(`${replayed} pedido(s) offline enviado(s).`, 'success');
            if (typeof window.loadStateForTab === 'function') {
                await loadStateForTab('active-orders');
            }
        }
    } catch (e) {
        console.error('[OFFLINE] Replay error:', e);
    }
}

function toggleLoginPassword() {
    const input = document.getElementById('auth-login-password');
    const icon = document.getElementById('auth-login-eye');
    if (!input || !icon) return;
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-solid fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-solid fa-eye';
    }
}

let tabNavInitialized = false;
let appStarted = false;
let appUiInitialized = false;

function initTabNavigation() {
    if (tabNavInitialized) return;
    tabNavInitialized = true;

    document.querySelectorAll('.nav-menu .nav-item').forEach(button => {
        button.addEventListener('click', () => {
            const tabId = button.getAttribute('data-tab');
            const defaultSubtab = button.getAttribute('data-default-subtab');
            switchTab(tabId).then(() => {
                if (defaultSubtab && tabId === 'menu-config' && typeof window.switchMenuConfigSubtab === 'function') {
                    window.switchMenuConfigSubtab(defaultSubtab);
                }
            });
        });
    });

    // Category Tabs in POS (Meals / Extras / Drinks)
    const safeBind = (id, event, handler) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener(event, handler);
    };

    safeBind('btn-cat-meals', 'click', () => {
        if (window.PosController) PosController.setCategory('meals');
    });

    safeBind('btn-cat-extras', 'click', () => {
        if (window.PosController) PosController.setCategory('extras');
    });

    safeBind('btn-cat-drinks', 'click', () => {
        if (window.PosController) PosController.setCategory('drinks');
    });

    safeBind('btn-cat-sauces', 'click', () => {
        if (window.PosController) PosController.setCategory('sauces');
    });

    // POS Table selection listener
    safeBind('order-table-select', 'change', (e) => {
        const customWrapper = document.getElementById('custom-customer-name-wrapper');
        if (customWrapper) customWrapper.style.display = '';
        if (e.target.value === 'Personalizado') {
            const nameTarget = document.getElementById('order-customer-name');
            if (nameTarget) nameTarget.focus();
        }
    });

    // Tables Config Modal
    safeBind('btn-open-tables-config', 'click', () => {
        if (window.openTablesConfigModal) window.openTablesConfigModal();
    });
    safeBind('btn-close-tables-config', 'click', () => {
        if (window.closeTablesConfigModal) window.closeTablesConfigModal();
    });
    safeBind('btn-close-tables-config-done', 'click', () => {
        if (window.closeTablesConfigModal) window.closeTablesConfigModal();
    });
    safeBind('modal-tables-config', 'click', (e) => {
        if (e.target.id === 'modal-tables-config') {
            if (window.closeTablesConfigModal) window.closeTablesConfigModal();
        }
    });
    safeBind('form-table-config', 'submit', (e) => {
        if (window.handleSaveTableConfig) window.handleSaveTableConfig(e);
    });
    safeBind('btn-cancel-table-edit', 'click', () => {
        if (window.cancelTableEdit) window.cancelTableEdit();
    });
    safeBind('table-icon-selector', 'click', (e) => {
        const btn = e.target.closest('.icon-option');
        if (btn && btn.dataset.icon) {
            if (window.handleTableIconSelect) window.handleTableIconSelect(btn.dataset.icon);
        }
    });

    // Export CSV click
    safeBind('btn-export-csv', 'click', exportSalesToCSV);
}

function initModalBackdropHandlers() {
    // Generic backdrop click: close any open modal when clicking outside content
    document.addEventListener('click', (e) => {
        const backdrop = e.target.closest('.modal-backdrop');
        if (!backdrop) return;
        if (e.target !== backdrop) return;
        const id = backdrop.id;
        if (id === 'modal-report-preview') { closeModal('modal-report-preview'); return; }
        if (id === 'modal-tables-config') { closeTablesConfigModal(); return; }
        if (id === 'modal-menu-products') { closeMenuProductsModal(); return; }
        if (id === 'modal-drinks-report') { closeDrinksReportModal(); return; }
        if (id === 'modal-dashboard-detail') { closeDashboardDetail(); return; }
        if (id === 'modal-reservation-payment') { closeReservationPaymentModal(); return; }
        if (id === 'modal-append-items') { closeAppendItemsModal(); return; }
        if (typeof window.closeModal === 'function') { closeModal(id); return; }
    });

    // Escape key: close topmost open modal
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const openModals = document.querySelectorAll('.modal-backdrop.open');
        if (openModals.length === 0) return;
        const topModal = openModals[openModals.length - 1];
        const id = topModal.id;
        if (id === 'modal-report-preview') { closeModal('modal-report-preview'); return; }
        if (id === 'modal-tables-config') { closeTablesConfigModal(); return; }
        if (id === 'modal-menu-products') { closeMenuProductsModal(); return; }
        if (id === 'modal-drinks-report') { closeDrinksReportModal(); return; }
        if (id === 'modal-dashboard-detail') { closeDashboardDetail(); return; }
        if (id === 'modal-reservation-payment') { closeReservationPaymentModal(); return; }
        if (id === 'modal-append-items') { closeAppendItemsModal(); return; }
        if (typeof window.closeModal === 'function') { closeModal(id); return; }
    });
}

function initGlobalSubtabDelegation() {
    document.addEventListener('click', (e) => {
        // 1. Menu Config Subnav (.menu-config-subnav .subnav-btn)
        const menuSubbtn = e.target.closest('.menu-config-subnav .subnav-btn');
        if (menuSubbtn) {
            const subtab = menuSubbtn.dataset.subtab || menuSubbtn.getAttribute('data-subtab');
            if (subtab && typeof window.switchMenuConfigSubtab === 'function') {
                window.switchMenuConfigSubtab(subtab);
            }
            return;
        }

        // 2. Reports Subnav (.reports-subnav .reports-subtab)
        const reportSubbtn = e.target.closest('.reports-subnav .reports-subtab');
        if (reportSubbtn) {
            const rTab = reportSubbtn.dataset.reportTab || reportSubbtn.getAttribute('data-report-tab');
            if (rTab && typeof window.switchReportsSubtab === 'function') {
                window.switchReportsSubtab(rTab);
            }
            return;
        }

        // 3. Reservation Catalog Subtabs (.reservation-catalog-tab)
        const resCatBtn = e.target.closest('.reservation-catalog-tab');
        if (resCatBtn) {
            const cTab = resCatBtn.dataset.resCatalogTab || resCatBtn.getAttribute('data-res-catalog-tab');
            if (cTab && typeof window.switchReservationCatalogTab === 'function') {
                window.switchReservationCatalogTab(cTab);
            }
            return;
        }

        // 4. Reservation Filter Subtabs (.reservation-subtab)
        const resSubbtn = e.target.closest('.reservation-subtab');
        if (resSubbtn) {
            const filter = resSubbtn.id === 'res-subtab-entregadas' ? 'entregadas' : 'activas';
            if (typeof window.filterReservations === 'function') {
                window.filterReservations(filter);
            }
            return;
        }

        // 5. SaaS Admin Subtabs (.saas-subtab)
        const saasSubbtn = e.target.closest('.saas-subtab');
        if (saasSubbtn) {
            const sTab = saasSubbtn.dataset.saasTab || saasSubbtn.getAttribute('data-saas-tab');
            if (sTab && typeof window.switchSaasSubtab === 'function') {
                window.switchSaasSubtab(sTab);
            }
            return;
        }
    });
}

async function startAuthenticatedApp(forceReload = false) {
    const stateLoaded = await loadStateFromServer();
    if (!stateLoaded) {
        return;
    }

    if (!appUiInitialized) {
        appStarted = true;
        appUiInitialized = true;

        const options = { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
        const todayStr = new Date().toLocaleDateString('es-ES', options);
        const dateEl = document.getElementById('current-date-display');
        if (dateEl) dateEl.textContent = todayStr.charAt(0).toUpperCase() + todayStr.slice(1);

        initTabNavigation();
        initGlobalSubtabDelegation();
        initModalBackdropHandlers();
        initThemeToggle();
        initSoundToggle();
        initKeyboardShortcuts();

        if (window.populateTableSelect) populateTableSelect();
        if (window.DashboardController) DashboardController.init();

        if (window.PosController) {
            window.PosController.init();
            window.PosController.relocatePosFlowControls?.();
        }

        const safeBind = (id, event, handler) => {
            const el = document.getElementById(id);
            if (el) el.addEventListener(event, handler);
        };

        safeBind('btn-clear-cart', 'click', () => {
            if (window.PosController) PosController.clearCart();
        });
        safeBind('search-active-orders', 'input', debounce(renderActiveOrders, 200));

        const reservationSearch = document.getElementById('reservation-search-input');
        if (reservationSearch) {
            reservationSearch.addEventListener('input', debounce(function() {
                searchReservations(this.value);
            }, 200));
        }

safeBind('form-business-info', 'submit', handleSaveBusinessInfo);
safeBind('form-print-settings', 'submit', handleSavePrintSettings);

        const posMenuSelect = document.getElementById('pos-active-menu-select');
        if (posMenuSelect) {
            populateMenuSelector();
            posMenuSelect.addEventListener('change', (e) => {
                const selectedMenuId = e.target.value;
                if (window.AppStore) {
                    window.AppStore.set({ activeMenuId: selectedMenuId });
                } else {
                    state.activeMenuId = selectedMenuId;
                }
                localStorage.setItem('restocloud_active_menu', selectedMenuId);
                showToast(`Menu cambiado a: ${posMenuSelect.options[posMenuSelect.selectedIndex].text}`, 'info');
                if (typeof window.renderCurrentTab === 'function') {
                    window.renderCurrentTab('pos');
                }
            });
        }

        const drinkForm = document.querySelector('#screen-menu-config #form-add-extra');
        if (drinkForm) {
            drinkForm.addEventListener('submit', handleAddExtra);
        }

        const btnExport = document.getElementById('btn-export-drinks-report');
        if (btnExport) btnExport.addEventListener('click', openDrinksReportModal);

        const btnPrint = document.getElementById('btn-print-drinks-report');
        if (btnPrint) btnPrint.addEventListener('click', printDrinksReport);

        const btnDownload = document.getElementById('btn-download-drinks-report');
        if (btnDownload) btnDownload.addEventListener('click', downloadDrinksReportCSV);

        const btnDownloadPdf = document.getElementById('btn-download-drinks-report-pdf');
        if (btnDownloadPdf) btnDownloadPdf.addEventListener('click', exportDrinksPDF);

        const formApertura = document.getElementById('form-caja-apertura');
        if (formApertura) formApertura.addEventListener('submit', handleSaveCajaApertura);

        const formEgreso = document.getElementById('form-caja-egreso');
        if (formEgreso) formEgreso.addEventListener('submit', handleSaveCajaEgreso);

        const cashRealInput = document.getElementById('caja-efectivo-real');
        if (cashRealInput) cashRealInput.addEventListener('input', runArqueoCalculations);

        const btnPrintCierre = document.getElementById('btn-print-cierre');
        if (btnPrintCierre) btnPrintCierre.addEventListener('click', printCajaCierre);

        const btnExportCierrePdf = document.getElementById('btn-export-cierre-pdf');
        if (btnExportCierrePdf) btnExportCierrePdf.addEventListener('click', exportCierrePDF);

        const btnExportVentasPdf = document.getElementById('btn-export-ventas-pdf');
        if (btnExportVentasPdf) btnExportVentasPdf.addEventListener('click', exportVentasPDF);

        const btnExportCierres = document.getElementById('btn-export-cierres-csv');
        if (btnExportCierres) btnExportCierres.addEventListener('click', exportCierresToCSV);

        const btnExportTopPdf = document.getElementById('btn-export-top-sellers-pdf');
        if (btnExportTopPdf) btnExportTopPdf.addEventListener('click', exportTopSellersPDF);

        const btnExportTopCsv = document.getElementById('btn-export-top-sellers-csv');
        if (btnExportTopCsv) btnExportTopCsv.addEventListener('click', exportTopSellersCSV);

        const btnReset = document.getElementById('btn-reset-data');
        if (btnReset) btnReset.addEventListener('click', resetAllData);

        const btnReportPreviewPrint = document.getElementById('btn-report-preview-print');
        if (btnReportPreviewPrint) {
            btnReportPreviewPrint.addEventListener('click', () => {
                const def = window.ReportRenderer ? window.ReportRenderer.getActiveDefinition() : null;
                if (def) window.ReportRenderer.print(def);
            });
        }

        const btnReportPreviewPdf = document.getElementById('btn-report-preview-pdf');
        if (btnReportPreviewPdf) {
            btnReportPreviewPdf.addEventListener('click', () => {
                const def = window.ReportRenderer ? window.ReportRenderer.getActiveDefinition() : null;
                if (def) window.ReportRenderer.pdf(def);
            });
        }

        const btnCloseReportPreviewX = document.getElementById('btn-close-report-preview-x');
        if (btnCloseReportPreviewX) {
            btnCloseReportPreviewX.addEventListener('click', () => closeModal('modal-report-preview'));
        }

        const btnCloseReportPreview = document.getElementById('btn-report-preview-close');
        if (btnCloseReportPreview) {
            btnCloseReportPreview.addEventListener('click', () => closeModal('modal-report-preview'));
        }

        const btnCloseDrinks = document.getElementById('btn-close-drinks-report');
        if (btnCloseDrinks) btnCloseDrinks.addEventListener('click', closeDrinksReportModal);
        const btnCloseDrinksBtn = document.getElementById('btn-close-drinks-report-btn');
        if (btnCloseDrinksBtn) btnCloseDrinksBtn.addEventListener('click', closeDrinksReportModal);

        const btnCloseMenuProducts = document.getElementById('btn-close-menu-products');
        if (btnCloseMenuProducts) btnCloseMenuProducts.addEventListener('click', closeMenuProductsModal);
        const btnCloseMenuProductsDone = document.getElementById('btn-close-menu-products-done');
        if (btnCloseMenuProductsDone) btnCloseMenuProductsDone.addEventListener('click', closeMenuProductsModal);

        const lowStockHeaderBtn = document.getElementById('header-low-stock-alert');
        if (lowStockHeaderBtn) {
            lowStockHeaderBtn.addEventListener('click', () => openLowStockModal());
        }
    }

    applyRoleVisibility();
    const validTabs = ['pos','active-orders','menu-config','inventory','reports','financial','customers','dashboard','reservations','users','saas-admin'];
    const hashTab = window.location.hash.replace('#', '');
    const uiContext = typeof window.getUiContext === 'function' ? window.getUiContext() : {};
    const savedTab = (hashTab && validTabs.includes(hashTab))
        ? hashTab
        : (localStorage.getItem('restocloud_active_tab') || 'pos');
    if (savedTab === 'reservations' && uiContext.reservationsDate) {
        state.reservationsDate = uiContext.reservationsDate;
    }
    switchTab(savedTab, { skipLoad: savedTab !== 'reservations' && savedTab !== 'financial' && savedTab !== 'customers' });
}

window.startAuthenticatedApp = startAuthenticatedApp;

function initializeAppUpdates() {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
    let hadController = Boolean(navigator.serviceWorker.controller);
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        // Never reload automatically: a payment or unsaved cart may be in progress.
        if (hadController && !document.getElementById('app-update-notice')) {
            let container = document.getElementById('toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast-container';
                container.className = 'toast-container';
                document.body.appendChild(container);
            }
            const notice = document.createElement('div');
            notice.id = 'app-update-notice';
            notice.className = 'toast info';
            notice.setAttribute('role', 'status');
            notice.textContent = 'Nueva versión disponible. Finaliza y guarda tu operación; después recarga la página para ver los cambios.';
            container.appendChild(notice);
        }
        hadController = true;
    });
    const workerUrl = new URL('sw.js', document.baseURI);
    navigator.serviceWorker.register(workerUrl.href, {
        scope: new URL('./', workerUrl).href,
        updateViaCache: 'none'
    }).then(registration => registration.update()).catch(error => {
        console.warn('[APP] No se pudo comprobar la actualización:', error);
    });
}

document.addEventListener('DOMContentLoaded', async () => {
    initializeAppUpdates();
    initReactiveRendering();
    initAutoAnimations();

    if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
        const devHint = document.getElementById('auth-dev-hint');
        if (devHint) devHint.style.display = '';
    }
    if (window.AppAuth) {
        window.AppAuth.bind();
    }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (event) => {
            if (event.data && event.data.type === 'replay-offline-orders') {
                replayOfflineOrders();
            }
        });
    }

    const hasSession = window.AppAuth ? await window.AppAuth.bootstrap() : true;
    if (!hasSession) {
        return;
    }

    await startAuthenticatedApp();
});

// ==========================================================================
// MOBILE: Hamburger sidebar + Cart toggle
// ==========================================================================
document.addEventListener('DOMContentLoaded', () => {
    const hamburger = document.getElementById('mobile-hamburger');
    const sidebar = document.getElementById('app-sidebar');
    const overlay = document.getElementById('mobile-overlay');

    const closeMobileMenu = () => {
        if (sidebar) sidebar.classList.remove('mobile-open');
        if (overlay) overlay.classList.remove('active');
        document.body.classList.remove('no-scroll');
        if (hamburger) hamburger.setAttribute('aria-expanded', 'false');
    };

    const openMobileMenu = () => {
        if (sidebar) sidebar.classList.add('mobile-open');
        if (overlay) overlay.classList.add('active');
        document.body.classList.add('no-scroll');
        if (hamburger) hamburger.setAttribute('aria-expanded', 'true');
    };

    if (hamburger && sidebar && overlay) {
        hamburger.setAttribute('aria-controls', 'app-sidebar');
        hamburger.setAttribute('aria-expanded', 'false');

        hamburger.addEventListener('click', () => {
            if (sidebar.classList.contains('mobile-open')) closeMobileMenu();
            else openMobileMenu();
        });

        overlay.addEventListener('click', closeMobileMenu);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') closeMobileMenu();
        });
        window.addEventListener('resize', () => {
            if (!window.matchMedia('(max-width: 768px)').matches) closeMobileMenu();
        });
    }

    // Close sidebar on nav-item click (delegated, runs immediately)
    document.addEventListener('click', (e) => {
        if (e.target.closest('.nav-item')) {
            closeMobileMenu();
        }
    });

    // Cart toggle
    const cartToggle = document.getElementById('mobile-cart-toggle');
    const posGrid = document.querySelector('.pos-grid');
    if (cartToggle && posGrid) {
        const backButton = document.getElementById('btn-back-to-catalog');
        let layoutFrame = 0;
        const updateCartLayout = () => {
            cancelAnimationFrame(layoutFrame);
            layoutFrame = requestAnimationFrame(() => {
                if (!posGrid.getClientRects().length) return;
                const viewport = window.visualViewport;
                const bottom = viewport ? viewport.offsetTop + viewport.height : window.innerHeight;
                const top = Math.max(0, posGrid.getBoundingClientRect().top);
                // Short viewports retain a usable panel and allow page scrolling.
                posGrid.style.setProperty('--pos-available-height', `${Math.max(280, Math.floor(bottom - top - 12))}px`);
            });
        };
        const setCartOpen = (open) => {
            posGrid.classList.toggle('show-cart', open);
            cartToggle.setAttribute('aria-expanded', String(open));
            updateCartLayout();
            if (open) backButton?.focus({ preventScroll: true });
            else cartToggle.focus({ preventScroll: true });
        };
        cartToggle.addEventListener('click', () => {
            setCartOpen(!posGrid.classList.contains('show-cart'));
        });
        backButton?.addEventListener('click', () => setCartOpen(false));
        const updateCartBadge = () => {
            const count = (state.cart || []).reduce((s, i) => s + Number(i.qty || i.quantity || 1), 0);
            const badge = document.getElementById('mobile-cart-badge');
            if (badge) badge.textContent = count;
            cartToggle.setAttribute('aria-label', `Ver pedido (${count} productos)`);
        };
        const observer = new MutationObserver(updateCartBadge);
        const cartList = document.getElementById('cart-items-container');
        if (cartList) {
            observer.observe(cartList, { childList: true, subtree: true });
        }
        window.addEventListener('resize', updateCartLayout);
        window.visualViewport?.addEventListener('resize', updateCartLayout);
        if (window.ResizeObserver) {
            const header = document.querySelector('.main-header');
            if (header) new ResizeObserver(updateCartLayout).observe(header);
        }
        const posScreen = document.getElementById('screen-pos');
        if (posScreen) new MutationObserver(updateCartLayout).observe(posScreen, { attributes: true, attributeFilter: ['class'] });
        updateCartBadge();
        updateCartLayout();
    }

    // Mobile: Fix sub-tab touch responsiveness
    // Add touchstart event listeners to ensure subtabs work on mobile browsers
    const subtabSelectors = [
        '.menu-config-subnav .subnav-btn',
        '.reports-subtab',
        '.reservation-subtab',
        '.reservation-catalog-tab',
        '.saas-subtab'
    ];

    subtabSelectors.forEach(selector => {
        document.querySelectorAll(selector).forEach(btn => {
            // Remove any existing touchstart listeners to prevent duplicates
            btn.removeEventListener('touchstart', handleSubtabTouch);
            // Add touchstart listener for immediate response on mobile
            btn.addEventListener('touchstart', handleSubtabTouch, { passive: true });
        });
    });

    function handleSubtabTouch(e) {
        // Ensure the button is clickable and not disabled
        if (this.disabled || this.classList.contains('disabled')) return;
        
        // Trigger click event immediately for responsive feel
        // The browser will also fire a click event, but this ensures immediate feedback
        if (!this.classList.contains('active')) {
            this.click();
        }
    }

    // MutationObserver to handle dynamically added subtabs
    const subtabObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.addedNodes.length) {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        subtabSelectors.forEach(selector => {
                            const buttons = node.querySelectorAll ? node.querySelectorAll(selector) : [];
                            buttons.forEach(btn => {
                                btn.removeEventListener('touchstart', handleSubtabTouch);
                                btn.addEventListener('touchstart', handleSubtabTouch, { passive: true });
                            });
                            // Check if the added node itself matches the selector
                            if (node.matches && node.matches(selector)) {
                                node.removeEventListener('touchstart', handleSubtabTouch);
                                node.addEventListener('touchstart', handleSubtabTouch, { passive: true });
                            }
                        });
                    }
                });
            }
        });
    });

    // Start observing the document body for changes
    subtabObserver.observe(document.body, {
        childList: true,
        subtree: true
    });
});
