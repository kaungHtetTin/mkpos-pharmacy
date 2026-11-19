import React, { useState } from 'react';
import { useForm } from '@/spa';
import { Button, Chip, MenuItem, Switch, FormControlLabel, TableCell, TableRow, TextField, Typography } from '@mui/material';
import { OfficePage, OfficeTable, OfficeDialog } from '@/Components/OfficeUi';
export default function PaymentMethods({
  auth,
  methods = []
}) {
  const [editing, setEditing] = useState(null);
  const defaults = {
    name: '',
    account_name: '',
    account_number: '',
    instructions: '',
    is_active: true,
    sort_order: 0
  };
  const form = useForm(defaults);
  const open = m => {
    form.clearErrors();
    form.setData(m ? Object.fromEntries(Object.keys(defaults).map(k => [k, m[k] ?? defaults[k]])) : defaults);
    setEditing(m || {});
  };
  return <OfficePage auth={auth} title="Payment methods" description="Payment destinations shown to business owners when submitting subscription requests. Payments still require office approval." actions={<Button variant="contained" onClick={() => open(null)}>Add payment method</Button>}><OfficeTable headings={['Method', 'Account', 'Status', 'Order', 'Action']} empty={!methods.length}>{methods.map(m => <TableRow key={m.id}><TableCell>{m.name}</TableCell><TableCell>{m.account_name || '—'}<Typography variant="caption" display="block">{m.account_number}</Typography></TableCell><TableCell><Chip size="small" label={m.is_active ? 'Active' : 'Hidden'} /></TableCell><TableCell>{m.sort_order}</TableCell><TableCell><Button onClick={() => open(m)}>Edit</Button></TableCell></TableRow>)}</OfficeTable><OfficeDialog open={!!editing} title={editing?.id ? 'Edit payment method' : 'Add payment method'} form={form} onClose={() => setEditing(null)} onSubmit={e => {
      e.preventDefault();
      const opts = {
        onSuccess: () => setEditing(null)
      };
      editing.id ? form.put(route('platform.payment-methods.update', editing.id), opts) : form.post(route('platform.payment-methods.store'), opts);
    }}><TextField required label="Display name" value={form.data.name} onChange={e => form.setData('name', e.target.value)} />{[['account_name', 'Account holder'], ['account_number', 'Account / wallet number']].map(([k, l]) => <TextField key={k} label={l} value={form.data[k]} onChange={e => form.setData(k, e.target.value)} />)}<TextField multiline minRows={3} label="Instructions for business owners" value={form.data.instructions} onChange={e => form.setData('instructions', e.target.value)} /><TextField label="Display order" type="number" inputProps={{
        min: 0,
        max: 10000
      }} value={form.data.sort_order} onChange={e => form.setData('sort_order', e.target.value)} /><FormControlLabel label="Show to business owners" control={<Switch checked={form.data.is_active} onChange={e => form.setData('is_active', e.target.checked)} />} /></OfficeDialog></OfficePage>;
}
