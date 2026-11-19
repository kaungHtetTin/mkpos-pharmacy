import React, { useEffect, useState } from 'react';
import { Box, Button, Chip, MenuItem, Paper, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Typography } from '@mui/material';
import DataTableFooter from '@/Components/DataTableFooter';
import { router } from '@/spa';

const empty = { search: '', branch_id: '', action: '', from: '', to: '' };
const dateTime = (value) => value ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Yangon' }).format(new Date(value)) : '—';
const quantity = (value) => value === null || value === undefined ? '—' : Number(value).toLocaleString();

export default function StockMovementHistory({ movements, movementActions = {}, branches = [], filters = {}, productId }) {
    const [form, setForm] = useState({ ...empty, ...filters });
    useEffect(() => setForm({ ...empty, ...filters }), [filters]);
    const url = productId ? route('inventory.show', productId) : route('inventory.movements');
    const navigate = (values) => router.get(url, Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '')), { preserveScroll: true, replace: true });
    const field = (key) => ({ value: form[key] || '', onChange: (event) => setForm((current) => ({ ...current, [key]: event.target.value })) });
    return <Paper variant="outlined" sx={{ mb: 2, overflow: 'hidden' }}>
        <Box sx={{ p: 1.5, borderBottom: '1px solid', borderColor: 'divider' }}>
            <Typography variant="subtitle1" fontWeight={700}>Stock movement history</Typography>
            <Typography variant="caption" color="text.secondary">Quantities use each product’s base unit. Balances are per branch. Dates are Myanmar time.</Typography>
            <Box component="form" onSubmit={(event) => { event.preventDefault(); navigate(form); }} sx={{ mt: 1.5 }}>
                <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr 1fr', md: 'minmax(180px, 2fr) repeat(4, minmax(130px, 1fr))' }, gap: 1 }}>
                    <TextField size="small" label="Product, reference or batch" {...field('search')} sx={{ gridColumn: { xs: '1 / -1', md: 'auto' } }} />
                    <TextField select size="small" label="Branch" {...field('branch_id')}><MenuItem value="">All branches</MenuItem>{branches.map((b) => <MenuItem key={b.id} value={b.id}>{b.name}</MenuItem>)}</TextField>
                    <TextField select size="small" label="Action" {...field('action')}><MenuItem value="">All actions</MenuItem>{Object.entries(movementActions).map(([key, label]) => <MenuItem key={key} value={key}>{label}</MenuItem>)}</TextField>
                    <TextField size="small" type="date" label="From" InputLabelProps={{ shrink: true }} {...field('from')} />
                    <TextField size="small" type="date" label="To" InputLabelProps={{ shrink: true }} inputProps={{ min: form.from || undefined }} {...field('to')} />
                </Box>
                <Stack direction="row" spacing={1} sx={{ mt: 1 }}><Button type="submit" variant="contained" size="small">Apply filters</Button><Button size="small" onClick={() => { setForm(empty); navigate(empty); }}>Reset</Button></Stack>
            </Box>
        </Box>
        <Typography variant="caption" color="text.secondary" sx={{ display: { xs: 'block', md: 'none' }, px: 1.5, py: 0.75 }}>Swipe horizontally for quantities, balances and details.</Typography>
        <TableContainer sx={{ overflowX: 'auto' }}>
            <Table size="small" sx={{ minWidth: productId ? 990 : 1150 }} aria-label="Stock movement history">
                <TableHead><TableRow>{['Recorded / document date', ...(!productId ? ['Product'] : []), 'Branch', 'Action / reference', 'Change', 'Before', 'After', 'User / details'].map((label) => <TableCell key={label} sx={{ whiteSpace: 'nowrap' }}>{label}</TableCell>)}</TableRow></TableHead>
                <TableBody>
                    {(movements?.data || []).map((m) => <TableRow key={m.id} hover>
                        <TableCell sx={{ whiteSpace: 'nowrap' }}>{dateTime(m.occurred_at)}{m.document_date && <Typography variant="caption" display="block" color="text.secondary">Document: {m.document_date.slice(0, 10)}</Typography>}</TableCell>
                        {!productId && <TableCell><Typography variant="body2" fontWeight={600}>{m.product_name}</Typography><Typography variant="caption" color="text.secondary">{m.base_unit || 'Base units'}</Typography></TableCell>}
                        <TableCell>{m.branch_name}</TableCell>
                        <TableCell><Typography variant="body2" fontWeight={600}>{movementActions[m.action] || m.action}</Typography><Typography variant="caption" color="text.secondary" sx={{ overflowWrap: 'anywhere', whiteSpace: 'normal' }}>{m.reference || '—'}</Typography></TableCell>
                        <TableCell sx={{ whiteSpace: 'nowrap' }}><Chip size="small" variant="outlined" color={m.quantity > 0 ? 'success' : m.quantity < 0 ? 'error' : 'default'} label={`${m.quantity > 0 ? '+' : ''}${quantity(m.quantity)}${m.base_unit ? ` ${m.base_unit}` : ''}`} /></TableCell>
                        <TableCell sx={{ fontVariantNumeric: 'tabular-nums' }}>{quantity(m.before_quantity)}</TableCell>
                        <TableCell sx={{ fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>{quantity(m.after_quantity)}</TableCell>
                        <TableCell sx={{ minWidth: 180, maxWidth: 300, whiteSpace: 'normal' }}><Typography variant="body2">{m.actor_name}</Typography>{m.batch_number && <Typography variant="caption" display="block">Batch: {m.batch_number}</Typography>}{m.note && <Typography variant="caption" display="block" color="text.secondary" sx={{ overflowWrap: 'anywhere', whiteSpace: 'normal' }}>{m.note}</Typography>}</TableCell>
                    </TableRow>)}
                    {!movements?.data?.length && <TableRow><TableCell colSpan={productId ? 8 : 9} sx={{ py: 4, textAlign: 'center' }}>No stock movements match these filters.</TableCell></TableRow>}
                </TableBody>
            </Table>
        </TableContainer>
        <DataTableFooter paginator={movements} label="movements" onPageChange={(page) => navigate({ ...filters, page })} />
    </Paper>;
}
