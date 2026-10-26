<?php

use App\Enums\SchoolCategory;
use App\Models\Application;
use App\Models\Assessment;
use App\Models\AttendanceEntry;
use App\Models\AttendanceRegister;
use App\Models\ClassSubject;
use App\Models\Invoice;
use App\Models\Programme;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\SharedModuleKit\Envelope;

/*
| School: set up as a primary school, institute, college or university;
| students admitted, enrolled, promoted and graduated; registers, marks and
| results graded the category's way; fees billed and paid, with Finance (and
| HR, for staff) told.
*/

const HEAD = [
    'school.students.view', 'school.students.manage', 'school.academics.manage',
    'school.attendance.record', 'school.marks.record', 'school.results.view',
    'school.fees.view', 'school.fees.manage', 'school.fees.void', 'school.settings.manage',
];

beforeEach(function () {
    config([
        'platform-client.modules.finance' => 'https://finance.internal/events',
        'platform-client.modules.hr' => 'https://hr.internal/events',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-03-02 09:00'));
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme Academy', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));
});

/**
 * @param  list<string>  $permissions
 */
function signIn(array $permissions = HEAD): User
{
    $member = test()->actingAsMember('acme', $permissions);
    assert($member instanceof User);

    return $member;
}

function setUpSchool(SchoolCategory $category, bool $withDefaults = true): void
{
    test()->post(test()->workspaceUrl('acme', 'setup'), [
        'category' => $category->value,
        'name' => '2026',
        'starts_on' => '2026-02-01',
        'ends_on' => '2026-12-15',
        'with_defaults' => $withDefaults,
    ])->assertSessionHasNoErrors();
}

/**
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function inSchool(Closure $callback): mixed
{
    return test()->asTenant('acme', $callback);
}

function primaryClass(int $level = 4): SchoolClass
{
    return inSchool(fn () => SchoolClass::query()->where('level', $level)->firstOrFail());
}

/**
 * @param  array<string, mixed>  $details
 */
function enrol(SchoolClass $class, array $details = []): Student
{
    test()->post(test()->workspaceUrl('acme', 'students'), [
        'first_name' => 'Achol',
        'last_name' => 'Deng',
        'gender' => 'female',
        'guardian_name' => 'Mary Deng',
        'guardian_phone' => '0912000000',
        'school_class_id' => $class->id,
        'admitted_on' => '2026-02-01',
        ...$details,
    ])->assertSessionHasNoErrors();

    return inSchool(fn () => Student::query()->latest('number')->firstOrFail());
}

function currentTerm(): Term
{
    return inSchool(fn () => Workspace::term() ?? throw new RuntimeException('No current term'));
}

/**
 * @return list<string>
 */
function eventsTo(string $module): array
{
    return array_map(fn (array $event) => Envelope::fromArray($event)->eventType, test()->platform()->moduleEvents[$module] ?? []);
}

describe('setup', function () {
    it('offers the setup until the school has a category', function () {
        signIn();

        $this->get($this->workspaceUrl('acme'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('home')->where('setUp', false)->where('canSetUp', true)->where('school', null));
        $this->get($this->workspaceUrl('acme', 'students'))->assertRedirect($this->workspaceUrl('acme'));
    });

    it('sets a primary school up with three terms, Primary 1 to 8 and the common subjects', function () {
        signIn();

        setUpSchool(SchoolCategory::Primary);

        $terms = inSchool(fn () => Term::query()->orderBy('sequence')->get());
        expect($terms->pluck('name')->all())->toBe(['Term 1', 'Term 2', 'Term 3'])
            ->and($terms->first()->starts_on->toDateString())->toBe('2026-02-01')
            ->and($terms->last()->ends_on->toDateString())->toBe('2026-12-15')
            ->and(currentTerm()->id)->toBe($terms->first()->id)
            ->and(inSchool(fn () => SchoolClass::query()->orderBy('level')->pluck('name')->all()))->toBe(['Primary 1', 'Primary 2', 'Primary 3', 'Primary 4', 'Primary 5', 'Primary 6', 'Primary 7', 'Primary 8'])
            ->and(inSchool(fn () => ClassSubject::query()->count()))->toBe(8 * 6)
            ->and(inSchool(fn () => Assessment::query()->where('term_id', $terms->first()->id)->sum('weight')))->toBe(100);

        $this->get($this->workspaceUrl('acme'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setUp', true)
                ->where('school.value', 'primary')
                ->where('school.period', 'Term')
                ->where('school.subject', 'Subject')
                ->where('school.term.title', 'Term 1, 2026'));
    });

    it('sets a university up with two semesters and the university words', function () {
        signIn();

        setUpSchool(SchoolCategory::University);

        expect(inSchool(fn () => Term::query()->orderBy('sequence')->pluck('name')->all()))->toBe(['Semester 1', 'Semester 2'])
            ->and(inSchool(fn () => SchoolClass::query()->count()))->toBe(0);
        $this->get($this->workspaceUrl('acme'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('school.subject', 'Course')
                ->where('school.usesFaculties', true)
                ->where('school.usesCredits', true));
    });

    it('sets the school up once, and only for members who may', function () {
        signIn(['school.students.view']);
        $this->post($this->workspaceUrl('acme', 'setup'), ['category' => 'primary'])->assertForbidden();

        signIn();
        setUpSchool(SchoolCategory::Institute);
        $this->post($this->workspaceUrl('acme', 'setup'), ['category' => 'primary', 'name' => '2027', 'starts_on' => '2027-02-01', 'ends_on' => '2027-12-15'])
            ->assertSessionHasErrors('category');
        expect(inSchool(fn () => Workspace::category()))->toBe(SchoolCategory::Institute);
    });

    it('changes the category only while there are no students', function () {
        signIn();
        setUpSchool(SchoolCategory::Primary);

        $this->put($this->workspaceUrl('acme', 'settings/category'), ['category' => 'college'])->assertSessionHasNoErrors();
        expect(inSchool(fn () => Workspace::category()))->toBe(SchoolCategory::College);

        $this->put($this->workspaceUrl('acme', 'settings/category'), ['category' => 'primary'])->assertSessionHasNoErrors();
        enrol(primaryClass());
        $this->put($this->workspaceUrl('acme', 'settings/category'), ['category' => 'university'])->assertSessionHasErrors('category');
    });

    it('keeps a term\'s assessment weights within 100', function () {
        signIn();
        setUpSchool(SchoolCategory::Primary);
        $term = currentTerm();

        $this->post($this->workspaceUrl('acme', "settings/terms/{$term->id}/assessments"), ['name' => 'Quiz', 'weight' => 10])
            ->assertSessionHasErrors('weight');
    });
});

describe('students', function () {
    beforeEach(function () {
        signIn();
        setUpSchool(SchoolCategory::Primary);
    });

    it('enrols pupils with admission numbers', function () {
        $first = enrol(primaryClass());
        $second = enrol(primaryClass(), ['first_name' => 'Lado']);

        expect($first)->number->toBe('ADM-00001')->status->toBe(Student::ACTIVE)
            ->and($second->number)->toBe('ADM-00002');
    });

    it('needs a guardian for a primary pupil', function () {
        $this->post($this->workspaceUrl('acme', 'students'), [
            'first_name' => 'Achol', 'last_name' => 'Deng', 'gender' => 'female',
            'school_class_id' => primaryClass()->id, 'admitted_on' => '2026-02-01',
        ])->assertSessionHasErrors('guardian_name');
    });

    it('refuses a full class', function () {
        $class = primaryClass();
        inSchool(fn () => $class->forceFill(['capacity' => 1])->save());
        enrol($class);

        $this->post($this->workspaceUrl('acme', 'students'), [
            'first_name' => 'Lado', 'last_name' => 'Deng', 'gender' => 'male', 'guardian_name' => 'Mary Deng',
            'school_class_id' => $class->id, 'admitted_on' => '2026-02-01',
        ])->assertSessionHasErrors('school_class_id');
    });

    it('admits an application into a class, or declines it', function () {
        $this->post($this->workspaceUrl('acme', 'applications'), [
            'first_name' => 'Nyibol', 'last_name' => 'Garang', 'gender' => 'female',
            'guardian_name' => 'Peter Garang', 'level' => 1,
        ])->assertSessionHasNoErrors();
        $application = inSchool(fn () => Application::query()->sole());
        expect($application)->number->toBe('APP-00001')->status->toBe(Application::PENDING);

        $this->post($this->workspaceUrl('acme', "applications/{$application->id}/admit"), ['school_class_id' => primaryClass(1)->id])
            ->assertSessionHasNoErrors();

        $application = inSchool(fn () => $application->fresh());
        expect($application->status)->toBe(Application::ADMITTED)
            ->and(inSchool(fn () => Student::query()->findOrFail($application->student_id)))
            ->first_name->toBe('Nyibol')
            ->guardian_name->toBe('Peter Garang')
            ->school_class_id->toBe(primaryClass(1)->id);

        $this->post($this->workspaceUrl('acme', "applications/{$application->id}/decline"), ['note' => 'Twice'])
            ->assertSessionHasErrors('application');
    });

    it('promotes pupils to the next class and graduates the final class', function () {
        $pupil = enrol(primaryClass(4));
        $leaver = enrol(primaryClass(8));

        $this->post($this->workspaceUrl('acme', 'classes/'.primaryClass(4)->id.'/promote'), ['to_class_id' => primaryClass(5)->id, 'student_ids' => [$pupil->id]])
            ->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', 'classes/'.primaryClass(8)->id.'/promote'), ['to_class_id' => null, 'student_ids' => [$leaver->id]])
            ->assertSessionHasNoErrors();

        expect(inSchool(fn () => $pupil->fresh())->school_class_id)->toBe(primaryClass(5)->id)
            ->and(inSchool(fn () => $leaver->fresh())->status)->toBe(Student::GRADUATED);
    });

    it('lists only the workspace\'s own students', function () {
        enrol(primaryClass());
        $this->asTenant('globex', fn () => Student::query()->create([
            'number' => 'ADM-00001', 'first_name' => 'Other', 'last_name' => 'School', 'gender' => 'male',
            'status' => Student::ACTIVE, 'admitted_on' => '2026-02-01',
        ]));

        $this->get($this->workspaceUrl('acme', 'students'))
            ->assertInertia(fn (Assert $page) => $page->component('students/index')->has('students', 1)->where('students.0.name', 'Achol Deng'));
    });

    it('keeps teachers to registers, marks and results', function () {
        signIn(['school.students.view', 'school.attendance.record', 'school.marks.record', 'school.results.view']);

        $this->get($this->workspaceUrl('acme', 'students'))->assertOk();
        $this->get($this->workspaceUrl('acme', 'marks'))->assertOk();
        $this->get($this->workspaceUrl('acme', 'students/create'))->assertForbidden();
        $this->get($this->workspaceUrl('acme', 'fees'))->assertForbidden();
        $this->get($this->workspaceUrl('acme', 'settings'))->assertForbidden();
    });

    it('tells HR of each member of staff added', function () {
        $this->post($this->workspaceUrl('acme', 'staff'), ['name' => 'James Lado', 'position' => 'Teacher', 'email' => 'james@acme.test', 'started_on' => '2026-02-01'])
            ->assertSessionHasNoErrors();

        expect(eventsTo('hr'))->toBe(['hr.employee.joined']);
        $event = Envelope::fromArray($this->platform()->moduleEvents['hr'][0]);
        expect($event->payload)->toMatchArray(['employee_reference' => 'STF-00001', 'display_name' => 'James Lado', 'started_on' => '2026-02-01']);
    });
});

describe('attendance', function () {
    it('takes a primary register once a day, replacing it when taken again', function () {
        signIn();
        setUpSchool(SchoolCategory::Primary);
        $achol = enrol(primaryClass());
        $lado = enrol(primaryClass(), ['first_name' => 'Lado']);
        $register = fn (array $statuses) => $this->post($this->workspaceUrl('acme', 'attendance'), [
            'school_class_id' => primaryClass()->id, 'taken_on' => '2026-03-02', 'statuses' => $statuses,
        ]);

        $register([$achol->id => 'present', $lado->id => 'absent'])->assertSessionHasNoErrors();
        $register([$achol->id => 'present', $lado->id => 'late'])->assertSessionHasNoErrors();

        expect(inSchool(fn () => AttendanceRegister::query()->count()))->toBe(1)
            ->and(inSchool(fn () => AttendanceEntry::query()->where('student_id', $lado->id)->sole()->status))->toBe('late');
        $register([$achol->id => 'present'])->assertSessionHasErrors('statuses');
    });

    it('takes registers per course at a university', function () {
        signIn();
        setUpSchool(SchoolCategory::University);
        $programme = inSchool(fn () => Programme::query()->create(['code' => 'BCS', 'name' => 'BSc Computer Science', 'award' => 'bachelor', 'duration_years' => 4]));
        $class = inSchool(fn () => SchoolClass::query()->create(['name' => 'BCS Year 1', 'level' => 1, 'programme_id' => $programme->id]));
        $course = inSchool(fn () => Subject::query()->create(['code' => 'CS101', 'name' => 'Programming', 'credit_hours' => 4]));
        inSchool(fn () => ClassSubject::query()->create(['school_class_id' => $class->id, 'subject_id' => $course->id]));
        $student = enrol($class, ['guardian_name' => null]);

        expect($student->number)->toBe('REG-00001');
        $this->post($this->workspaceUrl('acme', 'attendance'), ['school_class_id' => $class->id, 'taken_on' => '2026-03-02', 'statuses' => [$student->id => 'present']])
            ->assertSessionHasErrors('subject_id');
        $this->post($this->workspaceUrl('acme', 'attendance'), ['school_class_id' => $class->id, 'subject_id' => $course->id, 'taken_on' => '2026-03-02', 'statuses' => [$student->id => 'present']])
            ->assertSessionHasNoErrors();
    });
});

describe('teaching', function () {
    beforeEach(function () {
        signIn();
        setUpSchool(SchoolCategory::Primary);
        $this->p4 = primaryClass(4);
        $this->p5 = primaryClass(5);
        $this->pupil4 = enrol($this->p4);
        $this->pupil5 = enrol($this->p5);
        $this->post($this->workspaceUrl('acme', 'staff'), ['name' => 'Grace Akol', 'position' => 'Teacher', 'email' => 'Grace@Acme.test', 'started_on' => '2026-02-01'])
            ->assertSessionHasNoErrors();
        [$this->maths, $this->english] = inSchool(fn () => [
            Subject::query()->where('code', 'MATH')->firstOrFail(),
            Subject::query()->where('code', 'ENG')->firstOrFail(),
        ]);
        inSchool(function () {
            $grace = Staff::query()->sole();
            $this->p4->forceFill(['class_teacher_id' => $grace->id])->save();
            ClassSubject::query()->where('school_class_id', $this->p5->id)->where('subject_id', $this->maths->id)->update(['teacher_id' => $grace->id]);
        });
        $this->assessment = inSchool(fn () => currentTerm()->assessments()->firstOrFail());
        $this->teacher = fn (string $email) => $this->actingAsMember('acme', ['school.students.view', 'school.attendance.record', 'school.marks.record', 'school.results.view'], user: $this->platformUser(['email' => $email]));
        $this->register = fn (SchoolClass $class, Student $pupil) => $this->post($this->workspaceUrl('acme', 'attendance'), [
            'school_class_id' => $class->id, 'taken_on' => '2026-03-02', 'statuses' => [$pupil->id => 'present'],
        ]);
        $this->marks = fn (SchoolClass $class, Subject $subject, Student $pupil) => $this->post($this->workspaceUrl('acme', 'marks'), [
            'assessment_id' => $this->assessment->id, 'school_class_id' => $class->id, 'subject_id' => $subject->id, 'scores' => [$pupil->id => '70'],
        ]);
    });

    it('keeps a teacher to their own class and the subjects they teach elsewhere', function () {
        ($this->teacher)('grace@acme.test');

        $this->get($this->workspaceUrl('acme', 'marks')."?class={$this->p5->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('linked', true)
                ->where('classes', [['value' => $this->p4->id, 'label' => $this->p4->name], ['value' => $this->p5->id, 'label' => $this->p5->name]])
                ->has('subjects', 1)
                ->where('subjects.0.value', $this->maths->id));

        ($this->register)($this->p4, $this->pupil4)->assertSessionHasNoErrors();
        ($this->register)($this->p5, $this->pupil5)->assertForbidden();
        ($this->marks)($this->p4, $this->english, $this->pupil4)->assertSessionHasNoErrors();
        ($this->marks)($this->p5, $this->maths, $this->pupil5)->assertSessionHasNoErrors();
        ($this->marks)($this->p5, $this->english, $this->pupil5)->assertForbidden();
    });

    it('gives a teacher who is not on the staff list no classes', function () {
        ($this->teacher)('someone@acme.test');

        $this->get($this->workspaceUrl('acme', 'attendance'))
            ->assertInertia(fn (Assert $page) => $page->where('linked', false)->where('classes', []));
        ($this->register)($this->p4, $this->pupil4)->assertForbidden();
        ($this->marks)($this->p4, $this->english, $this->pupil4)->assertForbidden();
    });

    it('lets members who manage academics record for every class', function () {
        ($this->register)($this->p5, $this->pupil5)->assertSessionHasNoErrors();
        ($this->marks)($this->p5, $this->english, $this->pupil5)->assertSessionHasNoErrors();
    });
});

describe('results', function () {
    it('weighs a primary term\'s assessments, grades them and ranks the class', function () {
        signIn();
        setUpSchool(SchoolCategory::Primary);
        $class = primaryClass();
        $achol = enrol($class);
        $lado = enrol($class, ['first_name' => 'Lado']);
        [$midTerm, $endOfTerm] = inSchool(fn () => currentTerm()->assessments()->get()->all());
        $english = inSchool(fn () => Subject::query()->where('code', 'ENG')->firstOrFail());
        $marks = fn (Assessment $assessment, array $scores) => $this->post($this->workspaceUrl('acme', 'marks'), [
            'assessment_id' => $assessment->id, 'school_class_id' => $class->id, 'subject_id' => $english->id, 'scores' => $scores,
        ]);

        $marks($midTerm, [$achol->id => '60', $lado->id => '90'])->assertSessionHasNoErrors();
        $marks($endOfTerm, [$achol->id => '90', $lado->id => '70'])->assertSessionHasNoErrors();
        $marks($endOfTerm, [$achol->id => '101'])->assertSessionHasErrors("scores.{$achol->id}");

        // Achol: 60 × 30% + 90 × 70% = 81 (A); Lado: 90 × 30% + 70 × 70% = 76 (B).
        $this->get($this->workspaceUrl('acme', "results?class={$class->id}"))
            ->assertInertia(fn (Assert $page) => $page
                ->component('results/index')
                ->where('results.0.name', 'Achol Deng')
                ->where('results.0.position', 1)
                ->where('results.0.subjects.0.name', 'Arabic')
                ->where('results.0.subjects.0.mark', null)
                ->where('results.1.position', 2));

        $this->get($this->workspaceUrl('acme', "students/{$achol->id}/report"))
            ->assertInertia(fn (Assert $page) => $page
                ->component('results/show')
                ->where('report.position', 1)
                ->where('classSize', 2)
                ->where('report.subjects', fn ($subjects) => (float) collect($subjects)->firstWhere('code', 'ENG')['mark'] === 81.0
                    && collect($subjects)->firstWhere('code', 'ENG')['grade'] === 'A'
                    && collect($subjects)->firstWhere('code', 'ENG')['complete'] === true));
    });

    it('works out a GPA weighted by credit hours at a university', function () {
        signIn();
        setUpSchool(SchoolCategory::University);
        $programme = inSchool(fn () => Programme::query()->create(['code' => 'BCS', 'name' => 'BSc Computer Science', 'award' => 'bachelor', 'duration_years' => 4]));
        $class = inSchool(fn () => SchoolClass::query()->create(['name' => 'BCS Year 1', 'level' => 1, 'programme_id' => $programme->id]));
        $student = enrol($class, ['guardian_name' => null]);
        [$coursework, $exam] = inSchool(fn () => currentTerm()->assessments()->get()->all());

        // A (5.0) in a 4-credit course, C (3.0) in a 2-credit one: (20 + 6) / 6 = 4.33.
        foreach ([['CS101', 4, '80'], ['MTH101', 2, '60']] as [$code, $credits, $score]) {
            $course = inSchool(fn () => Subject::query()->create(['code' => $code, 'name' => $code, 'credit_hours' => $credits]));
            inSchool(fn () => ClassSubject::query()->create(['school_class_id' => $class->id, 'subject_id' => $course->id]));
            foreach ([$coursework, $exam] as $assessment) {
                $this->post($this->workspaceUrl('acme', 'marks'), ['assessment_id' => $assessment->id, 'school_class_id' => $class->id, 'subject_id' => $course->id, 'scores' => [$student->id => $score]])
                    ->assertSessionHasNoErrors();
            }
        }

        $this->get($this->workspaceUrl('acme', "students/{$student->id}/report"))
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.gpa', 4.33)
                ->where('report.credits', 6)
                ->where('report.position', null)
                ->where('cgpa', 4.33));
    });
});

describe('fees', function () {
    beforeEach(function () {
        signIn();
        setUpSchool(SchoolCategory::Primary);
    });

    it('bills each enrolled pupil once a term, with the fees of their class', function () {
        $term = currentTerm();
        $p4 = enrol(primaryClass(4));
        $p8 = enrol(primaryClass(8), ['first_name' => 'Lado']);
        $this->post($this->workspaceUrl('acme', 'fees/items'), ['term_id' => $term->id, 'name' => 'Tuition', 'amount' => '50000'])->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', 'fees/items'), ['term_id' => $term->id, 'school_class_id' => primaryClass(8)->id, 'name' => 'Exam fee', 'amount' => '10000'])->assertSessionHasNoErrors();

        $this->post($this->workspaceUrl('acme', 'fees/bill'), ['term_id' => $term->id])->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', 'fees/bill'), ['term_id' => $term->id])->assertSessionHasNoErrors();

        $invoices = inSchool(fn () => Invoice::query()->orderBy('number')->get());
        expect($invoices)->toHaveCount(2)
            ->and($invoices->firstWhere('student_id', $p4->id)->total_minor)->toBe(5000000)
            ->and($invoices->firstWhere('student_id', $p8->id)->total_minor)->toBe(6000000)
            ->and(eventsTo('finance'))->toBe(['finance.invoice.issued', 'finance.invoice.issued']);
    });

    it('takes payments up to the balance, and voids only unpaid invoices', function () {
        $term = currentTerm();
        enrol(primaryClass());
        $this->post($this->workspaceUrl('acme', 'fees/items'), ['term_id' => $term->id, 'name' => 'Tuition', 'amount' => '500'])->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', 'fees/bill'), ['term_id' => $term->id])->assertSessionHasNoErrors();
        $invoice = inSchool(fn () => Invoice::query()->sole());
        $pay = fn (string $amount) => $this->post($this->workspaceUrl('acme', "invoices/{$invoice->id}/payments"), ['amount' => $amount, 'method' => 'cash', 'paid_on' => '2026-03-02']);

        $pay('600')->assertSessionHasErrors('amount');
        $pay('200')->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', "invoices/{$invoice->id}/void"), ['reason' => 'Wrong'])->assertSessionHasErrors('reason');
        $pay('300')->assertSessionHasNoErrors();

        expect(inSchool(fn () => $invoice->fresh()))->status->toBe(Invoice::PAID)->paid_minor->toBe(50000)
            ->and(eventsTo('finance'))->toBe(['finance.invoice.issued', 'finance.payment.received', 'finance.payment.received']);
    });

    it('bills a pupil again after their invoice is voided', function () {
        $term = currentTerm();
        enrol(primaryClass());
        $this->post($this->workspaceUrl('acme', 'fees/items'), ['term_id' => $term->id, 'name' => 'Tuition', 'amount' => '500'])->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', 'fees/bill'), ['term_id' => $term->id])->assertSessionHasNoErrors();
        $invoice = inSchool(fn () => Invoice::query()->sole());

        $this->post($this->workspaceUrl('acme', "invoices/{$invoice->id}/void"), ['reason' => 'Wrong amount'])->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', 'fees/bill'), ['term_id' => $term->id])->assertSessionHasNoErrors();

        expect(inSchool(fn () => Invoice::query()->where('status', Invoice::ISSUED)->count()))->toBe(1)
            ->and(eventsTo('finance'))->toBe(['finance.invoice.issued', 'finance.invoice.voided', 'finance.invoice.issued']);
    });
});
