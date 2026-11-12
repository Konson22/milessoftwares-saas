import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import {
    formatDate,
    SelectField,
    selectClass,
    TextField,
} from '@/components/payroll/fields';
import { formatMoney, percent, toInput } from '@/lib/payroll/money';
import {
    store as storeComponent,
    update as updateComponent,
} from '@/routes/payroll/settings/components';
import { store as storeRule } from '@/routes/payroll/settings/rules';

type Band = { up_to_minor: number | null; rate_bp: number };

type Component = {
    id: string;
    name: string;
    kind: 'earning' | 'deduction';
    calculation: 'fixed' | 'percent';
    taxable: boolean;
    active: boolean;
};

type Rule = {
    id: string;
    name: string;
    effectiveFrom: string;
    bands: Band[];
    employeeBp: number;
    employerBp: number;
    capMinor: number | null;
    deductible: boolean;
    by: string;
};

type Preset = {
    code: string;
    name: string;
    currency: string;
    source: string;
    income_tax_bands: Band[];
    social_security_employee_bp: number;
    social_security_employer_bp: number;
    social_security_cap_minor: number | null;
    social_security_deductible: boolean;
};

type Props = { components: Component[]; rules: Rule[]; presets: Preset[] };

function Check({
    name,
    label,
    defaultChecked,
    id,
}: {
    name: string;
    label: string;
    defaultChecked: boolean;
    id: string;
}) {
    return (
        <div className="flex items-center gap-2 self-end pb-2">
            <input
                id={id}
                name={name}
                type="checkbox"
                value="1"
                defaultChecked={defaultChecked}
                className="size-4"
            />
            <Label htmlFor={id}>{label}</Label>
        </div>
    );
}

export default function Settings({ components, rules, presets }: Props) {
    return (
        <>
            <Head title="Settings" />
            <PageHeader title="Settings" />
            <div className="grid gap-3">
                <ComponentsPanel components={components} />
                <RulesPanel rules={rules} presets={presets} />
            </div>
        </>
    );
}

function ComponentsPanel({ components }: { components: Component[] }) {
    return (
        <Panel>
            <PanelHeader title="Allowances and deductions" />
            {components.length > 0 && (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Kind</TableHead>
                            <TableHead>Worked out as</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {components.map((component) => (
                            <TableRow key={component.id}>
                                <TableCell colSpan={4} className="p-0">
                                    <Form
                                        {...updateComponent.form(component.id)}
                                        className="grid items-center gap-3 px-2 py-1.5 sm:grid-cols-[2fr_1fr_1fr_auto_auto_auto]"
                                    >
                                        <Input
                                            name="name"
                                            defaultValue={component.name}
                                            aria-label="Name"
                                        />
                                        <span className="text-sm">
                                            {component.kind === 'earning'
                                                ? 'Allowance'
                                                : 'Deduction'}
                                        </span>
                                        <span className="text-sm">
                                            {component.calculation === 'percent'
                                                ? '% of basic'
                                                : 'Fixed amount'}
                                        </span>
                                        {component.kind === 'earning' ? (
                                            <Check
                                                id={`taxable-${component.id}`}
                                                name="taxable"
                                                label="Taxed"
                                                defaultChecked={
                                                    component.taxable
                                                }
                                            />
                                        ) : (
                                            <span />
                                        )}
                                        <Check
                                            id={`active-${component.id}`}
                                            name="active"
                                            label="In use"
                                            defaultChecked={component.active}
                                        />
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            size="sm"
                                        >
                                            Save
                                        </Button>
                                    </Form>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
            <PanelBody className="border-t">
                <Form
                    {...storeComponent.form()}
                    resetOnSuccess
                    className="grid gap-3 sm:grid-cols-5"
                >
                    {({ errors, processing }) => (
                        <>
                            <TextField
                                name="name"
                                label="New"
                                required
                                error={errors.name}
                            />
                            <SelectField
                                name="kind"
                                label="Kind"
                                options={[
                                    { value: 'earning', label: 'Allowance' },
                                    { value: 'deduction', label: 'Deduction' },
                                ]}
                                error={errors.kind}
                            />
                            <SelectField
                                name="calculation"
                                label="Worked out as"
                                options={[
                                    { value: 'fixed', label: 'Fixed amount' },
                                    { value: 'percent', label: '% of basic' },
                                ]}
                                error={errors.calculation}
                            />
                            <Check
                                id="new-taxable"
                                name="taxable"
                                label="Taxed (allowances)"
                                defaultChecked
                            />
                            <div className="flex items-end">
                                <Button type="submit" disabled={processing}>
                                    Add
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}

type BandInput = { up_to: string; rate: string };

const EMPTY_BANDS: BandInput[] = [
    { up_to: '', rate: '0' },
    { up_to: '', rate: '' },
];

function RulesPanel({ rules, presets }: { rules: Rule[]; presets: Preset[] }) {
    const [bands, setBands] = useState<BandInput[]>(EMPTY_BANDS);
    const [preset, setPreset] = useState<Preset | null>(null);
    const [formKey, setFormKey] = useState(0);

    const load = (code: string) => {
        const chosen = presets.find((p) => p.code === code) ?? null;
        setPreset(chosen);
        setBands(
            chosen
                ? chosen.income_tax_bands.map((band) => ({
                      up_to: toInput(band.up_to_minor),
                      rate: String(band.rate_bp / 100),
                  }))
                : EMPTY_BANDS,
        );
        setFormKey((key) => key + 1);
    };

    return (
        <Panel>
            <PanelHeader title="Income tax and social security" />
            {rules.length === 0 ? (
                <p className="px-3 py-3 text-sm text-muted-foreground">
                    None yet: payroll is paid without tax or social security
                    until rules are saved.
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>From</TableHead>
                            <TableHead>Name</TableHead>
                            <TableHead>Income tax a month</TableHead>
                            <TableHead>Social security</TableHead>
                            <TableHead>Saved by</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rules.map((rule) => (
                            <TableRow key={rule.id}>
                                <TableCell className="tabular-nums">
                                    {formatDate(rule.effectiveFrom)}
                                </TableCell>
                                <TableCell>{rule.name}</TableCell>
                                <TableCell className="text-xs">
                                    {rule.bands.map((band, i) => (
                                        <div key={i}>
                                            {band.up_to_minor === null
                                                ? 'above'
                                                : `up to ${formatMoney(band.up_to_minor)}`}
                                            : {percent(band.rate_bp)}
                                        </div>
                                    ))}
                                </TableCell>
                                <TableCell className="text-xs">
                                    Employee {percent(rule.employeeBp)},
                                    employer {percent(rule.employerBp)}
                                    {rule.capMinor !== null &&
                                        `, on pay up to ${formatMoney(rule.capMinor)}`}
                                    {rule.deductible && (
                                        <div>Taken off taxable pay</div>
                                    )}
                                </TableCell>
                                <TableCell>{rule.by}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}

            <PanelBody className="grid gap-3 border-t">
                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="preset">Start from</Label>
                        <select
                            id="preset"
                            className={`${selectClass} w-auto`}
                            defaultValue=""
                            onChange={(event) => load(event.target.value)}
                        >
                            <option value="">Blank</option>
                            {presets.map((p) => (
                                <option key={p.code} value={p.code}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    {preset && (
                        <StatusBadge tone="warning">
                            Check every figure against the law before saving
                        </StatusBadge>
                    )}
                </div>
                {preset && (
                    <p className="text-xs text-muted-foreground">
                        {preset.source}
                    </p>
                )}

                <Form
                    key={formKey}
                    {...storeRule.form()}
                    className="grid gap-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-3 sm:grid-cols-4">
                                <TextField
                                    name="name"
                                    label="Name"
                                    defaultValue={preset?.name}
                                    required
                                    error={errors.name}
                                />
                                <TextField
                                    name="effective_from"
                                    label="From"
                                    type="date"
                                    required
                                    error={errors.effective_from}
                                />
                            </div>

                            <div className="grid gap-2">
                                <p className="text-sm font-medium">
                                    Income tax bands, on monthly taxable pay
                                </p>
                                {bands.map((band, i) => {
                                    const last = i === bands.length - 1;

                                    return (
                                        <div
                                            key={i}
                                            className="grid items-start gap-2 sm:grid-cols-[1fr_1fr_auto]"
                                        >
                                            <div>
                                                <Input
                                                    name={`bands[${i}][up_to]`}
                                                    type="number"
                                                    step="0.01"
                                                    placeholder={
                                                        last
                                                            ? 'Everything above'
                                                            : 'Up to'
                                                    }
                                                    disabled={last}
                                                    defaultValue={
                                                        last ? '' : band.up_to
                                                    }
                                                    aria-label={`Band ${i + 1} up to`}
                                                />
                                                {errors[`bands.${i}.up_to`] && (
                                                    <p className="text-sm text-destructive">
                                                        {
                                                            errors[
                                                                `bands.${i}.up_to`
                                                            ]
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                            <Input
                                                name={`bands[${i}][rate]`}
                                                type="number"
                                                step="0.01"
                                                placeholder="Rate %"
                                                defaultValue={band.rate}
                                                aria-label={`Band ${i + 1} rate`}
                                                required
                                            />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                disabled={bands.length <= 1}
                                                onClick={() => {
                                                    setBands(
                                                        bands.filter(
                                                            (_, j) => j !== i,
                                                        ),
                                                    );
                                                    setFormKey((k) => k + 1);
                                                }}
                                            >
                                                Remove
                                            </Button>
                                        </div>
                                    );
                                })}
                                <div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setBands([
                                                ...bands.slice(0, -1),
                                                { up_to: '', rate: '' },
                                                bands[bands.length - 1],
                                            ]);
                                            setFormKey((k) => k + 1);
                                        }}
                                    >
                                        Add a band
                                    </Button>
                                </div>
                                {errors.bands && (
                                    <p className="text-sm text-destructive">
                                        {errors.bands}
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-3 sm:grid-cols-4">
                                <TextField
                                    name="employee_rate"
                                    label="Social security, employee %"
                                    type="number"
                                    defaultValue={
                                        preset
                                            ? preset.social_security_employee_bp /
                                              100
                                            : 0
                                    }
                                    required
                                    error={errors.employee_rate}
                                />
                                <TextField
                                    name="employer_rate"
                                    label="Social security, employer %"
                                    type="number"
                                    defaultValue={
                                        preset
                                            ? preset.social_security_employer_bp /
                                              100
                                            : 0
                                    }
                                    required
                                    error={errors.employer_rate}
                                />
                                <TextField
                                    name="cap"
                                    label="On basic pay up to (optional)"
                                    type="number"
                                    defaultValue={toInput(
                                        preset?.social_security_cap_minor,
                                    )}
                                    error={errors.cap}
                                />
                                <Check
                                    id="deductible"
                                    name="deductible"
                                    label="Employee share is taken off taxable pay"
                                    defaultChecked={
                                        preset?.social_security_deductible ??
                                        false
                                    }
                                />
                            </div>

                            <div>
                                <Button type="submit" disabled={processing}>
                                    Save rules
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}
