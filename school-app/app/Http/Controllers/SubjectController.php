<?php

namespace App\Http\Controllers;

use App\Models\ClassSubject;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Subjects, modules or courses the school teaches.
 */
class SubjectController extends Controller
{
    public function index(Request $request): Response
    {
        $subjects = Subject::query()->orderByDesc('active')->orderBy('name')->get();
        $classes = ClassSubject::query()->selectRaw('subject_id, count(*) as classes')->groupBy('subject_id')->pluck('classes', 'subject_id');

        return Inertia::render('subjects/index', [
            'subjects' => $subjects->map(fn (Subject $subject) => [
                'id' => $subject->id,
                'code' => $subject->code,
                'name' => $subject->name,
                'credits' => $subject->credit_hours,
                'classes' => (int) ($classes[$subject->id] ?? 0),
                'active' => $subject->active,
            ]),
            'canManage' => $this->can($request, 'school.academics.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('subjects/form', ['subject' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $subject = new Subject;
        $this->save($request, $subject);

        return to_route('subjects.index')->with('success', "{$subject->name} added.");
    }

    public function edit(Subject $subject): Response
    {
        return Inertia::render('subjects/form', [
            'subject' => [
                'id' => $subject->id,
                'code' => $subject->code,
                'name' => $subject->name,
                'credits' => $subject->credit_hours,
                'active' => $subject->active,
            ],
        ]);
    }

    public function update(Request $request, Subject $subject): RedirectResponse
    {
        $this->save($request, $subject);

        return to_route('subjects.index')->with('success', "{$subject->name} saved.");
    }

    private function save(Request $request, Subject $subject): void
    {
        $credits = $this->category()->usesCredits();
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/ ]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'credit_hours' => [$credits ? 'required' : 'prohibited', 'nullable', 'integer', 'min:1', 'max:30'],
            'active' => ['boolean'],
        ]);
        $code = strtoupper(trim($validated['code']));

        if (Subject::query()->where('code', $code)->whereKeyNot($subject->id)->exists()) {
            throw ValidationException::withMessages(['code' => "Another {$this->category()->subjectLabel()} is {$code}."]);
        }

        $subject->fill([
            'code' => $code,
            'name' => trim($validated['name']),
            'credit_hours' => $credits ? (int) $validated['credit_hours'] : null,
            'active' => (bool) ($validated['active'] ?? true),
        ])->save();
    }
}
