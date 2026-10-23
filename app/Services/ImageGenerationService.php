<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImageGenerationService
{
    /**
     * The disk the generated images are stored on.
     */
    protected string $disk = 'public';

    /**
     * Generate an image from a text prompt via the free Pollinations API
     * (https://image.pollinations.ai) and store it on the public disk.
     *
     * @param  string  $prompt  The image prompt; must not be blank.
     * @param  int  $width  Image width in pixels (256–2048).
     * @param  int  $height  Image height in pixels (256–2048).
     * @return string Publicly accessible image URL, e.g. "/storage/ai-images/<uuid>.jpg".
     *
     * @throws RuntimeException When the prompt is blank, the request fails,
     *                          the response is not an image, or storing fails.
     */
    public function generate(string $prompt, int $width = 1024, int $height = 1024): string
    {
        $prompt = trim($prompt);

        if ($prompt === '') {
            throw new RuntimeException('The image prompt cannot be empty.');
        }

        // Outbound requests automatically use the global HTTP proxy
        // configured in AppServiceProvider (see services.http_proxy).
        $response = Http::timeout(60)
            ->get(rtrim(config('services.pollinations.url'), '/').'/prompt/'.rawurlencode($prompt), [
                'width' => $width,
                'height' => $height,
                'model' => config('services.pollinations.model', 'flux'),
                'nologo' => 'true',
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Pollinations image generation failed (HTTP '.$response->status().'): '
                    .mb_substr($response->body(), 0, 200)
            );
        }

        if (! str_starts_with(strtolower($response->header('Content-Type')), 'image/')) {
            throw new RuntimeException(
                'Pollinations returned a non-image response: '
                    .mb_substr($response->body(), 0, 200)
            );
        }

        $path = 'ai-images/'.Str::uuid().$this->extension($response->header('Content-Type'));

        if (Storage::disk($this->disk)->put($path, $response->body()) === false) {
            throw new RuntimeException('Failed to store the generated image.');
        }

        return Storage::disk($this->disk)->url($path);
    }

    /**
     * Map the response content type to a file extension.
     */
    protected function extension(string $contentType): string
    {
        return match (Str::before(strtolower($contentType), ';')) {
            'image/png' => '.png',
            'image/webp' => '.webp',
            default => '.jpg',
        };
    }
}
