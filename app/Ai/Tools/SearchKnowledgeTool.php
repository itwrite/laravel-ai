<?php

namespace App\Ai\Tools;

use App\Models\KnowledgeEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Keyword search over the application's knowledge base, used by the
 * knowledge agent before (or instead of) falling back to chat history.
 */
class SearchKnowledgeTool implements Tool
{
    /**
     * The maximum number of entries returned per search.
     */
    protected int $limit = 5;

    /**
     * Get the tool name exposed to the model.
     */
    public function name(): string
    {
        return 'search_knowledge';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return '在应用知识库中按关键词检索条目，返回标题、内容摘要与关键词。回答事实性、资料性问题时应先调用本工具。';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:200'],
        ]);

        $entries = $this->matchedEntries((string) $validated['query']);

        if ($entries->isEmpty()) {
            return '知识库中没有匹配的内容。';
        }

        return "知识库匹配到 {$entries->count()} 条内容：\n\n".$entries
            ->map(fn (KnowledgeEntry $entry): string => sprintf(
                "【%s】\n%s\n关键词：%s",
                $entry->title,
                Str::limit($entry->content, 300),
                $entry->keywords ?? '（无）',
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
     * Search the knowledge base: every whitespace-separated term must match
     * the title, content, or keywords column.
     *
     * @return Collection<int, KnowledgeEntry>
     */
    protected function matchedEntries(string $query)
    {
        $patterns = $this->termPatterns($query);

        $builder = KnowledgeEntry::query();

        foreach ($patterns as $pattern) {
            $builder->where(function (Builder $termQuery) use ($pattern): void {
                $termQuery->where('title', 'like', $pattern)
                    ->orWhere('content', 'like', $pattern)
                    ->orWhere('keywords', 'like', $pattern);
            });
        }

        return $builder
            ->orderByDesc('updated_at')
            ->limit($this->limit)
            ->get();
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
