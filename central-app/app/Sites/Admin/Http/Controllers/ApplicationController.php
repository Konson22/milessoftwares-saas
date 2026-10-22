<?php

namespace App\Sites\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Applications\Catalog\CatalogConflict;
use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationCatalogVersion;
use App\Modules\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;

/**
 * The application registry and role catalogs.
 */
class ApplicationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        return Inertia::render('admin/applications/index', [
            'applications' => Application::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Application $application) => [
                    'id' => $application->id,
                    'code' => $application->code,
                    'name' => $application->name,
                    'description' => $application->description,
                    'kind' => $application->kind->value,
                    'status' => $application->status->value,
                    'routePrefix' => $application->route_prefix,
                    'sortOrder' => $application->sort_order,
                    'trialEnabled' => $application->trial_enabled,
                    'tagline' => $application->capabilities['marketing']['tagline'] ?? null,
                    'features' => implode("\n", (array) ($application->capabilities['marketing']['features'] ?? [])),
                    'catalogVersions' => ApplicationCatalogVersion::query()
                        ->where('application_id', $application->id)
                        ->latest('published_at')
                        ->get(['id', 'version', 'status', 'published_at'])
                        ->map(fn (ApplicationCatalogVersion $version) => [
                            'version' => $version->version,
                            'status' => $version->status,
                            'current' => $version->id === $application->current_catalog_version_id,
                            'publishedAt' => $version->published_at?->toDateString(),
                        ])
                        ->all(),
                ])
                ->all(),
        ]);
    }

    public function update(Request $request, Application $application): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['planned', 'beta', 'available', 'deprecated', 'retired'])],
            // Served at {slug}.<root>/<route_prefix>: never a path the workspace shell owns.
            'route_prefix' => ['nullable', 'string', 'max:32', 'regex:/^[a-z][a-z0-9-]*$/', Rule::notIn((array) config('platform.workspace_paths')), Rule::unique('applications', 'route_prefix')->ignore($application->id)],
            'sort_order' => ['required', 'integer', 'between:0,65535'],
            'tagline' => ['nullable', 'string', 'max:200'],
            'features' => ['nullable', 'string', 'max:4000'],
            'trial_enabled' => ['boolean'],
        ]);

        // Marketing copy for the public site lives under capabilities.marketing.
        $features = array_values(array_filter(array_map('trim', explode("\n", (string) ($validated['features'] ?? '')))));
        $capabilities = (array) ($application->capabilities ?? []);
        $capabilities['marketing'] = ['tagline' => $validated['tagline'] ?? null, 'features' => $features];
        unset($validated['tagline'], $validated['features']);

        // An unticked checkbox is not sent: absent means off.
        $application->update([...$validated, 'trial_enabled' => $request->boolean('trial_enabled'), 'capabilities' => $capabilities]);
        $this->record('application.updated', $application, $request, ['changes' => $application->getChanges()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application updated.')]);

        return back();
    }

    public function importCatalog(Request $request, CatalogImporter $importer): RedirectResponse
    {
        $request->validate(['catalog' => ['required', 'file', 'mimetypes:application/json,text/plain', 'max:512']]);

        try {
            $definition = json_decode((string) file_get_contents($request->file('catalog')->getRealPath()), true, flags: JSON_THROW_ON_ERROR);
            $version = $importer->import(is_array($definition) ? $definition : []);
        } catch (JsonException) {
            throw ValidationException::withMessages(['catalog' => 'The file is not valid JSON.']);
        } catch (CatalogConflict $e) {
            throw ValidationException::withMessages(['catalog' => $e->getMessage()]);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['catalog' => collect($e->errors())->map(fn ($messages, $field) => "{$field}: ".implode(' ', $messages))->values()->all()]);
        }

        $this->record('catalog.imported', $version, $request, ['application' => $version->application->code, 'version' => $version->version]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':app catalog :version is current.', ['app' => $version->application->name, 'version' => $version->version])]);

        return back();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $action, Application|ApplicationCatalogVersion $subject, Request $request, array $context): void
    {
        $staff = $request->attributes->get('staff');
        $this->audit->record($action, $subject, $context, actor: $staff instanceof PlatformStaff ? $staff->user : null, actorType: 'staff');
    }
}
