<?php

namespace Tests\Feature;

use App\Models\AiProviderLog;
use App\Services\Ai\AiAnalysisService;
use App\Services\Ai\Dto\ImagePayload;
use App\Services\Ai\Exceptions\AnalysisUnavailableException;
use App\Services\Ai\Exceptions\InvalidImageException;
use App\Services\Ai\Providers\GrokProvider;
use App\Services\Ai\Providers\HuggingFaceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeVisionProvider as F;

class AiFallbackTest extends ScanTestCase
{
    /** Http::fake() stacks stubs (first match wins), so reset the client before each scenario. */
    private function fakeHttp(array|callable $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($stubs);
    }

    private function run_chain(): \App\Services\Ai\Dto\AnalysisResult
    {
        return app(AiAnalysisService::class)->analyze(new ImagePayload('bytes', 'image/jpeg'), ['Early blight']);
    }

    public function test_primary_is_used_when_healthy_and_fallback_is_untouched(): void
    {
        $this->hf->script = [F::result()];

        $r = $this->run_chain();

        $this->assertSame('huggingface', $r->provider);
        $this->assertSame(1, $this->hf->calls);
        $this->assertSame(0, $this->grok->calls);
    }

    public function test_a_transient_error_is_retried_on_the_same_provider_with_backoff(): void
    {
        $this->hf->script = [F::transient('timeout', 408), F::transient('rate_limited', 429), F::result()];

        $r = $this->run_chain();

        $this->assertSame('huggingface', $r->provider);
        $this->assertSame(3, $this->hf->calls);
        $this->assertSame(0, $this->grok->calls, 'no fallback needed after the retry succeeded');
        $this->assertSame(2, AiProviderLog::where('provider', 'huggingface')->where('outcome', 'failure')->count());
        $this->assertSame(1, AiProviderLog::where('provider', 'huggingface')->where('outcome', 'success')->count());
        \Illuminate\Support\Sleep::assertSleptTimes(2);
    }

    public function test_retries_are_bounded_then_the_next_provider_is_used(): void
    {
        $this->hf->script = array_fill(0, 5, F::transient());
        $this->grok->script = [F::result('grok')];

        $r = $this->run_chain();

        $this->assertSame('grok', $r->provider);
        $this->assertSame(3, $this->hf->calls, '1 attempt + 2 bounded retries');
        $this->assertSame(1, $this->grok->calls);
    }

    public function test_permanent_provider_errors_skip_straight_to_the_next_provider_without_retrying(): void
    {
        foreach ([F::permanent('auth_error', 401), F::permanent('payment_required', 402), F::permanent('model_unavailable', 404)] as $error) {
            $this->hf->calls = 0;
            $this->hf->script = [$error];
            $this->grok->script = [F::result('grok')];

            $this->assertSame('grok', $this->run_chain()->provider);
            $this->assertSame(1, $this->hf->calls, $error->category.' must not be retried');
        }
    }

    public function test_invalid_input_stops_everything_with_no_retry_and_no_fallback(): void
    {
        $this->hf->script = [F::invalidInput()];
        $this->grok->script = [F::result('grok')];

        try {
            $this->run_chain();
            $this->fail('expected InvalidImageException');
        } catch (InvalidImageException) {
            $this->assertSame(1, $this->hf->calls);
            $this->assertSame(0, $this->grok->calls);
        }
    }

    public function test_when_every_provider_fails_a_clear_error_is_thrown_and_nothing_is_fabricated(): void
    {
        $this->hf->script = array_fill(0, 3, F::transient());
        $this->grok->script = array_fill(0, 3, F::transient('timeout', 408));

        $this->expectException(AnalysisUnavailableException::class);
        $this->run_chain();
    }

    public function test_unconfigured_providers_are_skipped_automatically(): void
    {
        $this->hf->configured = false;
        $this->grok->script = [F::result('grok')];

        $this->assertSame('grok', $this->run_chain()->provider);
        $this->assertSame(0, $this->hf->calls);
    }

    public function test_with_no_configured_provider_analysis_is_unavailable(): void
    {
        $this->hf->configured = false;
        $this->grok->configured = false;

        $this->expectException(AnalysisUnavailableException::class);
        $this->run_chain();
    }

    public function test_failures_are_logged_with_provider_model_latency_and_category_but_no_secrets(): void
    {
        $this->hf->script = [F::permanent('auth_error', 401)];
        $this->grok->script = [F::result('grok')];
        $this->run_chain();

        $log = AiProviderLog::where('provider', 'huggingface')->first();
        $this->assertSame('fake-model', $log->model);
        $this->assertSame('auth_error', $log->error_category);
        $this->assertSame(401, $log->http_status);
        $this->assertNotNull($log->latency_ms);
        $this->assertSame([], array_intersect(['token', 'secret', 'api_key', 'payload'], array_keys($log->getAttributes())));
    }

    public function test_end_to_end_the_api_shows_a_retry_message_when_all_providers_fail(): void
    {
        $this->farmer();
        $this->hf->script = array_fill(0, 3, F::transient());
        $this->grok->script = array_fill(0, 3, F::transient());

        $res = $this->postScan()->assertStatus(503);

        $res->assertJsonPath('code', 'analysis_unavailable');
        $this->assertStringContainsString('try again', strtolower($res->json('message')));
        $this->assertDatabaseCount('scan_results', 0);
    }

    // ---- real adapters against faked HTTP (no network, no paid calls) ----------------------------

    private function hfProvider(array $over = []): HuggingFaceProvider
    {
        return new HuggingFaceProvider($over + [
            'enabled' => true, 'token' => 'hf_test', 'model' => 'org/plant-model', 'task' => 'image-classification',
            'classification_base' => 'https://router.test/hf-inference/models', 'chat_url' => 'https://router.test/v1/chat/completions', 'hub_url' => 'https://hub.test/api/models',
        ], 5);
    }

    public function test_huggingface_classification_returns_real_probabilities(): void
    {
        $this->fakeHttp(['router.test/*' => Http::response([['label' => 'Tomato___healthy', 'score' => 0.1], ['label' => 'Tomato___Early_blight', 'score' => 0.83]])]);

        $r = $this->hfProvider()->analyze(new ImagePayload('x', 'image/png'));

        $this->assertSame('tomato', $r->cropLabel);
        $this->assertSame('early blight', $r->conditionLabel);
        $this->assertEqualsWithDelta(0.83, $r->confidence, 0.0001);
        $this->assertFalse($r->isHealthy);
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer hf_test') && str_contains($req->url(), 'plant-model'));
    }

    public function test_huggingface_chat_models_report_no_confidence(): void
    {
        $this->fakeHttp(['router.test/*' => Http::response(['choices' => [['message' => ['content' => 'Sure! {"crop":"Tomato","condition":"Early blight","is_healthy":false}']]]])]);

        $r = $this->hfProvider(['task' => 'chat'])->analyze(new ImagePayload('x', 'image/png'), ['Early blight']);

        $this->assertNull($r->confidence, 'a language model has no calibrated probability');
        $this->assertSame('Early blight', $r->conditionLabel);
    }

    public function test_huggingface_http_errors_map_to_the_right_categories(): void
    {
        $cases = [[429, true, 'rate_limited'], [503, true, 'provider_error'], [402, false, 'payment_required'], [401, false, 'auth_error'], [404, false, 'model_unavailable'], [422, false, 'invalid_input']];
        foreach ($cases as [$status, $transient, $category]) {
            $this->fakeHttp(['router.test/*' => Http::response(['error' => 'secret details'], $status)]);
            try {
                $this->hfProvider()->analyze(new ImagePayload('x', 'image/png'));
                $this->fail("expected failure for $status");
            } catch (\App\Services\Ai\Exceptions\ProviderException $e) {
                $this->assertSame($category, $e->category, "status $status");
                $this->assertSame($transient, $e->transient, "status $status");
                $this->assertStringNotContainsString('secret details', $e->getMessage(), 'provider bodies are never surfaced');
            }
        }
    }

    public function test_huggingface_timeouts_are_transient(): void
    {
        $this->fakeHttp(fn () => throw new ConnectionException('timed out'));

        try {
            $this->hfProvider()->analyze(new ImagePayload('x', 'image/png'));
            $this->fail('expected exception');
        } catch (\App\Services\Ai\Exceptions\ProviderException $e) {
            $this->assertTrue($e->transient);
            $this->assertSame('timeout', $e->category);
        }
    }

    public function test_huggingface_verify_checks_the_model_accepts_images(): void
    {
        $this->fakeHttp(['hub.test/api/models/org/plant-model' => Http::response(['pipeline_tag' => 'image-classification'])]);
        $this->assertTrue($this->hfProvider()->verify()['ok']);

        $this->fakeHttp(['hub.test/api/models/org/plant-model' => Http::response(['pipeline_tag' => 'text-generation'])]);
        $this->assertFalse($this->hfProvider()->verify()['ok'], 'a text-only model must be rejected');

        $this->fakeHttp(['hub.test/api/models/org/plant-model' => Http::response([], 404)]);
        $this->assertFalse($this->hfProvider()->verify()['ok']);
    }

    public function test_huggingface_is_not_configured_without_token_or_model(): void
    {
        $this->assertFalse($this->hfProvider(['token' => ''])->isConfigured());
        $this->assertFalse($this->hfProvider(['model' => null])->isConfigured());
        $this->assertFalse($this->hfProvider(['enabled' => false])->isConfigured());
    }

    public function test_grok_is_never_called_unless_explicitly_authorised(): void
    {
        $base = ['enabled' => true, 'api_key' => 'xai_test', 'model' => 'some-vision-model', 'base_url' => 'https://xai.test/v1', 'access_authorized' => false];

        $this->assertFalse((new GrokProvider($base))->isConfigured(), 'enabled + key is not enough without the authorisation flag');
        $this->assertFalse((new GrokProvider(['enabled' => false, 'access_authorized' => true] + $base))->isConfigured());
        $this->assertFalse((new GrokProvider(['api_key' => ''] + ['access_authorized' => true] + $base))->isConfigured());
        $this->assertTrue((new GrokProvider(['access_authorized' => true] + $base))->isConfigured());
    }

    public function test_grok_parses_a_chat_response_and_maps_a_402_to_payment_required(): void
    {
        $cfg = ['enabled' => true, 'access_authorized' => true, 'api_key' => 'xai_test', 'model' => 'm', 'base_url' => 'https://xai.test/v1'];
        $this->fakeHttp(['xai.test/*' => Http::response(['choices' => [['message' => ['content' => '{"crop":"Rice","condition":"Rice blast","is_healthy":false}']]]])]);
        $r = (new GrokProvider($cfg))->analyze(new ImagePayload('x', 'image/jpeg'), ['Rice blast']);
        $this->assertSame('grok', $r->provider);
        $this->assertNull($r->confidence);

        $this->fakeHttp(['xai.test/*' => Http::response([], 402)]);
        try {
            (new GrokProvider($cfg))->analyze(new ImagePayload('x', 'image/jpeg'));
            $this->fail('expected exception');
        } catch (\App\Services\Ai\Exceptions\ProviderException $e) {
            $this->assertSame('payment_required', $e->category);
            $this->assertFalse($e->transient, 'a paid endpoint is never retried or silently used');
        }
    }

    public function test_an_unparseable_model_answer_is_treated_as_a_transient_failure_not_a_prediction(): void
    {
        $this->fakeHttp(['router.test/*' => Http::response(['choices' => [['message' => ['content' => 'I think it looks sick.']]]])]);

        try {
            $this->hfProvider(['task' => 'chat'])->analyze(new ImagePayload('x', 'image/png'));
            $this->fail('expected exception');
        } catch (\App\Services\Ai\Exceptions\ProviderException $e) {
            $this->assertSame('invalid_response', $e->category);
        }
    }
}
