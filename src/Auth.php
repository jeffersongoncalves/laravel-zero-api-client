<?php

namespace JeffersonGoncalves\LaravelZero\ApiClient;

/**
 * Immutable authentication descriptor for an API client.
 *
 * Build it through the named constructors:
 *
 *   Auth::basic('user@example.com', 'api-token');
 *   Auth::bearer('oauth-access-token');
 */
final class Auth
{
    private function __construct(
        public readonly AuthType $type,
        public readonly ?string $username,
        public readonly string $token,
    ) {}

    public static function basic(string $username, string $token): self
    {
        return new self(AuthType::Basic, $username, $token);
    }

    public static function bearer(string $token): self
    {
        return new self(AuthType::Bearer, null, $token);
    }

    /**
     * The HTTP Authorization header this credential produces.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return match ($this->type) {
            AuthType::Bearer => ['Authorization' => "Bearer {$this->token}"],
            AuthType::Basic => ['Authorization' => 'Basic '.base64_encode("{$this->username}:{$this->token}")],
        };
    }
}
