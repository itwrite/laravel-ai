<?php

namespace App\Http\Controllers\Ai;

use App\Ai\Agents\ImageEnhanceAgent\ImageEnhanceAgent;
use App\Concerns\ExplainsProviderFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ImageEnhanceRequest;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Image;
use Throwable;

class ImageEnhancerController extends Controller
{
    use ExplainsProviderFailures;

    /**
     * Render the image ultra-high-definition assistant page.
     */
    public function index(): Response
    {
        return Inertia::render('ImageEnhancer', [
            'aiConfigured' => self::imageProviderConfigured(),
        ]);
    }

    /**
     * Re-render the uploaded image as an ultra-high-resolution version of
     * itself, returned inline as a base64 data URL for in-page preview and
     * download. The ImageEnhanceAgent contributes the fixed enhancement
     * instructions, the request contributes the per-call requirements.
     */
    public function generate(ImageEnhanceRequest $request): JsonResponse
    {
        $agent = new ImageEnhanceAgent;

        $pending = Image::of($agent->imagePrompt($request->enhancePrompt()))
            ->quality($request->quality())
            ->attachments([$request->sourceImage()])
            ->timeout(300);

        // The source image is mandatory here, and Pollinations cannot honor
        // reference-image attachments, so it never joins the chain.
        $providers = array_values(array_diff(
            (array) config('ai.image_providers'),
            ['pollinations'],
        ));

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
                'message' => __('AI 未返回图片，请稍后重试。'),
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
