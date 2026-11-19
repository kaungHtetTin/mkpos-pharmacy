import ViewportPanel from '@/Components/ViewportPanel';
import React from 'react';
import { automaticPrice } from '@/Utils/automaticPricing';
import MainLayout from '@/Layouts/MainLayout';
import CategorySelect from '@/Components/CategorySelect';
import { AppBarBack } from '@/Components/AppBarPortal';
import { Head, useForm, router, usePage } from '@/spa';
import { compressImage } from '@/Utils/compressImage';
import {
    Alert,
    Box,
    Paper,
    Typography,
    Button,
    IconButton,
    Stack,
    TextField,
    MenuItem,
    FormControl,
    InputLabel,
    Select,
    Avatar,
    Checkbox,
    ListItemText,
    FormHelperText,
    Radio,
    Tooltip,
    Table,
    TableBody,
    TableCell,
    TableContainer,
    TableHead,
    TableRow,
} from '@mui/material';
import {
    Save as SaveIcon,
    PhotoCamera as PhotoCameraIcon,
    QrCode as BarcodeIcon,
    AddCircleOutline as AddUnitIcon,
    Medication as ProductIcon,
    DeleteOutline as DeleteIcon,
} from '@mui/icons-material';

export default function ProductEdit({ auth, product = null, categories, taxes, units, default_tax_id: defaultTaxId = '', initial_barcode: initialBarcode = '', pricing_rules = [] }) {
    const isCreating = !product;
    const pricingRules = Object.fromEntries(pricing_rules.map((rule) => [rule.code, rule]));
    const medicine = product || {
        category_id: '',
        tax_id: defaultTaxId,
        taxes: [],
        generic_name: '',
        brand_name: '',
        name: '',
        manufacturer: '',
        strength: '',
        barcode: initialBarcode,
        description: '',
        min_stock_level: 10,
        discount_percentage: 0,
        tax_method: 'Exclusive',
        status: 'Active',
        image_path: null,
        product_units: [{ unit_id: '', unit_name: '', unit_short_name: '', conversion_factor: 1, selling_price: 0, wholesale_price: 0, is_base_unit: true, is_default_selling_unit: true }],
    };
    const { ziggy = {} } = usePage().props;
    const appBase = ziggy?.base || '';
    const withBase = (path) => `${appBase}${path.startsWith('/') ? path : `/${path}`}`.replace(/\/{2,}/g, '/');
    const storageUrl = (path) => withBase(`/storage/${String(path || '').replace(/^\/+/, '')}`);

    const { data, setData, post, errors, processing, setError, clearErrors } = useForm({
        pricing_base_cost: medicine.pricing_base_cost ?? '',
        pricing_version: medicine.pricing_version ?? 1,
        category_id: medicine.category_id,
        tax_id: (medicine.taxes?.[0]?.id) || medicine.tax_id || '',
        tax_ids: (medicine.taxes || []).map(t => t.id).length > 0
            ? (medicine.taxes || []).map(t => t.id)
            : (medicine.tax_id ? [medicine.tax_id] : []),
        generic_name: medicine.generic_name || '',
        brand_name: medicine.brand_name || medicine.name || '',
        manufacturer: medicine.manufacturer || '',
        strength: medicine.strength || '',
        barcode: medicine.barcode || '',
        description: medicine.description || '',
        min_stock_level: medicine.min_stock_level,
        discount_percentage: medicine.discount_percentage || 0,
        tax_method: medicine.tax_method,
        status: medicine.status,
        image: null,
        product_units: [...medicine.product_units]
            .sort((a, b) => Number(Boolean(b.is_base_unit)) - Number(Boolean(a.is_base_unit)))
            .map((pu, index) => ({
            unit_id: pu.unit_id,
            unit_name: pu.unit?.name || pu.unit_name || '',
            unit_short_name: pu.unit?.short_name || pu.unit_short_name || '',
            conversion_factor: pu.conversion_factor,
            selling_price: pu.selling_price,
            selling_price_mode: pu.selling_price_mode ?? (isCreating ? pricingRules.selling_price?.pricing_mode : 'manual') ?? 'manual',
            wholesale_price_mode: pu.wholesale_price_mode ?? (isCreating ? pricingRules.wholesale_price?.pricing_mode : 'manual') ?? 'manual',
            wholesale_price: pu.wholesale_price ?? pu.selling_price,
            is_base_unit: index === 0,
            is_default_selling_unit: pu.is_default_selling_unit === undefined
                ? index === 0
                : Boolean(pu.is_default_selling_unit),
        })),
    });

    const unitsTableRef = React.useRef(null);
    const focusUnitRef = React.useRef(null);
    React.useEffect(() => {
        if (focusUnitRef.current === null) return;
        const input = unitsTableRef.current?.querySelector(`[data-unit-name="${focusUnitRef.current}"]`);
        input?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        input?.focus({ preventScroll: true });
        focusUnitRef.current = null;
    }, [data.product_units.length]);

    const handleAddUnit = () => {
        focusUnitRef.current = data.product_units.length;
        setData('product_units', [...data.product_units, { unit_id: '', unit_name: '', unit_short_name: '', conversion_factor: 1, selling_price: 0, wholesale_price: 0, selling_price_mode: pricingRules.selling_price?.pricing_mode || 'manual', wholesale_price_mode: pricingRules.wholesale_price?.pricing_mode || 'manual', is_base_unit: false, is_default_selling_unit: false }]);
    };

    const handleRemoveUnit = (index) => {
        if (data.product_units[index].is_base_unit) return;
        const removedDefault = data.product_units[index].is_default_selling_unit;
        const newUnits = data.product_units.filter((_, i) => i !== index);
        if (removedDefault && newUnits.length > 0) newUnits[0].is_default_selling_unit = true;
        focusUnitRef.current = Math.min(index, newUnits.length - 1);
        setData('product_units', newUnits);
    };

    const handleUnitChange = (index, field, value) => {
        if (index === 0 && field === 'conversion_factor') return;
        const newUnits = [...data.product_units];
        newUnits[index] = { ...newUnits[index], [field]: value };
        if (field === 'unit_name' || field === 'unit_short_name') newUnits[index].unit_id = '';
        setData('product_units', newUnits);
    };

    const handleSetDefaultSellingUnit = (index) => {
        const newUnits = data.product_units.map((unit, i) => ({ ...unit, is_default_selling_unit: i === index }));
        setData('product_units', newUnits);
    };

    const pricingCost = medicine.pricing_has_purchase ? medicine.pricing_buying_cost : data.pricing_base_cost;
    const unitInputSx = {
        // The cell supplies the border; prevent the theme's input ring from doubling it.
        '&& .MuiInputBase-root': {
            height: 38, minHeight: 38, borderRadius: 0, fontSize: 12,
            bgcolor: 'transparent',
            '&.Mui-disabled': { bgcolor: 'action.hover' },
            '&.Mui-focused': { bgcolor: 'background.paper', boxShadow: (theme) => `inset 0 0 0 2px ${theme.palette.primary.main}` },
            '&.Mui-error': { boxShadow: (theme) => `inset 0 0 0 1px ${theme.palette.error.main}` },
            '&.Mui-error.Mui-focused': { boxShadow: (theme) => `inset 0 0 0 2px ${theme.palette.error.main}` },
        },
        '&& .MuiInputBase-input': { px: 1, py: 1, fontWeight: 400, minWidth: 0 },
        '&& .MuiSelect-select': { pr: '24px', fontSize: 11 },
        '& .MuiSelect-icon': { right: 3, fontSize: 18 },
        '&& .MuiOutlinedInput-notchedOutline': { border: '0 !important', boxShadow: 'none !important' },
        '& input[type=number]': { textAlign: 'right', fontVariantNumeric: 'tabular-nums', MozAppearance: 'textfield' },
        '& input[type=number]::-webkit-outer-spin-button, & input[type=number]::-webkit-inner-spin-button': { WebkitAppearance: 'none', m: 0 },
    };
    const unitErrorLabels = {
        unit_id: 'Unit', unit_name: 'Unit name', unit_short_name: 'Short name',
        conversion_factor: 'Base quantity', selling_price_mode: 'Selling mode',
        selling_price: 'Selling price', wholesale_price_mode: 'Wholesale mode',
        wholesale_price: 'Wholesale price', is_default_selling_unit: 'Default unit',
    };
    const fieldProps = (index, field, label) => ({
        error: Boolean(errors[`product_units.${index}.${field}`]),
        inputProps: {
            'aria-label': `${label}, unit ${index + 1}`,
            'aria-describedby': errors[`product_units.${index}.${field}`] ? `unit-errors-${index}` : undefined,
        },
    });
    const generateBarcode = () => {
        const timestamp = Date.now().toString().slice(-6);
        const random = Math.floor(Math.random() * 1000000).toString().padStart(6, '0');
        setData('barcode', `200${timestamp}${random}`);
    };

    const submit = (e) => {
        e.preventDefault();
        post(isCreating ? route('products.store') : route('products.update', medicine.id), {
            forceFormData: true,
            onSuccess: () => router.visit(route('products.index')),
            data,
        });
    };

    return (
        <MainLayout auth={auth} header={isCreating ? 'Add New Medicine' : 'Edit Medicine'}>
            <Head title={isCreating ? 'Add New Medicine' : `Edit ${medicine.name}`} />
            <AppBarBack href={route('products.index')} label="Back to Medicines" />

            <ViewportPanel sx={{ maxWidth: 1440, mx: 'auto' }}>
                <Paper
                    sx={{
                        height: '100%', boxSizing: 'border-box',
                        p: { xs: 1.5, md: 1.25 },
                        '& .MuiInputBase-root:not(.MuiInputBase-multiline)': { minHeight: 36, fontSize: 12.5 },
                        '& .MuiInputLabel-root': { fontSize: 12 },
                        '& .MuiFormHelperText-root': { mt: 0.25, mx: 0.25, fontSize: 10, lineHeight: 1.15 },
                        '& .MuiButton-root': { minHeight: 32, fontSize: 11.5 },
                    }}
                >
                    <Box component="form" onSubmit={submit} sx={{ height: '100%', minHeight: 0, display: 'flex', flexDirection: 'column' }}>
                        <Box sx={{ flex: 1, minHeight: 0, overflow: 'auto', p: 0.5 }}>
                        {errors.pricing && <Alert severity="error" sx={{ mb: 1, py: 0 }}>{errors.pricing}</Alert>}
                        <Stack spacing={1.5}>
                            <Box>
                                <Typography variant="subtitle2" sx={{ fontSize: 12, fontWeight: 'bold', color: 'primary.main', mb: 0.75 }}>
                                    MEDICINE IDENTITY
                                </Typography>
                                <Box
                                    sx={{
                                        display: 'grid',
                                        gridTemplateColumns: {
                                            xs: '1fr',
                                            md: '160px repeat(4, minmax(0, 1fr))',
                                        },
                                        gap: 1,
                                        alignItems: 'start',
                                    }}
                                >
                                    <Stack
                                        alignItems="center"
                                        spacing={0.75}
                                        sx={{
                                            gridColumn: { xs: '1', md: '1 / 2' },
                                            gridRow: { md: '1 / span 3' },
                                            height: '100%',
                                            p: 1,
                                            border: '1px solid',
                                            borderColor: 'divider',
                                            borderRadius: 1,
                                            justifyContent: 'center',
                                        }}
                                    >
                                        <Avatar
                                            src={data.image ? URL.createObjectURL(data.image) : (medicine.image_path ? storageUrl(medicine.image_path) : null)}
                                            variant="rounded"
                                            sx={{ width: 92, height: 92, border: '1px solid', borderColor: 'divider' }}
                                        >
                                            <ProductIcon sx={{ fontSize: 42 }} />
                                        </Avatar>
                                        <Button variant="outlined" component="label" size="small" fullWidth startIcon={<PhotoCameraIcon />}>
                                            {isCreating ? 'Upload Photo' : 'Change Photo'}
                                            <input type="file" hidden accept="image/*" onChange={async (e) => {
                                                try { setData('image', await compressImage(e.target.files[0])); clearErrors('image'); }
                                                catch (error) { setError('image', error.message); }
                                            }} />
                                        </Button>
                                        {errors.image && <Typography variant="caption" color="error">{errors.image}</Typography>}
                                    </Stack>

                                    <TextField
                                        fullWidth
                                        size="small"
                                        label="Brand Name"
                                        value={data.brand_name}
                                        onChange={e => setData('brand_name', e.target.value)}
                                        error={!!errors.brand_name}
                                        helperText={errors.brand_name}
                                        required
                                        sx={{ gridColumn: { xs: '1', md: '2 / span 2' } }}
                                    />
                                    <CategorySelect
                                        categories={categories}
                                        value={data.category_id}
                                        onChange={(categoryId) => setData('category_id', categoryId)}
                                        error={errors.category_id}
                                        sx={{ gridColumn: { xs: '1', md: '4 / span 2' } }}
                                    />
                                    <TextField
                                        fullWidth
                                        size="small"
                                        label="Chemical Name"
                                        value={data.generic_name}
                                        onChange={e => setData('generic_name', e.target.value)}
                                        error={!!errors.generic_name}
                                        helperText={errors.generic_name}
                                        required
                                        sx={{ gridColumn: { xs: '1', md: '2 / span 2' } }}
                                    />
                                    <TextField
                                        fullWidth
                                        size="small"
                                        label="Manufacturer"
                                        value={data.manufacturer}
                                        onChange={e => setData('manufacturer', e.target.value)}
                                        error={!!errors.manufacturer}
                                        helperText={errors.manufacturer}
                                        sx={{ gridColumn: { xs: '1', md: '4 / span 1' } }}
                                    />
                                    <TextField
                                        fullWidth
                                        size="small"
                                        label="Strength"
                                        value={data.strength}
                                        onChange={e => setData('strength', e.target.value)}
                                        error={!!errors.strength}
                                        helperText={errors.strength}
                                        sx={{ gridColumn: { xs: '1', md: '5 / span 1' } }}
                                    />
                                    <TextField
                                        fullWidth
                                        size="small"
                                        label="Barcode"
                                        value={data.barcode}
                                        onChange={e => setData('barcode', e.target.value)}
                                        error={!!errors.barcode}
                                        helperText={errors.barcode}
                                        InputProps={{ endAdornment: <IconButton size="small" onClick={generateBarcode}><BarcodeIcon /></IconButton> }}
                                        sx={{ gridColumn: { xs: '1', md: '2 / span 4' } }}
                                    />
                                </Box>
                            </Box>

                            <Box>
                                <Typography variant="subtitle2" sx={{ fontSize: 12, fontWeight: 'bold', color: 'primary.main', mb: 0.75 }}>
                                    TAX, STOCK & STATUS
                                </Typography>
                                <Box
                                    sx={{
                                        display: 'grid',
                                        gridTemplateColumns: {
                                            xs: '1fr',
                                            sm: 'repeat(2, minmax(0, 1fr))',
                                            md: 'repeat(4, minmax(0, 1fr))',
                                            lg: 'repeat(5, minmax(0, 1fr))',
                                        },
                                        gap: 1,
                                    }}
                                >
                                    <FormControl fullWidth size="small" error={!!errors.tax_ids}>
                                        <InputLabel>Applied Taxes</InputLabel>
                                        <Select
                                            multiple
                                            value={data.tax_ids}
                                            label="Applied Taxes"
                                            onChange={(e) => {
                                                const selectedIds = Array.isArray(e.target.value) ? e.target.value : [];
                                                setData(prev => ({ ...prev, tax_ids: selectedIds, tax_id: selectedIds[0] || '' }));
                                            }}
                                            renderValue={(selected) => {
                                                const names = taxes.filter(t => selected.includes(t.id)).map(t => t.name);
                                                return names.length ? names.join(', ') : 'None';
                                            }}
                                        >
                                            {taxes.map(tax => (
                                                <MenuItem key={tax.id} value={tax.id}>
                                                    <Checkbox size="small" checked={data.tax_ids.includes(tax.id)} />
                                                    <ListItemText primary={`${tax.name} (${tax.rate}%)`} />
                                                </MenuItem>
                                            ))}
                                        </Select>
                                        {errors.tax_ids && <FormHelperText>{errors.tax_ids}</FormHelperText>}
                                    </FormControl>
                                    <FormControl fullWidth size="small" required>
                                        <InputLabel>Tax Method</InputLabel>
                                        <Select value={data.tax_method} label="Tax Method" onChange={e => setData('tax_method', e.target.value)}>
                                            <MenuItem value="Exclusive">Exclusive</MenuItem>
                                            <MenuItem value="Inclusive">Inclusive</MenuItem>
                                        </Select>
                                    </FormControl>
                                    <TextField
                                        fullWidth
                                        size="small"
                                        label="Min Stock Level"
                                        type="number"
                                        value={data.min_stock_level}
                                        onChange={e => setData('min_stock_level', e.target.value)}
                                        error={!!errors.min_stock_level}
                                        helperText={errors.min_stock_level}
                                    />
                                    <TextField
                                        fullWidth
                                        size="small"
                                        label="Product Discount %"
                                        type="number"
                                        value={data.discount_percentage}
                                        onChange={e => setData('discount_percentage', e.target.value)}
                                        error={!!errors.discount_percentage}
                                        helperText={errors.discount_percentage}
                                        inputProps={{ min: 0, max: 100, step: '0.01' }}
                                    />
                                    <FormControl fullWidth size="small" required>
                                        <InputLabel>Status</InputLabel>
                                        <Select value={data.status} label="Status" onChange={e => setData('status', e.target.value)}>
                                            <MenuItem value="Active">Active</MenuItem>
                                            <MenuItem value="Inactive">Inactive</MenuItem>
                                        </Select>
                                    </FormControl>
                                </Box>
                            </Box>

                            <Box>
                                <Typography variant="subtitle2" sx={{ fontSize: 12, fontWeight: 'bold', color: 'primary.main', mb: 0.75 }}>
                                    NOTES
                                </Typography>
                                <TextField
                                    fullWidth
                                    multiline
                                    minRows={2}
                                    maxRows={8}
                                    label="Description"
                                    value={data.description}
                                    onChange={(event) => setData('description', event.target.value)}
                                />
                            </Box>

                            <Box>
                                <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 0.75 }}>
                                    <Typography variant="subtitle2" sx={{ fontSize: 12, fontWeight: 'bold', color: 'primary.main' }}>
                                        UNITS & PRICING
                                    </Typography>
                                    <Button size="small" startIcon={<AddUnitIcon />} onClick={handleAddUnit}>Add Unit</Button>
                                </Stack>

                                <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} alignItems={{ md: 'center' }} sx={{ mb: 1.25 }}>
                                    <TextField size="small" type="number" label="Starting cost / base unit" value={data.pricing_base_cost} onChange={(event) => setData('pricing_base_cost', event.target.value)} error={!!errors.pricing_base_cost} helperText={errors.pricing_base_cost || 'Optional · used until your first purchase'} inputProps={{ min: 0, step: '0.000001' }} sx={{ maxWidth: { md: 280 } }} />
                                    <Box sx={{ minWidth: 0 }}>
                                        <Typography variant="caption" display="block" color="text.secondary">
                                            Pricing cost: <Box component="strong" sx={{ color: 'text.primary' }}>{pricingCost === '' || pricingCost == null ? 'Not available' : pricingCost}</Box> / base unit
                                        </Typography>
                                        <Typography variant="caption" color="text.secondary">Automatic prices follow Settings → Automatic Pricing. Purchase costs exclude free quantities.</Typography>
                                    </Box>
                                </Stack>
                                <Typography variant="caption" color="text.secondary" sx={{ display: { xs: 'block', md: 'none' }, mb: 0.5 }}>Scroll sideways to edit prices. Unit names stay visible.</Typography>
                                <TableContainer ref={unitsTableRef} sx={{ borderRadius: 1, scrollbarWidth: 'thin', maxWidth: '100%', minWidth: 0, contain: 'inline-size' }}>
                                    <Table size="small" aria-label="Units and pricing" sx={{
                                        tableLayout: 'fixed', minWidth: 800,
                                        '&& .MuiTableCell-root': { p: 0, height: 40, borderRight: '1px solid', borderBottom: '1px solid', borderColor: 'divider', verticalAlign: 'middle' },
                                        '&& .MuiTableCell-head': { px: 1, py: 1, height: 'auto', bgcolor: 'action.hover', color: 'text.secondary', fontSize: 11, fontWeight: 600, whiteSpace: 'nowrap' },
                                        '&& .MuiTableCell-root:last-child': { borderRight: 0 },
                                        '&& tbody .MuiTableCell-root:focus-within, && tbody .MuiTableCell-root:has(.Mui-error)': { boxShadow: 'none !important', bgcolor: 'transparent' },
                                        '&& tbody tr:hover': { bgcolor: 'transparent' },
                                        '&& tbody tr:focus-within > th': { bgcolor: 'action.hover' },
                                        '&&& .unit-name-cell': { position: 'sticky !important', left: 0, zIndex: 3, bgcolor: (theme) => `${theme.palette.background.paper} !important`, boxShadow: (theme) => `1px 0 0 ${theme.palette.divider}` },
                                    }}>
                                        <colgroup>
                                            <col style={{ width: '22%' }} /><col style={{ width: '9%' }} /><col style={{ width: '12%' }} />
                                            <col style={{ width: '22%' }} /><col style={{ width: '22%' }} /><col style={{ width: '8%' }} /><col style={{ width: '5%' }} />
                                        </colgroup>
                                        <TableHead>
                                            <TableRow>
                                                <TableCell className="unit-name-cell">Unit name</TableCell>
                                                <TableCell>Short name</TableCell>
                                                <TableCell align="right"><Tooltip title="Number of base units contained in this unit"><Box component="span" sx={{ cursor: 'help', borderBottom: '1px dotted' }}>Base qty.</Box></Tooltip></TableCell>
                                                <TableCell>Selling price</TableCell>
                                                <TableCell>Wholesale price</TableCell>
                                                <TableCell align="center">Default</TableCell>
                                                <TableCell><Box component="span" sx={{ position: 'absolute', width: 1, height: 1, overflow: 'hidden', clipPath: 'inset(50%)' }}>Actions</Box></TableCell>
                                            </TableRow>
                                        </TableHead>
                                        <TableBody>
                                            {data.product_units.map((unit, index) => {
                                                return (
                                                    <React.Fragment key={index}>
                                                        <TableRow>
                                                            <TableCell component="th" scope="row" className="unit-name-cell">
                                                                <Box sx={{ display: 'flex', alignItems: 'center' }}>
                                                                    <TextField placeholder={index === 0 ? 'e.g. Tablet' : 'e.g. Box'} value={unit.unit_name} onChange={(event) => handleUnitChange(index, 'unit_name', event.target.value)} {...fieldProps(index, 'unit_name', 'Unit name')} inputProps={{ ...fieldProps(index, 'unit_name', 'Unit name').inputProps, 'data-unit-name': index }} sx={unitInputSx} />
                                                                    {index === 0 && <Typography component="span" sx={{ px: 0.75, fontSize: 10, color: 'primary.main', whiteSpace: 'nowrap' }}>Base</Typography>}
                                                                </Box>
                                                            </TableCell>
                                                            <TableCell><TextField placeholder="e.g. tab" value={unit.unit_short_name} onChange={(event) => handleUnitChange(index, 'unit_short_name', event.target.value)} {...fieldProps(index, 'unit_short_name', 'Short name')} sx={unitInputSx} /></TableCell>
                                                            <TableCell>
                                                                <TextField type="number" value={index === 0 ? 1 : unit.conversion_factor} disabled={index === 0} onChange={(event) => handleUnitChange(index, 'conversion_factor', event.target.value)} {...fieldProps(index, 'conversion_factor', 'Base quantity')} inputProps={{ ...fieldProps(index, 'conversion_factor', 'Base quantity').inputProps, min: 1 }} sx={unitInputSx} />
                                                            </TableCell>
                                                            {['selling_price', 'wholesale_price'].map((field) => {
                                                                const mode = `${field}_mode`;
                                                                const automatic = unit[mode] === 'automatic';
                                                                const calculated = automaticPrice(pricingCost, pricingRules[field] || {}, String(unit.conversion_factor));
                                                                const label = field === 'selling_price' ? 'Selling' : 'Wholesale';
                                                                return (
                                                                    <TableCell key={field}>
                                                                        <Box sx={{ display: 'grid', gridTemplateColumns: '88px minmax(0, 1fr)' }}>
                                                                            <TextField select value={unit[mode]} onChange={(event) => handleUnitChange(index, mode, event.target.value)} {...fieldProps(index, mode, `${label} mode`)} sx={{ ...unitInputSx, borderRight: '1px solid', borderColor: 'divider' }}>
                                                                                <MenuItem value="manual">Manual</MenuItem>
                                                                                <MenuItem value="automatic" disabled={pricingRules[field]?.pricing_mode !== 'automatic'}>Auto</MenuItem>
                                                                            </TextField>
                                                                            <TextField type="number" value={automatic ? (calculated ?? unit[field]) : unit[field]} disabled={automatic} onChange={(event) => handleUnitChange(index, field, event.target.value)} {...fieldProps(index, field, `${label} price`)} inputProps={{ ...fieldProps(index, field, `${label} price`).inputProps, min: 0, step: '0.01' }} sx={unitInputSx} />
                                                                        </Box>
                                                                    </TableCell>
                                                                );
                                                            })}
                                                            <TableCell align="center">
                                                                <Radio checked={unit.is_default_selling_unit} onChange={() => handleSetDefaultSellingUnit(index)} name="default-selling-unit" value={index} size="small" inputProps={{ 'aria-label': `Use ${unit.unit_name || `unit ${index + 1}`} as default selling unit` }} sx={{ p: 0.75, '&.Mui-focusVisible': { outline: '2px solid', outlineColor: 'primary.main', outlineOffset: -2 } }} />
                                                            </TableCell>
                                                            <TableCell align="center">
                                                                {!unit.is_base_unit && <Tooltip title={`Remove ${unit.unit_name || 'unit'}`}><IconButton size="small" aria-label={`Remove unit ${index + 1}`} onClick={() => handleRemoveUnit(index)} sx={{ borderRadius: 0.5, color: 'text.secondary', '&:hover': { color: 'error.main', bgcolor: 'action.hover' } }}><DeleteIcon sx={{ fontSize: 18 }} /></IconButton></Tooltip>}
                                                            </TableCell>
                                                        </TableRow>

                                                    </React.Fragment>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                </TableContainer>
                                {data.product_units.map((unit, index) => {
                                    const rowErrors = Object.entries(errors).filter(([key]) => key.startsWith(`product_units.${index}.`));
                                    return rowErrors.length > 0 && <Box key={index} id={`unit-errors-${index}`} role="alert" sx={{ mt: 0.75 }}>
                                        {rowErrors.map(([key, message]) => {
                                            const label = unitErrorLabels[key.split('.').pop()] || 'Unit';
                                            return <Typography key={key} variant="caption" color="error" display="block">Unit {index + 1} · {String(message).replace(key, label.toLowerCase())}</Typography>;
                                        })}
                                    </Box>;
                                })}
                                <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" spacing={0.5} sx={{ mt: 0.75 }}>
                                    <Typography variant="caption" color="text.secondary">Base qty.: 1 box of 10 tablets = 10. The base unit always equals 1.</Typography>
                                    <Typography variant="caption" color="text.secondary">Default = preselected unit when selling.</Typography>
                                </Stack>
                                {errors.product_units && <Typography color="error" variant="caption">{errors.product_units}</Typography>}
                            </Box>
                        </Stack>

                        </Box>
                        <Stack direction="row" spacing={1} sx={{ pt: 1, pb: 0.5, flexShrink: 0, borderTop: '1px solid', borderColor: 'divider' }} justifyContent="flex-end">
                            <Button variant="outlined" onClick={() => router.visit(route('products.index'))}>Cancel</Button>
                            <Button type="submit" variant="contained" startIcon={<SaveIcon />} disabled={processing}>
                                {isCreating ? 'Create Medicine' : 'Save Changes'}
                            </Button>
                        </Stack>
                    </Box>
                </Paper>
            </ViewportPanel>
        </MainLayout>
    );
}
