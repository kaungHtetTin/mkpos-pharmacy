import React, { useState } from 'react';
import { Link, router, useForm } from '@/spa';
import { Alert, Button, Chip, FormControlLabel, MenuItem, Stack, Switch, TableCell, TableRow, TextField, Typography } from '@mui/material';
import { OfficePage, OfficeTable, OfficeDialog } from '@/Components/OfficeUi';
import { billingMoney } from '@/Components/BillingUi';
export default function BankBooks({
  auth,
  summary: s,
  currencies = []
}) {
  const [editing, setEditing] = useState(null);
  const form = useForm({
    name: '',
    currency: s.currency,
    account_reference: '',
    is_active: true,
    is_default: false
  });
  const open = b => {
    form.clearErrors();
    form.setData({
      name: b?.name || '',
      currency: b?.currency || s.currency,
      account_reference: b?.account_reference || '',
      is_active: b?.is_active ?? true,
      is_default: b?.is_default ?? false
    });
    setEditing(b || {});
  };
  return <OfficePage auth={auth} title="Bank books" description="Record bank, wallet and cash balances. Each currency has its own receiving book. Changing the default affects future subscription receipts only." actions={<Button variant="contained" onClick={() => open(null)}>New bank book</Button>}><Stack direction="row" gap={1} flexWrap="wrap"><TextField select label="Currency" value={s.currency} onChange={e => router.get(route('platform.bank-books.index'), {
        currency: e.target.value
      })} sx={{
        width: 180
      }}>{currencies.map(c => <MenuItem key={c} value={c}>{c}</MenuItem>)}</TextField><Button component={Link} href={route('platform.finance', {
        currency: s.currency
      })}>Financial book</Button></Stack>{s.unassigned_minor > 0 && <Alert severity="warning">{billingMoney(s.unassigned_minor, s.currency)} in approved receipts has no bank book. Assign those receipts in the financial book after confirming where they were received.</Alert>}<OfficeTable headings={['Bank book', 'Reference', 'Recorded balance', 'Subscription receipts', 'Status', 'Actions']} empty={!s.books.length}>{s.books.map(b => <TableRow key={b.id}><TableCell>{b.name}</TableCell><TableCell>{b.account_reference || '—'}</TableCell><TableCell>{billingMoney(b.balance_minor, s.currency)}</TableCell><TableCell>{b.is_default ? 'Default receiving book' : '—'}</TableCell><TableCell><Chip size="small" label={b.is_active ? 'Active' : 'Archived'} /></TableCell><TableCell><Button onClick={() => open(b)}>Edit</Button><Button component={Link} href={route('platform.finance', {
            currency: s.currency,
            book_id: b.id
          })}>Statement</Button></TableCell></TableRow>)}</OfficeTable><Typography variant="caption" color="text.secondary">Record opening adjustments and transfers in the financial book. Books with history are archived, keeping their balances and statements.</Typography><OfficeDialog open={!!editing} title={editing?.id ? 'Edit bank book' : 'New bank book'} form={form} onClose={() => setEditing(null)} onSubmit={e => {
      e.preventDefault();
      const o = {
        onSuccess: () => setEditing(null)
      };
      editing.id ? form.put(route('platform.bank-books.update', editing.id), o) : form.post(route('platform.bank-books.store'), o);
    }}><TextField required label="Book name" value={form.data.name} onChange={e => form.setData('name', e.target.value)} placeholder="Main bank, office cash, wallet…" /><TextField required label="Currency code" value={form.data.currency} onChange={e => form.setData('currency', e.target.value.toUpperCase())} disabled={!!editing?.id} inputProps={{
        maxLength: 3
      }} helperText="Three-letter code, for example USD or MMK. Currency stays fixed after creation." /><TextField label="Account / wallet reference" value={form.data.account_reference} onChange={e => form.setData('account_reference', e.target.value)} /><FormControlLabel label="Active bank book" control={<Switch checked={form.data.is_active} onChange={e => form.setData('is_active', e.target.checked)} />} /><FormControlLabel label="Receive future subscription payments here" control={<Switch checked={form.data.is_default} disabled={!!editing?.is_default} onChange={e => form.setData('is_default', e.target.checked)} />} /><Typography variant="caption" color="text.secondary">To replace a receiving book, set another active book as the default. Historical receipts remain in their original books.</Typography></OfficeDialog></OfficePage>;
}
