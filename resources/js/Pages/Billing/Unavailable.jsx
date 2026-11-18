import React from 'react';
import MainLayout from '@/Layouts/MainLayout';
import { Head } from '@/spa';
import { Alert, Stack, Typography } from '@mui/material';
export default function Unavailable({ auth, message }) {
    return <MainLayout auth={auth} header="Business access"><Head title="Business access" /><Stack spacing={1.5}><Alert severity="warning">{message}</Alert><Typography variant="body2">Contact your business owner. Your saved records remain available after the subscription is renewed.</Typography></Stack></MainLayout>;
}
