(function (window) {
    let initialized = false;

    function init() {
        if (initialized) return;

        const catalogContainer = document.getElementById('pos-catalog-cards');
        if (catalogContainer) {
            catalogContainer.addEventListener('click', handleCatalogClick);
            catalogContainer.addEventListener('click', handleDrinkQtyBtn);
        }

        const cartContainer = document.getElementById('cart-items-container');
        if (cartContainer) {
            cartContainer.addEventListener('click', handleCartClick);
        }

        // Mixed payment toggle
        const paymentSelect = document.getElementById('order-payment-select');
        if (paymentSelect) {
            paymentSelect.addEventListener('change', (e) => {
                const section = document.getElementById('mixed-payment-section');
                if (section) section.style.display = e.target.value === 'mixto' ? 'flex' : 'none';
                if (e.target.value === 'mixto') updateMixedPaymentSum();
            });
        }

        // Mixed payment auto-calculate
        const mixedEfectivo = document.getElementById('mixed-efectivo');
        const mixedQr = document.getElementById('mixed-qr');
        if (mixedEfectivo) mixedEfectivo.addEventListener('input', updateMixedPaymentSum);
        if (mixedQr) mixedQr.addEventListener('input', updateMixedPaymentSum);

        // Table select: show customer name input for Mesa/Llevar/Delivery/Personalizado
        const tableSelect = document.getElementById('order-table-select');
        if (tableSelect) {
            tableSelect.addEventListener('change', (e) => {
                const wrapper = document.getElementById('custom-customer-name-wrapper');
                if (wrapper) wrapper.style.display = 'block';

                if (e.target.value !== 'Llevar' && e.target.value !== 'Delivery' && e.target.value !== 'Personalizado') {
                    const occupiedTables = new Set(
                        (window.state.activeOrders || [])
                            .filter(o => o.status === 'pendiente' && o.deliveryType === 'mesa')
                            .map(o => {
                                const cust = o.customer || '';
                                const dashIdx = cust.indexOf(' - ');
                                return dashIdx > -1 ? cust.substring(0, dashIdx) : cust;
                            })
                    );
                    if (occupiedTables.has(e.target.value)) {
                        const existingOrder = window.state.activeOrders.find(o => {
                            if (o.status !== 'pendiente' || o.deliveryType !== 'mesa') return false;
                            const cust = o.customer || '';
                            const dashIdx = cust.indexOf(' - ');
                            const tableName = dashIdx > -1 ? cust.substring(0, dashIdx) : cust;
                            return tableName === e.target.value;
                        });
                        showToast(`La mesa "${e.target.value}" ya tiene un pedido activo (Bs ${existingOrder ? existingOrder.total.toFixed(2) : '0'}). Use "Agregar Ítems" desde el board.`, 'warning');
                        e.target.value = 'Llevar';
                    }
                }
            });
        }

        initialized = true;
    }

    function updateMixedPaymentSum() {
        const total = window.state.cart.reduce((sum, item) => {
            const salsaTotal = (item.salsas || []).reduce((acc, s) => acc + (s.salsaPrice || 0), 0);
            return sum + (item.price + salsaTotal) * (item.qty || 1);
        }, 0);
        const efectivoEl = document.getElementById('mixed-efectivo');
        const qrEl = document.getElementById('mixed-qr');
        const efectivo = parseFloat(efectivoEl ? efectivoEl.value : 0) || 0;
        const qr = parseFloat(qrEl ? qrEl.value : 0) || 0;
        const sum = efectivo + qr;
        const sumEl = document.getElementById('mixed-payment-sum');
        if (sumEl) {
            sumEl.textContent = `Bs ${sum.toFixed(2)} / Bs ${total.toFixed(2)}`;
            sumEl.style.color = Math.abs(sum - total) < 0.01 ? 'var(--success)' : 'var(--danger)';
        }
    }

    function handleCatalogClick(event) {
        const actionTarget = event.target.closest('[data-action]');
        if (!actionTarget) return;

        const action = actionTarget.dataset.action;
        if (action === 'add-cart-type') {
            const qtyInput = actionTarget.closest('.catalog-card').querySelector('.card-qty-input-top');
            const qty = Math.max(1, parseInt(qtyInput ? qtyInput.value : 1, 10) || 1);
            addToCart(actionTarget.dataset.itemType, qty);
            if (qtyInput) qtyInput.value = 1;
            return;
        }
        if (action === 'add-plato-extra') {
            const qtyInput = actionTarget.closest('.catalog-card').querySelector('.card-qty-input-top');
            const qty = Math.max(1, parseInt(qtyInput ? qtyInput.value : 1, 10) || 1);
            addPlatoExtraToCart(actionTarget.dataset.platoId, qty);
            if (qtyInput) qtyInput.value = 1;
            return;
        }
        if (action === 'add-extra') {
            const extraId = actionTarget.dataset.extraId;
            if (event.target.closest('[data-qty-action]')) return;
            const qtyInput = document.getElementById('qty-extra-' + extraId);
            const qty = Math.max(1, parseInt(qtyInput ? qtyInput.value : 1, 10) || 1);
            addExtraToCart(extraId, qty);
            if (qtyInput) qtyInput.value = 1;
        }
    }

    function handleDrinkQtyBtn(event) {
        const btn = event.target.closest('[data-qty-action]');
        if (!btn) return;
        event.stopPropagation();
        const extraId = btn.dataset.extraId;
        const action = btn.dataset.qtyAction;
        const input = document.getElementById('qty-extra-' + extraId);
        if (!input) return;
        let val = parseInt(input.value, 10) || 1;
        if (action === 'inc') val = Math.min(99, val + 1);
        else if (action === 'dec') val = Math.max(1, val - 1);
        input.value = val;
    }

    function handleCartClick(event) {
        const removeButton = event.target.closest('[data-action="remove-cart-item"]');
        if (!removeButton) return;
        removeCartItem(removeButton.dataset.itemId);
    }

    function setCategory(category) {
        if (typeof window.setPosCategory === 'function') {
            window.setPosCategory(category);
        } else {
            window.posCategory = category;
        }

        if (typeof window.setActiveCategoryTab === 'function') {
            const activeButtonMap = {
                meals: 'btn-cat-meals',
                extras: 'btn-cat-extras',
                drinks: 'btn-cat-drinks'
            };
            window.setActiveCategoryTab(activeButtonMap[category]);
        }

        notify();
    }

    function commitCart(nextCart) {
        if (window.AppStore) {
            window.AppStore.set({ cart: nextCart });
        } else {
            window.state.cart = nextCart;
        }
        saveCartToLocalStorage();
        refreshPosView();
        notify();
    }

    function cartItemKey(item) {
        let salsaKey = '';
        if (item.salsas && item.salsas.length > 0) {
            salsaKey = '|' + item.salsas.map(s => `${s.salsaId}:${s.salsaMode}`).sort().join(',');
        }
        if (item.type === 'almuerzo') return `almuerzo|${item.sopaId}|${item.segundoId}|${item.serviceType}${salsaKey}`;
        if (item.type === 'segundo') return `segundo|${item.segundoId}|${item.serviceType}${salsaKey}`;
        if (item.type === 'sopa') return `sopa|${item.sopaId}|${item.serviceType}${salsaKey}`;
        if (item.type === 'plato_extra') return `plato_extra|${item.platoId}|${item.serviceType}${salsaKey}`;
        if (item.type === 'extra') return `extra|${item.extraId}|${item.serviceType}`;
        return item.id;
    }

    function appendCartItem(newItem) {
        const qty = newItem.qty || 1;
        const cart = [...window.state.cart];
        const key = cartItemKey(newItem);
        const idx = cart.findIndex(c => cartItemKey(c) === key);
        if (idx >= 0) {
            cart[idx] = { ...cart[idx], qty: (cart[idx].qty || 1) + qty };
        } else {
            cart.push({ ...newItem, qty: qty });
        }
        commitCart(cart);
    }

    function refreshPosView() {
        if (typeof currentTabId !== 'undefined' && currentTabId === 'pos' && window.PosView) {
            window.PosView.render();
        }
    }

    function addToCart(type, qty = 1) {
        const appState = window.state;

        if (type === 'almuerzo') {
            const soupSelect = document.getElementById('pos-select-sopa-almuerzo');
            const sopaId = soupSelect ? soupSelect.value : '';
            if (!sopaId) {
                showToast('Seleccione una sopa para el almuerzo.', 'warning');
                return;
            }

            const sopaStockVal = getAvailableSopaStock(sopaId);
            if (sopaStockVal < qty) {
                showToast(`Stock insuficiente de sopa. Disponible: ${sopaStockVal}`, 'error');
                return;
            }

            const segundoSelect = document.getElementById('pos-select-segundo-almuerzo');
            const segundoId = segundoSelect ? segundoSelect.value : '';
            if (!segundoId) {
                showToast('Seleccione un segundo para el almuerzo.', 'warning');
                return;
            }

            const segundoStockVal = getAvailableSegundoStock(segundoId);
            if (segundoStockVal < qty) {
                showToast(`Stock insuficiente de segundo. Disponible: ${segundoStockVal}`, 'error');
                return;
            }

            const chosenSopa = appState.sopas.find(s => s.id === sopaId);
            const chosenSec = appState.seconds.find(s => s.id === segundoId);
            const almeTypeRadio = document.querySelector('input[name="almuerzo-type"]:checked');
            const serviceType = almeTypeRadio ? almeTypeRadio.value : 'servirse';

            const itemData = {
                id: generateId(),
                type: 'almuerzo',
                name: 'Almuerzo Completo',
                sopaId: sopaId,
                sopaName: chosenSopa?.name || '',
                segundoId: segundoId,
                segundoName: chosenSec?.name || '',
                serviceType: serviceType,
                price: appState.prices.almuerzo,
                qty: qty
            };

            const acceptsSalsa = (chosenSopa && chosenSopa.accepts_salsa) || (chosenSec && chosenSec.accepts_salsa);
            if (acceptsSalsa) {
                openSalsaSelectModal({ name: 'Almuerzo Completo' }, function(salsas) {
                    if (salsas) itemData.salsas = salsas;
                    appendCartItem(itemData);
                    showToast(`${qty > 1 ? qty + ' almuerzos' : 'Almuerzo'} añadido(s) al pedido`, 'success');
                });
            } else {
                appendCartItem(itemData);
                showToast(`${qty > 1 ? qty + ' almuerzos' : 'Almuerzo'} añadido(s) al pedido`, 'success');
            }

            const remainingSegundo = segundoStockVal - qty;
            const remainingSopa = sopaStockVal - qty;
            if (remainingSegundo <= 3 || remainingSopa <= 3) {
                setTimeout(() => {
                    showToast(`Stock bajo. Quedan pocas porciones (${chosenSopa.name}: ${remainingSopa}, ${chosenSec.name}: ${remainingSegundo})`, 'warning');
                }, 600);
            }
        } else if (type === 'segundo') {
            const segundoSelect = document.getElementById('pos-select-segundo-suelto');
            const segundoId = segundoSelect ? segundoSelect.value : '';
            if (!segundoId) {
                showToast('Seleccione un segundo.', 'warning');
                return;
            }

            const segundoStockVal = getAvailableSegundoStock(segundoId);
            if (segundoStockVal < qty) {
                showToast(`Stock insuficiente. Disponible: ${segundoStockVal}`, 'error');
                return;
            }

            const chosenSec = appState.seconds.find(s => s.id === segundoId);
            const secTypeRadio = document.querySelector('input[name="segundo-type"]:checked');
            const serviceType = secTypeRadio ? secTypeRadio.value : 'servirse';

            const itemData = {
                id: generateId(),
                type: 'segundo',
                name: 'Segundo Suelto',
                segundoId: segundoId,
                segundoName: chosenSec.name,
                serviceType: serviceType,
                price: appState.prices.segundo,
                qty: qty
            };

            if (chosenSec && chosenSec.accepts_salsa) {
                openSalsaSelectModal({ name: chosenSec.name }, function(salsas) {
                    if (salsas) itemData.salsas = salsas;
                    appendCartItem(itemData);
                    showToast(`${qty > 1 ? qty + ' segundos' : 'Segundo suelto'} añadido(s) al pedido`, 'success');
                });
            } else {
                appendCartItem(itemData);
                showToast(`${qty > 1 ? qty + ' segundos' : 'Segundo suelto'} añadido(s) al pedido`, 'success');
            }

            const remainingSegundo = segundoStockVal - qty;
            if (remainingSegundo <= 3) {
                setTimeout(() => {
                    showToast(`Stock bajo. Quedan solo ${remainingSegundo} porciones de "${chosenSec.name}"`, 'warning');
                }, 600);
            }
        } else if (type === 'sopa') {
            const soupSelect = document.getElementById('pos-select-sopa-suelta');
            const sopaId = soupSelect ? soupSelect.value : '';
            if (!sopaId) {
                showToast('Seleccione una sopa.', 'warning');
                return;
            }

            const sopaStockVal = getAvailableSopaStock(sopaId);
            if (sopaStockVal < qty) {
                showToast(`Stock insuficiente. Disponible: ${sopaStockVal}`, 'error');
                return;
            }

            const chosenSopa = appState.sopas.find(s => s.id === sopaId);
            const sopaTypeRadio = document.querySelector('input[name="sopa-type"]:checked');
            const serviceType = sopaTypeRadio ? sopaTypeRadio.value : 'servirse';

            const itemData = {
                id: generateId(),
                type: 'sopa',
                name: chosenSopa.name,
                sopaId: sopaId,
                sopaName: chosenSopa.name,
                serviceType: serviceType,
                price: appState.prices.sopa,
                qty: qty
            };

            if (chosenSopa && chosenSopa.accepts_salsa) {
                openSalsaSelectModal({ name: chosenSopa.name }, function(salsas) {
                    if (salsas) itemData.salsas = salsas;
                    appendCartItem(itemData);
                    showToast(`${qty > 1 ? qty + ' sopas' : chosenSopa.name} añadida(s) al pedido`, 'success');
                });
            } else {
                appendCartItem(itemData);
                showToast(`${qty > 1 ? qty + ' sopas' : chosenSopa.name} añadida(s) al pedido`, 'success');
            }

            const remainingSopa = sopaStockVal - qty;
            if (remainingSopa <= 3) {
                setTimeout(() => {
                    showToast(`Stock bajo. Quedan solo ${remainingSopa} porciones de "${chosenSopa.name}"`, 'warning');
                }, 600);
            }
        }
    }

    function addPlatoExtraToCart(platoId, qty = 1) {
        const appState = window.state;
        const plato = appState.platosExtras.find(p => p.id === platoId);
        if (!plato) return;

        const availStock = getAvailablePlatoExtraStock(platoId);
        if (availStock < qty) {
            showToast(`Stock insuficiente de "${plato.name}". Disponible: ${availStock}`, 'error');
            return;
        }

        const peTypeRadio = document.querySelector(`input[name="pe-${platoId}-type"]:checked`);
        const serviceType = peTypeRadio ? peTypeRadio.value : 'servirse';

        const itemData = {
            id: generateId(),
            type: 'plato_extra',
            name: plato.name,
            platoId: platoId,
            segundoId: null,
            segundoName: null,
            serviceType: serviceType,
            price: plato.price,
            qty: qty
        };

        if (plato.accepts_salsa) {
            openSalsaSelectModal({ name: plato.name }, function(salsas) {
                if (salsas) itemData.salsas = salsas;
                appendCartItem(itemData);
                showToast(`${qty > 1 ? qty + 'x ' : ''}"${plato.name}" añadido(s) al pedido`, 'success');
            });
        } else {
            appendCartItem(itemData);
            showToast(`${qty > 1 ? qty + 'x ' : ''}"${plato.name}" añadido(s) al pedido`, 'success');
        }

        const remainingPlato = availStock - qty;
        if (remainingPlato <= 3) {
            setTimeout(() => {
                showToast(`Stock bajo. Quedan solo ${remainingPlato} porciones de "${plato.name}"`, 'warning');
            }, 600);
        }
    }

    function addExtraToCart(extraId, qty = 1) {
        const appState = window.state;
        const extra = appState.extras.find(e => e.id === extraId);
        if (!extra) return;

        const availStock = getAvailableExtraStock(extraId);
        if (availStock < qty) {
            showToast(`Stock insuficiente de "${extra.name}". Disponible: ${availStock}`, 'error');
            return;
        }

        appendCartItem({
            id: generateId(),
            type: 'extra',
            name: extra.name,
            extraId: extraId,
            segundoId: null,
            segundoName: null,
            serviceType: 'llevar',
            price: extra.price,
            qty: qty
        });

        showToast(`${qty > 1 ? qty + 'x ' : ''}Bebida "${extra.name}" añadida(s) al pedido`, 'success');

        const remainingExtra = availStock - qty;
        if (remainingExtra <= 3) {
            setTimeout(() => {
                showToast(`Stock bajo. Quedan solo ${remainingExtra} unidades de "${extra.name}"`, 'warning');
            }, 600);
        }
    }

    function removeCartItem(itemId) {
        commitCart(window.state.cart.filter(item => item.id !== itemId));
        showToast('Item removido del pedido', 'info');
    }

    function clearCart() {
        if (window.state.cart.length === 0) return;
        commitCart([]);
        showToast('Pedido vaciado', 'info');
    }

    async function checkoutOrder(isPendingOnly) {
        const appState = window.state;
        if (appState.cart.length === 0) {
            showToast('El pedido está vacío.', 'warning');
            return;
        }

        const tableSelect = document.getElementById('order-table-select');
        let customerName = tableSelect ? tableSelect.value : 'Llevar';
        let deliveryType = 'llevar';

        if (customerName === 'Personalizado') {
            const customerInput = document.getElementById('order-customer-name');
            customerName = customerInput ? customerInput.value.trim() : '';
            if (!customerName) {
                showToast('Escriba un nombre personalizado.', 'warning');
                return;
            }
            deliveryType = 'llevar';
        } else if (customerName === 'Delivery') {
            const customerInput = document.getElementById('order-customer-name');
            customerName = customerInput ? customerInput.value.trim() : '';
            if (!customerName) {
                showToast('Escriba el nombre del cliente para delivery.', 'warning');
                return;
            }
            deliveryType = 'delivery';
        } else if (customerName === 'Llevar') {
            deliveryType = 'llevar';
            const customerInput = document.getElementById('order-customer-name');
            const takeoutName = customerInput ? customerInput.value.trim() : '';
            if (takeoutName) {
                customerName = `Para Llevar - ${takeoutName}`;
            }
        } else {
            deliveryType = 'mesa';
            const customerInput = document.getElementById('order-customer-name');
            const dinerName = customerInput ? customerInput.value.trim() : '';
            if (dinerName) {
                customerName = `${customerName} - ${dinerName}`;
            }
        }

        const hasSoup = appState.cart.some(i => i.type === 'almuerzo' || i.type === 'sopa');
        const initialServiceState = hasSoup ? 'esperando_sopa' : 'esperando_segundo';

        if (deliveryType === 'mesa') {
            const tableName = customerName.includes(' - ') ? customerName.split(' - ')[0] : customerName;
            const occupiedTables = new Set(
                (appState.activeOrders || [])
                    .filter(o => o.status === 'pendiente' && o.deliveryType === 'mesa')
                    .map(o => {
                        const cust = o.customer || '';
                        const dashIdx = cust.indexOf(' - ');
                        return dashIdx > -1 ? cust.substring(0, dashIdx) : cust;
                    })
            );
            if (occupiedTables.has(tableName)) {
                showToast(`La mesa "${tableName}" ya tiene un pedido activo. No se puede crear otro pedido para la misma mesa.`, 'error');
                return;
            }
        }
        const paymentSelectEl = document.getElementById('order-payment-select');
        const paymentSelect = paymentSelectEl ? paymentSelectEl.value : 'efectivo';
        const total = appState.cart.reduce((sum, item) => {
            const salsaTotal = (item.salsas || []).reduce((acc, s) => acc + (s.salsaPrice || 0), 0);
            return sum + (item.price + salsaTotal) * (item.qty || 1);
        }, 0);

        let paymentMethod;
        if (paymentSelect === 'mixto') {
            const efectivo = parseFloat(document.getElementById('mixed-efectivo').value) || 0;
            const qr = parseFloat(document.getElementById('mixed-qr').value) || 0;
            const sumParts = efectivo + qr;
            if (Math.abs(sumParts - total) > 0.01) {
                showToast(`La suma de pagos (Bs ${sumParts.toFixed(2)}) no coincide con el total (Bs ${total.toFixed(2)}).`, 'warning');
                return;
            }
            paymentMethod = { efectivo: efectivo, qr: qr };
        } else {
            paymentMethod = paymentSelect;
        }

        const newOrder = {
            id: generateId(),
            customer: customerName,
            deliveryType: deliveryType,
            items: [...appState.cart],
            total: total,
            paymentMethod: paymentMethod,
            timestamp: nowLocal()
        };

        if (isPendingOnly) {
            newOrder.status = 'pendiente';
            newOrder.serviceState = initialServiceState;

            const success = await saveOrderOnServer(newOrder);
            if (!success) {
                showToast('Error al procesar comanda en el servidor.', 'error');
                return;
            }

            Notifications.notify(`🛒 Nuevo pedido: ${customerName}`, 'success');
            commitCart([]);
            resetCartInputs();
            if (typeof window.upsertActiveOrder === 'function') {
                window.upsertActiveOrder(newOrder);
            }
            showToast('La comanda quedo registrada. Imprimiendo ticket de cocina...', 'info');
            try {
                window.openTicketModal(newOrder, 'kitchen');
            } catch (e) {
                console.error('[POS] Error abriendo ticket modal:', e);
            }
            return;
        }

        newOrder.status = 'pendiente';
        newOrder.paid = true;
        newOrder.serviceState = initialServiceState;

        const success = await saveOrderOnServer(newOrder);
        if (!success) {
            showToast('Error al registrar cobro en el servidor.', 'error');
            return;
        }

        Notifications.notify(`💰 Venta cobrada: ${customerName}`, 'success');
        commitCart([]);
        resetCartInputs();
        if (typeof window.upsertActiveOrder === 'function') {
            window.upsertActiveOrder(newOrder);
        }
        showToast('Pedido cobrado. Imprimiendo comanda...', 'info');
        try {
            if (window.TicketPrinter) {
                window.TicketPrinter.printFull(newOrder);
            } else {
                window.openTicketModal(newOrder, 'full');
            }
        } catch (e) {
            console.error('Error abriendo ticket modal:', e);
            showToast('Venta registrada. Error al mostrar ticket.', 'warning');
        }
    }

    async function saveOrderOnServer(order) {
        try {
            const data = await AppApi.request('save_order', order);
            if (data.status !== 'success') {
                console.error('[ORDER] Server rejected order:', data);
            }
            return data.status === 'success';
        } catch (error) {
            console.error('[ORDER] saveOrderOnServer error:', error);
            if (window.OfflineQueue) {
                await OfflineQueue.enqueue(order);
                showToast('Sin conexión. Pedido en cola — se enviará cuando haya red.', 'warning');
                if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                    navigator.serviceWorker.ready.then(reg => {
                        if (reg.sync) reg.sync.register('replay-offline-orders').catch(() => {});
                    });
                }
            }
            return false;
        }
    }

    function resetCartInputs() {
        const tableSelect = document.getElementById('order-table-select');
        if (tableSelect) tableSelect.value = 'Llevar';
        const customWrapper = document.getElementById('custom-customer-name-wrapper');
        if (customWrapper) customWrapper.style.display = 'none';
        const customerName = document.getElementById('order-customer-name');
        if (customerName) customerName.value = '';
        const paymentSelect = document.getElementById('order-payment-select');
        if (paymentSelect) paymentSelect.value = 'efectivo';
        const mixedSection = document.getElementById('mixed-payment-section');
        if (mixedSection) mixedSection.style.display = 'none';
        const mixedEfectivo = document.getElementById('mixed-efectivo');
        if (mixedEfectivo) mixedEfectivo.value = '0';
        const mixedQr = document.getElementById('mixed-qr');
        if (mixedQr) mixedQr.value = '0';
    }

    function notify() {
        if (typeof window.notifyStateChanged === 'function') {
            window.notifyStateChanged();
            return;
        }
        if (typeof window.renderCurrentTab === 'function') {
            window.renderCurrentTab('pos');
        }
    }

    window.PosController = {
        init,
        setCategory,
        addToCart,
        addPlatoExtraToCart,
        addExtraToCart,
        removeCartItem,
        clearCart,
        checkoutOrder
    };
})(window);

window.setActiveCategoryTab = function(btnId) {
    if (!btnId) return;
    const btn = document.getElementById(btnId);
    if (!btn) return;
    const siblings = btn.parentElement ? btn.parentElement.querySelectorAll('button') : [];
    siblings.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
};
