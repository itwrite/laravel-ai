<?php

namespace App\Ai\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;

/**
 * Gateway for the free Pollinations text-to-image API.
 *
 * GET {base}/prompt/{prompt}?width=&height=&model=&nologo=true[&token=]
 * responds with the raw image bytes. The anonymous tier is rate limited;
 * an optional token (ai.providers.pollinations.token) raises the limits.
 */
class PollinationsImageGateway implements ImageGateway
{
    use CreatesClient;
    use HandlesFailoverErrors;

    /**
     * The status codes that indicate a transiently unavailable provider.
     *
     * Pollinations frequently reports its own rate limits and backend
     * hiccups as HTTP 500 (e.g. "Gen Sana request failed with 429: ...
     * Per-user limit of 300 RPM exceeded for model lykon/dreamshaper-8-lcm
     * ... community_model_rate_limit"), so 500 is failoverable here: the
     * chain continues with the next provider instead of failing outright.
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        return [500, 502, 503, 504, 520, 522, 524];
    }

    /**
     * Generate an image.
     *
     * @param  array<Image>  $attachments
     * @param  'low'|'medium'|'high'|null  $quality
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        if (filled($attachments)) {
            // Unsupported capability: throw a failoverable exception so the
            // chain moves on instead of failing the whole generation.
            throw new ProviderOverloadedException(
                'Pollinations does not support reference image attachments.'
            );
        }

        $options = $provider->defaultImageOptions($size, $quality);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->get('/prompt/'.rawurlencode($prompt), [
                ...$options,
                'model' => $model,
                'nologo' => 'true',
                ...array_filter(['token' => $provider->additionalConfiguration()['token'] ?? null]),
            ]),
        );

        $mime = strtolower($response->header('Content-Type'));

        if (! str_starts_with($mime, 'image/')) {
            // Failoverable as well: treat "200 but not an image" (e.g. an
            // HTML/JSON rate-limit page) like a transient provider failure.
            throw new ProviderOverloadedException(
                'Pollinations returned a non-image response ('.($mime ?: 'no content type').'): '
                    .mb_substr($response->body(), 0, 200)
            );
        }

        return new ImageResponse(
            new Collection([
                new GeneratedImage(base64_encode($response->body()), Str::before($mime, ';')),
            ]),
            new Usage,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get an HTTP client for the Pollinations API.
     */
    protected function client(ImageProvider $provider, ?int $timeout): PendingRequest
    {
        return $this->createClient(
            rtrim((string) ($provider->additionalConfiguration()['url'] ?? 'https://image.pollinations.ai'), '/'),
            [],
            [],
            $timeout ?? 60,
        );
    }
}
