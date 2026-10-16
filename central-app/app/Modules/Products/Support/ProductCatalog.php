<?php

namespace App\Modules\Products\Support;

use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Models\Application;
use App\Modules\Products\Enums\ProductStatus;
use App\Modules\Products\Models\ErpProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Writes one ERP product definition (database/erp-products.json) to the
 * database: the product row and the applications it is made of. Running it
 * again with the same definition changes nothing.
 *
 * A product can only be offered on software that is released enough for
 * it: a pilot needs every required application in beta or later, a
 * generally available product needs them all generally available. A
 * planned product may name applications that are not registered yet; they
 * are left out until they are.
 */
final class ProductCatalog
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array{product: ErpProduct, unregistered: list<string>}
     *
     * @throws ValidationException
     */
    public function sync(array $definition): array
    {
        $product = $this->validate($definition);
        $status = ProductStatus::from($product['status']);

        $links = [];
        $unregistered = [];
        $errors = [];

        foreach ($product['applications'] as $index => $entry) {
            $application = Application::query()->where('code', $entry['code'])->first();

            if ($application === null) {
                if ($status === ProductStatus::Planned) {
                    $unregistered[] = $entry['code'];
                } else {
                    $errors["applications.{$index}.code"] = "{$entry['code']} is not a registered application.";
                }

                continue;
            }

            if ($application->kind === ApplicationKind::Platform) {
                $errors["applications.{$index}.code"] = "{$entry['code']} is part of the platform, not a product application.";

                continue;
            }

            if ($entry['requirement'] === 'required' && ! $status->accepts($application->status)) {
                $errors["applications.{$index}.code"] = "{$entry['code']} is {$application->status->value}; a {$status->value} product cannot require it.";

                continue;
            }

            $links[$application->id] = ['requirement' => $entry['requirement']];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $model = DB::transaction(function () use ($product, $links): ErpProduct {
            $model = ErpProduct::query()->where('code', $product['code'])->first() ?? new ErpProduct;
            $model->forceFill([
                'code' => $product['code'],
                'name' => $product['name'],
                'tagline' => $product['tagline'] ?? null,
                'description' => $product['description'] ?? null,
                'status' => $product['status'],
                'sort_order' => $product['sort_order'] ?? 0,
            ])->save();

            $model->applications()->sync($links);

            return $model;
        });

        return ['product' => $model, 'unregistered' => $unregistered];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{code: string, name: string, tagline?: string|null, description?: string|null, status: string, sort_order?: int|null, applications: list<array{code: string, requirement: string}>}
     *
     * @throws ValidationException
     */
    private function validate(array $definition): array
    {
        $validator = Validator::make($definition, [
            'code' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:255'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::enum(ProductStatus::class)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'applications' => ['required', 'array', 'list', 'min:1'],
            'applications.*.code' => ['required', 'string', 'distinct'],
            'applications.*.requirement' => ['required', Rule::in(['required', 'optional'])],
        ]);

        $validator->after(function ($validator) use ($definition): void {
            $requirements = array_column(is_array($definition['applications'] ?? null) ? $definition['applications'] : [], 'requirement');

            if (! in_array('required', $requirements, true)) {
                $validator->errors()->add('applications', 'A product needs at least one required application.');
            }
        });

        /** @var array{code: string, name: string, tagline?: string|null, description?: string|null, status: string, sort_order?: int|null, applications: list<array{code: string, requirement: string}>} */
        return $validator->validate();
    }
}
