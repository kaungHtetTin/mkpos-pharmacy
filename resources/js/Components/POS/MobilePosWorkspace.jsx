import React, { useEffect, useRef, useState } from 'react';
import { Alert, Autocomplete, Box, Button, Chip, Collapse, Dialog, DialogActions, DialogContent, DialogTitle, Divider, IconButton, InputAdornment, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { Add, ArrowBack, CheckCircleOutline, Close, ExpandMore, PrintOutlined, Remove, Search, ShoppingCartOutlined } from '@mui/icons-material';
import { unitPrice, priceTypeName } from '@/Utils/priceTypes';

const panel = { p: 1.5, border: '1px solid', borderColor: 'divider', borderRadius: 2, bgcolor: 'background.paper' };

export default function MobilePosWorkspace({ step, onStep, catalog, order, payment, actions, receipt, currency, error, processing, errors = {}, creditDetails }) {
    const [units, setUnits] = useState({});
    const [expanded, setExpanded] = useState(null);
    const [pendingType, setPendingType] = useState(null);
    const [clearOpen, setClearOpen] = useState(false);
    const headingRef = useRef(null);
    const money = (amount) => `${currency}${Number(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const count = order.lines.reduce((sum, line) => sum + Number(line.quantity || 0), 0);
    const missingPrice = order.lines.some((line) => line.unit_price === null);
    const invalidQuantity = order.lines.some((line) => !Number.isFinite(Number(line.quantity)) || Number(line.quantity)<=0 || !Number.isFinite(Number(line.foc_quantity || 0)) || Number(line.foc_quantity || 0)<0);
    const invalidCreditPayment = payment.data.payment_status === 'Credit' && Number(payment.data.amount_received || 0) >= order.totals.grandTotal;
    const stockByProduct = order.lines.reduce((stock, line) => { stock[line.product_id] = (stock[line.product_id] || 0) + order.requested(line); return stock; }, {});
    const stockIssue = order.stockIssue || order.lines.some((line) => stockByProduct[line.product_id] > Number(line.stock_quantity || 0));
    const blocked = processing || stockIssue || missingPrice || invalidQuantity;
    const go = (next) => {
        if (processing || (next !== 'products' && next !== 'complete' && !order.lines.length)) return;
        if (next === step) return;
        window.history.pushState({ ...window.history.state, posMobileStep: next }, '', window.location.href);
        onStep(next);
    };
    useEffect(() => {
        window.history.replaceState({ ...window.history.state, posMobileStep: step }, '', window.location.href);
        const back = (event) => { if (event.state?.posMobileStep) onStep(event.state.posMobileStep); };
        window.addEventListener('popstate', back);
        return () => window.removeEventListener('popstate', back);
    }, [onStep, step]);
    useEffect(() => {
        window.scrollTo(0, 0);
        if (step !== 'products') headingRef.current?.focus({ preventScroll: true });
    }, [step]);
    const selectType = (code) => {
        if (code === order.priceType) return;
        if (order.lines.length) setPendingType(code);
        else actions.priceType(code);
    };
    const requested = (productId) => order.lines.filter((line) => line.product_id === productId).reduce((sum, line) => sum + order.requested(line), 0);
    const nextQuantityAllowed = (line) => {
        const factor = Number(line.units.find((unit) => unit.unit_id === line.unit_id)?.conversion_factor || 1);
        return requested(line.product_id) + factor <= Number(line.stock_quantity || 0);
    };
    const summary = <Stack spacing={0.75}>
        <Stack direction="row" justifyContent="space-between"><Typography variant="body2" color="text.secondary">Items after discounts</Typography><Typography variant="body2">{money(order.totals.totalAmount)}</Typography></Stack>
        {order.totals.productDiscount > 0 && <Typography variant="caption" color="text.secondary">Discount included: {money(order.totals.productDiscount)}</Typography>}
        <Stack direction="row" justifyContent="space-between"><Typography variant="body2" color="text.secondary">Tax</Typography><Typography variant="body2">{money(order.totals.tax)}</Typography></Stack>
        <Divider />
        <Stack direction="row" justifyContent="space-between"><Typography fontWeight={800}>Total</Typography><Typography fontWeight={800}>{money(order.totals.grandTotal)}</Typography></Stack>
    </Stack>;

    return <Box data-mobile-pos sx={{ px: 1.25, pt: 1.25, pb: 'calc(104px + env(safe-area-inset-bottom, 0px))', minWidth: 0, '& .MuiButton-root': { minHeight: 44 }, '& .MuiIconButton-root': { minWidth: 44, minHeight: 44 }, '& .MuiOutlinedInput-root': { minHeight: 44 } }}>
        {error && <Alert severity="error" sx={{ mb: 1.25 }}>{error}</Alert>}
        {Object.values(errors).flat().length > 0 && <Alert severity="error" sx={{ mb: 1.25 }}>{[...new Set(Object.values(errors).flat())].join(' ')}</Alert>}
        {step !== 'complete' && <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1.25 }}>
            <Typography variant="caption" color="text.secondary">{step === 'products' ? '1 · Choose products' : step === 'cart' ? '2 · Review cart' : '3 · Take payment'}</Typography>
            <Button size="small" color={order.session ? 'inherit' : 'warning'} onClick={actions.shift} sx={{ minHeight: '36px !important' }}>{order.session ? 'Shift open' : 'Start shift'}</Button>
        </Stack>}
        {!order.session && step !== 'complete' && <Alert severity="info" sx={{ mb: 1.25 }}>You can browse and build a cart. Start a shift before taking payment.</Alert>}

        {step === 'products' && <>
            <Box sx={{ position: 'sticky', top: 'var(--mkpos-appbar-height, 48px)', zIndex: 4, bgcolor: 'background.default', pb: 1.25, pt: 0.5 }}>
                <TextField fullWidth inputRef={catalog.searchRef} placeholder="Search medicine or scan barcode" value={catalog.query} onChange={(e) => catalog.onQuery(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); catalog.onScan(); } }} InputProps={{ startAdornment: <InputAdornment position="start"><Search fontSize="small" /></InputAdornment>, endAdornment: catalog.query && <InputAdornment position="end"><IconButton aria-label="Clear product search" onClick={() => catalog.onQuery('')}><Close fontSize="small" /></IconButton></InputAdornment> }} inputProps={{ 'aria-label': 'Search products' }} />
                <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
                    <TextField select fullWidth size="small" label="Price type" value={order.priceType} onChange={(e) => selectType(e.target.value)}>{order.types.map((type) => <MenuItem key={type.code} value={type.code}>{type.name}</MenuItem>)}</TextField>
                    <TextField select fullWidth size="small" label="Category" value={catalog.category} onChange={(e) => catalog.onCategory(e.target.value)}><MenuItem value="">All categories</MenuItem>{catalog.categories.map((category) => <MenuItem key={category.id} value={String(category.id)}>{category.name}</MenuItem>)}</TextField>
                </Stack>
            </Box>
            <Stack direction="row" justifyContent="space-between" sx={{ mb: 1 }}><Typography component="h1" variant="subtitle1" fontWeight={800}>Products</Typography><Typography variant="caption" color="text.secondary">{catalog.loading ? 'Finding products…' : `${catalog.products.length} loaded`}</Typography></Stack>
            <Stack spacing={1}>
                {catalog.products.map((product) => {
                    const options = product.units || [];
                    const unit = options.find((option) => option.unit_id === units[product.id]) || options.find((option) => option.is_default_selling_unit) || options.find((option) => option.is_base_unit) || options[0];
                    const price = unitPrice(unit, order.priceType);
                    const available = Math.max(0, Number(product.stock_quantity || 0) - requested(product.id));
                    const stock = Math.floor(available / Math.max(Number(unit?.conversion_factor || 1), 1));
                    const already = order.lines.filter((line) => line.product_id === product.id).reduce((sum, line) => sum + Number(line.quantity || 0), 0);
                    return <Paper key={product.id} variant="outlined" data-mobile-product={product.id} sx={{ p: 1.25, borderRadius: 2 }}>
                        <Stack direction="row" spacing={1.25} alignItems="start">
                            <Box sx={{ flex: 1, minWidth: 0 }}><Typography variant="body2" fontWeight={750}>{catalog.name(product)}</Typography><Typography variant="caption" color="text.secondary" sx={{ display: 'block', overflowWrap: 'anywhere' }}>{product.generic_name || product.barcode || 'Medicine'}</Typography></Box>
                            {already > 0 && <Chip size="small" color="primary" variant="outlined" label={`${already} in cart`} />}
                        </Stack>
                        <Stack direction="row" spacing={1} alignItems="center" sx={{ mt: 1 }}>
                            <Box sx={{ flex: 1, minWidth: 0 }}><Typography variant="body2" fontWeight={800} color={price === null ? 'warning.main' : 'primary.main'}>{price === null ? 'Price not set' : money(price)}</Typography><Typography variant="caption" color="text.secondary">{stock > 0 ? `${stock} available` : 'Out of stock'}</Typography></Box>
                            {options.length > 1 ? <TextField select size="small" label="Unit" value={unit?.unit_id || ''} onChange={(e) => setUnits((current) => ({ ...current, [product.id]: e.target.value }))} sx={{ minWidth: 88, maxWidth: 120 }}>{options.map((option) => <MenuItem key={option.unit_id} value={option.unit_id}>{option.short_name || option.name || option.unit_name}</MenuItem>)}</TextField> : <Typography variant="caption" color="text.secondary">{unit?.short_name || unit?.name || unit?.unit_name}</Typography>}
                            <Button variant="contained" startIcon={<Add />} aria-label={`Add ${product.name}`} disabled={!unit || price === null || stock < 1} onClick={() => actions.add(product, unit)}>Add</Button>
                        </Stack>
                    </Paper>;
                })}
                {!catalog.products.length && !catalog.loading && !catalog.error && <Box sx={{ ...panel, py: 4, textAlign: 'center' }}><Search color="disabled" /><Typography variant="body2" sx={{ mt: 1 }}>No matching products</Typography><Typography variant="caption" color="text.secondary">Try another name, barcode or category.</Typography></Box>}
            </Stack>
            <Box ref={catalog.endRef} sx={{ py: 1.5 }}>{catalog.footer}</Box>
        </>}

        {step === 'cart' && <>
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1.25 }}><Box><Typography component="h1" variant="h6" tabIndex={-1} ref={headingRef}>Review cart</Typography><Typography variant="caption" color="text.secondary">{order.lines.length} products · {priceTypeName(order.priceType, order.types)}</Typography></Box><Button color="error" onClick={() => setClearOpen(true)} disabled={processing}>Clear</Button></Stack>
            {invalidQuantity && <Alert severity="warning" sx={{ mb: 1 }}>Enter a positive quantity and a valid free quantity before continuing.</Alert>}
            {stockIssue && <Alert severity="warning" sx={{ mb: 1 }}>Some quantities exceed stock. Reduce them before continuing.</Alert>}
            {missingPrice && <Alert severity="warning" sx={{ mb: 1 }}>A price is not set. Choose an available price type or set the unit price.</Alert>}
            <Stack spacing={1}>{order.lines.map((line) => <Paper key={line.id} variant="outlined" sx={{ p: 1.25, borderRadius: 2 }}>
                <Stack direction="row" spacing={1} justifyContent="space-between"><Typography fontWeight={750} variant="body2" sx={{ minWidth: 0 }}>{line.name}</Typography><Typography fontWeight={750} variant="body2" sx={{ whiteSpace: 'nowrap' }}>{line.unit_price === null ? 'Price not set' : money(order.lineTotal(line))}</Typography></Stack>
                <Typography variant="caption" color="text.secondary">{line.unit_price === null ? 'Choose another price type' : `${money(line.unit_price)} per unit`}{Number(line.discount_percentage) > 0 ? ` · ${line.discount_percentage}% discount` : ''}</Typography>
                <Stack direction="row" spacing={0.75} alignItems="center" sx={{ mt: 1 }}>
                    <IconButton aria-label={`Decrease ${line.name}`} disabled={processing || Number(line.quantity) <= 1} onClick={() => actions.update(line.id, { quantity: Number(line.quantity) - 1 })}><Remove fontSize="small" /></IconButton>
                    <TextField type="number" size="small" value={line.quantity} onChange={(e) => actions.update(line.id, { quantity: e.target.value })} inputProps={{ 'aria-label': `Quantity for ${line.name}`, min: 0.01, step: 0.01, inputMode: 'decimal' }} error={Number(line.quantity)<=0} sx={{ width: 72 }} />
                    <IconButton aria-label={`Increase ${line.name}`} disabled={processing || !nextQuantityAllowed(line)} onClick={() => actions.update(line.id, { quantity: Number(line.quantity) + 1 })}><Add fontSize="small" /></IconButton>
                    <TextField select size="small" label="Unit" value={line.unit_id} onChange={(e) => actions.update(line.id, { unit_id: e.target.value })} sx={{ flex: 1, minWidth: 72 }}>{line.units.map((unit) => <MenuItem key={unit.unit_id} value={unit.unit_id}>{unit.short_name || unit.name || unit.unit_name}</MenuItem>)}</TextField>
                </Stack>
                <Stack direction="row" justifyContent="space-between" sx={{ mt: 0.5 }}><Button size="small" endIcon={<ExpandMore />} onClick={() => setExpanded(expanded === line.id ? null : line.id)} aria-expanded={expanded === line.id}>Item options</Button><Button size="small" color="error" onClick={() => actions.remove(line.id)} disabled={processing}>Remove</Button></Stack>
                <Collapse in={expanded === line.id}><Stack direction="row" spacing={1} sx={{ pt: 1 }}><TextField type="number" size="small" label="Free quantity" value={line.foc_quantity} onChange={(e) => actions.update(line.id, { foc_quantity: e.target.value })} inputProps={{ min: 0, step: 1 }} sx={{ flex: 1 }} /><TextField select size="small" label="Free unit" value={line.foc_unit_id || line.unit_id} onChange={(e) => actions.update(line.id, { foc_unit_id: e.target.value })} sx={{ flex: 1 }}>{line.units.map((unit) => <MenuItem key={unit.unit_id} value={unit.unit_id}>{unit.short_name || unit.name || unit.unit_name}</MenuItem>)}</TextField></Stack></Collapse>
            </Paper>)}</Stack>
            <Box sx={{ ...panel, mt: 1.5 }}>{summary}</Box>
        </>}

        {step === 'checkout' && <Box component="form" id="mobile-pos-payment" onSubmit={actions.submit}>
            <Typography component="h1" variant="h6" tabIndex={-1} ref={headingRef}>Payment</Typography>
            <Box sx={{ ...panel, mt: 1.25, mb: 1.5 }}><Typography variant="caption" color="text.secondary">Amount to pay · {priceTypeName(order.priceType, order.types)}</Typography><Typography variant="h4" fontWeight={800} color="primary.main" sx={{ mt: 0.25 }}>{money(order.totals.grandTotal)}</Typography><Typography variant="caption" color="text.secondary">{count} units · {order.lines.length} products</Typography></Box>
            <Stack spacing={1.5}>
                <Box sx={panel}><Typography variant="subtitle2" sx={{ mb: 1 }}>Customer <Typography component="span" variant="caption" color="text.secondary">(optional)</Typography></Typography>
                    <Autocomplete options={payment.customers} value={payment.customer} inputValue={payment.customerQuery} onInputChange={(e, value, reason) => { if (reason === 'input' || reason === 'clear') payment.onCustomerQuery(value); }} onChange={(e, value) => payment.onCustomer(value)} getOptionLabel={(option) => option.name || ''} isOptionEqualToValue={(a,b) => a.id===b.id} filterOptions={(options) => options} loading={payment.customersLoading} renderInput={(params) => <TextField {...params} label="Customer" placeholder="Walk-in customer" size="small" />} renderOption={(props, option) => <li {...props} key={option.id}><Box><Typography variant="body2">{option.name}</Typography><Typography variant="caption" color="text.secondary">{option.phone || option.email || ''}</Typography></Box></li>} />
                    <Button size="small" onClick={actions.newCustomer} sx={{ mt: 0.5 }}>Add new customer</Button>
                </Box>
                <Box sx={panel}><Typography variant="subtitle2" sx={{ mb: 1 }}>Payment method</Typography><Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(2,minmax(0,1fr))', gap: 1 }}>{payment.methods.map((method) => <Button key={method} sx={{ minWidth: 0, overflowWrap: 'anywhere' }} variant={payment.data.payment_method === method ? 'contained' : 'outlined'} onClick={() => payment.set('payment_method',method)} aria-pressed={payment.data.payment_method === method}>{method}</Button>)}</Box></Box>
                <Box sx={panel}><Typography variant="subtitle2" sx={{ mb: 1 }}>Payment terms</Typography><Stack direction="row" spacing={1}><Button fullWidth variant={payment.data.payment_status === 'Paid' ? 'contained' : 'outlined'} onClick={() => payment.set('payment_status','Paid')}>Pay in full</Button><Button fullWidth variant={payment.data.payment_status === 'Credit' ? 'contained' : 'outlined'} disabled={!payment.customer || payment.customer.has_overdue} onClick={() => payment.set('payment_status','Credit')}>Credit</Button></Stack>{!payment.customer && <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.75 }}>Choose a customer to offer credit.</Typography>}{creditDetails}</Box>
                {(payment.data.payment_method === 'Cash' || payment.data.payment_status === 'Credit') && <Box sx={panel}>
                    <TextField fullWidth label={payment.data.payment_status === 'Credit' ? 'Upfront payment' : 'Cash received'} type="number" value={payment.data.amount_received} onChange={(e) => payment.set('amount_received',e.target.value)} inputProps={{ min: 0, step: 0.01, inputMode: 'decimal' }} error={invalidCreditPayment || Boolean(errors.amount_received) || (payment.paidCash && payment.shortage > 0)} helperText={errors.amount_received || (invalidCreditPayment ? 'Choose Pay in full when paying the full amount.' : null) || (payment.paidCash && payment.shortage > 0 ? `Still needed: ${money(payment.shortage)}` : ' ')} />
                    {payment.data.payment_status !== 'Credit' && <Button variant="outlined" onClick={() => payment.set('amount_received',order.totals.grandTotal)}>Exact amount</Button>}
                    <Stack direction="row" justifyContent="space-between" sx={{ mt: 1.5 }}><Typography variant="body2">{payment.data.payment_status === 'Credit' ? 'Balance due' : 'Change'}</Typography><Typography fontWeight={800}>{money(payment.data.payment_status === 'Credit' ? Math.max(order.totals.grandTotal - Number(payment.data.amount_received || 0),0) : payment.change)}</Typography></Stack>
                </Box>}
                <Box sx={panel}>{summary}</Box>
            </Stack>
        </Box>}

        {step === 'complete' && receipt && <Stack spacing={2} alignItems="center" sx={{ py: 3 }}>
            <CheckCircleOutline color="success" sx={{ fontSize: 52 }} /><Typography component="h1" variant="h5" tabIndex={-1} ref={headingRef}>Sale complete</Typography><Typography variant="body2" color="text.secondary">{receipt.invoice_number}</Typography><Typography variant="h4" fontWeight={800}>{money(receipt.grand_total)}</Typography>
            <Paper variant="outlined" sx={{ p: 1.5, width: '100%', borderRadius: 2 }}>{(receipt.items || []).map((item,index) => <Stack direction="row" justifyContent="space-between" spacing={1} key={index} sx={{ py: 0.5 }}><Typography variant="body2">{item.name || item.product?.name} × {item.quantity}{item.foc_quantity ? ` + ${item.foc_quantity} free` : ''}</Typography><Typography variant="body2" sx={{ whiteSpace: 'nowrap' }}>{money(item.total_price)}</Typography></Stack>)}</Paper>
            <Box sx={{ ...panel, width: '100%' }}><Stack spacing={0.75}>
                <Stack direction="row" justifyContent="space-between"><Typography variant="body2">Tax</Typography><Typography variant="body2">{money(receipt.tax)}</Typography></Stack>
                <Stack direction="row" justifyContent="space-between"><Typography variant="body2">Discount included</Typography><Typography variant="body2">{money(receipt.discount)}</Typography></Stack>
                <Divider />
                <Stack direction="row" justifyContent="space-between"><Typography variant="body2">{receipt.payment_status !== 'Paid' ? 'Upfront payment' : 'Amount received'}</Typography><Typography variant="body2">{money(receipt.amount_received)}</Typography></Stack>
                <Stack direction="row" justifyContent="space-between"><Typography variant="body2">{receipt.payment_status !== 'Paid' ? 'Balance due' : 'Change'}</Typography><Typography fontWeight={750}>{money(receipt.payment_status !== 'Paid' ? Math.max(Number(receipt.grand_total || 0) - Number(receipt.amount_received || 0), 0) : receipt.change_due)}</Typography></Stack>
            </Stack></Box>
            <Button variant="outlined" startIcon={<PrintOutlined />} fullWidth onClick={() => actions.print(receipt)}>Print receipt</Button><Button variant="contained" fullWidth onClick={actions.newSale}>Start new sale</Button>
        </Stack>}

        {step !== 'complete' && order.lines.length > 0 && <Paper component="footer" data-mobile-pos-dock elevation={0} sx={{ position: 'fixed', bottom: 0, left: 0, right: 0, borderTop: '1px solid', borderColor: 'divider', zIndex: 1099, px: 1.5, pt: 1.25, pb: 'calc(10px + env(safe-area-inset-bottom, 0px))', display: 'flex', alignItems: 'center', gap: 1.25, borderRadius: 0, boxShadow: '0 -3px 16px rgba(0,0,0,0.04)' }}>
            {step !== 'products' && <IconButton aria-label={step === 'cart' ? 'Back to products' : 'Back to cart'} disabled={processing} onClick={() => go(step === 'cart' ? 'products' : 'cart')}><ArrowBack /></IconButton>}
            {step === 'products' ? <><Box aria-live="polite" sx={{ flex: 1, minWidth: 0 }}><Typography variant="caption" color="text.secondary">{count} units in cart</Typography><Typography variant="subtitle1" fontWeight={800}>{money(order.totals.grandTotal)}</Typography></Box><Button variant="contained" startIcon={<ShoppingCartOutlined />} onClick={() => go('cart')}>Review cart</Button></> : step === 'cart' ? <Button variant="contained" fullWidth disabled={blocked} onClick={() => order.session ? go('checkout') : actions.shift()}>{order.session ? `Continue to payment · ${money(order.totals.grandTotal)}` : 'Start shift to continue'}</Button> : <Button type="submit" form="mobile-pos-payment" variant="contained" fullWidth disabled={blocked || invalidCreditPayment || !order.session || (payment.paidCash && payment.shortage > 0)}>{processing ? 'Completing sale…' : `Complete sale · ${money(order.totals.grandTotal)}`}</Button>}
        </Paper>}
        <Dialog open={Boolean(pendingType)} onClose={() => setPendingType(null)} maxWidth="xs" fullWidth><DialogTitle>Change price type?</DialogTitle><DialogContent>Apply {priceTypeName(pendingType,order.types)} prices to every item in this cart? Missing prices must be resolved before payment.</DialogContent><DialogActions><Button onClick={() => setPendingType(null)}>Cancel</Button><Button onClick={() => { actions.priceType(pendingType); setPendingType(null); }}>Apply prices</Button></DialogActions></Dialog>
        <Dialog open={clearOpen} onClose={() => setClearOpen(false)} maxWidth="xs" fullWidth><DialogTitle>Clear this cart?</DialogTitle><DialogContent>All items will be removed. No sale will be recorded.</DialogContent><DialogActions><Button onClick={() => setClearOpen(false)}>Keep cart</Button><Button color="error" onClick={() => { actions.clear(); setClearOpen(false); }}>Clear cart</Button></DialogActions></Dialog>
    </Box>;
}
