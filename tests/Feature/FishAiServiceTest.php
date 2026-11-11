<?php

namespace Tests\Feature;

use App\Services\FishAiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The model recognizer powers scan results, so its
 * helpers must keep the same guarantees the old AI plumbing enforced.
 */
class FishAiServiceTest extends TestCase
{
    public function test_confidence_bands_use_the_shared_thresholds(): void
    {
        $this->assertSame('high', FishAiService::confidenceBand(0.80));
        $this->assertSame('high', FishAiService::confidenceBand(0.93));
        $this->assertSame('moderate', FishAiService::confidenceBand(0.60));
        $this->assertSame('moderate', FishAiService::confidenceBand(0.79));
        $this->assertSame('low', FishAiService::confidenceBand(0.42));
    }

    public function test_percent_scale_confidences_are_clamped_to_the_band_scale(): void
    {
        $this->assertSame('high', FishAiService::confidenceBand(95));
        $this->assertSame('low', FishAiService::confidenceBand(0));
        /* Out-of-range percent values clamp to the top of the scale. */
        $this->assertSame('high', FishAiService::confidenceBand(150));
    }

    public function test_species_names_map_to_their_latin_labels(): void
    {
        $this->assertSame('Chanos chanos', FishAiService::latinFor('Milkfish'));
        $this->assertSame('Oreochromis niloticus', FishAiService::latinFor('Tilapia'));
        /* Case-insensitive partial matches stay supported. */
        $this->assertSame('Thunnus albacares', FishAiService::latinFor('tuna'));
    }

    public function test_an_unknown_species_stays_unchanged(): void
    {
        $this->assertSame('Galunggong', FishAiService::latinFor('Galunggong'));
    }

    public function test_predict_returns_null_when_no_model_service_is_configured(): void
    {
        config([
            'ai.url' => null,
            'roboflow.api_key' => null,
        ]);

        $this->assertNull((new FishAiService)->predict($this->modelSampleImage()));
    }

    public function test_a_connected_model_answer_is_mapped_into_the_scan_contract(): void
    {
        config(['ai.url' => 'http://fish-model.test']);

        Http::fake([
            'fish-model.test/predict' => Http::response([
                'species' => 'Tilapia',
                'species_label' => 'Oreochromis niloticus',
                /* Percent-scale confidences are accepted and clamped. */
                'species_confidence' => 94,
                'freshness' => 'fresh',
                'freshness_percentage' => 87,
                'freshness_confidence' => 0.87,
            ]),
        ]);

        $result = (new FishAiService)->predict($this->modelSampleImage());

        $this->assertSame('ai', $result['source']);
        $this->assertSame('Tilapia', $result['species']);
        $this->assertSame('Oreochromis niloticus', $result['species_label']);
        $this->assertSame(0.94, $result['species_confidence']);
        $this->assertSame('fresh', $result['freshness']);
        $this->assertSame(87, $result['freshness_percentage']);
        $this->assertSame(0.87, $result['freshness_confidence']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://fish-model.test/predict'
            && $request->hasFile('image'));
    }

    /** An answer without a label borrows the species' latin name. */
    public function test_a_model_answer_without_a_label_gets_the_latin_name(): void
    {
        config(['ai.url' => 'http://fish-model.test']);

        Http::fake([
            'fish-model.test/predict' => Http::response([
                'species' => 'Tuna',
                'species_confidence' => 0.9,
                'freshness' => 'moderate',
                'freshness_percentage' => 66,
            ]),
        ]);

        $result = (new FishAiService)->predict($this->modelSampleImage());

        $this->assertSame('ai', $result['source']);
        $this->assertSame('Thunnus albacares', $result['species_label']);
        $this->assertSame(0.66, $result['freshness_confidence']);
    }

    public function test_an_erroring_model_service_returns_no_result(): void
    {
        config(['ai.url' => 'http://fish-model.test']);

        Http::fake([
            'fish-model.test/predict' => Http::response(['detail' => 'model warming up'], 503),
        ]);

        $this->assertNull((new FishAiService)->predict($this->modelSampleImage()));
    }

    public function test_an_unreachable_model_service_returns_no_result(): void
    {
        config(['ai.url' => 'http://fish-model.test']);

        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->assertNull((new FishAiService)->predict($this->modelSampleImage()));
    }

    /** The contract in config/ai.php is enforced, not trusted. */
    public function test_a_payload_outside_the_contract_returns_no_result(): void
    {
        config(['ai.url' => 'http://fish-model.test']);

        Http::fake([
            'fish-model.test/predict' => Http::response([
                'species' => 'Tilapia',
                'freshness' => 'rotten',
                'freshness_percentage' => 87,
            ]),
        ]);

        $this->assertNull((new FishAiService)->predict($this->modelSampleImage()));
    }

    /** Nothing to analyse means the model service is never called at all. */
    public function test_predict_without_an_image_skips_the_model_service(): void
    {
        config(['ai.url' => 'http://fish-model.test']);

        Http::fake();

        $this->assertNull((new FishAiService)->predict());
        Http::assertNothingSent();
    }

    public function test_predict_uses_roboflow_workflow_when_configured(): void
    {
        config([
            'roboflow.api_key' => 'rf_test_key',
            'roboflow.workspace' => 'test-workspace',
            'roboflow.workflow_id' => 'test-workflow',
        ]);

        Http::fake([
            'serverless.roboflow.com/*' => Http::response([
                'outputs' => [
                    [
                        'predictions' => [
                            'predictions' => [
                                'Bangus' => ['confidence' => 0.95, 'class_id' => 0],
                                'Fresh' => ['confidence' => 0.89, 'class_id' => 2],
                            ],
                            'predicted_classes' => ['Bangus', 'Fresh'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = (new FishAiService)->predict($this->modelSampleImage());

        $this->assertSame('ai', $result['source']);
        $this->assertSame('Bangus', $result['species']);
        $this->assertSame('Chanos chanos', $result['species_label']);
        $this->assertSame(0.95, $result['species_confidence']);
        $this->assertSame('fresh', $result['freshness']);
        $this->assertSame(89, $result['freshness_percentage']);
    }

    public function test_predict_returns_no_result_when_roboflow_errors(): void
    {
        config([
            'roboflow.api_key' => 'rf_test_key',
            'ai.url' => null,
        ]);

        Http::fake([
            'serverless.roboflow.com/*' => Http::response(['message' => 'Internal server error'], 500),
        ]);

        $this->assertNull((new FishAiService)->predict($this->modelSampleImage()));
    }

    /** A real temp file, so the multipart client has a body to attach. */
    private function modelSampleImage(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'freshda_model');
        file_put_contents($path, 'sample image bytes');

        return (string) $path;
    }
}
