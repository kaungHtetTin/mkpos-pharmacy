import React, { useState } from 'react';
import { Link, router } from '@/spa';
import { Box, Button, Chip, Stack, TableCell, TableRow, TextField, Typography } from '@mui/material';
import { OfficePage, OfficeTable, officeFiltersSx } from '@/Components/OfficeUi';
import { BillingStatus } from '@/Components/BillingUi';
export default function Calendar({
  auth,
  events = [],
  filters
}) {
  const [f, setF] = useState(filters),
    [day, setDay] = useState(''),
    [page, setPage] = useState(1);
  const [year, month] = filters.month.split('-').map(Number);
  const offset = new Date(year, month - 1, 1).getDay(),
    count = new Date(year, month, 0).getDate();
  const selected = day ? events.filter(e => e.date === day) : events;
  const link = id => auth.user.platform_role === 'billing' ? route('platform.billing.index', {
    business_id: id
  }) : route('platform.businesses.show', id);
  return <OfficePage auth={auth} title="Subscription calendar" description="Business access end dates, including trials, prepaid periods and applicable grace. Dates follow the platform timezone."><Box component="form" sx={officeFiltersSx} onSubmit={e => {
      e.preventDefault();
      router.get(route('platform.calendar'), f);
    }}><TextField type="month" label="Month" InputLabelProps={{
        shrink: true
      }} value={f.month} onChange={e => setF({
        ...f,
        month: e.target.value
      })} /><TextField label="Search businesses" value={f.q} onChange={e => setF({
        ...f,
        q: e.target.value
      })} /><Button type="submit" variant="outlined">Show month</Button><Button onClick={() => {
        setDay('');
        setPage(1);
      }}>All dates · {events.length}</Button></Box><Box sx={{
      overflowX: 'auto',
      border: 1,
      borderColor: 'divider',
      borderRadius: 1
    }}><Box sx={{
        minWidth: 560,
        display: 'grid',
        gridTemplateColumns: 'repeat(7,minmax(0,1fr))'
      }}>{['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(d => <Typography key={d} variant="caption" sx={{
          p: 1,
          bgcolor: 'action.hover',
          textAlign: 'center'
        }}>{d}</Typography>)}{Array.from({
          length: Math.ceil((offset + count) / 7) * 7
        }, (_, i) => {
          const n = i - offset + 1,
            valid = n > 0 && n <= count,
            date = `${filters.month}-${String(n).padStart(2, '0')}`,
            list = valid ? events.filter(e => e.date === date) : [];
          return <Box key={i} component={valid ? 'button' : 'div'} type={valid ? 'button' : undefined} aria-label={valid ? `${date}: ${list.length} access deadlines` : undefined} onClick={valid ? () => {
            setDay(date);
            setPage(1);
          } : undefined} sx={{
            minHeight: 70,
            p: 1,
            textAlign: 'left',
            font: 'inherit',
            color: 'text.primary',
            border: 0,
            borderTop: '1px solid',
            borderRight: '1px solid',
            borderColor: 'divider',
            bgcolor: !valid ? 'action.hover' : day === date ? 'primary.soft' : 'background.paper',
            cursor: valid ? 'pointer' : 'default',
            '&:focus-visible': {
              outline: '2px solid',
              outlineColor: 'primary.main',
              outlineOffset: -2
            }
          }}>{valid && <><Typography variant="caption">{n}</Typography>{list.length > 0 && <Chip size="small" color="primary" label={`${list.length} due`} sx={{
                display: 'flex',
                mt: .5,
                maxWidth: 95
              }} />}</>}</Box>;
        })}</Box></Box><Typography variant="subtitle2">{day || filters.month} · {selected.length} access deadlines</Typography><OfficeTable headings={['Date', 'Business', 'Plan', 'Current state']} empty={!selected.length}>{selected.slice((page - 1) * 25, page * 25).map(e => <TableRow key={e.business_id}><TableCell>{e.date}</TableCell><TableCell><Link href={link(e.business_id)}>{e.business_name}</Link></TableCell><TableCell>{e.plan}</TableCell><TableCell><BillingStatus value={e.state} /></TableCell></TableRow>)}</OfficeTable><Stack direction="row" justifyContent="space-between" alignItems="center"><Typography variant="caption" color="text.secondary">{selected.length} records · page {page} of {Math.max(1, Math.ceil(selected.length / 25))}</Typography><Stack direction="row"><Button disabled={page === 1} onClick={() => setPage(page - 1)}>Previous</Button><Button disabled={page * 25 >= selected.length} onClick={() => setPage(page + 1)}>Next</Button></Stack></Stack></OfficePage>;
}
