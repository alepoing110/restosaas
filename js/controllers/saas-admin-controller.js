window.switchSaasSubtab = function(tabId) {
    if (!tabId) return;
    window.activeSaasSubtab = tabId;

    document.querySelectorAll('.saas-subtab').forEach(btn => {
        const isActive = btn.dataset.saasTab === tabId;
        btn.classList.toggle('active', isActive);
        if (isActive) {
            try {
                btn.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            } catch (e) {}
        }
    });

    document.querySelectorAll('.saas-subtab-panel').forEach(panel => {
        const isActive = panel.id === `saas-panel-${tabId}`;
        panel.classList.toggle('active', isActive);
    });

    if (tabId === 'plans' && typeof renderSaasPlansTable === 'function') {
        renderSaasPlansTable();
    }
    if (tabId === 'chatbot' && typeof reloadChatbotConversations === 'function') {
        reloadChatbotConversations();
    }
};

async function reloadChatbotConversations() {
    try {
        const params = {};
        if (state.activeTenantId) params.tenant_id = state.activeTenantId;
        const data = await AppApi.request('get_chatbot_conversations', null, { method: 'GET', params });
        state.saasAdmin.chatbotConversations = data.conversations || [];
        renderSaasChatbotConversations();
        renderSaasChatbotMessages();
    } catch (error) {
        showToast(error.message || 'No se pudo cargar conversaciones del chatbot.', 'error');
    }
}

async function selectChatbotConversation(conversationId) {
    state.saasAdmin.chatbotSelectedConversationId = conversationId;
    renderSaasChatbotConversations();
    try {
        const params = { conversation_id: conversationId };
        if (state.activeTenantId) params.tenant_id = state.activeTenantId;
        const data = await AppApi.request('get_chatbot_messages', null, { method: 'GET', params });
        state.saasAdmin.chatbotMessages = data.messages || [];
        renderSaasChatbotMessages();
    } catch (error) {
        state.saasAdmin.chatbotMessages = [];
        renderSaasChatbotMessages();
        showToast(error.message || 'No se pudo cargar mensajes de la conversación.', 'error');
    }
}

async function sendSimulatedMessage() {
    const input = document.getElementById('chatbot-sim-input');
    const sendBtn = document.getElementById('chatbot-sim-send');
    if (!input || !sendBtn) return;

    const message = input.value.trim();
    if (!message) return;

    let phone = document.getElementById('chatbot-sim-phone')?.value.trim();
    const customerName = document.getElementById('chatbot-sim-name')?.value.trim() || 'Cliente Sim';

    if (!phone) {
        phone = 'sim_' + Date.now().toString(36);
        const phoneInput = document.getElementById('chatbot-sim-phone');
        if (phoneInput) phoneInput.value = phone;
    }

    sendBtn.disabled = true;
    input.disabled = true;
    input.value = '';

    if (!state.saasAdmin.chatbotSimMessages) state.saasAdmin.chatbotSimMessages = [];
    state.saasAdmin.chatbotSimMessages.push({
        role: 'user',
        content: message,
        created_at: new Date().toISOString()
    });
    renderSaasChatbotSimMessages();

    state.saasAdmin.chatbotSimMessages.push({
        role: 'loading',
        content: '...',
        created_at: new Date().toISOString()
    });
    renderSaasChatbotSimMessages();

    try {
        const payload = { message, phone, customer_name: customerName };
        if (state.activeTenantId) payload.tenant_id = state.activeTenantId;
        const data = await AppApi.request('chatbot_simulate', payload);

        state.saasAdmin.chatbotSimMessages = state.saasAdmin.chatbotSimMessages.filter(m => m.role !== 'loading');
        state.saasAdmin.chatbotSimMessages.push({
            role: 'assistant',
            content: data.reply || '',
            tool_result: data.tool_result || null,
            conversation_id: data.conversation_id || null,
            created_at: new Date().toISOString()
        });
        renderSaasChatbotSimMessages();

        if (data.conversation_id) {
            state.saasAdmin.chatbotSelectedConversationId = data.conversation_id;
            await reloadChatbotConversations();
        }
    } catch (error) {
        state.saasAdmin.chatbotSimMessages = state.saasAdmin.chatbotSimMessages.filter(m => m.role !== 'loading');
        state.saasAdmin.chatbotSimMessages.push({
            role: 'error',
            content: error.message || 'Error al enviar mensaje',
            created_at: new Date().toISOString()
        });
        renderSaasChatbotSimMessages();
    } finally {
        sendBtn.disabled = false;
        input.disabled = false;
        input.focus();
    }
}

async function clearSimulatorChat() {
    const conversationId = state.saasAdmin.chatbotSelectedConversationId;
    if (conversationId) {
        try {
            const payload = { conversation_id: conversationId };
            if (state.activeTenantId) payload.tenant_id = state.activeTenantId;
            await AppApi.request('delete_chatbot_conversation', payload);
        } catch (e) {
            // Silently fail — clear UI regardless
        }
    }
    state.saasAdmin.chatbotSimMessages = [];
    state.saasAdmin.chatbotSelectedConversationId = null;
    state.saasAdmin.chatbotMessages = [];
    renderSaasChatbotSimMessages();
    renderSaasChatbotConversations();
    renderSaasChatbotMessages();
    const phoneInput = document.getElementById('chatbot-sim-phone');
    const nameInput = document.getElementById('chatbot-sim-name');
    if (phoneInput) phoneInput.value = '';
    if (nameInput) nameInput.value = '';
}

async function closeChatbotConversation(conversationId) {
    const confirmed = await ConfirmDialog.show('¿Cerrar esta conversación? El chatbot no responderá más mensajes de esta conversación.', {
        title: 'Cerrar Conversación',
        confirmText: 'Sí, cerrar'
    });
    if (!confirmed) return;

    try {
        await AppApi.request('chatbot_close_conversation', { conversation_id: conversationId });
        showToast('Conversación cerrada.', 'success');
        await reloadChatbotConversations();
    } catch (error) {
        showToast(error.message || 'No se pudo cerrar la conversación.', 'error');
    }
}

async function deleteChatbotConversation(conversationId) {
    const confirmed = await ConfirmDialog.show('¿Eliminar esta conversación y todos sus mensajes? Esta acción no se puede deshacer.', {
        title: 'Eliminar Conversación',
        confirmText: 'Sí, eliminar'
    });
    if (!confirmed) return;

    try {
        const payload = { conversation_id: conversationId };
        if (state.activeTenantId) payload.tenant_id = state.activeTenantId;
        await AppApi.request('delete_chatbot_conversation', payload);
        showToast('Conversación eliminada.', 'success');
        if (state.saasAdmin.chatbotSelectedConversationId === conversationId) {
            state.saasAdmin.chatbotSelectedConversationId = null;
            state.saasAdmin.chatbotMessages = [];
            renderSaasChatbotMessages();
        }
        await reloadChatbotConversations();
    } catch (error) {
        showToast(error.message || 'No se pudo eliminar la conversación.', 'error');
    }
}
window.deleteChatbotConversation = deleteChatbotConversation;
window.clearSimulatorChat = clearSimulatorChat;

async function reloadSaasAdminData() {
    await loadStateForTab('saas-admin');
    renderSaasAdmin();
}

async function handleCreateTenant(e) {
    e.preventDefault();

    const payload = {
        tenant_name: document.getElementById('saas-tenant-name').value.trim(),
        tenant_slug: document.getElementById('saas-tenant-slug').value.trim(),
        owner_name: document.getElementById('saas-owner-name').value.trim(),
        owner_email: document.getElementById('saas-owner-email').value.trim(),
        owner_password: document.getElementById('saas-owner-password').value,
        branch_name: document.getElementById('saas-branch-name').value.trim(),
        plan_code: document.getElementById('saas-plan-code').value.trim(),
        subscription_status: document.getElementById('saas-subscription-status').value
    };

    if (!/^[a-z0-9-]{3,50}$/.test(payload.tenant_slug)) {
        showToast('El slug debe tener 3 a 50 caracteres: minúsculas, números y guiones.', 'error');
        return;
    }

    if (payload.owner_password.length < 8) {
        showToast('La contraseña del owner debe tener al menos 8 caracteres.', 'error');
        return;
    }

    try {
        const data = await AppApi.request('saas_create_tenant', payload);
        applyServerState(data);
        renderSaasAdmin();
        e.target.reset();
        showToast('Tenant SaaS creado correctamente.', 'success');
    } catch (error) {
        showToast(error.message || 'No se pudo crear el tenant.', 'error');
    }
}

async function handleCreateBranch(e) {
    e.preventDefault();

    const payload = {
        tenant_id: document.getElementById('saas-branch-tenant-id').value,
        branch_name: document.getElementById('saas-new-branch-name').value.trim()
    };

    if (!payload.tenant_id || !payload.branch_name) {
        showToast('Selecciona un tenant y escribe el nombre de la sucursal.', 'error');
        return;
    }

    try {
        const data = await AppApi.request('saas_create_branch', payload);
        applyServerState(data);
        renderSaasAdmin();
        e.target.reset();
        showToast('Sucursal creada.', 'success');
    } catch (error) {
        showToast(error.message || 'No se pudo crear la sucursal.', 'error');
    }
}

async function handleCreateUser(e) {
    e.preventDefault();

    const payload = {
        tenant_id: document.getElementById('saas-user-tenant-id').value,
        branch_id: document.getElementById('saas-user-branch-id').value,
        name: document.getElementById('saas-user-name').value.trim(),
        email: document.getElementById('saas-user-email').value.trim(),
        password: document.getElementById('saas-user-password').value,
        role: document.getElementById('saas-user-role').value
    };

    if (!payload.tenant_id || !payload.branch_id) {
        showToast('Selecciona tenant y sucursal para el usuario.', 'error');
        return;
    }

    if (payload.password.length < 8) {
        showToast('La contraseña debe tener al menos 8 caracteres.', 'error');
        return;
    }

    try {
        const data = await AppApi.request('saas_create_user', payload);
        applyServerState(data);
        renderSaasAdmin();
        e.target.reset();
        showToast('Usuario creado.', 'success');
    } catch (error) {
        showToast(error.message || 'No se pudo crear el usuario.', 'error');
    }
}

async function handleUpdateSubscription(e) {
    e.preventDefault();

    const payload = {
        tenant_id: document.getElementById('saas-subscription-tenant-id').value,
        plan_code: document.getElementById('saas-update-plan-code').value.trim(),
        status: document.getElementById('saas-update-status').value,
        starts_at: document.getElementById('saas-update-starts-at')?.value || null,
        ends_at: document.getElementById('saas-update-ends-at')?.value || null
    };

    try {
        const data = await AppApi.request('saas_update_subscription', payload);
        applyServerState(data);
        renderSaasAdmin();
        showToast('Suscripción actualizada.', 'success');
    } catch (error) {
        showToast(error.message || 'No se pudo actualizar la suscripción.', 'error');
    }
}

async function approvePayment(tenantId) {
    const confirmed = await ConfirmDialog.show('¿Aprobar el pago de este tenant?', { title: 'Aprobar Pago', confirmText: 'Sí, aprobar' });
    if (!confirmed) return;

    try {
        const data = await AppApi.request('saas_approve_payment', {
            tenant_id: tenantId,
            payment_notes: 'Aprobado por admin'
        });
        applyServerState(data);
        renderSaasAdmin();
        showToast('Pago aprobado. Suscripción activada.', 'success');
    } catch (error) {
        showToast(error.message || 'No se pudo aprobar el pago.', 'error');
    }
}

async function rejectPayment(tenantId) {
    const reason = prompt('Motivo del rechazo:');
    if (reason === null) return;

    try {
        const data = await AppApi.request('saas_reject_payment', {
            tenant_id: tenantId,
            reason: reason || 'No especificado'
        });
        applyServerState(data);
        renderSaasAdmin();
        showToast('Pago rechazado.', 'success');
    } catch (error) {
        showToast(error.message || 'No se pudo rechazar el pago.', 'error');
    }
}

async function handleSavePlan(e) {
    e.preventDefault();

    const editId = document.getElementById('saas-plan-edit-id').value;
    const isEdit = editId !== '';

    const features = {
        pos: document.getElementById('saas-feat-pos')?.checked ?? true,
        reportes: document.getElementById('saas-feat-reportes')?.value || 'basicos',
        reservations: document.getElementById('saas-feat-reservations')?.checked ?? false,
        inventory: document.getElementById('saas-feat-inventory')?.checked ?? true,
        caja: document.getElementById('saas-feat-caja')?.checked ?? true,
        delivery: document.getElementById('saas-feat-delivery')?.checked ?? false,
        multi_branch: document.getElementById('saas-feat-multi-branch')?.checked ?? false,
        priority_support: document.getElementById('saas-feat-priority-support')?.checked ?? false
    };

    const payload = {
        code: document.getElementById('saas-plan-code-input').value.trim().toLowerCase(),
        name: document.getElementById('saas-plan-name').value.trim(),
        description: document.getElementById('saas-plan-description').value.trim(),
        price_monthly: parseFloat(document.getElementById('saas-plan-price-monthly').value) || 0,
        price_yearly: parseFloat(document.getElementById('saas-plan-price-yearly').value) || 0,
        max_branches: parseInt(document.getElementById('saas-plan-max-branches').value) || 1,
        max_users: parseInt(document.getElementById('saas-plan-max-users').value) || 5,
        max_products: parseInt(document.getElementById('saas-plan-max-products').value) || 50,
        trial_days: parseInt(document.getElementById('saas-plan-trial-days')?.value) || 14,
        features: features
    };

    if (!/^[a-z0-9_]{2,30}$/.test(payload.code)) {
        showToast('El código debe tener 2 a 30 caracteres: minúsculas, números y guiones bajos.', 'error');
        return;
    }

    if (payload.name.length < 2) {
        showToast('El nombre del plan debe tener al menos 2 caracteres.', 'error');
        return;
    }

    if (isEdit) {
        payload.id = editId;
        payload.name = payload.name;
    }

    try {
        const action = isEdit ? 'saas_update_plan' : 'saas_create_plan';
        const data = await AppApi.request(action, payload);
        applyServerState(data);
        renderSaasAdmin();
        resetPlanForm();
        showToast(isEdit ? 'Plan actualizado.' : 'Plan creado.', 'success');
    } catch (error) {
        showToast(error.message || 'No se pudo guardar el plan.', 'error');
    }
}

window.openPlanEditor = function(planId) {
    const plan = (state.saasAdmin?.plans || []).find(p => p.id === planId);
    if (!plan) return;

    let features = {};
    if (typeof plan.features === 'string') {
        try { features = JSON.parse(plan.features); } catch (e) { features = {}; }
    } else if (typeof plan.features === 'object' && plan.features !== null) {
        features = plan.features;
    }

    document.getElementById('saas-plan-edit-id').value = plan.id;
    document.getElementById('saas-plan-code-input').value = plan.code;
    document.getElementById('saas-plan-code-input').disabled = true;
    document.getElementById('saas-plan-name').value = plan.name;
    document.getElementById('saas-plan-description').value = plan.description || '';
    document.getElementById('saas-plan-price-monthly').value = plan.price_monthly;
    document.getElementById('saas-plan-price-yearly').value = plan.price_yearly;
    document.getElementById('saas-plan-max-branches').value = plan.max_branches;
    document.getElementById('saas-plan-max-users').value = plan.max_users;
    document.getElementById('saas-plan-max-products').value = plan.max_products;
    document.getElementById('saas-plan-trial-days').value = plan.trial_days || 14;

    document.getElementById('saas-feat-pos').checked = features.pos !== false;
    document.getElementById('saas-feat-inventory').checked = features.inventory !== false;
    document.getElementById('saas-feat-caja').checked = features.caja !== false;
    document.getElementById('saas-feat-reservations').checked = features.reservations === true;
    document.getElementById('saas-feat-delivery').checked = features.delivery === true;
    document.getElementById('saas-feat-multi-branch').checked = features.multi_branch === true;
    document.getElementById('saas-feat-priority-support').checked = features.priority_support === true;
    document.getElementById('saas-feat-reportes').value = features.reportes || 'basicos';

    document.getElementById('plan-form-title').textContent = 'Editar Plan (' + plan.name + ')';
    document.getElementById('plan-form-btn-text').textContent = 'Actualizar';
    document.getElementById('btn-cancel-plan-edit').style.display = 'inline-flex';
    updatePlanYearlyHelp();

    if (typeof window.switchSaasSubtab === 'function') {
        window.switchSaasSubtab('plans');
    }
    const formCard = document.getElementById('form-saas-plan');
    if (formCard) formCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
};

window.togglePlanStatus = async function(planId) {
    const plan = (state.saasAdmin?.plans || []).find(p => p.id === planId);
    if (!plan) return;
    const newActive = plan.active ? 0 : 1;
    try {
        const payload = {
            id: plan.id,
            code: plan.code,
            name: plan.name,
            description: plan.description || '',
            price_monthly: plan.price_monthly,
            price_yearly: plan.price_yearly,
            max_branches: plan.max_branches,
            max_users: plan.max_users,
            max_products: plan.max_products,
            trial_days: plan.trial_days || 14,
            active: newActive,
            features: plan.features || {}
        };
        const data = await AppApi.request('saas_update_plan', payload);
        applyServerState(data);
        renderSaasAdmin();
        showToast(`Plan "${plan.name}" ${newActive ? 'activado' : 'desactivado'}.`, 'success');
    } catch (error) {
        showToast(error.message || 'No se pudo actualizar el estado del plan.', 'error');
    }
};

window.deletePlan = async function(planId) {
    const confirmed = await ConfirmDialog.show('¿Eliminar este plan? Solo se eliminará si no tiene tenants activos o en trial asociados.', { title: 'Eliminar Plan', confirmText: 'Sí, eliminar' });
    if (!confirmed) return;
    try {
        const data = await AppApi.request('saas_delete_plan', { id: planId });
        applyServerState(data);
        renderSaasAdmin();
        showToast('Plan eliminado.', 'success');
    } catch (error) {
        showToast(error.message || 'No se pudo eliminar el plan.', 'error');
    }
};

function updatePlanYearlyHelp() {
    const monthlyInput = document.getElementById('saas-plan-price-monthly');
    const yearlyInput = document.getElementById('saas-plan-price-yearly');
    const help = document.getElementById('saas-plan-yearly-help');
    if (!monthlyInput || !yearlyInput || !help) return;

    const monthly = parseFloat(monthlyInput.value) || 0;
    const yearly = parseFloat(yearlyInput.value) || 0;
    const suggested = monthly * 10;
    const yearlyFull = monthly * 12;
    const discount = yearlyFull > 0 && yearly > 0 ? Math.max(0, Math.round((1 - (yearly / yearlyFull)) * 100)) : 0;
    help.textContent = monthly > 0
        ? `Sugerido: Bs ${suggested.toFixed(2)} al año. Descuento actual: ${discount}%.`
        : 'Sugerencia: 10 meses de pago.';
}

function suggestPlanYearlyPrice() {
    const monthlyInput = document.getElementById('saas-plan-price-monthly');
    const yearlyInput = document.getElementById('saas-plan-price-yearly');
    if (!monthlyInput || !yearlyInput) return;

    const monthly = parseFloat(monthlyInput.value) || 0;
    yearlyInput.value = (monthly * 10).toFixed(2);
    updatePlanYearlyHelp();
}

function normalizePlanCodeInput(event) {
    const input = event.target;
    input.value = input.value.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/_+/g, '_').slice(0, 30);
}

function normalizeTenantSlugInput(event) {
    const input = event.target;
    input.value = input.value
        .toLowerCase()
        .replace(/[^a-z0-9-]/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-+/, '')
        .slice(0, 50);
}

function resetPlanForm() {
    document.getElementById('saas-plan-edit-id').value = '';
    document.getElementById('saas-plan-code-input').value = '';
    document.getElementById('saas-plan-code-input').disabled = false;
    document.getElementById('saas-plan-name').value = '';
    document.getElementById('saas-plan-description').value = '';
    document.getElementById('saas-plan-price-monthly').value = '0';
    document.getElementById('saas-plan-price-yearly').value = '0';
    document.getElementById('saas-plan-max-branches').value = '1';
    document.getElementById('saas-plan-max-users').value = '5';
    document.getElementById('saas-plan-max-products').value = '50';
    document.getElementById('saas-plan-trial-days').value = '14';
    document.getElementById('saas-feat-pos').checked = true;
    document.getElementById('saas-feat-inventory').checked = true;
    document.getElementById('saas-feat-caja').checked = true;
    document.getElementById('saas-feat-reservations').checked = false;
    document.getElementById('saas-feat-delivery').checked = false;
    document.getElementById('saas-feat-multi-branch').checked = false;
    document.getElementById('saas-feat-priority-support').checked = false;
    document.getElementById('saas-feat-reportes').value = 'basicos';
    document.getElementById('plan-form-title').textContent = 'Crear Plan';
    document.getElementById('plan-form-btn-text').textContent = 'Crear';
    document.getElementById('btn-cancel-plan-edit').style.display = 'none';
    updatePlanYearlyHelp();
}

window.startNewPlan = function() {
    resetPlanForm();
    if (typeof window.switchSaasSubtab === 'function') {
        window.switchSaasSubtab('plans');
    }
    const formCard = document.getElementById('form-saas-plan');
    if (formCard) formCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const codeInput = document.getElementById('saas-plan-code-input');
    if (codeInput) codeInput.focus();
};

window.openSubscriptionEditor = function(tenantId) {
    const tenant = (state.saasAdmin?.tenants || []).find(item => item.id === tenantId);
    if (!tenant) return;

    const tenantSelect = document.getElementById('saas-subscription-tenant-id');
    const planInput = document.getElementById('saas-update-plan-code');
    const statusSelect = document.getElementById('saas-update-status');
    const startsAtInput = document.getElementById('saas-update-starts-at');
    const endsAtInput = document.getElementById('saas-update-ends-at');
    if (tenantSelect) tenantSelect.value = tenant.id;
    if (planInput) planInput.value = tenant.plan_code || 'starter';
    if (statusSelect) statusSelect.value = tenant.subscription_status || 'trial';

    if (startsAtInput) {
        startsAtInput.value = tenant.starts_at ? tenant.starts_at.split(' ')[0] : '';
    }
    if (endsAtInput) {
        endsAtInput.value = tenant.ends_at ? tenant.ends_at.split(' ')[0] : '';
    }

    const subtabBtn = document.querySelector('.saas-subtab[data-saas-tab="subscriptions"]');
    if (subtabBtn) subtabBtn.click();
};

// ==========================================================================
// TENANT INLINE EDIT + DELETE
// ==========================================================================

let _editingTenantId = null;

window.startEditTenant = function(id) {
    _editingTenantId = id;
    state.saasAdmin.editingTenantId = id;
    renderSaasTenantsTable();
};

window.cancelTenantInline = function() {
    _editingTenantId = null;
    state.saasAdmin.editingTenantId = null;
    renderSaasTenantsTable();
};

window.saveTenantInline = async function(id) {
    const name = document.getElementById(`edit-tenant-name-${id}`)?.value.trim();
    const businessType = document.getElementById(`edit-tenant-business-${id}`)?.value;
    const active = document.getElementById(`edit-tenant-active-${id}`)?.value;
    if (!name) { showToast('El nombre es requerido.', 'warning'); return; }
    try {
        const data = await AppApi.request('saas_edit_tenant', { id, name, business_type: businessType, active: parseInt(active) });
        applyServerState(data);
        _editingTenantId = null;
        state.saasAdmin.editingTenantId = null;
        renderSaasAdmin();
        showToast('Tenant actualizado.', 'success');
    } catch (e) {
        showToast(e.message || 'Error al actualizar tenant.', 'error');
    }
};

window.deleteTenant = async function(id) {
    const confirmed = await ConfirmDialog.show('¿Eliminar este tenant? Se perderán todas las sucursales, usuarios y suscripciones asociadas.', { title: 'Eliminar Tenant', confirmText: 'Sí, eliminar' });
    if (!confirmed) return;
    try {
        const data = await AppApi.request('saas_delete_tenant', { id });
        applyServerState(data);
        renderSaasAdmin();
        showToast('Tenant eliminado.', 'success');
    } catch (e) {
        showToast(e.message || 'Error al eliminar tenant.', 'error');
    }
};

// ==========================================================================
// BRANCH INLINE EDIT + DELETE
// ==========================================================================

let _editingBranchId = null;

window.startEditBranch = function(id) {
    _editingBranchId = id;
    state.saasAdmin.editingBranchId = id;
    renderSaasBranchesTable();
};

window.cancelBranchInline = function() {
    _editingBranchId = null;
    state.saasAdmin.editingBranchId = null;
    renderSaasBranchesTable();
};

window.saveBranchInline = async function(id) {
    const name = document.getElementById(`edit-branch-name-${id}`)?.value.trim();
    const active = document.getElementById(`edit-branch-active-${id}`)?.value;
    if (!name) { showToast('El nombre es requerido.', 'warning'); return; }
    try {
        const data = await AppApi.request('saas_edit_branch', { id, name, active: parseInt(active) });
        applyServerState(data);
        _editingBranchId = null;
        state.saasAdmin.editingBranchId = null;
        renderSaasAdmin();
        showToast('Sucursal actualizada.', 'success');
    } catch (e) {
        showToast(e.message || 'Error al actualizar sucursal.', 'error');
    }
};

window.deleteBranch = async function(id) {
    const confirmed = await ConfirmDialog.show('¿Eliminar esta sucursal? Los usuarios asociados perderán la referencia.', { title: 'Eliminar Sucursal', confirmText: 'Sí, eliminar' });
    if (!confirmed) return;
    try {
        const data = await AppApi.request('saas_delete_branch', { id });
        applyServerState(data);
        renderSaasAdmin();
        showToast('Sucursal eliminada.', 'success');
    } catch (e) {
        showToast(e.message || 'Error al eliminar sucursal.', 'error');
    }
};

// ==========================================================================
// USER INLINE EDIT + DELETE
// ==========================================================================

let _editingUserId = null;

window.startEditUser = function(id) {
    _editingUserId = id;
    state.saasAdmin.editingUserId = id;
    renderSaasUsersTable();
};

window.cancelUserInline = function() {
    _editingUserId = null;
    state.saasAdmin.editingUserId = null;
    renderSaasUsersTable();
};

window.saveUserInline = async function(id) {
    const name = document.getElementById(`edit-user-name-${id}`)?.value.trim();
    const email = document.getElementById(`edit-user-email-${id}`)?.value.trim();
    const branchId = document.getElementById(`edit-user-branch-${id}`)?.value;
    const role = document.getElementById(`edit-user-role-${id}`)?.value;
    const active = document.getElementById(`edit-user-active-${id}`)?.value;
    const password = document.getElementById(`edit-user-pass-${id}`)?.value || '';
    if (!name || !email) { showToast('Nombre y email son requeridos.', 'warning'); return; }
    try {
        const payload = { id, name, email, branch_id: branchId, role, active: parseInt(active) };
        if (password) payload.password = password;
        const data = await AppApi.request('saas_edit_user', payload);
        applyServerState(data);
        state.saasAdmin.editingUserId = null;
        renderSaasAdmin();
        showToast('Usuario actualizado.', 'success');
    } catch (e) {
        showToast(e.message || 'Error al actualizar usuario.', 'error');
    }
};

window.deleteSaasUser = async function(id) {
    const confirmed = await ConfirmDialog.show('¿Eliminar este usuario? Será desactivado y perderá acceso.', { title: 'Eliminar Usuario', confirmText: 'Sí, eliminar' });
    if (!confirmed) return;
    try {
        const data = await AppApi.request('saas_delete_user', { id });
        applyServerState(data);
        renderSaasAdmin();
        showToast('Usuario eliminado.', 'success');
    } catch (e) {
        showToast(e.message || 'Error al eliminar usuario.', 'error');
    }
};

window.impersonateTenant = function(tenantId) {
    const tenant = (state.saasAdmin?.tenants || []).find(t => t.id === tenantId);
    if (!tenant) {
        showToast('Empresa no encontrada.', 'error');
        return;
    }
    state.activeTenantId = tenantId;
    localStorage.setItem('restocloud_active_tenant', tenantId);
    showToast(`Super Admin Support: Ingresando como "${tenant.name}"...`, 'info');
    if (typeof window.switchTab === 'function') {
        window.switchTab('pos');
    }
};

window.toggleTenantStatus = async function(tenantId, newStatus) {
    const tenant = (state.saasAdmin?.tenants || []).find(t => t.id === tenantId);
    if (!tenant) return;
    const confirmMsg = newStatus === 'suspended'
        ? `¿Suspender temporalmente la suscripción de "${tenant.name}"?`
        : `¿Activar la suscripción de "${tenant.name}"?`;
    const confirmed = await ConfirmDialog.show(confirmMsg, { title: newStatus === 'suspended' ? 'Suspender' : 'Activar', confirmText: newStatus === 'suspended' ? 'Sí, suspender' : 'Sí, activar' });
    if (!confirmed) return;

    try {
        const data = await AppApi.request('saas_update_subscription', {
            tenant_id: tenantId,
            plan_code: tenant.plan_code || 'starter',
            status: newStatus
        });
        applyServerState(data);
        renderSaasAdmin();
        showToast(`Suscripción de "${tenant.name}" cambiada a ${newStatus.toUpperCase()}.`, 'success');
    } catch (e) {
        showToast(e.message || 'Error al cambiar estado de la suscripción.', 'error');
    }
};

window.filterSaasTenants = function(query, status) {
    if (!state.saasAdmin) state.saasAdmin = {};
    if (query !== undefined) state.saasAdmin.searchQuery = query.toLowerCase().trim();
    if (status !== undefined) state.saasAdmin.statusFilter = status;
    renderSaasTenantsTable();
};

window.setSaasListFilter = function(list, field, value) {
    if (!state.saasAdmin.filters) state.saasAdmin.filters = {};
    if (!state.saasAdmin.filters[list]) state.saasAdmin.filters[list] = {};
    state.saasAdmin.filters[list][field] = field === 'search' ? String(value || '').trim() : (value || 'all');
    if (list === 'branches') renderSaasBranchesTable();
    if (list === 'users') renderSaasUsersTable();
    if (list === 'payments') renderSaasPendingPayments();
};

window.clearSaasListFilters = function(list) {
    if (!state.saasAdmin.filters) state.saasAdmin.filters = {};
    state.saasAdmin.filters[list] = {};
    const panel = document.getElementById(`saas-panel-${list}`);
    if (panel) panel.querySelectorAll('input[type="search"]').forEach(input => { input.value = ''; });
    if (panel) panel.querySelectorAll('.saas-list-filters select').forEach(select => { select.value = 'all'; });
    if (list === 'branches') renderSaasBranchesTable();
    if (list === 'users') renderSaasUsersTable();
    if (list === 'payments') renderSaasPendingPayments();
};

window.initSaasAdminController = function() {
    const tenantForm = document.getElementById('form-saas-create-tenant');
    if (tenantForm && !tenantForm.dataset.bound) {
        tenantForm.dataset.bound = 'true';
        tenantForm.addEventListener('submit', handleCreateTenant);
    }

    const branchForm = document.getElementById('form-saas-create-branch');
    if (branchForm && !branchForm.dataset.bound) {
        branchForm.dataset.bound = 'true';
        branchForm.addEventListener('submit', handleCreateBranch);
    }

    const userForm = document.getElementById('form-saas-create-user');
    if (userForm && !userForm.dataset.bound) {
        userForm.dataset.bound = 'true';
        userForm.addEventListener('submit', handleCreateUser);
    }

    const subscriptionForm = document.getElementById('form-saas-update-subscription');
    if (subscriptionForm && !subscriptionForm.dataset.bound) {
        subscriptionForm.dataset.bound = 'true';
        subscriptionForm.addEventListener('submit', handleUpdateSubscription);
    }

    const tenantSelect = document.getElementById('saas-user-tenant-id');
    if (tenantSelect && !tenantSelect.dataset.bound) {
        tenantSelect.dataset.bound = 'true';
        tenantSelect.addEventListener('change', populateSaasBranchOptions);
    }

    const planForm = document.getElementById('form-saas-plan');
    if (planForm && !planForm.dataset.bound) {
        planForm.dataset.bound = 'true';
        planForm.addEventListener('submit', handleSavePlan);
    }

    const cancelPlanBtn = document.getElementById('btn-cancel-plan-edit');
    if (cancelPlanBtn && !cancelPlanBtn.dataset.bound) {
        cancelPlanBtn.dataset.bound = 'true';
        cancelPlanBtn.addEventListener('click', resetPlanForm);
    }

    const monthlyPlanPrice = document.getElementById('saas-plan-price-monthly');
    const yearlyPlanPrice = document.getElementById('saas-plan-price-yearly');
    const suggestYearlyBtn = document.getElementById('btn-suggest-yearly-price');
    const planCodeInput = document.getElementById('saas-plan-code-input');
    const tenantSlugInput = document.getElementById('saas-tenant-slug');

    if (monthlyPlanPrice && !monthlyPlanPrice.dataset.bound) {
        monthlyPlanPrice.dataset.bound = 'true';
        monthlyPlanPrice.addEventListener('input', updatePlanYearlyHelp);
    }
    if (yearlyPlanPrice && !yearlyPlanPrice.dataset.bound) {
        yearlyPlanPrice.dataset.bound = 'true';
        yearlyPlanPrice.addEventListener('input', updatePlanYearlyHelp);
    }
    if (suggestYearlyBtn && !suggestYearlyBtn.dataset.bound) {
        suggestYearlyBtn.dataset.bound = 'true';
        suggestYearlyBtn.addEventListener('click', suggestPlanYearlyPrice);
    }
    if (planCodeInput && !planCodeInput.dataset.bound) {
        planCodeInput.dataset.bound = 'true';
        planCodeInput.addEventListener('input', normalizePlanCodeInput);
    }

    if (tenantSlugInput && !tenantSlugInput.dataset.bound) {
        tenantSlugInput.dataset.bound = 'true';
        tenantSlugInput.addEventListener('input', normalizeTenantSlugInput);
    }

    updatePlanYearlyHelp();
};
