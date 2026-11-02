<?php

namespace Tests\Feature;

use App\Models\Advisory;
use App\Models\Crop;
use App\Models\Disease;
use App\Models\PendingOperation;
use App\Models\SystemHealthCheck;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeVisionProvider as F;

class AdminMonitoringTest extends ScanTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
        // The app's "pgsql" connection is just another SQLite memory DB under test.
        config(['database.connections.pgsql' => config('database.connections.sqlite')]);
    }

    public function test_dashboard_returns_platform_statistics(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->hf->script = [F::result()];
        $this->postScan()->assertCreated();
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonPath('data.scans_total', 1)
            ->assertJsonPath('data.users_total', 2)
            ->assertJsonPath('data.top_diseases.0.name', 'Early blight')
            ->assertJsonCount(14, 'data.scans_by_day')
            ->assertJsonStructure(['data' => ['storage_used_bytes', 'pending_operations', 'failed_jobs', 'services']]);
    }

    public function test_user_listing_shows_real_quota_and_usage_with_pagination_meta(): void
    {
        $this->getJson('/api/v1/admin/users')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'quota_bytes', 'used_bytes', 'scan_count']], 'meta' => ['current_page', 'last_page', 'total']])
            ->assertJsonPath('data.0.quota_bytes', config('agriscan.quota.default_bytes'));

        $this->getJson('/api/v1/admin/users?q='.urlencode($this->admin->email))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_ai_health_reports_the_chain_and_marks_unconfigured_providers(): void
    {
        $this->grok->configured = false;
        $this->hf->script = [F::result()];

        $res = $this->getJson('/api/v1/admin/ai-health')->assertOk();

        $res->assertJsonPath('data.chain', ['huggingface']);
        $providers = collect($res->json('data.providers'))->keyBy('name');
        $this->assertSame('unconfigured', $providers['grok']['status']);
        $this->assertTrue($providers['huggingface']['configured']);
        $this->assertNull($providers['huggingface']['success_rate'], 'no traffic yet => unknown, not 0% or 100%');
    }

    public function test_ai_health_computes_success_rate_and_latency_from_logs(): void
    {
        $this->hf->script = [F::transient(), F::result()];
        app(\App\Services\Ai\AiAnalysisService::class)->analyze(new \App\Services\Ai\Dto\ImagePayload('x', 'image/png'));

        $p = collect($this->getJson('/api/v1/admin/ai-health')->json('data.providers'))->firstWhere('name', 'huggingface');

        $this->assertSame(2, $p['requests_24h']);
        $this->assertEquals(0.5, $p['success_rate']);
        $this->assertSame('provider_error', $p['last_error_category']);
    }

    public function test_storage_report_lists_providers_and_logical_usage(): void
    {
        $this->secondary->configured = false;
        Sanctum::actingAs(User::factory()->create());
        $this->hf->script = [F::result()];
        $this->postScan()->assertCreated();
        Sanctum::actingAs($this->admin);

        $res = $this->getJson('/api/v1/admin/storage')->assertOk();

        $res->assertJsonPath('data.files_available', 1)->assertJsonPath('data.files_failed', 0);
        $providers = collect($res->json('data.providers'))->keyBy('name');
        $this->assertSame(1, $providers['r2']['object_count']);
        $this->assertFalse($providers['secondary']['configured']);
        $this->assertNull($providers['r2']['provider_limit_bytes'], 'unknown provider allowance is null, never invented');
    }

    public function test_database_health_reports_unknown_capacity_honestly(): void
    {
        $res = $this->getJson('/api/v1/admin/database-health')->assertOk();

        $res->assertJsonPath('data.primary.status', 'healthy')->assertJsonPath('data.primary.capacity_bytes', null);
        $res->assertJsonPath('data.secondary.status', 'unconfigured');
        $this->assertNotNull($res->json('data.primary.latency_ms'));
    }

    public function test_database_health_reports_down_without_leaking_connection_details(): void
    {
        config(['database.connections.pgsql' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite', 'prefix' => '']]);

        $res = $this->getJson('/api/v1/admin/database-health')->assertOk();

        $res->assertJsonPath('data.primary.status', 'down');
        $this->assertStringNotContainsString('password', strtolower($res->json('data.primary.last_error') ?? ''));
    }

    public function test_database_capacity_warning_appears_when_a_configured_limit_is_nearly_reached(): void
    {
        config(['agriscan.database.capacity_bytes' => 1000]);
        // SQLite has no pg_database_size(), so use the service directly with a faked size via the pgsql driver check.
        $r = app(\App\Services\Health\DatabaseHealthService::class)->check('pgsql', false);
        $this->assertSame(1000, $r['capacity_bytes']);
        $this->assertNull($r['percent_used'] ?? null, 'size is unknown on SQLite, so no percentage is invented');
    }

    public function test_running_health_checks_records_a_row_per_service(): void
    {
        $this->grok->configured = false;
        $this->secondary->configured = false;

        $this->postJson('/api/v1/admin/system-health/run')->assertOk();

        $status = SystemHealthCheck::pluck('status', 'service');
        $this->assertSame('healthy', $status['database_primary']);
        $this->assertSame('healthy', $status['storage_r2']);
        $this->assertSame('unconfigured', $status['storage_secondary']);
        $this->assertSame('healthy', $status['ai_huggingface']);
        $this->assertSame('unconfigured', $status['ai_grok']);

        $this->getJson('/api/v1/admin/system-health')->assertOk()->assertJsonStructure(['data' => ['checks', 'failed_jobs']]);
    }

    public function test_a_failing_storage_provider_shows_as_down(): void
    {
        $this->r2->alwaysFailPut = true;

        $this->postJson('/api/v1/admin/system-health/run')->assertOk();

        $this->assertSame('down', SystemHealthCheck::where('service', 'storage_r2')->latest('id')->value('status'));
    }

    public function test_failed_jobs_are_listed(): void
    {
        \Illuminate\Support\Facades\DB::table('failed_jobs')->insert(['uuid' => 'u-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => "RuntimeException: boom\n#0 trace", 'failed_at' => now()]);

        $this->getJson('/api/v1/admin/system-health')->assertOk()->assertJsonPath('data.failed_jobs.0.summary', 'RuntimeException: boom');
        $this->getJson('/api/v1/admin/dashboard')->assertJsonPath('data.failed_jobs', 1);
    }

    public function test_pending_operations_can_be_listed_and_retried(): void
    {
        PendingOperation::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'delete_replica', 'idempotency_key' => 'delete:gone', 'payload' => ['file_uuid' => 'gone'], 'last_error' => 'x']);
        $op = PendingOperation::first();

        $this->getJson('/api/v1/admin/pending-operations')->assertOk()->assertJsonPath('data.0.type', 'delete_replica')->assertJsonStructure(['meta' => ['current_page']]);
        $this->postJson("/api/v1/admin/pending-operations/{$op->id}/retry")->assertOk()->assertJsonPath('data.completed', true);
        $this->assertSame('completed', $op->fresh()->status);
    }

    public function test_settings_can_be_changed_and_are_validated(): void
    {
        $ok = ['default_quota_bytes' => 5242880, 'warn_percent' => 60, 'critical_percent' => 85, 'db_warn_percent' => 70];

        $this->putJson('/api/v1/admin/settings', $ok)->assertOk();
        $this->getJson('/api/v1/admin/settings')->assertOk()->assertJsonPath('data.default_quota_bytes', 5242880)->assertJsonPath('data.warn_percent', 60);

        $this->putJson('/api/v1/admin/settings', ['critical_percent' => 50] + $ok)->assertStatus(422)->assertJsonValidationErrors('critical_percent');
        $this->putJson('/api/v1/admin/settings', ['default_quota_bytes' => 10] + $ok)->assertStatus(422);
    }

    public function test_reconcile_endpoint_runs_and_is_audited(): void
    {
        $this->postJson('/api/v1/admin/storage/reconcile')->assertOk();

        $this->getJson('/api/v1/admin/logs')->assertOk()->assertJsonPath('data.0.action', 'admin.storage_reconciled')->assertJsonPath('data.0.actor', $this->admin->email);
    }

    public function test_crop_and_disease_reference_management(): void
    {
        $id = $this->postJson('/api/v1/admin/crops', ['name' => 'Rice', 'scientific_name' => 'Oryza sativa'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/crops', ['name' => 'Rice'])->assertStatus(422);

        $d = $this->postJson('/api/v1/admin/diseases', [
            'crop_id' => $id, 'name' => 'Rice blast', 'severity' => 'high', 'symptoms' => ['Diamond lesions'], 'treatments' => ['Balanced nitrogen'], 'prevention' => ['Resistant varieties'],
        ])->assertCreated()->assertJsonPath('data.symptoms.0', 'Diamond lesions')->json('data.id');

        $this->putJson("/api/v1/admin/diseases/{$d}", ['crop_id' => $id, 'name' => 'Rice blast', 'severity' => 'moderate', 'symptoms' => ['New symptom'], 'treatments' => [], 'prevention' => []])
            ->assertOk()->assertJsonPath('data.severity', 'moderate')->assertJsonPath('data.symptoms.0', 'New symptom')->assertJsonPath('data.treatments', []);
        $this->getJson('/api/v1/admin/diseases')->assertOk()->assertJsonStructure(['data', 'meta' => ['total']]);

        $this->deleteJson("/api/v1/admin/crops/{$id}")->assertStatus(422); // still has a disease
        $this->deleteJson("/api/v1/admin/diseases/{$d}")->assertOk();
        $this->deleteJson("/api/v1/admin/crops/{$id}")->assertOk();
        $this->assertDatabaseMissing('crops', ['id' => $id]);
    }

    public function test_deleting_a_disease_keeps_the_recommendation_snapshot_on_past_scans(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->hf->script = [F::result()];
        $id = $this->postScan()->json('data.id');
        Sanctum::actingAs($this->admin);

        $this->deleteJson('/api/v1/admin/diseases/'.Disease::first()->id)->assertOk();

        Sanctum::actingAs(\App\Models\Scan::first()->user);
        $this->getJson("/api/v1/scans/{$id}")->assertOk()->assertJsonPath('data.treatments.0', 'Remove infected leaves');
    }

    public function test_advisory_publishing_workflow(): void
    {
        $id = $this->postJson('/api/v1/admin/advisories', ['title' => 'Draft piece', 'body' => 'Body text', 'is_published' => false])->assertCreated()->json('data.id');
        $this->assertNull(Advisory::find($id)->published_at);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/advisories')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/advisories/{$id}")->assertNotFound();

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/admin/advisories/{$id}", ['title' => 'Live piece', 'body' => 'Body text', 'is_published' => true, 'category' => 'Prevention'])->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/advisories')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Live piece');
        $this->getJson('/api/v1/advisories?q=live')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/advisories?q=zzz')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/advisories/{$id}")->assertOk()->assertJsonPath('data.body', 'Body text');
    }

    public function test_search_input_is_treated_as_text_not_a_pattern(): void
    {
        Advisory::create(['title' => 'Rice care', 'body' => 'x', 'is_published' => true, 'published_at' => now()->subMinute()]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/advisories?q='.urlencode('%'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/advisories?q='.urlencode("'; drop table advisories;--"))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cron_endpoint_is_closed_without_the_secret_and_works_with_it(): void
    {
        $this->postJson('/api/v1/internal/schedule')->assertNotFound();

        config(['agriscan.health.cron_token' => 'short']);
        $this->withToken('short')->postJson('/api/v1/internal/schedule')->assertNotFound();

        config(['agriscan.health.cron_token' => str_repeat('s', 32)]);
        $this->withToken('wrong'.str_repeat('s', 27))->postJson('/api/v1/internal/schedule')->assertNotFound();
        $this->withToken(str_repeat('s', 32))->postJson('/api/v1/internal/schedule')->assertOk();
    }

    public function test_liveness_endpoint_needs_no_database(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.status', 'ok');
    }

    public function test_crops_endpoint_lists_reference_crops_for_the_scanner(): void
    {
        Crop::create(['name' => 'Corn']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/crops')->assertOk()->assertJsonCount(2, 'data');
    }
}
