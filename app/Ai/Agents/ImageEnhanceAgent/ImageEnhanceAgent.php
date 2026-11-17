<?php

namespace App\Ai\Agents\ImageEnhanceAgent;

use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

#[Timeout(300)]
class ImageEnhanceAgent implements Agent
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
     * Compose the flat prompt handed to the image model: the fixed agent
     * instructions followed by the request-specific requirements.
     */
    public function imagePrompt(string $requirements): string
    {
        return trim($this->instructions()."\n\n".trim($requirements));
    }
}
