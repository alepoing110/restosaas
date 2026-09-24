window.switchMenuConfigSubtab = function(subtabId) {
    document.querySelectorAll('.menu-config-subnav .subnav-btn').forEach(btn => {
        const isActive = btn.dataset.subtab === subtabId;
        btn.classList.toggle('active', isActive);
        if (isActive) {
            try {
                btn.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            } catch (e) {}
        }
    });

    document.querySelectorAll('.menu-config-panel').forEach(panel => {
        if (panel.id === `subtab-${subtabId}`) {
            panel.style.display = 'block';
        } else {
            panel.style.display = 'none';
        }
    });

    window.activeMenuConfigSubtab = subtabId;
    if (typeof window.saveUiContext === 'function') {
        const context = typeof window.getUiContext === 'function' ? window.getUiContext() : {};
        window.saveUiContext({ subtabs: { ...(context.subtabs || {}), 'menu-config': subtabId } });
    }

    if (subtabId === 'descuentos') {
        if (typeof window.PromoController === 'object' && window.PromoController.init) window.PromoController.init();
        if (typeof renderPromoPlans === 'function') renderPromoPlans();
    }
    if (subtabId === 'impresion' && window.PrintJobs?.refreshPrinters) window.PrintJobs.refreshPrinters();
};

// Segundos inline editing
window.startEditSegundo = function(id) {
    editingSegundoId = id;
    renderMenuConfig();
};
window.cancelSegundoInline = function() {
    editingSegundoId = null;
    renderMenuConfig();
};
window.saveSegundoInline = async function(id) {
    const input = document.getElementById(`edit-sec-name-${id}`);
    const salsaCheck = document.getElementById(`edit-sec-salsa-${id}`);
    const newName = input.value.trim();
    if (!newName) {
        showToast('El nombre no puede estar vacío.', 'warning');
        return;
    }
    const sec = state.seconds.find(s => s.id === id);
    if (sec) {
        const acceptsSalsa = salsaCheck ? (salsaCheck.checked ? 1 : 0) : (sec.accepts_salsa ? 1 : 0);
        const success = await saveItemOnServer('segundo', { id: id, name: newName, stock: sec.stock, accepts_salsa: acceptsSalsa });
        if (success) {
            editingSegundoId = null;
            showToast('Segundo actualizado.', 'success');
            await loadStateForTab('inventory');
            renderMenuConfig();
        }
    }
};

// Platos Extras inline editing
window.startEditPlato = function(id) {
    editingPlatoId = id;
    renderMenuConfig();
};
window.cancelPlatoInline = function() {
    editingPlatoId = null;
    renderMenuConfig();
};
window.savePlatoInline = async function(id) {
    const nameInput = document.getElementById(`edit-plato-name-${id}`);
    const priceInput = document.getElementById(`edit-plato-price-${id}`);
    const salsaCheck = document.getElementById(`edit-plato-salsa-${id}`);
    const newName = nameInput.value.trim();
    const newPrice = parseFloat(priceInput.value);

    if (!newName || isNaN(newPrice) || newPrice < 0) {
        showToast('Valores de plato extra inválidos.', 'warning');
        return;
    }
    const plato = state.platosExtras.find(p => p.id === id);
    if (plato) {
        const acceptsSalsa = salsaCheck ? (salsaCheck.checked ? 1 : 0) : (plato.accepts_salsa ? 1 : 0);
        const success = await saveItemOnServer('plato_extra', { id: id, name: newName, price: newPrice, stock: plato.stock, accepts_salsa: acceptsSalsa });
        if (success) {
            editingPlatoId = null;
            showToast('Plato Extra actualizado.', 'success');
            await loadStateForTab('inventory');
            renderMenuConfig();
        }
    }
};

// Gaseosas inline editing
window.startEditExtra = function(id) {
    editingExtraId = id;
    renderMenuConfig();
};
window.cancelExtraInline = function() {
    editingExtraId = null;
    renderMenuConfig();
};
window.saveExtraInline = async function(id) {
    const nameInput = document.getElementById(`edit-extra-name-${id}`);
    const priceInput = document.getElementById(`edit-extra-price-${id}`);
    const newName = nameInput.value.trim();
    const newPrice = parseFloat(priceInput.value);

    if (!newName || isNaN(newPrice) || newPrice < 0) {
        showToast('Valores de bebida inválidos.', 'warning');
        return;
    }
    const ext = state.extras.find(e => e.id === id);
    if (ext) {
        const success = await saveItemOnServer('extra', { id: id, name: newName, price: newPrice, stock: ext.stock });
        if (success) {
            editingExtraId = null;
            showToast('Bebida actualizada.', 'success');
            await loadStateForTab('inventory');
            renderMenuConfig();
        }
    }
};

// CRUD creation methods
async function handleSavePrices(e) {
    e.preventDefault();
    const almuerzoPrice = parseFloat(document.getElementById('price-almuerzo-input').value);
    const segundoPrice = parseFloat(document.getElementById('price-segundo-input').value);
    const sopaPrice = parseFloat(document.getElementById('price-sopa-input').value);

    if (isNaN(almuerzoPrice) || isNaN(segundoPrice) || isNaN(sopaPrice)) {
        showToast('Ingrese precios numéricos válidos.', 'error');
        return;
    }

    try {
        const data = await AppApi.request('save_prices', { almuerzo: almuerzoPrice, segundo: segundoPrice, sopa: sopaPrice });
        showToast('Precios de venta base actualizados.', 'success');
        await loadStateForTab('menu-config');
        renderMenuConfig();
    } catch (err) {
        console.error('save_prices error:', err);
        showToast(err.message || 'Error al guardar precios.', 'error');
    }
}
window.handleSavePrices = handleSavePrices;

async function handleAddSegundo(e) {
    e.preventDefault();
    const nameInput = document.getElementById('new-segundo-name');
    const name = nameInput.value.trim();
    if (!name) return;

    const success = await saveItemOnServer('segundo', { id: generateId(), name: name, stock: 20 });
    if (success) {
        nameInput.value = '';
        showToast(`Segundo "${name}" agregado.`, 'success');
        await loadStateForTab('inventory');
        renderMenuConfig();
    } else {
        showToast('Error al agregar el segundo.', 'error');
    }
}
window.handleAddSegundo = handleAddSegundo;

window.deleteSegundo = async function(segundoId) {
    const sec = state.seconds.find(s => s.id === segundoId);
    if (!sec) return;
    
    const confirmed = await window.ConfirmDialog.show(
        `¿Eliminar plato "${sec.name}" del menú?`,
        { title: 'Eliminar Plato', confirmText: 'Sí, eliminar', type: 'danger' }
    );
    
    if (confirmed) {
        const success = await deleteItemOnServer('segundo', segundoId);
        if (success) {
            showToast(`Plato "${sec.name}" eliminado.`, 'info');
            await loadStateForTab('inventory');
            renderMenuConfig();
        }
    }
};

window.toggleSegundoActive = async function(segundoId, newActive) {
    const sec = state.seconds.find(s => s.id === segundoId);
    if (!sec) return;

    const success = await saveItemOnServer('segundo', {
        id: sec.id,
        name: sec.name,
        stock: sec.stock,
        active: newActive ? 1 : 0
    });
    if (success) {
        sec.active = newActive;
        showToast(`"${sec.name}" ${newActive ? 'activado' : 'desactivado'}.`, 'info');
        renderMenuConfig();
    }
};

// ==========================================================================
// SOPAS CRUD
// ==========================================================================

window.startEditSopa = function(id) {
    editingSopaId = id;
    renderMenuConfig();
};
window.cancelSopaInline = function() {
    editingSopaId = null;
    renderMenuConfig();
};
window.saveSopaInline = async function(id) {
    const input = document.getElementById(`edit-sopa-name-${id}`);
    const salsaCheck = document.getElementById(`edit-sopa-salsa-${id}`);
    const newName = input.value.trim();
    if (!newName) {
        showToast('El nombre no puede estar vacío.', 'warning');
        return;
    }
    const sopa = state.sopas.find(s => s.id === id);
    if (sopa) {
        const acceptsSalsa = salsaCheck ? (salsaCheck.checked ? 1 : 0) : (sopa.accepts_salsa ? 1 : 0);
        const success = await saveItemOnServer('sopa', { id: id, name: newName, stock: sopa.stock, active: sopa.active ? 1 : 0, accepts_salsa: acceptsSalsa });
        if (success) {
            editingSopaId = null;
            showToast('Sopa actualizada.', 'success');
            await loadStateForTab('inventory');
            renderMenuConfig();
        }
    }
};

async function handleAddSopa(e) {
    e.preventDefault();
    const nameInput = document.getElementById('new-sopa-name');
    const name = nameInput.value.trim();
    if (!name) return;

    const success = await saveItemOnServer('sopa', { id: generateId(), name: name, stock: 20, active: 1 });
    if (success) {
        nameInput.value = '';
        showToast(`Sopa "${name}" agregada.`, 'success');
        await loadStateForTab('inventory');
        renderMenuConfig();
    } else {
        showToast('Error al agregar la sopa.', 'error');
    }
}
window.handleAddSopa = handleAddSopa;

window.deleteSopa = async function(sopaId) {
    const sopa = state.sopas.find(s => s.id === sopaId);
    if (!sopa) return;
    
    const confirmed = await window.ConfirmDialog.show(
        `¿Eliminar sopa "${sopa.name}"?`,
        { title: 'Eliminar Sopa', confirmText: 'Sí, eliminar', type: 'danger' }
    );
    
    if (confirmed) {
        const success = await deleteItemOnServer('sopa', sopaId);
        if (success) {
            showToast(`Sopa "${sopa.name}" eliminada.`, 'info');
            await loadStateForTab('inventory');
            renderMenuConfig();
        }
    }
};

window.toggleSopaActive = async function(sopaId, newActive) {
    const sopa = state.sopas.find(s => s.id === sopaId);
    if (!sopa) return;

    const success = await saveItemOnServer('sopa', {
        id: sopa.id,
        name: sopa.name,
        stock: sopa.stock,
        active: newActive ? 1 : 0
    });
    if (success) {
        sopa.active = newActive;
        showToast(`"${sopa.name}" ${newActive ? 'activada' : 'desactivada'}.`, 'info');
        renderMenuConfig();
    }
};

async function handleAddPlatoExtra(e) {
    e.preventDefault();
    const nameInput = document.getElementById('new-platoextra-name');
    const priceInput = document.getElementById('new-platoextra-price');
    const name = nameInput.value.trim();
    const priceVal = parseFloat(priceInput.value);

    if (!name || isNaN(priceVal) || priceVal < 0) {
        showToast('Valores de plato extra inválidos.', 'warning');
        return;
    }

    const success = await saveItemOnServer('plato_extra', { id: generateId(), name: name, price: priceVal, stock: 10 });
    if (success) {
        nameInput.value = '';
        priceInput.value = '25';
        showToast(`Plato Extra "${name}" registrado.`, 'success');
        await loadStateForTab('inventory');
        renderMenuConfig();
    } else {
        showToast('Error al agregar el plato extra.', 'error');
    }
}
window.handleAddPlatoExtra = handleAddPlatoExtra;

window.deletePlatoExtra = async function(platoId) {
    const plato = state.platosExtras.find(p => p.id === platoId);
    if (!plato) return;
    
    const confirmed = await window.ConfirmDialog.show(
        `¿Eliminar plato extra "${plato.name}"?`,
        { title: 'Eliminar Plato Extra', confirmText: 'Sí, eliminar', type: 'danger' }
    );
    
    if (confirmed) {
        const success = await deleteItemOnServer('plato_extra', platoId);
        if (success) {
            showToast(`Plato Extra "${plato.name}" eliminado.`, 'info');
            await loadStateForTab('inventory');
            renderMenuConfig();
        }
    }
};

async function handleAddExtra(e) {
    e.preventDefault();
    const nameInput = document.getElementById('new-extra-name');
    const priceInput = document.getElementById('new-extra-price');
    const name = nameInput.value.trim();
    const priceVal = parseFloat(priceInput.value);

    if (!name || isNaN(priceVal) || priceVal < 0) {
        showToast('Valores de bebida inválidos.', 'warning');
        return;
    }

    const success = await saveItemOnServer('extra', { id: generateId(), name: name, price: priceVal, stock: 24 });
    if (success) {
        nameInput.value = '';
        priceInput.value = '5';
        showToast(`Bebida "${name}" registrada.`, 'success');
        await loadStateForTab('inventory');
        renderMenuConfig();
    }
}
window.handleAddExtra = handleAddExtra;

window.deleteExtra = async function(extraId) {
    const ext = state.extras.find(e => e.id === extraId);
    if (!ext) return;
    
    const confirmed = await window.ConfirmDialog.show(
        `¿Eliminar bebida "${ext.name}"?`,
        { title: 'Eliminar Bebida', confirmText: 'Sí, eliminar', type: 'danger' }
    );
    
    if (confirmed) {
        const success = await deleteItemOnServer('extra', extraId);
        if (success) {
            showToast(`Bebida "${ext.name}" eliminada.`, 'info');
            await loadStateForTab('inventory');
            renderMenuConfig();
        }
    }
};

// ==========================================================================
// MENUS MANAGEMENT
// ==========================================================================

window.startEditMenu = function(menuId) {
    window.editingMenuId = menuId;
    renderMenuConfig();
};

window.cancelMenuInline = function() {
    window.editingMenuId = null;
    renderMenuConfig();
};

window.saveMenuInline = async function(menuId) {
    const menu = state.menus.find(m => m.id === menuId);
    if (!menu) return;

    const name = document.getElementById(`edit-menu-name-${menuId}`)?.value.trim() || '';
    const startTime = document.getElementById(`edit-menu-start-${menuId}`)?.value || '';
    const endTime = document.getElementById(`edit-menu-end-${menuId}`)?.value || '';
    if (!name) {
        showToast('El nombre del menú es requerido.', 'warning');
        return;
    }
    if (Boolean(startTime) !== Boolean(endTime)) {
        showToast('Indique ambos horarios o déjelos vacíos para todo el día.', 'warning');
        return;
    }

    try {
        await AppApi.request('save_menu', {
            id: menu.id,
            name,
            start_time: startTime || null,
            end_time: endTime || null,
            active: Number(menu.active) !== 0 ? 1 : 0
        });
        window.editingMenuId = null;
        showToast(`Menú "${name}" actualizado.`, 'success');
        await loadStateForTab('menu-config');
        renderMenuConfig();
    } catch (e) {
        showToast(e.message || 'Error al actualizar el menú.', 'error');
    }
};

async function handleAddMenu(e) {
    e.preventDefault();
    const nameInput = document.getElementById('new-menu-name');
    const startInput = document.getElementById('new-menu-start');
    const endInput = document.getElementById('new-menu-end');
    
    const name = nameInput.value.trim();
    if (!name) {
        showToast('El nombre del menú es requerido.', 'warning');
        return;
    }

    const menuId = 'menu_' + Date.now();
    const startTime = startInput.value || null;
    const endTime = endInput.value || null;

    try {
        const data = await AppApi.request('save_menu', {
            id: menuId,
            name: name,
            start_time: startTime,
            end_time: endTime,
            active: 1
        });
        
        if (data.status === 'success') {
            nameInput.value = '';
            startInput.value = '';
            endInput.value = '';
            showToast(`Menú "${name}" creado.`, 'success');
            await loadStateForTab('menu-config');
            renderMenuConfig();
        } else {
            showToast('Error al crear el menú.', 'error');
        }
    } catch (e) {
        showToast('Error de conexión al crear menú.', 'error');
    }
}
window.handleAddMenu = handleAddMenu;

window.toggleMenuActive = async function(menuId, newActive) {
    const menu = state.menus.find(m => m.id === menuId);
    if (!menu) return;

    try {
        const data = await AppApi.request('save_menu', {
            id: menuId,
            name: menu.name,
            start_time: menu.start_time,
            end_time: menu.end_time,
            active: newActive ? 1 : 0
        });
        
        if (data.status === 'success') {
            showToast(`Menú "${menu.name}" ${newActive ? 'activado' : 'desactivado'}.`, 'info');
            await loadStateForTab('menu-config');
            renderMenuConfig();
        }
    } catch (e) {
        showToast('Error al actualizar menú.', 'error');
    }
};

window.deleteMenu = async function(menuId) {
    const menu = state.menus.find(m => m.id === menuId);
    if (!menu) return;

    const confirmed = await window.ConfirmDialog.show(
        `¿Eliminar menú "${menu.name}"? Los productos se moverán al menú por defecto.`,
        { title: 'Eliminar Menú', confirmText: 'Sí, eliminar', type: 'danger' }
    );

    if (confirmed) {
        try {
            const data = await AppApi.request('delete_menu', { id: menuId });
            if (data.status === 'success') {
                showToast(`Menú "${menu.name}" eliminado.`, 'info');
                await loadStateForTab('menu-config');
                renderMenuConfig();
            }
        } catch (e) {
            showToast('Error al eliminar menú.', 'error');
        }
    }
};

// ==========================================================================
// MENU PRODUCTS ASSIGNMENT MODAL
// ==========================================================================

let currentMenuId = null;
let currentMenuFilter = 'all';

window.openMenuProductsModal = function(menuId) {
    currentMenuId = menuId;
    currentMenuFilter = 'all';
    
    const menu = state.menus.find(m => m.id === menuId);
    if (!menu) return;
    
    const title = document.getElementById('modal-menu-products-title');
    if (title) title.textContent = `Productos en: ${menu.name}`;
    
    const searchInput = document.getElementById('menu-products-search-input');
    if (searchInput) searchInput.value = '';
    
    updateFilterCounts();
    renderMenuProductsList();
    
    openModal('modal-menu-products');
};

window.closeMenuProductsModal = function() {
    closeModal('modal-menu-products');
    currentMenuId = null;
};

function updateFilterCounts() {
    const products = state.products || [];
    const types = ['sopa', 'segundo', 'plato_extra'];
    let totalAll = 0, assignedAll = 0;
    
    types.forEach(type => {
        const byType = products.filter(p => p.type === type);
        const assigned = byType.filter(p => p.menu_id === currentMenuId).length;
        const el = document.getElementById(`count-${type}`);
        if (el) el.textContent = `${assigned}/${byType.length}`;
        totalAll += byType.length;
        assignedAll += assigned;
    });
    
    const allEl = document.getElementById('count-all');
    if (allEl) allEl.textContent = `${assignedAll}/${totalAll}`;
}

function getFilteredProducts() {
    const products = state.products || [];
    const searchInput = document.getElementById('menu-products-search-input');
    const query = (searchInput ? searchInput.value : '').toLowerCase().trim();
    
    let filtered = products.filter(p => p.type !== 'refresco');
    if (currentMenuFilter !== 'all') {
        filtered = filtered.filter(p => p.type === currentMenuFilter);
    }
    
    if (query) {
        filtered = filtered.filter(p => p.name.toLowerCase().includes(query));
    }
    
    return filtered;
}

const TYPE_LABELS = { sopa: 'Sopas', segundo: 'Segundos', plato_extra: 'Platos Extras' };
const TYPE_ICONS = { sopa: 'fa-bowl-food', segundo: 'fa-plate-wheat', plato_extra: 'fa-utensils' };
const TYPE_ORDER = ['sopa', 'segundo', 'plato_extra'];

function renderMenuProductsList() {
    const container = document.getElementById('menu-products-list');
    if (!container) return;
    
    container.innerHTML = '';
    
    const filtered = getFilteredProducts();
    const summaryEl = document.getElementById('menu-products-summary');
    const assignedCount = filtered.filter(p => p.menu_id === currentMenuId).length;
    if (summaryEl) {
        summaryEl.textContent = `${assignedCount} de ${filtered.length} asignados`;
    }
    
    if (filtered.length === 0) {
        container.innerHTML = '<div class="menu-products-empty"><i class="fa-solid fa-box-open"></i><p>No se encontraron productos.</p></div>';
        return;
    }
    
    if (currentMenuFilter === 'all') {
        TYPE_ORDER.forEach(type => {
            const group = filtered.filter(p => p.type === type);
            if (group.length === 0) return;
            
            const sectionAssigned = group.filter(p => p.menu_id === currentMenuId).length;
            const header = document.createElement('div');
            header.className = 'menu-products-group-header';
            header.innerHTML = `<i class="fa-solid ${TYPE_ICONS[type]}"></i> ${TYPE_LABELS[type]} <span class="menu-products-group-count">${sectionAssigned}/${group.length}</span>`;
            container.appendChild(header);
            
            group.forEach(product => container.appendChild(createProductItem(product)));
        });
    } else {
        filtered.forEach(product => container.appendChild(createProductItem(product)));
    }
}

function createProductItem(product) {
    const isAssigned = product.menu_id === currentMenuId;
    
    const item = document.createElement('div');
    item.className = `menu-product-item ${isAssigned ? 'assigned' : ''}`;
    item.dataset.productId = product.id;
    
    item.innerHTML = `
        <div class="menu-product-info">
            <span class="menu-product-name">${escapeHtml(product.name)}</span>
            <div class="menu-product-meta">
                <span class="menu-product-type-badge ${product.type}">${TYPE_LABELS[product.type] || product.type}</span>
                <span>Bs ${product.price.toFixed(2)}</span>
                <span>Stock: ${product.stock}</span>
            </div>
        </div>
        <label class="toggle-switch">
            <input type="checkbox" ${isAssigned ? 'checked' : ''} 
                onchange="toggleProductMenuAssignment('${product.id}', this.checked)">
            <span class="toggle-slider"></span>
        </label>
    `;
    
    return item;
}

window.toggleProductMenuAssignment = async function(productId, assign) {
    const product = state.products.find(p => p.id === productId);
    if (!product) return;
    
    const newMenuId = assign ? currentMenuId : null;
    
    try {
        const data = await AppApi.request('save_product', {
            id: product.id,
            name: product.name,
            type: product.type,
            price: product.price,
            stock: product.stock,
            menu_id: newMenuId
        });
        
        if (data.status === 'success') {
            product.menu_id = newMenuId;
            
            const item = document.querySelector(`.menu-product-item[data-product-id="${productId}"]`);
            if (item) item.classList.toggle('assigned', assign);
            
            updateFilterCounts();
            updateBulkSummary();
        }
    } catch (e) {
        showToast('Error al actualizar producto.', 'error');
    }
};

window.bulkAssignMenuProducts = async function(assign) {
    const visible = getFilteredProducts().filter(p => (p.menu_id === currentMenuId) !== assign);
    if (visible.length === 0) return;
    
    const newMenuId = assign ? currentMenuId : null;
    let successCount = 0;
    
    for (const product of visible) {
        try {
            const data = await AppApi.request('save_product', {
                id: product.id,
                name: product.name,
                type: product.type,
                price: product.price,
                stock: product.stock,
                menu_id: newMenuId
            });
            if (data.status === 'success') {
                product.menu_id = newMenuId;
                successCount++;
            }
        } catch (e) { /* skip */ }
    }
    
    renderMenuProductsList();
    updateFilterCounts();
    showToast(`${successCount} producto${successCount !== 1 ? 's' : ''} ${assign ? 'asignado(s)' : 'removido(s)'}`, 'success');
};

function updateBulkSummary() {
    const filtered = getFilteredProducts();
    const assignedCount = filtered.filter(p => p.menu_id === currentMenuId).length;
    const summaryEl = document.getElementById('menu-products-summary');
    if (summaryEl) summaryEl.textContent = `${assignedCount} de ${filtered.length} asignados`;
}

// Search input handler
document.addEventListener('input', (e) => {
    if (e.target.id === 'menu-products-search-input') {
        renderMenuProductsList();
    }
});

// Filter buttons in modal
document.addEventListener('click', (e) => {
    const filterBtn = e.target.closest('.menu-filter-btn');
    if (!filterBtn) return;
    
    document.querySelectorAll('.menu-filter-btn').forEach(b => b.classList.remove('active'));
    filterBtn.classList.add('active');
    
    currentMenuFilter = filterBtn.dataset.filter;
    renderMenuProductsList();
});

// ==========================================================================
// ACCEPTS SALSA TOGGLE
// ==========================================================================

window.toggleAcceptsSalsa = async function(type, id, newValue) {
    const acceptsSalsa = newValue ? 1 : 0;
    let item, success;

    if (type === 'segundo') {
        item = state.seconds.find(s => s.id === id);
        if (item) success = await saveItemOnServer('segundo', { id, name: item.name, stock: item.stock, active: item.active ? 1 : 0, accepts_salsa: acceptsSalsa });
    } else if (type === 'sopa') {
        item = state.sopas.find(s => s.id === id);
        if (item) success = await saveItemOnServer('sopa', { id, name: item.name, stock: item.stock, active: item.active ? 1 : 0, accepts_salsa: acceptsSalsa });
    } else if (type === 'plato_extra') {
        item = state.platosExtras.find(p => p.id === id);
        if (item) success = await saveItemOnServer('plato_extra', { id, name: item.name, price: item.price, stock: item.stock, accepts_salsa: acceptsSalsa });
    }

    if (success) {
        showToast(newValue ? 'Salsa habilitada para este producto.' : 'Salsa deshabilitada.', 'success');
        await loadStateForTab('inventory');
        renderMenuConfig();
    }
};

window.toggleAcceptsAccompaniment = async function(type, id, enabled, checkbox = null) {
    const collection = type === 'segundo' ? state.seconds : type === 'sopa' ? state.sopas : state.platosExtras;
    const item = collection.find(entry => entry.id === id);
    if (!item) return;
    const maxIncluded = enabled ? Number(item.max_included_accompaniments || 0) : 0;
    const payload = { id, name: item.name, stock: item.stock, accepts_accompaniment: enabled ? 1 : 0, max_included_accompaniments: maxIncluded };
    if (type === 'plato_extra') payload.price = item.price;
    const success = await saveItemOnServer(type, payload);
    if (success) {
        await loadStateForTab('inventory');
        renderMenuConfig();
        showToast(enabled ? `Acompañamientos habilitados para "${item.name}".` : `Acompañamientos deshabilitados para "${item.name}".`, 'success');
    } else if (checkbox) {
        checkbox.checked = !enabled;
    }
};

window.saveAccompanimentSettings = async function(type, id) {
    const collection = type === 'segundo' ? state.seconds : type === 'sopa' ? state.sopas : state.platosExtras;
    const item = collection.find(entry => entry.id === id);
    const enabled = document.getElementById(`accompaniment-enabled-${id}`)?.checked;
    const maxIncluded = Number(document.getElementById(`accompaniment-limit-${id}`)?.value);
    if (!item || enabled === undefined) return;
    if (!Number.isInteger(maxIncluded) || maxIncluded < 0 || maxIncluded > 20) return showToast('El máximo incluido debe ser un entero entre 0 y 20.', 'warning');
    const payload = { id, name: item.name, stock: item.stock, accepts_accompaniment: enabled ? 1 : 0, max_included_accompaniments: enabled ? maxIncluded : 0 };
    if (type === 'plato_extra') payload.price = item.price;
    if (await saveItemOnServer(type, payload)) {
        await loadStateForTab('inventory');
        renderMenuConfig();
        showToast('Configuración de acompañamientos actualizada.', 'success');
    }
};

document.getElementById('form-add-accompaniment')?.addEventListener('submit', async event => {
    event.preventDefault();
    const name = document.getElementById('new-accompaniment-name').value.trim().replace(/\s+/g, ' ');
    const price = Number(document.getElementById('new-accompaniment-price').value);
    if (!name || name.length > 100 || !Number.isFinite(price) || price < 0 || price > 99999999.99 || Math.round(price * 100) !== price * 100) return showToast('Datos de acompañamiento inválidos.', 'warning');
    if (await saveItemOnServer('acompanamiento', { id: generateId(), name, price, active: 1 })) {
        event.target.reset();
        await loadStateForTab('inventory');
        renderMenuConfig();
        showToast(`Acompañamiento "${name}" añadido correctamente.`, 'success');
    }
});
window.startEditAccompaniment = function(id) {
    window.editingAccompanimentId = id;
    renderMenuConfig();
};
window.cancelAccompanimentInline = function() {
    window.editingAccompanimentId = null;
    renderMenuConfig();
};
window.saveAccompanimentInline = async function(id) {
    const item = (state.accompaniments || []).find(entry => entry.id === id);
    const name = document.getElementById(`edit-accompaniment-name-${id}`)?.value.trim().replace(/\s+/g, ' ') || '';
    const price = Number(document.getElementById(`edit-accompaniment-price-${id}`)?.value);
    if (!item) return;
    if (!name || name.length > 100 || !Number.isFinite(price) || price < 0 || price > 99999999.99 || Math.round(price * 100) !== price * 100) {
        return showToast('Datos de acompañamiento inválidos.', 'warning');
    }
    if (await saveItemOnServer('acompanamiento', { id, name, price, active: Number(item.active) !== 0 ? 1 : 0 })) {
        window.editingAccompanimentId = null;
        await loadStateForTab('inventory');
        renderMenuConfig();
        showToast('Acompañamiento actualizado.', 'success');
    }
};
window.toggleAccompanimentActive = async function(id, active) {
    const item = (state.accompaniments || []).find(entry => entry.id === id);
        if (item && await saveItemOnServer('acompanamiento', { id, name: item.name, price: item.price_extra, active: active ? 1 : 0 })) {
        await loadStateForTab('inventory');
        renderMenuConfig();
        showToast(active ? `Acompañamiento "${item.name}" activado.` : `Acompañamiento "${item.name}" desactivado.`, 'success');
    }
};
window.deleteAccompaniment = async function(id) {
    const item = (state.accompaniments || []).find(entry => entry.id === id);
    if (await deleteItemOnServer('acompanamiento', id)) {
        await loadStateForTab('inventory');
        renderMenuConfig();
        showToast(`Acompañamiento "${item?.name || ''}" eliminado.`, 'success');
    }
};

// ==========================================================================
// SALSAS CRUD
// ==========================================================================

window.startEditSalsa = function(id) {
    editingSalsaId = id;
    renderMenuConfig();
};
window.cancelSalsaInline = function() {
    editingSalsaId = null;
    renderMenuConfig();
};
window.saveSalsaInline = async function(id) {
    const nameInput = document.getElementById(`edit-salsa-name-${id}`);
    const priceInput = document.getElementById(`edit-salsa-price-${id}`);
    const newName = nameInput.value.trim();
    const newPrice = parseFloat(priceInput.value);

    if (!newName || isNaN(newPrice) || newPrice < 0) {
        showToast('Valores de salsa inválidos.', 'warning');
        return;
    }
    const salsa = (state.salsas || []).find(s => s.id === id);
    if (salsa) {
        const success = await saveItemOnServer('salsa', { id, name: newName, price: newPrice, active: salsa.active ? 1 : 0 });
        if (success) {
            editingSalsaId = null;
            showToast('Salsa actualizada.', 'success');
            await loadStateForTab('inventory');
            renderMenuConfig();
        }
    }
};

window.toggleSalsaActive = async function(id, newActive) {
    const salsa = (state.salsas || []).find(s => s.id === id);
    if (salsa) {
        const success = await saveItemOnServer('salsa', { id, name: salsa.name, price: salsa.price, active: newActive ? 1 : 0 });
        if (success) {
            showToast(newActive ? 'Salsa activada.' : 'Salsa desactivada.', 'success');
            await loadStateForTab('inventory');
            renderMenuConfig();
        }
    }
};

window.deleteSalsa = async function(id) {
    const confirmed = await window.ConfirmDialog.show(
        '¿Eliminar esta salsa?',
        { title: 'Eliminar Salsa', confirmText: 'Sí, eliminar', type: 'danger' }
    );
    if (!confirmed) return;
    try {
        const data = await AppApi.request('delete_item', { type: 'salsa', id });
        if (data.status === 'success') {
            showToast('Salsa eliminada.', 'info');
            await loadStateForTab('inventory');
            renderMenuConfig();
        }
    } catch (e) {
        showToast('Error al eliminar salsa.', 'error');
    }
};

async function handleAddSalsa(e) {
    e.preventDefault();
    const nameInput = document.getElementById('new-salsa-name');
    const priceInput = document.getElementById('new-salsa-price');
    const name = nameInput.value.trim();
    const price = Number(priceInput.value);
    if (!name || name.length > 100 || !Number.isFinite(price) || price < 0 || price > 99999999.99 || Math.round(price * 100) !== price * 100) return showToast('Valores de salsa inválidos.', 'warning');

    const success = await saveItemOnServer('salsa', { id: generateId(), name, price, active: 1 });
    if (success) {
        nameInput.value = '';
        priceInput.value = '0';
        showToast('Salsa creada.', 'success');
        await loadStateForTab('inventory');
        renderMenuConfig();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const bindings = [
        ['form-add-salsa', handleAddSalsa],
        ['form-add-segundo', handleAddSegundo],
        ['form-add-sopa', handleAddSopa],
        ['form-add-plato-extra', handleAddPlatoExtra],
        ['form-add-extra', handleAddExtra],
        ['form-add-menu', handleAddMenu],
        ['form-prices', handleSavePrices],
    ];
    bindings.forEach(([id, handler]) => {
        const form = document.getElementById(id);
        if (form) form.addEventListener('submit', handler);
    });
});
