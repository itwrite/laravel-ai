<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\PollinationsImageRequest;
use App\Services\ImageGenerationService;
use Illuminate\Http\JsonResponse;
use Throwable;

class PollinationsImageController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(protected ImageGenerationService $images) {}

    /**
     * Generate an image from the validated prompt and return its public URL.
     */
    public function generate(PollinationsImageRequest $request): JsonResponse
    {
        try {
            $url = $this->images->generate(
                $request->string('prompt')->trim()->toString(),
                $request->integer('width', 1024),
                $request->integer('height', 1024),
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'url' => $url,
        ]);
    }
}
