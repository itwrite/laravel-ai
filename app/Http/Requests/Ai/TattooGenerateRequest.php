<?php

namespace App\Http\Requests\Ai;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Laravel\Ai\Files\Base64Image;

class TattooGenerateRequest extends FormRequest
{
    /**
     * The line art styles the generator understands, mapped to the English
     * prompt fragments the image model reacts to.
     *
     * @var array<string, string>
     */
    public const STYLES = [
        'fine-line' => 'minimalist fine-line tattoo style with delicate thin outlines',
        'traditional' => 'American traditional (old school) tattoo style with bold outlines',
        'neo-traditional' => 'neo-traditional tattoo style with decorative flowing linework',
        'japanese' => 'Japanese traditional irezumi tattoo style',
        'geometric' => 'geometric tattoo style composed of precise lines and shapes',
        'black-grey' => 'realistic black-and-grey tattoo design rendered as detailed line work',
        'tribal' => 'Polynesian tribal tattoo style with bold flowing patterns',
        'sketch' => 'loose hand-drawn pencil sketch tattoo draft',
    ];

    /**
     * The body placements the design can be tailored for, mapped to the
     * English phrases used in the prompt.
     *
     * @var array<string, string>
     */
    public const PLACEMENTS = [
        'forearm' => 'forearm',
        'upper-arm' => 'upper arm',
        'shoulder' => 'shoulder',
        'back' => 'upper back',
        'chest' => 'chest',
        'leg' => 'leg (thigh or calf)',
        'wrist' => 'wrist',
        'ankle' => 'ankle',
        'neck' => 'neck',
        'rib' => 'ribcage',
    ];

    /**
     * The canvas aspect ratios, mapped to the sizes the image provider takes.
     *
     * @var array<string, string>
     */
    public const RATIOS = [
        'square' => '1:1',
        'portrait' => '2:3',
        'landscape' => '3:2',
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
            // The tattoo idea the line art is drawn from.
            'subject' => ['required', 'string', 'min:2', 'max:500'],
            'style' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::STYLES))],
            'placement' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::PLACEMENTS))],
            'ratio' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::RATIOS))],
            // Resolution tier: low = 1K, medium = 2K, high = 4K.
            'detail' => ['nullable', 'string', 'in:low,medium,high'],
            'extras' => ['nullable', 'string', 'max:1000'],
            // Inline base64 data URL of an optional reference image
            // (max ~1.4 MB decoded) the design should be based on.
            'reference' => ['nullable', 'string', 'max:2000000', 'regex:#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$#'],
        ];
    }

    /**
     * Build the English image prompt handed to the Gemini image model from
     * the validated input, omitting sections the user left blank.
     */
    public function imagePrompt(): string
    {
        $lines = [
            'Professional tattoo line art design, stencil-ready tattoo outline.',
            'Subject: '.$this->string('subject')->trim()->toString(),
        ];

        $style = $this->validated('style');

        if (is_string($style) && isset(self::STYLES[$style])) {
            $lines[] = 'Style: '.self::STYLES[$style];
        }

        $placement = $this->validated('placement');

        if (is_string($placement) && isset(self::PLACEMENTS[$placement])) {
            $lines[] = 'Intended body placement: '.self::PLACEMENTS[$placement];
        }

        if ($this->referenceImage() !== null) {
            $lines[] = 'The attached image is the visual reference: preserve its overall composition and key elements while converting it into the requested line art.';
        }

        $extras = $this->string('extras')->trim()->toString();

        if ($extras !== '') {
            $lines[] = "Additional requirements: {$extras}";
        }

        $lines[] = 'Rendering requirements: pure black ink linework on a clean white background; bold, crisp, consistent line weight; no shading, no color, no gradients; no text, no letters, no watermark; a single centered, isolated design filling the canvas.';

        return implode("\n", $lines);
    }

    /**
     * Get the canvas size for the selected ratio, if any.
     */
    public function size(): ?string
    {
        $ratio = $this->validated('ratio');

        return is_string($ratio) && isset(self::RATIOS[$ratio]) ? self::RATIOS[$ratio] : null;
    }

    /**
     * Get the resolution tier for the selected detail level, if any.
     *
     * @return 'low'|'medium'|'high'|null
     */
    public function quality(): ?string
    {
        $detail = $this->validated('detail');

        return is_string($detail) && in_array($detail, ['low', 'medium', 'high'], true)
            ? $detail
            : null;
    }

    /**
     * Get the validated reference image as an SDK attachment.
     */
    public function referenceImage(): ?Base64Image
    {
        $reference = $this->validated('reference');

        if (! is_string($reference)) {
            return null;
        }

        [$meta, $base64] = explode(',', $reference, 2);

        // Strip the "data:" prefix from e.g. "data:image/png".
        return new Base64Image($base64, substr($meta, 5));
    }
}
