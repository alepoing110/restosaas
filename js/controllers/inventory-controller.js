// ==========================================================================
// SCREEN 4: INVENTORY STOCK CONTROLLER
// ==========================================================================

window.saveStockInline = async function(id, type) {
    let inputEl = null;
    let newCurrentStock = 0;

    if (type === 'segundo') {
        inputEl = document.getElementById(`stock-val-sec-${id}`);
        if (!inputEl) return;
        newCurrentStock = parseInt(inputEl.value);
        if (isNaN(newCurrentStock) || newCurrentStock < 0) return;

        const sec = state.seconds.find(s => s.id === id);
        if (sec) {
            let usage = countSegundoUsage(state.cart, id);
            state.activeOrders.forEach(o => { usage += countSegundoUsage(o.items, id); });
            state.salesHistory.forEach(s => {
                if (s.status === 'completado' || (s.status === 'pendiente' && s.paid)) usage += countSegundoUsage(s.items, id);
            });
            const newTotalStock = newCurrentStock + usage;
            const success = await window.saveItemOnServer('segundo', { id: id, name: sec.name, stock: newTotalStock });
            if (success) {
                showToast(`Stock de "${sec.name}" actualizado en base de datos.`, 'success');
                await window.loadStateForTab('inventory');
                renderInventoryTab();
            }
        }
    } else if (type === 'sopa') {
        inputEl = document.getElementById(`stock-val-sopa-${id}`);
        if (!inputEl) return;
        newCurrentStock = parseInt(inputEl.value);
        if (isNaN(newCurrentStock) || newCurrentStock < 0) return;

        const sopa = state.sopas.find(s => s.id === id);
        if (sopa) {
            let usage = countSopaUsage(state.cart, id);
            state.activeOrders.forEach(o => { usage += countSopaUsage(o.items, id); });
            state.salesHistory.forEach(s => {
                if (s.status === 'completado' || (s.status === 'pendiente' && s.paid)) usage += countSopaUsage(s.items, id);
            });
            const newTotalStock = newCurrentStock + usage;
            const success = await window.saveItemOnServer('sopa', { id: id, name: sopa.name, stock: newTotalStock, active: sopa.active ? 1 : 0 });
            if (success) {
                showToast(`Stock de "${sopa.name}" actualizado en base de datos.`, 'success');
                await window.loadStateForTab('inventory');
                renderInventoryTab();
            }
        }
    } else if (type === 'plato_extra') {
        inputEl = document.getElementById(`stock-val-pe-${id}`);
        if (!inputEl) return;
        newCurrentStock = parseInt(inputEl.value);
        if (isNaN(newCurrentStock) || newCurrentStock < 0) return;

        const plato = state.platosExtras.find(p => p.id === id);
        if (plato) {
            let usage = countPlatoExtraUsage(state.cart, id);
            state.activeOrders.forEach(o => { usage += countPlatoExtraUsage(o.items, id); });
            state.salesHistory.forEach(s => {
                if (s.status === 'completado' || (s.status === 'pendiente' && s.paid)) usage += countPlatoExtraUsage(s.items, id);
            });
            const newTotalStock = newCurrentStock + usage;
            const success = await window.saveItemOnServer('plato_extra', { id: id, name: plato.name, price: plato.price, stock: newTotalStock });
            if (success) {
                showToast(`Stock de "${plato.name}" actualizado en base de datos.`, 'success');
                await window.loadStateForTab('inventory');
                renderInventoryTab();
            }
        }
    } else if (type === 'extra') {
        inputEl = document.getElementById(`stock-val-ext-${id}`);
        if (!inputEl) return;
        let addEl = document.getElementById(`stock-add-ext-${id}`);
        const editStock = parseInt(inputEl.value);
        const addAmount = addEl ? (parseInt(addEl.value) || 0) : 0;

        if (isNaN(editStock) || editStock < 0) return;

        const ext = state.extras.find(e => e.id === id);
        if (ext) {
            let usage = countExtraUsage(state.cart, id);
            state.activeOrders.forEach(o => { usage += countExtraUsage(o.items, id); });
            state.salesHistory.forEach(s => {
                if (s.status === 'completado' || (s.status === 'pendiente' && s.paid)) usage += countExtraUsage(s.items, id);
            });
            const newTotalStock = editStock + addAmount + usage;
            const success = await window.saveItemOnServer('extra', { id: id, name: ext.name, price: ext.price, stock: newTotalStock });
            if (success) {
                if (addAmount > 0) {
                    showToast(`Stock de "${ext.name}" actualizado (+${addAmount} sumados).`, 'success');
                } else {
                    showToast(`Stock de "${ext.name}" actualizado en base de datos.`, 'success');
                }
                await window.loadStateForTab('inventory');
                renderInventoryTab();
            }
        }
    }
};


