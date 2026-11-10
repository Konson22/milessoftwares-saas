<?php

namespace Database\Seeders;

use App\Actions\Attendance;
use App\Actions\Calendar;
use App\Actions\Enrolment;
use App\Actions\Fees;
use App\Actions\Marks;
use App\Actions\Staffing;
use App\Enums\PaymentMethod;
use App\Enums\SchoolCategory;
use App\Models\Assessment;
use App\Models\AttendanceEntry;
use App\Models\ClassSubject;
use App\Models\Department;
use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\Programme;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Platform\SetUpWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use RuntimeException;

/**
 * The water training institute of the demo business central's
 * DemoBusinessSeeder made (DEMO_BUSINESS_FILE): programmes, trainees, marks,
 * registers and fees for 2026, entered through the app's services with the
 * clock set back. Amounts are in cents.
 */
class DemoBusinessSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $team = [];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoBusinessSeeder only runs with APP_ENV=local.');
        }

        $data = json_decode((string) file_get_contents((string) getenv('DEMO_BUSINESS_FILE')), true, flags: JSON_THROW_ON_ERROR);
        $organization = Organization::fromArray($data['organization']);

        app(CurrentTenant::class)->run($organization, function () use ($data, $organization) {
            try {
                DB::transaction(function () use ($data, $organization) {
                    app(SetUpWorkspace::class)->provision($organization);

                    foreach ($data['users'] as $key => $user) {
                        $member = new User;
                        $member->forceFill($user)->save();
                        $this->team[$key] = $member;
                    }

                    $this->seedInstitute();
                    $this->holdBackEvents();
                });
            } finally {
                $this->clock(null);
            }
        });
    }

    private function seedInstitute(): void
    {
        $this->clock('2026-02-02 08:00');

        app(Calendar::class)->setUp(SchoolCategory::Institute, '2026', CarbonImmutable::parse('2026-02-02'), CarbonImmutable::parse('2026-12-18'), false);

        $this->clock(null);
        app(Calendar::class)->makeCurrent(Term::query()->whereDate('starts_on', '<=', '2026-11-04')->whereDate('ends_on', '>=', '2026-11-04')->sole());
        $this->clock('2026-02-02 08:00');

        $water = Department::query()->create(['name' => 'Water Supply', 'code' => 'WSP']);
        $quality = Department::query()->create(['name' => 'Water Quality', 'code' => 'WQL']);

        $diploma = Programme::query()->create(['department_id' => $water->id, 'code' => 'DWS', 'name' => 'Water Supply Engineering', 'award' => 'diploma', 'duration_years' => 2, 'active' => true]);
        $certificate = Programme::query()->create(['department_id' => $water->id, 'code' => 'CPO', 'name' => 'Pump Operation and Maintenance', 'award' => 'certificate', 'duration_years' => 1, 'active' => true]);
        $laboratory = Programme::query()->create(['department_id' => $quality->id, 'code' => 'CWQ', 'name' => 'Water Quality Testing', 'award' => 'certificate', 'duration_years' => 1, 'active' => true]);

        $staffing = app(Staffing::class);
        $head = $staffing->hire(['name' => $this->team['school_head']->name, 'position' => 'Head of the Institute', 'department_id' => $water->id, 'phone' => '+211 923 000 401', 'email' => $this->team['school_head']->email, 'started_on' => '2021-01-11']);
        $trainer = $staffing->hire(['name' => $this->team['teacher']->name, 'position' => 'Senior Trainer, Water Supply', 'department_id' => $water->id, 'phone' => '+211 923 000 402', 'email' => $this->team['teacher']->email, 'started_on' => '2022-02-07']);
        $chemist = $staffing->hire(['name' => 'Amou Kuol', 'position' => 'Trainer, Water Quality', 'department_id' => $quality->id, 'phone' => '+211 923 000 403', 'email' => 'amou.kuol@ssuwc.gov.ss', 'started_on' => '2023-08-14']);
        $mechanic = $staffing->hire(['name' => 'Tito Kenyi', 'position' => 'Trainer, Pumps', 'department_id' => $water->id, 'phone' => '+211 923 000 404', 'email' => 'tito.kenyi@ssuwc.gov.ss', 'started_on' => '2024-02-05']);

        $subject = fn (string $code, string $name, int $credits) => Subject::query()->create(['code' => $code, 'name' => $name, 'credit_hours' => $credits, 'active' => true]);
        $hydraulics = $subject('WS101', 'Hydraulics', 4);
        $networks = $subject('WS102', 'Distribution Networks', 3);
        $pumps = $subject('PO101', 'Pumps and Motors', 4);
        $safety = $subject('GN101', 'Safety at Work', 2);
        $chemistry = $subject('WQ101', 'Water Chemistry', 4);
        $sampling = $subject('WQ102', 'Sampling and Testing', 3);

        $classes = [];
        foreach ([
            [$diploma, 'DWS Year 1', 1, $trainer, 12, [[$hydraulics, $trainer], [$networks, $trainer], [$safety, $head]], 95000],
            [$certificate, 'CPO 2026', 1, $mechanic, 10, [[$pumps, $mechanic], [$safety, $head]], 60000],
            [$laboratory, 'CWQ 2026', 1, $chemist, 10, [[$chemistry, $chemist], [$sampling, $chemist], [$safety, $head]], 65000],
        ] as [$programme, $name, $level, $teacher, $capacity, $subjects, $tuition]) {
            $class = SchoolClass::query()->create(['name' => $name, 'level' => $level, 'programme_id' => $programme->id, 'class_teacher_id' => $teacher->id, 'capacity' => $capacity, 'active' => true]);
            foreach ($subjects as [$taught, $by]) {
                ClassSubject::query()->create(['school_class_id' => $class->id, 'subject_id' => $taught->id, 'teacher_id' => $by->id]);
            }
            $classes[] = ['class' => $class, 'subjects' => array_column($subjects, 0), 'tuition' => $tuition];
        }

        $this->seedTrainees($classes);
        $this->seedFees($classes);
        $this->seedMarks($classes);
        $this->seedRegisters($classes);
        $this->seedApplications($diploma, $certificate);

        $this->clock(null);
    }

    /**
     * @param  list<array{class: SchoolClass, subjects: list<Subject>, tuition: int}>  $classes
     */
    private function seedTrainees(array $classes): void
    {
        $names = [
            ['Achol', 'Dut', 'female'], ['Kuol', 'Majok', 'male'], ['Nyabol', 'Riek', 'female'], ['Lado', 'Sebit', 'male'],
            ['Poni', 'Yengi', 'female'], ['Wani', 'Bullen', 'male'], ['Keji', 'Lomoro', 'female'], ['Deng', 'Alier', 'male'],
            ['Amani', 'Tombura', 'female'], ['Gatluak', 'Puok', 'male'], ['Yar', 'Thon', 'female'], ['Modi', 'Taban', 'male'],
            ['Sunday', 'Kenyi', 'male'], ['Nyakim', 'Gatwech', 'female'], ['Jok', 'Ayuel', 'male'], ['Ayen', 'Malual', 'female'],
            ['Bosco', 'Lemi', 'male'], ['Night', 'Ajonye', 'female'], ['Garang', 'Atem', 'male'], ['Aluel', 'Mading', 'female'],
            ['Ladu', 'Morris', 'male'], ['Kiden', 'Sworo', 'female'], ['Chuol', 'Gai', 'male'], ['Rita', 'Akot', 'female'],
            ['Malish', 'Duku', 'male'], ['Nyandit', 'Kur', 'female'],
        ];
        $enrolment = app(Enrolment::class);

        foreach ($names as $index => [$first, $last, $gender]) {
            $enrolment->enrol([
                'first_name' => $first,
                'last_name' => $last,
                'gender' => $gender,
                'date_of_birth' => (1996 + $index % 9).'-'.str_pad((string) (1 + $index % 12), 2, '0', STR_PAD_LEFT).'-15',
                'phone' => '+211 924 '.str_pad((string) (200000 + $index * 311), 6, '0', STR_PAD_LEFT),
                'address' => ['Munuki', 'Kator', 'Gudele', 'Juba Town', 'Sherikat'][$index % 5].', Juba',
                'guardian_name' => $last.' family',
                'guardian_phone' => '+211 925 '.str_pad((string) (300000 + $index * 173), 6, '0', STR_PAD_LEFT),
                'guardian_relationship' => 'Parent',
            ], $classes[$index % 3]['class'], CarbonImmutable::parse('2026-02-02'));
        }
    }

    /**
     * @param  list<array{class: SchoolClass, subjects: list<Subject>, tuition: int}>  $classes
     */
    private function seedFees(array $classes): void
    {
        $fees = app(Fees::class);
        $bursar = $this->team['bursar'];

        foreach (Term::query()->orderBy('sequence')->get() as $term) {
            foreach ($classes as $class) {
                FeeItem::query()->create(['term_id' => $term->id, 'school_class_id' => $class['class']->id, 'name' => 'Tuition', 'amount_minor' => $class['tuition']]);
                FeeItem::query()->create(['term_id' => $term->id, 'school_class_id' => $class['class']->id, 'name' => 'Practical workshop', 'amount_minor' => 15000]);
            }
            FeeItem::query()->create(['term_id' => $term->id, 'school_class_id' => null, 'name' => 'Registration', 'amount_minor' => 5000]);

            $first = $term->sequence === 1;
            $this->clock($term->starts_on->toDateString().' 09:00');
            $invoices = $fees->bill($term, null, CarbonImmutable::parse($term->starts_on)->addDays(30), $bursar);

            foreach ($invoices as $index => $invoice) {
                /** @var Invoice $invoice */
                $paidOn = CarbonImmutable::parse($term->starts_on)->addDays(7 + $index);
                $this->clock($paidOn->toDateString().' 10:30');
                $method = $index % 3 === 0 ? PaymentMethod::MobileMoney : PaymentMethod::BankTransfer;

                if ($first) {
                    // Everyone paid the first semester, the last two in part.
                    $amount = $index >= count($invoices) - 2 ? intdiv($invoice->total_minor, 2) : $invoice->total_minor;
                } elseif ($index % 4 === 3) {
                    continue;
                } else {
                    // The second semester: some paid in full, some in part, some not yet.
                    $amount = $index % 4 === 2 ? intdiv($invoice->total_minor, 2) : $invoice->total_minor;
                }

                $fees->pay($invoice, $amount, $method, 'FEE-'.$term->sequence.str_pad((string) $index, 3, '0', STR_PAD_LEFT), $paidOn, $bursar);
            }
        }
    }

    /**
     * Both assessments of the first semester, coursework so far in the second.
     *
     * @param  list<array{class: SchoolClass, subjects: list<Subject>, tuition: int}>  $classes
     */
    private function seedMarks(array $classes): void
    {
        $marks = app(Marks::class);
        $teacher = $this->team['teacher'];
        $this->clock('2026-10-16 15:00');

        foreach (Term::query()->orderBy('sequence')->get() as $term) {
            foreach (Assessment::query()->where('term_id', $term->id)->orderBy('position')->get() as $assessment) {
                if ($term->sequence === 2 && $assessment->position > 1) {
                    continue;
                }

                foreach ($classes as $class) {
                    $students = Student::query()->where('school_class_id', $class['class']->id)->orderBy('number')->pluck('id')->all();

                    foreach ($class['subjects'] as $s => $subject) {
                        $scores = [];
                        foreach ($students as $i => $student) {
                            $scores[$student] = (string) (48 + (($i * 7 + $s * 11 + $assessment->position * 5 + $term->sequence * 3) % 47));
                        }
                        $marks->record($assessment, $class['class'], $subject, $scores, $teacher);
                    }
                }
            }
        }
    }

    /**
     * Registers for the last two weeks.
     *
     * @param  list<array{class: SchoolClass, subjects: list<Subject>, tuition: int}>  $classes
     */
    private function seedRegisters(array $classes): void
    {
        $attendance = app(Attendance::class);
        $teacher = $this->team['teacher'];

        for ($n = 0, $day = CarbonImmutable::parse('2026-10-22'); $day->lte('2026-11-04'); $day = $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            $this->clock($day->setTime(9, 0)->toDateTimeString());

            foreach ($classes as $class) {
                $statuses = [];
                foreach (Student::query()->where('school_class_id', $class['class']->id)->orderBy('number')->pluck('id')->all() as $student) {
                    $n++;
                    $statuses[$student] = match (true) {
                        $n % 23 === 0 => AttendanceEntry::ABSENT,
                        $n % 17 === 0 => AttendanceEntry::LATE,
                        $n % 41 === 0 => AttendanceEntry::EXCUSED,
                        default => AttendanceEntry::PRESENT,
                    };
                }
                $attendance->take($class['class'], $class['subjects'][0], $day, $statuses, $teacher);
            }
        }
    }

    private function seedApplications(Programme $diploma, Programme $certificate): void
    {
        $enrolment = app(Enrolment::class);
        $registrar = $this->team['registrar'];
        $this->clock('2026-10-26 11:00');

        foreach ([
            ['Lual', 'Ring', 'male', '2001-03-09', 'Juba Day Secondary', $diploma],
            ['Sunday', 'Poni', 'female', '2003-07-21', 'Yei Girls Secondary', $diploma],
            ['Mabior', 'Chol', 'male', '1999-11-02', 'Depot apprentice', $certificate],
        ] as [$first, $last, $gender, $born, $previous, $programme]) {
            $enrolment->receive([
                'first_name' => $first, 'last_name' => $last, 'gender' => $gender, 'date_of_birth' => $born,
                'phone' => '+211 926 000 '.(100 + strlen($first.$last) * 37), 'guardian_name' => $last.' family', 'previous_school' => $previous,
                'level' => 1, 'programme_id' => $programme->id,
            ], $registrar);
        }

        $declined = $enrolment->receive([
            'first_name' => 'Peter', 'last_name' => 'Gatkuoth', 'gender' => 'male', 'date_of_birth' => '2009-01-14',
            'previous_school' => 'Primary 8', 'level' => 1, 'programme_id' => $diploma->id,
        ], $registrar);
        $enrolment->decline($declined, 'Needs a secondary certificate first.', $registrar);
    }

    /**
     * Finance has its own demo books: what these entries would tell it (and
     * HR) is not sent, or it would all land in Finance's first open month.
     */
    private function holdBackEvents(): void
    {
        DB::table('platform_outbox')->where('status', 'pending')->delete();
    }

    private function clock(?string $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }
}
