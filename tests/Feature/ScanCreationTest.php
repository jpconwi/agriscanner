<?php

namespace Tests\Feature;

use App\Models\Scan;
use App\Models\StorageUsage;
use App\Models\StoredFile;
use Tests\Fakes\FakeVisionProvider as F;

class ScanCreationTest extends ScanTestCase
{
    public function test_a_scan_is_analysed_stored_and_saved_with_reference_recommendations(): void
    {
        $user = $this->farmer();
        $this->hf->script = [F::result()];
        $photo = $this->photo();

        $res = $this->postScan($photo)->assertCreated();

        $res->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.disease.name', 'Early blight')
            ->assertJsonPath('data.crop.name', 'Tomato')
            ->assertJsonPath('data.confidence', 0.91)
            ->assertJsonPath('data.provider', 'huggingface')
            ->assertJsonPath('data.model', 'fake-model')
            ->assertJsonPath('data.treatments.0', 'Remove infected leaves')
            ->assertJsonPath('data.prevention.0', 'Rotate crops');
        $this->assertStringStartsWith('https://signed.example/r2/users/'.$user->id.'/scans/', $res->json('data.image_url'));
        $this->assertCount(1, $this->r2->objects, 'photo is in durable storage');

        $usage = StorageUsage::firstWhere('user_id', $user->id);
        $this->assertSame($this->photoBytes($photo), $usage->used_bytes);
        $this->assertSame(0, $usage->reserved_bytes);
        $this->assertSame(1, $usage->file_count);
        $this->assertDatabaseHas('stored_file_replicas', ['provider' => 'r2', 'status' => 'available']);
        $this->assertDatabaseHas('ai_provider_logs', ['provider' => 'huggingface', 'outcome' => 'success']);
    }

    public function test_confidence_is_null_when_the_model_does_not_provide_one(): void
    {
        $this->farmer();
        $this->hf->script = [F::result(confidence: null)];

        $this->postScan()->assertCreated()->assertJsonPath('data.confidence', null);
    }

    public function test_unknown_conditions_get_no_invented_recommendations(): void
    {
        $this->farmer();
        $this->hf->script = [F::result(condition: 'mystery wilt')];

        $res = $this->postScan()->assertCreated();

        $res->assertJsonPath('data.disease', null)->assertJsonPath('data.predicted_label', 'mystery wilt');
        $this->assertSame([], $res->json('data.treatments'));
        $this->assertSame([], $res->json('data.prevention'));
    }

    public function test_healthy_plants_are_reported_as_healthy(): void
    {
        $this->farmer();
        $this->hf->script = [F::result(condition: 'healthy', healthy: true, confidence: 0.97)];

        $this->postScan()->assertCreated()->assertJsonPath('data.is_healthy', true)->assertJsonPath('data.disease', null);
    }

    public function test_upload_requires_authentication_and_an_image(): void
    {
        $this->postJson('/api/v1/scans', [])->assertUnauthorized();

        $this->farmer();
        $this->postJson('/api/v1/scans', ['idempotency_key' => 'idem-key-0001'])->assertStatus(422)->assertJsonValidationErrors('image');
        $this->postJson('/api/v1/scans', ['image' => $this->photo()])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
    }

    public function test_non_images_and_renamed_files_are_rejected_before_any_storage_or_ai_call(): void
    {
        $this->farmer();

        $this->postScan(\Illuminate\Http\UploadedFile::fake()->createWithContent('leaf.jpg', '<?php echo "not an image";'))->assertStatus(422);
        $this->postScan(\Illuminate\Http\UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 fake'), 'idem-key-0002')->assertStatus(422);
        $this->postScan(\Illuminate\Http\UploadedFile::fake()->create('big.jpg', 5000, 'image/jpeg'), 'idem-key-0003')->assertStatus(422);

        $this->assertSame(0, $this->hf->calls);
        $this->assertSame(0, $this->r2->putCalls);
        $this->assertDatabaseCount('scans', 0);
        $this->assertDatabaseCount('stored_files', 0);
    }

    public function test_an_oversized_image_is_rejected_by_the_content_check_too(): void
    {
        $this->farmer();
        config(['agriscan.uploads.max_dimension' => 50]);

        $this->postScan($this->photo(w: 100, h: 100))->assertStatus(422)->assertJsonPath('code', 'invalid_image');
        $this->assertSame(0, $this->r2->putCalls);
    }

    public function test_the_same_idempotency_key_never_creates_a_second_scan_or_charges_quota_twice(): void
    {
        $user = $this->farmer();
        $this->hf->script = [F::result()];
        $photo = $this->photo();

        $first = $this->postScan($photo, 'retry-key-0001')->assertCreated();
        $second = $this->postScan($photo, 'retry-key-0001')->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, $this->hf->calls, 'the AI is not called again for an already-completed scan');
        $this->assertDatabaseCount('scans', 1);
        $this->assertDatabaseCount('stored_files', 1);
        $this->assertSame($this->photoBytes($photo), StorageUsage::firstWhere('user_id', $user->id)->used_bytes);
    }

    public function test_retrying_after_an_ai_outage_reuses_the_stored_photo_without_double_charging_quota(): void
    {
        $user = $this->farmer();
        $photo = $this->photo();
        // Every provider attempt fails transiently: HF (1 + 2 retries) and Grok.
        $this->hf->script = array_fill(0, 3, F::transient());
        $this->grok->configured = false;

        $this->postScan($photo, 'retry-key-0002')->assertStatus(503)->assertJsonPath('code', 'analysis_unavailable');

        $scan = Scan::firstWhere('idempotency_key', 'retry-key-0002');
        $this->assertSame('failed', $scan->status);
        $this->assertNull($scan->result, 'no prediction is invented');
        $used = StorageUsage::firstWhere('user_id', $user->id)->used_bytes;

        // The service recovers; the user retries with the same key.
        $this->hf->script = [F::result()];
        $this->postScan($photo, 'retry-key-0002')->assertCreated()->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseCount('scans', 1);
        $this->assertDatabaseCount('stored_files', 1);
        $this->assertCount(1, $this->r2->objects);
        $this->assertSame($used, StorageUsage::firstWhere('user_id', $user->id)->used_bytes);
    }

    public function test_reusing_a_key_with_a_different_photo_is_refused(): void
    {
        $this->farmer();
        $this->hf->script = array_fill(0, 3, F::transient());
        $this->grok->configured = false;
        $this->postScan($this->photo('a.jpg', 100, 100), 'retry-key-0003')->assertStatus(503);

        $this->hf->script = [F::result()];
        $this->postScan($this->photo('b.jpg', 90, 140), 'retry-key-0003')->assertStatus(422);
    }

    public function test_scans_can_be_listed_and_viewed(): void
    {
        $this->farmer();
        $this->hf->script = [F::result()];
        $id = $this->postScan()->json('data.id');

        $this->getJson('/api/v1/scans')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total']]);
        $this->getJson("/api/v1/scans/{$id}")->assertOk()->assertJsonPath('data.id', $id);
    }

    public function test_deleting_a_scan_removes_the_photo_from_every_replica_and_frees_quota(): void
    {
        $user = $this->farmer();
        $this->useStores(['r2' => $this->r2, 'secondary' => $this->secondary], replicate: true);
        $this->hf->script = [F::result()];
        $id = $this->postScan()->json('data.id');
        $this->assertCount(1, $this->r2->objects);
        $this->assertCount(1, $this->secondary->objects, 'replicated');

        $this->deleteJson("/api/v1/scans/{$id}")->assertOk();

        $this->assertSame([], $this->r2->objects);
        $this->assertSame([], $this->secondary->objects);
        $this->assertDatabaseCount('scans', 0);
        $this->assertDatabaseCount('stored_files', 0);
        $usage = StorageUsage::firstWhere('user_id', $user->id);
        $this->assertSame(0, $usage->used_bytes);
        $this->assertSame(0, $usage->file_count);
    }

    public function test_a_failed_replica_delete_keeps_the_scan_queues_a_retry_and_does_not_free_quota(): void
    {
        $user = $this->farmer();
        $this->useStores(['r2' => $this->r2, 'secondary' => $this->secondary], replicate: true);
        $this->hf->script = [F::result()];
        $photo = $this->photo();
        $id = $this->postScan($photo)->json('data.id');
        $this->secondary->failDelete = true;

        $this->deleteJson("/api/v1/scans/{$id}")->assertStatus(503)->assertJsonPath('code', 'storage_unavailable');

        $this->assertDatabaseCount('scans', 1);
        $this->assertSame($this->photoBytes($photo), StorageUsage::firstWhere('user_id', $user->id)->used_bytes, 'usage only drops after confirmed deletion');
        $this->assertSame('deleting', StoredFile::first()->status);
        $this->assertDatabaseHas('pending_operations', ['type' => 'delete_replica', 'status' => 'pending']);

        // Provider recovers; the pending-operation processor finishes the job idempotently.
        $this->secondary->failDelete = false;
        $this->artisan('agriscan:process-pending')->assertSuccessful();

        $this->assertSame([], $this->r2->objects);
        $this->assertSame([], $this->secondary->objects);
        $this->assertDatabaseCount('stored_files', 0);
        $this->assertSame(0, StorageUsage::firstWhere('user_id', $user->id)->used_bytes);
    }

    public function test_deleting_the_account_removes_all_photos_first(): void
    {
        $user = $this->farmer(['password' => 'Password12345']);
        $this->hf->script = [F::result(), F::result()];
        $this->postScan($this->photo(), 'acct-key-0001');
        $this->postScan($this->photo('b.jpg', 130, 130), 'acct-key-0002');
        $this->assertCount(2, $this->r2->objects);

        $this->deleteJson('/api/v1/user', ['password' => 'wrong'])->assertStatus(422);
        $this->assertCount(2, $this->r2->objects);

        $this->deleteJson('/api/v1/user', ['password' => 'Password12345'])->assertOk();
        $this->assertSame([], $this->r2->objects);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseCount('stored_files', 0);
    }
}
