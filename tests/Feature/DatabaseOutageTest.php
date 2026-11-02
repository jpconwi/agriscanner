<?php

namespace Tests\Feature;

use App\Models\PendingOperation;
use App\Models\Scan;
use App\Models\ScanResult;
use App\Models\StoredFile;
use App\Services\Scans\PendingJournal;
use App\Support\DatabaseOutage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Tests\Fakes\FakeVisionProvider as F;

class DatabaseOutageTest extends ScanTestCase
{
    private function connectionFailure(string $table = 'scan_results'): QueryException
    {
        return new QueryException('pgsql', "insert into {$table}", [], new \PDOException('SQLSTATE[08006] [7] could not connect to server: Connection refused'));
    }

    private function breakWrites(string $model): void
    {
        Event::listen('eloquent.creating: '.$model, fn () => throw $this->connectionFailure());
    }

    private function repairWrites(string $model): void
    {
        Event::forget('eloquent.creating: '.$model);
    }

    public function test_connection_failures_are_distinguished_from_ordinary_query_errors(): void
    {
        $this->assertTrue(DatabaseOutage::isConnectionFailure($this->connectionFailure()));
        $this->assertTrue(DatabaseOutage::isConnectionFailure(new QueryException('pgsql', 'select 1', [], new \PDOException('SSL connection has been closed unexpectedly'))));
        $this->assertTrue(DatabaseOutage::isConnectionFailure(new QueryException('pgsql', 'select 1', [], new \PDOException('terminating connection due to administrator command'))));

        $p = new \PDOException('duplicate key value violates unique constraint');
        $p->errorInfo = ['23505', 7, 'duplicate key'];
        $this->assertFalse(DatabaseOutage::isConnectionFailure(new QueryException('pgsql', 'insert', [], $p)));
    }

    public function test_the_api_answers_a_database_outage_with_a_retryable_503_and_no_internals(): void
    {
        Route::get('/api/v1/_outage', fn () => throw $this->connectionFailure('secret_table'));

        $res = $this->getJson('/api/v1/_outage')->assertStatus(503);

        $res->assertJsonPath('code', 'database_unavailable')->assertHeader('Retry-After');
        $this->assertStringContainsString('Nothing was saved', $res->json('message'));
        $this->assertStringNotContainsString('secret_table', $res->getContent());
        $this->assertStringNotContainsString('Connection refused', $res->getContent());
    }

    public function test_other_database_errors_return_a_generic_500_without_leaking_sql(): void
    {
        Route::get('/api/v1/_boom', function () {
            $p = new \PDOException('duplicate key value violates unique constraint "secret_idx"');
            $p->errorInfo = ['23505', 7, 'x'];
            throw new QueryException('pgsql', 'insert into secret_table', [], $p);
        });

        $res = $this->getJson('/api/v1/_boom')->assertStatus(500);

        $this->assertStringNotContainsString('secret', $res->getContent());
    }

    public function test_a_failed_scan_save_is_never_reported_as_saved_and_is_recorded_for_recovery(): void
    {
        $user = $this->farmer();
        $this->hf->script = [F::result()];
        $this->breakWrites(ScanResult::class);

        $res = $this->postScan()->assertStatus(503);

        $res->assertJsonPath('code', 'scan_not_saved')->assertJsonPath('saved', false)->assertJsonPath('recorded_in', 'primary');
        $this->assertStringContainsString('NOT saved', $res->json('message'));
        $this->assertDatabaseCount('scan_results', 0);
        $this->assertSame('pending', Scan::first()->status, 'the scan is not marked completed');
        $op = PendingOperation::firstWhere('type', 'save_scan');
        $this->assertNotNull($op);
        $this->assertSame('huggingface', $op->payload['result']['provider']);
        $this->assertArrayNotHasKey('image', $op->payload, 'no image bytes are journalled');
        $this->assertStringNotContainsString('hf_', json_encode($op->payload), 'no secrets are journalled');
    }

    public function test_the_journalled_result_is_replayed_once_the_database_recovers(): void
    {
        $this->farmer();
        $this->hf->script = [F::result()];
        $this->breakWrites(ScanResult::class);
        $this->postScan()->assertStatus(503);
        $this->repairWrites(ScanResult::class);

        $this->artisan('agriscan:process-pending')->assertSuccessful();

        $scan = Scan::first();
        $this->assertSame('completed', $scan->status);
        $this->assertSame('Early blight', $scan->result->disease->name);
        $this->assertSame('completed', PendingOperation::firstWhere('type', 'save_scan')->status);
        $this->getJson("/api/v1/scans/{$scan->uuid}")->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_retrying_after_an_outage_with_the_same_key_is_idempotent_even_if_the_journal_also_replays(): void
    {
        $this->farmer();
        $this->hf->script = [F::result(), F::result()];
        $this->breakWrites(ScanResult::class);
        $this->postScan(null, 'outage-key-001')->assertStatus(503);
        $this->repairWrites(ScanResult::class);

        $this->postScan(null, 'outage-key-001')->assertCreated();
        $this->artisan('agriscan:process-pending')->assertSuccessful();

        $this->assertDatabaseCount('scans', 1);
        $this->assertDatabaseCount('scan_results', 1);
        $this->assertDatabaseCount('stored_files', 1);
        $this->assertCount(1, $this->r2->objects);
    }

    public function test_when_the_primary_database_rejects_the_journal_too_it_falls_back_to_object_storage(): void
    {
        $this->farmer();
        $this->hf->script = [F::result()];
        $this->breakWrites(ScanResult::class);
        $this->breakWrites(PendingOperation::class);

        $res = $this->postScan()->assertStatus(503);

        $res->assertJsonPath('recorded_in', 'storage');
        $journal = array_values(array_filter(array_keys($this->r2->objects), fn ($k) => str_starts_with($k, 'journal/')));
        $this->assertCount(1, $journal);

        // Database recovers: the entry is imported into pending_operations, replayed, and the journal object removed.
        $this->repairWrites(ScanResult::class);
        $this->repairWrites(PendingOperation::class);
        $this->artisan('agriscan:process-pending')->assertSuccessful();

        $this->assertSame('completed', Scan::first()->status);
        $this->assertSame([], array_values(array_filter(array_keys($this->r2->objects), fn ($k) => str_starts_with($k, 'journal/'))));
    }

    public function test_when_nothing_durable_is_available_the_user_is_told_to_upload_again(): void
    {
        $user = $this->farmer();
        $this->breakWrites(PendingOperation::class);
        $this->r2->alwaysFailPut = true;
        $this->secondary->alwaysFailPut = true;

        $where = app(PendingJournal::class)->record('save_scan', 'save_scan:x', ['scan_uuid' => 'x'], $user->id, 'database unavailable');

        $this->assertSame('none', $where);
        $this->assertStringContainsString('upload the photo again', (new \App\Services\Scans\ScanNotSavedException('none'))->getMessage());
    }

    public function test_the_secondary_database_is_never_used_unless_explicitly_enabled(): void
    {
        $this->assertFalse(config('agriscan.database.secondary_enabled'));
        $this->farmer();
        $this->hf->script = [F::result()];
        $this->breakWrites(ScanResult::class);
        $this->breakWrites(PendingOperation::class);

        // Recovery goes to object storage; the secondary-database branch is gated behind secondary_enabled and is skipped.
        $this->postScan()->assertStatus(503)->assertJsonPath('recorded_in', 'storage');
    }

    public function test_the_photo_stays_in_storage_and_counted_after_a_failed_save(): void
    {
        $this->farmer();
        $this->hf->script = [F::result()];
        $this->breakWrites(ScanResult::class);

        $this->postScan()->assertStatus(503);

        $this->assertCount(1, $this->r2->objects, 'the upload is durable and reused by the retry');
        $this->assertSame('available', StoredFile::first()->status);
    }
}
