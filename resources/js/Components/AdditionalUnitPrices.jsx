import { automaticPrice } from '@/Utils/automaticPricing';
import React, { useState } from 'react';
import { Alert, Box, Button, Dialog, DialogTitle, DialogContent, DialogActions, MenuItem, Stack, TextField, Typography } from '@mui/material';

export default function AdditionalUnitPrices({ rows, rules, onChange, cost }) {
    const [open, setOpen] = useState(false);
    const types = rules.filter((r) => !['selling_price','wholesale_price'].includes(r.code) && r.is_active !== false);
    const update = (index, code, field, value) => onChange(rows.map((row, i) => i === index ? { ...row, custom_prices: { ...row.custom_prices, [code]: { mode: row.custom_prices?.[code]?.mode || 'manual', amount: row.custom_prices?.[code]?.amount ?? '', ...row.custom_prices?.[code], [field]: value } } } : row));
    if (!types.length) return null;
    return <><Button variant="outlined" size="small" onClick={() => setOpen(true)}>Additional prices ({types.length})</Button>
        <Dialog aria-labelledby="additional-prices-title" open={open} onClose={() => setOpen(false)} fullWidth maxWidth="md" scroll="paper">
            <DialogTitle id="additional-prices-title">Additional unit prices</DialogTitle>
            <DialogContent dividers>
                <Alert severity="info" sx={{ mb: 2 }}>Prices are per selling unit. Blank means “Price not set”; it never falls back silently to Retail.</Alert>
                <Stack spacing={2}>{rows.map((row, index) => <Box key={index}>
                    <Typography variant="subtitle2" fontWeight={700} sx={{ mb: 1 }}>{row.unit_name || `Unit ${index+1}`} · factor {row.conversion_factor}</Typography>
                    <Stack spacing={1}>{types.map((rule) => {
                        const price=row.custom_prices?.[rule.code] || { amount: '', mode: row._existing ? 'manual' : rule.pricing_mode };
                        return <Box key={rule.code} sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '1fr 130px 160px' }, gap: 1, alignItems: 'center' }}>
                            <Typography variant="body2">{rule.name}</Typography>
                            <TextField select size="small" label={`${rule.name} mode`} value={price.mode || 'manual'} onChange={(e) => update(index,rule.code,'mode',e.target.value)}><MenuItem value="manual">Manual</MenuItem><MenuItem value="automatic" disabled={rule.pricing_mode!=='automatic'}>Automatic</MenuItem></TextField>
                            <TextField size="small" type="number" label={`${rule.name} price`} value={price.mode==='automatic' ? (automaticPrice(cost,rule,String(row.conversion_factor)) ?? '') : (price.amount ?? '')} disabled={price.mode==='automatic'} inputProps={{ min: 0.01, step: 0.01 }} placeholder={price.mode==='automatic' ? 'Buying cost required' : 'Price not set'} onChange={(e) => update(index,rule.code,'amount',e.target.value)} />
                        </Box>;
                    })}</Stack>
                </Box>)}</Stack>
            </DialogContent>
            <DialogActions><Button onClick={() => setOpen(false)}>Done</Button></DialogActions>
        </Dialog>
    </>;
}
