import React, { useState } from 'react';
import { router, Link } from '@/spa';
import { Box, Button, MenuItem, TableCell, TableRow, TextField } from '@mui/material';
import { OfficePage, OfficeTable, officeFiltersSx } from '@/Components/OfficeUi';
import { BillingPager, billingDate } from '@/Components/BillingUi';
export default function Audit({
  auth,
  entries,
  filters = {},
  actions = []
}) {
  const [f, setF] = useState({
    q: filters.q || '',
    action: filters.action || '',
    from: filters.from || '',
    to: filters.to || ''
  });
  return <OfficePage auth={auth} title="Platform audit" description="Filter office actions by actor, business, action or date."><Box component="form" sx={officeFiltersSx} onSubmit={e => {
      e.preventDefault();
      router.get(route('platform.audit'), f);
    }}><TextField label="Search actor, business or action" value={f.q} onChange={e => setF({
        ...f,
        q: e.target.value
      })} /><TextField select label="Action" value={f.action} onChange={e => setF({
        ...f,
        action: e.target.value
      })}><MenuItem value="">All actions</MenuItem>{actions.map(a => <MenuItem key={a} value={a}>{a}</MenuItem>)}</TextField>{['from', 'to'].map(k => <TextField key={k} type="date" label={k === 'from' ? 'From' : 'Through'} InputLabelProps={{
        shrink: true
      }} value={f[k]} onChange={e => setF({
        ...f,
        [k]: e.target.value
      })} />)}<Button variant="outlined" type="submit">Apply</Button><Button component={Link} href={route('platform.audit')}>Clear</Button></Box><OfficeTable headings={['When', 'Action', 'Actor', 'Business', 'Details']} empty={!entries.data.length}>{entries.data.map(e => <TableRow key={e.id}><TableCell>{billingDate(e.created_at)}</TableCell><TableCell>{e.action}</TableCell><TableCell>{e.actor?.name || 'System'}</TableCell><TableCell>{e.business?.name || 'Platform'}</TableCell><TableCell sx={{
          maxWidth: 360,
          overflowWrap: 'anywhere'
        }}>{JSON.stringify(e.properties)}</TableCell></TableRow>)}</OfficeTable><BillingPager page={entries} /></OfficePage>;
}
