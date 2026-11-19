import React, { useState } from 'react';
import { Link, router, useForm } from '@/spa';
import { Alert, Box, Button, Chip, FormControlLabel, MenuItem, Stack, Switch, TableCell, TableRow, TextField, Typography } from '@mui/material';
import { OfficePage, OfficeTable, OfficeDialog } from '@/Components/OfficeUi';
import { billingMoney } from '@/Components/BillingUi';
const bps = v => /^\d+(\.\d{1,2})?$/.test(String(v)) ? Math.round(Number(v) * 100) : -1;
export default function Founders({
  auth,
  configuration,
  summary: s,
  currencies,
  owner
}) {
  const [open, setOpen] = useState(false);
  const form = useForm({
    version: configuration.version,
    founders: []
  });
  const begin = () => {
    form.clearErrors();
    form.setData({
      version: configuration.version,
      founders: configuration.items.map(f => ({
        id: f.id,
        name: f.name,
        share: String(f.share_bps / 100),
        is_active: f.is_active
      }))
    });
    setOpen(true);
  };
  const change = (i, k, v) => form.setData('founders', form.data.founders.map((f, n) => n === i ? {
    ...f,
    [k]: v
  } : f));
  const total = form.data.founders.filter(f => f.is_active).reduce((n, f) => n + Math.max(0, bps(f.share)), 0);
  return <OfficePage auth={auth} title="Founders & profit shares" description="Founder statements keep contributions, allocated profit and payouts separate. Share changes apply to future allocations; saved allocations retain their original shares." actions={owner && <Button variant="contained" onClick={begin}>Edit founder shares</Button>}><Stack direction="row" gap={1} flexWrap="wrap"><TextField label="Statement currency" value="MMK" InputProps={{readOnly:true}} /><Button component={Link} href={route('platform.finance', {
        currency: s.currency
      })}>Financial book</Button></Stack>{!s.founders.some(f => f.is_active) && <Alert severity="info">Add founders and set their active profit shares to a total of 100% before allocating profit.</Alert>}<OfficeTable headings={['Founder', 'Current share', 'Net capital', 'Allocated profit', 'Paid profit', 'Unpaid profit', 'Status', 'Statement']} empty={!s.founders.length}>{s.founders.map(f => <TableRow key={f.id}><TableCell>{f.name}</TableCell><TableCell>{(f.share_bps / 100).toFixed(2)}%</TableCell><TableCell>{billingMoney(f.capital_minor, s.currency)}</TableCell><TableCell>{billingMoney(f.allocated_minor, s.currency)}</TableCell><TableCell>{billingMoney(f.paid_minor, s.currency)}</TableCell><TableCell>{billingMoney(f.owed_minor, s.currency)}</TableCell><TableCell><Chip size="small" label={f.is_active ? 'Active' : 'Archived'} /></TableCell><TableCell><Button component={Link} href={route('platform.finance', {
            currency: s.currency,
            founder_id: f.id
          })}>View book</Button></TableCell></TableRow>)}</OfficeTable><Typography variant="caption" color="text.secondary">Allocation and payment are distinct. You may allocate profit without moving cash, then record a payout later, or allocate and pay all founder shares together.</Typography><OfficeDialog open={open} title="Founder profit shares" form={form} onClose={() => setOpen(false)} onSubmit={e => {
      e.preventDefault();
      const rows = form.data.founders;
      if (rows.some(f => bps(f.share) < 0 || bps(f.share) > 10000)) {
        form.setError('founders', 'Enter percentages from 0 to 100 with at most two decimal places.');
        return;
      }
      form.put(route('platform.founders.save'), {
        data: {
          version: form.data.version,
          founders: form.data.founders.map(f => ({
            ...f,
            share_bps: bps(f.share)
          }))
        },
        onSuccess: () => setOpen(false)
      });
    }}><Alert severity={total === 10000 ? 'success' : 'info'}>Active shares: {(total / 100).toFixed(2)}% · must total 100% when any founder is active.</Alert>{form.data.founders.map((f, i) => <Box key={f.id || i} sx={{
        borderBottom: 1,
        borderColor: 'divider',
        pb: 1.5
      }}><Stack direction={{
          xs: 'column',
          sm: 'row'
        }} spacing={1}><TextField required label={`Founder ${i + 1} name`} value={f.name} onChange={e => change(i, 'name', e.target.value)} /><TextField required label={`Founder ${i + 1} share %`} type="number" inputProps={{
            min: 0,
            max: 100,
            step: '0.01'
          }} value={f.share} onChange={e => change(i, 'share', e.target.value)} sx={{
            maxWidth: {
              sm: 140
            }
          }} /></Stack><FormControlLabel label="Active for future allocations" control={<Switch checked={f.is_active} onChange={e => change(i, 'is_active', e.target.checked)} />} /></Box>)}<Button variant="outlined" disabled={form.data.founders.length >= 100} onClick={() => form.setData('founders', [...form.data.founders, {
        name: '',
        share: '0',
        is_active: true
      }])}>Add founder</Button><Typography variant="caption" color="text.secondary">Archive a founder using the switch. Past allocations, payouts and capital remain in the founder statement. Saving from an outdated screen is rejected.</Typography></OfficeDialog></OfficePage>;
}
