<?php

namespace App\Http\Controllers;

use App\Services\ScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function __construct(private ScanService $scans) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $result = $this->scans->store($request->user(), $request->file('image'));

        if ($result === null) {
            return response()->json([
                'message' => 'AI analysis is currently unavailable. Please try again later.',
            ], 503);
        }

        return response()->json($result);
    }
}
