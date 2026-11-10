<?php

namespace Database\Seeders;

use App\Access\Access;
use App\Access\Catalog;
use App\Actions\Budgets;
use App\Actions\Grants;
use App\Actions\ListStatuses;
use App\Actions\Organizations;
use App\Actions\Spending;
use App\Actions\Tasks;
use App\Actions\Timesheets;
use App\Actions\WorkStructure;
use App\Enums\CostCategory;
use App\Enums\FunderKind;
use App\Enums\GrantReportStatus;
use App\Enums\GrantStatus;
use App\Enums\TaskPriority;
use App\Models\ApprovalPolicy;
use App\Models\Funder;
use App\Models\Grant;
use App\Models\GrantReport;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Risk;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ActivityLog;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Made-up demo data for Asaas NGO (AsaasSeeder): a team, funders, grants at
 * every stage, and four projects with budgets, spending, tasks, time and
 * risks. It goes through the app's own actions, so every row obeys the same
 * rules as real work, and dates are relative to today, so the data looks
 * current. Runs once: it does nothing if the WASH project is already there.
 * The team signs in with SEED_TEAM_PASSWORD; their addresses are all
 *
 * @asaas.test, which can never receive mail. Nothing is emailed while seeding.
 */
class AsaasDemoSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Person> */
    private array $people = [];

    private Tasks $tasks;

    /** The workspace the demo data goes in (php artisan projects:demo <slug>). */
    public string $slug = 'asaas';

    public function run(): void
    {
        $organization = Organization::query()->where('slug', $this->slug)->firstOrFail();
        // Whoever owns it and has opened Projects; otherwise a stand-in.
        $this->users['owner'] = User::query()
            ->whereHas('memberships', fn ($query) => $query->where('organization_id', $organization->id)->where('is_owner', true))
            ->first() ?? $this->member($organization, 'owner', 'Asaas Admin', null);
        $this->tasks = new Tasks;

        // Nothing is emailed while seeding, to the owner either.
        $ownerAlerts = $this->users['owner']->email_alerts;
        $this->users['owner']->forceFill(['email_alerts' => false])->save();

        app(CurrentOrganization::class)->run($organization, function () use ($organization) {
            if (Project::query()->where('code', 'WASH')->exists()) {
                return;
            }

            DB::transaction(function () use ($organization) {
                $this->seedTeam($organization);

                // Larger spending takes two approvers.
                ApprovalPolicy::query()->create([
                    'subject' => ApprovalPolicy::EXPENSE,
                    'currency' => 'USD',
                    'min_amount_minor' => 10_000_00,
                    'approvals_required' => 2,
                    'created_by' => $this->users['owner']->id,
                ]);

                $grants = $this->seedGrants();

                $this->seedWash($grants['nile']);
                $this->seedEducation($grants['horizon']);
                $this->seedFoodSecurity();
                $this->seedCholeraResponse($grants['sunrise']);
            });
        });

        $this->users['owner']->forceFill(['email_alerts' => $ownerAlerts])->save();
        // Made-up notifications are not for the first daily digest.
        UserNotification::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->whereNull('emailed_at')->update(['emailed_at' => now()]);
    }

    /**
     * A demo member here, holding a catalog role (or owning it). Only a copy:
     * they have no platform account, so they never sign in. @asaas.test can
     * never receive mail.
     */
    private function member(Organization $organization, string $key, string $name, ?string $role): User
    {
        $user = User::findByEmail("{$key}@asaas.test") ?? new User(['name' => $name, 'email' => "{$key}@asaas.test"]);
        $user->forceFill(['status' => User::ACTIVE, 'email_alerts' => false])->save();

        $permissions = $role === null ? Catalog::permissions() : Catalog::rolePermissions($role);
        Organizations::enter($organization, $user, new Access($organization->id, '', $role === null, $permissions, $role === null ? [] : [$role]));

        return $user;
    }

    private function seedTeam(Organization $organization): void
    {
        $owner = $this->users['owner'];
        $this->people['owner'] = $this->person($owner, 'Executive Director', 45_00);

        $team = [
            'achol' => ['Achol Deng', 'Programme Manager', 'manager', 28_00],
            'mary' => ['Mary Akech', 'Education Programme Manager', 'manager', 26_00],
            'peter' => ['Peter Lado', 'Finance Controller', 'controller', 30_00],
            'grace' => ['Grace Keji', 'Finance Officer', 'finance_officer', 18_00],
            'james' => ['James Wani', 'WASH Field Officer', 'member', 15_00],
            'samuel' => ['Samuel Taban', 'MEAL Officer', 'member', 16_00],
        ];

        foreach ($team as $key => [$name, $title, $role, $rate]) {
            $user = $this->member($organization, $key, $name, $role);

            $this->users[$key] = $user;
            $this->people[$key] = $this->person($user, $title, $rate);
        }

        // On the team without an account.
        $this->people['rose'] = Person::query()->create(['name' => 'Rose Poni', 'email' => 'rose@asaas.test', 'title' => 'Hygiene Promotion Consultant', 'hourly_cost_minor' => 20_00]);
    }

    private function person(User $user, string $title, int $hourlyCostMinor): Person
    {
        // The owner may already have one, from having opened the app.
        $person = Person::query()->firstOrNew(['user_id' => $user->id]);
        $person->forceFill([
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'title' => $title,
            'hourly_cost_minor' => $hourlyCostMinor,
        ])->save();

        return $person;
    }

    /**
     * One grant at each stage. The active and closed ones are returned for
     * their projects.
     *
     * @return array{nile: Grant, horizon: Grant, sunrise: Grant}
     */
    private function seedGrants(): array
    {
        $by = $this->users['peter'];

        $nileFund = $this->funder('Nile Basin Relief Fund', FunderKind::Institutional, 'NO', 'Ingrid Solberg');
        $horizonTrust = $this->funder('Horizon Education Trust', FunderKind::Foundation, 'GB', 'Helen Marsh');
        $greenfield = $this->funder('Greenfield Foundation', FunderKind::Foundation, 'US', 'Daniel Reyes');
        $sunrise = $this->funder('Sunrise Telecom Giving', FunderKind::Corporate, 'KE', 'Wanjiru Kamau');

        // Active, increased and extended by an amendment.
        $nile = $this->awardedGrant($nileFund, 'NBRF/2026/014', 'Safe water and sanitation, Juba County', 'USD', 220_000_00, 220_000_00, startedDaysAgo: 240, endsInDays: 250);
        Grants::amend($nile, 250_000_00, $this->day(370), $this->day(-60), 'Additional boreholes agreed after the mid-term review; agreement extended by four months.', $by);
        foreach ([[-230, 100_000_00, true], [-110, 75_000_00, true], [40, 75_000_00, false]] as [$due, $amount, $received]) {
            $this->disbursement($nile, $due, $amount, $received);
        }
        $this->report($nile, 'First quarter report', 'combined', -150, GrantReportStatus::Accepted);
        $this->report($nile, 'Second quarter report', 'combined', -40, GrantReportStatus::Submitted);
        $this->report($nile, 'Third quarter report', 'combined', 20, GrantReportStatus::Due);

        // Active, in another currency than the project it funds, and behind
        // on a payment and a report.
        $horizon = $this->awardedGrant($horizonTrust, 'HET/2026/007', "Girls' education in Yei", 'EUR', 110_000_00, 100_000_00, startedDaysAgo: 150, endsInDays: 215);
        foreach ([[-140, 40_000_00, true], [-10, 30_000_00, false], [120, 30_000_00, false]] as [$due, $amount, $received]) {
            $this->disbursement($horizon, $due, $amount, $received);
        }
        $this->report($horizon, 'Interim financial report', 'financial', -5, GrantReportStatus::Due);
        $this->report($horizon, 'Interim narrative report', 'narrative', -30, GrantReportStatus::RevisionRequested);

        // Spent and reported: closed once its project is (seedCholeraResponse).
        $closing = $this->awardedGrant($sunrise, 'STG/2026/003', 'Cholera response, Juba', 'USD', 30_000_00, 30_000_00, startedDaysAgo: 210, endsInDays: -30, restricted: false);
        $this->disbursement($closing, -205, 30_000_00, true);
        $this->report($closing, 'Final report', 'combined', -40, GrantReportStatus::Accepted);

        // Still in the pipeline, and one that was turned down.
        Grants::create($this->grantDetails($greenfield, 'GF/2027/002', 'Food security and livelihoods, Bor', 'USD', 180_000_00), GrantStatus::Applied, $by);
        Grants::create($this->grantDetails($nileFund, 'NBRF/2027/OPP1', 'Flood preparedness, Jonglei', 'USD', 75_000_00), GrantStatus::Opportunity, $by);
        $declined = Grants::create($this->grantDetails($horizonTrust, 'HET/2026/019', 'Teacher training, Torit', 'EUR', 60_000_00), GrantStatus::Applied, $by);
        Grants::decline($declined, $this->day(-45), $by);

        return ['nile' => $nile, 'horizon' => $horizon, 'sunrise' => $closing];
    }

    private function funder(string $name, FunderKind $kind, string $country, string $contact): Funder
    {
        return Funder::query()->create([
            'name' => $name,
            'kind' => $kind,
            'country_code' => $country,
            'contact_name' => $contact,
            'contact_email' => 'grants@'.str($name)->slug()->value().'.example.org',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function grantDetails(Funder $funder, string $reference, string $title, string $currency, int $amountMinor, bool $restricted = true): array
    {
        return [
            'funder_id' => $funder->id,
            'reference' => $reference,
            'title' => $title,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'restricted' => $restricted,
        ];
    }

    private function awardedGrant(Funder $funder, string $reference, string $title, string $currency, int $requestedMinor, int $awardedMinor, int $startedDaysAgo, int $endsInDays, bool $restricted = true): Grant
    {
        $by = $this->users['peter'];
        $grant = Grants::create($this->grantDetails($funder, $reference, $title, $currency, $requestedMinor, $restricted), GrantStatus::Applied, $by);
        $grant->forceFill(['applied_on' => $this->day(-$startedDaysAgo - 60)])->save();
        Grants::award($grant, $awardedMinor, $this->day(-$startedDaysAgo - 10), $this->day(-$startedDaysAgo), $this->day($endsInDays), $by);

        return $grant;
    }

    private function disbursement(Grant $grant, int $dueInDays, int $amountMinor, bool $received): void
    {
        $by = $this->users['peter'];
        $disbursement = Grants::schedule($grant, $this->day($dueInDays), $amountMinor, null, $by);
        if ($received) {
            Grants::receive($disbursement, $this->day($dueInDays + 3), $by);
        }
    }

    private function report(Grant $grant, string $title, string $kind, int $dueInDays, GrantReportStatus $status): void
    {
        $by = $this->users['peter'];
        /** @var GrantReport $report */
        $report = $grant->reports()->create(['title' => $title, 'kind' => $kind, 'due_on' => $this->day($dueInDays), 'status' => GrantReportStatus::Due]);

        $steps = match ($status) {
            GrantReportStatus::Due => [],
            GrantReportStatus::Submitted => [GrantReportStatus::Submitted],
            GrantReportStatus::Accepted => [GrantReportStatus::Submitted, GrantReportStatus::Accepted],
            GrantReportStatus::RevisionRequested => [GrantReportStatus::Submitted, GrantReportStatus::RevisionRequested],
        };
        foreach ($steps as $index => $step) {
            Grants::moveReport($report, $step, $this->day($dueInDays + 2 + $index * 7), $by);
        }
    }

    private function seedWash(Grant $grant): void
    {
        $project = $this->project(
            'WASH', 'Clean Water for Juba Communities', 'Boreholes, sanitation and hygiene promotion for 12,000 people in Juba County.',
            'Juba County communities', 'achol', Project::ACTIVE, -220, 240,
        );
        $this->team($project, ['achol' => ['Project manager', 80], 'james' => ['Field lead', 100], 'samuel' => ['Monitoring', 40], 'rose' => ['Hygiene promotion', 50]]);
        $this->approvedBudget($project, 'achol', [
            [CostCategory::Labour, 'Field and programme staff', 42_000_00],
            [CostCategory::Materials, 'Pumps, pipes and construction materials', 38_000_00],
            [CostCategory::Equipment, 'Water testing equipment', 12_000_00],
            [CostCategory::Travel, 'Vehicle hire and field travel', 9_000_00],
            [CostCategory::Subcontract, 'Borehole drilling and latrine construction', 46_000_00],
            [CostCategory::Other, 'Community meetings', 3_000_00],
        ]);
        Grants::allocate($grant, $project, 150_000_00, null, 'Programme costs', $this->users['peter']);

        $this->spend($project, $grant, 'achol', CostCategory::Subcontract, 'Drilling of six boreholes', 'Kator Drilling Services', 28_500_00, 'paid', -120, 'PV-2026-0212');
        $this->spend($project, $grant, 'james', CostCategory::Materials, 'Twelve hand pumps', 'Konyo Hardware Supplies', 9_600_00, 'paid', -95, 'PV-2026-0267');
        $this->spend($project, $grant, 'james', CostCategory::Travel, 'Vehicle hire, third quarter', 'Gudele Transport', 2_400_00, 'paid', -30, 'PV-2026-0388');
        $this->spend($project, $grant, 'samuel', CostCategory::Equipment, 'Water quality testing kits', 'Munuki Lab Supplies', 1_850_00, 'approved');
        $this->spend($project, $grant, 'james', CostCategory::Materials, 'Distribution pipes, second phase', 'Konyo Hardware Supplies', 4_200_00, 'requested');
        $this->spend($project, $grant, 'achol', CostCategory::Subcontract, 'Latrine construction, four schools', 'Rejaf Builders', 12_000_00, 'partly');
        $this->spend($project, null, 'james', CostCategory::Equipment, 'Second backup generator', 'Juba Power Equipment', 3_100_00, 'rejected', reason: 'Not in the agreed workplan: hire one for the drilling weeks instead.');

        $list = $project->lists()->firstOrFail();
        foreach ([['Planned', Task::TODO], ['In the field', Task::IN_PROGRESS], ['Quality check', Task::REVIEW], ['Commissioned', Task::DONE]] as [$name, $category]) {
            ListStatuses::add($list, $name, $category, $this->users['achol']);
        }

        $tasks = $this->work($project, 'achol', [
            ['Community mobilisation complete', -150, true],
            ['Six boreholes commissioned', -20, false],
            ['Handover to water committees', 180, false],
        ], [
            ['Sign agreements with community leaders', Task::DONE, TaskPriority::High, 'achol', 0, -170, 16],
            ['Baseline water access survey', Task::DONE, TaskPriority::High, 'samuel', 0, -155, 40],
            ['Select drilling contractor', Task::DONE, TaskPriority::High, 'achol', 1, -130, 12],
            ['Drill boreholes in Gudele and Munuki', Task::DONE, TaskPriority::Urgent, 'james', 1, -60, 60],
            ['Drill boreholes in Kator and Rejaf', Task::IN_PROGRESS, TaskPriority::Urgent, 'james', 1, -8, 60],
            ['Test water quality at every borehole', Task::TODO, TaskPriority::High, 'samuel', 1, 12, 24],
            ['Train water committees on pump repair', Task::REVIEW, TaskPriority::Medium, 'james', 2, 30, 30],
            ['Hygiene promotion sessions in schools', Task::IN_PROGRESS, TaskPriority::Medium, 'rose', 2, 60, 80],
            ['Build latrines at four schools', Task::TODO, TaskPriority::Medium, null, 2, 110, null],
            ['Endline survey and handover report', Task::TODO, TaskPriority::Low, 'samuel', 2, 170, 40],
            ['Pump test at Gudele borehole', Task::DONE, TaskPriority::High, 'james', 1, -74, 8],
            ['Community consultation in Munuki', Task::DONE, TaskPriority::Medium, 'rose', 1, -52, 12],
            ['Pump test at Munuki borehole', Task::DONE, TaskPriority::High, 'james', 1, -49, 8],
            ['Spare parts order for hand pumps', Task::DONE, TaskPriority::Medium, 'james', 1, -38, 6],
            ['Site safety review with the contractor', Task::DONE, TaskPriority::High, 'achol', 1, -24, 6],
            ['Quarterly monitoring visit', Task::DONE, TaskPriority::Medium, 'samuel', 1, -17, 16],
            ['Water committee elections in Gudele', Task::DONE, TaskPriority::Medium, 'rose', 2, -10, 10],
        ]);
        $this->tasks->addDependency($tasks[5], $tasks[4], $this->users['achol']);
        $this->tasks->addDependency($tasks[9], $tasks[6], $this->users['achol']);
        foreach ([['Rejaf site survey and access check', Task::DONE, 'james', -30], ['Drill and case the Kator boreholes', Task::IN_PROGRESS, 'james', -8], ['Install hand pumps at Rejaf', Task::TODO, null, 5]] as [$title, $status, $assignee, $dueInDays]) {
            $this->tasks->addSubtask($tasks[4], [
                'title' => $title,
                'status' => $status,
                'priority' => TaskPriority::High,
                'assignee_id' => $assignee === null ? null : $this->people[$assignee]->id,
                'due_on' => $this->day($dueInDays),
            ], $this->users['achol']);
        }
        $this->tasks->addCollaborator($tasks[4], $this->people['samuel'], $this->users['achol']);
        $this->tasks->addCollaborator($tasks[7], $this->people['james'], $this->users['achol']);
        foreach (['Agree session dates with head teachers', 'Print hygiene posters', 'Collect attendance sheets'] as $index => $step) {
            $item = $this->tasks->addChecklistItem($tasks[7], $step);
            if ($index === 0) {
                $this->tasks->toggleChecklistItem($tasks[7], $item, $this->users['james']);
            }
        }
        $this->comment($tasks[4], 'james', 'The Rejaf site is reachable again now the road has dried out. Drilling restarts on Monday.');
        $this->comment($tasks[4], 'achol', 'Good. @James Wani please send the contractor the revised dates so the invoice schedule matches. @'.$this->users['owner']->name.' this may move the handover date.');
        $this->comment($tasks[6], 'samuel', 'Attendance sheets are checked: 46 committee members across eight sites.');

        $this->risk($project, 'Road access lost in the rainy season', 4, 4, Risk::MITIGATING, 'james', 'Schedule drilling for the dry months and pre-position materials at each site.', 21);
        $this->risk($project, 'Fuel price rises raise drilling costs', 3, 3, Risk::OPEN, 'achol', 'Fixed-price contract; review the travel budget quarterly.', 45);
        $this->risk($project, 'Pumps fall out of use after handover', 2, 4, Risk::OPEN, 'samuel', 'Train committees and agree a spare parts supplier before handover.', 90);
    }

    private function seedEducation(Grant $grant): void
    {
        $project = $this->project(
            'EDU', "Girls' Education in Yei", 'Classrooms, learning materials and bursaries keeping 800 girls in school.',
            'Yei River County schools', 'mary', Project::ACTIVE, -140, 200,
        );
        $this->team($project, ['mary' => ['Project manager', 100], 'samuel' => ['Monitoring', 40]]);
        $this->approvedBudget($project, 'mary', [
            [CostCategory::Labour, 'Programme staff', 30_000_00],
            [CostCategory::Materials, 'Textbooks and learning materials', 28_000_00],
            [CostCategory::Travel, 'Field travel', 6_000_00],
            [CostCategory::Subcontract, 'Classroom rehabilitation', 24_000_00],
            [CostCategory::Other, 'Community engagement', 3_800_00],
        ]);
        // EUR 85,000 at 1.08 is USD 91,800, the whole budget.
        Grants::allocate($grant, $project, 85_000_00, '1.08', 'Rate agreed with the funder at award', $this->users['peter']);

        $this->spend($project, $grant, 'mary', CostCategory::Materials, 'Textbooks for twelve schools', 'Yei Educational Books', 11_400_00, 'paid', -70, 'PV-2026-0301');
        $this->spend($project, $grant, 'samuel', CostCategory::Travel, 'Term one school visits', 'Yei Town Transport', 1_250_00, 'paid', -40, 'PV-2026-0359');
        $this->spend($project, $grant, 'mary', CostCategory::Subcontract, 'Rehabilitate six classrooms', 'Lasu Construction', 18_000_00, 'approved');
        $this->spend($project, $grant, 'samuel', CostCategory::Materials, 'Exercise books and stationery', 'Yei Educational Books', 3_300_00, 'requested');
        $this->spend($project, $grant, 'mary', CostCategory::Travel, 'Regional education workshop', 'Yei Town Transport', 900_00, 'cancelled');
        Spending::record($project, ['category' => CostCategory::Other, 'description' => 'Parents meeting refreshments', 'supplier' => null, 'amount_minor' => 180_00, 'grant_id' => null], $this->day(-15), $this->users['mary']);

        $tasks = $this->work($project, 'mary', [
            ['Schools and girls enrolled', -90, true],
            ['Classrooms rehabilitated', -5, false],
            ['End of year results reported', 190, false],
        ], [
            ['Agree school list with the county education office', Task::DONE, TaskPriority::High, 'mary', 0, -120, 12],
            ['Enrol girls in the bursary programme', Task::DONE, TaskPriority::High, 'mary', 0, -95, 30],
            ['Distribute textbooks to schools', Task::REVIEW, TaskPriority::Medium, 'samuel', 1, -12, 24],
            ['Supervise classroom rehabilitation', Task::IN_PROGRESS, TaskPriority::Urgent, 'mary', 1, -3, 40],
            ['Fix attendance tracking in term two', Task::TODO, TaskPriority::High, 'samuel', 2, 20, 16],
            ['Set up mothers groups at each school', Task::IN_PROGRESS, TaskPriority::Medium, 'mary', 2, 75, 36],
            ['Collect end of year exam results', Task::TODO, TaskPriority::Low, 'samuel', 2, 180, 20],
            ['Teacher training on inclusive classrooms', Task::DONE, TaskPriority::High, 'mary', 1, -66, 24],
            ['Term two attendance review', Task::DONE, TaskPriority::Medium, 'samuel', 1, -45, 8],
            ['School meal pilot in two schools', Task::DONE, TaskPriority::Medium, 'mary', 1, -31, 20],
            ['Parent meetings before the term', Task::DONE, TaskPriority::Low, 'mary', 2, -19, 8],
            ['Bursary payments for term three', Task::DONE, TaskPriority::High, 'samuel', 2, -5, 10],
        ]);
        $this->comment($tasks[3], 'mary', 'Two classrooms are behind because roofing sheets arrived late. The contractor expects to catch up in two weeks.');
        $this->comment($tasks[2], 'samuel', 'Delivery notes are in from ten of the twelve schools.');

        $this->risk($project, 'Insecurity closes schools on the Morobo road', 3, 5, Risk::OPEN, 'mary', 'Follow the security briefings; keep materials at the two safest schools.', 7);
        $this->risk($project, 'Second funder payment arrives late', 4, 3, Risk::OPEN, 'mary', 'Finance to chase the funder and sequence contractor payments.', 14);
    }

    /**
     * In planning: its budget awaits approval and its grant is still applied for.
     */
    private function seedFoodSecurity(): void
    {
        $project = $this->project(
            'FSL', 'Food Security and Livelihoods, Bor', 'Seed, tools and farmer groups for 1,500 flood-affected households.',
            'Bor South households', 'achol', Project::PLANNING, 45, 410,
        );
        $this->team($project, ['achol' => ['Project manager', 20]]);

        $by = $this->users['achol'];
        foreach ([
            [CostCategory::Labour, 'Field staff', 54_000_00],
            [CostCategory::Materials, 'Seed and tools', 82_000_00],
            [CostCategory::Travel, 'Field travel', 18_000_00],
            [CostCategory::Subcontract, 'Farmer training', 26_000_00],
        ] as [$category, $description, $amount]) {
            Budgets::addLine($project, $category, $description, $amount, $by);
        }
        $version = Budgets::working($project);
        assert($version !== null);
        Budgets::submit($project, $version, 'Submitted with the application to the Greenfield Foundation.', $by);

        $this->work($project, 'achol', [['Project start agreed', 45, false]], [
            ['Needs assessment with county agriculture office', Task::IN_PROGRESS, TaskPriority::High, 'achol', 0, 15, 30],
            ['Identify farmer groups', Task::TODO, TaskPriority::Medium, 'achol', 0, 35, 24],
            ['Tender for seed supplier', Task::TODO, TaskPriority::Medium, null, null, 60, null],
        ]);

        $this->risk($project, 'Funding is not awarded in time for planting', 3, 5, Risk::OPEN, 'achol', 'Plan a smaller first phase with unrestricted funds if the decision is late.', 30);
    }

    /**
     * Finished: everything spent and reported, its grant closed.
     */
    private function seedCholeraResponse(Grant $grant): void
    {
        $project = $this->project(
            'CHOL', 'Cholera Response, Juba', 'Emergency chlorination, treatment kits and hygiene messaging during the outbreak.',
            'Juba City', 'achol', Project::ACTIVE, -205, -35,
        );
        $this->team($project, ['achol' => ['Project manager', 30], 'james' => ['Field lead', 100], 'rose' => ['Hygiene promotion', 100]]);
        $this->approvedBudget($project, 'achol', [
            [CostCategory::Labour, 'Response staff', 8_000_00],
            [CostCategory::Materials, 'Treatment kits and chlorine', 14_000_00],
            [CostCategory::Travel, 'Field travel', 5_000_00],
            [CostCategory::Other, 'Radio messaging', 3_000_00],
        ]);
        Grants::allocate($grant, $project, 30_000_00, null, null, $this->users['peter']);

        $this->spend($project, $grant, 'james', CostCategory::Materials, 'Treatment kits and chlorine', 'Juba Medical Supplies', 13_400_00, 'paid', -190, 'PV-2026-0098');
        $this->spend($project, $grant, 'james', CostCategory::Travel, 'Field travel during the response', 'Gudele Transport', 4_600_00, 'paid', -120, 'PV-2026-0177');
        $this->spend($project, $grant, 'achol', CostCategory::Other, 'Radio hygiene messages', 'Juba Community Radio', 2_700_00, 'paid', -150, 'PV-2026-0141');

        $this->work($project, 'achol', [['Outbreak response closed', -35, true]], [
            ['Chlorinate water points in affected areas', Task::DONE, TaskPriority::Urgent, 'james', 0, -180, 60],
            ['Distribute treatment kits', Task::DONE, TaskPriority::Urgent, 'james', 0, -150, 40],
            ['Run hygiene radio messages', Task::DONE, TaskPriority::High, 'rose', 0, -100, 30],
            ['Final report to the funder', Task::DONE, TaskPriority::Medium, 'achol', 0, -45, 16],
        ]);
        $this->risk($project, 'Outbreak spreads to neighbouring counties', 3, 5, Risk::CLOSED, 'achol', 'Coordinated with the health cluster until no new cases were reported.', -40);

        $project->forceFill(['status' => Project::COMPLETED, 'completed_at' => $this->day(-35)])->save();
        ActivityLog::record($project, $this->users['achol'], "{$project->code} completed");
        Grants::close($grant, $this->day(-25), $this->users['peter']);
    }

    private function project(string $code, string $name, string $description, string $client, string $manager, string $status, int $startsInDays, int $dueInDays): Project
    {
        $by = $this->users[$manager];
        $project = Project::query()->create([
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'client' => $client,
            'manager_id' => $this->people[$manager]->id,
            'status' => $status,
            'start_on' => $this->day($startsInDays),
            'due_on' => $this->day($dueInDays),
            'currency' => 'USD',
            'created_by' => $by->id,
        ]);
        ActivityLog::record($project, $by, "Set up {$project->code}: {$project->name}");
        WorkStructure::setUpProject($project, $by);

        return $project;
    }

    /**
     * @param  array<string, array{string, int}>  $members  role and allocation by person
     */
    private function team(Project $project, array $members): void
    {
        foreach ($members as $key => [$role, $allocation]) {
            ProjectMember::query()->create(['project_id' => $project->id, 'person_id' => $this->people[$key]->id, 'role' => $role, 'allocation_percent' => $allocation]);
        }
    }

    /**
     * Drafted and submitted by the manager, approved by the controller.
     *
     * @param  list<array{CostCategory, string, int}>  $lines
     */
    private function approvedBudget(Project $project, string $manager, array $lines): void
    {
        $by = $this->users[$manager];
        foreach ($lines as [$category, $description, $amount]) {
            Budgets::addLine($project, $category, $description, $amount, $by);
        }

        $version = Budgets::working($project);
        assert($version !== null);
        Budgets::submit($project, $version, null, $by);
        Budgets::approve($project, $version, $this->users['peter']);
    }

    /**
     * Spending taken as far as $stage: requested, partly (one of two
     * approvals), approved, paid, rejected or cancelled. The requester,
     * approvers and payer are different people, as the rules require.
     */
    private function spend(Project $project, ?Grant $grant, string $requester, CostCategory $category, string $description, string $supplier, int $amountMinor, string $stage, int $paidInDays = 0, string $reference = '', string $reason = ''): void
    {
        $controller = $this->users['peter'];
        $expense = Spending::request($project, [
            'category' => $category,
            'description' => $description,
            'supplier' => $supplier,
            'amount_minor' => $amountMinor,
            'grant_id' => $grant?->id,
        ], $this->users[$requester]);

        if ($stage === 'rejected') {
            Spending::reject($expense, $reason, $controller);

            return;
        }
        if ($stage === 'cancelled') {
            Spending::cancel($expense, null, $this->users[$requester]);

            return;
        }
        if ($stage === 'requested') {
            return;
        }

        Spending::approve($expense, null, $controller);
        if ($stage === 'partly') {
            return;
        }
        if ($expense->approvals_required > 1) {
            Spending::approve($expense, null, $this->users['owner']);
        }
        if ($stage === 'paid') {
            Spending::pay($expense, $this->day($paidInDays), $reference, $this->users['grace']);
        }
    }

    /**
     * Milestones and tasks, with time logged on the work already started.
     *
     * @param  list<array{string, int, bool}>  $milestones  name, due in days, completed
     * @param  list<array{string, string, TaskPriority, ?string, ?int, int, ?int}>  $tasks  title, status, priority, assignee, milestone index, due in days, estimated hours
     * @return list<Task>
     */
    private function work(Project $project, string $manager, array $milestones, array $tasks): array
    {
        $by = $this->users[$manager];

        $milestoneIds = [];
        foreach ($milestones as [$name, $dueInDays, $completed]) {
            $milestoneIds[] = Milestone::query()->create([
                'project_id' => $project->id,
                'name' => $name,
                'due_on' => $this->day($dueInDays),
                'completed_at' => $completed ? $this->day($dueInDays) : null,
            ])->id;
        }

        $created = [];
        foreach ($tasks as [$title, $status, $priority, $assignee, $milestone, $dueInDays, $estimate]) {
            $task = $this->tasks->create($project, [
                'title' => $title,
                'status' => $status,
                'priority' => $priority,
                'assignee_id' => $assignee === null ? null : $this->people[$assignee]->id,
                'milestone_id' => $milestone === null ? null : $milestoneIds[$milestone],
                'start_on' => $this->day($dueInDays - 21),
                'due_on' => $this->day($dueInDays),
                'estimate_hours' => $estimate,
            ], $by);
            if ($status === Task::DONE) {
                $task->forceFill(['completed_at' => $this->day($dueInDays)])->save();
            }
            $created[] = $task;

            if ($assignee !== null && $estimate !== null) {
                $this->logTime($project, $task, $assignee, $status, $dueInDays, $estimate);
            }
        }

        return $created;
    }

    /**
     * All of the estimate on finished work, part of it on work under way,
     * in two sittings before the due date (or before today, if that is sooner).
     */
    private function logTime(Project $project, Task $task, string $assignee, string $status, int $dueInDays, int $estimate): void
    {
        $share = match ($status) {
            Task::DONE => 1.0,
            Task::REVIEW => 0.8,
            Task::IN_PROGRESS => 0.5,
            default => 0.0,
        };
        if ($share === 0.0) {
            return;
        }

        $lastDay = min($dueInDays, -1);
        $minutes = (int) round($estimate * 60 * $share / 2);
        $timesheets = new Timesheets;
        foreach ([$lastDay - 6, $lastDay] as $offset) {
            $timesheets->log($project, $task, $this->people[$assignee], $this->day($offset), min($minutes, Timesheets::MAX_MINUTES), null, $this->users['achol']);
        }
    }

    private function comment(Task $task, string $author, string $body): void
    {
        $this->tasks->addComment($task, $body, $this->users[$author]);
    }

    private function risk(Project $project, string $title, int $probability, int $impact, string $status, string $owner, string $mitigation, int $reviewInDays): void
    {
        Risk::query()->create([
            'project_id' => $project->id,
            'title' => $title,
            'probability' => $probability,
            'impact' => $impact,
            'status' => $status,
            'owner_id' => $this->people[$owner]->id,
            'mitigation' => $mitigation,
            'review_on' => $this->day($reviewInDays),
        ]);
    }

    private function day(int $offset): CarbonImmutable
    {
        return CarbonImmutable::today()->addDays($offset);
    }
}
