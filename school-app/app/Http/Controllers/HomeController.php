<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\AttendanceEntry;
use App\Models\AttendanceRegister;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The school at a glance: students, today's registers and fees, each only
 * for members who may see them. Before setup, the way to set it up.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workspace = Workspace::current();

        if ($workspace?->category === null) {
            return Inertia::render('home', [
                'setUp' => false,
                'canSetUp' => $this->can($request, 'school.settings.manage'),
            ]);
        }

        $term = $workspace->currentTerm;
        $students = $this->can($request, 'school.students.view');
        $fees = $this->can($request, 'school.fees.view');
        $today = AttendanceRegister::query()->whereDate('taken_on', today());
        $open = Invoice::query()->where('status', Invoice::ISSUED);

        return Inertia::render('home', [
            'setUp' => true,
            'canSetUp' => false,
            'students' => ! $students ? null : [
                'enrolled' => Student::query()->where('status', Student::ACTIVE)->count(),
                'female' => Student::query()->where('status', Student::ACTIVE)->where('gender', 'female')->count(),
                'classes' => SchoolClass::query()->where('active', true)->count(),
                'staff' => Staff::query()->where('active', true)->count(),
                'applications' => Application::query()->where('status', Application::PENDING)->count(),
            ],
            'attendance' => ! $students ? null : [
                'registersToday' => (clone $today)->count(),
                'absentToday' => AttendanceEntry::query()
                    ->whereIn('attendance_register_id', (clone $today)->select('id'))
                    ->where('status', AttendanceEntry::ABSENT)
                    ->distinct()
                    ->count('student_id'),
            ],
            'fees' => ! $fees || $term === null ? null : [
                'outstandingMinor' => (int) (clone $open)->sum('total_minor') - (int) (clone $open)->sum('paid_minor'),
                'collectedMinor' => (int) Payment::query()->whereBetween('paid_on', [$term->starts_on, $term->ends_on])->sum('amount_minor'),
                'notBilled' => Student::query()
                    ->where('status', Student::ACTIVE)
                    ->whereNotIn('id', Invoice::query()->where('term_id', $term->id)->where('status', '!=', Invoice::VOIDED)->select('student_id'))
                    ->count(),
                'overdue' => (clone $open)->whereNotNull('due_on')->where('due_on', '<', today())->count(),
            ],
        ]);
    }
}
