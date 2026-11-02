<?php

namespace Tests\Feature;

use App\Models\Scan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public static function adminGetEndpoints(): array
    {
        return array_map(fn ($u) => [$u], [
            '/api/v1/admin/dashboard', '/api/v1/admin/users', '/api/v1/admin/storage', '/api/v1/admin/database-health',
            '/api/v1/admin/ai-health', '/api/v1/admin/system-health', '/api/v1/admin/crops', '/api/v1/admin/diseases',
            '/api/v1/admin/advisories', '/api/v1/admin/settings', '/api/v1/admin/pending-operations', '/api/v1/admin/logs',
        ]);
    }

    #[DataProvider('adminGetEndpoints')]
    public function test_admin_endpoints_reject_guests_and_farmers(string $url): void
    {
        $this->getJson($url)->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson($url)->assertForbidden();
    }

    public function test_admin_write_endpoints_reject_farmers(): void
    {
        $farmer = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($farmer);

        $this->patchJson("/api/v1/admin/users/{$other->id}", ['role' => 'admin'])->assertForbidden();
        $this->putJson("/api/v1/admin/users/{$other->id}/quota", ['quota_bytes' => 5000000])->assertForbidden();
        $this->deleteJson("/api/v1/admin/users/{$other->id}")->assertForbidden();
        $this->postJson('/api/v1/admin/crops', ['name' => 'Rice'])->assertForbidden();
        $this->postJson('/api/v1/admin/advisories', ['title' => 'x', 'body' => 'y', 'is_published' => true])->assertForbidden();
        $this->putJson('/api/v1/admin/settings', ['default_quota_bytes' => 5000000, 'warn_percent' => 70, 'critical_percent' => 90, 'db_warn_percent' => 80])->assertForbidden();
        $this->postJson('/api/v1/admin/system-health/run')->assertForbidden();

        $this->assertSame('farmer', $other->fresh()->role, 'role must be unchanged');
    }

    public function test_farmer_cannot_promote_themselves_through_profile_endpoints(): void
    {
        $farmer = User::factory()->create();
        Sanctum::actingAs($farmer);

        $this->putJson('/api/v1/user/profile', ['name' => 'New Name', 'role' => 'admin'])->assertOk();

        $this->assertSame('farmer', $farmer->fresh()->role);
    }

    public function test_admin_can_change_roles_and_quota_and_it_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $farmer = User::factory()->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$farmer->id}", ['role' => 'admin'])->assertOk();
        $this->assertSame('admin', $farmer->fresh()->role);

        $this->putJson("/api/v1/admin/users/{$farmer->id}/quota", ['quota_bytes' => 5242880])->assertOk()->assertJsonPath('data.quota_bytes', 5242880);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'admin.quota_set']);
    }

    public function test_the_last_administrator_cannot_be_demoted_disabled_or_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$admin->id}", ['role' => 'farmer'])->assertStatus(422);
        $this->patchJson("/api/v1/admin/users/{$admin->id}", ['is_active' => false])->assertStatus(422);
        $this->deleteJson("/api/v1/admin/users/{$admin->id}")->assertStatus(422);
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_disabling_a_user_revokes_their_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $farmer = User::factory()->create();
        $farmer->createToken('t');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$farmer->id}", ['is_active' => false])->assertOk();

        $this->assertSame(0, $farmer->tokens()->count());
    }

    public function test_a_farmer_cannot_read_or_delete_another_farmers_scan(): void
    {
        $owner = User::factory()->create();
        $scan = Scan::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $owner->id, 'idempotency_key' => 'owner-key-1', 'status' => 'completed']);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/scans/{$scan->uuid}")->assertNotFound();
        $this->deleteJson("/api/v1/scans/{$scan->uuid}")->assertNotFound();
        $this->assertDatabaseHas('scans', ['id' => $scan->id]);

        // Admins do not get access to private photos through the farmer endpoint either.
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson("/api/v1/scans/{$scan->uuid}")->assertNotFound();
    }

    public function test_scans_list_only_contains_own_scans(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        Scan::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $a->id, 'idempotency_key' => 'a-key-0001', 'status' => 'completed']);
        Scan::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $b->id, 'idempotency_key' => 'b-key-0001', 'status' => 'completed']);

        Sanctum::actingAs($a);
        $this->getJson('/api/v1/scans')->assertOk()->assertJsonCount(1, 'data');
    }
}
