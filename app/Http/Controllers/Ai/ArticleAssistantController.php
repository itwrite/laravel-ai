<?php

namespace App\Http\Controllers\Ai;

use App\Ai\Agents\ArticleAgent\ArticleAgent;
use App\Ai\Agents\ChatAgent\ChatAgent;
use App\Concerns\ExplainsProviderFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ArticleGenerateRequest;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ArticleAssistantController extends Controller
{
    use ExplainsProviderFailures;

    /**
     * Render the article assistant page.
     */
    public function index(): Response
    {
        return Inertia::render('ArticleAssistant', [
            'aiConfigured' => ChatAgent::configured(),
        ]);
    }

    /**
     * Optimize the submitted material into a standalone responsive HTML
     * article, returned as JSON for in-page preview and download.
     */
    public function generate(ArticleGenerateRequest $request): JsonResponse
    {
        $agent = new ArticleAgent;

        try {
            $response = $agent->prompt(
                $request->promptText(),
                provider: config('ai.chat_providers'),
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => self::providerFailureMessage($e),
            ], 502);
        }

        $html = ArticleAgent::extractHtml($response->text);

        if ($html === '' || ! str_contains($html, '<')) {
            return response()->json([
                'message' => __('AI 未返回有效的文章内容，请稍后重试。'),
            ], 502);
        }

        return response()->json([
            'html' => $html,
            'title' => ArticleAgent::extractTitle($html),
        ]);
    }
}
