export const defaultPriceTypes = [{ code: 'retail', name: 'Retail' }, { code: 'wholesale', name: 'Wholesale' }];
export function unitPrice(unit, type = 'retail') {
    if (!unit) return null;
    if (type === 'retail' || type === 'wholesale') {
        const field = type === 'retail' ? 'selling_price' : 'wholesale_price';
        const amount = Number(unit[field] ?? unit.selling_price ?? 0);
        return unit[`${field}_mode`] === 'automatic' && amount <= 0 ? null : amount;
    }
    const price = unit.custom_prices?.[type];
    const amount = price?.amount;
    return amount === null || amount === undefined || amount === '' || Number(amount) <= 0 || price.status === 'cost_required' ? null : Number(amount);
}
export const priceTypeName = (type, types = defaultPriceTypes) => types.find((item) => item.code === type)?.name || type;
