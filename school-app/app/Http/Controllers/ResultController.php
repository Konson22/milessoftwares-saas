<?php

namespace App\Http\Controllers;

use App\Models\AttendanceEntry;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Workspace;
use App\Support\Results;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Term results: a class's broadsheet, and each student's report card.
 */
class ResultController extends Controller
{
    public function index(Request $request): Response
    {
        $category = $this->category();
        $term = $this->term($request);
        $classId = $request->string('class')->toString();
        $class = $classId === '' ? null : SchoolClass::query()->whereKey($classId)->first();

        return Inertia::render('results/index', [
            'filters' => ['term' => $term?->id, 'class' => $classId],
            'terms' => $this->termOptions(),
            'classes' => $this->classOptions(),
            'assessments' => $term?->assessments()->get(['id', 'name', 'weight']) ?? [],
            'results' => $term === null || $class === null ? null : (new Results($category))->forClass($term, $class),
            'grading' => $category->gradingScale(),
        ]);
    }

    public function show(Request $request, Student $student): Response
    {
        $category = $this->category();
        $term = $this->term($request);
        $student->load('schoolClass.classTeacher:id,name', 'schoolClass.programme:id,name');
        $results = new Results($category);
        $report = $term === null ? null : $results->forStudent($term, $student);

        $attendance = $term === null ? collect() : AttendanceEntry::query()
            ->where('student_id', $student->id)
            ->whereHas('register', fn ($query) => $query->where('term_id', $term->id))
            ->selectRaw('status, count(*) as days')
            ->groupBy('status')
            ->pluck('days', 'status');

        return Inertia::render('results/show', [
            'schoolName' => Workspace::current()?->display_name,
            'student' => [
                'id' => $student->id,
                'number' => $student->number,
                'name' => $student->name(),
                'class' => $student->schoolClass?->name,
                'programme' => $student->schoolClass?->programme?->name,
                'classTeacher' => $student->schoolClass?->classTeacher?->name,
            ],
            'term' => $term === null ? null : ['id' => $term->id, 'title' => $term->title()],
            'terms' => $this->termOptions(),
            'assessments' => $term?->assessments()->get(['id', 'name', 'weight']) ?? [],
            'report' => $report,
            'classSize' => $student->schoolClass !== null && $category->ranksInClass() ? $student->schoolClass->enrolled()->count() : null,
            'cgpa' => $term === null ? null : $results->cumulativeGpa($term, $student),
            'attendance' => [
                'present' => (int) ($attendance[AttendanceEntry::PRESENT] ?? 0) + (int) ($attendance[AttendanceEntry::LATE] ?? 0),
                'absent' => (int) ($attendance[AttendanceEntry::ABSENT] ?? 0),
                'excused' => (int) ($attendance[AttendanceEntry::EXCUSED] ?? 0),
            ],
            'grading' => $category->gradingScale(),
        ]);
    }
}
