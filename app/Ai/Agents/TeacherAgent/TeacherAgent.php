<?php

namespace App\Ai\Agents\TeacherAgent;

use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

#[Timeout(300)]
class TeacherAgent implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations {
        RemembersConversations::messages as rememberedMessages;
    }

    /**
     * Create a new teacher agent instance.
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
     * Get the starter prompts suggested for this agent.
     *
     * @return list<string>
     */
    public static function suggestions(): array
    {
        return [
            '出一份初二数学期中试卷',
            '生成 20 道初三英语时态选择题',
            '帮我编一本《一次函数》专项练习册',
            '把二次函数知识点改编成 10 道填空题',
        ];
    }
}
