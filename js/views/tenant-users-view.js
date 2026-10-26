const ROLE_LABELS = { owner: 'Dueño', admin: 'Admin', cajero: 'Cajero', super_admin: 'Super Admin' };

function renderTenantUsers() {
    loadTenantUsers();
}

async function loadTenantUsers() {
    try {
        const data = await AppApi.request('get_tenant_users');
        renderTenantUsersTable(data.users || []);
        populateTenantBranchSelect(data.branches || []);
    } catch (err) {
        console.error('Error loading tenant users:', err);
        showToast('Error al cargar usuarios', 'error');
    }
}

function renderTenantUsersTable(users) {
    const tbody = document.getElementById('tenant-users-body');
    if (!tbody) return;
    if (users.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted);">No hay usuarios registrados.</td></tr>';
        return;
    }
    tbody.innerHTML = users.map(u => `
        <tr>
            <td><strong>${escapeHtml(u.name)}</strong></td>
            <td>${escapeHtml(u.email)}</td>
            <td><span class="admin-badge role-${u.role}">${ROLE_LABELS[u.role] || u.role}</span></td>
            <td>${escapeHtml(u.branch_name)}</td>
            <td><span class="admin-badge ${u.active ? 'active' : 'inactive'}">${u.active ? 'Activo' : 'Inactivo'}</span></td>
            <td class="admin-actions">
                <button class="btn btn-sm btn-outline" onclick="openEditTenantUser('${u.id}')" title="Editar"><i class="fa-solid fa-pen"></i></button>
                ${u.role !== 'owner' ? `<button class="btn btn-sm btn-outline" onclick="confirmDeleteTenantUser('${u.id}','${escapeHtml(u.name)}')" title="Eliminar"><i class="fa-solid fa-trash"></i></button>` : ''}
            </td>
        </tr>
    `).join('');
}

function populateTenantBranchSelect(branches) {
    const sel = document.getElementById('tenant-user-branch');
    if (!sel) return;
    sel.innerHTML = branches.map(b => `<option value="${b.id}">${escapeHtml(b.name)}</option>`).join('');
}

