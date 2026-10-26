// ==========================================================================
// SCREEN: SAAS ADMIN VIEW
// ==========================================================================

function renderSaasAdmin() {
    try { renderSaasSummary(); } catch (e) { console.error('renderSaasSummary error:', e); }
    try { renderSaasPendingPayments(); } catch (e) { console.error('renderSaasPendingPayments error:', e); }
    try { renderSaasTenantsTable(); } catch (e) { console.error('renderSaasTenantsTable error:', e); }
    try { renderSaasBranchesTable(); } catch (e) { console.error('renderSaasBranchesTable error:', e); }
    try { renderSaasUsersTable(); } catch (e) { console.error('renderSaasUsersTable error:', e); }
    try { renderSaasPlansTable(); } catch (e) { console.error('renderSaasPlansTable error:', e); }
    try { renderSaasChatbotConversations(); } catch (e) { console.error('renderSaasChatbotConversations error:', e); }
    try { renderSaasChatbotMessages(); } catch (e) { console.error('renderSaasChatbotMessages error:', e); }
    try { renderSaasChatbotSimMessages(); } catch (e) { console.error('renderSaasChatbotSimMessages error:', e); }
    try { populateSaasTenantOptions(); } catch (e) { console.error('populateSaasTenantOptions error:', e); }
    try { populateSaasSubscriptionPlanOptions(); } catch (e) { console.error('populateSaasSubscriptionPlanOptions error:', e); }
    initSaasSubtabs();

    const activeTab = window.activeSaasSubtab || 'summary';
    if (typeof window.switchSaasSubtab === 'function') {
        window.switchSaasSubtab(activeTab);
    }
}

function renderSaasChatbotConversations() {
    const container = document.getElementById('saas-chatbot-conversations-list');
    if (!container) return;

    const conversations = state.saasAdmin?.chatbotConversations || [];
    const selectedId = state.saasAdmin?.chatbotSelectedConversationId || '';
    if (!conversations.length) {
        container.innerHTML = '<div class="saas-empty-panel"><i class="fa-solid fa-comment-slash"></i><strong>Sin conversaciones</strong><span>Cuando entren mensajes por WhatsApp aparecerán aquí.</span></div>';
        return;
    }

    container.innerHTML = conversations.map((conv) => {
        const active = conv.id === selectedId;
        const statusClass = conv.status === 'active' ? 'badge-success' : 'badge-secondary';
        const ctx = conv.context || {};
        const summary = [ctx.reservation_date, ctx.reservation_time].filter(Boolean).join(' · ');
        return `<button type="button" class="content-card" onclick="selectChatbotConversation('${conv.id}')" style="width:100%; text-align:left; margin-bottom:10px; border:${active ? '2px solid var(--brand-primary, #6c63ff)' : '1px solid var(--border-color, #2f3544)'}; background:${active ? 'rgba(108,99,255,.08)' : 'var(--surface-elevated, transparent)'};">
            <div style="display:flex; justify-content:space-between; gap:8px; align-items:start;">
                <div style="flex:1; min-width:0;">
                    <strong>${escapeHtml(conv.customer_name || conv.wa_phone || 'Cliente')}</strong>
                    <div class="saas-muted-line">${escapeHtml(conv.wa_phone || '-')}</div>
                </div>
                <div style="display:flex; gap:4px; align-items:center; flex-shrink:0;">
                    <span class="badge ${statusClass}">${escapeHtml(conv.status || 'active')}</span>
                    <button type="button" onclick="event.stopPropagation(); deleteChatbotConversation('${conv.id}')" title="Eliminar conversación" style="background:none; border:none; color:var(--text-muted, #888); cursor:pointer; padding:2px 4px; font-size:12px; line-height:1;"><i class="fa-solid fa-trash"></i></button>
                </div>
            </div>
            <div class="saas-muted-line" style="margin-top:8px;">${escapeHtml(summary || 'Sin reserva vinculada todavía')}</div>
        </button>`;
    }).join('');
}

function renderSaasChatbotMessages() {
    const container = document.getElementById('saas-chatbot-messages-panel');
    if (!container) return;

    const selectedId = state.saasAdmin?.chatbotSelectedConversationId || '';
    const messages = state.saasAdmin?.chatbotMessages || [];
    if (!selectedId) {
        container.innerHTML = '<div class="saas-empty-panel"><i class="fa-solid fa-message"></i><strong>Selecciona una conversación</strong><span>Verás aquí el historial del chatbot.</span></div>';
        return;
    }

    if (!messages.length) {
        container.innerHTML = '<div class="saas-empty-panel"><i class="fa-solid fa-inbox"></i><strong>Sin mensajes</strong><span>Esta conversación no tiene mensajes cargados.</span></div>';
        return;
    }

    container.innerHTML = messages.map((message) => {
        const isUser = message.role === 'user';
        const bg = isUser ? 'rgba(40,167,69,.12)' : 'rgba(108,99,255,.10)';
        const border = isUser ? 'rgba(40,167,69,.35)' : 'rgba(108,99,255,.35)';
        const label = isUser ? 'Cliente' : (message.role === 'assistant' ? 'Bot' : message.role);
        const toolMeta = Array.isArray(message.tool_calls) && message.tool_calls.length ? `<div class="saas-muted-line" style="margin-top:6px;">Tool: ${escapeHtml((message.tool_calls[0]?.function?.name) || 'tool')}</div>` : '';
        return `<div class="content-card" style="margin-bottom:10px; background:${bg}; border:1px solid ${border};">
            <div style="display:flex; justify-content:space-between; gap:8px; align-items:center; margin-bottom:8px;">
                <strong>${escapeHtml(label)}</strong>
                <small class="saas-muted-line">${escapeHtml(message.created_at || '')}</small>
            </div>
            <div style="white-space:pre-wrap; line-height:1.45;">${escapeHtml(message.content || '')}</div>
            ${toolMeta}
        </div>`;
    }).join('');
}

function renderSaasChatbotSimMessages() {
    const container = document.getElementById('chatbot-sim-messages');
    if (!container) return;

    const messages = state.saasAdmin?.chatbotSimMessages || [];
    if (!messages.length) {
        container.innerHTML = '<div class="saas-empty-panel"><i class="fa-solid fa-flask"></i><strong>Simulador listo</strong><span>Escribe un mensaje para simular una conversación del chatbot.</span></div>';
        return;
    }

    container.innerHTML = messages.map((msg) => {
        const isUser = msg.role === 'user';
        const isError = msg.role === 'error';
        const isLoading = msg.role === 'loading';
        const bg = isError ? 'rgba(220,53,69,.12)' : isLoading ? 'rgba(108,99,255,.05)' : isUser ? 'rgba(40,167,69,.12)' : 'rgba(108,99,255,.10)';
        const border = isError ? 'rgba(220,53,69,.35)' : isLoading ? 'rgba(108,99,255,.2)' : isUser ? 'rgba(40,167,69,.35)' : 'rgba(108,99,255,.35)';
        const label = isError ? 'Error' : isLoading ? 'Bot' : (isUser ? 'Tú (simulador)' : 'Bot');
        const toolResult = msg.tool_result;
        let toolMeta = '';

        if (isLoading) {
            return `<div class="content-card" style="margin-bottom:10px; background:${bg}; border:1px solid ${border};">
                <div style="display:flex; align-items:center; gap:8px; padding:4px 0;">
                    <div class="spinner-sm" style="width:16px; height:16px; border:2px solid var(--brand-primary, #6c63ff); border-top-color:transparent; border-radius:50%; animation:spin .6s linear infinite;"></div>
                    <strong style="font-size:13px;">${escapeHtml(label)}</strong>
                    <span class="saas-muted-line" style="font-size:12px;">pensando...</span>
                </div>
            </div>`;
        }

        if (toolResult) {
            if (toolResult.items && Array.isArray(toolResult.items)) {
                const items = toolResult.items;
                const itemList = items.map(i => `<div style="display:flex; justify-content:space-between; padding:3px 0; border-bottom:1px solid rgba(255,255,255,.06);"><span>${escapeHtml(i.name)}</span><span style="color:var(--brand-primary, #6c63ff); font-weight:600;">Bs ${Number(i.price).toFixed(2)}</span></div>`).join('');
                toolMeta = `<div style="margin-top:8px; padding:8px 10px; background:rgba(108,99,255,.06); border-radius:6px; font-size:12px; border-left:3px solid var(--brand-primary, #6c63ff);">
                    <div style="font-weight:600; margin-bottom:4px;"><i class="fa-solid fa-utensils" style="margin-right:4px;"></i> Menú (${items.length} platos)</div>
                    ${itemList}
                </div>`;
            } else if (toolResult.available !== undefined) {
                const label2 = toolResult.available ? 'Disponible: ' + (toolResult.table?.name || '-') : 'No disponible';
                toolMeta = `<div style="margin-top:8px; padding:6px 10px; background:rgba(108,99,255,.06); border-radius:6px; font-size:12px; border-left:3px solid var(--brand-primary, #6c63ff);">
                    <i class="fa-solid fa-table" style="margin-right:4px;"></i> ${escapeHtml(label2)}
                </div>`;
            } else if (toolResult.recommendations) {
                toolMeta = `<div style="margin-top:8px; padding:6px 10px; background:rgba(108,99,255,.06); border-radius:6px; font-size:12px; border-left:3px solid var(--brand-primary, #6c63ff);">
                    <i class="fa-solid fa-clock" style="margin-right:4px;"></i> Horarios: ${escapeHtml(toolResult.recommendations.map(r => r.time).join(', '))}
                </div>`;
            } else if (toolResult.reservation) {
                const r = toolResult.reservation;
                const items = r.items || [];
                const itemList = items.length ? items.map(i => `${i.name} x${i.quantity || 1}`).join(', ') : '';
                toolMeta = `<div style="margin-top:8px; padding:8px 10px; background:rgba(40,167,69,.08); border-radius:6px; font-size:12px; border-left:3px solid rgba(40,167,69,.5);">
                    <div style="font-weight:600; margin-bottom:4px;"><i class="fa-solid fa-check-circle" style="color:rgba(40,167,69,.8); margin-right:4px;"></i> Reserva Confirmada</div>
                    <div>Cliente: ${escapeHtml(r.customer_name)} | Tel: ${escapeHtml(r.phone)}</div>
                    <div>Fecha: ${escapeHtml(r.reservation_date)} ${escapeHtml(r.reservation_time)}</div>
                    <div>Mesa: ${escapeHtml(r.table?.name || 'Para llevar')}</div>
                    ${itemList ? `<div>Pedido: ${escapeHtml(itemList)}</div>` : ''}
                    <div style="font-weight:600; margin-top:4px;">Total: Bs ${Number(r.total || 0).toFixed(2)}</div>
                </div>`;
            }
        }

        const time = msg.created_at ? new Date(msg.created_at).toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit' }) : '';
        return `<div class="content-card" style="margin-bottom:10px; background:${bg}; border:1px solid ${border};">
            <div style="display:flex; justify-content:space-between; gap:8px; align-items:center; margin-bottom:8px;">
                <strong style="font-size:13px;">${escapeHtml(label)}</strong>
                <small class="saas-muted-line">${escapeHtml(time)}</small>
            </div>
            <div style="white-space:pre-wrap; line-height:1.45;">${escapeHtml(msg.content || '')}</div>
            ${toolMeta}
        </div>`;
    }).join('');

    container.scrollTop = container.scrollHeight;
}

function renderSaasPendingPayments() {
    const container = document.getElementById('saas-pending-payments');
    if (!container) return;

    const tenants = state.saasAdmin?.tenants || [];
    const filters = state.saasAdmin?.filters?.payments || {};
    const search = (filters.search || '').toLowerCase();
    const pending = tenants.filter(t => t.subscription_status === 'pending_payment' && (!search || `${t.name || ''} ${t.slug || ''} ${t.plan_code || ''}`.toLowerCase().includes(search)));

    if (pending.length === 0) {
        container.innerHTML = '<div class="saas-empty-panel"><i class="fa-solid fa-circle-check"></i><strong>No hay pagos pendientes</strong><span>Cuando un cliente solicite activación por pago manual aparecerá aquí.</span></div>';
        return;
    }

    container.innerHTML = pending.map(tenant => `
        <div class="pending-payment-card saas-payment-card">
            <div style="display:flex; justify-content:space-between; align-items:start; gap:12px;">
                <div>
                    <strong>${escapeHtml(tenant.name)}</strong>
                    <div class="saas-muted-line">${escapeHtml(tenant.slug)}</div>
                </div>
                <span class="badge badge-warning">Pendiente</span>
            </div>
            <div class="saas-payment-meta">
                <div><strong>Plan:</strong> ${escapeHtml(tenant.plan_code)}</div>
                <div><strong>Referencia:</strong> ${escapeHtml(tenant.payment_notes || '-')}</div>
            </div>
            <div class="saas-payment-actions">
                <button class="btn btn-primary btn-sm" onclick="approvePayment('${tenant.id}')">
                    <i class="fa-solid fa-check"></i> Aprobar
                </button>
                <button class="btn btn-outline btn-sm" onclick="rejectPayment('${tenant.id}')">
                    <i class="fa-solid fa-xmark"></i> Rechazar
                </button>
            </div>
        </div>
    `).join('');
}

function initSaasSubtabs() {
    const container = document.querySelector('.saas-subtabs');
    if (!container || container.dataset.delegated) return;
    container.dataset.delegated = 'true';
    container.addEventListener('click', (e) => {
        const btn = e.target.closest('.saas-subtab');
        if (!btn) return;
        const tabId = btn.dataset.saasTab;
        document.querySelectorAll('.saas-subtab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.saas-subtab-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        const panel = document.getElementById('saas-panel-' + tabId);
        if (panel) panel.classList.add('active');
    });
}

function renderSaasSummary() {
    const summary = state.saasAdmin?.summary || {};
    const map = {
        'saas-summary-tenants': summary.tenants || 0,
        'saas-summary-branches': summary.branches || 0,
        'saas-summary-users': summary.users || 0,
        'saas-summary-subscriptions': summary.activeSubscriptions || 0
    };

    Object.entries(map).forEach(([id, value]) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    });
}

function renderSaasEmptyState(message, detail, colspan) {
    return `<tr><td colspan="${colspan}"><div class="empty-table-state"><i class="fa-solid fa-circle-info"></i><p>${escapeHtml(message)}</p><span>${escapeHtml(detail || '')}</span></div></td></tr>`;
}

function getSaasStatusMeta(status) {
    const map = {
        active: { label: 'Activa', badge: 'badge-success' },
        trial: { label: 'Trial', badge: 'badge-info' },
        pending_payment: { label: 'Pago pendiente', badge: 'badge-warning' },
        past_due: { label: 'Vencida', badge: 'badge-warning' },
        suspended: { label: 'Suspendida', badge: 'badge-danger' }
    };
    return map[status] || { label: status || 'Activa', badge: 'badge-success' };
}

function renderUsagePill(current, limit) {
    const value = parseInt(current, 10) || 0;
    const max = parseInt(limit, 10) || 0;
    const formattedLimit = max >= 999 ? '∞' : (max || '-');
    return `<div class="usage-indicator saas-usage-pill"><span>${value}</span><small>/ ${formattedLimit}</small></div>`;
}

function formatSaasDate(dateStr) {
    if (!dateStr) return '<span class="text-muted">-</span>';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return '<span class="text-muted">-</span>';
    const day = d.getDate().toString().padStart(2, '0');
    const month = (d.getMonth() + 1).toString().padStart(2, '0');
    const year = d.getFullYear();
    return `${day}/${month}/${year}`;
}

function getDaysRemaining(dateStr) {
    if (!dateStr) return null;
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return null;
    const now = new Date();
    const diff = Math.ceil((d - now) / (1000 * 60 * 60 * 24));
    return diff;
}

function renderSubscriptionDatesCell(tenant) {
    const days = getDaysRemaining(tenant.ends_at);
    const hasEnd = !!tenant.ends_at;

    let endClass = '';
    let remainingLabel = '';
    let endDisplay = '';

    if (!hasEnd) {
        endClass = 'saas-date-none';
        endDisplay = '<i class="fa-solid fa-infinity"></i> Sin vencimiento';
        remainingLabel = '<small class="saas-date-badge info">Por definir</small>';
    } else {
        endDisplay = `<i class="fa-solid fa-flag-checkered"></i> ${formatSaasDate(tenant.ends_at)}`;
        if (days !== null) {
            if (days < 0) {
                endClass = 'saas-date-expired';
                remainingLabel = `<small class="saas-date-badge danger">Venció hace ${Math.abs(days)}d</small>`;
            } else if (days <= 7) {
                endClass = 'saas-date-urgent';
                remainingLabel = `<small class="saas-date-badge warning">Quedan ${days}d</small>`;
            } else {
                remainingLabel = `<small class="saas-date-badge ok">Quedan ${days}d</small>`;
            }
        }
    }

    return `<div class="saas-dates-cell">
        <span class="saas-date-start"><i class="fa-solid fa-play"></i> ${formatSaasDate(tenant.starts_at)}</span>
        <span class="saas-date-arrow"><i class="fa-solid fa-arrow-right"></i></span>
        <span class="saas-date-end ${endClass}">${endDisplay}</span>
        ${remainingLabel}
    </div>`;
}

function renderSaasTenantsTable() {
    const tbody = document.getElementById('saas-tenants-body');
    if (!tbody) return;

    let tenants = state.saasAdmin?.tenants || [];
    const searchQuery = state.saasAdmin?.searchQuery;
    const statusFilter = state.saasAdmin?.statusFilter;

    if (searchQuery) {
        tenants = tenants.filter(t => `${t.name || ''} ${t.slug || ''} ${t.owner_name || ''} ${t.owner_email || ''} ${t.plan_code || ''}`.toLowerCase().includes(searchQuery));
    }
    if (statusFilter && statusFilter !== 'all') {
        tenants = tenants.filter(t => t.subscription_status === statusFilter);
    }

    if (tenants.length === 0) {
        tbody.innerHTML = renderSaasEmptyState('No hay tenants que coincidan', 'Cambia los filtros o crea un tenant nuevo.', 7);
        return;
    }

    const editingId = state.saasAdmin?.editingTenantId;

    tbody.innerHTML = tenants.map(tenant => {
        if (editingId === tenant.id) {
            return `<tr>
                <td>
                    <input type="text" class="td-edit-input" id="edit-tenant-name-${tenant.id}" value="${escapeHtml(tenant.name)}">
                    <div style="font-size:11px; color: var(--text-muted);">${escapeHtml(tenant.slug)}</div>
                </td>
                <td>
                    <select class="td-edit-input" id="edit-tenant-business-${tenant.id}" style="width:auto;">
                        ${['restaurante','cafeteria','tienda','bar','otro'].map(bt => `<option value="${bt}" ${tenant.business_type === bt ? 'selected' : ''}>${bt}</option>`).join('')}
                    </select>
                </td>
                <td>
                    <select class="td-edit-input" id="edit-tenant-active-${tenant.id}" style="width:auto;">
                        <option value="1" ${tenant.active ? 'selected' : ''}>Activo</option>
                        <option value="0" ${!tenant.active ? 'selected' : ''}>Inactivo</option>
                    </select>
                </td>
                <td colspan="4" style="text-align:right;">
                    <button class="btn btn-primary btn-sm" onclick="saveTenantInline('${tenant.id}')" title="Guardar"><i class="fa-solid fa-check"></i></button>
                    <button class="btn btn-outline btn-sm" onclick="cancelTenantInline()" title="Cancelar"><i class="fa-solid fa-xmark"></i></button>
                </td>
            </tr>`;
        }

        const isSuspended = tenant.subscription_status === 'suspended';
        const status = getSaasStatusMeta(tenant.subscription_status || 'active');
        const maxBranches = tenant.plan ? tenant.plan.max_branches : null;
        const maxUsers = tenant.plan ? tenant.plan.max_users : null;

        return `<tr>
            <td>
                <div class="saas-entity-cell">
                    <span class="saas-entity-avatar"><i class="fa-solid fa-building"></i></span>
                    <div>
                        <strong>${escapeHtml(tenant.name)}</strong>
                        <small>${escapeHtml(tenant.slug)}</small>
                    </div>
                </div>
            </td>
            <td><strong>${tenant.plan ? escapeHtml(tenant.plan.name) : escapeHtml(tenant.plan_code || '-')}</strong><small class="saas-muted-line">${escapeHtml(tenant.business_type || 'restaurante')}</small></td>
            <td><span class="badge ${status.badge}">${escapeHtml(status.label)}</span></td>
            <td>${renderSubscriptionDatesCell(tenant)}</td>
            <td>${renderUsagePill(tenant.branches_count || 1, maxBranches)}</td>
            <td>${renderUsagePill(tenant.users_count || 1, maxUsers)}</td>
            <td style="text-align:right;">
                <div class="saas-plan-actions">
                    <button class="btn-table-action edit" onclick="impersonateTenant('${tenant.id}')" title="Ingresar como esta empresa"><i class="fa-solid fa-right-to-bracket"></i> Entrar</button>
                    <button class="btn-table-action edit" onclick="openSubscriptionEditor('${tenant.id}')" title="Editar suscripción"><i class="fa-solid fa-credit-card"></i> Plan</button>
                    <button class="btn-table-action edit" onclick="startEditTenant('${tenant.id}')" title="Editar tenant"><i class="fa-solid fa-pen"></i> Editar</button>
                    <button class="btn-table-action edit" onclick="toggleTenantStatus('${tenant.id}', '${isSuspended ? 'active' : 'suspended'}')" title="${isSuspended ? 'Activar suscripción' : 'Suspender suscripción'}"><i class="fa-solid ${isSuspended ? 'fa-play' : 'fa-pause'}"></i> ${isSuspended ? 'Activar' : 'Suspender'}</button>
                    <button class="btn-table-action delete" onclick="deleteTenant('${tenant.id}')" title="Eliminar tenant"><i class="fa-solid fa-trash"></i> Eliminar</button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

function renderSaasBranchesTable() {
    const tbody = document.getElementById('saas-branches-body');
    if (!tbody) return;

    const filters = state.saasAdmin?.filters?.branches || {};
    const search = (filters.search || '').toLowerCase();
    const branches = (state.saasAdmin?.branches || []).filter(branch => {
        const matchesSearch = !search || `${branch.name || ''} ${branch.tenant_name || ''}`.toLowerCase().includes(search);
        const matchesStatus = !filters.status || filters.status === 'all' || (filters.status === 'active' ? branch.active : !branch.active);
        return matchesSearch && matchesStatus;
    });
    if (branches.length === 0) {
        tbody.innerHTML = renderSaasEmptyState('No hay sucursales registradas', 'Crea una sucursal desde el formulario superior.', 5);
        return;
    }

    const editingId = state.saasAdmin?.editingBranchId;

    tbody.innerHTML = branches.map(branch => {
        if (editingId === branch.id) {
            return `<tr>
                <td><input type="text" class="td-edit-input" id="edit-branch-name-${branch.id}" value="${escapeHtml(branch.name)}"></td>
                <td>${escapeHtml(branch.tenant_name || '-')}</td>
                <td>
                    <select class="td-edit-input" id="edit-branch-active-${branch.id}" style="width:auto;">
                        <option value="1" ${branch.active ? 'selected' : ''}>Activa</option>
                        <option value="0" ${!branch.active ? 'selected' : ''}>Inactiva</option>
                    </select>
                </td>
                <td colspan="2" style="text-align:right;">
                    <button class="btn btn-primary btn-sm" onclick="saveBranchInline('${branch.id}')" title="Guardar"><i class="fa-solid fa-check"></i></button>
                    <button class="btn btn-outline btn-sm" onclick="cancelBranchInline()" title="Cancelar"><i class="fa-solid fa-xmark"></i></button>
                </td>
            </tr>`;
        }
        return `<tr>
            <td><div class="saas-entity-cell"><span class="saas-entity-avatar"><i class="fa-solid fa-store"></i></span><div><strong>${escapeHtml(branch.name)}</strong><small>${escapeHtml(branch.id)}</small></div></div></td>
            <td><strong>${escapeHtml(branch.tenant_name || '-')}</strong></td>
            <td><span class="badge ${branch.active ? 'badge-success' : 'badge-danger'}">${branch.active ? 'Activa' : 'Inactiva'}</span></td>
            <td><code>${escapeHtml(branch.id)}</code></td>
            <td style="text-align:right;">
                <div class="saas-plan-actions">
                    <button class="btn-table-action edit" onclick="startEditBranch('${branch.id}')" title="Editar sucursal"><i class="fa-solid fa-pen"></i> Editar</button>
                    <button class="btn-table-action delete" onclick="deleteBranch('${branch.id}')" title="Eliminar sucursal"><i class="fa-solid fa-trash"></i> Eliminar</button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

function renderSaasUsersTable() {
    const tbody = document.getElementById('saas-users-body');
    if (!tbody) return;

    const filters = state.saasAdmin?.filters?.users || {};
    const search = (filters.search || '').toLowerCase();
    const users = (state.saasAdmin?.users || []).filter(user => {
        const matchesSearch = !search || `${user.name || ''} ${user.email || ''} ${user.tenant_name || ''} ${user.branch_name || ''}`.toLowerCase().includes(search);
        const matchesRole = !filters.role || filters.role === 'all' || user.role === filters.role;
        const matchesStatus = !filters.status || filters.status === 'all' || (filters.status === 'active' ? user.active : !user.active);
        return matchesSearch && matchesRole && matchesStatus;
    });
    if (users.length === 0) {
        tbody.innerHTML = renderSaasEmptyState('No hay usuarios registrados', 'Crea usuarios por tenant y sucursal desde el formulario superior.', 7);
        return;
    }

    const editingId = state.saasAdmin?.editingUserId;

    tbody.innerHTML = users.map(user => {
        if (editingId === user.id) {
            return `<tr>
                <td>
                    <input type="text" class="td-edit-input" id="edit-user-name-${user.id}" value="${escapeHtml(user.name)}">
                    <input type="email" class="td-edit-input" id="edit-user-email-${user.id}" value="${escapeHtml(user.email)}" style="margin-top:4px;">
                </td>
                <td>${escapeHtml(user.tenant_name || '-')}</td>
                <td>
                    <select class="td-edit-input" id="edit-user-branch-${user.id}" style="width:auto;">
                        ${(state.saasAdmin?.branches || []).map(b => `<option value="${b.id}" ${b.id === user.branch_id ? 'selected' : ''}>${escapeHtml(b.name)}</option>`).join('')}
                    </select>
                </td>
                <td>
                    <select class="td-edit-input" id="edit-user-role-${user.id}" style="width:auto;" ${user.role === 'super_admin' ? 'disabled' : ''}>
                        ${['super_admin','owner','admin','cajero'].map(r => `<option value="${r}" ${user.role === r ? 'selected' : ''} ${r === 'super_admin' && user.role !== 'super_admin' ? 'disabled' : ''}>${r}${r === 'super_admin' ? ' (protegido)' : ''}</option>`).join('')}
                    </select>
                </td>
                <td>
                    <select class="td-edit-input" id="edit-user-active-${user.id}" style="width:auto;">
                        <option value="1" ${user.active ? 'selected' : ''}>Activo</option>
                        <option value="0" ${!user.active ? 'selected' : ''}>Inactivo</option>
                    </select>
                </td>
                <td>
                    <input type="password" class="td-edit-input" id="edit-user-pass-${user.id}" placeholder="(sin cambio)" style="width:120px;">
                </td>
                <td style="text-align:right;">
                    <button class="btn btn-primary btn-sm" onclick="saveUserInline('${user.id}')" title="Guardar"><i class="fa-solid fa-check"></i></button>
                    <button class="btn btn-outline btn-sm" onclick="cancelUserInline()" title="Cancelar"><i class="fa-solid fa-xmark"></i></button>
                </td>
            </tr>`;
        }
        return `<tr>
            <td>
                <div class="saas-entity-cell">
                    <span class="saas-entity-avatar"><i class="fa-solid fa-user"></i></span>
                    <div><strong>${escapeHtml(user.name)}</strong><small>${escapeHtml(user.email)}</small></div>
                </div>
            </td>
            <td>${escapeHtml(user.tenant_name || '-')}</td>
            <td>${escapeHtml(user.branch_name || '-')}</td>
            <td><span class="badge badge-info">${escapeHtml(user.role)}</span></td>
            <td><span class="badge ${user.active ? 'badge-success' : 'badge-danger'}">${user.active ? 'Activo' : 'Inactivo'}</span></td>
            <td><code>${escapeHtml(user.id)}</code></td>
            <td style="text-align:right;">
                <div class="saas-plan-actions">
                    <button class="btn-table-action edit" onclick="startEditUser('${user.id}')" title="Editar usuario"><i class="fa-solid fa-pen"></i> Editar</button>
                    <button class="btn-table-action delete" onclick="deleteSaasUser('${user.id}')" title="Eliminar usuario"><i class="fa-solid fa-trash"></i> Eliminar</button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

function populateSaasTenantOptions() {
    const tenants = state.saasAdmin?.tenants || [];
    const plans = state.saasAdmin?.plans || [];
    const selects = [
        document.getElementById('saas-branch-tenant-id'),
        document.getElementById('saas-user-tenant-id'),
        document.getElementById('saas-subscription-tenant-id')
    ];

    selects.forEach(select => {
        if (!select) return;
        const current = select.value;
        select.innerHTML = '<option value="">Seleccionar tenant</option>' + tenants.map(tenant => (
            `<option value="${tenant.id}">${escapeHtml(tenant.name)}</option>`
        )).join('');
        if (current && tenants.some(tenant => tenant.id === current)) {
            select.value = current;
        }
    });

    const planSelect = document.getElementById('saas-plan-code');
    if (planSelect) {
        const currentPlan = planSelect.value;
        planSelect.innerHTML = plans.map(p => (
            `<option value="${escapeHtml(p.code)}">${escapeHtml(p.name)} (Bs ${parseFloat(p.price_monthly).toFixed(2)}/mes)</option>`
        )).join('');
        if (currentPlan && plans.some(p => p.code === currentPlan)) planSelect.value = currentPlan;
    }

    populateSaasBranchOptions();
}

function populateSaasBranchOptions() {
    const tenantId = document.getElementById('saas-user-tenant-id')?.value || '';
    const branchSelect = document.getElementById('saas-user-branch-id');
    if (!branchSelect) return;

    const branches = (state.saasAdmin?.branches || []).filter(branch => !tenantId || branch.tenant_id === tenantId);
    const current = branchSelect.value;
    branchSelect.innerHTML = '<option value="">Seleccionar sucursal</option>' + branches.map(branch => (
        `<option value="${branch.id}">${escapeHtml(branch.name)} - ${escapeHtml(branch.tenant_name || '')}</option>`
    )).join('');
    if (current && branches.some(branch => branch.id === current)) {
        branchSelect.value = current;
    }
}

const defaultSaasPlans = [
    {
        id: 'plan_starter',
        code: 'starter',
        name: 'Plan Starter',
        description: 'Ideal para pequeños restaurantes y puestos de comida',
        price_monthly: 99.00,
        price_yearly: 990.00,
        max_branches: 1,
        max_users: 3,
        max_products: 50,
        trial_days: 14,
        active: 1,
        features: { pos: true, inventory: true, caja: true, reservations: false, delivery: false, multi_branch: false, priority_support: false, reportes: 'basicos' }
    },
    {
        id: 'plan_pro',
        code: 'pro',
        name: 'Plan Profesional',
        description: 'Para restaurantes en crecimiento con reservaciones y delivery',
        price_monthly: 199.00,
        price_yearly: 1990.00,
        max_branches: 3,
        max_users: 10,
        max_products: 200,
        trial_days: 14,
        active: 1,
        features: { pos: true, inventory: true, caja: true, reservations: true, delivery: true, multi_branch: true, priority_support: false, reportes: 'completos' }
    },
    {
        id: 'plan_enterprise',
        code: 'enterprise',
        name: 'Plan Enterprise',
        description: 'Franquicias y cadenas con sucursales ilimitadas y soporte VIP',
        price_monthly: 399.00,
        price_yearly: 3990.00,
        max_branches: 999,
        max_users: 999,
        max_products: 999,
        trial_days: 14,
        active: 1,
        features: { pos: true, inventory: true, caja: true, reservations: true, delivery: true, multi_branch: true, priority_support: true, reportes: 'completos' }
    }
];

function renderSaasPlansTable() {
    const container = document.getElementById('saas-plans-table-container');
    if (!container) return;

    if (!state.saasAdmin) state.saasAdmin = {};
    if (!state.saasAdmin.plans || state.saasAdmin.plans.length === 0) {
        state.saasAdmin.plans = defaultSaasPlans;
    }
    const plans = state.saasAdmin.plans;
    const basePlanIds = ['plan_starter', 'plan_pro', 'plan_enterprise'];
    const search = (window.saasPlanSearch || '').trim().toLowerCase();
    const statusFilter = window.saasPlanStatusFilter || 'all';
    const isActivePlan = (plan) => plan.active === true || plan.active === 1 || plan.active === '1';
    const filteredPlans = plans.filter(plan => {
        const active = isActivePlan(plan);
        const matchesStatus = statusFilter === 'all' || (statusFilter === 'active' && active) || (statusFilter === 'inactive' && !active);
        const haystack = `${plan.code || ''} ${plan.name || ''} ${plan.description || ''}`.toLowerCase();
        return matchesStatus && (!search || haystack.includes(search));
    });
    const activeCount = plans.filter(isActivePlan).length;
    const customCount = plans.filter(plan => !basePlanIds.includes(plan.id)).length;

    const renderLimit = (value) => {
        const numeric = parseInt(value, 10) || 0;
        return numeric >= 999 ? '∞' : numeric;
    };

    container.innerHTML = `
        <div class="content-card saas-plans-card" style="margin-top: 1.5rem;">
            <div class="content-card-header saas-plans-header">
                <div>
                    <h3><i class="fa-solid fa-layer-group"></i> Catálogo de Planes</h3>
                    <p class="saas-plans-subtitle">Administra precios, límites, features y disponibilidad comercial.</p>
                </div>
                <button class="btn btn-primary btn-sm" type="button" onclick="startNewPlan()">
                    <i class="fa-solid fa-plus"></i> Nuevo plan
                </button>
            </div>
            <div class="saas-plans-toolbar">
                <div class="saas-plan-stat">
                    <span>Total</span>
                    <strong>${plans.length}</strong>
                </div>
                <div class="saas-plan-stat">
                    <span>Activos</span>
                    <strong>${activeCount}</strong>
                </div>
                <div class="saas-plan-stat">
                    <span>Personalizados</span>
                    <strong>${customCount}</strong>
                </div>
                <div class="saas-plan-controls">
                    <input class="form-input" type="search" placeholder="Buscar por código, nombre o descripción" value="${escapeHtml(window.saasPlanSearch || '')}" oninput="setSaasPlanSearch(this.value)">
                    <select class="form-select" onchange="setSaasPlanStatusFilter(this.value)">
                        <option value="all" ${statusFilter === 'all' ? 'selected' : ''}>Todos</option>
                        <option value="active" ${statusFilter === 'active' ? 'selected' : ''}>Activos</option>
                        <option value="inactive" ${statusFilter === 'inactive' ? 'selected' : ''}>Inactivos</option>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="crud-table">
                    <thead>
                        <tr>
                            <th>Plan</th>
                            <th>Precios</th>
                            <th>Límites</th>
                            <th>Features</th>
                            <th>Estado</th>
                            <th style="text-align:right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${filteredPlans.length === 0 ? `
                            <tr>
                                <td colspan="6">
                                    <div class="empty-table-state">
                                        <i class="fa-solid fa-magnifying-glass"></i>
                                        <p>No se encontraron planes</p>
                                        <span>Ajusta la búsqueda o crea un nuevo plan personalizado.</span>
                                    </div>
                                </td>
                            </tr>
                        ` : filteredPlans.map(plan => {
                            let feat = {};
                            if (typeof plan.features === 'string') {
                                try { feat = JSON.parse(plan.features); } catch (e) { feat = {}; }
                            } else if (typeof plan.features === 'object' && plan.features !== null) {
                                feat = plan.features;
                            }
                            const featureBadges = [];
                            if (feat.pos !== false) featureBadges.push('<span class="badge badge-info">POS</span>');
                            if (feat.inventory !== false) featureBadges.push('<span class="badge badge-info">Inventario</span>');
                            if (feat.caja !== false) featureBadges.push('<span class="badge badge-info">Caja</span>');
                            if (feat.reservations) featureBadges.push('<span class="badge badge-success">Reservas</span>');
                            if (feat.delivery) featureBadges.push('<span class="badge badge-success">Delivery</span>');
                            if (feat.multi_branch) featureBadges.push('<span class="badge badge-warning">Multi-sucursal</span>');
                            if (feat.priority_support) featureBadges.push('<span class="badge badge-warning">Soporte</span>');
                            if (feat.reportes === 'completos') featureBadges.push('<span class="badge badge-success">Reportes completos</span>');

                            const priceM = parseFloat(plan.price_monthly) || 0;
                            const priceY = parseFloat(plan.price_yearly) || 0;
                            const monthlyYear = priceM * 12;
                            const discount = monthlyYear > 0 && priceY > 0 ? Math.max(0, Math.round((1 - (priceY / monthlyYear)) * 100)) : 0;
                            const active = isActivePlan(plan);
                            const isBasePlan = basePlanIds.includes(plan.id);

                            return `
                            <tr>
                                <td>
                                    <div class="saas-plan-name-cell">
                                        <strong>${escapeHtml(plan.name)}</strong>
                                        <code>${escapeHtml(plan.code)}</code>
                                    </div>
                                    ${plan.description ? `<div class="saas-plan-description">${escapeHtml(plan.description)}</div>` : ''}
                                    ${isBasePlan ? '<span class="badge badge-info">Plan base</span>' : '<span class="badge badge-warning">Personalizado</span>'}
                                </td>
                                <td>
                                    <div class="saas-plan-price-cell">
                                        <strong>Bs ${priceM.toFixed(2)} <span>/mes</span></strong>
                                        <small>Bs ${priceY.toFixed(2)} /año ${discount ? `(${discount}% ahorro)` : ''}</small>
                                        <small>${parseInt(plan.trial_days, 10) || 0} días trial</small>
                                    </div>
                                </td>
                                <td>
                                    <div class="saas-plan-limits">
                                        <span><strong>${renderLimit(plan.max_branches)}</strong> sucursales</span>
                                        <span><strong>${renderLimit(plan.max_users)}</strong> usuarios</span>
                                        <span><strong>${renderLimit(plan.max_products)}</strong> productos</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="saas-plan-features">
                                        ${featureBadges.length > 0 ? featureBadges.join(' ') : '<span class="text-muted" style="font-size:11px;">Básico</span>'}
                                    </div>
                                </td>
                                <td>
                                    <span class="badge ${active ? 'badge-success' : 'badge-danger'}">
                                        ${active ? 'Activo' : 'Inactivo'}
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <div class="saas-plan-actions">
                                        <button class="btn-table-action edit" onclick="openPlanEditor('${plan.id}')" title="Editar plan">
                                            <i class="fa-solid fa-pen"></i> Editar
                                        </button>
                                        <button class="btn-table-action edit" onclick="togglePlanStatus('${plan.id}')" title="${active ? 'Desactivar plan' : 'Activar plan'}">
                                            <i class="fa-solid ${active ? 'fa-pause' : 'fa-play'}"></i> ${active ? 'Desactivar' : 'Activar'}
                                        </button>
                                        ${!isBasePlan ? `
                                        <button class="btn-table-action delete" onclick="deletePlan('${plan.id}')" title="Eliminar plan">
                                            <i class="fa-solid fa-trash"></i> Eliminar
                                        </button>` : `
                                        <span class="saas-plan-locked" title="Los planes base del sistema no se eliminan">
                                            <i class="fa-solid fa-lock"></i> Protegido
                                        </span>`}
                                    </div>
                                </td>
                            </tr>`;
                        }).join('')}
                    </tbody>
                </table>
            </div>
        </div>
    `;
}

window.setSaasPlanSearch = function(value) {
    window.saasPlanSearch = value || '';
    renderSaasPlansTable();
};

window.setSaasPlanStatusFilter = function(value) {
    window.saasPlanStatusFilter = value || 'all';
    renderSaasPlansTable();
};

window.renderSaasPlansTable = renderSaasPlansTable;

function populateSaasSubscriptionPlanOptions() {
    const plans = state.saasAdmin?.plans || [];
    const select = document.getElementById('saas-update-plan-code');
    if (!select) return;
    const current = select.value;
    select.innerHTML = '<option value="">Seleccionar plan</option>' + plans.map(p => (
        `<option value="${escapeHtml(p.code)}">${escapeHtml(p.name)} (Bs ${parseFloat(p.price_monthly).toFixed(2)}/mes)</option>`
    )).join('');
    if (current && plans.some(p => p.code === current)) select.value = current;
}

window.renderSaasAdmin = renderSaasAdmin;
window.populateSaasTenantOptions = populateSaasTenantOptions;
window.populateSaasBranchOptions = populateSaasBranchOptions;
