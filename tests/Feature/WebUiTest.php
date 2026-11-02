<?php

namespace Tests\Feature;

use App\Models\Scan;
use App\Models\User;
use Tests\Fakes\FakeVisionProvider as F;

/** Browser (Blade) flows. Providers and storage are faked; no network is used. */
class WebUiTest extends ScanTestCase
{
    private function web(array $attrs = []): User
    {
        $u = User::factory()->create($attrs);
        $this->actingAs($u);

        return $u;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        foreach (['/dashboard', '/scan', '/history', '/advisories', '/storage', '/profile', '/admin'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_public_pages_render(): void
    {
        $this->get('/')->assertOk();
        $this->get('/login')->assertOk()->assertSee('Sign in');
        $this->get('/register')->assertOk();
        $this->get('/forgot-password')->assertOk();
    }

    public function test_register_logs_in_and_lands_on_dashboard(): void
    {
        $this->post('/register', ['name' => 'Ana Farmer', 'email' => 'ana@example.com', 'password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->assertSame('farmer', User::firstWhere('email', 'ana@example.com')->role);
        $this->get('/dashboard')->assertOk();
    }

    public function test_login_failure_uses_one_generic_message(): void
    {
        User::factory()->create(['email' => 'a@example.com']);
        $a = $this->from('/login')->post('/login', ['email' => 'a@example.com', 'password' => 'wrong-password']);
        $b = $this->from('/login')->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']);
        $a->assertSessionHasErrors('email');
        $this->assertSame(session('errors')->first('email'), $a->baseResponse->getSession()->get('errors')->first('email'));
        $b->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_disabled_accounts_cannot_use_the_app(): void
    {
        $u = User::factory()->disabled()->create();
        $this->actingAs($u)->get('/dashboard')->assertRedirect('/login');
    }

    public function test_logout_ends_the_session(): void
    {
        $this->web();
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_farmers_get_403_on_admin_pages_and_admins_get_in(): void
    {
        $this->web();
        foreach (['/admin', '/admin/users', '/admin/reference', '/admin/advisories', '/admin/infrastructure'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->actingAs(User::factory()->admin()->create());
        foreach (['/admin', '/admin/users', '/admin/reference', '/admin/advisories'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_infrastructure_tabs_render_for_admins(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach (['ai', 'db', 'storage', 'pending', 'settings', 'logs'] as $tab) {
            $this->get('/admin/infrastructure?tab='.$tab)->assertOk();
        }
    }

    public function test_a_farmer_cannot_post_admin_actions(): void
    {
        $this->web();
        $this->post('/admin/crops', ['name' => 'Rice'])->assertForbidden();
        $this->post('/admin/infrastructure/checks')->assertForbidden();
    }

    public function test_uploading_a_photo_creates_a_scan_and_shows_the_result(): void
    {
        $user = $this->web();
        $this->hf->script = [F::result()];

        $res = $this->post('/scan', ['image' => $this->photo(), 'idempotency_key' => 'web-key-00001']);

        $scan = Scan::where('user_id', $user->id)->firstOrFail();
        $res->assertRedirect(route('scans.show', $scan->uuid));
        $this->get(route('scans.show', $scan->uuid))->assertOk()->assertSee('Early blight')->assertSee('Remove infected leaves');
        $this->get('/history')->assertOk();
    }

    public function test_failed_analysis_flashes_an_error_and_saves_nothing(): void
    {
        $user = $this->web();
        $this->hf->configured = false;
        $this->grok->configured = false;

        $this->from('/scan')->post('/scan', ['image' => $this->photo(), 'idempotency_key' => 'web-key-00002'])
            ->assertRedirect('/scan')->assertSessionHas('error');
        $this->assertSame(0, Scan::where('user_id', $user->id)->count());
    }

    public function test_other_users_scans_are_404(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $this->hf->script = [F::result()];
        $this->post('/scan', ['image' => $this->photo(), 'idempotency_key' => 'web-key-00003']);
        $scan = Scan::firstWhere('user_id', $owner->id);

        $this->actingAs(User::factory()->create());
        $this->get(route('scans.show', $scan->uuid))->assertNotFound();
        $this->delete(route('scans.destroy', $scan->uuid))->assertNotFound();
        $this->assertDatabaseHas('scans', ['id' => $scan->id]);
    }

    public function test_non_images_are_rejected_with_a_validation_error(): void
    {
        $this->web();
        $file = \Illuminate\Http\UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf');
        $this->from('/scan')->post('/scan', ['image' => $file, 'idempotency_key' => 'web-key-00004'])->assertSessionHasErrors('image');
    }

    public function test_admin_can_create_a_crop_and_a_disease_from_the_forms(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/admin/crops', ['name' => 'Rice'])->assertRedirect();
        $crop = \App\Models\Crop::firstWhere('name', 'Rice');
        $this->post('/admin/diseases', ['name' => 'Blast', 'crop_id' => $crop->id, 'severity' => 'high', 'symptoms' => "Diamond lesions\nGrey centres"])->assertRedirect();
        $this->assertDatabaseHas('diseases', ['name' => 'Blast']);
        $this->assertDatabaseHas('disease_symptoms', ['text' => 'Grey centres']);
    }
}
