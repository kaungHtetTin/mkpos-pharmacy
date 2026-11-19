import React, { useState } from 'react';
import { Link, router, useForm } from '@/spa';
import { Box, Button, Chip, MenuItem, Stack, TableCell, TableRow, TextField, Typography } from '@mui/material';
import { OfficePage, OfficeTable, OfficeDialog, officeFiltersSx } from '@/Components/OfficeUi';
import StatCardGrid from '@/Components/StatCardGrid';
import { BillingStatus, BillingPager, billingDate } from '@/Components/BillingUi';

export default function Businesses({ auth, businesses = {data:[]}, filters = {}, stats = {} }) {
    const [search, setSearch] = useState({q: filters.q || '', status: filters.status || ''});
    const [open, setOpen] = useState(false);
    const canManage = ['owner','admin'].includes(auth.user.platform_role);
    const form = useForm({ name: '', slug: '', owner_name: '', owner_email: '', owner_password: '', currency_symbol: '$' });
    return <OfficePage auth={auth} title="Businesses" description="Business profiles, owner accounts, access and subscription billing in one place." actions={canManage && <Button variant="contained" onClick={() => setOpen(true)}>Create business</Button>}>
        <StatCardGrid items={[{label:'Businesses',value:stats.total || 0},{label:'Active',value:stats.active || 0},{label:'Suspended',value:stats.suspended || 0},{label:'Business accounts',value:stats.accounts || 0}]} />
        <Box component="form" sx={officeFiltersSx} onSubmit={e=>{e.preventDefault();router.get(route('platform.businesses.index'),search);}}><TextField label="Search business, name or email" value={search.q} onChange={e=>setSearch({...search,q:e.target.value})}/><TextField select label="Business status" value={search.status} onChange={e=>setSearch({...search,status:e.target.value})}><MenuItem value="">All statuses</MenuItem><MenuItem value="Active">Active</MenuItem><MenuItem value="Suspended">Suspended</MenuItem></TextField><Button type="submit" variant="outlined">Search</Button><Button component={Link} href={route('platform.businesses.index')}>Clear</Button></Box>
        <OfficeTable headings={['Business','Accounts & contacts','Subscription','Access through','Status','Actions']} empty={!businesses.data.length}>
            {businesses.data.map(b=><TableRow key={b.id} hover><TableCell><Link href={route('platform.businesses.show',b.id)}>{b.name}</Link><Typography variant="caption" color="text.secondary" display="block">{b.slug}</Typography></TableCell><TableCell>{b.accounts?.map(u=><Box key={u.id}><Typography variant="body2">{u.name}</Typography><Typography variant="caption" color="text.secondary">{u.email}</Typography></Box>)}<Typography variant="caption" display="block">{b.memberships_count} account{b.memberships_count===1?'':'s'}</Typography></TableCell><TableCell><BillingStatus value={b.subscription.state}/><Typography variant="caption" display="block">{b.subscription.plan?.name || 'No plan'}</Typography></TableCell><TableCell>{billingDate(b.subscription.access_until)}</TableCell><TableCell><Chip size="small" label={b.status} color={b.status==='Active'?'success':'warning'}/></TableCell><TableCell><Button component={Link} href={route('platform.businesses.show',b.id)} size="small">View business</Button></TableCell></TableRow>)}
        </OfficeTable><BillingPager page={businesses}/>
        <OfficeDialog open={open} title="Create business" form={form} onClose={()=>setOpen(false)} submit="Create business" onSubmit={e=>{e.preventDefault();form.post(route('platform.businesses.store'),{onSuccess:()=>{setOpen(false);form.reset();}});}}>
            {[['name','Business name'],['slug','Business slug'],['owner_name','Owner name'],['owner_email','Owner email'],['owner_password','Password for new owner'],['currency_symbol','Currency symbol']].map(([key,label])=><TextField key={key} label={label} type={key==='owner_password'?'password':key==='owner_email'?'email':'text'} autoComplete={key==='owner_password'?'new-password':'off'} required value={form.data[key]} onChange={e=>form.setData(key,e.target.value)} error={!!form.errors[key]} helperText={form.errors[key] || (key==='owner_password'?'At least 12 characters. Share it privately.':undefined)}/>)}
        </OfficeDialog>
    </OfficePage>;
}
