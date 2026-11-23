import React, { useEffect, useRef, useState } from 'react';
import { Alert, Box, Button, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, IconButton, Stack, Typography } from '@mui/material';
import { Close, VideocamOutlined } from '@mui/icons-material';

const formats = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'code_93', 'codabar', 'itf', 'qr_code'];
const cameraError = (error) => {
    if (['NotAllowedError', 'PermissionDeniedError', 'SecurityError'].includes(error?.name)) return 'Camera access was denied. Allow camera access in your browser settings, then try again, or use manual search.';
    if (['NotFoundError', 'DevicesNotFoundError'].includes(error?.name)) return 'No camera was found on this device. You can still enter or scan a barcode into the search field.';
    if (['NotReadableError', 'TrackStartError'].includes(error?.name)) return 'The camera is busy or could not start. Close other apps using it, then try again.';
    return 'The camera could not scan a barcode. Try again, or use manual search.';
};

export default function CameraBarcodeScanner({ open, onClose, onDetected, onManualSearch }) {
    const [video, setVideo] = useState(null);
    const [attempt, setAttempt] = useState(0);
    const [phase, setPhase] = useState('starting');
    const [error, setError] = useState('');
    const callbacks = useRef({ onClose, onDetected, onManualSearch });
    callbacks.current = { onClose, onDetected, onManualSearch };
    const stopRef = useRef(() => {});
    const close = () => { stopRef.current(); callbacks.current.onClose(); };

    useEffect(() => {
        if (!open || !video) return;
        let active = true, stream, controls, timer;
        const stop = () => {
            if (!active) return;
            active = false;
            window.clearTimeout(timer);
            try { controls?.stop(); } finally {
                stream?.getTracks().forEach((track) => { if (track.readyState !== 'ended') track.stop(); });
            }
            if (video.srcObject === stream) video.srcObject = null;
        };
        stopRef.current = stop;
        const fail = (message) => { if (!active) return; stop(); setPhase('error'); setError(message); };
        const detected = (value) => {
            if (!active || typeof value !== 'string') return;
            const barcode = value.trim();
            if (!barcode || barcode.length > 255 || /[\x00-\x1f\x7f]/.test(barcode)) return;
            stop();
            callbacks.current.onDetected(barcode);
        };
        const fallback = async () => {
            const { BrowserMultiFormatReader } = await import('@zxing/browser');
            if (!active) return;
            const reader = new BrowserMultiFormatReader(undefined, { delayBetweenScanAttempts: 150 });
            const started = await reader.decodeFromStream(stream, video, (result) => { if (result) detected(result.getText()); });
            controls = started;
            if (!active) started.stop();
        };
        const start = async () => {
            setPhase('starting');
            setError('');
            if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
                fail('Camera scanning needs HTTPS and a supported browser. You can still use manual search.');
                return;
            }
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } } });
                if (!active) { stream.getTracks().forEach((track) => track.stop()); return; }
                video.srcObject = stream;
                await video.play();
                if (!active) return;
                let detector;
                if (window.BarcodeDetector) {
                    try {
                        const supported = await window.BarcodeDetector.getSupportedFormats();
                        const available = formats.filter((format) => supported.includes(format));
                        if (available.includes('ean_13') && available.includes('code_128')) detector = new window.BarcodeDetector({ formats: available });
                    } catch { /* Browsers without a working native decoder use ZXing below. */ }
                }
                if (!active) return;
                setPhase('scanning');
                if (!detector) { await fallback(); return; }
                const scan = async () => {
                    if (!active) return;
                    try {
                        if (video.readyState >= 2 && video.videoWidth > 0) {
                            const results = await detector.detect(video);
                            if (!active) return;
                            if (results.length) detected(results[0].rawValue);
                        }
                    } catch {
                        try { await fallback(); } catch (decodeError) { fail(cameraError(decodeError)); }
                        return;
                    }
                    if (active) timer = window.setTimeout(scan, 150);
                };
                scan();
            } catch (startError) { fail(cameraError(startError)); }
        };
        const pause = () => { if (document.hidden) fail('Camera paused while this page was in the background. Tap Try again to resume.'); };
        const leave = () => stop();
        document.addEventListener('visibilitychange', pause);
        window.addEventListener('pagehide', leave);
        start();
        return () => {
            document.removeEventListener('visibilitychange', pause);
            window.removeEventListener('pagehide', leave);
            stop();
        };
    }, [open, video, attempt]);

    return <Dialog open={open} onClose={close} fullWidth maxWidth="sm" aria-labelledby="camera-barcode-title" sx={{ '& .MuiDialog-paper': { m: 1.5, width: 'calc(100% - 24px)', maxHeight: 'calc(100dvh - 24px)' }, '& .MuiButton-root': { minHeight: 44 } }}>
        <DialogTitle id="camera-barcode-title" sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 1 }}>Scan medicine barcode<IconButton aria-label="Close camera scanner" onClick={close} sx={{ minWidth: 44, minHeight: 44 }}><Close /></IconButton></DialogTitle>
        <DialogContent dividers sx={{ p: 1.5 }}>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>Point the back camera at the barcode. Keep the whole barcode inside the frame and hold steady.</Typography>
            <Box sx={{ position: 'relative', bgcolor: '#111', borderRadius: 1.5, overflow: 'hidden', aspectRatio: '4 / 3', maxHeight: '45dvh' }}>
                <Box component="video" ref={setVideo} autoPlay muted playsInline aria-label="Barcode camera preview" sx={{ display: 'block', width: '100%', height: '100%', objectFit: 'contain' }} />
                {phase === 'scanning' && <Box aria-hidden="true" sx={{ position: 'absolute', inset: '24% 8%', border: '2px solid rgba(255,255,255,.85)', borderRadius: 1, pointerEvents: 'none' }} />}
                {phase === 'starting' && <Stack role="status" alignItems="center" justifyContent="center" spacing={1} sx={{ position: 'absolute', inset: 0, color: '#fff' }}><CircularProgress color="inherit" size={28} /><Typography variant="body2">Starting camera…</Typography></Stack>}
                {phase === 'error' && <VideocamOutlined sx={{ position: 'absolute', top: 'calc(50% - 24px)', left: 'calc(50% - 24px)', fontSize: 48, color: '#aaa' }} />}
            </Box>
            {error ? <Alert severity="warning" sx={{ mt: 1.5 }}>{error}</Alert> : <Typography role="status" variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1.5 }}>{phase === 'scanning' ? 'Scanning… A recognized barcode adds the medicine once and closes the camera.' : 'Allow camera access when your browser asks. Camera images stay on this device.'}</Typography>}
        </DialogContent>
        <DialogActions sx={{ px: 1.5, flexWrap: 'wrap' }}><Button onClick={() => { close(); callbacks.current.onManualSearch(); }}>Use manual search</Button>{phase === 'error' && <Button variant="contained" onClick={() => setAttempt((value) => value + 1)}>Try again</Button>}</DialogActions>
    </Dialog>;
}
