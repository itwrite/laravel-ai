<?php

namespace App\Ai\Gateways;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\OpenAiCompatible\OpenAiCompatibleGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

/**
 * OpenAI-compatible gateway that surfaces zhipu GLM chain-of-thought.
 *
 * Thinking-capable GLM models (glm-4.5 and later) stream their reasoning in
 * the "reasoning_content" delta field, which the stock openai-compatible
 * gateway discards. This subclass keeps the standard behavior (the answer
 * text never contains reasoning) while additionally emitting the SDK's
 * ReasoningStart / ReasoningDelta / ReasoningEnd stream events so the chat
 * UI can show the thinking process live.
 *
 * The stream parsing mirrors HandlesTextStreaming from laravel/ai v0.11.2;
 * re-check it when upgrading the SDK.
 */
class ZhipuGateway extends OpenAiCompatibleGateway
{
    /**
     * Build the Chat Completions request body, enabling the model's thinking
     * mode so the reasoning is streamed back.
     *
     * @param  array<int, mixed>  $messages
     * @param  array<int, mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>
     */
    #[\Override]
    protected function buildStepBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        $body = parent::buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $thinking = $this->thinkingConfiguration($provider);

        if ($thinking !== null) {
            $body['thinking'] = $thinking;
        }

        return $body;
    }

    /**
     * Resolve the zhipu "thinking" request parameter from the provider
     * configuration (see config/ai.php). Returns null when thinking mode is
     * disabled, or a raw parameter array when fully customized.
     *
     * @return array<string, mixed>|null
     */
    protected function thinkingConfiguration(Provider $provider): ?array
    {
        $configured = $provider->additionalConfiguration()['thinking'] ?? true;

        if (! $configured) {
            return null;
        }

        if (is_array($configured)) {
            return $configured;
        }

        return ['type' => 'enabled'];
    }

    /**
     * Process a Chat Completions streaming response and yield Laravel stream
     * events, including reasoning events for thinking-capable GLM models.
     *
     * @param  mixed  $streamBody
     * @return Generator<int, StreamEvent, mixed, StepResponse|null>
     */
    #[\Override]
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        $streamBody,
    ): Generator {
        $messageId = $this->generateEventId();
        $reasoningId = '';
        $streamStartEmitted = false;
        $textStartEmitted = false;
        $currentText = '';
        $toolCalls = [];
        $pendingToolCalls = [];
        $usage = null;
        $finishReason = null;
        $responseModel = $model;

        /** @var array<string, mixed> $data */
        foreach ($this->parseServerSentEvents($streamBody) as $data) {
            $error = $data['error'] ?? null;

            if (is_array($error)) {
                yield (new Error(
                    $this->generateEventId(),
                    (string) ($error['code'] ?? 'unknown_error'),
                    (string) ($error['message'] ?? 'Unknown error'),
                    false,
                    time(),
                ))->withInvocationId($invocationId);

                return null;
            }

            $choices = is_array($data['choices'] ?? null) ? $data['choices'] : [];
            $choice = $choices[0] ?? null;

            if (! is_array($choice)) {
                if (isset($data['usage'])) {
                    $usage = $this->extractUsage($data);
                }

                continue;
            }

            $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

            if (! $streamStartEmitted) {
                $streamStartEmitted = true;
                $responseModel = (string) ($data['model'] ?? $model);

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $responseModel,
                    time(),
                ))->withInvocationId($invocationId);
            }

            // Reasoning deltas arrive before the answer; zhipu names the field
            // "reasoning_content" while other OpenAI-compatible APIs use
            // "reasoning", so both are accepted.
            $reasoningDelta = $delta['reasoning_content'] ?? $delta['reasoning'] ?? null;

            if (is_string($reasoningDelta) && $reasoningDelta !== '') {
                if ($reasoningId === '') {
                    $reasoningId = $this->generateEventId();

                    yield (new ReasoningStart(
                        $this->generateEventId(),
                        $reasoningId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                yield (new ReasoningDelta(
                    $this->generateEventId(),
                    $reasoningId,
                    $reasoningDelta,
                    time(),
                ))->withInvocationId($invocationId);
            }

            $contentDelta = $delta['content'] ?? null;

            if (is_string($contentDelta) && $contentDelta !== '') {
                if (! $textStartEmitted) {
                    $textStartEmitted = true;

                    // The answer starts: close the reasoning block first so the
                    // frontend can collapse its live preview.
                    if ($reasoningId !== '') {
                        yield (new ReasoningEnd(
                            $this->generateEventId(),
                            $reasoningId,
                            time(),
                        ))->withInvocationId($invocationId);

                        $reasoningId = '';
                    }

                    yield (new TextStart(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $currentText .= $contentDelta;

                yield (new TextDelta(
                    $this->generateEventId(),
                    $messageId,
                    $contentDelta,
                    time(),
                ))->withInvocationId($invocationId);
            }

            $toolCallDeltas = $delta['tool_calls'] ?? null;

            if (is_array($toolCallDeltas)) {
                foreach ($toolCallDeltas as $toolCallDelta) {
                    if (! is_array($toolCallDelta)) {
                        continue;
                    }

                    $index = $toolCallDelta['index'];

                    if (! array_key_exists($index, $pendingToolCalls)) {
                        $function = is_array($toolCallDelta['function'] ?? null) ? $toolCallDelta['function'] : [];

                        $pendingToolCalls[$index] = [
                            'id' => (string) ($toolCallDelta['id'] ?? ''),
                            'name' => (string) ($function['name'] ?? ''),
                            'arguments' => '',
                        ];
                    }

                    $function = is_array($toolCallDelta['function'] ?? null) ? $toolCallDelta['function'] : [];

                    if (is_string($function['arguments'] ?? null)) {
                        $pendingToolCalls[$index]['arguments'] .= $function['arguments'];
                    }
                }
            }

            $choiceFinishReason = $choice['finish_reason'] ?? null;

            if (is_string($choiceFinishReason) && $choiceFinishReason !== '') {
                $finishReason = $choiceFinishReason;
            }

            if (isset($data['usage'])) {
                $usage = $this->extractUsage($data);
            }
        }

        // A stream may end during the reasoning phase (e.g. stopped early);
        // close the block so the frontend always gets its ReasoningEnd.
        if ($reasoningId !== '') {
            yield (new ReasoningEnd(
                $this->generateEventId(),
                $reasoningId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if ($textStartEmitted) {
            yield (new TextEnd(
                $this->generateEventId(),
                $messageId,
                time(),
            ))->withInvocationId($invocationId);
        }

        if (filled($pendingToolCalls) && $finishReason === 'tool_calls') {
            $toolCalls = $this->mapStreamToolCalls($pendingToolCalls);

            foreach ($toolCalls as $toolCall) {
                yield (new ToolCallEvent(
                    $this->generateEventId(),
                    $toolCall,
                    time(),
                ))->withInvocationId($invocationId);
            }
        }

        return new StepResponse(
            text: $currentText,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason(['finish_reason' => $finishReason ?? '']),
            usage: $usage ?? new Usage(0, 0),
            meta: new Meta($provider->name(), $responseModel),
        );
    }

    /**
     * Generate a lowercase UUID v7 for use as a stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
