export function customerOrderState(order) {
    const tracking = order?.tracking_status;
    const fulfillment = order?.fulfillment_status;
    const legacy = order?.status;

    if (tracking === 'canceled' || fulfillment === 'canceled' || ['canceled', 'rejected'].includes(legacy)) {
        return { group: 'canceled', label: legacy === 'rejected' ? 'Pedido rechazado' : 'Pedido cancelado', icon: 'pi pi-times-circle', classes: 'bg-slate-100 text-slate-700 ring-1 ring-slate-300' };
    }
    if (tracking === 'delivered' || tracking === 'picked_up' || fulfillment === 'delivered' || legacy === 'delivered') {
        return { group: 'completed', label: tracking === 'picked_up' || order?.delivery_type === 'pickup' ? 'Pedido recogido' : 'Pedido entregado', icon: 'pi pi-check-circle', classes: 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' };
    }
    if (tracking === 'shipped') {
        return { group: 'ready', label: 'Pedido en camino', icon: 'pi pi-truck', classes: 'bg-cyan-50 text-cyan-900 ring-1 ring-cyan-200' };
    }
    if (tracking === 'ready_for_pickup' || fulfillment === 'ready') {
        return { group: 'ready', label: order?.delivery_type === 'pickup' ? 'Listo para recoger' : 'Listo para entrega', icon: 'pi pi-box', classes: 'bg-cyan-50 text-cyan-900 ring-1 ring-cyan-200' };
    }
    if (tracking === 'processing' || fulfillment === 'preparing') {
        return { group: 'preparing', label: 'En preparación', icon: 'pi pi-cog', classes: 'bg-blue-50 text-blue-900 ring-1 ring-blue-200' };
    }
    if (tracking === 'confirmed' || legacy === 'confirmed') {
        return { group: 'pending', label: 'Pedido confirmado', icon: 'pi pi-check', classes: 'bg-blue-50 text-blue-900 ring-1 ring-blue-200' };
    }
    if (tracking === 'pending' || fulfillment === 'reserved' || legacy === 'pending') {
        return { group: 'pending', label: 'Pedido recibido', icon: 'pi pi-clock', classes: 'bg-amber-50 text-amber-900 ring-1 ring-amber-200' };
    }

    return { group: 'other', label: 'Estado en actualización', icon: 'pi pi-info-circle', classes: 'bg-slate-100 text-slate-700 ring-1 ring-slate-300' };
}

export function customerOrderNumber(id) {
    return String(id ?? '').padStart(6, '0');
}
