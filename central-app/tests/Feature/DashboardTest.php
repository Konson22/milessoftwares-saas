<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_sign_in_before_their_businesses()
    {
        $this->get(route('workspaces'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_see_their_businesses()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('workspaces'))
            ->assertOk();
    }
}
