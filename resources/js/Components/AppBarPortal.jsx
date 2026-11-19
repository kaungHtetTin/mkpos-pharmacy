import React, { useContext } from 'react';
import { createPortal } from 'react-dom';
import PersistentShellContext from '@/contexts/PersistentShellContext';
import { Box, IconButton, Tooltip, useMediaQuery } from '@mui/material';
import { ArrowBack as ArrowBackIcon } from '@mui/icons-material';
import MobileActionOverflow, { flattenActions } from './MobileActionOverflow';
import { Link } from '@/spa';

export default function AppBarPortal({ children }) {
    const shell = useContext(PersistentShellContext);
    return shell?.appBarTarget ? createPortal(children, shell.appBarTarget) : null;
}

export function AppBarActions({ children }) {
    const mobile = useMediaQuery((theme) => theme.breakpoints.down('md'));
    const actions = flattenActions(children);
    const primary = actions.filter((node) => node?.props?.variant === 'contained');
    const secondary = actions.filter((node) => !primary.includes(node));
    return (
        <AppBarPortal>
            <Box className="appbar-action-group" role="toolbar" aria-label="Page actions" sx={{
                display: 'flex',
                alignItems: 'center',
                gap: 0.75,
                ml: 'auto',
                flexWrap: { xs: 'wrap', md: 'nowrap' },
                minWidth: 0,
            }}>
                {mobile ? <>{primary}<MobileActionOverflow>{secondary}</MobileActionOverflow></> : children}
            </Box>
        </AppBarPortal>
    );
}

export function AppBarBack({ href, label = 'Back', onClick }) {
    const shell = useContext(PersistentShellContext);
    if (!shell?.appBarBackTarget) return null;

    return createPortal(
        <Tooltip title={label}>
            <IconButton
                component={href ? Link : 'button'}
                href={href}
                onClick={onClick}
                aria-label={label}
            >
                <ArrowBackIcon />
            </IconButton>
        </Tooltip>,
        shell.appBarBackTarget
    );
}
