import React, { useEffect, useState } from 'react';
import { Alert, Box, Button, LinearProgress, Skeleton, Stack, Typography } from '@mui/material';
import PageContainer from './PageContainer';

// Short requests should finish without a flash of loading UI.
function useDelayedLoading(active, delay = 180) {
    const [visible, setVisible] = useState(false);
    useEffect(() => {
        if (!active) { setVisible(false); return; }
        const timer = window.setTimeout(() => setVisible(true), delay);
        return () => window.clearTimeout(timer);
    }, [active, delay]);
    return active && visible;
}

export function PageContentSkeleton() {
    const visible = useDelayedLoading(true);
    return <PageContainer role="status" aria-live="polite" aria-label="Loading page content" sx={{ minHeight: 320 }}>
        {visible && <Stack spacing={2} aria-hidden="true">
            <Stack spacing={0.75}>
                <Skeleton variant="rounded" width={170} height={20} />
                <Skeleton variant="rounded" width="min(65%, 300px)" height={10} />
            </Stack>
            <Box sx={{ display: 'grid', gridTemplateColumns: { xs: 'repeat(2, minmax(0, 1fr))', sm: 'repeat(4, minmax(0, 1fr))' }, gap: 1.25, maxWidth: 1000 }}>
                {[0, 1, 2, 3].map((item) => <Stack key={item} spacing={1} sx={{ py: 1, px: 1.25, borderBottom: '1px solid', borderColor: 'divider' }}>
                    <Skeleton variant="rounded" width="58%" height={9} />
                    <Skeleton variant="rounded" width={48} height={18} />
                </Stack>)}
            </Box>
            <Box sx={{ border: '1px solid', borderColor: 'divider', borderRadius: '6px', overflow: 'hidden' }}>
                {[0, 1, 2, 3, 4, 5].map((row) => <Box key={row} sx={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr 0.7fr', alignItems: 'center', gap: 2, px: 1.5, height: { xs: 40, md: 34 }, borderBottom: row < 5 ? '1px solid' : 0, borderColor: 'divider', bgcolor: row === 0 ? 'background.paper' : 'transparent' }}>
                    {[0, 1, 2, 3].map((column) => <Skeleton key={column} variant="rounded" width={column === 0 ? '62%' : '54%'} height={row === 0 ? 8 : 10} />)}
                </Box>)}
            </Box>
        </Stack>}
    </PageContainer>;
}

export function PageLoadingState() {
    const visible = useDelayedLoading(true);
    return <Box role="status" aria-live="polite" aria-label="Loading page" sx={{ minHeight: '100dvh', display: 'grid', placeItems: 'center', p: 3 }}>
        {visible && <Stack spacing={1.25} sx={{ width: 160, textAlign: 'center' }}>
            <Typography variant="caption" color="text.secondary">Loading page…</Typography>
            <LinearProgress aria-label="Loading page" />
        </Stack>}
    </Box>;
}

export function ContentTransition({ loading, error, children }) {
    const visible = useDelayedLoading(loading);
    return <Box aria-busy={loading} sx={{ position: 'relative', minHeight: 240 }}>
        {visible && <Box role="status" aria-live="polite" aria-label="Loading latest data" sx={{ position: 'sticky', top: 48, height: 0, zIndex: 5, pointerEvents: 'none' }}>
            <LinearProgress aria-label="Loading latest data" />
        </Box>}
        {error && <Alert severity="error" sx={{ mb: 1.5 }}>{error}</Alert>}
        {children}
    </Box>;
}

export function PageLoadError({ message }) {
    return <PageContainer><Alert severity="error" action={<Button color="inherit" size="small" onClick={() => window.location.reload()}>Retry</Button>}>{message}</Alert></PageContainer>;
}
