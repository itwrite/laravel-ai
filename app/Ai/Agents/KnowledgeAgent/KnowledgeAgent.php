<?php

namespace App\Ai\Agents\KnowledgeAgent;

use App\Ai\Tools\SearchChatHistoryTool;
use App\Ai\Tools\SearchKnowledgeTool;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

#[Timeout(300)]
class KnowledgeAgent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations {
        RemembersConversations::messages as rememberedMessages;
    }

    /**
     * Create a new knowledge agent instance.
     *
     * @param  Message[]  $messages
     */
    public function __construct(protected array $messages = []) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $path = __DIR__.'/instructions.md';

        if (! file_exists($path)) {
            throw new \RuntimeException("Agent instructions file not found: {$path}");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("Agent instructions file is not readable: {$path}");
        }

        return $content;
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * When the agent is bound to a stored conversation (logged-in users), the
     * persisted transcript wins over the client-supplied fallback history.
     *
     * @return Message[]
     */
    public function messages(): iterable
    {
        return $this->currentConversation() !== null
            ? $this->rememberedMessages()
            : $this->messages;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return list<Tool>
     */
    public function tools(): iterable
    {
        return [new SearchKnowledgeTool, new SearchChatHistoryTool];
    }

    /**
     * Get the starter prompts suggested for this agent.
     *
     * @return list<string>
     */
    public static function suggestions(): array
    {
        return [
            '知识库里有哪些关于 Laravel AI 的内容？',
            '帮我查一下项目的常用命令',
            '我之前问过你什么问题？',
            '总结一下历史聊天里关于部署的讨论',
        ];
    }
}
