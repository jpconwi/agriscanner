<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Google is always faked: no network, no real credentials. */
class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
    }

    private function fakeGoogle(array $info = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'openidconnect.googleapis.com/*' => Http::response($info + ['sub' => 'g-123', 'email' => 'Ana@Example.com', 'email_verified' => true, 'name' => 'Ana Farmer']),
        ]);
    }

    private function callback(string $state = 'good-state', array $query = [])
    {
        return $this->withSession(['google_oauth_state' => 'good-state'])->get('/auth/google/callback?'.http_build_query(['code' => 'abc', 'state' => $state] + $query));
    }

    public function test_redirect_sends_the_user_to_google_with_a_state(): void
    {
        $res = $this->get('/auth/google');
        $res->assertRedirectContains('https://accounts.google.com/o/oauth2/v2/auth');
        $res->assertRedirectContains('client_id=test-client');
        $this->assertNotEmpty(session('google_oauth_state'));
    }

    public function test_it_is_disabled_when_not_configured(): void
    {
        config(['services.google.client_id' => null]);
        $this->get('/auth/google')->assertRedirect('/login')->assertSessionHas('error');
        $this->get('/login')->assertOk()->assertDontSee('Continue with Google');
    }

    public function test_login_page_shows_the_button_when_configured(): void
    {
        $this->get('/login')->assertOk()->assertSee('Continue with Google');
    }

    public function test_new_google_user_gets_a_farmer_account(): void
    {
        $this->fakeGoogle();
        $this->callback()->assertRedirect('/dashboard');

        $user = User::firstWhere('email', 'ana@example.com');
        $this->assertNotNull($user);
        $this->assertSame('g-123', $user->google_id);
        $this->assertSame('farmer', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('storage_quotas', ['user_id' => $user->id]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_existing_account_with_the_same_verified_email_is_linked(): void
    {
        $existing = User::factory()->create(['email' => 'ana@example.com']);
        $this->fakeGoogle();
        $this->callback()->assertRedirect('/dashboard');

        $this->assertSame(1, User::count());
        $this->assertSame('g-123', $existing->fresh()->google_id);
        $this->assertAuthenticatedAs($existing);
    }

    public function test_a_wrong_or_missing_state_is_rejected(): void
    {
        $this->fakeGoogle();
        $this->callback('forged')->assertRedirect('/login')->assertSessionHas('error');
        $this->assertGuest();
        $this->assertSame(0, User::count());
        Http::assertNothingSent();
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $this->fakeGoogle(['email_verified' => false]);
        $this->callback()->assertRedirect('/login')->assertSessionHas('error');
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_disabled_accounts_cannot_sign_in_with_google(): void
    {
        User::factory()->disabled()->create(['email' => 'ana@example.com']);
        $this->fakeGoogle();
        $this->callback()->assertRedirect('/login')->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_cancelled_sign_in_returns_to_login(): void
    {
        $this->callback('good-state', ['error' => 'access_denied', 'code' => ''])->assertRedirect('/login')->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_role_is_never_taken_from_google(): void
    {
        $this->fakeGoogle(['role' => 'admin', 'is_admin' => true]);
        $this->callback();
        $this->assertSame('farmer', User::firstWhere('email', 'ana@example.com')->role);
    }
}
