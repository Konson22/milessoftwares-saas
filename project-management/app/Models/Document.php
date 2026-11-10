<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A document of the organization, with every version of its file. Files attached
 * to one expense, task or grant report stay with those. Changes go through
 * App\Actions\Documents.
 *
 * Confidential or archived documents are for those with documents.manage alone.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $title
 * @property string $category
 * @property string|null $description
 * @property bool $confidential
 * @property int $current_version
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable $updated_at
 */
class Document extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const CATEGORIES = [
        'policy' => 'Policy or procedure',
        'contract' => 'Contract or agreement',
        'registration' => 'Registration or certificate',
        'report' => 'Report',
        'minutes' => 'Minutes',
        'template' => 'Template',
        'other' => 'Other',
    ];

    /** Office files, PDFs, text and pictures: nothing a browser would run. */
    public const RULES = ['file', 'max:25600', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,csv,txt,png,jpg,jpeg,webp'];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['confidential' => 'boolean', 'current_version' => 'integer', 'archived_at' => 'datetime'];
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    /**
     * What someone without documents.manage may open.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeOpenToAll(Builder $query): Builder
    {
        return $query->where('confidential', false)->whereNull('archived_at');
    }
}
