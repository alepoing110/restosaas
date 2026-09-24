// ==========================================================================
// PROMOS VIEW — renders promo plans list in menu-config descuentos subtab
// ==========================================================================

function renderPromoPlans() {
    const tbody = document.getElementById('promo-plans-body');
    const emptyState = document.getElementById('promo-empty-plans');
    if (!tbody) return;

    const plans = (state.promoPlans || []);
    const activePlans = plans.filter(p => Number(p.active) === 1);
    const couponPlans = plans.filter(p => p.coupon_code && p.coupon_code.trim() !== '');
    const autoPlans = plans.filter(p => !p.coupon_code || p.coupon_code.trim() === '');

    const kpiActive = document.getElementById('promo-kpi-active');
    const kpiCoupons = document.getElementById('promo-kpi-coupons');
    const kpiAuto = document.getElementById('promo-kpi-auto');
    if (kpiActive) kpiActive.textContent = activePlans.length;
    if (kpiCoupons) kpiCoupons.textContent = couponPlans.length;
    if (kpiAuto) kpiAuto.textContent = autoPlans.length;

    tbody.innerHTML = '';

    if (plans.length === 0) {
        if (emptyState) emptyState.style.display = '';
        return;
    }
    if (emptyState) emptyState.style.display = 'none';

    plans.forEach(plan => {
        const tr = document.createElement('tr');
        const isActive = Number(plan.active) === 1;
        const statusBadge = isActive
            ? '<span class="badge badge-success" style="font-size:10px;">Activo</span>'
            : '<span class="badge badge-muted" style="font-size:10px;">Inactivo</span>';

        const typeLabels = { percentage: 'Porcentaje', fixed: 'Monto fijo', buy_x_get_y: 'Compra X lleva Y', menu_price: 'Precio menú' };
        const typeLabel = typeLabels[plan.type] || plan.type;
        const valueDisplay = plan.type === 'percentage'
            ? `${plan.value}%`
            : plan.type === 'buy_x_get_y'
                ? `Compra ${Number(plan.min_quantity || 0)}, lleva ${Number(plan.free_quantity || 0)}`
                : `Bs ${Number(plan.value).toFixed(2)}`;

        const channels = promoParseChannels(plan.channels);
        const channelBadges = channels.map(c => `<span class="badge badge-outline" style="font-size:9px;">${promoChannelLabel(c)}</span>`).join(' ');

        const startDate = plan.start_date || '';
        const endDate = plan.end_date || '';
        const vigencia = startDate && endDate
            ? `${startDate} — ${endDate}`
            : startDate
                ? `Desde ${startDate}`
                : endDate
                    ? `Hasta ${endDate}`
                    : 'Sin vigencia';

        const targetNames = (plan.groups || []).map(group => {
            const names = (group.products || []).map(product => product.product_name).join(' / ');
            const qty = Number(group.quantity_required || 1) + Number(group.free_quantity || 0);
            return names ? `${qty} x ${names}` : '';
        }).filter(Boolean).join(' + ');
        const targetBadge = targetNames ? `<div style="font-size:10px;color:var(--text-muted);margin-top:3px;">${escapeHtml(targetNames)}</div>` : '';
        const couponBadge = plan.coupon_code
            ? `<span class="badge badge-accent" style="font-size:9px; margin-left:4px;">${escapeHtml(plan.coupon_code)}</span>`
            : '';

        tr.innerHTML = `
            <td><strong>${escapeHtml(plan.name)}</strong>${couponBadge}${targetBadge}</td>
            <td style="font-size:11px;">${typeLabel}</td>
            <td style="font-size:11px;">${valueDisplay}</td>
            <td style="font-size:10px;">${channelBadges || '<span class="text-muted">Todos</span>'}</td>
            <td style="font-size:10px; color:var(--text-muted);">${vigencia}</td>
            <td>${statusBadge}</td>
            <td style="text-align:right;">
                <button class="btn-table-action edit" onclick="editPromoPlan('${plan.id}')" title="Editar">
                    <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <button class="btn-table-action delete" onclick="deletePromoPlan('${plan.id}')" title="Eliminar">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function promoParseChannels(channelsRaw) {
    if (!channelsRaw) return ['pos', 'mesa', 'llevar', 'delivery', 'reserva'];
    if (Array.isArray(channelsRaw)) return channelsRaw;
    try { return JSON.parse(channelsRaw); } catch (_) { return channelsRaw.split(',').map(s => s.trim()).filter(Boolean); }
}

function promoChannelLabel(channel) {
    const labels = { pos: 'POS', mesa: 'Mesa', llevar: 'Llevar', delivery: 'Delivery', reserva: 'Reserva' };
    return labels[channel] || channel;
}
