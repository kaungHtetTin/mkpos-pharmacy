import React from 'react';
import MainLayout from '@/Layouts/MainLayout';
import { Head, router } from '@/spa';
import { Alert, Box, Button, Paper, Stack, Typography } from '@mui/material';
import SimpleIcon from '@/Components/SimpleIcon';

export default function SelectBusiness({ auth, businesses = [], selected_business_id }) {
    return <MainLayout auth={auth} header="Choose a business"><Head title="Choose a business" />
        <Stack spacing={2}>
            <Typography color="text.secondary">Choose the business you want to work in. Each business has its own branches, records and access roles.</Typography>
            {!businesses.length && <Alert severity="info">No active business is assigned to your account. Contact your platform office or business owner.</Alert>}
            <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(min(100%, 240px), 300px))', gap: 1.5 }}>
                {businesses.map(business => <Paper variant="outlined" key={business.id} sx={{ p: 2, borderRadius: '10px', minWidth: 0 }}><Stack spacing={1.5}><SimpleIcon name="store" size={24} /><Box><Typography sx={{ fontWeight: 700, overflowWrap: 'anywhere' }}>{business.name}</Typography><Typography variant="caption" color="text.secondary">{business.slug}</Typography></Box><Button variant={business.id === selected_business_id ? 'outlined' : 'contained'} onClick={() => router.post(route('businesses.select'), { business_id: business.id }, { preserveState: false })}>Open business</Button></Stack></Paper>)}
            </Box>
            {auth.user?.is_platform_admin && <Button sx={{ alignSelf: 'flex-start' }} onClick={() => router.visit(route('platform.businesses.index'))}>Platform office</Button>}
        </Stack>
    </MainLayout>;
}
