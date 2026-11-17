import React, { useState } from 'react';
import MainLayout from '@/Layouts/MainLayout';
import { Head, router, useForm } from '@/spa';
import { Alert, Box, Button, Chip, Dialog, DialogTitle, DialogContent, DialogActions, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField } from '@mui/material';

export default function Businesses({ auth, businesses = [], recent_activity = [] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '', slug: '', owner_name: '', owner_email: '', owner_password: '', currency_symbol: '$' });
    const submit = event => { event.preventDefault(); form.post(route('platform.businesses.store'), { onSuccess: () => { setOpen(false); form.reset(); } }); };
    return <MainLayout auth={auth} header="Platform office"><Head title="Business administration" /><Stack spacing={1.5}>
        <Alert severity="info">Manage business accounts and availability here. Business records are accessed through an assigned membership. Subscription billing is not enabled.</Alert>
        <Button variant="contained" sx={{ alignSelf: 'flex-start' }} onClick={() => setOpen(true)}>Create business</Button>
        <TableContainer><Table size="small"><TableHead><TableRow><TableCell>Business</TableCell><TableCell>Slug</TableCell><TableCell align="right">Members</TableCell><TableCell>Status</TableCell><TableCell>Action</TableCell></TableRow></TableHead><TableBody>
            {businesses.map(business => <TableRow key={business.id}><TableCell>{business.name}</TableCell><TableCell>{business.slug}</TableCell><TableCell align="right">{business.memberships_count}</TableCell><TableCell><Chip size="small" label={business.status} color={business.status === 'Active' ? 'success' : 'warning'} /></TableCell><TableCell><Button onClick={() => { if (window.confirm(`${business.status === 'Active' ? 'Suspend' : 'Activate'} ${business.name}?`)) router.put(route('platform.businesses.update', business.id), { status: business.status === 'Active' ? 'Suspended' : 'Active' }); }}>{business.status === 'Active' ? 'Suspend' : 'Activate'}</Button></TableCell></TableRow>)}
            {!businesses.length && <TableRow><TableCell colSpan={5}>No businesses created yet.</TableCell></TableRow>}
        </TableBody></Table></TableContainer>
        <Box><Box component="h2" sx={{fontSize:14, fontWeight:700}}>Recent platform activity</Box><TableContainer><Table size="small"><TableHead><TableRow><TableCell>Action</TableCell><TableCell>Actor</TableCell><TableCell>Business</TableCell><TableCell>When</TableCell></TableRow></TableHead><TableBody>{recent_activity.map(entry=><TableRow key={entry.id}><TableCell>{entry.action}</TableCell><TableCell>{entry.actor?.name || 'System'}</TableCell><TableCell>{entry.business?.name || 'Platform'}</TableCell><TableCell>{new Date(entry.created_at).toLocaleString()}</TableCell></TableRow>)}{!recent_activity.length&&<TableRow><TableCell colSpan={4}>No platform activity yet.</TableCell></TableRow>}</TableBody></Table></TableContainer></Box>
        <Dialog open={open} onClose={() => !form.processing && setOpen(false)} fullWidth maxWidth="sm"><Box component="form" onSubmit={submit}><DialogTitle>Create business</DialogTitle><DialogContent dividers><Stack spacing={2} sx={{ pt: 1 }}>
            {[['name','Business name'],['slug','Business slug'],['owner_name','Owner name'],['owner_email','Owner email'],['owner_password','Password for new owner'],['currency_symbol','Currency symbol']].map(([key,label]) => <TextField key={key} label={label} type={key === 'owner_password' ? 'password' : key === 'owner_email' ? 'email' : 'text'} autoComplete={key === 'owner_password' ? 'new-password' : 'off'} required={key !== 'owner_password'} value={form.data[key]} onChange={event => form.setData(key,event.target.value)} error={Boolean(form.errors[key])} helperText={form.errors[key] || (key === 'owner_password' ? 'At least 12 characters for a new account. Leave blank to use an existing account.' : undefined)} />)}
        </Stack></DialogContent><DialogActions><Button disabled={form.processing} onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" variant="contained" disabled={form.processing}>Create business</Button></DialogActions></Box></Dialog>
    </Stack></MainLayout>;
}
