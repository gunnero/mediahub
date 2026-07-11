<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Alert;
use App\Models\Invite;
use App\Models\Show;
use App\Models\User;
use App\Services\InviteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackendFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_endpoint_reports_app_and_database_readiness(): void
    {
        $this->getJson('/api/v1/status')
            ->assertOk()
            ->assertJsonPath('app.ready', true)
            ->assertJsonPath('database.ready', true)
            ->assertJsonPath('queue.connection', 'sync');
    }

    public function test_invite_only_registration_accepts_valid_invite_and_audits_it(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner,
            'status' => UserStatus::Active,
        ]);

        $token = app(InviteService::class)->create(
            email: 'new.member@example.com',
            role: UserRole::Member,
            inviter: $owner,
        );

        $this->assertDatabaseMissing('users', ['email' => 'new.member@example.com']);
        $this->assertNotEquals($token, Invite::firstOrFail()->token_hash);

        $this->postJson('/api/v1/register', [
            'email' => 'new.member@example.com',
            'password' => 'not-used',
        ])->assertNotFound();

        $this->postJson('/api/v1/invites/accept', [
            'token' => $token,
            'name' => 'New Member',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertCreated()
            ->assertJsonPath('user.email', 'new.member@example.com')
            ->assertJsonPath('user.role', UserRole::Member->value);

        $member = User::where('email', 'new.member@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('a-strong-password', $member->password));
        $this->assertAuthenticatedAs($member);
        $this->assertDatabaseHas('invites', [
            'email' => 'new.member@example.com',
            'accepted_by_user_id' => $member->id,
        ]);
        $this->assertDatabaseHas('analytics_events', [
            'event_name' => 'invite.accepted',
            'actor_user_id' => $member->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invite.accepted',
            'actor_user_id' => $member->id,
            'target_user_id' => $member->id,
        ]);
    }

    public function test_login_logout_and_me_endpoint_use_active_invited_users(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'password' => Hash::make('secret-password'),
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'member@example.com',
            'password' => 'secret-password',
        ])->assertNoContent();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('analytics_events', [
            'actor_user_id' => $user->id,
            'event_name' => 'user.login',
        ]);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'member@example.com')
            ->assertJsonPath('user.role', UserRole::Member->value);

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_private_api_routes_require_authentication(): void
    {
        $this->get('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $this->postJson('/api/v1/alerts/1/read')->assertUnauthorized();
        $this->postJson('/api/v1/alerts/read-all')->assertUnauthorized();
    }

    public function test_dashboard_returns_empty_payload_for_authenticated_new_user(): void
    {
        $user = User::factory()->create([
            'name' => 'Fresh User',
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('profile.name', 'Fresh User')
            ->assertJsonPath('stats.episodesWatched', 0)
            ->assertJsonPath('stats.moviesWatched', 0)
            ->assertJsonPath('stats.hoursWatched', 0)
            ->assertJsonPath('stats.showsFollowed', 0)
            ->assertJsonPath('stats.alertsUnread', 0)
            ->assertJsonCount(0, 'alerts')
            ->assertJsonCount(0, 'recentlyWatched')
            ->assertJsonCount(0, 'followedNewEpisodes')
            ->assertJsonCount(0, 'moviesToCheckOut')
            ->assertJsonCount(0, 'topShows')
            ->assertJsonCount(7, 'activity');

        $this->assertDatabaseHas('analytics_events', [
            'actor_user_id' => $user->id,
            'event_name' => 'dashboard.viewed',
        ]);
    }

    public function test_alert_read_actions_persist_and_record_analytics(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);
        $alert = Alert::factory()->create([
            'user_id' => $user->id,
            'unread' => true,
            'read_at' => null,
        ]);
        Alert::factory()->create([
            'user_id' => $user->id,
            'unread' => true,
            'read_at' => null,
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/alerts/{$alert->id}/read")
            ->assertOk()
            ->assertJsonPath('alert.unread', false);

        $this->assertFalse($alert->fresh()->unread);
        $this->assertNotNull($alert->fresh()->read_at);
        $this->assertDatabaseHas('analytics_events', [
            'actor_user_id' => $user->id,
            'event_name' => 'alert.read',
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/alerts/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertSame(0, Alert::where('user_id', $user->id)->where('unread', true)->count());
        $this->assertDatabaseHas('analytics_events', [
            'actor_user_id' => $user->id,
            'event_name' => 'alert.read_all',
        ]);
    }

    public function test_users_cannot_access_another_users_alerts_or_library_counts(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);
        $otherUser = User::factory()->create([
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);
        $otherAlert = Alert::factory()->create([
            'user_id' => $otherUser->id,
            'unread' => true,
        ]);
        Show::factory()->create([
            'user_id' => $otherUser->id,
            'followed' => true,
            'seen_episodes' => 12,
            'aired_episodes' => 12,
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/alerts/{$otherAlert->id}/read")
            ->assertNotFound();

        $this->assertTrue($otherAlert->fresh()->unread);

        $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.showsFollowed', 0)
            ->assertJsonPath('stats.alertsUnread', 0);
    }

    public function test_members_cannot_access_filament_admin(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();
    }
}
