# laravel-zero-api-client

A small, self-contained base for building REST API client wrappers in Laravel Zero CLI
tools. It was extracted from the near-identical HTTP layers of the Bitbucket and Jira
CLIs and captures the shared 85%: `get`/`post`/`put`/`delete`, pagination, Basic/Bearer
authentication and consistent JSON error handling on top of Guzzle.

## Why

Every API CLI ends up writing the same boilerplate: a Guzzle client, JSON decoding, a
"throw a typed exception on 4xx" branch, and a pagination loop. This package owns that
boilerplate so each concrete client only describes what is genuinely API-specific:

- which exception type to throw, and
- how to walk that API's particular pagination scheme.

It has no dependency on any credentials package - authentication is passed in through the
constructor.

## Installation

```bash
composer require jeffersongoncalves/laravel-zero-api-client
```

Requires PHP `^8.2` and `guzzlehttp/guzzle ^7.10`.

## Usage

### 1. Define your exception

Extend `ApiException`. The base `extractMessage()` already understands the common error
shapes (`error.message`, `message`, `errorMessages`); override it only when your API is
different.

```php
use JeffersonGoncalves\LaravelZero\ApiClient\ApiException;

final class GitHubApiException extends ApiException
{
    // Optional: GitHub returns { "message": "..." }, already handled by the base.
}
```

### 2. Define your client

Extend `AbstractApiClient` and implement `newApiException()` to bind error handling to
your exception type.

```php
use JeffersonGoncalves\LaravelZero\ApiClient\AbstractApiClient;
use JeffersonGoncalves\LaravelZero\ApiClient\ApiException;
use JeffersonGoncalves\LaravelZero\ApiClient\Auth;

final class GitHubClient extends AbstractApiClient
{
    public function __construct(string $token)
    {
        parent::__construct('https://api.github.com', Auth::bearer($token));
    }

    protected function newApiException(int $statusCode, array $body): ApiException
    {
        return GitHubApiException::fromResponse($statusCode, $body);
    }

    public function repo(string $owner, string $repo): array
    {
        return $this->get("repos/{$owner}/{$repo}");
    }
}
```

### 3. Authentication

```php
Auth::basic('user@example.com', 'api-token'); // Authorization: Basic base64(user:token)
Auth::bearer('access-token');                 // Authorization: Bearer access-token
```

Pass `null` for anonymous access, or override `authHeaders()` for a custom scheme.

### 4. Pagination

Pagination differs across APIs, so `paginate()` takes two callables: one to extract the
items from a page, one to describe the next request (or return `null` to stop).

Cursor pagination (Bitbucket-style `next` URL):

```php
$all = $client->paginate(
    "repositories/{$workspace}/{$repo}/pullrequests",
    ['state' => 'OPEN'],
    fn (array $page) => $page['values'] ?? [],
    fn (array $page) => isset($page['next'])
        ? ['path' => $page['next'], 'query' => []]
        : null,
);
```

Offset pagination (Jira-style `startAt`/`maxResults`):

```php
$all = $client->paginate(
    'search',
    ['startAt' => 0, 'maxResults' => 50],
    fn (array $page) => $page['issues'] ?? [],
    function (array $page, array $query) {
        $next = $query['startAt'] + $query['maxResults'];

        return $next >= ($page['total'] ?? 0)
            ? null
            : ['query' => ['startAt' => $next, 'maxResults' => $query['maxResults']]];
    },
);
```

## Public API

| Class | Purpose |
|-------|---------|
| `AbstractApiClient` | Base client: `get`, `post`, `put`, `delete`, `paginate`. Implement `newApiException()`. |
| `ApiException` | Abstract base exception with `fromResponse()` / `extractMessage()` and `$statusCode` / `$response`. |
| `Auth` | Immutable credential: `Auth::basic()`, `Auth::bearer()`. |
| `AuthType` | `Basic` / `Bearer` enum. |

## Testing

```bash
composer test
```

## License

MIT
