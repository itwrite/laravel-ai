<?php

namespace App\Ai\Agents\ChatAgent;

use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

#[Timeout(300)]
class ChatAgent implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations {
        RemembersConversations::messages as rememberedMessages;
    }

    /**
     * Create a new chat agent instance.
     *
     * @param  Message[]  $messages
     */
    public function __construct(protected array $messages = []) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return '';
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
     * Determine whether the default AI provider has been configured.
     */
    public static function configured(): bool
    {
        $provider = (array) config('ai.providers.'.config('ai.default'), []);

        return filled($provider['key'] ?? null)
            || (($provider['driver'] ?? null) === 'ollama' && filled($provider['url'] ?? null));
    }

    /**
     * Get the starter prompts suggested for this agent.
     *
     * @return list<string>
     */
    public static function suggestions(): array
    {
        return [
            'What can the Laravel AI SDK do?',
            'Write a Pest test for a POST endpoint',
            'Explain Laravel 13 attributes like #[Fillable]',
            'Give me ideas for my next Laravel side project',
        ];
    }
}
