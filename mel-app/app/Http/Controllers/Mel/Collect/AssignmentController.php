<?php

namespace App\Http\Controllers\Mel\Collect;

use App\Collect\Assignments;
use App\Collect\Models\Assignment;
use App\Collect\Models\Form;
use App\Collect\Models\Submission;
use App\Http\Controllers\Mel\Controller;
use App\Mel\Members;
use App\Mel\Models\ProgrammeUnit;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Field assignments: who collects with which form, from when to when, and
 * how much each person has sent.
 */
class AssignmentController extends Controller
{
    public function __construct(private readonly Assignments $assignments) {}

    public function index(Request $request): Response
    {
        $formId = is_string($request->query('form')) ? $request->query('form') : null;
        $show = $request->query('show') === 'ended' ? 'ended' : 'live';
        $forms = $this->scope()->forms();

        $assignments = Assignment::query()
            ->whereIn('form_id', (clone $forms)->select('id'))
            ->when($formId !== null, fn ($query) => $query->where('form_id', $formId))
            ->when($show === 'live', fn ($query) => $query->whereIn('status', [Assignment::ACTIVE, Assignment::CLOSING]), fn ($query) => $query->where('status', Assignment::REVOKED))
            ->with(['form', 'user', 'unit', 'assigner'])
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        // Submissions per person and form, with the last one's time.
        $sent = Submission::query()
            ->whereIn('form_id', $assignments->pluck('form_id')->unique()->all())
            ->whereIn('author_id', $assignments->pluck('user_id')->unique()->all())
            ->where('status', '<>', Submission::RECEIVING)
            ->selectRaw('form_id, author_id, status, count(*) as total, max(received_at) as last_at')
            ->groupBy('form_id', 'author_id', 'status')
            ->get()
            ->groupBy(fn ($row) => $row->getAttribute('form_id').'|'.$row->getAttribute('author_id'));
        $today = CarbonImmutable::today();

        $assignable = (clone $forms)->where('status', '<>', Form::ARCHIVED)->with(['programme.units', 'unit'])->orderBy('name')->get();

        return Inertia::render('mel/collect/assignments/index', [
            'filters' => ['form' => $formId, 'show' => $show],
            'assignments' => $assignments->map(function (Assignment $assignment) use ($sent, $today) {
                $rows = $sent->get($assignment->form_id.'|'.$assignment->user_id) ?? collect();
                $by = fn (array $statuses) => (int) $rows->filter(fn ($row) => in_array($row->getAttribute('status'), $statuses, true))->sum(fn ($row) => (int) $row->getAttribute('total'));
                $last = $rows->max(fn ($row) => $row->getAttribute('last_at'));
                // Done: sent and not rejected. Collection is apart from review.
                $done = $by([Submission::SUBMITTED, Submission::UNDER_REVIEW, Submission::CORRECTION_REQUESTED, Submission::ACCEPTED]);
                $expected = $assignment->expected_submissions;
                $complete = $expected !== null && $done >= $expected;

                return [
                    'id' => $assignment->id,
                    'person' => $assignment->user->name,
                    'email' => $assignment->user->email,
                    'form' => ['id' => $assignment->form_id, 'name' => $assignment->form->name, 'status' => $assignment->form->status],
                    'unit' => $assignment->unit?->code,
                    'startsOn' => $assignment->starts_on?->toDateString(),
                    'endsOn' => $assignment->ends_on?->toDateString(),
                    'status' => $assignment->status,
                    'closesAt' => $assignment->closes_at?->toIso8601String(),
                    'assignedBy' => $assignment->assigner->name,
                    'submissions' => (int) $rows->sum(fn ($row) => (int) $row->getAttribute('total')),
                    'lastSubmissionAt' => $last === null ? null : CarbonImmutable::parse((string) $last)->toIso8601String(),
                    'expected' => $expected,
                    'progress' => [
                        'done' => $done,
                        'awaitingReview' => $by(Submission::TO_REVIEW),
                        'correction' => $by([Submission::CORRECTION_REQUESTED]),
                        'accepted' => $by([Submission::ACCEPTED]),
                        'rejected' => $by([Submission::REJECTED]),
                    ],
                    // Collection only: whether the agreed work was sent, not whether it was accepted.
                    'collection' => match (true) {
                        $complete => 'complete',
                        $assignment->status === Assignment::ACTIVE && $assignment->ends_on !== null && $assignment->ends_on->lessThan($today) => 'overdue',
                        $done > 0 => 'in_progress',
                        default => 'not_started',
                    },
                ];
            }),
            'forms' => $assignable->map(fn (Form $form) => [
                'value' => $form->id,
                'label' => $form->name,
                'published' => $form->current_version_id !== null,
                'unitFixed' => $form->unit?->label(),
                'units' => $form->programme_unit_id !== null || $form->programme === null ? [] : $form->programme->units
                    ->filter(fn (ProgrammeUnit $unit) => $unit->archived_at === null)
                    ->map(fn (ProgrammeUnit $unit) => ['value' => $unit->id, 'label' => $unit->label()])->values(),
            ])->values(),
            'people' => Members::options(),
            'canAssign' => $this->access()->can('mel.collect.assign'),
        ]);
    }

    public function store(Request $request, string $form): RedirectResponse
    {
        $form = $this->scope()->form($form);
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:200'],
            'user_ids.*' => ['uuid', Members::rule()],
            'programme_unit_id' => ['nullable', 'uuid'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'expected_submissions' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ], ['user_ids.required' => 'Choose who collects with this form.', 'user_ids.*.exists' => 'Choose people of this organization.']);

        $created = $this->assignments->assign(
            $form,
            array_values(array_map('strval', $data['user_ids'])),
            $data['programme_unit_id'] ?? null,
            isset($data['starts_on']) ? CarbonImmutable::parse($data['starts_on']) : null,
            isset($data['ends_on']) ? CarbonImmutable::parse($data['ends_on']) : null,
            $this->user(),
            isset($data['expected_submissions']) ? (int) $data['expected_submissions'] : null,
        );
        $updated = count(array_unique($data['user_ids'])) - $created;

        return back()->with('success', trim(($created > 0 ? "{$created} assigned. " : '').($updated > 0 ? "{$updated} updated." : '')));
    }

    public function close(Request $request, string $assignment): RedirectResponse
    {
        $assignment = $this->assignment($assignment);
        $data = $request->validate(['closes_at' => ['nullable', 'date']]);

        $this->assignments->close($assignment, isset($data['closes_at']) ? CarbonImmutable::parse($data['closes_at']) : null, $this->user());

        return back()->with('success', "{$assignment->user->name} can no longer start this form. What they already filled in still arrives until the date shown.");
    }

    public function revoke(string $assignment): RedirectResponse
    {
        $assignment = $this->assignment($assignment);
        $this->assignments->revoke($assignment, $this->user());

        return back()->with('success', "{$assignment->user->name}'s assignment is revoked. Anything still on their phone is kept there until you assign them again.");
    }

    private function assignment(string $id): Assignment
    {
        return Assignment::query()->whereIn('form_id', $this->scope()->forms()->select('id'))->with(['user', 'form'])->findOrFail($id);
    }
}
