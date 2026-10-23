<?php

namespace App\Ai\Providers;

use App\Ai\Gateways\PollinationsImageGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Providers\Concerns\GeneratesImages;
use Laravel\Ai\Providers\Concerns\HasImageGateway;
use Laravel\Ai\Providers\Provider;

/**
 * Pollinations provider: the free text-to-image API
 * (https://image.pollinations.ai) exposed as an SDK image provider so it can
 * join the "image_providers" failover chain in config/ai.php.
 *
 * Only image generation is supported; reference-image attachments are not
 * accepted by this channel (the gateway fails over when handed any).
 */
class PollinationsProvider extends Provider implements ImageProvider
{
    use GeneratesImages;
    use HasImageGateway;

    /**
     * Create a new provider instance.
     *
     * Mirrors XaiProvider: the base constructor demands a generic Gateway,
     * so the config and events are promoted here instead and the image
     * gateway is instantiated lazily below.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config, protected Dispatcher $events) {}

    /**
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= new PollinationsImageGateway;
    }

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default']
            ?? $this->config['model']
            ?? 'flux';
    }

    /**
     * Map the requested aspect ratio and resolution tier to the pixel
     * dimensions the Pollinations API takes.
     *
     * Pollinations has no native ratio/quality options, so the ratio decides
     * the w/h split and the tier picks the long side (high = 1536, else 1024).
     *
     * @param  'low'|'medium'|'high'|null  $quality
     * @return array{width: int, height: int}
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        $longSide = $quality === 'high' ? 1536 : 1024;

        [$width, $height] = match ($size) {
            '2:3' => [(int) round($longSide * 2 / 3), $longSide],
            '3:2' => [$longSide, (int) round($longSide * 2 / 3)],
            default => [$longSide, $longSide],
        };

        return [
            'width' => $width,
            'height' => $height,
        ];
    }
}
