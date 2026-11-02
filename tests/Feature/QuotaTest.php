<?php

namespace Tests\Feature;

use App\Models\StorageQuota;
use App\Models\StorageUsage;
use App\Models\StoredFile;
use App\Services\Quota\QuotaExceededException;
use App\Services\Quota\QuotaService;
use App\Services\Quota\SettingsService;
use Tests\Fakes\FakeVisionProvider as F;

class QuotaTest extends ScanTestCase
{
    private function quotaFor($user, int $bytes): void
    {
        app(QuotaService::class)->setQuota($user, $bytes);
    }

    public function test_new_accounts_get_the_configurable_default_quota(): void
    {
        $user = $this->farmer();
        $this->assertSame(config('agriscan.quota.default_bytes'), app(QuotaService::class)->quotaBytes($user));

        app(SettingsService::class)->update(['default_quota_bytes' => 7340032]);
        $second = \App\Models\User::factory()->create();
        $this->assertSame(7340032, app(QuotaService::class)->quotaBytes($second));
        $this->assertSame(config('agriscan.quota.default_bytes'), app(QuotaService::class)->quotaBytes($user), 'existing accounts are unchanged');
    }

    public function test_usage_is_calculated_from_real_records_not_hardcoded(): void
    {
        $user = $this->farmer();
        $this->quotaFor($user, 1_000_000);
        $this->hf->script = [F::result()];
        $photo = $this->photo();
        $this->postScan($photo)->assertCreated();
        $bytes = $this->photoBytes($photo);

        $u = $this->getJson('/api/v1/storage/usage')->assertOk()->json('data');

        $this->assertSame($bytes, $u['used_bytes']);
        $this->assertSame(1_000_000, $u['quota_bytes']);
        $this->assertSame(1_000_000 - $bytes, $u['remaining_bytes']);
        $this->assertEqualsWithDelta($bytes / 1_000_000 * 100, $u['percent_used'], 0.01);
        $this->assertSame(1, $u['image_count']);
        $this->assertSame(1, $u['scan_count']);
        $this->assertSame(0, $u['failed_uploads']);
        $this->assertSame(0, $u['pending_uploads']);
        $this->getJson('/api/v1/storage/quota')->assertOk()->assertJsonPath('data.quota_bytes', 1_000_000);
    }

    public function test_an_upload_that_would_exceed_the_quota_is_rejected_before_storage_or_ai(): void
    {
        $user = $this->farmer();
        $photo = $this->photo();
        $this->quotaFor($user, $this->photoBytes($photo) - 1);

        $this->postScan($photo)->assertStatus(422)->assertJsonPath('code', 'quota_exceeded');

        $this->assertSame(0, $this->r2->putCalls);
        $this->assertSame(0, $this->hf->calls);
        $this->assertDatabaseCount('scans', 0);
        $this->assertSame(0, StorageUsage::firstWhere('user_id', $user->id)->reserved_bytes, 'no reservation leaks');
    }

    public function test_an_exhausted_quota_rejects_every_upload_and_never_deletes_existing_files(): void
    {
        $user = $this->farmer();
        $this->hf->script = [F::result()];
        $first = $this->photo();
        $bytes = $this->photoBytes($first);
        $this->quotaFor($user, $bytes + 10); // room for exactly one photo
        $id = $this->postScan($first, 'quota-key-001')->assertCreated()->json('data.id');

        $this->postScan($this->photo('b.jpg', 110, 110), 'quota-key-002')->assertStatus(422)->assertJsonPath('code', 'quota_exceeded');

        $this->assertCount(1, $this->r2->objects, 'the existing photo is untouched');
        $this->getJson("/api/v1/scans/{$id}")->assertOk();
        $this->assertSame(1, StoredFile::where('status', 'available')->count());
    }

    public function test_lowering_a_quota_below_usage_blocks_new_uploads_but_deletes_nothing(): void
    {
        $user = $this->farmer();
        $this->hf->script = [F::result()];
        $this->postScan()->assertCreated();

        $this->quotaFor($user, 1048576);
        StorageQuota::where('user_id', $user->id)->update(['quota_bytes' => 10]);

        $this->postScan($this->photo('c.jpg', 100, 100), 'quota-key-003')->assertStatus(422)->assertJsonPath('code', 'quota_exceeded');
        $this->assertCount(1, $this->r2->objects);
        $this->assertSame(100.0, $this->getJson('/api/v1/storage/usage')->json('data.percent_used'), 'percent is capped at 100');
    }

    public function test_concurrent_uploads_cannot_jointly_exceed_the_quota(): void
    {
        // Two requests both pass their own "does it fit?" check. The reservation taken under a row lock by the
        // first one must be visible to the second, so only one can win. (PHP tests are single-threaded, so this
        // models the interleaving: reserve A, reserve B before A has committed.)
        $user = $this->farmer();
        $svc = app(QuotaService::class);
        $this->quotaFor($user, 1000);

        $svc->reserve($user, 600);
        $this->expectException(QuotaExceededException::class);
        try {
            $svc->reserve($user, 600);
        } finally {
            $this->assertSame(600, StorageUsage::firstWhere('user_id', $user->id)->reserved_bytes);
        }
    }

    public function test_reservations_are_released_or_committed_exactly_once(): void
    {
        $user = $this->farmer();
        $svc = app(QuotaService::class);
        $this->quotaFor($user, 1000);

        $svc->reserve($user, 400);
        $svc->release($user, 400);
        $this->assertSame(0, StorageUsage::firstWhere('user_id', $user->id)->reserved_bytes);

        $svc->reserve($user, 400);
        $svc->commit($user, 400);
        $u = StorageUsage::firstWhere('user_id', $user->id);
        $this->assertSame([400, 0, 1], [$u->used_bytes, $u->reserved_bytes, $u->file_count]);

        $svc->decrement($user, 400);
        $svc->decrement($user, 400); // double decrement can never go negative
        $this->assertSame(0, StorageUsage::firstWhere('user_id', $user->id)->used_bytes);
    }

    public function test_warning_levels_follow_the_configurable_thresholds(): void
    {
        $svc = app(QuotaService::class);

        $this->assertSame('ok', $svc->level(74.9));
        $this->assertSame('warning', $svc->level(75));
        $this->assertSame('critical', $svc->level(90));
        $this->assertSame('full', $svc->level(100));

        app(SettingsService::class)->update(['warn_percent' => 50, 'critical_percent' => 60]);
        $this->assertSame('warning', $svc->level(50));
        $this->assertSame('critical', $svc->level(60));
    }

    public function test_the_api_reports_the_warning_level(): void
    {
        $user = $this->farmer();
        $svc = app(QuotaService::class);
        $this->quotaFor($user, 1000);
        StorageUsage::where('user_id', $user->id)->update(['used_bytes' => 800]);

        $this->getJson('/api/v1/storage/usage')->assertOk()->assertJsonPath('data.warning_level', 'warning');
        StorageUsage::where('user_id', $user->id)->update(['used_bytes' => 950]);
        $this->getJson('/api/v1/storage/usage')->assertJsonPath('data.warning_level', 'critical');
        StorageUsage::where('user_id', $user->id)->update(['used_bytes' => 1000]);
        $this->getJson('/api/v1/storage/usage')->assertJsonPath('data.warning_level', 'full');
    }

    public function test_reconciliation_repairs_drifted_counters_and_flags_missing_objects(): void
    {
        $user = $this->farmer();
        $this->hf->script = [F::result()];
        $this->postScan()->assertCreated();
        $real = StoredFile::first()->size_bytes;

        StorageUsage::where('user_id', $user->id)->update(['used_bytes' => 999999, 'file_count' => 7]);
        $this->artisan('agriscan:reconcile-storage --no-verify')->assertSuccessful();
        $u = StorageUsage::firstWhere('user_id', $user->id);
        $this->assertSame([$real, 1], [$u->used_bytes, $u->file_count]);
        $this->assertNotNull($u->last_reconciled_at);

        // An object that vanished from the bucket is flagged and stops counting.
        $this->r2->objects = [];
        $this->artisan('agriscan:reconcile-storage')->assertSuccessful();
        $this->assertSame('failed', StoredFile::first()->status);
        $this->assertSame(0, StorageUsage::firstWhere('user_id', $user->id)->used_bytes);
    }

    public function test_reconciliation_leaves_data_alone_when_the_provider_is_unreachable(): void
    {
        $this->farmer();
        $this->hf->script = [F::result()];
        $this->postScan()->assertCreated();
        $this->r2->configured = false; // cannot verify right now

        $this->artisan('agriscan:reconcile-storage')->assertSuccessful();

        $this->assertSame('available', StoredFile::first()->status);
    }
}
