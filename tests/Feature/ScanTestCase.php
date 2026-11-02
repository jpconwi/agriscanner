<?php

namespace Tests\Feature;

use App\Models\Crop;
use App\Models\Disease;
use App\Models\User;
use App\Services\Ai\AiAnalysisService;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeObjectStore;
use Tests\Fakes\FakeVisionProvider;
use Tests\TestCase;

/** Wires fake storage and AI providers into the container. No network, no paid services. */
abstract class ScanTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakeObjectStore $r2;

    protected FakeObjectStore $secondary;

    protected FakeVisionProvider $hf;

    protected FakeVisionProvider $grok;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake(); // retries back off instantly

        $this->r2 = new FakeObjectStore('r2');
        $this->secondary = new FakeObjectStore('secondary');
        $this->useStores(['r2' => $this->r2, 'secondary' => $this->secondary]);

        $this->hf = new FakeVisionProvider('huggingface');
        $this->grok = new FakeVisionProvider('grok');
        $this->useProviders(['huggingface' => $this->hf, 'grok' => $this->grok]);

        $crop = Crop::create(['name' => 'Tomato']);
        $d = Disease::create(['crop_id' => $crop->id, 'name' => 'Early blight', 'severity' => 'moderate', 'follow_up' => 'Re-scan in a week.']);
        $d->syncReference(['Brown rings on old leaves'], ['Humid weather'], ['Remove infected leaves'], ['Rotate crops']);
    }

    protected function useStores(array $stores, bool $replicate = false): void
    {
        $this->app->instance(StorageManager::class, new StorageManager($stores, 2, 1, $replicate));
    }

    protected function useProviders(array $providers, array $priority = ['huggingface', 'grok']): void
    {
        $this->app->instance(AiAnalysisService::class, new AiAnalysisService($providers, $priority, 2, 1, 5));
    }

    protected function farmer(array $attrs = []): User
    {
        $u = User::factory()->create($attrs);
        Sanctum::actingAs($u);

        return $u;
    }

    protected function photo(string $name = 'leaf.jpg', int $w = 120, int $h = 120): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    protected function photoBytes(UploadedFile $f): int
    {
        return strlen((string) file_get_contents($f->getRealPath()));
    }

    protected function postScan(?UploadedFile $file = null, string $key = 'idem-key-0001', array $extra = [])
    {
        return $this->postJson('/api/v1/scans', ['image' => $file ?? $this->photo(), 'idempotency_key' => $key] + $extra);
    }
}
