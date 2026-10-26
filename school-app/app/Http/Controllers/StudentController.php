<?php

namespace App\Http\Controllers;

use App\Actions\Enrolment;
use App\Models\AttendanceEntry;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Students: enrolled directly or admitted from an application, their
 * details, class, status, attendance and fees.
 */
class StudentController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $classId = $request->string('class')->toString();
        $status = $request->string('status')->toString();
        $status = in_array($status, Student::STATUSES, true) ? $status : Student::ACTIVE;

        $students = Student::query()
            ->search($search)
            ->where('status', $status)
            ->when($classId !== '', fn ($query) => $query->where('school_class_id', $classId))
            ->with('schoolClass:id,name')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(500)
            ->get();

        return Inertia::render('students/index', [
            'students' => $students->map(fn (Student $student) => [
                'id' => $student->id,
                'number' => $student->number,
                'name' => $student->name(),
                'gender' => $student->gender,
                'class' => $student->schoolClass?->name,
                'contact' => $student->guardian_phone ?? $student->phone,
                'status' => $student->status,
            ]),
            'filters' => ['search' => $search, 'class' => $classId, 'status' => $status],
            'classes' => $this->classOptions(false),
            'canManage' => $this->can($request, 'school.students.manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('students/form', [
            'student' => null,
            'classes' => $this->classOptions(),
            'classId' => $request->string('class')->toString() ?: null,
            'today' => today()->toDateString(),
        ]);
    }

    public function store(Request $request, Enrolment $enrolment): RedirectResponse
    {
        $details = $this->details($request);
        $validated = $request->validate([
            'school_class_id' => ['required', 'uuid'],
            'admitted_on' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $class = SchoolClass::query()->whereKey($validated['school_class_id'])->first() ?? throw ValidationException::withMessages(['school_class_id' => 'Choose a class.']);

        $student = $enrolment->enrol($details, $class, CarbonImmutable::parse($validated['admitted_on']));

        return to_route('students.show', $student)->with('success', "{$student->name()} enrolled in {$class->name} ({$student->number}).");
    }

    public function show(Request $request, Student $student): Response
    {
        $student->load('schoolClass:id,name');
        $term = Workspace::term();

        $attendance = AttendanceEntry::query()
            ->where('student_id', $student->id)
            ->when($term !== null, fn ($query) => $query->whereHas('register', fn ($register) => $register->where('term_id', $term?->id)))
            ->selectRaw('status, count(*) as days')
            ->groupBy('status')
            ->pluck('days', 'status');

        $canFees = $this->can($request, 'school.fees.view');

        return Inertia::render('students/show', [
            'student' => [
                'id' => $student->id,
                'number' => $student->number,
                'name' => $student->name(),
                'firstName' => $student->first_name,
                'lastName' => $student->last_name,
                'gender' => $student->gender,
                'dateOfBirth' => $student->date_of_birth?->toDateString(),
                'class' => $student->schoolClass === null ? null : ['id' => $student->schoolClass->id, 'name' => $student->schoolClass->name],
                'status' => $student->status,
                'admittedOn' => $student->admitted_on->toDateString(),
                'phone' => $student->phone,
                'email' => $student->email,
                'address' => $student->address,
                'guardianName' => $student->guardian_name,
                'guardianPhone' => $student->guardian_phone,
                'guardianRelationship' => $student->guardian_relationship,
            ],
            'attendance' => [
                'term' => $term?->title(),
                'present' => (int) ($attendance[AttendanceEntry::PRESENT] ?? 0),
                'late' => (int) ($attendance[AttendanceEntry::LATE] ?? 0),
                'absent' => (int) ($attendance[AttendanceEntry::ABSENT] ?? 0),
                'excused' => (int) ($attendance[AttendanceEntry::EXCUSED] ?? 0),
            ],
            'invoices' => ! $canFees ? null : $student->invoices()->with('term.academicYear')->latest('issued_on')->latest()->get()
                ->map(fn (Invoice $invoice) => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'term' => $invoice->term?->title(),
                    'status' => $invoice->status,
                    'totalMinor' => $invoice->total_minor,
                    'balanceMinor' => $invoice->balanceMinor(),
                ]),
            'can' => [
                'manage' => $this->can($request, 'school.students.manage'),
                'results' => $this->can($request, 'school.results.view'),
            ],
        ]);
    }

    public function edit(Student $student): Response
    {
        return Inertia::render('students/form', [
            'student' => [
                'id' => $student->id,
                'number' => $student->number,
                'firstName' => $student->first_name,
                'lastName' => $student->last_name,
                'gender' => $student->gender,
                'dateOfBirth' => $student->date_of_birth?->toDateString(),
                'classId' => $student->school_class_id,
                'status' => $student->status,
                'phone' => $student->phone,
                'email' => $student->email,
                'address' => $student->address,
                'guardianName' => $student->guardian_name,
                'guardianPhone' => $student->guardian_phone,
                'guardianRelationship' => $student->guardian_relationship,
            ],
            'classes' => $this->classOptions(false),
            'classId' => null,
            'today' => today()->toDateString(),
        ]);
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $details = $this->details($request);
        $validated = $request->validate([
            'school_class_id' => ['required', 'uuid'],
            'status' => ['required', Rule::in(Student::STATUSES)],
        ]);
        $class = SchoolClass::query()->whereKey($validated['school_class_id'])->first() ?? throw ValidationException::withMessages(['school_class_id' => 'Choose a class.']);

        $student->forceFill([...$details, 'school_class_id' => $class->id, 'status' => $validated['status']])->save();

        return to_route('students.show', $student)->with('success', "{$student->name()} saved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function details(Request $request): array
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'gender' => ['required', Rule::in(Student::GENDERS)],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:32'],
            'guardian_relationship' => ['nullable', 'string', 'max:32'],
        ]);

        if ($this->category()->value === 'primary' && blank($validated['guardian_name'] ?? null)) {
            throw ValidationException::withMessages(['guardian_name' => 'A pupil needs a parent or guardian.']);
        }

        return [
            'first_name' => trim($validated['first_name']),
            'last_name' => trim($validated['last_name']),
            'gender' => $validated['gender'],
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'phone' => $this->text($validated['phone'] ?? null),
            'email' => $this->text($validated['email'] ?? null),
            'address' => $this->text($validated['address'] ?? null),
            'guardian_name' => $this->text($validated['guardian_name'] ?? null),
            'guardian_phone' => $this->text($validated['guardian_phone'] ?? null),
            'guardian_relationship' => $this->text($validated['guardian_relationship'] ?? null),
        ];
    }
}
