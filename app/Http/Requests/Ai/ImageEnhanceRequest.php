<?php

namespace App\Http\Requests\Ai;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Laravel\Ai\Files\Base64Image;

class ImageEnhanceRequest extends FormRequest
{
    /**
     * The enhancement goals the assistant understands, mapped to the English
     * prompt fragments the image model reacts to.
     *
     * @var array<string, string>
     */
    public const GOALS = [
        'general' => 'General purpose enhancement faithful to the original medium, whether photo, illustration or render.',
        'portrait' => 'Portrait photo enhancement: keep facial identity and features strictly unchanged while recovering natural skin texture, crisp eyes and detailed hair.',
        'product' => 'Commercial product photo enhancement: catalog-grade edge-to-edge sharpness, clean surfaces and accurate materials.',
        'anime' => 'Illustration and anime artwork enhancement: clean confident linework and vivid flat colors without adding painterly noise.',
        'restore' => 'Old photo restoration: remove scratches, dust, creases and stains, repair faded or yellowed tones, and recover lost detail without altering the content.',
        // The one goal allowed to erase content: it targets handwritten
        // additions only and must keep the underlying print intact. The key
        // maps to Mode B ("remove_handwriting") of the agent instructions.
        'remove-handwriting' => 'completely erase every handwritten answer, note, annotation, circled mark, correction and signature; keep all original printed content exactly as is, with no smudge, shadow or ghost left where the handwriting was, and clarify the remaining print for re-printing.',
    ];

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
            // Inline base64 data URL of the source image (max ~2.9 MB
            // decoded); the frontend downscales larger uploads to fit.
            'image' => ['required', 'string', 'max:4000000', 'regex:#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$#'],
            'goal' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::GOALS))],
            // Resolution tier: medium = 2K, high = 4K (default).
            'detail' => ['nullable', 'string', 'in:medium,high'],
            'extras' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Build the request-specific requirements handed to the image model
     * from the validated input, omitting sections the user left blank; the
     * ImageEnhanceAgent's fixed instructions precede them.
     */
    public function enhancePrompt(): string
    {
        $lines = [];

        $goal = $this->validated('goal');

        if (is_string($goal) && isset(self::GOALS[$goal])) {
            // remove_handwriting selects Mode B of the agent instructions;
            // every other goal stays inside Mode A enhancement.
            $lines[] = $goal === 'remove-handwriting'
                ? 'Processing mode: remove_handwriting (Mode B). Mode requirements: '.self::GOALS[$goal]
                : 'Enhancement focus: '.self::GOALS[$goal];
        }

        $extras = $this->string('extras')->trim()->toString();

        if ($extras !== '') {
            $lines[] = "Additional requirements: {$extras}";
        }

        $lines[] = $this->quality() === 'high'
            ? 'Resolution tier: deliver the maximum available resolution (4K-class) with maximum preserved detail.'
            : 'Resolution tier: deliver high resolution (2K-class) with richly preserved detail.';

        return implode("\n", $lines);
    }

    /**
     * Get the resolution tier for the selected detail level, defaulting to
     * the highest available tier.
     *
     * @return 'medium'|'high'
     */
    public function quality(): string
    {
        return $this->validated('detail') === 'medium' ? 'medium' : 'high';
    }

    /**
     * Get the validated source image as an SDK attachment.
     */
    public function sourceImage(): Base64Image
    {
        [$meta, $base64] = explode(',', $this->string('image')->toString(), 2);

        // Strip the "data:" prefix from e.g. "data:image/png".
        return new Base64Image($base64, substr($meta, 5));
    }
}
