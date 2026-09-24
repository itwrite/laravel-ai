<?php

namespace App\Http\Requests\Ai;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ArticleGenerateRequest extends FormRequest
{
    /**
     * The language styles the article may be written in.
     *
     * @var array<string, string>
     */
    public const TONES = [
        'professional' => '专业严谨',
        'friendly' => '亲切易懂',
        'formal' => '正式书面',
        'passionate' => '热情有感染力',
        'playful' => '活泼有趣',
        'academic' => '学术规范',
    ];

    /**
     * The article lengths the generator understands.
     *
     * @var array<string, string>
     */
    public const LENGTHS = [
        'short' => '短篇（约 500 字）',
        'medium' => '中篇（约 1000 字）',
        'long' => '长篇（约 2000 字）',
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
            'topic' => ['nullable', 'string', 'max:120'],
            // The raw material the article is optimized and organized from.
            'content' => ['required', 'string', 'min:10', 'max:8000'],
            'audience' => ['nullable', 'string', 'max:120'],
            'tone' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::TONES))],
            'length' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::LENGTHS))],
            'extras' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Build the prompt handed to the article agent from the validated input,
     * omitting sections the user left blank.
     */
    public function promptText(): string
    {
        $sections = [
            '文章主题' => $this->string('topic')->toString(),
            '原始素材' => $this->string('content')->toString(),
            '目标读者' => $this->string('audience')->toString(),
            '语言风格' => $this->toneLabel(),
            '篇幅要求' => $this->lengthLabel(),
            '补充要求' => $this->string('extras')->toString(),
        ];

        $body = collect($sections)
            ->filter(fn (string $value): bool => $value !== '')
            ->map(fn (string $value, string $label): string => "【{$label}】\n{$value}")
            ->implode("\n\n");

        return "请根据以下信息，优化整理并生成一篇响应式的 HTML 文章：\n\n{$body}";
    }

    /**
     * Get the human-readable label of the requested tone, if any.
     */
    private function toneLabel(): string
    {
        $tone = $this->validated('tone');

        return is_string($tone) && isset(self::TONES[$tone]) ? self::TONES[$tone] : '';
    }

    /**
     * Get the human-readable label of the requested length, if any.
     */
    private function lengthLabel(): string
    {
        $length = $this->validated('length');

        return is_string($length) && isset(self::LENGTHS[$length]) ? self::LENGTHS[$length] : '';
    }
}
