<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use JeffersonGoncalves\LaravelZero\ApiClient\Auth;
use JeffersonGoncalves\LaravelZero\ApiClient\Tests\Fakes\FakeApiClient;
use JeffersonGoncalves\LaravelZero\ApiClient\Tests\Fakes\FakeApiException;

/**
 * Build a FakeApiClient backed by a MockHandler.
 *
 * @param  array<int, Response>  $responses
 * @param  array<int, array{request: Request}>  $history  Filled by reference with sent requests.
 */
function makeClient(array $responses, ?array &$history = null, ?Auth $auth = null): FakeApiClient
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $guzzle = new Client(['handler' => $stack, 'base_uri' => 'https://api.test/']);

    return new FakeApiClient('https://api.test', $auth, $guzzle);
}

it('parses a JSON response on get', function () {
    $client = makeClient([
        new Response(200, [], json_encode(['id' => 7, 'name' => 'widget'])),
    ]);

    expect($client->get('things/7'))->toBe(['id' => 7, 'name' => 'widget']);
});

it('returns an empty array for an empty body', function () {
    $client = makeClient([new Response(204, [], '')]);

    expect($client->delete('things/7'))->toBe([]);
});

it('sends query parameters on get', function () {
    $client = makeClient([new Response(200, [], '{}')], $history);

    $client->get('things', ['page' => 2, 'q' => 'foo']);

    /** @var Request $request */
    $request = $history[0]['request'];
    expect($request->getUri()->getQuery())->toBe('page=2&q=foo');
});

it('sends a JSON body on post', function () {
    $client = makeClient([new Response(201, [], '{"ok":true}')], $history);

    $result = $client->post('things', ['name' => 'gadget']);

    /** @var Request $request */
    $request = $history[0]['request'];
    expect($result)->toBe(['ok' => true])
        ->and($request->getMethod())->toBe('POST')
        ->and((string) $request->getBody())->toBe('{"name":"gadget"}')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json');
});

it('sends a JSON body on put', function () {
    $client = makeClient([new Response(200, [], '{}')], $history);

    $client->put('things/1', ['name' => 'renamed']);

    /** @var Request $request */
    $request = $history[0]['request'];
    expect($request->getMethod())->toBe('PUT')
        ->and((string) $request->getBody())->toBe('{"name":"renamed"}');
});

it('throws the concrete exception on a 4xx error with the extracted message', function () {
    $client = makeClient([
        new Response(404, [], json_encode(['error' => ['message' => 'Repository not found']])),
    ]);

    expect(fn () => $client->get('things/missing'))
        ->toThrow(FakeApiException::class, 'Repository not found');
});

it('uses the subclass message extractor', function () {
    $client = makeClient([
        new Response(422, [], json_encode(['detail' => 'Validation failed'])),
    ]);

    try {
        $client->post('things', ['bad' => true]);
        $this->fail('Expected FakeApiException was not thrown.');
    } catch (FakeApiException $e) {
        expect($e->getMessage())->toBe('Validation failed')
            ->and($e->statusCode)->toBe(422)
            ->and($e->response)->toBe(['detail' => 'Validation failed']);
    }
});

it('extracts Jira-style errorMessages via the base extractor', function () {
    $client = makeClient([
        new Response(400, [], json_encode(['errorMessages' => ['Bad sprint', 'Bad board']])),
    ]);

    expect(fn () => $client->get('search'))
        ->toThrow(FakeApiException::class, 'Bad sprint; Bad board');
});

it('falls back to the status code when no message is present', function () {
    $client = makeClient([new Response(500, [], '{}')]);

    expect(fn () => $client->get('boom'))
        ->toThrow(FakeApiException::class, 'HTTP 500');
});

it('aggregates items across cursor-paginated pages (Bitbucket style)', function () {
    $client = makeClient([
        new Response(200, [], json_encode([
            'values' => [['id' => 1], ['id' => 2]],
            'next' => 'https://api.test/things?page=2',
        ])),
        new Response(200, [], json_encode([
            'values' => [['id' => 3]],
        ])),
    ]);

    $items = $client->paginate(
        'things',
        [],
        fn (array $response) => $response['values'] ?? [],
        function (array $response) {
            $next = $response['next'] ?? null;

            return $next === null ? null : ['path' => $next, 'query' => []];
        },
    );

    expect($items)->toBe([['id' => 1], ['id' => 2], ['id' => 3]]);
});

it('aggregates items across offset-paginated pages (Jira style)', function () {
    $client = makeClient([
        new Response(200, [], json_encode([
            'issues' => [['id' => 'A'], ['id' => 'B']],
            'total' => 3,
        ])),
        new Response(200, [], json_encode([
            'issues' => [['id' => 'C']],
            'total' => 3,
        ])),
    ]);

    $items = $client->paginate(
        'search',
        ['startAt' => 0, 'maxResults' => 2],
        fn (array $response) => $response['issues'] ?? [],
        function (array $response, array $query) {
            $startAt = ($query['startAt'] ?? 0) + ($query['maxResults'] ?? 0);
            $total = $response['total'] ?? 0;

            if ($startAt >= $total) {
                return null;
            }

            return ['query' => ['startAt' => $startAt, 'maxResults' => $query['maxResults']]];
        },
    );

    expect($items)->toBe([['id' => 'A'], ['id' => 'B'], ['id' => 'C']]);
});

it('sends a Bearer Authorization header', function () {
    $client = makeClient([new Response(200, [], '{}')], $history, Auth::bearer('secret-token'));

    $client->get('user');

    /** @var Request $request */
    $request = $history[0]['request'];
    expect($request->getHeaderLine('Authorization'))->toBe('Bearer secret-token');
});

it('sends a Basic Authorization header', function () {
    $client = makeClient([new Response(200, [], '{}')], $history, Auth::basic('user@example.com', 'api-token'));

    $client->get('user');

    /** @var Request $request */
    $request = $history[0]['request'];
    $expected = 'Basic '.base64_encode('user@example.com:api-token');
    expect($request->getHeaderLine('Authorization'))->toBe($expected);
});

it('sends no Authorization header when unauthenticated', function () {
    $client = makeClient([new Response(200, [], '{}')], $history);

    $client->get('public');

    /** @var Request $request */
    $request = $history[0]['request'];
    expect($request->hasHeader('Authorization'))->toBeFalse();
});
