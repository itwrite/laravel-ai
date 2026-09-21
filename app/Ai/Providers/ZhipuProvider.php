<?php

namespace App\Ai\Providers;

use App\Ai\Gateways\ZhipuGateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;

/**
 * Zhipu provider: an openai-compatible provider whose text gateway surfaces
 * the model's chain-of-thought (see ZhipuGateway).
 */
class ZhipuProvider extends OpenAiCompatibleProvider
{
    /**
     * Get the provider's text gateway.
     */
    #[\Override]
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new ZhipuGateway($this->events);
    }
}
