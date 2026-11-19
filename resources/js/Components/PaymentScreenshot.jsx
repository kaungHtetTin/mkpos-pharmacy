import React, { useEffect, useState } from 'react';
import { Alert, Box, Button, Stack, Typography } from '@mui/material';

export default function PaymentScreenshot({paymentId}) {
    const [preview,setPreview]=useState(''),[status,setStatus]=useState('loading'),[attempt,setAttempt]=useState(0);
    useEffect(()=>{
        const controller=new AbortController();let objectUrl='';let active=true;
        setPreview('');setStatus('loading');
        (async()=>{
            try {
                const response=await fetch(route('platform.billing.receipt',paymentId),{credentials:'same-origin',signal:controller.signal});
                if(!response.ok)throw new Error('Screenshot unavailable');
                const blob=await response.blob();if(!active)return;
                if(!['image/jpeg','image/png','image/webp'].includes(blob.type)){setStatus('document');return;}
                objectUrl=URL.createObjectURL(blob);setPreview(objectUrl);setStatus('ready');
            }catch(error){if(active&&error.name!=='AbortError')setStatus('error');}
        })();
        return()=>{active=false;controller.abort();if(objectUrl)URL.revokeObjectURL(objectUrl);};
    },[paymentId,attempt]);
    return <Stack spacing={.75}>
        <Typography variant="subtitle2">Payment screenshot</Typography>
        {status==='loading'&&<Typography role="status" variant="body2" color="text.secondary">Loading screenshot…</Typography>}
        {status==='error'&&<Alert severity="warning" action={<Button size="small" onClick={()=>setAttempt(x=>x+1)}>Retry</Button>}>Unable to load the screenshot.</Alert>}
        {status==='document'&&<Alert severity="info">This receipt is a document. Use Download receipt to open it.</Alert>}
        {preview&&<><Box component="img" src={preview} alt="Uploaded payment screenshot" onError={()=>{setPreview('');setStatus('error');}} sx={{display:'block',width:'100%',maxHeight:{xs:'38dvh',sm:400},objectFit:'contain',border:1,borderColor:'divider',borderRadius:1,bgcolor:'background.default'}}/><Button component="a" href={preview} target="_blank" rel="noreferrer" size="small" sx={{alignSelf:'flex-start'}}>Open full-size screenshot</Button></>}
    </Stack>;
}
