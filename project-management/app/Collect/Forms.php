<?php

namespace App\Collect;

use App\Collect\Models\Form;
use App\Collect\Models\FormVersion;
use App\Mel\AuditTrail;
use App\Mel\Models\Programme;
use App\Mel\Models\ProgrammeUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A form's life (ADR-0012 §5): draft → published → archived.
 *
 * The draft is a working copy, saved as the builder changes it. Publishing
 * checks it (DefinitionCheck) and copies it into a new, immutable version.
 * The version it replaces is no longer offered to phones, but submissions
 * filled in on it are still accepted for a while (30 days unless the
 * publisher says otherwise), so offline work is not lost.
 */
class Forms
{
    public const DEFAULT_ACCEPT_OLD_DAYS = 30;

    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array{name: string, description?: string|null, purpose: string, programme_id?: string|null, programme_unit_id?: string|null, contains_personal_data?: bool}  $data
     */
    public function create(array $data, User $user): Form
    {
        $this->assertUnitInProgramme($data['programme_id'] ?? null, $data['programme_unit_id'] ?? null);

        return DB::transaction(function () use ($data, $user) {
            $form = Form::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'purpose' => $data['purpose'],
                'programme_id' => $data['programme_id'] ?? null,
                'programme_unit_id' => $data['programme_unit_id'] ?? null,
                'contains_personal_data' => $data['contains_personal_data'] ?? true,
                'status' => Form::DRAFT,
                'draft_definition' => Definition::blank($data['name']),
                'draft_updated_at' => now(),
                'draft_updated_by' => $user->id,
                'created_by' => $user->id,
            ]);
            $this->audit->record('form.created', $form, $user);

            return $form;
        });
    }

    /**
     * @param  array{name: string, description?: string|null, purpose: string, programme_id?: string|null, programme_unit_id?: string|null, contains_personal_data?: bool}  $data
     */
    public function update(Form $form, array $data, User $user): void
    {
        $this->assertNotArchived($form);
        $this->assertUnitInProgramme($data['programme_id'] ?? null, $data['programme_unit_id'] ?? null);

        DB::transaction(function () use ($form, $data, $user) {
            $form->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'purpose' => $data['purpose'],
                'programme_id' => $data['programme_id'] ?? null,
                'programme_unit_id' => $data['programme_unit_id'] ?? null,
                'contains_personal_data' => $data['contains_personal_data'] ?? $form->contains_personal_data,
            ]);
            $changes = array_keys($form->getDirty());
            $form->save();
            if ($changes !== []) {
                $this->audit->record('form.updated', $form, $user, ['changed' => implode(', ', $changes)]);
            }
        });
    }

    /**
     * Saves the builder's working copy. Anything readable is saved, issues
     * and all: the issues come back for the builder to show, and only
     * publishing insists on none.
     *
     * @param  array<string, mixed>  $definition
     * @return list<array{path: string, question: string|null, message: string}>
     */
    public function saveDraft(Form $form, array $definition, User $user): array
    {
        $this->assertNotArchived($form);

        if (strlen((string) json_encode($definition)) > Definition::MAX_BYTES) {
            throw ValidationException::withMessages(['definition' => 'The form is too large (more than 1 MB).']);
        }

        $form->update([
            'draft_definition' => $definition,
            'draft_updated_at' => now(),
            'draft_updated_by' => $user->id,
        ]);

        return DefinitionCheck::issues($definition);
    }

    /**
     * Publishes the draft as the next version.
     */
    public function publish(Form $form, ?string $note, ?int $acceptOldDays, User $user): FormVersion
    {
        return DB::transaction(function () use ($form, $note, $acceptOldDays, $user) {
            $locked = Form::query()->with('currentVersion')->whereKey($form->id)->lockForUpdate()->firstOrFail();
            $this->assertNotArchived($locked);

            $definition = $locked->draft_definition;
            $issues = DefinitionCheck::issues($definition);
            if ($issues !== []) {
                throw ValidationException::withMessages(['definition' => 'Fix the '.(count($issues) === 1 ? 'issue' : count($issues).' issues').' in the form before publishing.']);
            }

            $previous = $locked->currentVersion;
            $hash = FormVersion::hash($definition);
            if ($previous !== null && $previous->sha256 === $hash) {
                throw ValidationException::withMessages(['definition' => "Nothing has changed since version {$previous->number}."]);
            }

            $version = FormVersion::query()->create([
                'form_id' => $locked->id,
                'number' => (int) FormVersion::query()->where('form_id', $locked->id)->max('number') + 1,
                'definition' => $definition,
                'sha256' => $hash,
                'note' => $note,
                'published_by' => $user->id,
                'published_at' => now(),
            ]);

            if ($previous !== null) {
                $days = $acceptOldDays ?? self::DEFAULT_ACCEPT_OLD_DAYS;
                $previous->update([
                    'closed_at' => now(),
                    'accept_until' => $days > 0 ? now()->addDays($days) : now(),
                ]);
            }

            $locked->update(['status' => Form::PUBLISHED, 'current_version_id' => $version->id]);
            $form->setRawAttributes($locked->getAttributes(), true);
            $this->audit->record('form.published', $locked, $user, ['version' => $version->number, 'note' => $note]);

            return $version;
        });
    }

    /**
     * Stops accepting submissions filled in on an older version from a
     * given moment (now, when null).
     */
    public function closeVersion(FormVersion $version, ?CarbonImmutable $acceptUntil, User $user): void
    {
        $form = $version->form;

        if ($form->current_version_id === $version->id) {
            throw ValidationException::withMessages(['version' => 'This is the version in use. Publish a new one, or archive the form, to stop it.']);
        }

        DB::transaction(function () use ($version, $form, $acceptUntil, $user) {
            $version->update([
                'closed_at' => $version->closed_at ?? now(),
                'accept_until' => $acceptUntil ?? now(),
            ]);
            $this->audit->record('form.version_closed', $form, $user, [
                'version' => $version->number,
                'accept_until' => $acceptUntil?->toIso8601String() ?? 'now',
            ]);
        });
    }

    public function duplicate(Form $form, User $user): Form
    {
        return DB::transaction(function () use ($form, $user) {
            $name = mb_substr('Copy of '.$form->name, 0, 255);
            $definition = $form->draft_definition;
            if (is_array($definition['settings'] ?? null)) {
                $definition['settings']['title'] = mb_substr('Copy of '.($definition['settings']['title'] ?? $form->name), 0, 200);
            }

            $copy = Form::query()->create([
                'name' => $name,
                'description' => $form->description,
                'purpose' => $form->purpose,
                'programme_id' => $form->programme_id,
                'programme_unit_id' => $form->programme_unit_id,
                'contains_personal_data' => $form->contains_personal_data,
                'status' => Form::DRAFT,
                'draft_definition' => $definition,
                'draft_updated_at' => now(),
                'draft_updated_by' => $user->id,
                'created_by' => $user->id,
            ]);
            $this->audit->record('form.created', $copy, $user, ['copied_from' => $form->name]);

            return $copy;
        });
    }

    /**
     * Archives a form: phones no longer get it, and new submissions are
     * refused after $acceptDays (at once when 0). Everything collected stays.
     */
    public function archive(Form $form, int $acceptDays, User $user): void
    {
        $this->assertNotArchived($form);

        DB::transaction(function () use ($form, $acceptDays, $user) {
            $form->update([
                'status' => Form::ARCHIVED,
                'archived_at' => now(),
                'accept_until' => $acceptDays > 0 ? now()->addDays($acceptDays) : null,
            ]);
            $this->audit->record('form.archived', $form, $user, ['accept_days' => $acceptDays]);
        });
    }

    public function restore(Form $form, User $user): void
    {
        if (! $form->isArchived()) {
            return;
        }

        DB::transaction(function () use ($form, $user) {
            $form->update([
                'status' => $form->current_version_id === null ? Form::DRAFT : Form::PUBLISHED,
                'archived_at' => null,
                'accept_until' => null,
            ]);
            $this->audit->record('form.restored', $form, $user);
        });
    }

    private function assertNotArchived(Form $form): void
    {
        if ($form->isArchived()) {
            throw ValidationException::withMessages(['form' => 'This form is archived. Restore it to change it.']);
        }
    }

    private function assertUnitInProgramme(?string $programmeId, ?string $unitId): void
    {
        if ($programmeId !== null && Programme::query()->whereKey($programmeId)->whereNotNull('archived_at')->exists()) {
            throw ValidationException::withMessages(['programme_id' => 'This programme is archived.']);
        }

        if ($unitId !== null && ($programmeId === null || ! ProgrammeUnit::query()->whereKey($unitId)->where('programme_id', $programmeId)->exists())) {
            throw ValidationException::withMessages(['programme_unit_id' => 'Choose a unit of the form\'s programme.']);
        }
    }
}
