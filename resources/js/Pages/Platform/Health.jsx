import React from 'react';
import { router } from '@/spa';
import { Alert, Button, Stack, Typography } from '@mui/material';
import { OfficePage } from '@/Components/OfficeUi';
import StatCardGrid from '@/Components/StatCardGrid';
import { billingDate } from '@/Components/BillingUi';
export default function Health({
  auth,
  health: h
}) {
  return <OfficePage auth={auth} title="System health" description={`Application checks · ${billingDate(h.checked_at)} · ${h.timezone}`} actions={<Button variant="outlined" onClick={() => router.reload()}>Refresh checks</Button>}><StatCardGrid items={[{
      label: 'Database',
      value: h.database ? 'Connected' : 'Unavailable',
      tone: h.database ? 'success' : 'error'
    }, {
      label: 'Application storage',
      value: h.storage_writable ? 'Writable' : 'Needs attention'
    }, {
      label: 'Failed queue jobs',
      value: h.failed_jobs
    }, {
      label: 'Password reset email',
      value: h.mail_available ? 'Configured' : 'Not configured'
    }]} /><Alert severity={h.backup.configured && !h.backup.stale ? 'success' : 'warning'}>Encrypted backup: {h.backup.configured ? 'configured' : 'not configured'}. Last verified: {billingDate(h.backup.last_success_at)}. {h.backup.stale ? 'Backup needs attention.' : ''}</Alert><Stack spacing={.75}><Typography variant="body2">PHP {h.php} · Laravel {h.framework}</Typography><Typography variant="body2">Queue driver: {h.queue_driver}</Typography><Typography variant="caption" color="text.secondary">These checks cover the application. Host-level CPU, memory and certificate management remain in hosting control.</Typography></Stack></OfficePage>;
}
