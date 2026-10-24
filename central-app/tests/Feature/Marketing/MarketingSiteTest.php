<?php

use App\Modules\Applications\Models\Application;
use App\Modules\Marketing\Models\ContactMessage;
use App\Modules\Marketing\Models\DemoRequest;
use App\Modules\Marketing\Notifications\LeadReceived;
use App\Modules\Marketing\Support\SpamGuard;
use App\Modules\Subscriptions\Models\Plan;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AdminFixtures;

uses(AdminFixtures::class);

beforeEach(function () {
    Notification::fake();
    config(['platform.marketing.sales_email' => 'sales@milesoftwares.test']);

    $this->retail = Application::query()->forceCreate([
        'code' => 'retail', 'name' => 'Retail', 'category' => 'operations', 'kind' => 'standalone', 'status' => 'available',
        'description' => 'Point of sale and stock.', 'sort_order' => 1,
        'capabilities' => ['marketing' => ['tagline' => 'Sell more, count less.', 'features' => ['Multi-branch stock', 'Offline tills']]],
    ]);
    Application::query()->forceCreate(['code' => 'school', 'name' => 'School', 'category' => 'education', 'kind' => 'standalone', 'status' => 'planned', 'sort_order' => 2]);
    Application::query()->forceCreate(['code' => 'legacy', 'name' => 'Legacy', 'category' => 'operations', 'kind' => 'standalone', 'status' => 'retired']);
    Application::query()->forceCreate(['code' => 'platform', 'name' => 'Platform', 'category' => 'platform', 'kind' => 'platform', 'status' => 'available']);

    $public = Plan::query()->forceCreate(['code' => 'retail-std', 'name' => 'Retail Standard', 'billing_interval' => 'monthly', 'price_minor' => 2500, 'currency' => 'USD', 'trial_days' => 14, 'is_public' => true, 'status' => 'active']);
    $public->applications()->attach($this->retail->id);
    $private = Plan::query()->forceCreate(['code' => 'retail-ent', 'name' => 'Retail Enterprise', 'billing_interval' => 'custom', 'price_minor' => 0, 'currency' => 'USD', 'is_public' => false, 'status' => 'active']);
    $private->applications()->attach($this->retail->id);
    $draft = Plan::query()->forceCreate(['code' => 'retail-new', 'name' => 'Retail Next', 'billing_interval' => 'monthly', 'price_minor' => 100, 'currency' => 'USD', 'is_public' => true, 'status' => 'draft']);
    $draft->applications()->attach($this->retail->id);
});

function site(string $path = ''): string
{
    return PlatformHosts::url(PlatformHosts::root(), $path);
}

/**
 * A human-looking submission: the form was rendered a few seconds ago.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function humanSubmission(array $overrides = []): array
{
    $token = SpamGuard::issueToken();
    test()->travel(5)->seconds();

    return array_merge([
        'name' => 'Amina Deng',
        'email' => 'Amina@Example.test',
        'company' => 'Manggala Trading',
        'message' => 'We have three branches and want to see stock transfers.',
        SpamGuard::TOKEN_FIELD => $token,
        SpamGuard::HONEYPOT_FIELD => '',
    ], $overrides);
}

it('lists released and upcoming products from the registry', function () {
    $this->get(site())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('public/home')
            ->where('canonical', site())
            ->where('products', fn ($products) => collect($products)->pluck('code')->all() === ['retail', 'school'])
            ->where('products.0.tagline', 'Sell more, count less.'));
});

it('always shows the public site in the light theme', function () {
    $this->withUnencryptedCookie('appearance', 'dark')->get(site())
        ->assertOk()
        ->assertSee('data-forced-appearance="light"', false)
        ->assertDontSee('class="dark"', false);

    $this->withUnencryptedCookie('appearance', 'dark')->get(PlatformHosts::url(PlatformHosts::auth(), 'login'))
        ->assertSee('class="dark"', false)
        ->assertDontSee('data-forced-appearance', false);
});

it('shows a product page with only its public active plans', function () {
    $this->get(site('products/retail'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('public/product')
            ->where('product.features', ['Multi-branch stock', 'Offline tills'])
            ->has('plans', 1)
            ->where('plans.0.name', 'Retail Standard')
            ->where('plans.0.price', 'USD 25.00'));
});

it('has no public page for the platform, retired or unknown applications', function (string $code) {
    $this->get(site("products/{$code}"))->assertNotFound();
})->with(['platform', 'legacy', 'nothing']);

it('prices only public active plans', function () {
    $this->get(site('pricing'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('plans', 1)
        ->where('plans.0.code', 'retail-std'));
});

it('publishes a sitemap and robots rules', function () {
    $this->get(site('sitemap.xml'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee(site('products/retail'), false)
        ->assertSee(site('start'), false)
        ->assertDontSee(site('demo'), false)
        ->assertSee(site('pricing'), false)
        ->assertDontSee('products/platform', false);

    $this->get(site('robots.txt'))->assertOk()->assertSee('Sitemap: '.site('sitemap.xml'), false);
    $this->get(PlatformHosts::url(PlatformHosts::app(), 'robots.txt'))->assertSee('Disallow: /', false);
    $this->get(PlatformHosts::url(PlatformHosts::admin(), 'robots.txt'))->assertSee('Disallow: /', false);
});

it('offers a free trial of released and beta applications only', function () {
    config(['platform.marketing.trial_days' => 60]);

    $this->get(site('start'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('public/start')
            ->where('trialDays', 60)
            ->where('selected', null)
            ->where('products', fn ($products) => collect($products)->pluck('code')->all() === ['retail']));

    $this->get(site('start?app=retail'))->assertInertia(fn (AssertableInertia $page) => $page->where('selected', 'retail'));
    $this->get(site('start?app=school'))->assertInertia(fn (AssertableInertia $page) => $page->where('selected', null));
});

it('sends old demo links to the free trial', function () {
    $this->get(site('demo'))->assertRedirect(site('start'))->assertStatus(301);
    $this->post(site('demo'), humanSubmission())->assertMethodNotAllowed();

    expect(DemoRequest::query()->count())->toBe(0);
});

it('stores a contact message and tells sales', function () {
    $this->post(site('contact'), humanSubmission(['subject' => 'Partnership']))->assertRedirect(site('thanks'));

    $lead = ContactMessage::query()->sole();
    expect($lead->subject)->toBe('Partnership')
        ->and($lead->status)->toBe('new')
        ->and($lead->email)->toBe('amina@example.test');

    Notification::assertSentTo(new AnonymousNotifiable, LeadReceived::class, fn (LeadReceived $mail, $channels, $notifiable) => $notifiable->routes['mail'] === 'sales@milesoftwares.test' && $mail->leadId === $lead->id);
});

it('quietly files bot submissions as spam', function (Closure $tamper) {
    $response = $this->post(site('contact'), $tamper(humanSubmission()));

    $response->assertRedirect(site('thanks'));
    expect(ContactMessage::query()->sole()->status)->toBe('spam');
    Notification::assertNothingSent();
})->with([
    'honeypot filled' => [fn (array $input) => [...$input, SpamGuard::HONEYPOT_FIELD => 'https://spam.example']],
    'forged token' => [fn (array $input) => [...$input, SpamGuard::TOKEN_FIELD => 'not-a-real-token']],
    'too many links' => [fn (array $input) => [...$input, 'message' => 'http://a.test http://b.test http://c.test http://d.test']],
]);

it('treats instant submissions as automated', function () {
    $this->post(site('contact'), [
        'name' => 'Bot', 'email' => 'bot@example.test', SpamGuard::TOKEN_FIELD => SpamGuard::issueToken(),
    ])->assertRedirect(site('thanks'));

    expect(ContactMessage::query()->sole()->status)->toBe('spam');
    Notification::assertNothingSent();
});

it('treats day-old forms as automated', function () {
    $input = humanSubmission();
    $this->travel(2)->days();

    $this->post(site('contact'), $input);

    expect(ContactMessage::query()->sole()->status)->toBe('spam');
});

it('validates genuine submissions', function () {
    $this->post(site('contact'), humanSubmission(['email' => 'not-an-email', 'name' => '']))
        ->assertSessionHasErrors(['email', 'name']);

    expect(ContactMessage::query()->count())->toBe(0);
});

it('rate limits each address', function () {

    foreach (range(1, 5) as $i) {
        $this->post(site('contact'), humanSubmission(['email' => "person{$i}@example.test"]))->assertRedirect();
    }

    $this->post(site('contact'), humanSubmission())->assertStatus(429);
    expect(ContactMessage::query()->count())->toBe(5);
});

it('shows leads to sales staff only', function () {
    $this->post(site('contact'), humanSubmission());

    $this->actingAs($this->staffWith(['sales'])->user)
        ->get($this->adminUrl('/leads'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('contactMessages', 1));

    $this->actingAs($this->staffWith(['support_agent'])->user)->get($this->adminUrl('/leads'))->assertForbidden();
});

it('lets sales move a lead along or restore false spam', function () {
    $this->post(site('contact'), [...humanSubmission(), SpamGuard::HONEYPOT_FIELD => 'x']);
    $lead = ContactMessage::query()->sole();

    $this->actingAs($this->staffWith(['sales'])->user)
        ->put($this->adminUrl("/leads/contact/{$lead->id}"), ['status' => 'new'])
        ->assertRedirect();

    expect($lead->refresh()->status)->toBe('new');
});

it('publishes marketing copy edited in the registry', function () {
    $this->actingAs($this->staffWith(['catalog_admin'])->user)
        ->put($this->adminUrl("/applications/{$this->retail->id}"), [
            'name' => 'Retail',
            'status' => 'available',
            'sort_order' => 1,
            'tagline' => 'Every branch, one stock count.',
            'features' => "Stock transfers\n\n  Daily cash-up  \nLoyalty points",
        ])
        ->assertSessionHasNoErrors();

    $this->get(site('products/retail'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('product.tagline', 'Every branch, one stock count.')
        ->where('product.features', ['Stock transfers', 'Daily cash-up', 'Loyalty points']));
});

it('serves the industries, catalog, platform, coming-soon and about pages', function (string $path, string $component) {
    $this->get(site($path))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component($component)
            ->where('canonical', site($path)));
})->with([
    ['industries', 'public/solutions'],
    ['erp', 'public/catalog'],
    ['apps', 'public/catalog'],
    ['platform', 'public/platform'],
    ['coming-soon', 'public/coming-soon'],
    ['about', 'public/about'],
    ['services', 'public/services'],
]);

it('moves the old solutions page to industries', function () {
    $this->get(site('solutions'))->assertMovedPermanently()->assertRedirect(site('industries'));
});

it('lists one category per catalog page, with every product of the brief', function () {
    $this->get(site('erp'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('category', 'erp')
        ->where('catalog', fn ($catalog) => collect($catalog)->pluck('slug')->all() === ['pharmacy', 'retail', 'school', 'logistics', 'ngo']));

    $this->get(site('apps'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('category', 'application')
        ->where('catalog', fn ($catalog) => collect($catalog)->pluck('slug')->all() === ['finance', 'projects', 'hr', 'procurement', 'inventory', 'crm', 'assets', 'documents']));
});

it('shows only unreleased catalog products on the coming-soon page', function () {
    $this->get(site('coming-soon'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('catalog', fn ($catalog) => collect($catalog)->every(fn ($entry) => $entry['status'] === 'coming_soon' && $entry['trial'] === null)
            && collect($catalog)->pluck('slug')->contains('logistics')));
});

it('gives each catalog product its own page, and 404s anything else', function () {
    $this->get(site('apps/projects'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/landing')
        ->where('product.name', 'Project Management')
        ->where('product.status', 'coming_soon')
        ->where('canonical', site('apps/projects')));

    $this->get(site('apps/nothing'))->assertNotFound();
    $this->get(site('erp/finance'))->assertNotFound();
});

it('lists the new pages in the sitemap', function () {
    $this->get(site('sitemap.xml'))
        ->assertSee(site('industries'), false)
        ->assertSee(site('erp'), false)
        ->assertSee(site('apps'), false)
        ->assertSee(site('erp/pharmacy'), false)
        ->assertSee(site('apps/documents'), false)
        ->assertSee(site('platform'), false)
        ->assertSee(site('coming-soon'), false)
        ->assertSee(site('about'), false)
        ->assertSee(site('services'), false)
        ->assertDontSee(site('solutions'), false);
});

it('fills in the contact subject from the link, trimmed and capped', function () {
    $this->get(site('contact?subject=Waitlist:%20School'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('subject', 'Waitlist: School'));

    $this->get(site('contact?subject='.str_repeat('a', 300)))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('subject', str_repeat('a', 120)));

    $this->get(site('contact'))->assertInertia(fn (AssertableInertia $page) => $page->where('subject', null));
});
