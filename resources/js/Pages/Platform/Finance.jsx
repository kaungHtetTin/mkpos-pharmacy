import React, { useState } from 'react';
import { Link, router, useForm } from '@/spa';
import { Alert, Box, Button, Chip, Dialog, DialogTitle, DialogContent, DialogActions, FormControlLabel, MenuItem, Stack, Switch, TableCell, TableRow, TextField, Typography } from '@mui/material';
import { OfficePage, OfficeTable, OfficeDialog, officeFiltersSx } from '@/Components/OfficeUi';
import StatCardGrid from '@/Components/StatCardGrid';
import { BillingPager, billingMoney } from '@/Components/BillingUi';
const labels = {
  subscription: 'Subscription receipt',
  income: 'Other income',
  expense: 'Expense',
  contribution: 'Founder contribution',
  capital_return: 'Capital return',
  opening: 'Opening adjustment',
  transfer: 'Book transfer',
  allocation: 'Profit allocation',
  payout: 'Founder payout'
};
export default function Finance({
  auth,
  summary: s,
  entries = {
    data: []
  },
  filters,
  currencies,
  owner,
  current_date
}) {
  const [f, setF] = useState({
      currency: s.currency,
      q: filters.q || '',
      type: filters.type || '',
      book_id: filters.book_id || '',
      founder_id: filters.founder_id || '',
      status: filters.status || 'posted',
      from: filters.from || '',
      to: filters.to || ''
    }),
    [posting, setPosting] = useState(false),
    [voiding, setVoiding] = useState(null),
    [assigning, setAssigning] = useState(null),
    [detail, setDetail] = useState(null);
  const empty = {
    request_key: '',
    type: 'expense',
    currency: s.currency,
    amount: '',
    entry_date: current_date,
    description: '',
    category: '',
    reference: '',
    counterparty: '',
    book_id: '',
    to_book_id: '',
    founder_id: '',
    pay_now: false,
    settings_version: s.settings_version,
    direction: 'increase'
  };
  const form = useForm(empty),
    reverse = useForm({
      reason: ''
    }),
    assign = useForm({
      book_id: ''
    });
  const open = type => {
    form.clearErrors();
    form.setData({
      ...empty,
      request_key: crypto.randomUUID(),
      type,
      book_id: s.books.find(b => b.is_active && b.is_default)?.id || s.books.find(b => b.is_active)?.id || ''
    });
    setPosting(true);
  };
  const isFounder = ['contribution', 'capital_return', 'payout'].includes(form.data.type),
    allocation = form.data.type === 'allocation',
    transfer = form.data.type === 'transfer',
    needsBook = !allocation || form.data.pay_now;
  return <OfficePage auth={auth} title="Platform financial book" description="Recorded profit = subscription receipts + other income − expenses. Contributions, capital returns, founder payouts and transfers do not change profit." actions={<Stack direction="row" gap={.5} flexWrap="wrap"><Button variant="outlined" onClick={() => open('transfer')}>Transfer</Button><Button variant="contained" onClick={() => open('expense')}>New entry</Button>{owner && <Button variant="contained" disabled={s.unallocated_minor <= 0 || !s.founders.some(f => f.is_active)} onClick={() => open('allocation')}>Allocate profit</Button>}</Stack>}>
 <StatCardGrid items={[['Income', s.income_minor], ['Expenses', s.expenses_minor], ['Recorded profit', s.profit_minor], ['Recorded cash', s.cash_minor]].map(([label, n]) => ({
      label,
      value: billingMoney(n, s.currency)
    }))} /><Stack direction="row" gap={2} flexWrap="wrap"><Typography variant="body2">Unallocated profit: <strong>{billingMoney(s.unallocated_minor, s.currency)}</strong></Typography><Typography variant="body2">Allocated profit: <strong>{billingMoney(s.allocated_minor, s.currency)}</strong></Typography></Stack><Typography variant="caption" color="text.secondary">Balances cover all recorded dates in {s.currency}. Filters affect the entries table and CSV. No bank transfers are sent by this app.</Typography>
 {s.unallocated_minor < 0 && <Alert severity="warning">Recorded profit is below previous allocations after adjustments. New profit allocation is paused until the deficit is covered.</Alert>}{s.unassigned_minor > 0 && <Alert severity="warning">Unassigned subscription receipts: {billingMoney(s.unassigned_minor, s.currency)}. They count as income and recorded cash, but cannot be spent from a bank book until assigned.</Alert>}
 {!s.books.some(b => b.is_active) && <Alert severity="info">Create a bank book before recording cash movements.</Alert>}{owner && !s.founders.some(f => f.is_active) && <Alert severity="info">Set founder shares before allocating profit.</Alert>}<Stack direction="row" gap={1} flexWrap="wrap"><Button component={Link} href={route('platform.bank-books.index', {
        currency: s.currency
      })}>Bank books</Button><Button component={Link} href={route('platform.founders.index', {
        currency: s.currency
      })}>Founder statements</Button>{owner && <><Button onClick={() => open('payout')}>Pay founder</Button><Button onClick={() => open('opening')}>Opening adjustment</Button></>}<Button component="a" href={route('platform.finance.export', filters)}>Export filtered entries</Button></Stack>
 <OfficeTable headings={['Bank book', 'Recorded balance', 'Receiving book', 'Status']} empty={!s.books.length}>{s.books.map(b => <TableRow key={b.id}><TableCell>{b.name}</TableCell><TableCell>{billingMoney(b.balance_minor, s.currency)}</TableCell><TableCell>{b.is_default ? 'Subscription default' : '—'}</TableCell><TableCell>{b.is_active ? 'Active' : 'Archived'}</TableCell></TableRow>)}</OfficeTable>
 <Box component="form" sx={officeFiltersSx} onSubmit={e => {
      e.preventDefault();
      router.get(route('platform.finance'), f);
    }}><TextField label="Currency" value="MMK" InputProps={{readOnly:true}} /><TextField label="Search description or reference" value={f.q} onChange={e => setF({
        ...f,
        q: e.target.value
      })} /><TextField select label="Entry type" value={f.type} onChange={e => setF({
        ...f,
        type: e.target.value
      })}><MenuItem value="">All types</MenuItem>{Object.entries(labels).map(([v, l]) => <MenuItem key={v} value={v}>{l}</MenuItem>)}</TextField><TextField select label="Bank book" value={f.book_id} onChange={e => setF({
        ...f,
        book_id: e.target.value
      })}><MenuItem value="">All bank books</MenuItem>{s.books.map(b => <MenuItem key={b.id} value={b.id}>{b.name}</MenuItem>)}</TextField><TextField select label="Founder statement" value={f.founder_id} onChange={e => setF({
        ...f,
        founder_id: e.target.value
      })}><MenuItem value="">All founders</MenuItem>{s.founders.map(x => <MenuItem key={x.id} value={x.id}>{x.name}</MenuItem>)}</TextField><TextField select label="Posting state" value={f.status} onChange={e => setF({
        ...f,
        status: e.target.value
      })}>{[['posted', 'Posted'], ['voided', 'Voided'], ['all', 'All history']].map(([v, l]) => <MenuItem key={v} value={v}>{l}</MenuItem>)}</TextField>{['from', 'to'].map(k => <TextField key={k} label={k === 'from' ? 'From' : 'Through'} type="date" InputLabelProps={{
        shrink: true
      }} value={f[k]} onChange={e => setF({
        ...f,
        [k]: e.target.value
      })} />)}<Button type="submit" variant="outlined">Apply filters</Button><Button component={Link} href={route('platform.finance', {
        currency: s.currency
      })}>Clear</Button></Box>
 <OfficeTable headings={['Date / type', 'Description', 'Bank book', 'Founder', 'Amount', 'State', 'Actions']} empty={!entries.data.length}>{entries.data.map(e => <TableRow key={`${e.source}-${e.id}`}><TableCell>{e.entry_date}<Typography variant="caption" display="block">{labels[e.type]}</Typography></TableCell><TableCell>{e.description}<Typography variant="caption" display="block">{e.reference}</Typography></TableCell><TableCell>{e.book_name || (e.type === 'allocation' ? 'No cash movement' : 'Unassigned')}{e.to_book_name && ` → ${e.to_book_name}`}</TableCell><TableCell>{e.founder_name || (e.type === 'allocation' ? 'All active founders' : '—')}</TableCell><TableCell><Box component="span" sx={{
            textDecoration: e.voided_at ? 'line-through' : 'none'
          }}>{billingMoney(e.founder_share_minor ?? e.amount_minor, e.currency)}</Box><Typography variant="caption" display="block">{e.type === 'allocation' ? 'No cash movement' : e.type === 'transfer' ? 'Book transfer' : e.type === 'opening' ? e.amount_minor < 0 ? 'Balance decrease' : 'Balance increase' : ['expense', 'payout', 'capital_return'].includes(e.type) ? 'Money out' : 'Money in'}</Typography>{e.founder_share_minor != null && <Typography variant="caption" display="block">Founder share</Typography>}</TableCell><TableCell><Chip size="small" label={e.voided_at ? 'Voided' : 'Posted'} color={e.voided_at ? 'default' : 'success'} /></TableCell><TableCell><Button size="small" onClick={() => setDetail(e)}>View</Button>{e.source === 'subscription' && !e.book_id && <Button size="small" onClick={() => {
            assign.clearErrors();
            assign.reset();
            setAssigning(e);
          }}>Assign book</Button>}{owner && e.source === 'manual' && !e.voided_at && <Button size="small" color="error" onClick={() => {
            reverse.clearErrors();
            reverse.reset();
            setVoiding(e);
          }}>Void</Button>}</TableCell></TableRow>)}</OfficeTable><BillingPager page={entries} />
 <OfficeDialog open={posting} title={labels[form.data.type] || 'New financial entry'} form={form} onClose={() => setPosting(false)} submit={allocation ? 'Allocate profit' : 'Record entry'} onSubmit={e => {
      e.preventDefault();
      form.post(route('platform.finance.store'), {
        onSuccess: () => {
          setPosting(false);
          form.reset();
        }
      });
    }}>
 {!allocation && !transfer && !['payout', 'opening'].includes(form.data.type) && <TextField select label="Entry type" value={form.data.type} onChange={e => form.setData('type', e.target.value)}>{['income', 'expense', 'contribution', ...(owner ? ['capital_return'] : [])].map(v => <MenuItem key={v} value={v}>{labels[v]}</MenuItem>)}</TextField>}
 <Stack direction={{
        xs: 'column',
        sm: 'row'
      }} spacing={1}><TextField label="Currency" value={s.currency} InputProps={{
          readOnly: true
        }} /><TextField required type="date" label="Entry date" InputLabelProps={{
          shrink: true
        }} inputProps={{
          max: current_date,
          ...(['allocation', 'payout'].includes(form.data.type) ? {
            min: current_date
          } : {})
        }} value={form.data.entry_date} onChange={e => form.setData('entry_date', e.target.value)} /></Stack><TextField required label="Amount" inputProps={{
        inputMode: 'decimal'
      }} value={form.data.amount} onChange={e => form.setData('amount', e.target.value)} helperText="Use at most two decimal places." />
 {form.data.type === 'opening' && <TextField select label="Adjustment direction" value={form.data.direction} onChange={e => form.setData('direction', e.target.value)}><MenuItem value="increase">Increase opening balance</MenuItem><MenuItem value="decrease">Decrease opening balance</MenuItem></TextField>}
 {allocation && <><Typography variant="body2">Unallocated profit: {billingMoney(s.unallocated_minor, s.currency)}. Current shares are saved with this allocation.</Typography><Stack spacing={.5}>{s.founders.filter(x => x.is_active).map(x => <Typography key={x.id} variant="caption">{x.name}: {(x.share_bps / 100).toFixed(2)}%</Typography>)}</Stack><FormControlLabel label="Record all founder shares as paid now" control={<Switch checked={form.data.pay_now} onChange={e => form.setData('pay_now', e.target.checked)} />} /><Typography variant="caption" color="text.secondary">Leave off to record amounts owed without reducing bank balances. Cent rounding uses largest fractional remainders.</Typography></>}
 {needsBook && <TextField required select label={transfer ? 'From bank book' : 'Paying / receiving bank book'} value={form.data.book_id} onChange={e => form.setData('book_id', e.target.value)}><MenuItem value="" disabled>Choose bank book</MenuItem>{s.books.filter(b => b.is_active).map(b => <MenuItem key={b.id} value={b.id}>{b.name} · {billingMoney(b.balance_minor, s.currency)}</MenuItem>)}</TextField>}
 {transfer && <TextField required select label="To bank book" value={form.data.to_book_id} onChange={e => form.setData('to_book_id', e.target.value)}><MenuItem value="" disabled>Choose receiving book</MenuItem>{s.books.filter(b => b.is_active).map(b => <MenuItem key={b.id} value={b.id} disabled={b.id === form.data.book_id}>{b.name}</MenuItem>)}</TextField>}
 {isFounder && <TextField required select label="Founder" value={form.data.founder_id} onChange={e => form.setData('founder_id', e.target.value)}><MenuItem value="" disabled>Choose founder</MenuItem>{s.founders.filter(x => form.data.type === 'contribution' ? x.is_active : form.data.type === 'payout' ? x.owed_minor > 0 : x.capital_minor > 0).map(x => <MenuItem key={x.id} value={x.id}>{x.name} · {form.data.type === 'payout' ? `Unpaid ${billingMoney(x.owed_minor, s.currency)}` : form.data.type === 'capital_return' ? `Capital ${billingMoney(x.capital_minor, s.currency)}` : x.is_active ? 'Active' : 'Archived'}</MenuItem>)}</TextField>}
 <TextField required multiline minRows={2} label="Description / reason" value={form.data.description} onChange={e => form.setData('description', e.target.value)} />{!allocation && <><TextField label="Category" value={form.data.category} onChange={e => form.setData('category', e.target.value)} placeholder="Hosting, salaries, marketing…" /><TextField label="Counterparty" value={form.data.counterparty} onChange={e => form.setData('counterparty', e.target.value)} /></>}<TextField label="Transfer / receipt reference" value={form.data.reference} onChange={e => form.setData('reference', e.target.value)} /><Typography variant="caption" color="text.secondary">This records bookkeeping only. Check actual bank statements before recording money received or paid.</Typography>
 </OfficeDialog>
 <OfficeDialog open={!!voiding} title="Void financial entry" submit="Void entry" form={reverse} onClose={() => setVoiding(null)} onSubmit={e => {
      e.preventDefault();
      reverse.post(route('platform.finance.void', voiding.id), {
        onSuccess: () => setVoiding(null)
      });
    }}><Typography variant="body2">{voiding?.description}. The original entry remains in history. Voiding a combined allocation also reverses its remaining automatic payouts. Dependent spending or payouts may need to be reversed first.</Typography><TextField required multiline minRows={2} label="Reason for voiding" value={reverse.data.reason} onChange={e => reverse.setData('reason', e.target.value)} /></OfficeDialog>
 <OfficeDialog open={!!assigning} title="Assign subscription receipt" form={assign} onClose={() => setAssigning(null)} onSubmit={e => {
      e.preventDefault();
      assign.put(route('platform.finance.assign', assigning.id), {
        onSuccess: () => setAssigning(null)
      });
    }}><Typography variant="body2">{assigning?.description} · {billingMoney(assigning?.amount_minor, s.currency)}. Confirm the actual receiving account. Existing assigned receipts are moved using a transfer entry.</Typography><TextField required select label="Receiving bank book" value={assign.data.book_id} onChange={e => assign.setData('book_id', e.target.value)}><MenuItem value="" disabled>Choose bank book</MenuItem>{s.books.filter(b => b.is_active).map(b => <MenuItem key={b.id} value={b.id}>{b.name}</MenuItem>)}</TextField></OfficeDialog>
 <Dialog open={!!detail} onClose={() => setDetail(null)} fullWidth maxWidth="sm" sx={{
      '& .MuiDialog-paper': {
        maxWidth: 620
      }
    }}><DialogTitle>Financial entry details</DialogTitle><DialogContent dividers><Stack spacing={1}><Typography fontWeight={700}>{detail?.description}</Typography><Typography variant="body2">{labels[detail?.type]} · {detail?.entry_date} · {billingMoney(detail?.amount_minor, s.currency)}</Typography><Typography variant="body2">Bank book: {detail?.book_name || (detail?.type === 'allocation' ? 'No cash movement' : 'Unassigned bank book')}{detail?.to_book_name && ` → ${detail.to_book_name}`}</Typography><Typography variant="body2">Recorded by: {detail?.actor_name || 'System'}</Typography>{detail?.reference && <Typography variant="body2">Reference: {detail.reference}</Typography>}{detail?.void_reason && <Alert severity="info">Voided: {detail.void_reason}</Alert>}{detail?.parent_entry_id && <Typography variant="caption">Automatically recorded with a profit allocation</Typography>}{detail?.shares?.length > 0 && <><Typography variant="subtitle2">Saved founder shares</Typography>{detail.shares.map(x => <Typography key={x.id} variant="body2">{x.founder_name} · {(x.share_bps / 100).toFixed(2)}% · {billingMoney(x.amount_minor, s.currency)}</Typography>)}</>}</Stack></DialogContent><DialogActions><Button onClick={() => setDetail(null)}>Close</Button></DialogActions></Dialog>
 </OfficePage>;
}
