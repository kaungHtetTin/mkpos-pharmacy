import React, { useState } from 'react';
import { Box, IconButton, Popover, Typography, useMediaQuery } from '@mui/material';
import MoreHorizIcon from '@mui/icons-material/MoreHoriz';

export const flattenActions = (children) => React.Children.toArray(children).flatMap((node) => React.isValidElement(node) && node.type === React.Fragment ? flattenActions(node.props.children) : [node]);

// Render the real action components, preserving their links, disabled states,
// asynchronous work and form callbacks instead of simulating button clicks.
export default function MobileActionOverflow({ children, force = false }) {
    const mobile = useMediaQuery((theme) => theme.breakpoints.down('md'));
    const [anchor, setAnchor] = useState(null);
    const actions = flattenActions(children);
    if (!actions.length) return null;
    if (!mobile || (!force && actions.length < 2)) return <>{children}</>;
    return <>
        <IconButton aria-label="More page actions" aria-haspopup="dialog" aria-expanded={Boolean(anchor)} onClick={(event) => setAnchor(event.currentTarget)}><MoreHorizIcon /></IconButton>
        <Popover open={Boolean(anchor)} anchorEl={anchor} onClose={() => setAnchor(null)} anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }} transformOrigin={{ vertical: 'top', horizontal: 'right' }} slotProps={{ paper: { sx: { mt: 0.75, p: 1, width: 256, maxWidth: 'calc(100vw - 24px)', borderRadius: 2 } } }}>
            <Box role="dialog" aria-label="More page actions" sx={{ display: 'flex', flexDirection: 'column', gap: 0.75, '&& .MuiButton-root': { width: '100%', minHeight: 44, justifyContent: 'flex-start', px: 1.5 }, '& .MuiIconButton-root': { minWidth: 44, minHeight: 44 } }}>
                <Typography variant="caption" color="text.secondary" sx={{ px: 1, py: 0.5 }}>Page actions</Typography>
                {actions}
            </Box>
        </Popover>
    </>;
}
