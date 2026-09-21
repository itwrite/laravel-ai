<?php

namespace App\Ai;

use App\Ai\Agents\ChatAgent\ChatAgent;
use App\Ai\Agents\PrintTemplateAgent\PrintTemplateAgent;
use App\Ai\Agents\TeacherAgent\TeacherAgent;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Messages\Message;

/**
 * The chat agents available on the homepage, keyed for the frontend switcher.
 */
enum ChatAgents: string
{
    case PrintTemplate = 'print-template';
    case General = 'general';
    case Teacher = 'teacher';

    /**
     * The agent used when the client sends no agent preference. It is also
     * the first catalog entry, so the frontend preselects it by default.
     */
    public static function defaultAgent(): self
    {
        return self::PrintTemplate;
    }

    /**
     * Get the display name of the agent.
     */
    public function name(): string
    {
        return match ($this) {
            self::General => '通用助手',
            self::Teacher => '职业教师',
            self::PrintTemplate => '打印模板助手',
        };
    }

    /**
     * Get the short description shown in the switcher.
     */
    public function description(): string
    {
        return match ($this) {
            self::General => '问答、写作与编程的通用助手',
            self::Teacher => '出试卷、练习题册与解析的教研专家',
            self::PrintTemplate => '设计与输出标签、证书、请柬等可打印模板的排版专家',
        };
    }

    /**
     * Get the starter prompts suggested for this agent.
     *
     * @return list<string>
     */
    public function suggestions(): array
    {
        return match ($this) {
            self::General => ChatAgent::suggestions(),
            self::Teacher => TeacherAgent::suggestions(),
            self::PrintTemplate => PrintTemplateAgent::suggestions(),
        };
    }

    /**
     * Create a new agent instance with the given conversation history.
     *
     * @param  Message[]  $messages
     */
    public function newAgent(array $messages): ChatAgent|TeacherAgent|PrintTemplateAgent
    {
        return match ($this) {
            self::General => new ChatAgent($messages),
            self::Teacher => new TeacherAgent($messages),
            self::PrintTemplate => new PrintTemplateAgent($messages),
        };
    }

    /**
     * Get the agent class backing this chat agent.
     *
     * @return class-string<Agent>
     */
    public function agentClass(): string
    {
        return match ($this) {
            self::General => ChatAgent::class,
            self::Teacher => TeacherAgent::class,
            self::PrintTemplate => PrintTemplateAgent::class,
        };
    }

    /**
     * Resolve the chat agent for a persisted agent class name, if known.
     */
    public static function fromAgentClass(string $agentClass): ?self
    {
        foreach (self::cases() as $agent) {
            if ($agent->agentClass() === $agentClass) {
                return $agent;
            }
        }

        return null;
    }

    /**
     * Get the agent catalog shared with the frontend.
     *
     * @return list<array{key: string, name: string, description: string, suggestions: string[]}>
     */
    public static function catalog(): array
    {
        return array_map(
            static fn (self $agent): array => [
                'key' => $agent->value,
                'name' => $agent->name(),
                'description' => $agent->description(),
                'suggestions' => $agent->suggestions(),
            ],
            self::cases(),
        );
    }
}
