<?php

namespace App\Services;

use App\Exceptions\RoboflowWorkflowException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class RoboflowWorkflowClient
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $workspace = null,
        private readonly ?string $workflowId = null,
        private readonly ?string $apiUrl = null,
        private readonly int $timeout = 30,
        private readonly int $retryTimes = 3,
        private readonly int $retrySleepMs = 500,
    ) {}

    /**
     * Factory from Laravel configuration.
     */
    public static function fromConfig(): self
    {
        return new self(
            apiKey: config('roboflow.api_key'),
            workspace: config('roboflow.workspace', 'evelyns-workspace-gfs2g'),
            workflowId: config('roboflow.workflow_id', 'fresh-da-project-5-vit-base-patch16-224-in21k-t1-vfresh-da-project-5-vit-base-patch16-224-in21k-t1-logic'),
            apiUrl: config('roboflow.api_url', 'https://serverless.roboflow.com'),
            timeout: (int) config('roboflow.timeout', 30),
            retryTimes: (int) config('roboflow.retry_times', 3),
            retrySleepMs: (int) config('roboflow.retry_sleep_ms', 500),
        );
    }

    /**
     * Check if client is configured with an API key.
     */
    public function isConfigured(): bool
    {
        return is_string($this->apiKey) && trim($this->apiKey) !== '';
    }

    /**
     * Run the Roboflow Workflow for an image path or HTTPS URL.
     *
     * @param  string  $imageSource  Absolute local file path or HTTPS URL.
     * @param  array<string, mixed>  $parameters  Declared workflow parameters.
     * @return array<string, mixed> Processed outputs dict for the image.
     *
     * @throws RoboflowWorkflowException
     */
    public function runWorkflow(string $imageSource, array $parameters = []): array
    {
        if (! $this->isConfigured()) {
            throw new RoboflowWorkflowException('Roboflow API key is not configured.');
        }

        $endpoint = sprintf(
            '%s/%s/workflows/%s',
            rtrim($this->apiUrl ?: 'https://serverless.roboflow.com', '/'),
            rawurlencode($this->workspace ?: 'evelyns-workspace-gfs2g'),
            rawurlencode($this->workflowId ?: 'fresh-da-project-5-vit-base-patch16-224-in21k-t1-vfresh-da-project-5-vit-base-patch16-224-in21k-t1-logic')
        );

        $imageInput = $this->buildImageInput($imageSource);

        $payload = [
            'inputs' => [
                'image' => $imageInput,
            ],
        ];

        if (! empty($parameters)) {
            $payload['parameters'] = $parameters;
        }

        $response = $this->sendRequestWithRetries($endpoint, $payload);

        return $this->parseResponse($response);
    }

    /**
     * Validate and format the image input according to Roboflow workflow specifications.
     *
     * @return array{type: 'url'|'base64', value: string}
     */
    private function buildImageInput(string $imageSource): array
    {
        if (Str::startsWith($imageSource, ['http://', 'https://'])) {
            if (! Str::startsWith($imageSource, 'https://')) {
                throw new RoboflowWorkflowException(
                    'Invalid image URL: Roboflow requires HTTPS URLs (plain http:// is rejected).'
                );
            }

            return [
                'type' => 'url',
                'value' => $imageSource,
            ];
        }

        if (! is_file($imageSource)) {
            throw new RoboflowWorkflowException("Image file not found: {$imageSource}");
        }

        $fileBytes = file_get_contents($imageSource);
        if ($fileBytes === false) {
            throw new RoboflowWorkflowException("Unable to read image file: {$imageSource}");
        }

        return [
            'type' => 'base64',
            'value' => base64_encode($fileBytes),
        ];
    }

    /**
     * Send HTTP POST request with header-based Bearer auth, timeout, and backoff retries.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RoboflowWorkflowException
     */
    private function sendRequestWithRetries(string $endpoint, array $payload): array
    {
        $attempts = max(1, $this->retryTimes);
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                    ->timeout($this->timeout)
                    ->post($endpoint, $payload);

                if ($response->successful()) {
                    $json = $response->json();
                    if (! is_array($json)) {
                        throw new RoboflowWorkflowException(
                            'Roboflow returned non-JSON response.',
                            $response->status()
                        );
                    }

                    return $json;
                }

                $status = $response->status();
                $body = $response->json() ?? [];
                $errorMsg = is_array($body) && isset($body['message'])
                    ? (string) $body['message']
                    : "Roboflow returned HTTP status {$status}";

                // Do not retry 4xx client errors (e.g. 401 Unauthorized, 404 Not Found, 422 Unprocessable)
                if ($status >= 400 && $status < 500) {
                    throw new RoboflowWorkflowException($errorMsg, $status, is_array($body) ? $body : null);
                }

                $lastException = new RoboflowWorkflowException($errorMsg, $status, is_array($body) ? $body : null);
            } catch (RoboflowWorkflowException $e) {
                if ($e->getStatusCode() >= 400 && $e->getStatusCode() < 500) {
                    throw $e;
                }
                $lastException = $e;
            } catch (Throwable $e) {
                $lastException = new RoboflowWorkflowException(
                    'Network error connecting to Roboflow: '.$e->getMessage(),
                    0,
                    null,
                    $e
                );
            }

            if ($attempt < $attempts) {
                // Exponential backoff with jitter
                $sleepMs = $this->retrySleepMs * (2 ** ($attempt - 1));
                usleep($sleepMs * 1000);
            }
        }

        throw $lastException ?? new RoboflowWorkflowException('Unknown error running Roboflow workflow.');
    }

    /**
     * Parse and sanitize the response outputs.
     * Decodes and offloads base64 images to disk to avoid keeping large blobs in memory.
     *
     * @param  array<string, mixed>  $rawResponse
     * @return array<string, mixed>
     *
     * @throws RoboflowWorkflowException
     */
    private function parseResponse(array $rawResponse): array
    {
        $outputsList = $rawResponse['outputs'] ?? null;
        if (! is_array($outputsList) || empty($outputsList)) {
            throw new RoboflowWorkflowException('Roboflow response missing outputs array.');
        }

        $firstOutput = $outputsList[0];
        if (! is_array($firstOutput)) {
            throw new RoboflowWorkflowException('First output item in Roboflow response is invalid.');
        }

        $processed = [];

        foreach ($firstOutput as $key => $value) {
            // Check if output is an image blob
            if (is_array($value) && ($value['type'] ?? null) === 'base64' && isset($value['value'])) {
                $processed[$key] = $this->persistBase64Image((string) $value['value']);

                continue;
            }

            // Keep segmentation payloads small: strip polygon points if present
            if (is_array($value) && isset($value['predictions']) && is_array($value['predictions'])) {
                $cleaned = $value;
                if (isset($cleaned['predictions']) && is_array($cleaned['predictions'])) {
                    foreach ($cleaned['predictions'] as $predKey => $predVal) {
                        if (is_array($predVal) && isset($predVal['points'])) {
                            unset($cleaned['predictions'][$predKey]['points']);
                        }
                    }
                }
                $processed[$key] = $cleaned;

                continue;
            }

            $processed[$key] = $value;
        }

        return $processed;
    }

    /**
     * Decode base64 image data and store to storage/app/public/workflow_outputs.
     */
    private function persistBase64Image(string $base64Data): string
    {
        $decoded = base64_decode($base64Data, true);
        if ($decoded === false) {
            return '';
        }

        $dir = storage_path('app/public/workflow_outputs');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fileName = 'roboflow_'.Str::random(24).'.png';
        $fullPath = $dir.DIRECTORY_SEPARATOR.$fileName;

        file_put_contents($fullPath, $decoded);

        return $fullPath;
    }
}
