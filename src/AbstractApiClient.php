<?php

namespace JeffersonGoncalves\LaravelZero\ApiClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Base REST HTTP client extracted from the Bitbucket and Jira CLI wrappers.
 *
 * Concrete clients only need to implement {@see AbstractApiClient::newApiException()}
 * to bind the shared request/error handling to their own {@see ApiException} subclass.
 *
 * Auth headers are applied per request (see {@see AbstractApiClient::authHeaders()}),
 * so an injected Guzzle client - e.g. one backed by a MockHandler in tests - still
 * receives the correct Authorization header.
 */
abstract class AbstractApiClient
{
    protected Client $client;

    /**
     * @param  string  $baseUrl  Absolute base URL (e.g. https://api.bitbucket.org/2.0).
     * @param  Auth|null  $auth  Authentication credentials, or null for anonymous access.
     * @param  Client|null  $client  Optional pre-built Guzzle client (handy for testing).
     * @param  array<string, string>  $headers  Extra default headers merged into every request.
     */
    public function __construct(
        string $baseUrl,
        protected ?Auth $auth = null,
        ?Client $client = null,
        protected array $headers = [],
    ) {
        $this->client = $client ?? new Client([
            'base_uri' => rtrim($baseUrl, '/').'/',
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $path, array $data = []): array
    {
        return $this->request('POST', $path, $data === [] ? [] : ['json' => $data]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function put(string $path, array $data = []): array
    {
        return $this->request('PUT', $path, $data === [] ? [] : ['json' => $data]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function delete(string $path, array $query = []): array
    {
        return $this->request('DELETE', $path, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * Walk a paginated endpoint and aggregate every item.
     *
     * Pagination styles differ between APIs (Bitbucket uses an opaque "next" cursor URL,
     * Jira uses startAt/maxResults offsets), so the two API-specific decisions are passed
     * in as callables:
     *
     *   - $extractItems(array $response): array
     *       Return the list of items contained in a single page.
     *
     *   - $nextRequest(array $response, array $query): ?array
     *       Return the descriptor for the next request as
     *       ['path' => string, 'query' => array] (both keys optional), or null to stop.
     *
     * @param  array<string, mixed>  $query
     * @param  callable(array<string, mixed>): array<int, mixed>  $extractItems
     * @param  callable(array<string, mixed>, array<string, mixed>): (array{path?: string, query?: array<string, mixed>}|null)  $nextRequest
     * @return array<int, mixed>
     */
    public function paginate(string $path, array $query, callable $extractItems, callable $nextRequest): array
    {
        $results = [];

        while (true) {
            $response = $this->get($path, $query);
            $results = array_merge($results, $extractItems($response));

            $next = $nextRequest($response, $query);
            if ($next === null) {
                break;
            }

            $path = $next['path'] ?? $path;
            $query = $next['query'] ?? [];
        }

        return $results;
    }

    /**
     * Default headers sent with every request. Override to customise.
     *
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $this->headers);
    }

    /**
     * Authorization headers derived from the configured {@see Auth}. Override for
     * bespoke auth schemes.
     *
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        return $this->auth?->headers() ?? [];
    }

    /**
     * Bind the shared error handling to the concrete client's exception type.
     *
     * @param  array<string, mixed>  $body
     */
    abstract protected function newApiException(int $statusCode, array $body): ApiException;

    /**
     * Perform a request and decode the JSON body. An empty body decodes to [].
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $options = []): array
    {
        $options['headers'] = array_merge(
            $this->defaultHeaders(),
            $this->authHeaders(),
            $options['headers'] ?? [],
        );

        try {
            $response = $this->client->request($method, $path, $options);
            $body = $response->getBody()->getContents();

            if ($body === '') {
                return [];
            }

            return json_decode($body, true) ?? [];
        } catch (BadResponseException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $body = json_decode($e->getResponse()->getBody()->getContents(), true) ?? [];

            throw $this->newApiException($statusCode, $body);
        } catch (GuzzleException $e) {
            throw $this->newApiException(0, ['message' => "HTTP request failed: {$e->getMessage()}"]);
        }
    }
}
