<?php

namespace App\Ai\Agents\ArticleAgent;

use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

#[Timeout(300)]
class ArticleAgent implements Agent
{
    use Promptable;

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
     * Extract the HTML document from a raw model reply.
     *
     * Models occasionally wrap the document in a markdown code fence despite
     * the instructions, so a single wrapping fence is stripped.
     */
    public static function extractHtml(string $text): string
    {
        $html = trim($text);

        if (preg_match('/^```[a-zA-Z]*\s*\n?(.*?)\n?\s*```$/s', $html, $matches) === 1) {
            $html = trim((string) $matches[1]);
        }

        return $html;
    }

    /**
     * Extract the document title, used as the download filename suggestion.
     */
    public static function extractTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches) !== 1) {
            return null;
        }

        $title = trim(html_entity_decode(strip_tags((string) $matches[1]), ENT_QUOTES, 'UTF-8'));

        return $title === '' ? null : $title;
    }
}
