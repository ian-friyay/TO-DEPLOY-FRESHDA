<?php

namespace Tests\Feature;

use App\Exceptions\RoboflowWorkflowException;
use App\Services\FishAiService;
use App\Services\RoboflowWorkflowClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RoboflowWorkflowSmokeTest extends TestCase
{
    private function sampleImagePath(): string
    {
        return public_path('icons/icon-192.png');
    }

    public function test_it_throws_exception_if_api_key_is_missing(): void
    {
        $client = new RoboflowWorkflowClient(apiKey: null);

        $this->expectException(RoboflowWorkflowException::class);
        $this->expectExceptionMessage('Roboflow API key is not configured.');

        $client->runWorkflow($this->sampleImagePath());
    }

    public function test_it_rejects_non_https_image_urls(): void
    {
        $client = new RoboflowWorkflowClient(apiKey: 'dummy_key');

        $this->expectException(RoboflowWorkflowException::class);
        $this->expectExceptionMessage('Roboflow requires HTTPS URLs (plain http:// is rejected).');

        $client->runWorkflow('http://example.com/fish.jpg');
    }

    public function test_it_sends_bearer_token_and_formats_base64_payload(): void
    {
        Http::fake([
            'serverless.roboflow.com/*' => Http::response([
                'outputs' => [
                    [
                        'predictions' => [
                            'predictions' => [
                                'Galonggong' => ['confidence' => 0.88, 'class_id' => 3],
                                'Fresh' => ['confidence' => 0.91, 'class_id' => 2],
                            ],
                            'predicted_classes' => ['Galonggong', 'Fresh'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = new RoboflowWorkflowClient(
            apiKey: 'rf_test_secret_key',
            workspace: 'test-workspace',
            workflowId: 'test-workflow'
        );

        $outputs = $client->runWorkflow($this->sampleImagePath());

        $this->assertArrayHasKey('predictions', $outputs);

        Http::assertSent(function (Request $request): bool {
            $hasAuthHeader = $request->header('Authorization')[0] === 'Bearer rf_test_secret_key';
            $isJson = $request->header('Content-Type')[0] === 'application/json';
            $data = $request->data();
            $hasImageInput = isset($data['inputs']['image']['type']) && $data['inputs']['image']['type'] === 'base64';

            return $hasAuthHeader && $isJson && $hasImageInput;
        });
    }

    public function test_it_handles_typed_exception_on_server_error(): void
    {
        Http::fake([
            'serverless.roboflow.com/*' => Http::response([
                'message' => 'Unauthorized api_key',
            ], 401),
        ]);

        $client = new RoboflowWorkflowClient(
            apiKey: 'invalid_key',
            retryTimes: 1
        );

        $this->expectException(RoboflowWorkflowException::class);
        $this->expectExceptionMessage('Unauthorized api_key');

        $client->runWorkflow($this->sampleImagePath());
    }

    public function test_it_translates_roboflow_payload_to_fish_scan_contract(): void
    {
        $roboflowOutput = [
            'predictions' => [
                'predictions' => [
                    'Bangus' => ['confidence' => 0.05, 'class_id' => 0],
                    'Galonggong' => ['confidence' => 0.85, 'class_id' => 3],
                    'Not Fresh' => ['confidence' => 0.92, 'class_id' => 4],
                    'Fresh' => ['confidence' => 0.08, 'class_id' => 2],
                ],
                'predicted_classes' => ['Galonggong', 'Not Fresh'],
            ],
        ];

        $scan = FishAiService::fromRoboflowOutputs($roboflowOutput);

        $this->assertNotNull($scan);
        $this->assertTrue($scan['is_fish']);
        $this->assertSame('Galonggong', $scan['species']);
        $this->assertSame('Decapterus macarellus', $scan['species_label']);
        $this->assertSame(0.85, $scan['species_confidence']);
        $this->assertSame('not_fresh', $scan['freshness']);
        $this->assertSame(92, $scan['freshness_percentage']);
        $this->assertSame(0.92, $scan['freshness_confidence']);
        $this->assertSame('ai', $scan['source']);
    }

    public function test_it_translates_fresh_da_project_5_prefixed_classes_payload(): void
    {
        $roboflowOutput = [
            'predictions' => [
                'predictions' => [
                    'freshness_fresh' => ['confidence' => 0.83, 'class_id' => 0],
                    'freshness_moderate' => ['confidence' => 0.17, 'class_id' => 1],
                    'freshness_not_fresh' => ['confidence' => 0.03, 'class_id' => 2],
                    'species_bangus' => ['confidence' => 0.03, 'class_id' => 3],
                    'species_dalagang_bukid' => ['confidence' => 0.04, 'class_id' => 4],
                    'species_galunggong' => ['confidence' => 0.95, 'class_id' => 5],
                    'species_other_fishes' => ['confidence' => 0.02, 'class_id' => 6],
                    'species_tilapia' => ['confidence' => 0.02, 'class_id' => 7],
                    'species_tulingan' => ['confidence' => 0.02, 'class_id' => 8],
                ],
                'predicted_classes' => ['freshness_fresh', 'species_galunggong'],
            ],
            'inference_id' => 'test-id',
            'model_id' => 'evelyns-workspace-gfs2g/fresh-da-project-5-vit-base-patch16-224-in21k-t1',
        ];

        $scan = FishAiService::fromRoboflowOutputs($roboflowOutput);

        $this->assertNotNull($scan);
        $this->assertTrue($scan['is_fish']);
        $this->assertSame('Galonggong', $scan['species']);
        $this->assertSame('Decapterus macarellus', $scan['species_label']);
        $this->assertSame(0.95, $scan['species_confidence']);
        $this->assertSame('fresh', $scan['freshness']);
        $this->assertSame(83, $scan['freshness_percentage']);
        $this->assertSame(0.83, $scan['freshness_confidence']);
        $this->assertSame('ai', $scan['source']);
    }

    public function test_it_rejects_images_classified_as_other_fishes(): void
    {
        $roboflowOutput = [
            'predictions' => [
                'predictions' => [
                    'Other Fishes' => ['confidence' => 0.82, 'class_id' => 5],
                    'Not Fresh' => ['confidence' => 0.70, 'class_id' => 4],
                ],
                'predicted_classes' => ['Other Fishes', 'Not Fresh'],
            ],
        ];

        $scan = FishAiService::fromRoboflowOutputs($roboflowOutput);

        $this->assertNotNull($scan);
        $this->assertFalse($scan['is_fish']);
        $this->assertStringContainsString('No fish detected', $scan['message']);
    }

    public function test_it_rejects_predictions_below_confidence_threshold(): void
    {
        config(['roboflow.min_confidence' => 0.70]);

        $roboflowOutput = [
            'predictions' => [
                'predictions' => [
                    'Bangus' => ['confidence' => 0.52, 'class_id' => 0],
                    'Fresh' => ['confidence' => 0.60, 'class_id' => 2],
                ],
                'predicted_classes' => ['Bangus', 'Fresh'],
            ],
        ];

        $scan = FishAiService::fromRoboflowOutputs($roboflowOutput);

        $this->assertNotNull($scan);
        $this->assertFalse($scan['is_fish']);
        $this->assertStringContainsString('Unclear fish detection', $scan['message']);
    }

    public function test_live_workflow_smoke_test_when_configured(): void
    {
        $apiKey = config('roboflow.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            $this->markTestSkipped('ROBOFLOW_API_KEY is not set in environment.');
        }

        $client = RoboflowWorkflowClient::fromConfig();
        $outputs = $client->runWorkflow($this->sampleImagePath());

        // Assert expected output keys exist
        $this->assertIsArray($outputs);
        $this->assertArrayHasKey('predictions', $outputs);
        $this->assertIsArray($outputs['predictions']);

        // Since sampleImagePath() is an app icon (not a fish), it must be safely identified as not a fish
        $scan = FishAiService::fromRoboflowOutputs($outputs);
        $this->assertNotNull($scan);
        $this->assertFalse($scan['is_fish']);
        $this->assertStringContainsString('fish', strtolower($scan['message']));
    }
}
