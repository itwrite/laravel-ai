<?php

namespace App\Http\Requests\Ai;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PollinationsImageRequest extends FormRequest
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
            // The image prompt handed to Pollinations.
            'prompt' => ['required', 'string', 'min:2', 'max:2000'],
            // Pixel dimensions accepted by the Pollinations API.
            'width' => ['nullable', 'integer', 'between:256,2048'],
            'height' => ['nullable', 'integer', 'between:256,2048'],
        ];
    }
}
