<?php

namespace Tests\Feature;

use App\Models\StorageUsage;
use App\Models\StoredFile;
use App\Services\Storage\Exceptions\StorageUnavailableException;
use App\Services\Storage\StorageManager;
use Tests\Fakes\FakeVisionProvider as F;

class StorageFallbackTest extends ScanTestCase
{
    public function test_transient_write_errors_are_retried_on_the_same_store(): void
    {
        $this->farmer();
        $this->hf->script = [F::result()];
        $this->r2->putFailures = 2; // fails twice, succeeds on the third (bounded) attempt

        $this->postScan()->assertCreated();

        $this->assertCount(1, $this->r2->objects);
        $this->assertCount(0, $this->secondary->objects, 'secondary untouched when primary recovers');
        $this->assertSame(3, $this->r2->putCalls);
    }

    public function test_when_the_primary_is_down_the_secondary_holds_the_file_and_ownership_stays_consistent(): void
    {
        $user = $this->farmer();
        $this->hf->script = [F::result()];
        $this->r2->alwaysFailPut = true;
        $photo = $this->photo();

        $res = $this->postScan($photo)->assertCreated();

        $this->assertSame([], $this->r2->objects);
        $this->assertCount(1, $this->secondary->objects);
        $file = StoredFile::firstWhere('user_id', $user->id);
        $this->assertSame('available', $file->status);
        $this->assertDatabaseHas('stored_file_replicas', ['stored_file_id' => $file->id, 'provider' => 'secondary', 'status' => 'available']);
        $this->assertDatabaseMissing('stored_file_replicas', ['provider' => 'r2']);
        $this->assertStringContainsString('/secondary/users/'.$user->id.'/', $res->json('data.image_url'), 'signed URL comes from the provider that holds the file');
        $this->assertSame($this->photoBytes($photo), StorageUsage::firstWhere('user_id', $user->id)->used_bytes, 'a single replica counts once');
    }

    public function test_when_all_storage_fails_nothing_is_saved_and_the_quota_is_released(): void
    {
        $user = $this->farmer();
        $this->r2->alwaysFailPut = true;
        $this->secondary->alwaysFailPut = true;

        $this->postScan()->assertStatus(503)->assertJsonPath('code', 'storage_unavailable');

        $this->assertSame(0, $this->hf->calls, 'no AI call is wasted when the photo cannot be stored');
        $this->assertDatabaseCount('scans', 0);
        $usage = StorageUsage::firstWhere('user_id', $user->id);
        $this->assertSame(0, $usage->used_bytes);
        $this->assertSame(0, $usage->reserved_bytes);
        $this->assertSame(0, StoredFile::where('status', 'available')->count());
        $this->assertSame('failed', StoredFile::first()->status, 'the failed upload is tracked for the admin');
    }

    public function test_an_upload_is_never_reported_successful_unless_verified(): void
    {
        $this->farmer();
        $this->r2->corruptWrites = true; // object lands truncated
        $this->secondary->configured = false;

        $this->postScan()->assertStatus(503);

        $this->assertSame([], $this->r2->objects, 'the bad object is cleaned up');
        $this->assertDatabaseCount('scans', 0);
        $this->assertSame(0, StorageUsage::first()->used_bytes);
    }

    public function test_with_no_storage_configured_uploads_are_refused_and_never_touch_local_disk(): void
    {
        $this->farmer();
        $this->r2->configured = false;
        $this->secondary->configured = false;

        $this->postScan()->assertStatus(503)->assertJsonPath('code', 'storage_unavailable');
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('local')->allFiles());
    }

    public function test_replication_failure_does_not_fail_the_upload_but_queues_a_retry(): void
    {
        $this->farmer();
        $this->useStores(['r2' => $this->r2, 'secondary' => $this->secondary], replicate: true);
        $this->secondary->alwaysFailPut = true;
        $this->hf->script = [F::result()];

        $this->postScan()->assertCreated();

        $this->assertDatabaseHas('pending_operations', ['type' => 'replicate_file', 'status' => 'pending']);
        $this->assertDatabaseMissing('stored_file_replicas', ['provider' => 'secondary']);

        // Recovery: the secondary comes back and the processor copies the file over.
        $this->secondary->alwaysFailPut = false;
        $this->artisan('agriscan:process-pending')->assertSuccessful();
        $this->assertCount(1, $this->secondary->objects);
        $this->assertDatabaseHas('stored_file_replicas', ['provider' => 'secondary', 'status' => 'available']);
    }

    public function test_replicas_do_not_inflate_per_account_usage(): void
    {
        $user = $this->farmer();
        $this->useStores(['r2' => $this->r2, 'secondary' => $this->secondary], replicate: true);
        $this->hf->script = [F::result()];
        $photo = $this->photo();

        $this->postScan($photo)->assertCreated();

        $this->assertCount(1, $this->r2->objects);
        $this->assertCount(1, $this->secondary->objects);
        $this->assertSame($this->photoBytes($photo), StorageUsage::firstWhere('user_id', $user->id)->used_bytes);
        $this->getJson('/api/v1/storage/usage')->assertOk()->assertJsonPath('data.used_bytes', $this->photoBytes($photo))->assertJsonPath('data.image_count', 1);
    }

    public function test_manager_picks_the_first_configured_store_in_priority_order(): void
    {
        $this->r2->configured = false;
        $manager = app(StorageManager::class);

        $store = $manager->putVerified('k/a.jpg', 'data', 'image/jpeg');

        $this->assertSame('secondary', $store->name());
    }

    public function test_manager_throws_a_clear_error_when_everything_fails(): void
    {
        $this->r2->alwaysFailPut = true;
        $this->secondary->alwaysFailPut = true;

        $this->expectException(StorageUnavailableException::class);
        app(StorageManager::class)->putVerified('k/a.jpg', 'data', 'image/jpeg');
    }

    public function test_pending_uploads_that_never_confirm_are_cleaned_up_and_their_reservation_released(): void
    {
        $user = $this->farmer();
        $quota = app(\App\Services\Quota\QuotaService::class);
        $quota->reserve($user, 5000);
        $file = StoredFile::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id, 'object_key' => 'users/1/scans/orphan.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 5000, 'sha256' => str_repeat('a', 64), 'status' => 'pending']);
        $file->forceFill(['created_at' => now()->subHours(2)])->save();
        $this->r2->objects['users/1/scans/orphan.jpg'] = str_repeat('x', 5000);

        $this->artisan('agriscan:process-pending')->assertSuccessful();

        $this->assertSame('failed', $file->fresh()->status);
        $this->assertSame([], $this->r2->objects);
        $this->assertSame(0, StorageUsage::firstWhere('user_id', $user->id)->reserved_bytes);
    }
}
