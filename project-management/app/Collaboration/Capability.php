<?php

namespace App\Collaboration;

use App\Access\Access;

/**
 * What a participating organization may do on a project it takes part in
 * (ADR-0014 §3). Granted explicitly on its participation, never derived from
 * its relationship. Each one is implied, on the owner's side, by catalog
 * permissions: nobody grants what they could not do themselves.
 */
final class Capability
{
    public const VIEW = 'view';

    public const EDIT = 'edit';

    public const ACTIVITIES_MANAGE = 'activities.manage';

    public const MEMBERS_MANAGE = 'members.manage';

    public const PARTNERS_MANAGE = 'partners.manage';

    public const BUDGET_VIEW = 'budget.view';

    public const BUDGET_MANAGE = 'budget.manage';

    public const PROCUREMENT_REQUEST = 'procurement.request';

    public const PROCUREMENT_APPROVE = 'procurement.approve';

    public const REPORTS_SUBMIT = 'reports.submit';

    public const REPORTS_REVIEW = 'reports.review';

    public const REQUESTS_CREATE = 'requests.create';

    public const REQUESTS_REVIEW = 'requests.review';

    public const RESULTS_VIEW = 'results.view';

    public const EVIDENCE_SUBMIT = 'evidence.submit';

    public const DONOR_REPORTS_VIEW = 'donor_reports.view';

    public const REPORTS_PUBLISH = 'reports.publish';

    /**
     * Label, the owner-side permissions that imply it (all of them), and
     * whether participants can already use it here (the others are stored
     * for the phases that build them).
     *
     * @var array<string, array{label: string, implies: list<string>, effective: bool}>
     */
    public const ALL = [
        self::VIEW => ['label' => 'View project', 'implies' => ['projects.view'], 'effective' => true],
        self::EDIT => ['label' => 'Edit project', 'implies' => ['projects.manage'], 'effective' => false],
        // Enforced by App\Collaboration\SharedTasks: work on the tasks given to it.
        self::ACTIVITIES_MANAGE => ['label' => 'Work on tasks given to it', 'implies' => ['projects.tasks.manage'], 'effective' => true],
        self::MEMBERS_MANAGE => ['label' => 'Manage project members', 'implies' => ['projects.manage'], 'effective' => false],
        self::PARTNERS_MANAGE => ['label' => 'Manage partner relationships', 'implies' => ['projects.partners.manage'], 'effective' => true],
        self::BUDGET_VIEW => ['label' => 'View approved budgets', 'implies' => ['projects.finance.view'], 'effective' => true],
        self::BUDGET_MANAGE => ['label' => 'Manage budget plans', 'implies' => ['projects.manage', 'projects.finance.view'], 'effective' => false],
        self::PROCUREMENT_REQUEST => ['label' => 'Create procurement requests', 'implies' => ['procurement.request'], 'effective' => false],
        self::PROCUREMENT_APPROVE => ['label' => 'Approve procurement requests', 'implies' => ['procurement.approve'], 'effective' => false],
        self::REPORTS_SUBMIT => ['label' => 'Submit partner reports', 'implies' => ['projects.reports.prepare'], 'effective' => true],
        self::REPORTS_REVIEW => ['label' => 'Review partner reports', 'implies' => ['projects.reports.review'], 'effective' => true],
        self::REQUESTS_CREATE => ['label' => 'Create project requests', 'implies' => ['projects.requests.raise'], 'effective' => true],
        self::REQUESTS_REVIEW => ['label' => 'Review project requests', 'implies' => ['projects.requests.review'], 'effective' => true],
        self::RESULTS_VIEW => ['label' => 'View M&E results', 'implies' => ['mel.results.view_published'], 'effective' => false],
        self::EVIDENCE_SUBMIT => ['label' => 'Submit monitoring evidence', 'implies' => ['mel.data.enter'], 'effective' => false],
        self::DONOR_REPORTS_VIEW => ['label' => 'View published reports', 'implies' => ['projects.view'], 'effective' => true],
        self::REPORTS_PUBLISH => ['label' => 'Publish approved reports', 'implies' => ['projects.reports.publish'], 'effective' => true],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    /**
     * The capabilities the backend enforces today. Only these can be granted,
     * offered in the UI or kept on a participation; the others stay defined
     * for later but are dropped wherever capabilities are read or saved, so
     * nobody believes they granted (or withheld) something that has no
     * effect. When one becomes enforced, review the participations that held
     * it before it was dropped (ADR-0014, amendment of 2026-11-16).
     *
     * @return list<string>
     */
    public static function operational(): array
    {
        return array_values(array_filter(self::keys(), fn (string $key) => self::ALL[$key]['effective']));
    }

    /**
     * Known, enforced keys only, always with view, in catalog order.
     *
     * @param  array<array-key, mixed>  $capabilities
     * @return list<string>
     */
    public static function normalize(array $capabilities): array
    {
        $given = array_filter($capabilities, 'is_string');

        return array_values(array_filter(self::operational(), fn (string $key) => $key === self::VIEW || in_array($key, $given, true)));
    }

    /**
     * What a member of the owning organization may grant: the capabilities
     * their own permissions imply.
     *
     * @return list<string>
     */
    public static function grantableBy(Access $access): array
    {
        return array_values(array_filter(self::operational(), function (string $key) use ($access) {
            if ($access->isOwner) {
                return true;
            }
            foreach (self::ALL[$key]['implies'] as $permission) {
                if (! $access->has($permission)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * For the UI: the enforced capabilities with their labels.
     *
     * @return list<array{value: string, label: string, effective: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (string $key) => ['value' => $key, 'label' => self::ALL[$key]['label'], 'effective' => true], self::operational());
    }

    /**
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    public static function labels(array $capabilities): array
    {
        return array_map(fn (string $key) => self::ALL[$key]['label'] ?? $key, $capabilities);
    }
}
