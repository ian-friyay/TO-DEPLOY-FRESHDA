<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Roboflow API Key
    |--------------------------------------------------------------------------
    |
    | Found at https://app.roboflow.com/settings/api. Always loaded from
    | environment variable, never hard-coded.
    |
    */
    'api_key' => env('ROBOFLOW_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Workspace and Workflow Slugs
    |--------------------------------------------------------------------------
    |
    | Workspace slug and workflow slug for the serverless deployment endpoint.
    |
    */
    'workspace' => env('ROBOFLOW_WORKSPACE', 'evelyns-workspace-gfs2g'),

    'workflow_id' => env(
        'ROBOFLOW_WORKFLOW_ID',
        'fresh-da-project-5-vit-base-patch16-224-in21k-t1-vfresh-da-project-5-vit-base-patch16-224-in21k-t1-logic'
    ),

    /*
    |--------------------------------------------------------------------------
    | Serverless Base URL
    |--------------------------------------------------------------------------
    |
    | Default endpoint for Roboflow serverless workflow runs.
    |
    */
    'api_url' => env('ROBOFLOW_API_URL', 'https://serverless.roboflow.com'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout and Retry Policy
    |--------------------------------------------------------------------------
    |
    | Network request timeout (seconds), retry count, and retry sleep backoff (ms).
    |
    */
    'timeout' => (int) env('ROBOFLOW_TIMEOUT', 30),

    'retry_times' => (int) env('ROBOFLOW_RETRY_TIMES', 3),

    'retry_sleep_ms' => (int) env('ROBOFLOW_RETRY_SLEEP_MS', 500),

    /*
    |--------------------------------------------------------------------------
    | Minimum Confidence Threshold for Fish Detection
    |--------------------------------------------------------------------------
    |
    | Predictions with species confidence below this threshold or classified
    | as "Other Fishes" are rejected as non-fish images.
    |
    */
    'min_confidence' => (float) env('ROBOFLOW_MIN_CONFIDENCE', 0.60),

];
