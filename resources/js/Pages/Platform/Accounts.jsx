import React, { useState } from 'react';
import { Link, router, useForm } from '@/spa';
import { Box, Button, Chip, MenuItem, Stack, TableCell, TableRow, TextField, Typography } from '@mui/material';
import { OfficePage, OfficeTable, OfficeDialog, officeFiltersSx } from '@/Components/OfficeUi';
import { BillingPager, billingDate } from '@/Components/BillingUi';
export default function Accounts({
  auth,
  mode,
  accounts = {
    data: []
  },
  filters = {}
}) {
  const admin = mode === 'admins',
    prefix = `platform.${mode}`;
  const [filter, setFilter] = useState({
    q: filters.q || '',
    status: filters.status || '',
    role: filters.role || '',
    business_id: filters.business_id || ''
  });
  const [editing, setEditing] = useState(null),
    [resetting, setResetting] = useState(null);
  const form = useForm({
      name: '',
      email: '',
      status: 'Active',
      platform_role: 'support',
      password: ''
    }),
    password = useForm({
      password: '',
      password_confirmation: '',
      note: ''
    });
  const open = u => {
    form.clearErrors();
    form.setData({
      name: u?.name || '',
      email: u?.email || '',
      status: u?.status || 'Active',
      platform_role: u?.platform_role || 'support',
      password: ''
    });
    setEditing(u || {});
  };
  const submit = e => {
    e.preventDefault();
    const options = {
      onSuccess: () => {
        setEditing(null);
        form.reset();
      }
    };
    editing.id ? form.put(route(`${prefix}.update`, editing.id), options) : form.post(route(`${prefix}.store`), options);
  };
  return <OfficePage auth={auth} title={admin ? 'Office administrators' : 'Business accounts'} description={admin ? 'Manage office roles and access. At least one active owner must remain.' : 'Find accounts across businesses. Each account stays assigned to its original business.'} actions={admin && <Button variant="contained" onClick={() => open(null)}>Add administrator</Button>}>
 <Box component="form" sx={officeFiltersSx} onSubmit={e => {
      e.preventDefault();
      router.get(route(`${prefix}.index`), filter);
    }}><TextField label="Search name, email or business" value={filter.q} onChange={e => setFilter({
        ...filter,
        q: e.target.value
      })} /><TextField select label="Status" value={filter.status} onChange={e => setFilter({
        ...filter,
        status: e.target.value
      })}><MenuItem value="">All statuses</MenuItem><MenuItem value="Active">Active</MenuItem><MenuItem value="Inactive">Inactive</MenuItem></TextField>{admin && <TextField select label="Office role" value={filter.role} onChange={e => setFilter({
        ...filter,
        role: e.target.value
      })}><MenuItem value="">All roles</MenuItem>{['owner', 'admin', 'billing', 'support'].map(v => <MenuItem key={v} value={v}>{v}</MenuItem>)}</TextField>}<Button type="submit" variant="outlined">Apply</Button><Button component={Link} href={route(`${prefix}.index`)}>Clear</Button></Box>
 <OfficeTable headings={['Account', 'Email', admin ? 'Office role' : 'Business', 'Status', 'Created', 'Actions']} empty={!accounts.data.length}>{accounts.data.map(u => <TableRow key={u.id}><TableCell>{u.name}</TableCell><TableCell>{u.email}</TableCell><TableCell>{admin ? u.platform_role : <Link href={route('platform.businesses.show', u.business_id)}>{u.business_name}</Link>}</TableCell><TableCell><Chip size="small" label={u.status} color={u.status === 'Active' ? 'success' : 'default'} />{!admin && u.membership_status !== 'Active' && <Typography variant="caption" display="block">Membership {u.membership_status}</Typography>}</TableCell><TableCell>{billingDate(u.created_at)}</TableCell><TableCell><Button size="small" onClick={() => open(u)}>Edit</Button><Button size="small" onClick={() => {
            password.reset();
            password.clearErrors();
            setResetting(u);
          }}>Reset password</Button></TableCell></TableRow>)}</OfficeTable><BillingPager page={accounts} />
 <OfficeDialog open={!!editing} title={editing?.id ? 'Edit account' : 'Add office administrator'} form={form} onClose={() => {
      setEditing(null);
      form.reset();
    }} onSubmit={submit}>{[['name', 'Name'], ['email', 'Email address']].map(([k, l]) => <TextField key={k} required type={k === 'email' ? 'email' : 'text'} label={l} value={form.data[k]} onChange={e => form.setData(k, e.target.value)} error={!!form.errors[k]} helperText={form.errors[k]} />)}{admin && <TextField select label="Office role" value={form.data.platform_role} onChange={e => form.setData('platform_role', e.target.value)}>{['owner', 'admin', 'billing', 'support'].map(v => <MenuItem key={v} value={v}>{v}</MenuItem>)}</TextField>}<TextField select label="Account status" value={form.data.status} onChange={e => form.setData('status', e.target.value)}><MenuItem value="Active">Active</MenuItem><MenuItem value="Inactive">Inactive</MenuItem></TextField>{!editing?.id && <TextField required label="Initial password" type="password" autoComplete="new-password" value={form.data.password} onChange={e => form.setData('password', e.target.value)} helperText="At least 12 characters; share it privately." />}<Typography variant="caption" color="text.secondary">Changes to email, role or status sign out existing sessions. Business membership is managed separately.</Typography></OfficeDialog>
 <OfficeDialog open={!!resetting} title={`Reset password · ${resetting?.name || ''}`} submit="Replace password" form={password} onClose={() => {
      setResetting(null);
      password.reset();
    }} onSubmit={e => {
      e.preventDefault();
      password.put(route(`${prefix}.password`, resetting.id), {
        onSuccess: () => {
          setResetting(null);
          password.reset();
        }
      });
    }}><Typography variant="body2">This replaces the current password and signs out existing browser and cashier sessions. Share the new password privately.</Typography>{[['password', 'New password'], ['password_confirmation', 'Confirm new password']].map(([k, l]) => <TextField key={k} required label={l} type="password" autoComplete="new-password" value={password.data[k]} onChange={e => password.setData(k, e.target.value)} helperText={k === 'password' ? 'At least 12 characters.' : null} />)}<TextField required label="Reason for reset" value={password.data.note} onChange={e => password.setData('note', e.target.value)} /></OfficeDialog>
 </OfficePage>;
}
