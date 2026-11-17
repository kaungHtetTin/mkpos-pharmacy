import React, { useState } from 'react';
import MainLayout from '@/Layouts/MainLayout';
import { Head, Link, useForm } from '@/spa';
import { Alert, Box, Button, Chip, Dialog, DialogTitle, DialogContent, DialogActions, MenuItem, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField } from '@mui/material';

export default function Members({ auth, members = [], roles = [], branches = [] }) {
    const [editing, setEditing] = useState(null);
    const form = useForm({ role_id: '', branch_id: '', status: 'Active' });
    const show = member => {
        form.clearErrors();
        form.setData({ role_id: member.roles?.[0]?.id || '', branch_id: member.branch_id || '', status: member.status });
        setEditing(member);
    };
    const submit = event => {
        event.preventDefault();
        form.put(route('business-members.update', editing.id), { onSuccess: () => setEditing(null) });
    };
    return <MainLayout auth={auth} header="Business members"><Head title="Business members" /><Stack spacing={1.5}>
        <Alert severity="info">Every account belongs to this business only. Manage roles and primary branches here, or create accounts and assign branch access in Staff Management.</Alert>
        <Button component={Link} href={route('staff.index')} variant="contained" sx={{ alignSelf: 'flex-start' }}>Manage staff</Button>
        <TableContainer><Table size="small"><TableHead><TableRow><TableCell>Name</TableCell><TableCell>Email</TableCell><TableCell>Roles</TableCell><TableCell>Status</TableCell><TableCell>Action</TableCell></TableRow></TableHead><TableBody>
            {members.map(member => <TableRow key={member.id}><TableCell>{member.name}</TableCell><TableCell>{member.email}</TableCell><TableCell>{member.roles.map(role => role.name).join(', ')}</TableCell><TableCell><Chip label={member.status} size="small" color={member.status === 'Active' ? 'success' : 'warning'} /></TableCell><TableCell><Button onClick={() => show(member)}>Edit account access</Button></TableCell></TableRow>)}
        </TableBody></Table></TableContainer>
        <Dialog open={Boolean(editing)} onClose={() => !form.processing && setEditing(null)} fullWidth maxWidth="xs"><Box component="form" onSubmit={submit}><DialogTitle>Edit account access</DialogTitle><DialogContent dividers><Stack spacing={2} sx={{ pt: 1 }}>
            <TextField label="Account email" value={editing?.email || ''} disabled />
            <TextField select required label="Role" value={form.data.role_id} onChange={event => form.setData('role_id', event.target.value)} error={Boolean(form.errors.role_id)} helperText={form.errors.role_id}>{roles.map(role => <MenuItem key={role.id} value={role.id}>{role.name}</MenuItem>)}</TextField>
            <TextField select required label="Primary branch" value={form.data.branch_id} onChange={event => form.setData('branch_id', event.target.value)} error={Boolean(form.errors.branch_id)} helperText={form.errors.branch_id}>{branches.map(branch => <MenuItem key={branch.id} value={branch.id}>{branch.name}</MenuItem>)}</TextField>
            <TextField select label="Account access" value={form.data.status} onChange={event => form.setData('status', event.target.value)} error={Boolean(form.errors.status)} helperText={form.errors.status}><MenuItem value="Active">Active</MenuItem><MenuItem value="Inactive">Inactive</MenuItem></TextField>
        </Stack></DialogContent><DialogActions><Button onClick={() => setEditing(null)} disabled={form.processing}>Cancel</Button><Button variant="contained" type="submit" disabled={form.processing}>Save access</Button></DialogActions></Box></Dialog>
    </Stack></MainLayout>;
}
