<?php

namespace App\Http\Controllers\Ai;

use App\Ai\Agents\ChatAgent\ChatAgent;
use App\Ai\ChatAgents;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ChatRequest;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    /**
     * Render the standalone chat page with the user's recent conversations.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('ChatAgent', [
            'aiConfigured' => ChatAgent::configured(),
            'agents' => ChatAgents::catalog(),
            'conversations' => $user instanceof User ? $this->recentConversations($user) : [],
            'activeConversation' => $this->activeConversation($request),
        ]);
    }

    /**
     * Stream a chat response for the selected agent, message, and recent history.
     *
     * The stream is driven manually (instead of returning the SDK response
     * directly) so that provider failures mid-stream — connection errors,
     * timeouts, rate limits — are delivered to the client as a final SSE
     * "stream_error" event instead of silently severing the connection.
     *
     * Logged-in users automatically persist each turn into a stored
     * conversation, continuing it when the client passes a conversation id.
     */
    public function stream(ChatRequest $request): StreamedResponse
    {
        $user = $request->user();
        $conversation = $this->requestedConversation($request);

        // Stored conversations carry their own context; ephemeral (guest or
        // brand-new) chats rely on the client-supplied history instead.
        $history = $conversation === null ? $request->history() : [];

        $agent = $request->agentKey()->newAgent($history);

        if ($user instanceof User) {
            $agent = $conversation !== null
                ? $agent->continue($conversation->id, as: $user)
                : $agent->forUser($user);
        }

        $message = $request->string('message')->toString();
        $images = $request->images();
        $providers = config('ai.chat_providers');

        return response()->stream(function () use ($agent, $message, $images, $providers): void {
            // Streaming responses legitimately outlive any PHP execution
            // timer (long generations can run for minutes); the provider
            // connection still has its own HTTP timeout as a backstop.
            set_time_limit(0);

            try {
                $stream = $agent->stream($message, $images, provider: $providers);

                foreach ($stream as $event) {
                    echo 'data: '.$event."\n\n";

                    flush();

                    if (connection_aborted() !== 0) {
                        return;
                    }
                }

                // Tell the client which stored conversation the turn landed
                // in, so it can continue the thread and refresh its history.
                if ($stream->conversationId !== null) {
                    echo 'data: '.json_encode([
                        'type' => 'conversation',
                        'id' => $stream->conversationId,
                    ])."\n\n";

                    flush();
                }
            } catch (Throwable $e) {
                report($e);

                echo 'data: '.json_encode([
                    'type' => 'stream_error',
                    'message' => self::streamErrorMessage($e),
                ])."\n\n";

                flush();
            }

            echo "data: [DONE]\n\n";

            flush();
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * List the user's most recent conversations for the history drawer.
     *
     * @return list<array{id: string, title: string, agent: string|null, updatedAt: string|null}>
     */
    private function recentConversations(User $user): array
    {
        $conversations = Conversation::query()
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->getKey())
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'title', 'updated_at']);

        // Resolve each conversation's agent from its first stored message in
        // a single query, instead of one query per conversation. The SDK's
        // conversation models rely on magic attributes, hence getAttribute().
        $agentClasses = ConversationMessage::query()
            ->whereIn('conversation_id', $conversations->pluck('id'))
            ->orderBy('id')
            ->get(['conversation_id', 'agent'])
            ->unique('conversation_id')
            ->mapWithKeys(fn (ConversationMessage $message): array => [
                (string) $message->getAttribute('conversation_id') => (string) $message->getAttribute('agent'),
            ]);

        return array_values($conversations
            ->map(fn (Conversation $conversation): array => [
                'id' => (string) $conversation->getAttribute('id'),
                'title' => (string) $conversation->getAttribute('title'),
                'agent' => ChatAgents::fromAgentClass($agentClasses[(string) $conversation->getAttribute('id')] ?? '')?->value,
                'updatedAt' => self::formatTimestamp($conversation->getAttribute('updated_at')),
            ])
            ->all());
    }

    /**
     * Load the conversation requested via ?conversation=..., enforcing ownership.
     *
     * @return array{id: string, title: string, agent: string|null, messages: list<array{role: string, content: string}>}|null
     */
    private function activeConversation(Request $request): ?array
    {
        $user = $request->user();

        $id = $request->query('conversation');

        if (! $user instanceof User || ! is_string($id) || $id === '') {
            return null;
        }

        $conversation = $this->ownedConversation($id, $user);

        $messages = array_values($conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id')
            ->get(['role', 'content'])
            ->map(fn (ConversationMessage $message): array => [
                'role' => (string) $message->getAttribute('role'),
                'content' => (string) $message->getAttribute('content'),
            ])
            ->all());

        $agentClass = (string) $conversation->messages()
            ->orderBy('id')
            ->value('agent');

        return [
            'id' => (string) $conversation->getAttribute('id'),
            'title' => (string) $conversation->getAttribute('title'),
            'agent' => ChatAgents::fromAgentClass($agentClass)?->value,
            'messages' => $messages,
        ];
    }

    /**
     * Format a model timestamp as ISO-8601 for the frontend.
     */
    private static function formatTimestamp(mixed $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return Carbon::parse((string) $timestamp)->toIso8601String();
    }

    /**
     * Resolve the conversation the stream request wants to continue, or null
     * for a brand-new (or guest) chat. Foreign conversations fail with a 404.
     */
    private function requestedConversation(ChatRequest $request): ?Conversation
    {
        $id = $request->validated('conversation');
        $user = $request->user();

        if (! is_string($id) || ! $user instanceof User) {
            return null;
        }

        return $this->ownedConversation($id, $user);
    }

    /**
     * Fetch a conversation owned by the given user or fail with a 404.
     */
    private function ownedConversation(string $id, User $user): Conversation
    {
        return Conversation::query()
            ->where('id', $id)
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->getKey())
            ->firstOrFail();
    }

    /**
     * Build the user-facing message for a failed stream, appending the
     * provider's own error detail when available. SDK exceptions such as
     * RateLimitedException repackage the HTTP failure and only say "rate
     * limited", while the chained RequestException still carries the
     * provider's response body (e.g. zhipu's 1113 "insufficient credits"
     * is also sent as HTTP 429).
     */
    private static function streamErrorMessage(Throwable $e): string
    {
        $message = $e->getMessage();

        $root = $e;

        while ($root->getPrevious() instanceof Throwable) {
            $root = $root->getPrevious();

            if ($root instanceof RequestException) {
                break;
            }
        }

        if ($root instanceof RequestException) {
            $detail = $root->response->json('error.message') ?? $root->response->body();

            if (is_string($detail) && $detail !== '') {
                $message .= ' — '.mb_substr($detail, 0, 300);
            }
        }

        return $message;
    }
}
