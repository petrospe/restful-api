<?php

declare(strict_types=1);

use RestFullApi\Client\RestClientRequest;
use RestFullApi\Http\Request;
use RestFullApi\Http\Response;
use RestFullApi\Http\Router;
use RestFullApi\Storage\JsonUserRepository;
use RestFullApi\User\UserController;

require __DIR__ . '/../src/autoload.php';

final class TestRunner
{
    /** @var list<string> */
    private array $failures = [];

    public function test(string $name, callable $callback): void
    {
        try {
            $callback();
            echo ".";
        } catch (Throwable $exception) {
            $this->failures[] = $name . ': ' . $exception->getMessage();
            echo "F";
        }
    }

    public function finish(): void
    {
        echo PHP_EOL;

        if ($this->failures === []) {
            echo "All tests passed." . PHP_EOL;
            return;
        }

        foreach ($this->failures as $failure) {
            echo "- " . $failure . PHP_EOL;
        }

        exit(1);
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$runner = new TestRunner();

$runner->test('parses JSON request body and negotiated format', function (): void {
    $request = Request::fromArrays(
        server: [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/users.json?debug=1',
            'HTTP_ACCEPT' => 'application/xml',
            'CONTENT_TYPE' => 'application/json',
        ],
        query: ['debug' => '1'],
        post: [],
        rawBody: '{"name":"Ada Lovelace","email":"ada@example.test"}'
    );

    assertSameValue('POST', $request->method());
    assertSameValue('/api/users', $request->path());
    assertSameValue('json', $request->format());
    assertSameValue('Ada Lovelace', $request->json()['name'] ?? null);
});

$runner->test('serializes JSON responses with HTTP metadata', function (): void {
    $response = Response::json(['id' => 10], 201);

    assertSameValue(201, $response->status());
    assertSameValue('Created', $response->reasonPhrase());
    assertSameValue('application/json; charset=utf-8', $response->headers()['Content-Type'] ?? null);
    assertSameValue('{"id":10}', $response->body());
});

$runner->test('routes users CRUD requests through controller', function (): void {
    $storage = sys_get_temp_dir() . '/epoptia-restfull-api-users-' . bin2hex(random_bytes(6)) . '.json';
    $repository = new JsonUserRepository($storage);
    $controller = new UserController($repository);
    $router = new Router();
    $controller->registerRoutes($router);

    $create = $router->dispatch(Request::fromArrays(
        server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/users', 'CONTENT_TYPE' => 'application/json'],
        query: [],
        post: [],
        rawBody: '{"name":"Grace Hopper","email":"grace@example.test"}'
    ));

    assertSameValue(201, $create->status());
    $created = json_decode($create->body(), true, flags: JSON_THROW_ON_ERROR);
    assertSameValue(1, $created['data']['id'] ?? null);

    $show = $router->dispatch(Request::fromArrays(
        server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/users/1'],
        query: [],
        post: [],
        rawBody: ''
    ));

    assertSameValue(200, $show->status());
    $shown = json_decode($show->body(), true, flags: JSON_THROW_ON_ERROR);
    assertSameValue('Grace Hopper', $shown['data']['name'] ?? null);

    $delete = $router->dispatch(Request::fromArrays(
        server: ['REQUEST_METHOD' => 'DELETE', 'REQUEST_URI' => '/api/users/1'],
        query: [],
        post: [],
        rawBody: ''
    ));

    assertSameValue(204, $delete->status());
    @unlink($storage);
});

$runner->test('builds cURL request bodies for POST and PUT', function (): void {
    $request = new RestClientRequest('https://example.test/api/users', 'POST', [
        'name' => 'Alan Turing',
        'email' => 'alan@example.test',
    ]);

    assertSameValue('name=Alan+Turing&email=alan%40example.test', $request->requestBody());
    assertSameValue(strlen($request->requestBody()), $request->requestLength());

    $request->flush();
    $request->setVerb('PUT');
    $request->setRequestBody(['name' => 'Alan M. Turing']);
    $request->buildPostBody();

    assertSameValue('name=Alan+M.+Turing', $request->requestBody());
});

$runner->test('returns 404 for unknown routes', function (): void {
    $router = new Router();
    $response = $router->dispatch(Request::fromArrays(
        server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/missing'],
        query: [],
        post: [],
        rawBody: ''
    ));

    assertSameValue(404, $response->status());
    assertTrueValue(str_contains($response->body(), 'Route not found'), 'Expected not-found response body.');
});

$runner->finish();
