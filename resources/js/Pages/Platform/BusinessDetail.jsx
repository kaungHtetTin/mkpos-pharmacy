import React, { useState } from 'react';
import { Link, useForm } from '@/spa';
import { Box, Button, Chip, MenuItem, Stack, TableCell, TableRow, Tabs, Tab, TextField, Typography } from '@mui/material';
import { OfficePage, OfficeTable, OfficeDialog } from '@/Components/OfficeUi';
import StatCardGrid from '@/Components/StatCardGrid';
import { BillingStatus, BillingUsage, billingDate, billingMoney } from '@/Components/BillingUi';
export default function BusinessDetail({
  auth,
  record,
  subscription,
  usage,
  members = [],
  branches = [],
  invoices = [],
  payments = [],
  activity = [],
  support = [],
  billing_visible,
  can_extend_trial
}) {
  const [tab, setTab] = useState('overview'),
    [edit, setEdit] = useState(false),
    [trial, setTrial] = useState(false);
  const details = useForm({
      name: record.name,
      slug: record.slug,
      status: record.status
    }),
    extend = useForm({
      days: 7,
      note: ''
    });
  return <OfficePage auth={auth} title={record.name} description={`${record.slug} · Created ${billingDate(record.created_at)}`} actions={<Stack direction="row" gap={.5} flexWrap="wrap"><Button component={Link} href={route('platform.businesses.index')}>All businesses</Button><Button onClick={() => setEdit(true)} variant="outlined">Edit business</Button>{billing_visible && <Button component={Link} href={route('platform.billing.index', {
      business_id: record.id
    })}>Open billing</Button>}</Stack>}>
 <Stack direction="row" gap={1} alignItems="center"><Chip size="small" label={record.status} /><BillingStatus value={subscription.state} /><Typography variant="body2">{subscription.plan?.name || 'No plan'} · Access through {billingDate(subscription.access_until)}</Typography></Stack>
 <BillingUsage usage={usage} plan={subscription.plan} />
 <Tabs value={tab} onChange={(e, v) => setTab(v)} variant="scrollable" scrollButtons="auto">{[['overview', 'Overview'], ['branches', 'Branches'], ['accounts', 'Accounts'], ...(billing_visible ? [['billing', 'Billing history']] : []), ['activity', 'Activity'], ['support', 'Support']].map(([v, l]) => <Tab key={v} value={v} label={l} />)}</Tabs>
 {tab === 'overview' && <Stack spacing={1.5}><StatCardGrid items={[{
        label: 'Branches',
        value: branches.length
      }, {
        label: 'Active accounts',
        value: usage.users
      }, {
        label: 'Medicines',
        value: usage.products
      }]} /><Typography variant="body2">Automatic renewal: {subscription.auto_renew ? 'Enabled' : 'Disabled'}. {subscription.next_period ? `Next paid period ends ${billingDate(subscription.next_period.ends_at)}.` : ''}</Typography>{can_extend_trial && <Button variant="outlined" onClick={() => setTrial(true)} sx={{
        alignSelf: 'flex-start'
      }}>Extend trial</Button>}<Typography variant="subtitle2">Owners and primary contacts</Typography>{members.filter(u => u.roles.some(x => ['Owner', 'Root'].includes(x))).map(u => <Stack key={u.id} direction="row" gap={1} flexWrap="wrap"><Typography variant="body2" fontWeight={600}>{u.name}</Typography><Typography variant="body2" color="text.secondary">{u.email}</Typography><Chip size="small" label={u.status} /></Stack>)}</Stack>}
 {tab === 'branches' && <OfficeTable headings={['Branch', 'Contact', 'Address', 'Status']} empty={!branches.length}>{branches.map(b => <TableRow key={b.id}><TableCell>{b.name}</TableCell><TableCell>{b.email || b.phone || '—'}</TableCell><TableCell>{b.address || '—'}</TableCell><TableCell>{b.status}</TableCell></TableRow>)}</OfficeTable>}
 {tab === 'accounts' && <Stack spacing={1}><Button component={Link} href={route('platform.users.index', {
        business_id: record.id
      })} sx={{
        alignSelf: 'flex-start'
      }}>Manage business accounts</Button><Typography variant="caption" color="text.secondary">Showing up to 100 accounts. Open account management for the full directory and password resets.</Typography><OfficeTable headings={['Name', 'Email', 'Roles', 'Account', 'Membership']} empty={!members.length}>{members.map(u => <TableRow key={u.id}><TableCell>{u.name}</TableCell><TableCell>{u.email}</TableCell><TableCell>{u.roles.join(', ') || '—'}</TableCell><TableCell>{u.status}</TableCell><TableCell>{u.membership_status}</TableCell></TableRow>)}</OfficeTable></Stack>}
 {tab === 'billing' && billing_visible && <Stack spacing={1.5}><Typography variant="subtitle2">Recent invoices</Typography><OfficeTable headings={['Invoice', 'Amount', 'Status', 'Date']} empty={!invoices.length}>{invoices.map(i => <TableRow key={i.id}><TableCell>{i.number}</TableCell><TableCell>{billingMoney(i.amount_minor, i.currency)}</TableCell><TableCell><BillingStatus value={i.status} /></TableCell><TableCell>{billingDate(i.created_at)}</TableCell></TableRow>)}</OfficeTable><Typography variant="subtitle2">Recent payments</Typography><OfficeTable headings={['Reference', 'Amount', 'Status', 'Date']} empty={!payments.length}>{payments.map(p => <TableRow key={p.id}><TableCell>{p.reference}</TableCell><TableCell>{billingMoney(p.amount_minor, p.currency)}</TableCell><TableCell><BillingStatus value={p.status} /></TableCell><TableCell>{billingDate(p.created_at)}</TableCell></TableRow>)}</OfficeTable></Stack>}
 {tab === 'activity' && <OfficeTable headings={['Action', 'Actor', 'Date']} empty={!activity.length}>{activity.map(a => <TableRow key={a.id}><TableCell>{a.action}</TableCell><TableCell>{a.actor?.name || 'System'}</TableCell><TableCell>{billingDate(a.created_at)}</TableCell></TableRow>)}</OfficeTable>}
 {tab === 'support' && <OfficeTable headings={['Subject', 'Status', 'Created']} empty={!support.length}>{support.map(t => <TableRow key={t.id}><TableCell><Link href={route('platform.support.show', t.id)}>{t.subject}</Link></TableCell><TableCell>{t.status}</TableCell><TableCell>{billingDate(t.created_at)}</TableCell></TableRow>)}</OfficeTable>}
 <OfficeDialog open={edit} title="Edit business" form={details} onClose={() => setEdit(false)} onSubmit={e => {
      e.preventDefault();
      details.put(route('platform.businesses.details', record.id), {
        onSuccess: () => setEdit(false)
      });
    }}>{[['name', 'Business name'], ['slug', 'Business slug']].map(([k, l]) => <TextField key={k} required label={l} value={details.data[k]} onChange={e => details.setData(k, e.target.value)} error={!!details.errors[k]} helperText={details.errors[k]} />)}<TextField select label="Business status" value={details.data.status} onChange={e => details.setData('status', e.target.value)}><MenuItem value="Active">Active</MenuItem><MenuItem value="Suspended">Suspended</MenuItem></TextField></OfficeDialog>
 <OfficeDialog open={trial} title="Extend trial" form={extend} onClose={() => setTrial(false)} submit="Extend trial" onSubmit={e => {
      e.preventDefault();
      extend.post(route('platform.businesses.trial', record.id), {
        onSuccess: () => {
          setTrial(false);
          extend.reset();
        }
      });
    }}><Typography variant="body2">Available before the first paid period. Existing paid invoices and business status stay unchanged.</Typography><TextField required label="Extra trial days" type="number" value={extend.data.days} onChange={e => extend.setData('days', e.target.value)} inputProps={{
        min: 1,
        max: 365
      }} /><TextField required multiline minRows={2} label="Reason for extension" value={extend.data.note} onChange={e => extend.setData('note', e.target.value)} /></OfficeDialog>
 </OfficePage>;
}
