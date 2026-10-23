<?php

namespace App\Http\Controllers\Ai;

use App\Concerns\ExplainsProviderFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\TattooGenerateRequest;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Image;
use Throwable;

class TattooAssistantController extends Controller
{
    use ExplainsProviderFailures;

    /**
     * Render the tattoo line art assistant page.
     */
    public function index(): Response
    {
        return Inertia::render('TattooAssistant', [
            'aiConfigured' => self::imageProviderConfigured(),
        ]);
    }

    /**
     * Generate a stencil-ready tattoo line art with the Gemini image model,
     * returned inline as a base64 data URL for in-page preview and download.
     */
    public function generate(TattooGenerateRequest $request): JsonResponse
    {
        $pending = Image::of($request->imagePrompt())
            ->timeout(300);

        if ($request->size() !== null) {
            $pending->size($request->size());
        }

        if ($request->quality() !== null) {
            $pending->quality($request->quality());
        }

        $reference = $request->referenceImage();

        if ($reference !== null) {
            $pending->attachments([$reference]);
        }

        // Pollinations cannot honor reference-image attachments, so keep it
        // out of the chain when one is uploaded.
        $providers = $reference === null
            ? config('ai.image_providers')
            : array_values(array_diff((array) config('ai.image_providers'), ['pollinations']));

        try {
            $response = $pending->generate(provider: $providers);
        } catch (Throwable $e) {
            report($e);

            $message = self::providerFailureMessage($e);

            if ($e instanceof RateLimitedException || $e instanceof InsufficientCreditsException) {
                $message .= ' —— 所有图片供应商均被限流或额度用尽：Gemini 免费层图片配额很小（按分钟/每日重置），可稍后重试、到 https://ai.dev/rate-limit 查看用量，或升级付费计划。';
            }

            return response()->json([
                'message' => $message,
            ], 502);
        }

        if ($response->images->isEmpty()) {
            return response()->json([
                'message' => __('AI 未返回图片，请调整描述后重试。'),
            ], 502);
        }

        $image = $response->firstImage();

        return response()->json([
            'image' => 'data:'.$image->mime().';base64,'.$image->image,
            'mime' => $image->mime(),
        ]);
    }

    /**
     * Determine whether any provider in the image failover chain is
     * configured with an API key.
     */
    protected static function imageProviderConfigured(): bool
    {
        foreach ((array) config('ai.image_providers') as $name) {
            $provider = (array) config("ai.providers.{$name}", []);

            if (filled($provider['key'] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
