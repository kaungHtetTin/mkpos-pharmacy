import React from 'react';
import { Link, router } from '@/spa';
import { Alert, Box, Button, Chip, Stack, Typography } from '@mui/material';

export const billingMoney = (minor, currency = 'USD') => `${currency} ${(Number(minor || 0) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
export const billingDate = value => value ? new Date(value).toLocaleString() : '—';
export const billingKey = () => crypto.randomUUID();
export function BillingStatus({ value }) {
    const color = ['active','paid','approved'].includes(value) ? 'success' : ['pending','open','trialing','grace','canceling'].includes(value) ? 'warning' : 'default';
    return <Chip size="small" label={String(value || 'unsubscribed').replaceAll('_', ' ')} color={color} sx={{ textTransform: 'capitalize', height: 22 }} />;
}
export function BillingErrors({ errors = {} }) {
    const messages = [...new Set(Object.values(errors).flat())];
    return messages.length ? <Alert severity="error">{messages.join(' ')}</Alert> : null;
}
export function BillingLinks() {
    return <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
        <Button component={Link} href={route('platform.businesses.index')} size="small">Businesses</Button>
        <Button component={Link} href={route('platform.plans.index')} size="small">Plans</Button>
        <Button component={Link} href={route('platform.billing.index')} size="small">Subscriptions & payments</Button>
    </Stack>;
}
export function BillingPager({ page }) {
    return <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ py: 1 }}>
        <Typography variant="caption" color="text.secondary">{page.total || 0} records · page {page.current_page || 1} of {page.last_page || 1}</Typography>
        <Stack direction="row" spacing={1}><Button size="small" disabled={!page.prev_page_url} onClick={() => router.get(page.prev_page_url, {}, { preserveState: true, preserveScroll: true })}>Previous</Button><Button size="small" disabled={!page.next_page_url} onClick={() => router.get(page.next_page_url, {}, { preserveState: true, preserveScroll: true })}>Next</Button></Stack>
    </Stack>;
}
export function BillingUsage({ usage = {}, plan = {} }) {
    return <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 230px))', gap: 1 }}>
        {['branches','users','products'].map(key => <Box key={key} sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 1, p: 1.25 }}><Typography variant="caption" color="text.secondary" sx={{ textTransform: 'capitalize' }}>{key === 'users' ? 'Active accounts' : key === 'products' ? 'Medicines' : key}</Typography><Typography sx={{ fontWeight: 700 }}>{usage[key] || 0} <Box component="span" sx={{ fontWeight: 400, color: 'text.secondary', fontSize: 12 }}>/ {plan[`max_${key}`] ?? 'Unlimited'}</Box></Typography></Box>)}
    </Box>;
}
