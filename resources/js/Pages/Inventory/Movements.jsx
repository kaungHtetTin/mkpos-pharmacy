import React from 'react';
import { Box } from '@mui/material';
import MainLayout from '@/Layouts/MainLayout';
import { AppBarBack } from '@/Components/AppBarPortal';
import StockMovementHistory from '@/Components/StockMovementHistory';
import { Head } from '@/spa';

export default function Movements({ auth, ...props }) {
    return <MainLayout auth={auth} header="Stock movements">
        <Head title="Stock movement history" />
        <AppBarBack href={route('inventory.index')} label="Back to Stock Balance" />
        <Box sx={{ p: { xs: 1, md: 1.25 } }}>
            <StockMovementHistory {...props} />
        </Box>
    </MainLayout>;
}
