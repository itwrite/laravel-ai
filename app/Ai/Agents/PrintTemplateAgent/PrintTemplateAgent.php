<?php

namespace App\Ai\Agents\PrintTemplateAgent;

use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

#[Timeout(300)]
class PrintTemplateAgent implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations {
        RemembersConversations::messages as rememberedMessages;
    }

    /**
     * Create a new print template agent instance.
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
            '做一份"三好学生"奖状模板（A4 横版）',
            '设计 40 个学生姓名贴，排满一张 A4',
            '生成一张儿童生日派对邀请函（A5 竖版）',
            '排一版 10cm×6cm 的商品促销价签',
        ];
    }
}
