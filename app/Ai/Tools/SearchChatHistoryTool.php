<?php

namespace App\Ai\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Tools\Request;

/**
 * Keyword search over the current user's own stored chat conversations,
 * used by the knowledge agent when the knowledge base has no answer or
 * when the user asks about past conversations.
 */
class SearchChatHistoryTool implements Tool
{
    /**
     * The maximum number of messages returned per search.
     */
    protected int $limit = 5;

    /**
     * Get the tool name exposed to the model.
     */
    public function name(): string
    {
        return 'search_chat_history';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return '在当前登录用户自己的历史聊天记录中按关键词检索过往对话内容。当用户询问"我之前聊过/问过什么"，或知识库检索无结果时调用。';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return '当前用户未登录，无法查询历史聊天记录。';
        }

        $validated = $request->validate([
            'query' => ['required', 'string', 'max:200'],
        ]);

        $titles = Conversation::query()
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->getKey())
            ->pluck('title', 'id');

        if ($titles->isEmpty()) {
            return '当前用户没有历史聊天记录。';
        }

        $messages = $this->matchedMessages((string) $validated['query'], $titles->keys());

        if ($messages->isEmpty()) {
            return '历史聊天记录中没有匹配的内容。';
        }

        return "历史聊天中匹配到 {$messages->count()} 条内容：\n\n".$messages
            ->map(fn (ConversationMessage $message): string => sprintf(
                '【%s · %s】%s',
                (string) ($titles[$message->getAttribute('conversation_id')] ?? '未命名对话'),
                $message->getAttribute('role') === 'user' ? '用户' : '助手',
                Str::limit((string) $message->getAttribute('content'), 200),
            ))
            ->implode("\n\n");
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('检索关键词，可用空格分隔多个词（需全部命中）')
                ->required(),
        ];
    }

    /**
     * Search the user's transcript messages: every whitespace-separated term
     * must appear in the message content.
     *
     * @param  Collection<int, string>  $conversationIds
     * @return Collection<int, ConversationMessage>
     */
    protected function matchedMessages(string $query, Collection $conversationIds)
    {
        $builder = ConversationMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->whereIn('role', ['user', 'assistant']);

        foreach ($this->termPatterns($query) as $pattern) {
            $builder->where(function (Builder $termQuery) use ($pattern): void {
                $termQuery->where('content', 'like', $pattern);
            });
        }

        return $builder
            ->orderByDesc('created_at')
            ->limit($this->limit)
            ->get(['conversation_id', 'role', 'content']);
    }

    /**
     * Split a raw query string into escaped LIKE patterns (max 4 terms).
     *
     * @return array<int, string>
     */
    protected function termPatterns(string $query): array
    {
        $terms = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($terms)
            ->take(4)
            ->map(fn (string $term): string => '%'.addcslashes($term, '%_\\').'%')
            ->values()
            ->all();
    }
}
