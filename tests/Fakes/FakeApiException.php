<?php

namespace JeffersonGoncalves\LaravelZero\ApiClient\Tests\Fakes;

use JeffersonGoncalves\LaravelZero\ApiClient\ApiException;

class FakeApiException extends ApiException
{
    /**
     * Custom shape: { "detail": "..." }, falling back to the base extractor.
     *
     * @param  array<string, mixed>  $body
     */
    protected static function extractMessage(array $body): string
    {
        if (isset($body['detail']) && is_string($body['detail'])) {
            return $body['detail'];
        }

        return parent::extractMessage($body);
    }
}
