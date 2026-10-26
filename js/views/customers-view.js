// ===========================================================================
// CUSTOMERS CRM VIEW
// ===========================================================================

function customerDate(value) {
    if (!value) return 'Sin registro';
    const date = new Date(value.replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('es-BO');
}

function renderCustomers() {
    const tbody = document.getElementById('customers-table-body');
    const empty = document.getElementById('customers-empty');
    if (!tbody) return;
    const query = (document.getElementById('customers-search')?.value || '').trim().toLowerCase();
    const customers = (state.customers || []).filter(customer => {
        const matchesSearch = !query || [customer.name, customer.phone, customer.whatsapp_phone].some(value => String(value || '').toLowerCase().includes(query));
        const lastOrder = customer.last_order_at ? new Date(customer.last_order_at.replace(' ', 'T')) : null;
        const segment = state.customerSegment || 'all';
        const matchesSegment = segment === 'all' || (segment === 'recurrentes' && Number(customer.order_count) >= 2) || (segment === 'inactivos' && (!lastOrder || lastOrder < new Date(Date.now() - 30 * 86400000))) || (segment === 'deudores' && Number(customer.receivable_balance) > 0);
        return matchesSearch && matchesSegment;
    });
    tbody.innerHTML = customers.map(customer => `<tr>
        <td><strong>${escapeHtml(customer.name)}</strong>${customer.marketing_opt_in ? '<br><small style="color:var(--success);">Promociones autorizadas</small>' : ''}</td>
        <td>${customer.phone ? escapeHtml(customer.phone) : '<span class="text-muted">Sin teléfono</span>'}${customer.whatsapp_phone && customer.whatsapp_phone !== customer.phone ? `<br><small>WhatsApp: ${escapeHtml(customer.whatsapp_phone)}</small>` : ''}</td>
        <td style="text-align:center">${customer.order_count || 0}</td><td style="text-align:right">${formatCurrency(customer.total_spent || 0)}</td>
        <td style="text-align:right; color:${Number(customer.receivable_balance) > 0 ? 'var(--danger)' : 'inherit'}">${formatCurrency(customer.receivable_balance || 0)}</td>
        <td>${customerDate(customer.last_order_at)}</td>
        <td style="white-space:nowrap"><button class="btn btn-sm btn-outline" onclick="openCustomerProfile('${customer.id}')" title="Ver ficha"><i class="fa-solid fa-id-card"></i></button> <button class="btn btn-sm btn-outline" onclick="openCustomerForm('${customer.id}')" title="Editar"><i class="fa-solid fa-pen"></i></button></td></tr>`).join('');
    if (empty) empty.style.display = customers.length ? 'none' : '';
    populatePosCustomerSelect();
    if (typeof window.populateReservationCustomerSelect === 'function') populateReservationCustomerSelect();
    renderCrmConversations();
}

function renderCrmConversations() {
    const list = document.getElementById('crm-conversations-list');
    const messages = document.getElementById('crm-conversation-messages');
    const reply = document.getElementById('crm-human-reply');
    if (!list || !messages) return;
    const conversations = state.crmConversations || [];
    const selectedId = state.selectedCrmConversationId;
    list.innerHTML = conversations.length ? conversations.map(conversation => `<div class="content-card" style="margin-bottom:8px;border:${selectedId === conversation.id ? '2px solid var(--primary)' : ''}"><button style="background:none;border:0;padding:0;width:100%;text-align:left;cursor:pointer" onclick="selectCrmConversation('${conversation.id}')"><strong>${escapeHtml(conversation.customer_name || conversation.wa_phone)}</strong><br><small>${escapeHtml(conversation.wa_phone || '')} · ${conversation.attention_mode === 'humano' ? 'Atención humana' : 'Bot activo'}</small></button>${conversation.attention_mode !== 'humano' ? `<button class="btn btn-sm btn-outline" style="margin-top:8px" onclick="takeCrmConversation('${conversation.id}')"><i class="fa-solid fa-hand"></i> Tomar atención</button>` : ''}</div>`).join('') : '<p class="text-muted">No hay conversaciones.</p>';
    const selected = conversations.find(conversation => conversation.id === selectedId);
    const history = state.crmConversationMessages || [];
    messages.innerHTML = selected ? (history.length ? history.map(message => `<div class="content-card" style="margin-bottom:8px"><strong>${message.role === 'user' ? 'Cliente' : (message.tool_result?.human ? 'Atención humana' : 'Bot')}</strong><small style="float:right">${customerDate(message.created_at)}</small><div style="white-space:pre-wrap;margin-top:6px">${escapeHtml(message.content || '')}</div></div>`).join('') : '<p class="text-muted">Sin mensajes.</p>') : '<p class="text-muted">Selecciona una conversación.</p>';
    if (reply) reply.style.display = selected?.attention_mode === 'humano' ? '' : 'none';
}

function customerActivity(profile) {
    const { orders = [], reservations = [], receivables = [], conversations = [], interactions = [] } = profile;
    return [
        ...orders.map(order => ({ date: order.occurred_at, icon: 'receipt', text: `Pedido ${order.id.slice(-6).toUpperCase()} · ${formatCurrency(order.total)} · ${escapeHtml(order.delivery_type || '')}` })),
        ...reservations.map(reservation => ({ date: `${reservation.reservation_date} ${reservation.reservation_time}`, icon: 'calendar-check', text: `Reserva ${reservation.status} · ${formatCurrency(reservation.total)} · ${escapeHtml(reservation.delivery_type)}` })),
        ...receivables.map(receivable => ({ date: receivable.due_date, icon: 'file-invoice-dollar', text: `Cuenta ${receivable.status} · Saldo ${formatCurrency(receivable.balance)}` })),
        ...conversations.map(conversation => ({ date: conversation.updated_at, icon: 'comments', text: `Conversación WhatsApp ${conversation.status}` })),
        ...interactions.map(interaction => ({ date: interaction.occurred_at, icon: interaction.type === 'llamada' ? 'phone' : 'note-sticky', text: `${interaction.type}: ${escapeHtml(interaction.content)}` }))
    ].sort((a, b) => String(b.date || '').localeCompare(String(a.date || ''))).slice(0, 20);
}

function renderCustomerProfile() {
    const container = document.getElementById('customer-profile');
    const profile = state.selectedCustomerProfile;
    if (!container) return;
    if (!profile) { container.style.display = 'none'; return; }
    const { customer, summary, receivables = [], collection_tasks: tasks = [] } = profile;
    const items = customerActivity(profile);
    const taskHtml = tasks.map(task => `<div class="financial-breakdown-item"><span>${escapeHtml(task.note)}<br><small>${escapeHtml(task.status)}${task.due_date ? ' · ' + customerDate(task.due_date) : ''}</small></span>${!['completada', 'cancelada'].includes(task.status) ? `<button class="btn btn-sm btn-outline" onclick="completeCollectionTask('${customer.id}', '${task.id}')">Completar</button>` : ''}</div>`).join('');
    const receivableOptions = receivables.filter(item => Number(item.balance) > 0).map(item => `<option value="${item.id}">${item.id.slice(-6).toUpperCase()} · ${formatCurrency(item.balance)}</option>`).join('');
    container.style.display = '';
    container.innerHTML = `<div class="panel-header-actions"><div><h3 class="panel-title"><i class="fa-solid fa-id-card"></i> ${escapeHtml(customer.name)}</h3><p class="panel-subtitle">${escapeHtml(customer.phone || customer.whatsapp_phone || 'Sin teléfono')} · ${escapeHtml(customer.email || 'Sin correo')}</p></div><button class="btn btn-sm btn-outline" onclick="closeCustomerProfile()"><i class="fa-solid fa-xmark"></i> Cerrar</button></div>
        <div class="financial-kpi-grid"><div class="financial-kpi"><span>Compras</span><strong>${summary.order_count || 0}</strong><small>Pedidos vinculados</small></div><div class="financial-kpi financial-kpi--sales"><span>Gasto acumulado</span><strong>${formatCurrency(summary.total_spent || 0)}</strong><small>Ventas no anuladas</small></div><div class="financial-kpi ${Number(summary.receivable_balance) > 0 ? 'financial-kpi--expense' : ''}"><span>Saldo pendiente</span><strong>${formatCurrency(summary.receivable_balance || 0)}</strong><small>Límite: ${formatCurrency(customer.credit_limit || 0)}</small></div><div class="financial-kpi"><span>Última compra</span><strong style="font-size:18px">${customerDate(summary.last_order_at)}</strong><small>Actividad comercial</small></div></div>
        <div style="margin-top:16px"><h4 class="panel-title">Actividad reciente</h4>${items.length ? `<div class="financial-breakdown-list">${items.map(item => `<div class="financial-breakdown-item"><span><i class="fa-solid fa-${item.icon}" style="margin-right:8px"></i>${item.text}</span><small>${customerDate(item.date)}</small></div>`).join('')}</div>` : '<p class="text-muted">Aún no hay actividad vinculada a esta ficha.</p>'}</div>
        <div class="financial-grid" style="margin-top:16px"><div><h4 class="panel-title">Nota o contacto</h4><textarea id="customer-interaction-content" class="form-input" rows="3" placeholder="Registrar una nota, llamada, WhatsApp o gestión de cobranza"></textarea><div style="display:flex; gap:8px; margin-top:8px"><select id="customer-interaction-type" class="form-select"><option value="nota">Nota</option><option value="llamada">Llamada</option><option value="whatsapp">WhatsApp</option><option value="cobranza">Cobranza</option></select><button class="btn btn-sm btn-primary" onclick="saveCustomerInteraction('${customer.id}')"><i class="fa-solid fa-plus"></i> Registrar</button></div></div><div><h4 class="panel-title">Seguimiento de cobranza</h4><select id="customer-task-receivable" class="form-select"><option value="">Sin cuenta específica</option>${receivableOptions}</select><div style="display:flex;gap:8px;margin-top:8px"><input id="customer-task-date" class="form-input" type="date"><input id="customer-task-note" class="form-input" placeholder="Ej: Llamar para confirmar pago"></div><button class="btn btn-sm btn-primary" style="margin-top:8px" onclick="saveCollectionTask('${customer.id}')"><i class="fa-solid fa-calendar-plus"></i> Crear tarea</button>${taskHtml ? `<div class="financial-breakdown-list" style="margin-top:10px">${taskHtml}</div>` : ''}</div></div>`;
}

window.renderCustomers = renderCustomers;
window.renderCustomerProfile = renderCustomerProfile;
window.renderCrmConversations = renderCrmConversations;
