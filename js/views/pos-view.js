(function (window) {
    function render() {
        renderCatalog();
        if (window.PosController?.syncServiceModeFromLocation) window.PosController.syncServiceModeFromLocation();
        renderCart();
    }

    function getActiveMenuId() {
        const saved = localStorage.getItem('restocloud_active_menu');
        if (!saved) return null;
        if (state.menus && state.menus.some(m => m.id === saved && m.active)) return saved;
        return null;
    }

    function menuFilter(item, activeMenuId) {
        if (!activeMenuId) return true;
        const product = (state.products || []).find(p => p.id === item.id);
        if (!product) return true;
        return product.menu_id === activeMenuId;
    }

    function getSecondsForMenu() {
        const activeMenuId = getActiveMenuId();
        return state.seconds.filter(sec => Number(sec.active) !== 0 && menuFilter(sec, activeMenuId));
    }

    function getActiveSopas() {
        const activeMenuId = getActiveMenuId();
        return state.sopas.filter(s => Number(s.active) !== 0 && menuFilter(s, activeMenuId));
    }

    function getPlatosExtrasForMenu() {
        const activeMenuId = getActiveMenuId();
        return (state.platosExtras || []).filter(p => menuFilter(p, activeMenuId));
    }

    function getExtrasForMenu() {
        const activeMenuId = getActiveMenuId();
        return (state.extras || []).filter(e => menuFilter(e, activeMenuId));
    }

    function renderCatalog() {
        const appState = window.state;
        const container = document.getElementById('pos-catalog-cards');
        const currentCategory = window.getPosCategory ? window.getPosCategory() : 'meals';

        if (!container || !appState) return;

        container.innerHTML = '';

        if (currentCategory === 'meals') {
            renderMealsCatalog(container, appState);
            return;
        }

        if (currentCategory === 'extras') {
            renderPlatosExtrasCatalog(container, appState);
            return;
        }

        renderDrinksCatalog(container, appState);
    }

    function renderMealsCatalog(container, appState) {
        const menuSeconds = getSecondsForMenu();
        const activeSopas = getActiveSopas();

        let allSecondsAgotado = true;
        let secondsOptions = '';
        if (menuSeconds.length === 0) {
            secondsOptions = '<option value="">-- No hay platos en este menú --</option>';
        } else {
            secondsOptions = '<option value="">-- Seleccionar segundo --</option>';
            menuSeconds.forEach(sec => {
                const availStock = getAvailableSegundoStock(sec.id);
                if (availStock > 0) allSecondsAgotado = false;
                const disabled = availStock <= 0 ? 'disabled' : '';
                const warningText = (availStock > 0 && availStock <= 5) ? ' STOCK BAJO' : '';
                secondsOptions += `<option value="${sec.id}" ${disabled}>${escapeHtml(sec.name)} (Stock: ${availStock}${warningText})</option>`;
            });
        }

        let allSopasAgotado = activeSopas.length === 0;
        let sopasOptions = '';
        if (activeSopas.length === 0) {
            sopasOptions = '<option value="">-- No hay sopas disponibles --</option>';
        } else {
            sopasOptions = '<option value="">-- Seleccionar sopa --</option>';
            activeSopas.forEach(sopa => {
                const availStock = getAvailableSopaStock(sopa.id);
                if (availStock > 0) allSopasAgotado = false;
                const disabled = availStock <= 0 ? 'disabled' : '';
                const warningText = (availStock > 0 && availStock <= 5) ? ' STOCK BAJO' : '';
                sopasOptions += `<option value="${sopa.id}" ${disabled}>${escapeHtml(sopa.name)} (Stock: ${availStock}${warningText})</option>`;
            });
        }

        const almuerzoAgotado = allSopasAgotado || allSecondsAgotado;

        container.innerHTML = `
            <div class="catalog-card ${almuerzoAgotado ? 'disabled-by-stock' : ''}">
                <div class="card-header-tag">Sopa + Segundo</div>
                <div class="card-top-qty">
                    <label for="qty-almuerzo">Cant:</label>
                    <input type="number" class="card-qty-input-top" id="qty-almuerzo" min="1" max="99" value="1">
                </div>
                <div class="card-icon-wrapper">
                    <i class="fa-solid fa-bowl-food"></i>
                </div>
                <div class="card-details">
                    <h4>Almuerzo Completo</h4>
                    <p class="card-description">Sopa con un segundo a elección.</p>
                    <span class="card-price">${formatCurrency(appState.prices.almuerzo)}</span>
                </div>
                <div class="card-options">
                    <div class="option-group">
                        <label>Sopa:</label>
                        <select id="pos-select-sopa-almuerzo" class="form-select">${sopasOptions}</select>
                    </div>
                    <div class="option-group">
                        <label>Segundo:</label>
                        <select id="pos-select-segundo-almuerzo" class="form-select">${secondsOptions}</select>
                    </div>
                    <div class="option-group">
                        <label>Tipo de entrega:</label>
                        <div class="radio-toggle">
                            <input type="radio" id="almuerzo-servir" name="almuerzo-type" value="servirse" checked>
                            <label for="almuerzo-servir"><i class="fa-solid fa-plate-wheat"></i> Servir</label>
                            <input type="radio" id="almuerzo-llevar" name="almuerzo-type" value="llevar">
                            <label for="almuerzo-llevar"><i class="fa-solid fa-bag-shopping"></i> Llevar</label>
                        </div>
                    </div>
                </div>
                <button class="btn btn-primary btn-add-cart" data-action="add-cart-type" data-item-type="almuerzo" ${almuerzoAgotado ? 'disabled' : ''}>
                    <i class="fa-solid fa-plus"></i> Agregar al Pedido
                </button>
            </div>

            <div class="catalog-card ${allSecondsAgotado ? 'disabled-by-stock' : ''}">
                <div class="card-top-qty">
                    <label for="qty-segundo">Cant:</label>
                    <input type="number" class="card-qty-input-top" id="qty-segundo" min="1" max="99" value="1">
                </div>
                <div class="card-icon-wrapper secondary-color">
                    <i class="fa-solid fa-plate-wheat"></i>
                </div>
                <div class="card-details">
                    <h4>Segundo Suelto</h4>
                    <p class="card-description">Plato de segundo servido individualmente.</p>
                    <span class="card-price">${formatCurrency(appState.prices.segundo)}</span>
                </div>
                <div class="card-options">
                    <div class="option-group">
                        <label>Segundo:</label>
                        <select id="pos-select-segundo-suelto" class="form-select">${secondsOptions}</select>
                    </div>
                    <div class="option-group">
                        <label>Tipo de entrega:</label>
                        <div class="radio-toggle">
                            <input type="radio" id="segundo-servir" name="segundo-type" value="servirse" checked>
                            <label for="segundo-servir"><i class="fa-solid fa-plate-wheat"></i> Servir</label>
                            <input type="radio" id="segundo-llevar" name="segundo-type" value="llevar">
                            <label for="segundo-llevar"><i class="fa-solid fa-bag-shopping"></i> Llevar</label>
                        </div>
                    </div>
                </div>
                <button class="btn btn-secondary btn-add-cart" data-action="add-cart-type" data-item-type="segundo" ${allSecondsAgotado ? 'disabled' : ''}>
                    <i class="fa-solid fa-plus"></i> Agregar al Pedido
                </button>
            </div>

            <div class="catalog-card ${allSopasAgotado ? 'disabled-by-stock' : ''}">
                <div class="card-top-qty">
                    <label for="qty-sopa">Cant:</label>
                    <input type="number" class="card-qty-input-top" id="qty-sopa" min="1" max="99" value="1">
                </div>
                <div class="card-icon-wrapper accent-color">
                    <i class="fa-solid fa-bowl-hot"></i>
                </div>
                <div class="card-details">
                    <h4>Sopa Suelta</h4>
                    <p class="card-description">Sopa servida individualmente.</p>
                    <span class="card-price">${formatCurrency(appState.prices.sopa)}</span>
                </div>
                <div class="card-options">
                    <div class="option-group">
                        <label>Sopa:</label>
                        <select id="pos-select-sopa-suelta" class="form-select">${sopasOptions}</select>
                    </div>
                    <div class="option-group">
                        <label>Tipo de entrega:</label>
                        <div class="radio-toggle">
                            <input type="radio" id="sopa-servir" name="sopa-type" value="servirse" checked>
                            <label for="sopa-servir"><i class="fa-solid fa-plate-wheat"></i> Servir</label>
                            <input type="radio" id="sopa-llevar" name="sopa-type" value="llevar">
                            <label for="sopa-llevar"><i class="fa-solid fa-bag-shopping"></i> Llevar</label>
                        </div>
                    </div>
                </div>
                <button class="btn btn-accent btn-add-cart" data-action="add-cart-type" data-item-type="sopa" ${allSopasAgotado ? 'disabled' : ''}>
                    <i class="fa-solid fa-plus"></i> Agregar al Pedido
                </button>
            </div>
        `;
    }

    function renderPlatosExtrasCatalog(container, appState) {
        let cardsHtml = '';
        const menuPlatosExtras = getPlatosExtrasForMenu();

        menuPlatosExtras.forEach(plato => {
            const availStock = getAvailablePlatoExtraStock(plato.id);
            const isAgotado = availStock <= 0;
            const isLowStock = availStock > 0 && availStock <= 5;
            const stockColor = isLowStock ? 'var(--primary)' : 'inherit';
            const warningBadge = isLowStock
                ? '<span style="background: rgba(255, 107, 53, 0.12); color: var(--primary); padding: 2px 6px; border-radius: 4px; font-weight:700; font-size:11px; margin-left: 6px;"><i class="fa-solid fa-triangle-exclamation"></i> Stock Bajo</span>'
                : '';

            cardsHtml += `
                <div class="catalog-card ${isAgotado ? 'disabled-by-stock' : ''}">
                    <div class="card-top-qty">
                        <label for="qty-pe-${plato.id}">Cant:</label>
                        <input type="number" class="card-qty-input-top" id="qty-pe-${plato.id}" min="1" max="99" value="1">
                    </div>
                    <div class="card-icon-wrapper secondary-color">
                        <i class="fa-solid fa-utensils"></i>
                    </div>
                    <div class="card-details">
                        <h4>${escapeHtml(plato.name)}</h4>
                        <p class="card-description">Stock Disponible: <strong style="color: ${stockColor}">${availStock}</strong> ${warningBadge}</p>
                        <span class="card-price">${formatCurrency(plato.price)}</span>
                    </div>
                    <div class="card-options">
                        <div class="option-group">
                            <label>Tipo de entrega:</label>
                            <div class="radio-toggle">
                                <input type="radio" id="pe-${plato.id}-servir" name="pe-${plato.id}-type" value="servirse" checked>
                                <label for="pe-${plato.id}-servir"><i class="fa-solid fa-plate-wheat"></i> Servir</label>
                                <input type="radio" id="pe-${plato.id}-llevar" name="pe-${plato.id}-type" value="llevar">
                                <label for="pe-${plato.id}-llevar"><i class="fa-solid fa-bag-shopping"></i> Llevar</label>
                            </div>
                        </div>
                    </div>
                    <button class="btn btn-primary btn-add-cart" data-action="add-plato-extra" data-plato-id="${plato.id}" ${isAgotado ? 'disabled' : ''}>
                        <i class="fa-solid fa-plus"></i> Agregar al Pedido
                    </button>
                </div>
            `;
        });

        if (menuPlatosExtras.length === 0) {
            cardsHtml = `
                <div class="empty-table-state" style="grid-column: 1/-1;">
                    <i class="fa-solid fa-circle-info" style="font-size:32px;"></i>
                    <p>No hay platos extras en este menú.</p>
                    <span>Vaya a "Platos y Menú" y asigne platos a este menú.</span>
                </div>
            `;
        }

        container.innerHTML = cardsHtml;
    }

    function renderDrinksCatalog(container, appState) {
        let cardsHtml = '';
        const menuExtras = getExtrasForMenu();

        menuExtras.forEach(extra => {
            const availStock = getAvailableExtraStock(extra.id);
            const isAgotado = availStock <= 0;
            const isLowStock = availStock > 0 && availStock <= 5;
            const stockStyle = isLowStock ? 'color: var(--primary); font-weight: 700;' : '';
            const warningIcon = isLowStock ? 'Stock bajo: ' : '';

            cardsHtml += `
                <div class="drink-minimal-btn ${isAgotado ? 'disabled-by-stock' : ''} ${isLowStock ? 'low-stock-drink' : ''}" data-action="add-extra" data-extra-id="${extra.id}">
                    <i class="fa-solid fa-bottle-water" style="font-size: 20px; color: ${isLowStock ? 'var(--primary)' : 'var(--accent)'}; margin-bottom: 2px;"></i>
                    <span class="drink-minimal-name">${escapeHtml(extra.name)}</span>
                    <span class="drink-minimal-price">${formatCurrency(extra.price)}</span>
                    <span class="drink-minimal-stock" style="${stockStyle}">${warningIcon}Stock: ${availStock}</span>
                    <div class="drink-qty-wrapper">
                        <button class="drink-qty-btn" data-qty-action="dec" data-extra-id="${extra.id}">−</button>
                        <input type="number" class="drink-qty-input" id="qty-extra-${extra.id}" min="1" max="99" value="1" onclick="event.stopPropagation()">
                        <button class="drink-qty-btn" data-qty-action="inc" data-extra-id="${extra.id}">+</button>
                    </div>
                </div>
            `;
        });

        if (menuExtras.length === 0) {
            container.innerHTML = `
                <div class="empty-table-state" style="grid-column: 1/-1;">
                    <i class="fa-solid fa-circle-info" style="font-size:32px;"></i>
                    <p>No hay bebidas en este menú.</p>
                </div>
            `;
            return;
        }

        container.innerHTML = `<div class="drinks-grid">${cardsHtml}</div>`;
    }

    function renderCart() {
        const appState = window.state;
        const container = document.getElementById('cart-items-container');

        if (!container || !appState) return;

        container.innerHTML = '';

        if (appState.cart.length === 0) {
            container.appendChild(buildEmptyCartState());
            const subtotalEl = document.getElementById('cart-subtotal');
            const totalEl = document.getElementById('cart-total');
            if (subtotalEl) subtotalEl.textContent = formatCurrency(0);
            if (totalEl) totalEl.textContent = formatCurrency(0);
            renderOrderServiceSummary();
            renderPromoSection();
            return;
        }

        let subtotal = 0;
        appState.cart.forEach(item => {
            const itemQty = item.qty || 1;
            const salsaTotal = (item.salsas || []).reduce((acc, s) => acc + (s.salsaPrice || 0), 0);
            const lineTotal = (item.price + salsaTotal) * itemQty;
            subtotal += lineTotal;
            const row = document.createElement('div');
            row.className = 'cart-item-row';

            let detailsHtml = '';
            if (item.sopaName) {
                detailsHtml += `<span class="item-detail-badge sopa-name">${item.sopaName}</span>`;
            }
            if (item.segundoName) {
                detailsHtml += `<span class="item-detail-badge segundo-name">${item.segundoName}</span>`;
            }
            detailsHtml += `<span class="item-detail-badge ${item.serviceType}">${item.serviceType}</span>`;
            if (item.salsas && item.salsas.length > 0) {
                item.salsas.forEach(s => {
                    const modeLabel = s.salsaMode === 'banar' ? 'Bañar' : 'A parte';
                    const priceLabel = s.salsaPrice > 0 ? ` +${formatCurrency(s.salsaPrice)}` : '';
                    detailsHtml += `<span class="item-detail-badge salsa-name" style="background:rgba(255,107,53,0.1);color:var(--primary);">🥗 ${s.salsaName} (${modeLabel})${priceLabel}</span>`;
                });
            }

            const qtyBadge = itemQty > 1 ? `<span class="item-qty-badge">x${itemQty}</span>` : '';
            const priceHtml = itemQty > 1
                ? `<span class="item-price-line">${itemQty} x ${formatCurrency(item.price + salsaTotal)}</span><span class="item-price">${formatCurrency(lineTotal)}</span>`
                : `<span class="item-price">${formatCurrency(lineTotal)}</span>`;

            row.innerHTML = `
                <div class="item-badge-type ${item.type === 'plato_extra' ? 'segundo' : item.type}">
                    <i class="fa-solid ${item.type === 'almuerzo' ? 'fa-bowl-food' : item.type === 'sopa' ? 'fa-bowl-hot' : item.type === 'segundo' ? 'fa-plate-wheat' : item.type === 'plato_extra' ? 'fa-utensils' : 'fa-bottle-water'}"></i>
                </div>
                <div class="item-info">
                    <div class="item-title">${escapeHtml(item.name)} ${qtyBadge}</div>
                    <div class="item-details">${detailsHtml}</div>
                </div>
                <div class="item-pricing">
                    ${priceHtml}
                </div>
                <button class="btn-remove-item" data-action="remove-cart-item" data-item-id="${item.id}" title="Eliminar">
                    <i class="fa-solid fa-trash-can"></i>
                </button>
            `;

            const removeButton = row.querySelector('[data-action="remove-cart-item"]');
            if (removeButton) {
                removeButton.addEventListener('click', () => {
                    if (window.PosController) {
                        window.PosController.removeCartItem(item.id);
                    }
                });
            }
            container.appendChild(row);
        });

        const subtotalEl = document.getElementById('cart-subtotal');
        const totalEl = document.getElementById('cart-total');
        const discount = appState.cartDiscountAmount || 0;
        const total = Math.max(0, subtotal - discount);
        if (subtotalEl) subtotalEl.textContent = formatCurrency(subtotal);
        if (totalEl) totalEl.textContent = formatCurrency(total);

        renderOrderServiceSummary();
        renderPromoSection();
    }

    function renderOrderServiceSummary() {
        const summary = document.getElementById('order-service-summary');
        if (!summary) return;
        const cart = window.state?.cart || [];
        const quantities = cart.reduce((result, item) => {
            const key = item.serviceType === 'llevar' ? 'llevar' : 'servirse';
            result[key] += Number(item.qty || item.quantity || 1);
            return result;
        }, { servirse: 0, llevar: 0 });
        if (!cart.length) {
            summary.innerHTML = '<span class="text-muted"><i class="fa-solid fa-circle-info"></i> Seleccione Servir o Llevar en cada tarjeta.</span>';
            return;
        }
        const parts = [];
        if (quantities.servirse) parts.push(`<span class="service-summary-badge servirse"><i class="fa-solid fa-plate-wheat"></i> Servirse: ${quantities.servirse}</span>`);
        if (quantities.llevar) parts.push(`<span class="service-summary-badge llevar"><i class="fa-solid fa-bag-shopping"></i> Llevar: ${quantities.llevar}</span>`);
        const mixed = quantities.servirse > 0 && quantities.llevar > 0;
        summary.innerHTML = `${parts.join(' ')}${mixed ? '<small class="service-summary-note">Pedido mixto: se respeta la modalidad de cada producto.</small>' : ''}`;
    }

    function renderPromoSection() {
        const appState = window.state;
        const section = document.getElementById('cart-promo-section');
        if (!section) return;

        if (appState.cart.length === 0) {
            section.style.display = 'none';
            return;
        }
        section.style.display = '';

        const listEl = document.getElementById('cart-promo-list');
        const appliedEl = document.getElementById('cart-applied-promo');
        const appliedNameEl = document.getElementById('cart-applied-promo-name');
        const appliedDescEl = document.getElementById('cart-applied-promo-desc');
        const discountRow = document.getElementById('cart-discount-row');
        const discountLabel = document.getElementById('cart-discount-label');
        const discountAmount = document.getElementById('cart-discount-amount');
        const couponInput = document.getElementById('cart-coupon-input');

        const suggested = appState.cartSuggestedPromos || [];
        const applied = appState.cartPromo;
        const discount = appState.cartDiscountAmount || 0;

        if (listEl) {
            listEl.innerHTML = '';
            if (!applied && suggested.length > 0) {
                suggested.forEach((promo, idx) => {
                    const item = document.createElement('div');
                    item.className = 'cart-promo-item';
                    item.innerHTML = `
                        <i class="fa-solid fa-tag" style="color:var(--accent);font-size:10px;"></i>
                        <span class="cart-promo-item-name">${escapeHtml(promo.plan_name || promo.name || 'Promo')}</span>
                        <span class="cart-promo-item-discount">-${formatCurrency(promo.discount_amount || promo.discount || 0)}</span>
                    `;
                    item.addEventListener('click', () => {
                        if (window.PosController) window.PosController.applyPromo(idx);
                    });
                    listEl.appendChild(item);
                });
                document.getElementById('btn-toggle-promos')?.style.setProperty('display', '');
            } else {
                document.getElementById('btn-toggle-promos')?.style.setProperty('display', 'none');
            }
        }

        if (appliedEl) {
            if (applied) {
                appliedEl.style.display = '';
                if (appliedNameEl) appliedNameEl.textContent = applied.plan_name || applied.name || 'Promoción aplicada';
                if (appliedDescEl) appliedDescEl.textContent = applied.description || '';
            } else {
                appliedEl.style.display = 'none';
            }
        }

        if (discountRow) {
            if (discount > 0) {
                discountRow.style.display = '';
                if (discountLabel) discountLabel.textContent = appState.cartDiscountLabel || 'Descuento';
                if (discountAmount) discountAmount.textContent = `-${formatCurrency(discount)}`;
            } else {
                discountRow.style.display = 'none';
            }
        }

        if (couponInput) {
            couponInput.value = appState.cartCouponCode || '';
        }
    }

    function buildEmptyCartState() {
        const emptyState = document.createElement('div');
        emptyState.className = 'empty-cart';
        emptyState.id = 'empty-cart-state';
        emptyState.innerHTML = `
            <i class="fa-solid fa-basket-shopping"></i>
            <p>El pedido está vacío</p>
            <span>Agregue almuerzos, platos extras o gaseosas para empezar</span>
        `;
        return emptyState;
    }

    window.PosView = {
        render,
        renderCatalog,
        renderCart
    };
})(window);
