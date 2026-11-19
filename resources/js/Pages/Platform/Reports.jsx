import React, { useState } from 'react';
import { router, Link } from '@/spa';
import { Box, Button, Stack, TableCell, TableRow, TextField, Typography } from '@mui/material';
import { OfficePage, OfficeTable, officeFiltersSx } from '@/Components/OfficeUi';
import StatCardGrid from '@/Components/StatCardGrid';
import { CompactBarChart } from '@/Components/CompactReportChart';
import { billingMoney, BillingStatus } from '@/Components/BillingUi';
export default function Reports({
  auth,
  filters,
  collections = [],
  monthly = [],
  invoice_totals = [],
  stats = {}
}) {
  const [dates, setDates] = useState(filters);
  return <OfficePage auth={auth} title="Subscription reports" description="Approved platform subscription collections and invoice activity. Pharmacy sales are excluded, and currencies remain separate."><Box component="form" sx={officeFiltersSx} onSubmit={e => {
      e.preventDefault();
      router.get(route('platform.reports'), dates);
    }}>{['from', 'to'].map(k => <TextField key={k} type="date" label={k === 'from' ? 'From' : 'Through'} InputLabelProps={{
        shrink: true
      }} value={dates[k]} onChange={e => setDates({
        ...dates,
        [k]: e.target.value
      })} />)}<Button variant="outlined" type="submit">Apply dates</Button><Button component="a" href={route('platform.reports.export', filters)}>Export approved collections</Button></Box><StatCardGrid items={Object.entries(stats).map(([k, v]) => ({
      label: k.replaceAll('_', ' '),
      value: v,
      description: k.startsWith('new_') ? 'Selected date range' : 'Current outstanding'
    }))} /><OfficeTable headings={['Currency', 'Approved payments', 'Collections']} empty={!collections.length}>{collections.map(c => <TableRow key={c.currency}><TableCell>{c.currency}</TableCell><TableCell>{c.count}</TableCell><TableCell>{billingMoney(c.amount_minor, c.currency)}</TableCell></TableRow>)}</OfficeTable><Box sx={{
      display: 'grid',
      gridTemplateColumns: {
        xs: '1fr',
        lg: 'repeat(2,minmax(0,1fr))'
      },
      gap: 1.5
    }}>{collections.map(c => <CompactBarChart key={c.currency} title={`Monthly collections · ${c.currency}`} layout="horizontal" data={monthly.filter(m => m.currency === c.currency).map(m => ({
        ...m,
        total: Number(m.amount_minor) / 100
      }))} xKey="month" series={[{
        key: 'total',
        label: 'Collections',
        color: '#176B5B'
      }]} valueFormatter={v => `${c.currency} ${Number(v).toLocaleString()}`} />)}</Box><Typography variant="subtitle2">Invoices created in selected range</Typography><OfficeTable headings={['Currency', 'Status', 'Invoices', 'Quoted amount']} empty={!invoice_totals.length}>{invoice_totals.map(i => <TableRow key={`${i.currency}-${i.status}`}><TableCell>{i.currency}</TableCell><TableCell><BillingStatus value={i.status} /></TableCell><TableCell>{i.count}</TableCell><TableCell>{billingMoney(i.amount_minor, i.currency)}</TableCell></TableRow>)}</OfficeTable></OfficePage>;
}
