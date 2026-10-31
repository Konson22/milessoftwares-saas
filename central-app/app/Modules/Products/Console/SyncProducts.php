<?php

namespace App\Modules\Products\Console;

use App\Modules\Applications\Models\Application;
use App\Modules\Products\Enums\ProductStatus;
use App\Modules\Products\Support\ProductCatalog;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;

class SyncProducts extends Command
{
    protected $signature = 'platform:erp-products {path? : Product definitions (default: database/erp-products.json)}';

    protected $description = 'Write the ERP product catalog (products and the applications they are made of)';

    public function handle(ProductCatalog $catalog): int
    {
        $path = (string) ($this->argument('path') ?: database_path('erp-products.json'));

        try {
            $file = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->components->error(basename($path).': '.$e->getMessage());

            return self::FAILURE;
        }

        $products = is_array($file) && is_array($file['products'] ?? null) ? $file['products'] : [];
        $failed = false;

        foreach ($products as $index => $definition) {
            $label = is_array($definition) && is_string($definition['code'] ?? null) ? $definition['code'] : "#{$index}";

            // A released product whose applications this deployment does not
            // run (yet) stays coming soon here instead of failing the sync.
            $missing = is_array($definition) ? $this->unregistered($definition) : [];
            if ($missing !== [] && ($definition['status'] ?? null) !== ProductStatus::Planned->value) {
                $this->line("  {$label}: ".implode(', ', $missing).' not registered here, kept planned.');
                $definition['status'] = ProductStatus::Planned->value;
            }

            try {
                $result = $catalog->sync(is_array($definition) ? $definition : []);
                $this->components->info("{$label}: {$result['product']->status->value}.");

                if ($result['unregistered'] !== []) {
                    $this->line('  not registered yet, left out: '.implode(', ', $result['unregistered']));
                }
            } catch (ValidationException $e) {
                $failed = true;
                $this->components->error("{$label} is invalid:");
                foreach ($e->errors() as $field => $messages) {
                    $this->line("  {$field}: ".implode(' ', $messages));
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<mixed>  $definition
     * @return list<string>
     */
    private function unregistered(array $definition): array
    {
        $codes = array_values(array_filter(array_column(is_array($definition['applications'] ?? null) ? $definition['applications'] : [], 'code'), 'is_string'));

        return array_values(array_diff($codes, Application::query()->whereIn('code', $codes)->pluck('code')->all()));
    }
}
