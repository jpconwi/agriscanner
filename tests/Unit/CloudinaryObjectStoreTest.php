<?php

namespace Tests\Unit;

use App\Services\Storage\CloudinaryObjectStore;
use App\Services\Storage\Exceptions\StoreException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Cloudinary is always faked here: no network and no real credentials. */
class CloudinaryObjectStoreTest extends TestCase
{
    private function store(array $over = []): CloudinaryObjectStore
    {
        return new CloudinaryObjectStore('cloudinary', $over + [
            'enabled' => true, 'cloud_name' => 'democloud', 'api_key' => 'key123', 'api_secret' => 'abcd', 'folder' => 'agriscan',
        ]);
    }

    public function test_it_needs_all_credentials(): void
    {
        $this->assertTrue($this->store()->isConfigured());
        $this->assertFalse($this->store(['api_secret' => null])->isConfigured());
        $this->assertFalse($this->store(['enabled' => false])->isConfigured());
    }

    public function test_signature_matches_the_documented_example(): void
    {
        // From Cloudinary's docs: secret "abcd", timestamp=1315060510
        $this->assertSame('a21ad0f63beb4de2e5575204b79ab90bffb02c10', $this->store()->sign(['timestamp' => 1315060510]));
    }

    public function test_signature_ignores_excluded_fields_and_sorts_parameters(): void
    {
        $a = $this->store()->sign(['timestamp' => 5, 'public_id' => 'x', 'type' => 'authenticated', 'api_key' => 'k', 'file' => 'bytes', 'resource_type' => 'image']);
        $this->assertSame(sha1('public_id=x&timestamp=5&type=authenticatedabcd'), $a);
    }

    public function test_images_upload_as_authenticated_image_assets_and_are_verified(): void
    {
        Http::fake(['api.cloudinary.com/*' => Http::response(['bytes' => 5, 'public_id' => 'agriscan/users/1/scans/a'])]);
        $this->store()->put('users/1/scans/a.jpg', 'hello', 'image/jpeg');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/v1_1/democloud/image/upload'));
    }

    public function test_non_images_go_to_raw(): void
    {
        Http::fake(['api.cloudinary.com/*' => Http::response(['bytes' => 2])]);
        $this->store()->put('journal/x.json', '{}', 'application/json');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/raw/upload'));
    }

    public function test_a_size_mismatch_is_not_accepted_as_stored(): void
    {
        Http::fake(['api.cloudinary.com/*' => Http::response(['bytes' => 3])]);
        $this->expectException(StoreException::class);
        $this->store()->put('users/1/scans/a.jpg', 'hello', 'image/jpeg');
    }

    public function test_bad_credentials_are_permanent_and_rate_limits_are_transient(): void
    {
        Http::fake(['api.cloudinary.com/*' => Http::sequence()->push(['error' => ['message' => 'Invalid api_key']], 401)->push(['error' => ['message' => 'Rate Limit Exceeded']], 420)]);
        try {
            $this->store()->put('users/1/scans/a.jpg', 'x', 'image/jpeg');
            $this->fail('expected exception');
        } catch (StoreException $e) {
            $this->assertFalse($e->transient);
            $this->assertStringNotContainsString('abcd', $e->getMessage());
        }
        try {
            $this->store()->put('users/1/scans/a.jpg', 'x', 'image/jpeg');
            $this->fail('expected exception');
        } catch (StoreException $e) {
            $this->assertTrue($e->transient);
        }
    }

    public function test_size_uses_the_admin_api_and_missing_objects_are_null(): void
    {
        Http::fake([
            'api.cloudinary.com/*/resources/image/authenticated/agriscan/users/1/scans/a' => Http::response(['bytes' => 1234]),
            'api.cloudinary.com/*' => Http::response(['error' => ['message' => 'not found']], 404),
        ]);
        $this->assertSame(1234, $this->store()->size('users/1/scans/a.jpg'));
        $this->assertNull($this->store()->size('users/1/scans/missing.png'));
    }

    public function test_deleting_a_missing_object_succeeds(): void
    {
        Http::fake(['api.cloudinary.com/*' => Http::response(['result' => 'not found'])]);
        $this->store()->delete('users/1/scans/gone.jpg');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/image/destroy'));
    }

    public function test_list_maps_back_to_keys(): void
    {
        Http::fake([
            'api.cloudinary.com/*/resources/image/*' => Http::response(['resources' => [['public_id' => 'agriscan/users/1/scans/a', 'format' => 'jpg']]]),
            'api.cloudinary.com/*/resources/raw/*' => Http::response(['resources' => [['public_id' => 'agriscan/journal/j1.json']]]),
        ]);
        $this->assertEqualsCanonicalizing(['users/1/scans/a.jpg', 'journal/j1.json'], $this->store()->list(''));
    }

    public function test_temporary_urls_are_signed_expire_and_never_contain_the_secret(): void
    {
        $url = $this->store()->temporaryUrl('users/1/scans/a.jpg', new \DateTimeImmutable('@1893456000'));
        $this->assertStringStartsWith('https://api.cloudinary.com/v1_1/democloud/image/download?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('agriscan/users/1/scans/a', $q['public_id']);
        $this->assertSame('jpg', $q['format']);
        $this->assertSame('authenticated', $q['type']);
        $this->assertSame('1893456000', $q['expires_at']);
        $this->assertSame('key123', $q['api_key']);
        $this->assertNotEmpty($q['signature']);
        $this->assertStringNotContainsString('abcd', $url);
    }

    public function test_probe_round_trips_a_real_image(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
        Http::fake([
            'api.cloudinary.com/*/image/upload' => Http::response(['bytes' => strlen($png)]),
            'api.cloudinary.com/*/resources/image/authenticated/*' => Http::response(['bytes' => strlen($png)]),
            'api.cloudinary.com/*/image/destroy' => Http::response(['result' => 'ok']),
        ]);
        $this->assertTrue($this->store()->probe()['ok']);
    }
}
