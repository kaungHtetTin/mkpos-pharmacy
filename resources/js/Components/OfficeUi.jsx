import React from 'react';
import MainLayout from '@/Layouts/MainLayout';
import { Head } from '@/spa';
import { Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Stack, Table, TableContainer, TableHead, TableRow, TableCell, TableBody, Typography } from '@mui/material';
import { BillingErrors } from './BillingUi';
export function OfficePage({
  auth,
  title,
  description,
  actions,
  children
}) {
  return <MainLayout auth={auth} header={title}><Head title={title} /><Stack spacing={1.5}><Stack direction="row" justifyContent="space-between" alignItems="center" gap={1} flexWrap="wrap">{description && <Typography variant="body2" color="text.secondary" sx={{
          maxWidth: 800
        }}>{description}</Typography>}{actions}</Stack>{children}</Stack></MainLayout>;
}
export function OfficeTable({
  headings,
  empty,
  children
}) {
  return <TableContainer><Table size="small"><TableHead><TableRow>{headings.map(x => <TableCell key={x}>{x}</TableCell>)}</TableRow></TableHead><TableBody>{empty ? <TableRow><TableCell colSpan={headings.length}>No records found.</TableCell></TableRow> : children}</TableBody></Table></TableContainer>;
}
export function OfficeDialog({
  open,
  title,
  form,
  onClose,
  onSubmit,
  submit = 'Save',
  children
}) {
  return <Dialog open={open} onClose={() => !form.processing && onClose()} fullWidth maxWidth="sm" sx={{
    '& .MuiDialog-paper': {
      maxWidth: 620,
      width: 'calc(100% - 32px)',
      maxHeight: 'calc(100dvh - 32px)'
    }
  }}><Box component="form" onSubmit={onSubmit} sx={{
      display: 'flex',
      flexDirection: 'column',
      maxHeight: 'min(90dvh,800px)',
      minHeight: 0
    }}><DialogTitle>{title}</DialogTitle><DialogContent dividers sx={{
        overflowY: 'auto'
      }}><Stack spacing={1.5} sx={{
          pt: .5
        }}><BillingErrors errors={form.errors} />{children}</Stack></DialogContent><DialogActions><Button disabled={form.processing} onClick={onClose}>Cancel</Button><Button variant="contained" type="submit" disabled={form.processing}>{submit}</Button></DialogActions></Box></Dialog>;
}
export const officeFiltersSx = {
  display: 'flex',
  gap: 1,
  flexWrap: 'wrap',
  alignItems: 'center',
  '& .MuiTextField-root': {
    width: {
      xs: '100%',
      sm: 210
    }
  },
  '& .MuiButton-root': {
    flexShrink: 0
  }
};
