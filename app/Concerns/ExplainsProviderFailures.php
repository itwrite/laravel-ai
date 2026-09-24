<?php

namespace App\Concerns;

use Illuminate\Http\Client\RequestException;
use Throwable;

trait ExplainsProviderFailures
{
    /**
     * Build the user-facing message for a failed AI provider call, appending
     * the provider's own error detail when available.
     *
     * SDK exceptions such as RateLimitedException repackage the HTTP failure
     * and only say "rate limited", while the chained RequestException still
     * carries the provider's response body (e.g. zhipu's 1113 "insufficient
     * credits" is also sent as HTTP 429).
     */
    protected static function providerFailureMessage(Throwable $e): string
    {
        $message = $e->getMessage();

        $root = $e;

        while ($root->getPrevious() instanceof Throwable) {
            $root = $root->getPrevious();

            if ($root instanceof RequestException) {
                break;
            }
        }

        if ($root instanceof RequestException) {
            $detail = $root->response->json('error.message') ?? $root->response->body();

            if (is_string($detail) && $detail !== '') {
                $message .= ' — '.mb_substr($detail, 0, 300);
            }
        }

        return $message;
    }
}
