// ==========================================================================
// PROMOS CONTROLLER — CRUD modal for promo plans, wired to descuentos subtab
// ==========================================================================

(function (window) {
    const Controller = {
        initialized: false,

        init() {
            if (this.initialized) return;
            this.initialized = true;

            document.getElementById('btn-create-promo-plan')?.addEventListener('click', () => this.openCreateModal());

            document.getElementById('btn-save-promo-plan')?.addEventListener('click', () => this.savePlan());

            document.getElementById('modal-promo-plan')?.addEventListener('click', (e) => {
                if (e.target.id === 'modal-promo-plan') this.closeModal();
            });

            document.getElementById('promo-plan-type')?.addEventListener('change', (e) => {
                this.updatePromoFormByType(e.target.value);
            });
            document.getElementById('btn-add-promo-group')?.addEventListener('click', () => this.addPromoGroup());
        },

        updatePromoFormByType(type) {
            const isPct = type === 'percentage';
            const isFixed = type === 'fixed';
            const isBuyX = type === 'buy_x_get_y';
            const isMenu = type === 'menu_price';

            const elPct = document.getElementById('promo-field-percentage');
            const elFixed = document.getElementById('promo-field-fixed');
            const elBuyMin = document.getElementById('promo-field-buyx-min');
            const elBuyFree = document.getElementById('promo-field-buyx-free');
            const elMenu = document.getElementById('promo-field-menu-price');

            if (elPct) elPct.style.display = isPct ? '' : 'none';
            if (elFixed) elFixed.style.display = isFixed ? '' : 'none';
            if (elBuyMin) elBuyMin.style.display = isBuyX ? '' : 'none';
            if (elBuyFree) elBuyFree.style.display = isBuyX ? '' : 'none';
            if (elMenu) elMenu.style.display = isMenu ? '' : 'none';

            const inputPct = document.getElementById('promo-plan-percentage');
            const inputFixed = document.getElementById('promo-plan-fixed-amount');
            const inputMenu = document.getElementById('promo-plan-menu-price');
            const inputMinQty = document.getElementById('promo-plan-min-qty');
            const inputFreeQty = document.getElementById('promo-plan-free-qty');

            if (inputPct) { inputPct.required = isPct; inputPct.min = 1; inputPct.max = 100; }
            if (inputFixed) { inputFixed.required = isFixed; inputFixed.min = 0.01; }
            if (inputMenu) { inputMenu.required = isMenu; inputMenu.min = 0; }
            if (inputMinQty) inputMinQty.required = isBuyX;
            if (inputFreeQty) inputFreeQty.required = isBuyX;
            document.querySelectorAll('.promo-group-free').forEach(el => { el.style.display = isBuyX ? '' : 'none'; });
        },

        catalogProducts() {
            const catalog = [{ product_id: '', product_type: 'almuerzo', product_name: 'Cualquier almuerzo' }];
            const add = (items, type) => (items || []).forEach(item => catalog.push({ product_id: item.id, product_type: type, product_name: item.name }));
            add(state.seconds, 'segundo');
            add(state.sopas, 'sopa');
            add(state.platosExtras, 'plato_extra');
            add(state.extras, 'extra');
            return catalog;
        },

        renderPromoGroups(groups = []) {
            const container = document.getElementById('promo-groups-container');
            if (!container) return;
            const normalized = groups.length ? groups : [{ quantity_required: 1, free_quantity: 0, products: [] }];
            container.innerHTML = '';
            normalized.forEach((group, index) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'promo-group-card';
                wrapper.dataset.groupIndex = index;
                const selected = new Set((group.products || []).map(product => `${product.product_type}|${product.product_id || ''}`));
                const productsHtml = this.catalogProducts().map(product => {
                    const key = `${product.product_type}|${product.product_id || ''}`;
                    const checked = selected.has(key) ? ' checked' : '';
                    return `<label class="promo-product-option"><input type="checkbox" data-promo-product="1" data-product-id="${escapeHtml(product.product_id)}" data-product-type="${escapeHtml(product.product_type)}" data-product-name="${escapeHtml(product.product_name)}"${checked}> <span>${escapeHtml(product.product_name)}</span></label>`;
                }).join('');
                wrapper.innerHTML = `
                    <div class="promo-group-heading"><strong>Grupo ${index + 1}</strong>${normalized.length > 1 ? '<button type="button" class="btn btn-outline btn-sm promo-remove-group"><i class="fa-solid fa-trash"></i></button>' : ''}</div>
                    <div class="promo-group-fields">
                        <label class="form-group"><span class="label-overline">Cantidad requerida</span><input type="number" class="form-input promo-group-quantity" min="1" step="1" value="${Math.max(1, Number(group.quantity_required || 1))}"></label>
                        <label class="form-group promo-group-free"><span class="label-overline">Cantidad gratis</span><input type="number" class="form-input promo-group-free-quantity" min="0" step="1" value="${Math.max(0, Number(group.free_quantity || 0))}"></label>
                    </div>
                    <div class="promo-product-picker">${productsHtml}</div>`;
                wrapper.querySelector('.promo-remove-group')?.addEventListener('click', () => { wrapper.remove(); this.renumberPromoGroups(); });
                container.appendChild(wrapper);
            });
            this.updatePromoFormByType(document.getElementById('promo-plan-type').value);
        },

        renumberPromoGroups() {
            document.querySelectorAll('#promo-groups-container .promo-group-card').forEach((group, index) => {
                const title = group.querySelector('.promo-group-heading strong');
                if (title) title.textContent = `Grupo ${index + 1}`;
            });
        },

        addPromoGroup() {
            const groups = this.readPromoGroups();
            groups.push({ quantity_required: 1, free_quantity: 0, products: [] });
            this.renderPromoGroups(groups);
        },

        readPromoGroups() {
            return Array.from(document.querySelectorAll('#promo-groups-container .promo-group-card')).map(group => ({
                quantity_required: Math.max(1, parseInt(group.querySelector('.promo-group-quantity')?.value, 10) || 1),
                free_quantity: Math.max(0, parseInt(group.querySelector('.promo-group-free-quantity')?.value, 10) || 0),
                products: Array.from(group.querySelectorAll('[data-promo-product]:checked')).map(input => ({
                    product_id: input.dataset.productId || null,
                    product_type: input.dataset.productType,
                    product_name: input.dataset.productName
                }))
            }));
        },

        resetForm() {
            document.getElementById('promo-plan-id').value = '';
            document.getElementById('promo-plan-name').value = '';
            document.getElementById('promo-plan-type').value = 'percentage';
            document.getElementById('promo-plan-description').value = '';
            document.getElementById('promo-plan-coupon').value = '';
            document.getElementById('promo-plan-priority').value = '0';
            document.getElementById('promo-plan-min-subtotal').value = '';
            document.getElementById('promo-plan-max-global').value = '';
            document.getElementById('promo-plan-max-customer').value = '';
            document.getElementById('promo-plan-start-date').value = '';
            document.getElementById('promo-plan-end-date').value = '';
            document.getElementById('promo-plan-start-hour').value = '';
            document.getElementById('promo-plan-end-hour').value = '';
            document.getElementById('promo-plan-active').checked = true;
            document.getElementById('promo-plan-respect-open').checked = false;
            document.getElementById('promo-plan-stackable').checked = false;
            const inputPct = document.getElementById('promo-plan-percentage');
            const inputFixed = document.getElementById('promo-plan-fixed-amount');
            const inputMenu = document.getElementById('promo-plan-menu-price');
            const inputMinQty = document.getElementById('promo-plan-min-qty');
            const inputFreeQty = document.getElementById('promo-plan-free-qty');
            if (inputPct) inputPct.value = '';
            if (inputFixed) inputFixed.value = '';
            if (inputMenu) inputMenu.value = '';
            if (inputMinQty) inputMinQty.value = '2';
            if (inputFreeQty) inputFreeQty.value = '1';
            ['pos', 'mesa', 'llevar', 'delivery', 'reserva'].forEach(ch => {
                const cb = document.getElementById(`promo-ch-${ch}`);
                if (cb) cb.checked = true;
            });
            this.renderPromoGroups();
        },

        openCreateModal() {
            this.resetForm();
            this.updatePromoFormByType('percentage');
            document.getElementById('modal-promo-plan-title').innerHTML = '<i class="fa-solid fa-tags"></i> Nueva Promoción';
            openModal('modal-promo-plan');
        },

        openEditModal(plan) {
            document.getElementById('promo-plan-id').value = plan.id || '';
            document.getElementById('promo-plan-name').value = plan.name || '';
            document.getElementById('promo-plan-type').value = plan.type || 'percentage';
            document.getElementById('promo-plan-description').value = plan.description || '';
            document.getElementById('promo-plan-coupon').value = plan.coupon_code || '';
            document.getElementById('promo-plan-priority').value = plan.priority || '0';
            document.getElementById('promo-plan-min-subtotal').value = plan.min_subtotal || '';
            document.getElementById('promo-plan-max-global').value = plan.max_global_uses || '';
            document.getElementById('promo-plan-max-customer').value = plan.max_customer_uses || '';
            document.getElementById('promo-plan-start-date').value = plan.start_date || '';
            document.getElementById('promo-plan-end-date').value = plan.end_date || '';
            document.getElementById('promo-plan-start-hour').value = plan.start_hour || '';
            document.getElementById('promo-plan-end-hour').value = plan.end_hour || '';
            document.getElementById('promo-plan-active').checked = Number(plan.active) === 1;
            document.getElementById('promo-plan-respect-open').checked = Number(plan.respetar_cotizaciones_abiertas) === 1;
            document.getElementById('promo-plan-stackable').checked = Number(plan.stackable) === 1;
            document.getElementById('promo-plan-min-qty').value = plan.min_quantity || '2';
            document.getElementById('promo-plan-free-qty').value = plan.free_quantity || '1';

            const channels = promoParseChannels(plan.channels);
            ['pos', 'mesa', 'llevar', 'delivery', 'reserva'].forEach(ch => {
                const cb = document.getElementById(`promo-ch-${ch}`);
                if (cb) cb.checked = channels.includes(ch);
            });

            const type = plan.type || 'percentage';
            this.updatePromoFormByType(type);
            this.renderPromoGroups(plan.groups || []);

            if (type === 'percentage') {
                document.getElementById('promo-plan-percentage').value = plan.value || '';
            } else if (type === 'fixed') {
                document.getElementById('promo-plan-fixed-amount').value = plan.value || '';
            } else if (type === 'menu_price') {
                document.getElementById('promo-plan-menu-price').value = plan.value || '';
            }

            document.getElementById('modal-promo-plan-title').innerHTML = '<i class="fa-solid fa-tags"></i> Editar Promoción';
            openModal('modal-promo-plan');
        },

        getFormValue() {
            const type = document.getElementById('promo-plan-type').value;
            let value = 0;

            if (type === 'percentage') {
                value = parseFloat(document.getElementById('promo-plan-percentage').value) || 0;
            } else if (type === 'fixed') {
                value = parseFloat(document.getElementById('promo-plan-fixed-amount').value) || 0;
            } else if (type === 'menu_price') {
                value = parseFloat(document.getElementById('promo-plan-menu-price').value) || 0;
            } else if (type === 'buy_x_get_y') {
                value = parseInt(document.getElementById('promo-plan-min-qty').value, 10) || 0;
            }
            return value;
        },

        async savePlan() {
            const name = document.getElementById('promo-plan-name').value.trim();
            const type = document.getElementById('promo-plan-type').value;
            if (!name) return showToast('Ingrese un nombre para la promoción.', 'warning');

            const value = this.getFormValue();
            if (type !== 'buy_x_get_y' && (isNaN(value) || value <= 0)) {
                return showToast('Ingrese un valor válido para la promoción.', 'warning');
            }

            const channels = [];
            ['pos', 'mesa', 'llevar', 'delivery', 'reserva'].forEach(ch => {
                const cb = document.getElementById(`promo-ch-${ch}`);
                if (cb && cb.checked) channels.push(ch);
            });

            const groups = this.readPromoGroups();
            if (!groups.length || groups.some(group => !group.products.length)) {
                return showToast('Seleccione productos para cada grupo de la promoción.', 'warning');
            }
            if (type === 'buy_x_get_y') {
                const minQuantity = parseInt(document.getElementById('promo-plan-min-qty').value, 10) || 0;
                const freeQuantity = parseInt(document.getElementById('promo-plan-free-qty').value, 10) || 0;
                if (groups.length !== 1) return showToast('Compra X y lleva Y debe utilizar un solo grupo de productos.', 'warning');
                if (minQuantity < 1 || freeQuantity < 1) return showToast('Compra X y lleva Y requiere cantidades mayores que cero.', 'warning');
                groups[0].quantity_required = minQuantity;
                groups[0].free_quantity = freeQuantity;
            }

            const startDate = document.getElementById('promo-plan-start-date').value;
            const endDate = document.getElementById('promo-plan-end-date').value;
            const startHour = document.getElementById('promo-plan-start-hour').value;
            const endHour = document.getElementById('promo-plan-end-hour').value;
            if (!startDate || !endDate) {
                return showToast('La fecha de inicio y la fecha de fin son obligatorias.', 'warning');
            }
            if (endDate < startDate) {
                return showToast('La fecha de fin no puede ser anterior a la fecha de inicio.', 'warning');
            }
            if ((startHour && !endHour) || (!startHour && endHour)) {
                return showToast('Complete ambas horas o déjelas vacías para aplicar la promoción las 24 horas.', 'warning');
            }

            const plan = {
                id: document.getElementById('promo-plan-id').value || null,
                name,
                type,
                value,
                description: document.getElementById('promo-plan-description').value.trim(),
                coupon_code: document.getElementById('promo-plan-coupon').value.trim() || null,
                priority: parseInt(document.getElementById('promo-plan-priority').value, 10) || 0,
                channels: JSON.stringify(channels),
                min_subtotal: parseFloat(document.getElementById('promo-plan-min-subtotal').value) || 0,
                max_global_uses: parseInt(document.getElementById('promo-plan-max-global').value, 10) || 0,
                max_customer_uses: parseInt(document.getElementById('promo-plan-max-customer').value, 10) || 0,
                start_date: startDate,
                end_date: endDate,
                start_hour: startHour || null,
                end_hour: endHour || null,
                active: document.getElementById('promo-plan-active').checked ? 1 : 0,
                respetar_cotizaciones_abiertas: document.getElementById('promo-plan-respect-open').checked ? 1 : 0,
                stackable: document.getElementById('promo-plan-stackable').checked ? 1 : 0,
                min_quantity: type === 'buy_x_get_y' ? parseInt(document.getElementById('promo-plan-min-qty').value, 10) : 0,
                free_quantity: type === 'buy_x_get_y' ? parseInt(document.getElementById('promo-plan-free-qty').value, 10) : 0,
                groups
            };

            try {
                const response = await AppApi.savePromoPlan(plan);
                if (!response || response.status !== 'success') {
                    throw new Error(response?.message || 'No se pudo guardar la promoción.');
                }
                showToast('Promoción guardada correctamente.', 'success');
                this.closeModal();
                await loadStateForTab('menu-config');
                renderMenuConfig();
            } catch (error) {
                showToast(error.message || 'No se pudo guardar la promoción.', 'error');
            }
        },

        closeModal() {
            closeModal('modal-promo-plan');
        }
    };

    window.PromoController = Controller;
})(window);

window.editPromoPlan = async function (planId) {
    const plan = (state.promoPlans || []).find(p => p.id === planId);
    if (plan) {
        window.PromoController.openEditModal(plan);
    }
};

window.deletePromoPlan = async function (planId) {
    const plan = (state.promoPlans || []).find(p => p.id === planId);
    if (!plan) return;
    if (!(await ConfirmDialog.show(`¿Eliminar la promoción "${plan.name}"?`, { title: 'Eliminar promoción', type: 'danger', confirmText: 'Eliminar' }))) return;
    try {
        await AppApi.deletePromoPlan(planId);
        showToast('Promoción eliminada.', 'success');
        await loadStateForTab('menu-config');
        renderMenuConfig();
    } catch (error) {
        showToast(error.message || 'No se pudo eliminar la promoción.', 'error');
    }
};

window.closePromoPlanModal = function () {
    window.PromoController.closeModal();
};
