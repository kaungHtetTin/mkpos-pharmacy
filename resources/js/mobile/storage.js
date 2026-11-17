const PREFIX = 'pharmacy.cashier.';

let currentUser = null;
let currentBusiness = null;
export function configureBusinessStorage(userId, businessId) { currentUser=userId || null; currentBusiness=businessId || null; }
export function storageBusinessId() { return currentBusiness; }
const scoped = suffix => {
    if (!currentUser || !currentBusiness) throw new Error('Select a business before reading cashier data.');
    return `${PREFIX}${currentUser}.${currentBusiness}.${suffix}`;
};
export const keys = {
    token: `${PREFIX}token`,
    profile: `${PREFIX}profile`,
    products: branchId => scoped(`products.${branchId || 'none'}`),
    get customers() { return scoped('customers'); },
    session: branchId => scoped(`session.${branchId || 'none'}`),
    cart: (userId, branchId) => scoped(`cart.${userId}.${branchId || 'none'}`),
    get pendingSales() { return scoped('pending-sales'); },
};

export function loadJson(key, fallback = null) {
    try {
        const value = localStorage.getItem(key);
        return value ? JSON.parse(value) : fallback;
    } catch {
        return fallback;
    }
}

export function saveJson(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage can be unavailable in private browsing. The live session still works.
    }
}

export function makeReference() {
    const id = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    return `pwa-sale-${id}`;
}

export function getPendingSales() {
    return loadJson(keys.pendingSales, []);
}

export function setPendingSales(sales) {
    saveJson(keys.pendingSales, sales);
    window.dispatchEvent(new CustomEvent('cashier:pending-sales', { detail: sales }));
}

export function addPendingSale(sale) {
    const next = [...getPendingSales().filter((item) => item.client_reference !== sale.client_reference), sale];
    setPendingSales(next);
    return next;
}
