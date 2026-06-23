<?php

namespace JeffersonGoncalves\LaravelZero\ApiClient;

use RuntimeException;

/**
 * Base exception for API errors.
 *
 * Concrete clients should extend this and (optionally) override
 * {@see ApiException::extractMessage()} when their API reports the
 * human-readable error under a non-standard JSON key.
 */
abstract class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $response  The decoded error body, when available.
     */
    public function __construct(
        string $message = 'API error.',
        public readonly int $statusCode = 0,
        public readonly ?array $response = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    /**
     * Build a concrete exception from an HTTP status code and decoded body.
     *
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(int $statusCode, array $body): static
    {
        $message = static::extractMessage($body);

        if ($message === '') {
            $message = "HTTP {$statusCode}";
        }

        return new static($message, $statusCode, $body);
    }

    /**
     * Pull the human-readable message out of a decoded error body.
     *
     * Default implementation understands the most common shapes:
     *   - { "error": { "message": "..." } }   (Bitbucket)
     *   - { "message": "..." }
     *   - { "errorMessages": ["...", "..."] } (Jira)
     *
     * Override in a subclass for API-specific shapes.
     *
     * @param  array<string, mixed>  $body
     */
    protected static function extractMessage(array $body): string
    {
        if (isset($body['error']['message']) && is_string($body['error']['message'])) {
            return $body['error']['message'];
        }

        if (isset($body['error']) && is_string($body['error'])) {
            return $body['error'];
        }

        if (isset($body['message']) && is_string($body['message'])) {
            return $body['message'];
        }

        if (isset($body['errorMessages']) && is_array($body['errorMessages'])) {
            return implode('; ', array_map('strval', $body['errorMessages']));
        }

        return '';
    }
}
