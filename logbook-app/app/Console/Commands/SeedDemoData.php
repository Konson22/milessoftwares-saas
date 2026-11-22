<?php

namespace App\Console\Commands;

use App\Logbook\AuditTrail;
use App\Logbook\Entries;
use App\Logbook\Models\Entry;
use App\Logbook\Models\Location;
use App\Logbook\Models\Logbook;
use App\Logbook\Models\LogException;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\Notification;
use App\Logbook\Models\Task;
use App\Logbook\Models\TaskSeries;
use App\Logbook\References;
use App\Logbook\Schedules;
use App\Logbook\Sheets;
use App\Logbook\Tracks;
use App\Models\User;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use RuntimeException;
use Throwable;

/**
 * Demo data for one workspace, local development only: people known to
 * Logbook alone (they cannot sign in and Central does not list them),
 * logbooks with published sheets, places and equipment, three weeks of
 * schedules and entries (reviewed, returned, pending, drafts), recorded
 * routes around Juba, tasks in every state, exceptions and notifications.
 *
 * Entries, reviews, schedules and routes go through the domain classes,
 * with the clock set back to each moment, so references, revisions, the
 * audit trail and automatic exceptions are what real use leaves. Tasks
 * and exception follow-up are written directly: assigning work asks
 * Central about the assignee, and the demo people are not there.
 * Notifications are not queued (Central would be asked about each
 * recipient); a few are written for the owner instead.
 *
 * The audit trail, revisions, reviews and published versions are
 * append-only in the database, so the data stays once seeded.
 */
class SeedDemoData extends Command
{
    protected $signature = 'logbook:demo-data
        {organization : The organization id}
        {--name=Demo workspace : Its display name}
        {--slug=demo : Its slug}
        {--timezone=Africa/Juba : Its time zone}
        {--days=21 : How many days of history}
        {--dry-run : Do everything, then roll it back}';

    protected $description = 'Seed a workspace with Logbook demo data (local development only)';

    private const DEMO_DOMAIN = 'demo.miles.invalid';

    private string $tz;

    /** True only while the run lasts: the permission override checks it. */
    private bool $seeding = false;

    /** The real time when the run started: the clock is moved while seeding. */
    private CarbonImmutable $realNow;

    private User $owner;

    /** @var array<string, User> */
    private array $people = [];

    /** @var array<string, LogResource> */
    private array $resources = [];

    /** @var array<string, Location> */
    private array $places = [];

    /** @var array<string, LogSheet> */
    private array $sheets = [];

    /** @var array<string, Logbook> */
    private array $logbooks = [];

    /** @var list<Entry> */
    private array $toReview = [];

    public function handle(CurrentTenant $tenant): int
    {
        if (app()->environment('production')) {
            $this->error('Demo data is for local development only.');

            return self::FAILURE;
        }

        $this->tz = (string) $this->option('timezone');
        $this->realNow = CarbonImmutable::now('UTC');
        $organization = new Organization(
            id: (string) $this->argument('organization'),
            slug: (string) $this->option('slug'),
            displayName: (string) $this->option('name'),
            status: 'active',
            countryCode: null,
            defaultCurrency: null,
            timezone: $this->tz,
        );

        // Seeding acts for each person in turn: every permission is granted during the run,
        // and queued notices (which would ask Central about each recipient) are dropped.
        // Both end with the run, so nothing else in the same process is affected.
        $this->seeding = true;
        Gate::before(fn () => $this->seeding ? true : null);
        $bus = Bus::getFacadeRoot();
        Bus::fake();

        $dry = (bool) $this->option('dry-run');
        try {
            // One transaction: a failure (or a dry run) leaves nothing behind.
            DB::beginTransaction();
            $tenant->run($organization, function () {
                $this->seed((int) $this->option('days'));
            });
            $dry ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->newLine();
            $this->error($e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')');

            return self::FAILURE;
        } finally {
            $this->seeding = false;
            Bus::swap($bus);
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }

        $this->info($dry ? 'Dry run: everything worked and was rolled back.' : 'Demo data seeded.');

        return self::SUCCESS;
    }

    private function seed(int $days): void
    {
        $owner = WorkspaceMember::query()->with('user')->get()
            ->map(fn (WorkspaceMember $m) => $m->user)
            ->first(fn (?User $u) => $u !== null && ! str_ends_with($u->email, '@'.self::DEMO_DOMAIN));
        if (! $owner instanceof User) {
            throw new RuntimeException('Open Logbook in this workspace once first: the demo data is made by its first member.');
        }
        $this->owner = $owner;
        if (WorkspaceMember::query()->whereIn('user_id', User::query()->where('email', 'like', '%@'.self::DEMO_DOMAIN)->select('id'))->exists()) {
            throw new RuntimeException('This workspace already has the demo data.');
        }

        $today = $this->realNow->setTimezone($this->tz)->startOfDay();
        $start = $today->subDays($days);

        $this->at($start->setTime(7, 0));
        $this->line('People, places and equipment…');
        $this->people();
        $this->placesAndResources();

        $this->line('Logbooks and log sheets…');
        $this->logbooksAndSheets();
        $this->schedules($start);

        $this->line('Entries and routes, day by day…');
        for ($day = $start; $day->lte($today); $day = $day->addDay()) {
            $this->at($day->setTime(0, 5));
            Schedules::generate(CarbonImmutable::now());
            $this->day($day, $today);
            $this->at($day->setTime(23, 55));
            Schedules::settle(CarbonImmutable::now());
            $this->output->write('.');
        }
        $this->newLine();

        $this->line('Reviews, tasks, exceptions and notifications…');
        $this->reviews($today);
        $this->tasks($today);
        $this->exceptions($today);
        $this->at($this->realNow->subMinutes(5));
        Schedules::generate(CarbonImmutable::now());
        $this->notifications();
        $this->summary();
    }

    private function summary(): void
    {
        $count = fn (string $table, string $column) => DB::table($table)->where('organization_id', app(CurrentTenant::class)->require()->id)
            ->selectRaw("{$column} as k, count(*) as n")->groupBy($column)->pluck('n', 'k')
            ->map(fn ($n, $k) => "{$k} {$n}")->implode(', ');
        $this->table(['What', 'Count'], [
            ['Entries', $count('log_entries', 'status')],
            ['Expected entries', $count('log_schedule_occurrences', 'status')],
            ['Tasks', $count('log_tasks', 'status')],
            ['Exceptions', $count('log_exceptions', 'status')],
            ['Routes', DB::table('log_tracks')->count().' · '.round(DB::table('log_tracks')->sum('distance_m') / 1000).' km · '.DB::table('log_track_points')->count().' points'],
            ['Audit events', (string) DB::table('log_audit_events')->count()],
        ]);
    }

    // ---- The cast and the places -------------------------------------

    private function people(): void
    {
        foreach ([
            'grace' => 'Grace Poni',
            'james' => 'James Wani',
            'mary' => 'Mary Keji',
            'peter' => 'Peter Lado',
            'samuel' => 'Samuel Deng',
            'rebecca' => 'Rebecca Ajak',
            'joseph' => 'Joseph Taban',
        ] as $key => $name) {
            $user = new User;
            $user->forceFill([
                'id' => (string) Str::uuid7(),
                'name' => $name,
                'email' => Str::slug($name, '.').'@'.self::DEMO_DOMAIN,
            ])->save();
            WorkspaceMember::query()->create(['user_id' => $user->id, 'last_seen_at' => now()]);
            $this->people[$key] = $user;
        }
    }

    private function placesAndResources(): void
    {
        foreach ([
            'compound' => 'Mission Compound, Munuki',
            'clinic' => 'Mission Clinic, Gudele',
            'field' => 'Field Office, Rejaf',
            'hall' => 'Church Hall, Hai Malakal',
        ] as $key => $name) {
            $this->places[$key] = Location::query()->create(['name' => $name, 'active' => true]);
        }

        foreach ([
            ['GEN-01', 'Main Compound Generator (50 kVA)', 'Generator', 'compound'],
            ['GEN-02', 'Clinic Generator (20 kVA)', 'Generator', 'clinic'],
            ['SSD-101A', 'Land Cruiser', 'Vehicle', 'compound'],
            ['SSD-214B', 'Toyota Hilux', 'Vehicle', 'compound'],
            ['SSD-330C', 'Land Cruiser Ambulance', 'Vehicle', 'clinic'],
            ['FRG-01', 'Vaccine Fridge', 'Cold chain', 'clinic'],
            ['WT-01', 'Water Tank (10,000 L)', 'Water', 'compound'],
            ['UPS-01', 'Server Room UPS', 'IT equipment', 'compound'],
        ] as [$code, $name, $type, $place]) {
            $resource = LogResource::query()->create([
                'code' => $code, 'name' => $name, 'type' => $type, 'location_id' => $this->places[$place]->id, 'active' => true,
            ]);
            AuditTrail::record('resource.created', $resource, $this->owner);
            $this->resources[$code] = $resource;
        }
    }

    // ---- Logbooks, sheets and schedules ------------------------------

    private function logbooksAndSheets(): void
    {
        $this->logbooks = [
            'drivers' => Logbook::query()->where('name', 'Drivers Movement')->first() ?? $this->logbook('Drivers Movement', 'Vehicle trips, drivers and passengers.', 'truck'),
            'generators' => $this->logbook('Generators', 'Daily running hours, fuel and condition of the generators.', 'zap'),
            'clinic' => $this->logbook('Health Clinic', 'Cold chain and clinic equipment.', 'heart-pulse'),
            'facilities' => $this->logbook('Facilities', 'Water, cleaning and the compound buildings.', 'building'),
            'security' => $this->logbook('Security', 'Gate visitors and guard rounds.', 'shield'),
            'it' => $this->logbook('IT', 'Network, power backup and equipment checks.', 'monitor'),
        ];

        $this->sheet('generators', 'GEN', 'Generator Daily Operation', true, [
            ['name' => 'generator', 'label' => 'Generator', 'type' => 'resource', 'required' => true, 'resource_type' => 'Generator'],
            ['name' => 'running_hours', 'label' => 'Running hours', 'type' => 'decimal', 'required' => true, 'min' => '0', 'max' => '24', 'expected_max' => '16', 'unit' => 'hours', 'raise_exception' => true, 'exception_severity' => 'medium'],
            ['name' => 'fuel_used', 'label' => 'Fuel used', 'type' => 'decimal', 'required' => true, 'min' => '0', 'expected_max' => '60', 'unit' => 'litres', 'raise_exception' => true, 'exception_severity' => 'medium'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select_one', 'required' => true, 'choices' => self::choices(['running' => 'Running', 'standby' => 'Standby', 'stopped' => 'Stopped', 'fault' => 'Fault']), 'exception_values' => ['fault'], 'exception_severity' => 'high'],
            ['name' => 'checks', 'label' => 'Checks done', 'type' => 'select_multiple', 'required' => false, 'choices' => self::choices(['oil' => 'Oil level', 'coolant' => 'Coolant', 'battery' => 'Battery', 'leaks' => 'No leaks'])],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false],
        ]);

        $this->sheet('clinic', 'FRG', 'Fridge Temperature', true, [
            ['name' => 'fridge', 'label' => 'Fridge', 'type' => 'resource', 'required' => true, 'resource_type' => 'Cold chain'],
            ['name' => 'temperature', 'label' => 'Temperature', 'type' => 'decimal', 'required' => true, 'min' => '-30', 'max' => '40', 'expected_min' => '2', 'expected_max' => '8', 'unit' => '°C', 'raise_exception' => true, 'exception_severity' => 'high'],
            ['name' => 'door_sealed', 'label' => 'Door seal intact', 'type' => 'yes_no', 'required' => true, 'exception_values' => ['no'], 'exception_severity' => 'medium'],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false],
        ]);

        $this->sheet('facilities', 'WTL', 'Water Tank Level', false, [
            ['name' => 'tank', 'label' => 'Tank', 'type' => 'resource', 'required' => true, 'resource_type' => 'Water'],
            ['name' => 'level', 'label' => 'Level', 'type' => 'integer', 'required' => true, 'min' => '0', 'max' => '100', 'expected_min' => '25', 'unit' => '%', 'raise_exception' => true, 'exception_severity' => 'medium'],
            ['name' => 'refilled', 'label' => 'Refilled today', 'type' => 'yes_no', 'required' => false],
        ]);

        $this->sheet('facilities', 'CLN', 'Office Cleaning Inspection', true, [
            ['name' => 'area', 'label' => 'Area', 'type' => 'select_one', 'required' => true, 'choices' => self::choices(['offices' => 'Offices', 'hall' => 'Church hall', 'clinic' => 'Clinic', 'kitchen' => 'Kitchen'])],
            ['name' => 'done', 'label' => 'Done', 'type' => 'select_multiple', 'required' => false, 'choices' => self::choices(['floors' => 'Floors', 'toilets' => 'Toilets', 'bins' => 'Bins emptied', 'windows' => 'Windows'])],
            ['name' => 'passed', 'label' => 'Passed inspection', 'type' => 'yes_no', 'required' => true, 'exception_values' => ['no'], 'exception_severity' => 'low'],
            ['name' => 'inspector', 'label' => 'Inspected by', 'type' => 'user', 'required' => false],
        ]);

        $this->sheet('security', 'VIS', 'Gate Visitor Log', false, [
            ['name' => 'visitor', 'label' => 'Visitor name', 'type' => 'text', 'required' => true, 'max_length' => 120],
            ['name' => 'organisation', 'label' => 'Organisation', 'type' => 'text', 'required' => false, 'max_length' => 120],
            ['name' => 'purpose', 'label' => 'Purpose', 'type' => 'select_one', 'required' => true, 'choices' => self::choices(['meeting' => 'Meeting', 'delivery' => 'Delivery', 'clinic' => 'Clinic visit', 'contractor' => 'Contractor', 'other' => 'Other'])],
            ['name' => 'time_in', 'label' => 'Time in', 'type' => 'time', 'required' => true],
            ['name' => 'time_out', 'label' => 'Time out', 'type' => 'time', 'required' => false],
        ]);

        $this->sheet('it', 'ITC', 'IT Equipment Check', false, [
            ['name' => 'ups', 'label' => 'UPS', 'type' => 'resource', 'required' => true, 'resource_type' => 'IT equipment'],
            ['name' => 'ups_ok', 'label' => 'UPS on battery test passed', 'type' => 'yes_no', 'required' => true, 'exception_values' => ['no'], 'exception_severity' => 'medium'],
            ['name' => 'network_ok', 'label' => 'Internet and Wi-Fi working', 'type' => 'yes_no', 'required' => true],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false],
        ]);

        $this->sheet('drivers', 'VML', 'Vehicle Movement Log', true, [
            ['name' => 'vehicle', 'label' => 'Vehicle', 'type' => 'resource', 'required' => true, 'resource_type' => 'Vehicle'],
            ['name' => 'from', 'label' => 'From', 'type' => 'text', 'required' => true, 'max_length' => 120],
            ['name' => 'to', 'label' => 'To', 'type' => 'text', 'required' => true, 'max_length' => 120],
            ['name' => 'purpose', 'label' => 'Purpose', 'type' => 'text', 'required' => true, 'max_length' => 200],
            ['name' => 'odometer_start', 'label' => 'Odometer at start', 'type' => 'integer', 'required' => true, 'min' => '0', 'unit' => 'km'],
            ['name' => 'odometer_end', 'label' => 'Odometer at end', 'type' => 'integer', 'required' => true, 'min' => '0', 'unit' => 'km'],
            ['name' => 'passengers', 'label' => 'Passengers', 'type' => 'integer', 'required' => false, 'min' => '0', 'max' => '20'],
        ], ['mode' => 'route', 'required' => false, 'resource_required' => true, 'accuracy_m' => 50]);
    }

    private function logbook(string $name, string $description, string $icon): Logbook
    {
        $logbook = Logbook::query()->create([
            'name' => $name, 'description' => $description, 'icon' => $icon,
            'status' => 'active', 'restricted' => false, 'created_by' => $this->owner->id,
        ]);
        AuditTrail::record('logbook.created', $logbook, $this->owner);

        return $logbook;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>|null  $location
     */
    private function sheet(string $logbook, string $code, string $name, bool $review, array $fields, ?array $location = null): void
    {
        Auth::setUser($this->owner);
        $sheet = Sheets::create($this->logbooks[$logbook], ['name' => $name, 'code' => $code, 'review_required' => $review], $fields, $this->owner, 'demo');
        if ($location !== null) {
            Sheets::saveDraft($sheet, $fields, $this->owner, $location, withLocation: true);
        }
        Sheets::publish($sheet->fresh(), $this->owner);
        $this->sheets[$code] = $sheet->fresh(['publishedVersion']);
    }

    private function schedules(CarbonImmutable $start): void
    {
        Auth::setUser($this->owner);
        $base = ['timezone' => $this->tz, 'starts_on' => $start->toDateString(), 'reminders' => [], 'late_minutes' => 240];
        Schedules::save($this->sheets['GEN'], null, $base + ['frequency' => 'daily', 'opens_time' => '07:00', 'due_time' => '09:00'], $this->owner);
        Schedules::save($this->sheets['FRG'], null, $base + ['frequency' => 'daily', 'opens_time' => '07:00', 'due_time' => '08:00', 'late_minutes' => 120], $this->owner);
        Schedules::save($this->sheets['WTL'], null, $base + ['frequency' => 'daily', 'opens_time' => '15:00', 'due_time' => '17:00'], $this->owner);
        Schedules::save($this->sheets['CLN'], null, $base + ['frequency' => 'weekly', 'weekdays' => [1], 'opens_time' => '08:00', 'due_time' => '12:00', 'late_minutes' => 480], $this->owner);
    }

    // ---- A day of work -------------------------------------------------

    private function day(CarbonImmutable $day, CarbonImmutable $today): void
    {
        $n = (int) $day->format('z');
        $isToday = $day->equalTo($today);
        $real = $this->realNow;
        $past = fn (int $h, int $m) => $day->setTime($h, $m)->utc()->lt($real);

        // Generators: the main one every day, the clinic one most days; one fault, one heavy day.
        if ($past(8, 20)) {
            $fuel = $n % 9 === 4 ? 72.5 : 28 + ($n * 7 % 22);
            $this->entry('GEN', 'james', $day->setTime(8, 10 + $n % 15), [
                'generator' => $this->resources['GEN-01']->id,
                'running_hours' => (string) (8 + ($n * 3 % 7) + 0.5),
                'fuel_used' => (string) $fuel,
                'status' => $n % 13 === 6 ? 'fault' : 'running',
                'checks' => ['oil', 'coolant', 'battery'],
                'notes' => $n % 13 === 6 ? 'Stopped twice: alternator warning light on. Called the technician.' : null,
            ], draft: $isToday);
        }
        if ($n % 3 !== 0 && $past(8, 45)) {
            $this->entry('GEN', 'james', $day->setTime(8, 40), [
                'generator' => $this->resources['GEN-02']->id,
                'running_hours' => (string) (5 + $n % 5),
                'fuel_used' => (string) (14 + $n % 9),
                'status' => $n % 4 === 0 ? 'standby' : 'running',
                'checks' => ['oil', 'leaks'],
            ]);
        }

        // Vaccine fridge: missed now and then, out of range twice.
        if ($n % 8 !== 3 && $past(7, 40)) {
            $hot = $n % 10 === 7;
            $this->entry('FRG', 'mary', $day->setTime(7, 20 + $n % 12), [
                'fridge' => $this->resources['FRG-01']->id,
                'temperature' => $hot ? '9.4' : (string) round(3.2 + ($n * 13 % 40) / 10, 1),
                'door_sealed' => $n % 17 === 9 ? 'no' : 'yes',
                'notes' => $hot ? 'Power cut overnight; vaccines moved to the cool box.' : null,
            ]);
        }

        // Water tank in the afternoon; low once.
        if ($past(16, 30) && $n % 6 !== 1) {
            $level = $n % 11 === 5 ? 18 : 40 + ($n * 17 % 55);
            $this->entry('WTL', 'rebecca', $day->setTime(16, 5 + $n % 20), [
                'tank' => $this->resources['WT-01']->id,
                'level' => (string) $level,
                'refilled' => $level < 50 ? 'yes' : 'no',
            ]);
        }

        // Cleaning inspection on Mondays.
        if ($day->isMonday() && $past(10, 30)) {
            $this->entry('CLN', 'rebecca', $day->setTime(10, 15), [
                'area' => ['offices', 'hall', 'clinic', 'kitchen'][$n % 4],
                'done' => ['floors', 'toilets', 'bins'],
                'passed' => $n % 3 === 0 ? 'no' : 'yes',
                'inspector' => $this->people['grace']->id,
            ]);
        }

        // Visitors at the gate on weekdays.
        if ($day->isWeekday()) {
            $visitors = [
                ['Achol Garang', 'Ministry of Health', 'meeting'], ['Juba Water Supply', 'JWS Ltd', 'delivery'],
                ['Daniel Lomoro', null, 'clinic'], ['Akim Electrical', 'Akim Electrical Services', 'contractor'],
                ['Nyandeng Mayen', 'World Relief', 'meeting'], ['Fresh Foods Juba', 'Fresh Foods', 'delivery'],
            ];
            foreach ([0, 1, 2] as $i) {
                if (($n + $i) % 3 === 2 || ! $past(9 + $i * 2, 30)) {
                    continue;
                }
                [$who, $org, $purpose] = $visitors[($n + $i * 2) % count($visitors)];
                $this->entry('VIS', 'joseph', $day->setTime(9 + $i * 2, 10), [
                    'visitor' => $who, 'organisation' => $org, 'purpose' => $purpose,
                    'time_in' => sprintf('%02d:10', 9 + $i * 2), 'time_out' => sprintf('%02d:%02d', 10 + $i * 2, 5 + $n % 40),
                ]);
            }
        }

        // IT check on Fridays, by the owner.
        if ($day->isFriday() && $past(15, 0)) {
            $this->entry('ITC', null, $day->setTime(14, 30), [
                'ups' => $this->resources['UPS-01']->id,
                'ups_ok' => $n % 4 === 1 ? 'no' : 'yes',
                'network_ok' => 'yes',
                'notes' => $n % 4 === 1 ? 'UPS held only 6 minutes on battery. Battery replacement needed.' : null,
            ]);
        }

        // Trips on weekdays: one or two, each recorded as a route on the phone.
        if ($day->isWeekday()) {
            $trips = [
                ['peter', 'SSD-101A', 'compound', 'field', 'Staff to the field office', 3],
                ['samuel', 'SSD-330C', 'clinic', 'hospital', 'Patient referral to Juba Teaching Hospital', 1],
                ['peter', 'SSD-214B', 'compound', 'airport', 'Airport pick-up: visiting team', 2],
                ['samuel', 'SSD-214B', 'compound', 'market', 'Supplies from Konyo Konyo market', 1],
            ];
            foreach ([0, 1] as $i) {
                $trip = $trips[($n + $i) % count($trips)];
                $hour = $i === 0 ? 8 : 13;
                if (($i === 1 && $n % 2 === 0) || ! $past($hour + 3, 0)) {
                    continue;
                }
                $this->trip($trip, $day->setTime($hour, 5 + $n % 20), $n);
            }
        }
    }

    /**
     * One entry written (and usually submitted) by a person at a moment.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $place
     */
    private function entry(string $code, ?string $who, CarbonImmutable $at, array $values, bool $draft = false, array $place = []): ?Entry
    {
        $user = $who === null ? $this->owner : $this->people[$who];
        $sheet = $this->sheets[$code];
        $this->at($at->addMinutes(4));
        Auth::setUser($user);
        $occurrence = DB::table('log_schedule_occurrences')->where('log_sheet_id', $sheet->id)
            ->where('opens_at', '<=', $at->utc())->where('closes_at', '>=', $at->utc())->value('id');
        $entry = Entries::create($sheet, $user, array_filter($values, fn ($v) => $v !== null), $at->utc()->toIso8601String(), (string) Str::uuid7(), ! $draft, null, $occurrence, $place);
        if (! $draft && $sheet->review_required) {
            $this->toReview[] = $entry;
        }

        return $entry;
    }

    /**
     * A trip: the route recorded on the driver's phone, then the movement entry written on it.
     *
     * @param  array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int}  $trip
     */
    private function trip(array $trip, CarbonImmutable $start, int $n): void
    {
        [$who, $vehicle, $from, $to, $purpose, $passengers] = $trip;
        $driver = $this->people[$who];
        $waypoints = self::ROUTES[$from.'>'.$to];
        $points = $this->path($waypoints, $start, $n);
        $endAt = CarbonImmutable::createFromTimestamp(end($points)['t'], 'UTC');

        $this->at($start);
        Auth::setUser($driver);
        $id = (string) Str::uuid7();
        [$track, $code, $message] = Tracks::upsert($driver, [
            'id' => $id, 'sheet_id' => $this->sheets['VML']->id, 'resource_id' => $this->resources[$vehicle]->id,
            'status' => 'recording', 'started_at' => $start->utc()->toIso8601String(), 'device' => 'Tecno Camon 20 · Android 13',
        ]);
        if ($track === null) {
            throw new RuntimeException("Route not started: {$code} {$message}");
        }

        $this->at($endAt->addMinutes(1));
        Tracks::append($track, array_map(fn (array $p, int $i) => [
            'seq' => $i + 1, 'lat' => $p['lat'], 'lng' => $p['lng'], 'accuracy_m' => 6 + ($i % 9),
            'speed_mps' => 9.5, 'captured_at' => CarbonImmutable::createFromTimestamp($p['t'], 'UTC')->toIso8601String(),
        ], $points, array_keys($points)), [
            ['seq' => count($points) + 1, 'kind' => 'start', 'at' => $start->utc()->toIso8601String()],
            ['seq' => count($points) + 2, 'kind' => 'end', 'at' => $endAt->toIso8601String()],
        ]);
        Tracks::upsert($driver, ['id' => $id, 'status' => 'ended', 'ended_at' => $endAt->toIso8601String()]);

        $odometer = 48210 + $n * 37;
        $track->refresh();
        $last = end($points);
        $this->entry('VML', $who, $endAt->setTimezone($this->tz), [
            'vehicle' => $this->resources[$vehicle]->id,
            'from' => self::PLACE_NAMES[$from],
            'to' => self::PLACE_NAMES[$to],
            'purpose' => $purpose,
            'odometer_start' => (string) $odometer,
            'odometer_end' => (string) ($odometer + max(1, (int) round($track->distance_m / 1000))),
            'passengers' => (string) $passengers,
        ], place: [
            'location' => ['lat' => $last['lat'], 'lng' => $last['lng'], 'accuracy_m' => 8, 'captured_at' => $endAt->toIso8601String()],
            'track_id' => $id,
            'source' => 'phone',
        ]);
    }

    /**
     * Positions every 20 seconds along the waypoints, about 35 km/h, with a little GPS wobble.
     *
     * @param  list<array{0: float, 1: float}>  $waypoints
     * @return list<array{lat: float, lng: float, t: int}>
     */
    private function path(array $waypoints, CarbonImmutable $start, int $n): array
    {
        $out = [];
        $t = $start->utc()->getTimestamp();
        $step = 195.0; // metres per 20 s
        for ($i = 0; $i < count($waypoints) - 1; $i++) {
            [$aLat, $aLng] = $waypoints[$i];
            [$bLat, $bLng] = $waypoints[$i + 1];
            $metres = self::metres($aLat, $aLng, $bLat, $bLng);
            $steps = max(1, (int) ceil($metres / $step));
            for ($s = 0; $s < $steps; $s++) {
                $f = $s / $steps;
                $wobble = (($n + $i * 7 + $s * 3) % 5 - 2) * 0.000012;
                $out[] = ['lat' => round($aLat + ($bLat - $aLat) * $f + $wobble, 7), 'lng' => round($aLng + ($bLng - $aLng) * $f - $wobble, 7), 't' => $t];
                $t += 20;
            }
        }
        [$lat, $lng] = $waypoints[count($waypoints) - 1];
        $out[] = ['lat' => $lat, 'lng' => $lng, 't' => $t];

        return $out;
    }

    private static function metres(float $aLat, float $aLng, float $bLat, float $bLng): float
    {
        $r = 6371000;
        $dLat = deg2rad($bLat - $aLat);
        $dLng = deg2rad($bLng - $aLng);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($aLat)) * cos(deg2rad($bLat)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($h)));
    }

    private const PLACE_NAMES = [
        'compound' => 'Mission Compound, Munuki',
        'clinic' => 'Mission Clinic, Gudele',
        'field' => 'Field Office, Rejaf',
        'hospital' => 'Juba Teaching Hospital',
        'airport' => 'Juba International Airport',
        'market' => 'Konyo Konyo Market',
    ];

    /** Rough road waypoints around Juba, [lat, lng]. */
    private const ROUTES = [
        'compound>field' => [[4.8702, 31.5598], [4.8641, 31.5671], [4.8552, 31.5760], [4.8433, 31.5821], [4.8270, 31.5868], [4.8065, 31.5902], [4.7860, 31.5915], [4.7652, 31.5907]],
        'clinic>hospital' => [[4.8803, 31.5352], [4.8771, 31.5437], [4.8712, 31.5531], [4.8655, 31.5644], [4.8596, 31.5748], [4.8541, 31.5862], [4.8512, 31.5948]],
        'compound>airport' => [[4.8702, 31.5598], [4.8689, 31.5702], [4.8671, 31.5810], [4.8690, 31.5905], [4.8717, 31.6008]],
        'compound>market' => [[4.8702, 31.5598], [4.8640, 31.5680], [4.8575, 31.5770], [4.8508, 31.5880], [4.8470, 31.6010]],
    ];

    // ---- Reviews, tasks, exceptions, notifications ----------------------

    private function reviews(CarbonImmutable $today): void
    {
        $reviewer = $this->people['grace'];
        foreach ($this->toReview as $i => $entry) {
            $entry->refresh();
            $local = $entry->occurred_at->setTimezone($this->tz);
            // The last two days wait for review; most older entries are reviewed, a few returned.
            if ($local->gte($today->subDays(2)) || $entry->status !== 'submitted') {
                continue;
            }
            $this->at($local->addHours(3 + $i % 4));
            Auth::setUser($reviewer);
            if ($i % 14 === 5) {
                Entries::review($entry, $reviewer, 'returned', 'Please add the odometer reading from the dashboard photo and check the figures.');
                if ($i % 28 !== 5) {
                    // Corrected and resubmitted the next morning, then passed.
                    $author = User::query()->findOrFail($entry->created_by);
                    $this->at($local->addDay()->setTime(8, 0));
                    Auth::setUser($author);
                    Entries::submit($entry->fresh(), $author);
                    $this->at($local->addDay()->setTime(11, 0));
                    Auth::setUser($reviewer);
                    Entries::review($entry->fresh(), $reviewer, 'reviewed', null);
                }

                continue;
            }
            Entries::review($entry, $reviewer, 'reviewed', $i % 9 === 0 ? 'Checked against the paper log.' : null);
        }
    }

    private function tasks(CarbonImmutable $today): void
    {
        $now = $this->realNow->setTimezone($this->tz);
        $rows = [
            // title, assignee, priority, status, due (days from today, hour), logbook, sheet, resource, verify
            ['Top up generator fuel (GEN-01)', 'james', 'high', 'pending', [0, 14], 'generators', 'GEN', 'GEN-01', false],
            ['Repair water leak – office block', 'rebecca', 'high', 'in_progress', [0, 17], 'facilities', null, null, true],
            ['Clean solar panels – clinic roof', 'rebecca', 'normal', 'pending', [1, 9], 'facilities', null, null, false],
            ['Service vehicle SSD-101A', 'peter', 'normal', 'pending', [2, 10], 'drivers', null, 'SSD-101A', true],
            ['Monthly generator inspection', 'james', 'low', 'pending', [4, 8], 'generators', 'GEN', 'GEN-02', true],
            ['Replace UPS battery in the server room', null, 'urgent', 'pending', [-2, 12], 'it', 'ITC', 'UPS-01', false],
            ['Fix the fridge door seal', 'mary', 'high', 'pending', [-1, 16], 'clinic', 'FRG', 'FRG-01', true],
            ['Renew ambulance insurance', 'samuel', 'normal', 'awaiting_verification', [-3, 12], 'drivers', null, 'SSD-330C', true],
            ['Buy cleaning supplies', 'rebecca', 'low', 'completed', [-5, 12], 'facilities', 'CLN', null, false],
            ['Check tyre pressure on all vehicles', 'samuel', 'normal', 'completed', [-6, 9], 'drivers', null, 'SSD-214B', true],
            ['Install visitor badge printer', 'joseph', 'low', 'returned', [-1, 15], 'security', 'VIS', null, true],
            ['Paint the church hall gate', 'joseph', 'low', 'cancelled', [-8, 12], 'security', null, null, false],
            ['Order spare alternator for GEN-01', null, 'high', 'in_progress', [1, 12], 'generators', 'GEN', 'GEN-01', false],
            ['Vaccine stock count', 'mary', 'normal', 'completed', [-2, 11], 'clinic', 'FRG', 'FRG-01', true],
        ];

        foreach ($rows as $i => [$title, $who, $priority, $status, [$days, $hour], $logbook, $sheet, $resource, $verify]) {
            $assignee = $who === null ? $this->owner : $this->people[$who];
            $due = $today->addDays($days)->setTime($hour, 0);
            $assigned = $due->subDays(3 + $i % 4)->setTime(9, 15);
            $this->at(min($assigned, $now->subHour()));
            Auth::setUser($this->owner);
            $task = Task::query()->create([
                'reference' => References::next(References::TASKS['sequence'], References::TASKS['prefix']),
                'title' => $title,
                'description' => null,
                'priority' => $priority,
                'status' => 'pending',
                'logbook_id' => $this->logbooks[$logbook]->id,
                'log_sheet_id' => $sheet !== null ? $this->sheets[$sheet]->id : null,
                'resource_id' => $resource !== null ? $this->resources[$resource]->id : null,
                'assignee_id' => $assignee->id,
                'created_by' => $this->owner->id,
                'verification_required' => $verify,
                'evidence_required' => false,
                'due_at' => $due->utc(),
                'reminders' => [],
                'assigned_at' => now(),
            ]);
            AuditTrail::record('task.created', $task, $this->owner, ['assignee' => $assignee->name]);

            $done = min($due->subHours(2), $now->subMinutes(30));
            $changes = match ($status) {
                'in_progress' => ['started_at' => $done->subDay()->utc()],
                'awaiting_verification' => ['started_at' => $done->subDay()->utc(), 'completed_at' => $done->utc(), 'completed_by' => $assignee->id, 'completion_notes' => 'Paid at the insurer; receipt filed in the office.'],
                'completed' => ['started_at' => $done->subDay()->utc(), 'completed_at' => $done->utc(), 'completed_by' => $assignee->id, 'completion_notes' => 'Done.', 'verified_at' => $verify ? $done->addHours(2)->utc() : null, 'verified_by' => $verify ? $this->owner->id : null],
                'returned' => ['started_at' => $done->subDays(2)->utc(), 'completed_at' => $done->subDay()->utc(), 'completed_by' => $assignee->id, 'return_comment' => 'The printer is not connected to the gate PC yet.'],
                'cancelled' => ['cancelled_at' => $done->utc(), 'cancelled_by' => $this->owner->id, 'cancel_reason' => 'Postponed to next financial year.'],
                default => [],
            };
            if ($status !== 'pending') {
                $this->at($done);
                $task->forceFill(['status' => $status, 'revision' => 2] + $changes)->save();
                AuditTrail::record(match ($status) {
                    'in_progress' => 'task.started',
                    'awaiting_verification' => 'task.submitted',
                    'completed' => $verify ? 'task.verified' : 'task.completed',
                    'returned' => 'task.returned',
                    'cancelled' => 'task.cancelled',
                }, $task, in_array($status, ['completed', 'returned', 'cancelled'], true) && ($verify || $status === 'cancelled') ? $this->owner : $assignee);
            }
        }

        // Recurring work: made a day ahead by the operations clock.
        $this->at($today->subDays(7)->setTime(9, 0));
        foreach ([
            ['Check generator fuel level', 'james', 'daily', null, '07:00', '09:00', 'generators', 'GEN-01'],
            ['Vehicle pre-trip check', 'peter', 'weekly', [1, 3, 5], '07:00', '08:00', 'drivers', 'SSD-101A'],
            ['Compound security walk-round', 'joseph', 'daily', null, '18:00', '19:00', 'security', null],
        ] as [$title, $who, $frequency, $weekdays, $starts, $dueTime, $logbook, $resource]) {
            $series = TaskSeries::query()->create([
                'title' => $title, 'priority' => 'normal', 'logbook_id' => $this->logbooks[$logbook]->id,
                'resource_id' => $resource !== null ? $this->resources[$resource]->id : null,
                'assignee_id' => $this->people[$who]->id, 'verification_required' => false, 'evidence_required' => false,
                'frequency' => $frequency, 'weekdays' => $weekdays, 'start_time' => $starts, 'due_time' => $dueTime,
                'timezone' => $this->tz, 'starts_on' => $today->subDays(7)->toDateString(), 'reminders' => [-30],
                'active' => true, 'created_by' => $this->owner->id, 'generate_from' => now(),
            ]);
            AuditTrail::record('task_series.created', $series, $this->owner);
        }
    }

    private function exceptions(CarbonImmutable $today): void
    {
        $now = $this->realNow->setTimezone($this->tz);
        // Those raised by readings: older ones followed up to closure, recent ones still open.
        $raised = LogException::query()->orderBy('created_at')->get();
        foreach ($raised as $i => $x) {
            $age = $x->created_at->diffInDays($now);
            $assignee = match (true) {
                str_starts_with((string) $x->field, 'temp') || $x->field === 'door_sealed' => $this->people['mary'],
                $x->field === 'level' => $this->people['rebecca'],
                $x->field === 'ups_ok' => $this->owner,
                $x->field === 'passed' => $this->people['rebecca'],
                default => $this->people['james'],
            };
            $at = $x->created_at->addHours(2);
            $changes = ['assignee_id' => $assignee->id, 'due_at' => $at->addDays(2), 'revision' => 2];
            if ($age > 9) {
                $changes += ['status' => 'closed', 'resolution_notes' => 'Fixed and checked the next day.', 'resolved_at' => $at->addDay(), 'resolved_by' => $assignee->id, 'closed_at' => $at->addDays(2), 'closed_by' => $this->owner->id, 'revision' => 4];
            } elseif ($age > 4) {
                $changes += ['status' => 'resolved', 'resolution_notes' => 'Back to normal; will watch it for a week.', 'resolved_at' => $at->addDay(), 'resolved_by' => $assignee->id, 'revision' => 3];
            } elseif ($i % 2 === 0) {
                $changes += ['status' => 'in_progress'];
            }
            $this->at(min($at, $now->subMinutes(10)));
            $x->forceFill($changes)->save();
            AuditTrail::record('exception.assigned', $x, $this->owner, ['assignee' => $assignee->name]);
        }

        // Reported by hand.
        foreach ([
            ['Broken window in the clinic waiting room', 'Glass cracked during the storm; taped for now.', 'medium', 'facilities', 'rebecca', 1],
            ['Gate lock jammed', 'The pedestrian gate lock jams in the evening; guards use the padlock.', 'high', 'security', 'joseph', 3],
        ] as [$title, $description, $severity, $logbook, $who, $daysAgo]) {
            $this->at($today->subDays($daysAgo)->setTime(10, 30));
            $x = LogException::query()->create([
                'reference' => References::next(References::EXCEPTIONS['sequence'], References::EXCEPTIONS['prefix']),
                'title' => $title, 'description' => $description, 'source' => 'manual', 'severity' => $severity,
                'status' => 'open', 'logbook_id' => $this->logbooks[$logbook]->id,
                'raised_by' => $this->people[$who]->id, 'assignee_id' => $this->people['rebecca']->id,
                'due_at' => now()->addDays(3),
            ]);
            AuditTrail::record('exception.raised', $x, $this->people[$who]);
        }
    }

    private function notifications(): void
    {
        $pending = Entry::query()->where('status', 'submitted')->latest('submitted_at')->with('sheet:id,name,code')->limit(3)->get();
        foreach ($pending as $e) {
            $this->notice('entry', 'entry.submitted', "{$e->reference} is waiting for review", $e->sheet->name, 'entry', $e->id, "entries/{$e->id}", $e->submitted_at);
        }
        foreach (LogException::query()->whereIn('status', ['open', 'in_progress'])->latest()->limit(3)->get() as $x) {
            $this->notice('exception', 'exception.raised', "{$x->reference} · {$x->title}", 'Severity: '.$x->severity, 'exception', $x->id, "exceptions/{$x->id}", $x->created_at);
        }
        foreach (Task::query()->where('assignee_id', $this->owner->id)->whereIn('status', ['pending', 'in_progress'])->get() as $t) {
            $this->notice('task', 'task.assigned', "{$t->reference} · {$t->title}", 'Given to you', 'task', $t->id, "tasks/{$t->id}", $t->assigned_at);
        }
    }

    private function notice(string $category, string $event, string $title, ?string $body, string $type, string $id, string $link, mixed $at): void
    {
        Notification::query()->create([
            'user_id' => $this->owner->id, 'category' => $category, 'event' => $event,
            'title' => mb_substr($title, 0, 160), 'body' => $body, 'subject_type' => $type, 'subject_id' => $id,
            'link' => $link, 'dedupe_key' => "demo:{$event}:{$id}", 'created_at' => $at ?? now(),
        ]);
    }

    // ---- Helpers --------------------------------------------------------

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private static function choices(array $labels): array
    {
        return array_map(fn ($value, $label) => ['value' => $value, 'label' => $label], array_keys($labels), $labels);
    }

    /** Sets the clock (both Carbon flavours) to a moment. */
    private function at(CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment->utc());
        CarbonImmutable::setTestNow($moment->utc());
    }
}
