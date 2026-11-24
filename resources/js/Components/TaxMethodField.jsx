import React, { useId } from 'react';
import { FormControl, FormHelperText, InputLabel, ListItemText, MenuItem, Select } from '@mui/material';

const methods = [
    {
        value: 'Exclusive',
        description: 'Tax is added on top of the selling price.',
        example: 'Example at 5% tax: price 1,000 → customer pays 1,050.',
    },
    {
        value: 'Inclusive',
        description: 'Tax is already included in the selling price.',
        example: 'Example at 5% tax: price 1,000 → customer pays 1,000.',
    },
];

export default function TaxMethodField({ value, onChange, error }) {
    const id = useId();
    const selected = methods.find((method) => method.value === value);
    return <FormControl fullWidth size="small" required error={Boolean(error)}>
        <InputLabel id={`${id}-label`}>Tax Method</InputLabel>
        <Select value={value} label="Tax Method" labelId={`${id}-label`} aria-describedby={`${id}-help`} onChange={(event) => onChange(event.target.value)} renderValue={(method) => method}>
            {methods.map((method) => <MenuItem key={method.value} value={method.value} sx={{ whiteSpace: 'normal' }}>
                <ListItemText primary={method.value} secondary={method.description} />
            </MenuItem>)}
        </Select>
        <FormHelperText id={`${id}-help`} aria-live="polite">{error || (selected && `${selected.description} ${selected.example}`)}</FormHelperText>
    </FormControl>;
}
