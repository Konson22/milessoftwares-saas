<?php

namespace App\Collaboration;

/**
 * How an organization takes part in a project. A label for people: access
 * comes only from the capabilities granted (ADR-0014 §2). SUGGESTED only
 * pre-fills the invitation form.
 */
final class Relationship
{
    public const DONOR = 'donor';

    public const LEAD_IMPLEMENTER = 'lead_implementer';

    public const IMPLEMENTER = 'implementer';

    public const SUB_IMPLEMENTER = 'sub_implementer';

    public const AUDITOR = 'auditor';

    public const OTHER = 'other';

    public const LABELS = [
        self::DONOR => 'Donor',
        self::LEAD_IMPLEMENTER => 'Lead implementing partner',
        self::IMPLEMENTER => 'Implementing partner',
        self::SUB_IMPLEMENTER => 'Sub-implementing partner',
        self::AUDITOR => 'Independent auditor',
        self::OTHER => 'Other authorized participant',
    ];

    /** @var array<string, list<string>> */
    public const SUGGESTED = [
        self::DONOR => [Capability::VIEW, Capability::BUDGET_VIEW, Capability::RESULTS_VIEW, Capability::DONOR_REPORTS_VIEW, Capability::REPORTS_REVIEW, Capability::REQUESTS_REVIEW],
        self::LEAD_IMPLEMENTER => [Capability::VIEW, Capability::ACTIVITIES_MANAGE, Capability::PARTNERS_MANAGE, Capability::BUDGET_VIEW, Capability::PROCUREMENT_REQUEST, Capability::REPORTS_SUBMIT, Capability::REPORTS_REVIEW, Capability::REQUESTS_CREATE, Capability::REQUESTS_REVIEW, Capability::EVIDENCE_SUBMIT],
        self::IMPLEMENTER => [Capability::VIEW, Capability::ACTIVITIES_MANAGE, Capability::REPORTS_SUBMIT, Capability::REQUESTS_CREATE, Capability::EVIDENCE_SUBMIT],
        self::SUB_IMPLEMENTER => [Capability::VIEW, Capability::REPORTS_SUBMIT, Capability::REQUESTS_CREATE, Capability::EVIDENCE_SUBMIT],
        self::AUDITOR => [Capability::VIEW, Capability::BUDGET_VIEW, Capability::RESULTS_VIEW],
        self::OTHER => [Capability::VIEW],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(string $relationship): string
    {
        return self::LABELS[$relationship] ?? $relationship;
    }

    /**
     * @return list<array{value: string, label: string, suggested: list<string>}>
     */
    public static function options(): array
    {
        return array_map(fn (string $key) => ['value' => $key, 'label' => self::LABELS[$key], 'suggested' => self::SUGGESTED[$key]], self::keys());
    }
}
