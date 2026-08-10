document.addEventListener('DOMContentLoaded', function () {
    const createBtn = document.getElementById('btn-create-tenant-user');
    if (createBtn) {
        createBtn.addEventListener('click', openCreateTenantUser);
    }

    const form = document.getElementById('form-tenant-user');
    if (form) {
        form.addEventListener('submit', handleSaveTenantUser);
    }
});

function openCreateTenantUser() {
    document.getElementById('modal-tenant-user-title').textContent = 'Nuevo Usuario';
    document.getElementById('tenant-user-edit-id').value = '';
    document.getElementById('form-tenant-user').reset();
    document.getElementById('tenant-user-password').required = true;
    document.getElementById('tenant-user-password-hint').textContent = '';
    loadTenantUsers();
    openModal('modal-tenant-user');
}

function openEditTenantUser(id) {
    const tbody = document.getElementById('tenant-users-body');
    const rows = tbody.querySelectorAll('tr');
    let user = null;

    // Fetch users from API to get full data
    AppApi.request('get_tenant_users').then(data => {
        user = (data.users || []).find(u => u.id === id);
        if (!user) return;

        document.getElementById('modal-tenant-user-title').textContent = 'Editar Usuario';
        document.getElementById('tenant-user-edit-id').value = user.id;
        document.getElementById('tenant-user-name').value = user.name;
        document.getElementById('tenant-user-email').value = user.email;
        document.getElementById('tenant-user-role').value = user.role;
        document.getElementById('tenant-user-password').required = false;
        document.getElementById('tenant-user-password').value = '';
        document.getElementById('tenant-user-password-hint').textContent = '(dejar vacío para no cambiar)';

        loadTenantUsers().then(() => {
            const branchSel = document.getElementById('tenant-user-branch');
            const option = Array.from(branchSel.options).find(o => o.text === user.branch_name);
            if (option) branchSel.value = option.value;
        });

        openModal('modal-tenant-user');
    });
}

async function handleSaveTenantUser(e) {
    e.preventDefault();
    const editId = document.getElementById('tenant-user-edit-id').value;
    const payload = {
        name: document.getElementById('tenant-user-name').value.trim(),
        email: document.getElementById('tenant-user-email').value.trim(),
        role: document.getElementById('tenant-user-role').value,
        branch_id: document.getElementById('tenant-user-branch').value,
        password: document.getElementById('tenant-user-password').value
    };

    try {
        if (editId) {
            payload.id = editId;
            payload.active = 1;
            await AppApi.request('edit_tenant_user', payload);
            showToast('Usuario actualizado', 'success');
        } else {
            if (!payload.password) {
                showToast('La contraseña es requerida', 'error');
                return;
            }
            await AppApi.request('create_tenant_user', payload);
            showToast('Usuario creado', 'success');
        }
        closeModal('modal-tenant-user');
        await loadTenantUsers();
    } catch (err) {
        showToast(err.message || 'Error al guardar', 'error');
    }
}

async function confirmDeleteTenantUser(id, name) {
    const msg = `¿Eliminar al usuario "${name}"?`;
    const confirmed = await window.ConfirmDialog.show(msg, { title: 'Eliminar Usuario', confirmText: 'Sí, eliminar' });
    if (confirmed) {
        deleteTenantUser(id);
    }
}

async function deleteTenantUser(id) {
    try {
        await AppApi.request('delete_tenant_user', { id });
        showToast('Usuario eliminado', 'success');
        await loadTenantUsers();
    } catch (err) {
        showToast(err.message || 'Error al eliminar', 'error');
    }
}
