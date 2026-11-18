import React from 'react';
import MainLayout from '@/Layouts/MainLayout';
import {Head} from '@/spa';
import {Stack,Table,TableBody,TableCell,TableContainer,TableHead,TableRow} from '@mui/material';
import {BillingLinks,BillingPager,billingDate} from '@/Components/BillingUi';
export default function Audit({auth,entries}){return <MainLayout auth={auth} header="Platform audit"><Head title="Platform audit"/><Stack spacing={1.5}><BillingLinks/><TableContainer><Table size="small"><TableHead><TableRow>{['When','Action','Actor','Business','Details'].map(x=><TableCell key={x}>{x}</TableCell>)}</TableRow></TableHead><TableBody>{entries.data.map(e=><TableRow key={e.id}><TableCell>{billingDate(e.created_at)}</TableCell><TableCell>{e.action}</TableCell><TableCell>{e.actor?.name||'System'}</TableCell><TableCell>{e.business?.name||'Platform'}</TableCell><TableCell sx={{maxWidth:360,overflowWrap:'anywhere'}}>{JSON.stringify(e.properties)}</TableCell></TableRow>)}</TableBody></Table></TableContainer><BillingPager page={entries}/></Stack></MainLayout>;}
