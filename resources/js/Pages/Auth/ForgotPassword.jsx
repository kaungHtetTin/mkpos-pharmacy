import React from 'react';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout';
import {Head,Link,useForm} from '@/spa';
import {Alert,Button,Stack,TextField,Typography} from '@mui/material';
export default function ForgotPassword({status,mail_available=true}){
 const form=useForm({email:''});return <AuthSplitLayout compact title="Forgot your password?" subtitle="Enter your account email to request a secure reset link." topBarContent={<Button component={Link} href={route('login')} size="small">Sign in</Button>}><Head title="Forgot password"/><Stack component="form" spacing={1.5} onSubmit={e=>{e.preventDefault();form.post(route('password.email'));}}>
 {!mail_available&&<Alert severity="warning">Password reset email is not configured yet. Contact the platform office for help.</Alert>}{status&&<Alert severity="success">{status}</Alert>}
 <TextField required fullWidth size="small" type="email" label="Account email" autoFocus autoComplete="username" value={form.data.email} onChange={e=>form.setData('email',e.target.value)} error={Boolean(form.errors.email)} helperText={form.errors.email} inputProps={{maxLength:255}}/>
 <Button type="submit" variant="contained" disabled={form.processing||!mail_available} sx={{minHeight:38}}>{form.processing?'Requesting link…':'Send reset link'}</Button><Typography variant="caption" color="text.secondary">The link expires in one hour and can be used once. You do not need to select a business.</Typography><Button component={Link} href={route('login')} size="small">Back to sign in</Button></Stack></AuthSplitLayout>;
}
