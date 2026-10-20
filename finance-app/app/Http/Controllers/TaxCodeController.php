<?php

namespace App\Http\Controllers;

use App\Models\TaxCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tax codes: the rates charged on sales and paid on purchases. A code is
 * never deleted (posted lines name it); it is made inactive instead.
 */
class TaxCodeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('tax-codes/index', [
            'codes' => TaxCode::query()->orderBy('code')->get()->map(fn (TaxCode $code) => [
                'id' => $code->id,
                'code' => $code->code,
                'name' => $code->name,
                'rate' => rtrim(rtrim(number_format($code->rate_bp / 100, 2, '.', ''), '0'), '.'),
                'label' => $code->label(),
                'sales' => $code->sales,
                'purchases' => $code->purchases,
                'recoverable' => $code->recoverable,
                'active' => $code->active,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        $code = TaxCode::query()->create($data);

        return back()->with('success', "Tax code {$code->label()} added.");
    }

    public function update(Request $request, TaxCode $code): RedirectResponse
    {
        $data = $this->validated($request, $code);

        // The rate is part of what was posted: a new rate is a new code.
        $code->update(collect($data)->except('rate_bp')->all());

        return back()->with('success', "Tax code {$code->label()} saved.");
    }

    /**
     * @return array{code: string, name: string, rate_bp: int, sales: bool, purchases: bool, recoverable: bool, active: bool}
     */
    private function validated(Request $request, ?TaxCode $code): array
    {
        // Codes are upper case, so "vat18" is "VAT18" when checked as unique.
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('tax_codes', 'code')->where('organization_id', $this->organizationId())->ignore($code?->id)],
            'name' => ['required', 'string', 'max:255'],
            'rate' => [$code === null ? 'required' : 'nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'sales' => ['boolean'],
            'purchases' => ['boolean'],
            'recoverable' => ['boolean'],
            'active' => ['boolean'],
        ]);

        return [
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'rate_bp' => $code->rate_bp ?? (int) round((float) $data['rate'] * 100),
            'sales' => (bool) ($data['sales'] ?? false),
            'purchases' => (bool) ($data['purchases'] ?? false),
            'recoverable' => (bool) ($data['recoverable'] ?? false),
            'active' => (bool) ($data['active'] ?? true),
        ];
    }
}
