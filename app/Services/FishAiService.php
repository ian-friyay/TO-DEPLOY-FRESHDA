<?php

namespace App\Services;

use App\Exceptions\RoboflowWorkflowException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Species + freshness recognition for a scan.
 *
 * Scans can be processed via either:
 * 1. Roboflow Serverless Workflow ("fresh-da-project-5-vit-base-patch16-224-in21k-t1 vfresh-da-project-5-vit-base-patch16-224-in21k-t1 Logic")
 *    when ROBOFLOW_API_KEY is configured in config/roboflow.php.
 * 2. Self-hosted FastAPI YOLOv8 service when AI_SERVICE_URL is configured in config/ai.php.
 *
 * If no service is configured or if an error / contract breach occurs,
 * predict() degrades gracefully to deterministic simulated results so
 * scans never fail.
 */
class FishAiService
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    public const CANDIDATES = [
        ['Milkfish', 'Chanos chanos'],
        ['Tilapia', 'Oreochromis niloticus'],
        ['Salmon', 'Salmo salar'],
        ['Mackerel', 'Scomber scombrus'],
        ['Bangus', 'Chanos chanos'],
        ['Tuna', 'Thunnus albacares'],
        ['Snapper', 'Lutjanus spp.'],
        ['Catfish', 'Pangasius pangasius'],
        ['Galonggong', 'Decapterus macarellus'],
        ['Dalagang Bukid', 'Caesio cuning'],
        ['Tulingan', 'Auxis thazard'],
        ['Other Fishes', 'Pisces spp.'],
    ];

    /**
     * The only verdicts the app understands; a model answer naming anything
     * else is a contract breach and degrades to a simulated result.
     *
     * @var array<int, string>
     */
    private const FRESHNESS_STATES = ['fresh', 'moderate', 'not_fresh'];

    /**
     * Produce the result for a scan: the Roboflow or FastAPI model's answer
     * when connected, a deterministic simulated one otherwise.
     *
     * @return array{species: string, species_label: string, species_confidence: float, freshness: string, freshness_percentage: int, freshness_confidence: float, source: string}
     */
    public function predict(?string $imagePath = null): array
    {
        if ($imagePath === null || ! is_file($imagePath)) {
            return self::simulated();
        }

        // 1. Try Roboflow Workflow if configured
        $roboflow = app(RoboflowWorkflowClient::class);
        if ($roboflow->isConfigured()) {
            $roboflowResult = $this->askRoboflow($roboflow, $imagePath);
            if ($roboflowResult !== null) {
                return $roboflowResult;
            }
        }

        // 2. Fall back to legacy FastAPI YOLO service if configured
        $baseUrl = config('ai.url');
        if (is_string($baseUrl) && $baseUrl !== '') {
            $modelResult = $this->askModel(rtrim($baseUrl, '/'), $imagePath);
            if ($modelResult !== null) {
                return $modelResult;
            }
        }

        // 3. Fallback to deterministic simulated result
        return self::simulated();
    }

    /**
     * Confidence bands the UI displays next to a scan: high ≥ 0.80,
     * moderate ≥ 0.60, low below that.
     */
    public static function confidenceBand(float $confidence): string
    {
        $confidence = self::clamp01($confidence);

        return match (true) {
            $confidence >= 0.80 => 'high',
            $confidence >= 0.60 => 'moderate',
            default => 'low',
        };
    }

    /**
     * Build a deterministic-looking offline result for a scan.
     *
     * @return array{species: string, species_label: string, species_confidence: float, freshness: string, freshness_percentage: int, freshness_confidence: float, source: string}
     */
    public static function simulated(): array
    {
        $classes = [
            ['fresh', 80, 98],
            ['fresh', 80, 98],
            ['moderate', 60, 79],
            ['not_fresh', 20, 55],
        ];

        [$species, $label] = self::CANDIDATES[array_rand(self::CANDIDATES)];
        [$freshness, $min, $max] = $classes[array_rand($classes)];
        $percentage = random_int($min, $max);

        return [
            'is_fish' => true,
            'species' => $species,
            'species_label' => $label,
            'species_confidence' => round(random_int(8500, 9900) / 10000, 2),
            'freshness' => $freshness,
            'freshness_percentage' => $percentage,
            'freshness_confidence' => round($percentage / 100, 2),
            'source' => 'simulated',
            'message' => 'Analysis Complete — AI Service Offline, Simulated Result Shown.',
        ];
    }

    public static function latinFor(string $species): string
    {
        foreach (self::CANDIDATES as $candidate) {
            if (str_contains(strtolower($species), strtolower($candidate[0]))) {
                return $candidate[1];
            }
        }

        return $species;
    }

    /**
     * Normalize model species class names (e.g. "species_galunggong" -> "Galonggong").
     */
    public static function normalizeSpeciesName(string $name): string
    {
        $clean = trim((string) preg_replace('/^species_/i', '', $name));
        $clean = str_replace('_', ' ', $clean);

        $lookup = [
            'bangus' => 'Bangus',
            'galonggong' => 'Galonggong',
            'galunggong' => 'Galonggong',
            'dalagang bukid' => 'Dalagang Bukid',
            'tilapia' => 'Tilapia',
            'tulingan' => 'Tulingan',
            'other fishes' => 'Other Fishes',
            'other fish' => 'Other Fishes',
            'milkfish' => 'Milkfish',
            'salmon' => 'Salmon',
            'mackerel' => 'Mackerel',
            'tuna' => 'Tuna',
            'snapper' => 'Snapper',
            'catfish' => 'Catfish',
        ];

        $lower = strtolower($clean);

        return $lookup[$lower] ?? ucwords($clean);
    }

    /**
     * Execute inference via Roboflow Serverless Workflow client.
     *
     * @return array{species: string, species_label: string, species_confidence: float, freshness: string, freshness_percentage: int, freshness_confidence: float, source: string}|null
     */
    private function askRoboflow(RoboflowWorkflowClient $client, string $imagePath): ?array
    {
        try {
            $outputs = $client->runWorkflow($imagePath);

            return self::fromRoboflowOutputs($outputs);
        } catch (RoboflowWorkflowException $e) {
            Log::warning('Roboflow workflow execution failed: '.$e->getMessage(), [
                'status' => $e->getStatusCode(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('Unexpected error executing Roboflow workflow: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Map Roboflow workflow output schema into the scan contract.
     *
     * @param  array<string, mixed>  $outputs
     * @return array{species: string, species_label: string, species_confidence: float, freshness: string, freshness_percentage: int, freshness_confidence: float, source: string}|null
     */
    public static function fromRoboflowOutputs(array $outputs): ?array
    {
        // Predictions block from JsonField output 'predictions'
        $predictionsBlock = $outputs['predictions'] ?? null;
        if (! is_array($predictionsBlock)) {
            return null;
        }

        // Extract class dictionary
        $classesDict = $predictionsBlock['predictions'] ?? null;
        if (! is_array($classesDict) || empty($classesDict)) {
            return null;
        }

        $freshnessMap = [
            'fresh' => 'fresh',
            'slightly fresh' => 'moderate',
            'moderate' => 'moderate',
            'not fresh' => 'not_fresh',
            'not_fresh' => 'not_fresh',
            'freshness_fresh' => 'fresh',
            'freshness_moderate' => 'moderate',
            'freshness_slightly_fresh' => 'moderate',
            'freshness_not_fresh' => 'not_fresh',
        ];

        $topSpecies = null;
        $topSpeciesConf = -1.0;

        $topFreshness = null;
        $topFreshnessConf = -1.0;

        foreach ($classesDict as $className => $data) {
            $confidence = is_array($data) ? (float) ($data['confidence'] ?? 0.0) : (float) $data;
            $normalizedName = strtolower(trim((string) $className));

            if (isset($freshnessMap[$normalizedName])) {
                if ($confidence > $topFreshnessConf) {
                    $topFreshnessConf = $confidence;
                    $topFreshness = $freshnessMap[$normalizedName];
                }
            } else {
                if ($confidence > $topSpeciesConf) {
                    $topSpeciesConf = $confidence;
                    $topSpecies = self::normalizeSpeciesName((string) $className);
                }
            }
        }

        // If no species class was found in dict, check predicted_classes array
        if ($topSpecies === null && isset($predictionsBlock['predicted_classes']) && is_array($predictionsBlock['predicted_classes'])) {
            foreach ($predictionsBlock['predicted_classes'] as $predClass) {
                $norm = strtolower(trim((string) $predClass));
                if (! isset($freshnessMap[$norm])) {
                    $topSpecies = self::normalizeSpeciesName((string) $predClass);
                    $topSpeciesConf = 0.90;
                    break;
                }
            }
        }

        if ($topSpecies === null || $topSpecies === '') {
            $topSpecies = 'Other Fishes';
            $topSpeciesConf = 0.50;
        }

        if ($topFreshness === null) {
            $topFreshness = 'fresh';
            $topFreshnessConf = 0.85;
        }

        $percentage = (int) round(self::clamp01($topFreshnessConf) * 100);

        $minConfidence = (float) config('roboflow.min_confidence', 0.60);
        $invalidSpecies = ['other fishes', 'other fish', 'unknown', 'none'];

        $isFish = true;
        $rejectReason = null;

        if ($topSpecies === null || in_array(strtolower($topSpecies), $invalidSpecies, true)) {
            $isFish = false;
            $rejectReason = 'No fish detected. Please position the fish clearly in frame and scan again.';
        } elseif ($topSpeciesConf < $minConfidence) {
            $isFish = false;
            $rejectReason = 'Unclear fish detection. Please ensure good lighting, hold the camera steady, and scan again.';
        }

        return [
            'is_fish' => $isFish,
            'species' => $topSpecies ?? 'Other Fishes',
            'species_label' => self::latinFor($topSpecies ?? 'Other Fishes'),
            'species_confidence' => self::clamp01($topSpeciesConf),
            'freshness' => $topFreshness,
            'freshness_percentage' => max(0, min(100, $percentage)),
            'freshness_confidence' => self::clamp01($topFreshnessConf),
            'source' => 'ai',
            'message' => $isFish
                ? 'Analysis Complete — AI Model Result.'
                : ($rejectReason ?? 'No fish detected. Please position the fish clearly in frame and scan again.'),
        ];
    }

    /**
     * Ask the FastAPI model service for one prediction.
     *
     * @return array{species: string, species_label: string, species_confidence: float, freshness: string, freshness_percentage: int, freshness_confidence: float, source: string}|null
     */
    private function askModel(string $baseUrl, string $imagePath): ?array
    {
        try {
            $response = Http::timeout((int) config('ai.timeout', 15))
                ->attach('image', $imagePath, basename($imagePath))
                ->post($baseUrl.'/predict');
        } catch (\Throwable $exception) {
            Log::warning('Fish AI service unreachable: '.$exception->getMessage());

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Fish AI service answered with status '.$response->status().'.');

            return null;
        }

        $payload = $response->json();

        return self::fromPayload(is_array($payload) ? $payload : []);
    }

    /**
     * Validate the model's JSON against the contract in config/ai.php.
     *
     * @param  array<string, mixed>  $payload
     * @return array{species: string, species_label: string, species_confidence: float, freshness: string, freshness_percentage: int, freshness_confidence: float, source: string}|null
     */
    private static function fromPayload(array $payload): ?array
    {
        $species = trim((string) ($payload['species'] ?? ''));
        $freshness = (string) ($payload['freshness'] ?? '');
        $percentage = filter_var($payload['freshness_percentage'] ?? null, FILTER_VALIDATE_INT);
        $speciesConfidence = $payload['species_confidence'] ?? null;

        if ($species === ''
            || ! in_array($freshness, self::FRESHNESS_STATES, true)
            || $percentage === false
            || $percentage < 0
            || $percentage > 100
            || ! is_numeric($speciesConfidence)) {
            return null;
        }

        $label = trim((string) ($payload['species_label'] ?? ''));
        $freshnessConfidence = $payload['freshness_confidence'] ?? null;

        return [
            'is_fish' => true,
            'species' => $species,
            'species_label' => $label === '' ? self::latinFor($species) : $label,
            'species_confidence' => self::clamp01((float) $speciesConfidence),
            'freshness' => $freshness,
            'freshness_percentage' => $percentage,
            'freshness_confidence' => is_numeric($freshnessConfidence)
                ? self::clamp01((float) $freshnessConfidence)
                : round($percentage / 100, 2),
            'source' => 'ai',
            'message' => 'Analysis Complete — AI Model Result.',
        ];
    }

    /**
     * Confidences are fractions, so a percent-scale payload must not be
     * stored or displayed as e.g. "90.00".
     */
    private static function clamp01(float $value): float
    {
        return round(max(0.0, min(1.0, $value > 1.0 ? $value / 100 : $value)), 2);
    }
}
