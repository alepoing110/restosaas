// ===========================================================================
// CUSTOMERS CRM CONTROLLER
// ===========================================================================

async function refreshCustomers(search = '') {
    const data = await AppApi.getCustomers(search);
    if (data.status !== 'success') throw new Error(data.message || 'No se pudieron cargar los clientes.');
    if (window.AppStore) AppStore.set({ customers: data.customers || [] });
    else state.customers = data.customers || [];
    renderCustomers();
}

function searchCustomers() {
    renderCustomers();
}

function filterCustomerSegment(segment) {
    state.customerSegment = segment;
    renderCustomers();
}

async function refreshCrmConversations() {
    try {
        const data = await AppApi.getChatbotConversations();
        if (data.status !== 'success') throw new Error(data.message || 'No se pudieron cargar las conversaciones.');
        if (window.AppStore) AppStore.set({ crmConversations: data.conversations || [] });
        else state.crmConversations = data.conversations || [];
        renderCrmConversations();
    } catch (error) { showToast(error.message || 'No se pudieron cargar las conversaciones.', 'error'); }
}

async function selectCrmConversation(id) {
    try {
        const data = await AppApi.getChatbotMessages(id);
        if (data.status !== 'success') throw new Error(data.message || 'No se pudieron cargar los mensajes.');
        if (window.AppStore) AppStore.set({ selectedCrmConversationId: id, crmConversationMessages: data.messages || [] });
        else { state.selectedCrmConversationId = id; state.crmConversationMessages = data.messages || []; }
        renderCrmConversations();
    } catch (error) { showToast(error.message || 'No se pudieron cargar los mensajes.', 'error'); }
}

async function takeCrmConversation(id) {
    try {
        const data = await AppApi.updateChatbotAttention(id, 'humano');
        if (data.status !== 'success') throw new Error(data.message || 'No se pudo tomar la conversación.');
        await refreshCrmConversations();
        await selectCrmConversation(id);
        showToast('Conversación asignada a atención humana. El bot quedó pausado.', 'success');
    } catch (error) { showToast(error.message || 'No se pudo tomar la conversación.', 'error'); }
}

async function sendCrmHumanReply() {
    const content = document.getElementById('crm-human-reply-content')?.value.trim() || '';
    if (!content || !state.selectedCrmConversationId) return;
    try {
        const data = await AppApi.sendHumanWhatsAppReply(state.selectedCrmConversationId, content);
        if (data.status !== 'success') throw new Error(data.message || 'No se pudo enviar el mensaje.');
        document.getElementById('crm-human-reply-content').value = '';
        await selectCrmConversation(state.selectedCrmConversationId);
    } catch (error) { showToast(error.message || 'No se pudo enviar el mensaje. Verifica la ventana de atención de WhatsApp.', 'error'); }
}

function populatePosCustomerSelect() {
    const select = document.getElementById('order-customer-id');
    if (!select) return;
    const selected = select.value;
    select.innerHTML = '<option value="">Sin vincular a ficha CRM</option>';
    (state.customers || []).forEach(customer => {
        const option = document.createElement('option');
        option.value = customer.id;
        option.textContent = `${customer.name}${customer.phone ? ' · ' + customer.phone : ''}`;
        select.appendChild(option);
    });
    select.value = (state.customers || []).some(customer => customer.id === selected) ? selected : '';
}

function populateReservationCustomerSelect() {
    const select = document.getElementById('reservation-form-customer-id');
    if (!select) return;
    const selected = select.value;
    select.innerHTML = '<option value="">Sin vincular</option>';
    (state.customers || []).forEach(customer => {
        const option = document.createElement('option');
        option.value = customer.id;
        option.textContent = `${customer.name}${customer.phone ? ' · ' + customer.phone : ''}`;
        select.appendChild(option);
    });
    select.value = (state.customers || []).some(customer => customer.id === selected) ? selected : '';
}

async function loadPosCustomers() {
    if ((state.customers || []).length) { populatePosCustomerSelect(); return; }
    try { await refreshCustomers(); } catch (_) { /* POS remains available without CRM selection. */ }
}

function openCustomerForm(id = '') {
    const customer = (state.customers || []).find(item => item.id === id) || {};
    document.getElementById('customer-form-id').value = customer.id || '';
    document.getElementById('customer-form-name').value = customer.name || '';
    document.getElementById('customer-form-phone').value = customer.phone || '';
    document.getElementById('customer-form-whatsapp').value = customer.whatsapp_phone || '';
    document.getElementById('customer-form-email').value = customer.email || '';
    document.getElementById('customer-form-address').value = customer.address || '';
    document.getElementById('customer-form-credit').value = customer.credit_limit || 0;
    document.getElementById('customer-form-notes').value = customer.notes || '';
    document.getElementById('customer-form-marketing').checked = Number(customer.marketing_opt_in) === 1;
    document.getElementById('customer-form-title').textContent = id ? 'Editar cliente' : 'Nuevo cliente';
    document.getElementById('modal-customer-form').classList.add('open');
}

function closeCustomerForm() {
    document.getElementById('modal-customer-form')?.classList.remove('open');
}

async function saveCustomer(event) {
    event.preventDefault();
    try {
        const data = await AppApi.saveCustomer({
            id: document.getElementById('customer-form-id').value,
            name: document.getElementById('customer-form-name').value.trim(),
            phone: document.getElementById('customer-form-phone').value.trim(),
            whatsapp_phone: document.getElementById('customer-form-whatsapp').value.trim(),
            email: document.getElementById('customer-form-email').value.trim(),
            address: document.getElementById('customer-form-address').value.trim(),
            credit_limit: document.getElementById('customer-form-credit').value || 0,
            source: (state.customers || []).find(customer => customer.id === document.getElementById('customer-form-id').value)?.source || 'manual',
            notes: document.getElementById('customer-form-notes').value.trim(),
            marketing_opt_in: document.getElementById('customer-form-marketing').checked
        });
        if (data.status !== 'success') throw new Error(data.message || 'No se pudo guardar el cliente.');
        closeCustomerForm();
        await refreshCustomers();
        showToast('Cliente guardado.', 'success');
    } catch (error) {
        showToast(error.message || 'No se pudo guardar el cliente.', 'error');
    }
}

async function openCustomerProfile(id) {
    try {
        const data = await AppApi.getCustomerProfile(id);
        if (data.status !== 'success') throw new Error(data.message || 'No se pudo cargar la ficha.');
        if (window.AppStore) AppStore.set({ selectedCustomerProfile: data });
        else state.selectedCustomerProfile = data;
        renderCustomerProfile();
        document.getElementById('customer-profile')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (error) {
        showToast(error.message || 'No se pudo cargar la ficha.', 'error');
    }
}

async function saveCustomerInteraction(customerId) {
    const content = document.getElementById('customer-interaction-content')?.value.trim() || '';
    const type = document.getElementById('customer-interaction-type')?.value || 'nota';
    if (!content) { showToast('Escribe el detalle de la interacción.', 'warning'); return; }
    try {
        const data = await AppApi.saveCustomerInteraction({ customer_id: customerId, type, content });
        if (data.status !== 'success') throw new Error(data.message || 'No se pudo registrar la interacción.');
        await openCustomerProfile(customerId);
        showToast('Interacción registrada.', 'success');
    } catch (error) {
        showToast(error.message || 'No se pudo registrar la interacción.', 'error');
    }
}

async function saveCollectionTask(customerId) {
    const note = document.getElementById('customer-task-note')?.value.trim() || '';
    if (!note) { showToast('Escribe la acción de seguimiento.', 'warning'); return; }
    try {
        const data = await AppApi.saveCollectionTask({
            customer_id: customerId,
            receivable_id: document.getElementById('customer-task-receivable')?.value || '',
            due_date: document.getElementById('customer-task-date')?.value || '',
            note,
            status: 'pendiente'
        });
        if (data.status !== 'success') throw new Error(data.message || 'No se pudo crear la tarea.');
        await openCustomerProfile(customerId);
        showToast('Tarea de cobranza creada.', 'success');
    } catch (error) {
        showToast(error.message || 'No se pudo crear la tarea.', 'error');
    }
}

async function completeCollectionTask(customerId, taskId, note) {
    const task = (state.selectedCustomerProfile?.collection_tasks || []).find(item => item.id === taskId);
    try {
        const data = await AppApi.saveCollectionTask({
            id: taskId,
            customer_id: customerId,
            receivable_id: task?.receivable_id || '',
            due_date: task?.due_date || '',
            note: note || task?.note || 'Seguimiento completado',
            status: 'completada'
        });
        if (data.status !== 'success') throw new Error(data.message || 'No se pudo completar la tarea.');
        await openCustomerProfile(customerId);
        showToast('Tarea completada.', 'success');
    } catch (error) {
        showToast(error.message || 'No se pudo completar la tarea.', 'error');
    }
}

function closeCustomerProfile() {
    if (window.AppStore) AppStore.set({ selectedCustomerProfile: null });
    else state.selectedCustomerProfile = null;
    renderCustomerProfile();
}

window.searchCustomers = searchCustomers;
window.filterCustomerSegment = filterCustomerSegment;
window.refreshCrmConversations = refreshCrmConversations;
window.selectCrmConversation = selectCrmConversation;
window.takeCrmConversation = takeCrmConversation;
window.sendCrmHumanReply = sendCrmHumanReply;
window.openCustomerForm = openCustomerForm;
window.closeCustomerForm = closeCustomerForm;
window.saveCustomer = saveCustomer;
window.openCustomerProfile = openCustomerProfile;
window.closeCustomerProfile = closeCustomerProfile;
window.saveCustomerInteraction = saveCustomerInteraction;
window.saveCollectionTask = saveCollectionTask;
window.completeCollectionTask = completeCollectionTask;
window.populatePosCustomerSelect = populatePosCustomerSelect;
window.populateReservationCustomerSelect = populateReservationCustomerSelect;

document.addEventListener('DOMContentLoaded', () => {
    const customerSelect = document.getElementById('order-customer-id');
    customerSelect?.addEventListener('focus', loadPosCustomers);
    customerSelect?.addEventListener('change', () => {
        const customer = (state.customers || []).find(item => item.id === customerSelect.value);
        const input = document.getElementById('order-customer-name');
        if (customer && input) input.value = customer.name;
    });
    const reservationCustomerSelect = document.getElementById('reservation-form-customer-id');
    reservationCustomerSelect?.addEventListener('focus', loadPosCustomers);
    reservationCustomerSelect?.addEventListener('change', () => {
        const customer = (state.customers || []).find(item => item.id === reservationCustomerSelect.value);
        if (!customer) return;
        const name = document.getElementById('reservation-form-name');
        const phone = document.getElementById('reservation-form-phone');
        if (name) name.value = customer.name;
        if (phone && customer.phone) phone.value = customer.phone;
    });
});
