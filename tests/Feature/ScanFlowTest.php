<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Scan;
use App\Models\User;
use App\Services\FishAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScanFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_the_live_camera_controls(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('id="cameraPreview"', false);
        $response->assertSee('id="cameraVideo"', false);
        $response->assertSee('id="cameraSnapshot"', false);
        $response->assertSee('id="cameraCanvas"', false);
        $response->assertSee('id="openCameraBtn"', false);
        $response->assertSee('id="captureBtn"', false);
        $response->assertSee('id="switchCameraBtn"', false);
        $response->assertSee('id="closeCameraBtn"', false);
        $response->assertSee('js/dashboard/scan.js', false);
    }

    public function test_dashboard_script_drives_the_camera_with_get_user_media(): void
    {
        $script = $this->dashboardScript();

        $this->assertStringContainsString('getUserMedia', $script);
        $this->assertStringContainsString('capturePhoto', $script);
        $this->assertStringContainsString('stopCameraStream', $script);
        $this->assertStringContainsString('stopCameraStream();', $script);
        $this->assertStringContainsString('state.capturedFile', $script);
    }

    public function test_dashboard_scan_does_not_treat_the_browser_online_flag_as_connectivity(): void
    {
        $script = $this->dashboardScript();

        $this->assertStringNotContainsString('navigator.onLine', $script);
        $this->assertStringContainsString('Could not reach the scan server.', $script);
    }

    public function test_dashboard_ai_status_reflects_server_configuration(): void
    {
        config([
            'roboflow.api_key' => 'rf_test_key',
            'ai.url' => null,
        ]);

        $html = (string) $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Roboflow workflow configured', $html);
        $this->assertStringContainsString('> Configured</span>', $html);
        $this->assertStringNotContainsString('rf_test_key', $html);
        $this->assertStringNotContainsString('offline analysis', $html);
    }

    /**
     * Every element the dashboard script looks up must exist in the rendered page,
     * otherwise the handler silently breaks with a null reference.
     */
    public function test_every_element_looked_up_by_the_dashboard_script_is_rendered(): void
    {
        $user = User::factory()->vendor()->create();
        $html = (string) $this->actingAs($user)->get('/dashboard')->getContent();
        $script = $this->dashboardScript();

        preg_match_all('/getElementById\("([^"]+)"\)/', $script, $matches);

        $ids = array_unique($matches[1]);

        /* The script builds these elements itself, so they are absent from the markup. */
        preg_match_all('/(?<![-\w])id="([^"]+)"|\bid\s*=\s*"([^"]+)"/', $script, $declared);
        $builtByScript = array_values(array_filter(array_merge($declared[1], $declared[2])));

        /* Optional hooks: the script checks for null before using them. */
        $optional = [
            'mobileNotifBadge',
            'viewContactField',
            'viewContact',
            'viewAddressField',
            'viewAddress',
        ];

        $missing = array_values(array_filter(
            $ids,
            fn (string $id): bool => ! in_array($id, $builtByScript, true)
                && ! in_array($id, $optional, true)
                && ! str_contains($html, 'id="'.$id.'"')
        ));

        $this->assertSame([], $missing, 'The dashboard script looks up missing elements: '.implode(', ', $missing));
    }

    public function test_a_customer_dashboard_renders_no_vendor_only_sections(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $html = (string) $this->actingAs($user)->get('/dashboard')->getContent();

        /* Inventory, suppliers, deliveries and reports are vendor tools. */
        foreach (['inventory', 'suppliers', 'deliveries', 'reports'] as $section) {
            $this->assertStringNotContainsString('id="'.$section.'-section"', $html);
        }
    }

    /** 9.21 / D8 — history rows open a detail card and can be re-ordered. */
    public function test_the_history_section_renders_the_sort_control_and_detail_fields(): void
    {
        $user = User::factory()->vendor()->create();
        Scan::factory()->create(['user_id' => $user->id]);

        $html = (string) $this->actingAs($user)->get('/dashboard')->getContent();

        $this->assertStringContainsString('id="historySort"', $html);
        $this->assertStringContainsString('data-ts="', $html);
        $this->assertStringContainsString('data-source="', $html);
        $this->assertStringContainsString('data-confidence-band="', $html);

        $script = $this->dashboardScript();

        $this->assertStringContainsString('getElementById("historySort")', $script);
        $this->assertStringContainsString('function openScanDetail(row)', $script);
    }

    public function test_camera_snapshot_is_not_saved_when_ai_service_is_unavailable(): void
    {
        Storage::fake('public');
        config([
            'ai.url' => null,
            'roboflow.api_key' => null,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/scans', [
            'image' => UploadedFile::fake()->image('capture.jpg', 640, 480),
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('message', 'AI analysis is currently unavailable. Please try again later.');

        $this->assertDatabaseCount('scans', 0);
        $this->assertSame([], Storage::disk('public')->files('scans'));
    }

    public function test_scan_without_an_image_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/scans', []);

        $response->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('scans', 0);
    }

    public function test_scan_rejects_a_file_that_is_not_an_image(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/scans', [
            'image' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertDatabaseCount('scans', 0);
    }

    public function test_an_unavailable_scan_leaves_inventory_untouched(): void
    {
        Storage::fake('public');
        config([
            'ai.url' => null,
            'roboflow.api_key' => null,
        ]);

        $vendor = User::factory()->vendor()->create();

        $items = collect(FishAiService::CANDIDATES)
            ->map(fn (array $candidate): InventoryItem => InventoryItem::factory()->create([
                'user_id' => $vendor->id,
                'name' => $candidate[0],
                'freshness' => 'fresh',
                'freshness_percentage' => 95,
                'last_scanned_at' => now()->subDay(),
            ]));

        $response = $this->actingAs($vendor)->postJson('/scans', [
            'image' => UploadedFile::fake()->image('fish.jpg'),
        ]);

        $response->assertStatus(503);
        $this->assertDatabaseCount('scans', 0);

        foreach ($items as $item) {
            $fresh = $item->fresh();
            $this->assertSame('fresh', $fresh->freshness);
            $this->assertSame(95, $fresh->freshness_percentage);
            $this->assertTrue($fresh->last_scanned_at->lt(now()->subHour()));
        }
    }

    /** The real model result still refreshes the matching item only. */
    public function test_an_ai_scan_refreshes_the_matching_inventory_item(): void
    {
        Storage::fake('public');
        $vendor = User::factory()->vendor()->create();

        /* Stand in a connected model service so the "ai" path is exercised. */
        $this->app->bind(FishAiService::class, fn () => new class
        {
            /** @return array<string, mixed> */
            public function predict(?string $path): array
            {
                return [
                    'species' => 'Milkfish',
                    'species_confidence' => 0.91,
                    'freshness' => 'moderate',
                    'freshness_percentage' => 66,
                    'freshness_confidence' => 0.8,
                    'source' => 'ai',
                ];
            }
        });

        $target = InventoryItem::factory()->create([
            'user_id' => $vendor->id,
            'name' => 'Milkfish',
            'freshness' => 'fresh',
            'freshness_percentage' => 95,
            'last_scanned_at' => now()->subDay(),
        ]);
        $other = InventoryItem::factory()->create([
            'user_id' => $vendor->id,
            'name' => 'Tuna',
            'freshness' => 'fresh',
            'freshness_percentage' => 95,
            'last_scanned_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($vendor)->postJson('/scans', [
            'image' => UploadedFile::fake()->image('fish.jpg'),
        ]);

        $response->assertOk()->assertJsonPath('source', 'ai');

        $updated = $target->fresh();
        $this->assertSame('moderate', $updated->freshness);
        $this->assertSame(66, $updated->freshness_percentage);
        $this->assertTrue($updated->last_scanned_at->greaterThan(now()->subMinute()));

        $stale = $other->fresh();
        $this->assertSame('fresh', $stale->freshness);
        $this->assertTrue($stale->last_scanned_at->lt(now()->subHour()));
    }

    public function test_guests_cannot_submit_scans(): void
    {
        $response = $this->postJson('/scans', []);

        $response->assertUnauthorized();
        $this->assertDatabaseCount('scans', 0);
    }

    /**
     * The band shown in history uses the shared 0.80/0.60 thresholds, so a
     * 0.93 species confidence must read "High" next to the score.
     */
    public function test_history_renders_the_confidence_band_next_to_the_score(): void
    {
        $user = User::factory()->create();

        $user->scans()->create([
            'image_path' => null,
            'species' => 'Tilapia',
            'species_confidence' => 0.93,
            'freshness' => 'fresh',
            'freshness_percentage' => 94,
            'freshness_confidence' => 0.94,
            'source' => 'single',
        ]);

        $html = (string) $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('0.93 · High', $html);
    }

    public function test_authenticated_scanner_rejects_non_fish_images_without_saving(): void
    {
        Storage::fake('public');
        config(['roboflow.api_key' => 'rf_test_key']);

        Http::fake([
            'serverless.roboflow.com/*' => Http::response([
                'outputs' => [
                    [
                        'predictions' => [
                            'predictions' => [
                                'Other Fishes' => ['confidence' => 0.88, 'class_id' => 5],
                                'Not Fresh' => ['confidence' => 0.65, 'class_id' => 4],
                            ],
                            'predicted_classes' => ['Other Fishes', 'Not Fresh'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->vendor()->create();

        $response = $this->actingAs($user)->postJson('/scans', [
            'image' => UploadedFile::fake()->image('person_face.jpg', 640, 480),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['image']);
        $this->assertStringContainsString('No fish detected', $response->json('errors.image.0'));

        // Assert nothing is saved to database or storage
        $this->assertDatabaseCount('scans', 0);
        $this->assertDatabaseCount('app_notifications', 0);
        $this->assertSame(0, count(Storage::disk('public')->files('scans')));
    }
}
