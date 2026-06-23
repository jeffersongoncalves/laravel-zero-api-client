<?php

namespace JeffersonGoncalves\LaravelZero\ApiClient\Tests\Fakes;

use JeffersonGoncalves\LaravelZero\ApiClient\AbstractApiClient;
use JeffersonGoncalves\LaravelZero\ApiClient\ApiException;

class FakeApiClient extends AbstractApiClient
{
    /**
     * @param  array<string, mixed>  $body
     */
    protected function newApiException(int $statusCode, array $body): ApiException
    {
        return FakeApiException::fromResponse($statusCode, $body);
    }
}
