import React from 'react';
import { Link, router, usePage } from '@/spa';
import { Alert, Box, Button, Chip, Stack, Typography } from '@mui/material';

export const billingTerm = months => `${Number(months)} ${Number(months) === 1 ? 'month' : 'months'}`;
export const billingMoney = (minor, currency = 'MMK') => `${currency} ${(Number(minor || 0) / 100).toLocaleString(undefined, { minimumFractionDigits: currency === 'MMK' ? 0 : 2, maximumFractionDigits: 2 })}`;
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
    const {props}=usePage();const role=props.auth?.user?.platform_role;
    const links=[['platform.dashboard','Overview',['owner','admin','billing','support']],['platform.businesses.index','Businesses',['owner','admin','billing']],['platform.plans.index','Plans',['owner','billing']],['platform.billing.index','Subscriptions & payments',['owner','billing']],['platform.support.index','Support',['owner','admin','support']],['platform.audit','Audit',['owner','admin']],['platform.email.index','Email',['owner']],...(props.office_mfa_required ? [['platform.security.index','Security',['owner','admin','billing','support']]] : [])];
    return <Stack direction="row" spacing={.5} flexWrap="wrap" useFlexGap>{links.filter(([, ,roles])=>roles.includes(role)).map(([name,label])=><Button key={name} component={Link} href={route(name)} size="small">{label}</Button>)}</Stack>;
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
