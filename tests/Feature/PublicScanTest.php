<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_scanner_page_renders_for_guests(): void
    {
        $response = $this->get('/scan');

        $response->assertOk();
        $response->assertSee('Analyze Fish', false);
        $response->assertSee('Login', false);
    }

    public function test_a_guest_can_analyze_an_image_and_nothing_is_saved(): void
    {
        config(['ai.url' => 'http://fish-model.test']);
        Http::fake([
            'fish-model.test/predict' => Http::response([
                'species' => 'Tilapia',
                'species_confidence' => 0.94,
                'freshness' => 'fresh',
                'freshness_percentage' => 87,
                'freshness_confidence' => 0.87,
            ]),
        ]);

        $response = $this->postJson('/scan/analyze', [
            'image' => UploadedFile::fake()->image('fish.jpg'),
        ]);

        $response->assertOk()->assertJsonPath('source', 'ai');
        $response->assertJsonStructure([
            'name',
            'species_label',
            'species_confidence',
            'freshness',
            'freshness_percentage',
            'freshness_confidence',
            'cls',
            'confidence_band',
            'source',
            'message',
        ]);

        /* Public traffic must never create scan records or notifications. */
        $this->assertDatabaseCount('scans', 0);
        $this->assertDatabaseCount('app_notifications', 0);
    }

    public function test_the_public_analyzer_rejects_non_images(): void
    {
        $response = $this->postJson('/scan/analyze', [
            'image' => UploadedFile::fake()->create('fish.pdf', 10),
        ]);

        $response->assertUnprocessable();
    }

    public function test_the_public_analyzer_reports_unavailable_when_no_model_is_configured(): void
    {
        config([
            'ai.url' => null,
            'roboflow.api_key' => null,
        ]);

        $response = $this->postJson('/scan/analyze', [
            'image' => UploadedFile::fake()->image('fish.jpg'),
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('message', 'AI analysis is currently unavailable. Please try again later.');
    }

    public function test_the_public_analyzer_rejects_non_fish_images(): void
    {
        config(['roboflow.api_key' => 'rf_test_key']);

        Http::fake([
            'serverless.roboflow.com/*' => Http::response([
                'outputs' => [
                    [
                        'predictions' => [
                            'predictions' => [
                                'Other Fishes' => ['confidence' => 0.85, 'class_id' => 5],
                                'Not Fresh' => ['confidence' => 0.70, 'class_id' => 4],
                            ],
                            'predicted_classes' => ['Other Fishes', 'Not Fresh'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/scan/analyze', [
            'image' => UploadedFile::fake()->image('person.jpg'),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['image']);
        $this->assertStringContainsString('No fish detected', $response->json('errors.image.0'));
    }

    public function test_the_public_endpoint_is_throttled_for_anonymous_abuse(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $status = $this
                ->postJson('/scan/analyze', ['image' => null])
                ->assertUnprocessable()
                ->getStatusCode();
            $this->assertSame(422, $status);
        }

        /* The 11th request within the same minute is rate limited. */
        $this->postJson('/scan/analyze', ['image' => null])
            ->assertStatus(429);
    }
}
