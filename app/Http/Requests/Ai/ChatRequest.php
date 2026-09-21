<?php

namespace App\Http\Requests\Ai;

use App\Ai\ChatAgents;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Messages\Message;

class ChatRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'agent' => ['nullable', 'string', Rule::enum(ChatAgents::class)],
            // Stored conversation to continue (logged-in users only).
            'conversation' => ['nullable', 'uuid'],
            // A message may consist of images only, hence "required_without".
            'message' => ['nullable', 'string', 'max:4000', 'required_without:images'],
            // Inline base64 data URLs (max ~1.4 MB decoded per image).
            'images' => ['nullable', 'array', 'max:3'],
            'images.*' => ['string', 'max:2000000', 'regex:#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$#'],
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            // Assistant replies streamed back as history can be far longer
            // than user input, so they get a higher ceiling than "message".
            'history.*.content' => ['required', 'string', 'max:10000'],
        ];
    }

    /**
     * Get the selected chat agent, falling back to the general one.
     */
    public function agentKey(): ChatAgents
    {
        $agent = $this->validated('agent');

        return is_string($agent) ? ChatAgents::from($agent) : ChatAgents::defaultAgent();
    }

    /**
     * Get the conversation history as SDK messages.
     *
     * @return Message[]
     */
    public function history(): array
    {
        $history = $this->validated('history');

        if (! is_array($history)) {
            return [];
        }

        return collect($history)
            ->map(fn (array $message): Message => new Message($message['role'], $message['content']))
            ->values()
            ->all();
    }

    /**
     * Get the validated inline images as SDK attachments.
     *
     * @return Base64Image[]
     */
    public function images(): array
    {
        $images = $this->validated('images');

        if (! is_array($images)) {
            return [];
        }

        return collect($images)
            ->map(function (string $dataUrl): Base64Image {
                [$meta, $base64] = explode(',', $dataUrl, 2);

                // Strip the "data:" prefix from e.g. "data:image/png".
                return new Base64Image($base64, substr($meta, 5));
            })
            ->values()
            ->all();
    }
}
