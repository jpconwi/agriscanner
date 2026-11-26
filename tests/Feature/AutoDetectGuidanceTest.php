<?php

namespace Tests\Feature;

use App\Models\Crop;
use App\Models\Disease;
use App\Services\Ai\AiGuidance;
use App\Services\Ai\Dto\AnalysisResult;
use App\Services\Ai\Exceptions\ProviderException;
use App\Services\Ai\Providers\ChatVision;

class AutoDetectGuidanceTest extends ScanTestCase
{
    private function details(array $over = []): array
    {
        return $over + [
            'summary' => 'Brown oval spots with yellow halos on a rice leaf.',
            'symptoms' => ['Oval brown spots', 'Yellow halo around spots'],
            'causes' => ['Poor soil fertility', 'Long periods of leaf wetness'],
            'management' => ['Remove badly infected leaves', 'Improve field drainage'],
            'prevention' => ['Use clean seed', 'Balance fertiliser use'],
            'follow_up' => 'Check for new spots over the next week and contact a local agricultural technician if they spread.',
        ];
    }

    private function aiResult(?array $details, string $condition = 'bacterial leaf streak', string $crop = 'banana', ?float $confidence = null): AnalysisResult
    {
        return new AnalysisResult('cloudflare', 'fake-vision', $crop, $condition, false, $confidence, [], null, $details);
    }

    // ---- prompt and parsing ----------------------------------------------------------------------------

    public function test_the_prompt_asks_the_model_to_identify_the_crop_and_forbids_doses_and_products(): void
    {
        $p = ChatVision::prompt(['Tomato: Early blight']);

        $this->assertStringContainsString('identify the crop yourself', $p);
        $this->assertStringContainsString('Any crop or plant is allowed', $p);
        $this->assertStringContainsString('do not diagnose a disease listed for a different crop', $p);
        $this->assertStringContainsString('NO doses', $p);
        $this->assertStringContainsString('"follow_up"', $p);
        $this->assertStringContainsString('Tomato: Early blight', $p);
    }

    public function test_a_known_disease_for_a_different_crop_is_not_shown_as_the_diagnosis(): void
    {
        $this->farmer();
        $tomato = Crop::firstWhere('name', 'Tomato');
        Crop::create(['name' => 'Rice']);
        Disease::create([
            'crop_id' => $tomato->id,
            'name' => 'Leaf mold',
            'severity' => 'low',
        ]);
        $this->hf->script = [$this->aiResult($this->details(), 'leaf mold', 'rice', 0.91)];

        $res = $this->postScan()->assertCreated();

        $res->assertJsonPath('data.crop.name', 'Rice')
            ->assertJsonPath('data.predicted_label', 'unknown')
            ->assertJsonPath('data.disease', null)
            ->assertJsonPath('data.confidence', null)
            ->assertJsonPath('data.guidance_source', 'conflict')
            ->assertJsonPath('data.summary', null);
        $this->assertSame([], $res->json('data.symptoms'));
        $this->assertSame([], $res->json('data.treatments'));
    }

    public function test_parse_reads_crop_condition_and_guidance(): void
    {
        $json = json_encode(['crop' => 'Rice', 'condition' => 'Brown spot', 'is_healthy' => false] + $this->details());

        $parsed = ChatVision::parse("Here you go:\n".$json);

        $this->assertSame('Rice', $parsed['crop']);
        $this->assertSame('Brown spot', $parsed['condition']);
        $this->assertSame(['Oval brown spots', 'Yellow halo around spots'], $parsed['details']['symptoms']);
        $this->assertSame(['Remove badly infected leaves', 'Improve field drainage'], $parsed['details']['management']);
        $this->assertSame('Check for new spots over the next week and contact a local agricultural technician if they spread.', $parsed['details']['follow_up']);
    }

    public function test_a_minimal_reply_without_guidance_still_parses(): void
    {
        $parsed = ChatVision::parse('{"crop":"Rice","condition":"Rice blast","is_healthy":false}');

        $this->assertNull($parsed['details']);
    }

    public function test_prose_without_json_is_still_a_transient_failure(): void
    {
        $this->expectException(ProviderException::class);
        ChatVision::parse('It looks like a leaf disease.');
    }

    public function test_lines_with_doses_or_pesticide_names_are_dropped(): void
    {
        $clean = AiGuidance::sanitize($this->details([
            'management' => [
                'Remove badly infected leaves',
                'Spray mancozeb weekly',
                'Apply 2 ml per litre of water',
                'Mix 5 g per tank and spray',
                'Use 0.5% copper solution',
                'Improve field drainage',
            ],
            'follow_up' => 'Recheck in 7 days and apply 2 ml per litre if symptoms remain.',
        ]));

        $this->assertSame(['Remove badly infected leaves', 'Improve field drainage'], $clean['management']);
        $this->assertNull($clean['follow_up']);
    }

    public function test_the_guidance_is_trimmed_deduplicated_and_capped(): void
    {
        $clean = AiGuidance::sanitize([
            'summary' => '  Spots <b>on</b> the leaf.  ',
            'symptoms' => ['A', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 42, null],
        ]);

        $this->assertSame('Spots on the leaf.', $clean['summary']);
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $clean['symptoms']);
        $this->assertNull($clean['follow_up']);
    }

    public function test_empty_guidance_becomes_null(): void
    {
        $this->assertNull(AiGuidance::sanitize(['summary' => '', 'symptoms' => [], 'management' => 'not a list']));
    }

    // ---- end to end: a crop the library has never heard of ---------------------------------------------

    public function test_an_unlisted_crop_and_disease_still_get_a_result_with_labelled_ai_guidance(): void
    {
        $this->farmer();
        $this->hf->script = [$this->aiResult($this->details())];

        $res = $this->postScan()->assertCreated();

        $res->assertJsonPath('data.disease', null)
            ->assertJsonPath('data.crop', null)
            ->assertJsonPath('data.crop_label', 'banana')
            ->assertJsonPath('data.predicted_label', 'bacterial leaf streak')
            ->assertJsonPath('data.guidance_source', 'ai')
            ->assertJsonPath('data.summary', 'Brown oval spots with yellow halos on a rice leaf.')
            ->assertJsonPath('data.symptoms.0', 'Oval brown spots')
            ->assertJsonPath('data.treatments.0', 'Remove badly infected leaves')
            ->assertJsonPath('data.contributing_factors.0', 'Poor soil fertility')
            ->assertJsonPath('data.prevention.0', 'Use clean seed')
            ->assertJsonPath('data.follow_up', 'Check for new spots over the next week and contact a local agricultural technician if they spread.');
    }

    public function test_a_library_match_wins_over_the_models_own_advice(): void
    {
        $this->farmer();
        $this->hf->script = [$this->aiResult($this->details(['management' => ['Burn everything']]), 'early blight', 'tomato')];

        $res = $this->postScan()->assertCreated();

        $res->assertJsonPath('data.disease.name', 'Early blight')
            ->assertJsonPath('data.guidance_source', 'library')
            ->assertJsonPath('data.treatments.0', 'Remove infected leaves');
        $this->assertNotContains('Burn everything', $res->json('data.treatments'));
    }

    public function test_a_classifier_label_without_guidance_still_gets_no_invented_text(): void
    {
        $this->farmer();
        $this->hf->script = [$this->aiResult(null, 'mystery wilt')];

        $res = $this->postScan()->assertCreated();

        $res->assertJsonPath('data.guidance_source', null);
        $this->assertSame([], $res->json('data.treatments'));
    }

    public function test_the_scan_form_no_longer_asks_for_a_crop(): void
    {
        $user = $this->farmer();

        $this->actingAs($user)->get('/scan')->assertOk()
            ->assertDontSee('Let the model decide')
            ->assertSee('detected automatically');
    }
}
