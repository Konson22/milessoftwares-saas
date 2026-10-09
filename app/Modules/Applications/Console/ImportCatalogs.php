<?php

namespace App\Modules\Applications\Console;

use App\Modules\Applications\Catalog\CatalogConflict;
use App\Modules\Applications\Catalog\CatalogImporter;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;

class ImportCatalogs extends Command
{
    protected $signature = 'platform:catalog-import {paths?* : Catalog JSON files (default: database/catalogs/*.json)}';

    protected $description = 'Import application role and permission catalogs as new immutable versions';

    public function handle(CatalogImporter $importer): int
    {
        /** @var list<string> $paths */
        $paths = $this->argument('paths') ?: (glob(database_path('catalogs/*.json')) ?: []);
        $failed = false;

        foreach ($paths as $path) {
            try {
                $definition = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                $version = $importer->import(is_array($definition) ? $definition : []);
                $this->components->info(basename($path).": {$version->application->code} {$version->version} is current.");
            } catch (ValidationException $e) {
                $failed = true;
                $this->components->error(basename($path).' is invalid:');
                foreach ($e->errors() as $field => $messages) {
                    $this->line("  {$field}: ".implode(' ', $messages));
                }
            } catch (CatalogConflict|JsonException $e) {
                $failed = true;
                $this->components->error(basename($path).': '.$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
