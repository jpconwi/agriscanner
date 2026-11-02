<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        return array_merge(['name' => 'Maria Santos', 'email' => 'maria@example.com', 'password' => 'Sup3rSecret99', 'password_confirmation' => 'Sup3rSecret99'], $over);
    }

    public function test_registration_creates_a_farmer_with_token_and_quota_rows(): void
    {
        $res = $this->postJson('/api/v1/register', $this->payload())->assertCreated();

        $res->assertJsonPath('data.user.role', 'farmer')->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email']]]);
        $user = User::firstWhere('email', 'maria@example.com');
        $this->assertNotSame('Sup3rSecret99', $user->password, 'password must be hashed');
        $this->assertDatabaseHas('storage_quotas', ['user_id' => $user->id, 'quota_bytes' => config('agriscan.quota.default_bytes')]);
        $this->assertDatabaseHas('storage_usage', ['user_id' => $user->id, 'used_bytes' => 0]);
    }

    public function test_role_cannot_be_chosen_at_registration(): void
    {
        $this->postJson('/api/v1/register', $this->payload(['role' => 'admin', 'is_active' => false]))->assertCreated();

        $user = User::firstWhere('email', 'maria@example.com');
        $this->assertSame('farmer', $user->role);
        $this->assertTrue($user->is_active);
    }

    public function test_registration_validates_input(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/register', $this->payload(['email' => 'taken@example.com']))->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/register', $this->payload(['password' => 'short1', 'password_confirmation' => 'short1']))->assertStatus(422);
        $this->postJson('/api/v1/register', $this->payload(['password' => 'allletterspassword', 'password_confirmation' => 'allletterspassword']))->assertStatus(422);
        $this->postJson('/api/v1/register', $this->payload(['password_confirmation' => 'different1234']))->assertStatus(422);
    }

    public function test_login_succeeds_and_returns_token(): void
    {
        User::factory()->create(['email' => 'a@example.com', 'password' => 'Password12345']);

        $this->postJson('/api/v1/login', ['email' => 'a@example.com', 'password' => 'Password12345'])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_failure_message_is_identical_for_unknown_email_and_wrong_password(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        $wrong = $this->postJson('/api/v1/login', ['email' => 'a@example.com', 'password' => 'nope-nope-nope'])->assertStatus(422);
        $unknown = $this->postJson('/api/v1/login', ['email' => 'ghost@example.com', 'password' => 'nope-nope-nope'])->assertStatus(422);

        $this->assertSame($wrong->json('message'), $unknown->json('message'));
    }

    public function test_disabled_accounts_cannot_log_in_or_use_existing_tokens(): void
    {
        $u = User::factory()->disabled()->create(['email' => 'off@example.com']);
        $this->postJson('/api/v1/login', ['email' => 'off@example.com', 'password' => 'Password12345'])->assertForbidden();

        $token = $u->createToken('t')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/user')->assertForbidden();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => 'x@example.com', 'password' => 'bad-password-1'])->assertStatus(422);
        }
        $this->postJson('/api/v1/login', ['email' => 'x@example.com', 'password' => 'bad-password-1'])->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/user')->assertUnauthorized();
        $this->getJson('/api/v1/scans')->assertUnauthorized();
        $this->getJson('/api/v1/storage/usage')->assertUnauthorized();
    }

    public function test_authenticated_user_can_fetch_profile_and_logout_revokes_token(): void
    {
        $this->postJson('/api/v1/register', $this->payload());
        $token = $this->postJson('/api/v1/login', ['email' => 'maria@example.com', 'password' => 'Sup3rSecret99'])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/user')->assertOk()->assertJsonPath('data.email', 'maria@example.com');
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withToken($token)->postJson('/api/v1/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1); // only the registration token remains
    }

    public function test_forgot_password_never_reveals_whether_the_email_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'real@example.com']);

        $a = $this->postJson('/api/v1/forgot-password', ['email' => 'real@example.com'])->assertOk();
        $b = $this->postJson('/api/v1/forgot-password', ['email' => 'ghost@example.com'])->assertOk();

        $this->assertSame($a->json('message'), $b->json('message'));
        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_password_reset_with_valid_token_works_and_revokes_old_tokens(): void
    {
        $user = User::factory()->create(['email' => 'real@example.com', 'password' => 'OldPassword123']);
        $user->createToken('old');
        $token = Password::createToken($user);

        $this->postJson('/api/v1/reset-password', ['email' => 'real@example.com', 'token' => $token, 'password' => 'BrandNewPass99', 'password_confirmation' => 'BrandNewPass99'])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/login', ['email' => 'real@example.com', 'password' => 'BrandNewPass99'])->assertOk();
    }

    public function test_password_reset_rejects_bad_token(): void
    {
        User::factory()->create(['email' => 'real@example.com']);

        $this->postJson('/api/v1/reset-password', ['email' => 'real@example.com', 'token' => 'bogus', 'password' => 'BrandNewPass99', 'password_confirmation' => 'BrandNewPass99'])->assertStatus(422);
    }

    public function test_password_change_requires_current_password(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/v1/user/password', ['current_password' => 'wrong', 'password' => 'BrandNewPass99', 'password_confirmation' => 'BrandNewPass99'])->assertStatus(422);
    }

    public function test_password_change_succeeds_and_keeps_only_the_current_token(): void
    {
        $user = User::factory()->create(['email' => 'pw@example.com', 'password' => 'OldPassword123']);
        $user->createToken('other-device');
        $current = $user->createToken('this-device')->plainTextToken;

        $this->withToken($current)->putJson('/api/v1/user/password', ['current_password' => 'OldPassword123', 'password' => 'BrandNewPass99', 'password_confirmation' => 'BrandNewPass99'])->assertOk();

        $this->assertSame(1, $user->tokens()->count());
        $this->postJson('/api/v1/login', ['email' => 'pw@example.com', 'password' => 'BrandNewPass99'])->assertOk();
    }
}
