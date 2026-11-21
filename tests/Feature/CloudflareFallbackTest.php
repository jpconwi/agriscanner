<?php

namespace Tests\Feature;

use App\Services\Ai\AiAnalysisService;
use App\Services\Ai\Dto\ImagePayload;
use App\Services\Ai\Exceptions\AnalysisUnavailableException;
use App\Services\Ai\Exceptions\ProviderException;
use App\Services\Ai\Providers\CloudflareProvider;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeVisionProvider;
use Tests\Fakes\FakeVisionProvider as F;

class CloudflareFallbackTest extends ScanTestCase
{
    private FakeVisionProvider $cf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cf = new FakeVisionProvider('cloudflare');
        $this->useProviders(
            ['huggingface' => $this->hf, 'cloudflare' => $this->cf, 'grok' => $this->grok],
            ['huggingface', 'cloudflare', 'grok'],
        );
    }

    private function run_chain(): \App\Services\Ai\Dto\AnalysisResult
    {
        return app(AiAnalysisService::class)->analyze(new ImagePayload('bytes', 'image/jpeg'), ['Early blight']);
    }

    private function cfConfig(array $over = []): array
    {
        return $over + ['enabled' => true, 'account_id' => 'acc123', 'api_token' => 'cf_test', 'model' => '@cf/meta/llama-3.2-11b-vision-instruct', 'base_url' => 'https://cf.test/client/v4'];
    }

    private function fakeHttp(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($stubs);
    }

    // ---- chain order: huggingface -> cloudflare -> grok ------------------------------------------------

    public function test_huggingface_answers_first_and_the_others_are_untouched(): void
    {
        $this->hf->script = [F::result('huggingface')];

        $this->assertSame('huggingface', $this->run_chain()->provider);
        $this->assertSame(0, $this->cf->calls);
        $this->assertSame(0, $this->grok->calls);
    }

    public function test_huggingface_payment_required_falls_to_cloudflare_not_grok(): void
    {
        $this->hf->script = [F::permanent('payment_required', 402)];
        $this->cf->script = [F::result('cloudflare')];

        $this->assertSame('cloudflare', $this->run_chain()->provider);
        $this->assertSame(1, $this->hf->calls, 'permanent error: no retry');
        $this->assertSame(0, $this->grok->calls);
    }

    public function test_cloudflare_failing_too_falls_to_grok(): void
    {
        $this->hf->script = [F::permanent('payment_required', 402)];
        $this->cf->script = array_fill(0, 3, F::transient()); // 1 try + 2 retries
        $this->grok->script = [F::result('grok')];

        $this->assertSame('grok', $this->run_chain()->provider);
        $this->assertSame(3, $this->cf->calls);
        $this->assertSame(1, $this->grok->calls);
    }

    public function test_all_three_failing_throws_and_never_invents_a_result(): void
    {
        $this->hf->script = [F::permanent('payment_required', 402)];
        $this->cf->script = [F::permanent('rate_limited', 429)];
        $this->grok->script = [F::permanent('auth_error', 401)];

        $this->expectException(AnalysisUnavailableException::class);
        $this->run_chain();
    }

    public function test_an_unconfigured_cloudflare_is_skipped_silently(): void
    {
        $this->cf->configured = false;
        $this->hf->script = [F::permanent('payment_required', 402)];
        $this->grok->script = [F::result('grok')];

        $this->assertSame('grok', $this->run_chain()->provider);
        $this->assertSame(0, $this->cf->calls);
    }

    public function test_invalid_image_stops_the_whole_chain(): void
    {
        $this->hf->script = [F::invalidInput()];

        try {
            $this->run_chain();
            $this->fail('expected InvalidImageException');
        } catch (\App\Services\Ai\Exceptions\InvalidImageException) {
            $this->assertSame(0, $this->cf->calls);
            $this->assertSame(0, $this->grok->calls);
        }
    }

    // ---- CloudflareProvider itself ---------------------------------------------------------------------

    public function test_cloudflare_needs_account_token_and_model(): void
    {
        $this->assertTrue((new CloudflareProvider($this->cfConfig()))->isConfigured());
        $this->assertFalse((new CloudflareProvider($this->cfConfig(['account_id' => ''])))->isConfigured());
        $this->assertFalse((new CloudflareProvider($this->cfConfig(['api_token' => null])))->isConfigured());
        $this->assertFalse((new CloudflareProvider($this->cfConfig(['model' => ''])))->isConfigured());
        $this->assertFalse((new CloudflareProvider($this->cfConfig(['enabled' => false])))->isConfigured());
    }

    public function test_cloudflare_parses_a_workers_ai_response(): void
    {
        $this->fakeHttp(['cf.test/*' => Http::response(['success' => true, 'errors' => [], 'result' => ['response' => '{"crop":"Rice","condition":"Rice blast","is_healthy":false}']])]);

        $r = (new CloudflareProvider($this->cfConfig()))->analyze(new ImagePayload('x', 'image/jpeg'), ['Rice blast']);

        $this->assertSame('cloudflare', $r->provider);
        $this->assertSame('Rice blast', $r->conditionLabel);
        $this->assertNull($r->confidence, 'a chat model reports no calibrated confidence');
        Http::assertSent(fn ($req) => str_contains($req->url(), '/accounts/acc123/ai/run/@cf/meta/llama-3.2-11b-vision-instruct')
            && $req->hasHeader('Authorization', 'Bearer cf_test'));
    }

    public function test_cloudflare_daily_limit_is_not_retried(): void
    {
        $this->fakeHttp(['cf.test/*' => Http::response(['success' => false, 'errors' => [['code' => 4006, 'message' => 'you have used up your daily free allocation of 10,000 neurons']]], 429)]);

        try {
            (new CloudflareProvider($this->cfConfig()))->analyze(new ImagePayload('x', 'image/jpeg'));
            $this->fail('expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame('rate_limited', $e->category);
            $this->assertFalse($e->transient, 'retrying an exhausted daily allowance only wastes time');
        }
    }

    public function test_cloudflare_unaccepted_licence_is_a_clear_permanent_error(): void
    {
        $this->fakeHttp(['cf.test/*' => Http::response(['success' => false, 'errors' => [['code' => 5016, 'message' => 'Prior to using this model, you must submit the prompt agree']]], 403)]);

        try {
            (new CloudflareProvider($this->cfConfig()))->analyze(new ImagePayload('x', 'image/jpeg'));
            $this->fail('expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame('model_unavailable', $e->category);
            $this->assertFalse($e->transient);
        }
    }

    public function test_cloudflare_server_errors_are_transient_and_auth_errors_are_not(): void
    {
        $this->fakeHttp(['cf.test/*' => Http::response([], 503)]);
        try {
            (new CloudflareProvider($this->cfConfig()))->analyze(new ImagePayload('x', 'image/jpeg'));
            $this->fail('expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertTrue($e->transient);
        }

        $this->fakeHttp(['cf.test/*' => Http::response([], 401)]);
        try {
            (new CloudflareProvider($this->cfConfig()))->analyze(new ImagePayload('x', 'image/jpeg'));
            $this->fail('expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame('auth_error', $e->category);
            $this->assertFalse($e->transient);
        }
    }

    public function test_an_answer_in_prose_is_a_transient_failure_not_a_prediction(): void
    {
        $this->fakeHttp(['cf.test/*' => Http::response(['success' => true, 'result' => ['response' => 'It looks unhealthy.']])]);

        try {
            (new CloudflareProvider($this->cfConfig()))->analyze(new ImagePayload('x', 'image/jpeg'));
            $this->fail('expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame('invalid_response', $e->category);
            $this->assertTrue($e->transient);
        }
    }

    public function test_cloudflare_verify_reports_rejected_credentials_and_a_found_model(): void
    {
        $this->fakeHttp(['cf.test/*' => Http::response([], 403)]);
        $this->assertSame('down', (new CloudflareProvider($this->cfConfig()))->verify()['status']);

        $this->fakeHttp(['cf.test/*' => Http::response(['success' => true, 'result' => [['name' => '@cf/meta/llama-3.2-11b-vision-instruct']]])]);
        $this->assertTrue((new CloudflareProvider($this->cfConfig()))->verify()['ok']);

        $this->fakeHttp(['cf.test/*' => Http::response(['success' => true, 'result' => []])]);
        $this->assertSame('degraded', (new CloudflareProvider($this->cfConfig()))->verify()['status']);
    }
}
