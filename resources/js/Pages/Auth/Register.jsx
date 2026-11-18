import React from 'react';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout';
import {Head,Link,useForm} from '@/spa';
import {Alert,Button,MenuItem,Stack,TextField,Typography} from '@mui/material';

export default function Register({trial_days=14}){
    const form=useForm({business_name:'',name:'',email:'',password:'',password_confirmation:'',currency_symbol:'$'});
    const submit=e=>{e.preventDefault();form.post(route('register'),{onFinish:()=>form.reset('password','password_confirmation')});};
    const field=(name,label,extra={})=><TextField key={name} required fullWidth size="small" label={label} name={name} value={form.data[name]} onChange={e=>form.setData(name,e.target.value)} error={Boolean(form.errors[name])} helperText={form.errors[name]} {...extra}/>;
    return <AuthSplitLayout compact title="Create your business." subtitle={`Start with a ${trial_days}-day trial. No payment is required to get started.`} topBarContent={<Button component={Link} href={route('login')} size="small">Sign in</Button>}><Head title="Create business account"/>
        <Stack component="form" spacing={1.25} onSubmit={submit} sx={{'& .MuiOutlinedInput-root':{minHeight:{xs:40,sm:34}},'& .MuiFormHelperText-root':{mx:0,mt:.5}}}>
            {field('business_name','Business name',{autoComplete:'organization',autoFocus:true,inputProps:{maxLength:150}})}
            {field('name','Your name',{autoComplete:'name',inputProps:{maxLength:150}})}
            {field('email','Owner email',{type:'email',autoComplete:'username',inputProps:{maxLength:255}})}
            {field('password','Password',{type:'password',autoComplete:'new-password',helperText:form.errors.password||'At least 12 characters.',inputProps:{minLength:12,maxLength:72}})}
            {field('password_confirmation','Confirm password',{type:'password',autoComplete:'new-password',inputProps:{minLength:12,maxLength:72}})}
            <TextField select required size="small" label="Business currency" value={form.data.currency_symbol} onChange={e=>form.setData('currency_symbol',e.target.value)} error={Boolean(form.errors.currency_symbol)} helperText={form.errors.currency_symbol}>{[['$','USD — US dollar'],['Ks','MMK — Myanmar kyat'],['฿','THB — Thai baht'],['€','EUR — Euro'],['£','GBP — Pound sterling']].map(([value,label])=><MenuItem key={value} value={value}>{label}</MenuItem>)}</TextField>
            <Typography variant="caption" color="text.secondary">One owner account belongs to one business. Add more branches inside your workspace. Subscription payments are reviewed by the platform office.</Typography>
            {form.errors.registration&&<Alert severity="error">{form.errors.registration}</Alert>}
            <Button type="submit" variant="contained" disabled={form.processing} sx={{minHeight:38}}>{form.processing?'Creating your business…':'Create business account'}</Button>
            <Typography variant="caption" textAlign="center">Already have an account? <Link href={route('login')}>Sign in</Link></Typography>
        </Stack>
    </AuthSplitLayout>;
}
