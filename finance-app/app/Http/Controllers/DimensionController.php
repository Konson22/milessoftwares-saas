<?php

namespace App\Http\Controllers;

use App\Models\DimensionValue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cost centres and projects. A value is never deleted (posted lines name
 * it); it is closed instead, and then no longer offered on new lines.
 */
class DimensionController extends Controller
{
    public function index(): Response
    {
        $values = DimensionValue::query()->orderBy('code')->get()->map(fn (DimensionValue $value) => [
            'id' => $value->id,
            'kind' => $value->kind,
            'code' => $value->code,
            'name' => $value->name,
            'active' => $value->active,
        ]);

        return Inertia::render('dimensions/index', [
            'costCentres' => $values->where('kind', DimensionValue::COST_CENTRE)->values(),
            'projects' => $values->where('kind', DimensionValue::PROJECT)->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $kind = $request->validate(['kind' => ['required', Rule::in(DimensionValue::KINDS)]])['kind'];
        $value = DimensionValue::query()->create(['kind' => $kind] + $this->validated($request, $kind, null));

        return back()->with('success', DimensionValue::kindLabel($kind)." {$value->label()} added.");
    }

    public function update(Request $request, DimensionValue $value): RedirectResponse
    {
        $value->update($this->validated($request, $value->kind, $value));

        return back()->with('success', DimensionValue::kindLabel($value->kind)." {$value->label()} saved.");
    }

    /**
     * @return array{code: string, name: string, active: bool}
     */
    private function validated(Request $request, string $kind, ?DimensionValue $value): array
    {
        // Codes are upper case, so "adm" is "ADM" when checked as unique.
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('dimension_values', 'code')->where('organization_id', $this->organizationId())->where('kind', $kind)->ignore($value?->id)],
            'name' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return [
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'active' => (bool) ($data['active'] ?? true),
        ];
    }
}
