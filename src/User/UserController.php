<?php

declare(strict_types=1);

namespace RestFullApi\User;

use RestFullApi\Http\Request;
use RestFullApi\Http\Response;
use RestFullApi\Http\Router;
use RestFullApi\Storage\JsonUserRepository;

final readonly class UserController
{
    public function __construct(private JsonUserRepository $repository)
    {
    }

    public function registerRoutes(Router $router): void
    {
        $router->add('GET', '/api/users', $this->index(...));
        $router->add('GET', '/api/users/{id}', $this->show(...));
        $router->add('POST', '/api/users', $this->create(...));
        $router->add('PUT', '/api/users/{id}', $this->update(...));
        $router->add('DELETE', '/api/users/{id}', $this->delete(...));
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->payload($request, ['data' => $this->repository->all()]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $user = $this->repository->find($id);

        if ($user === null) {
            return Response::error(404, 'User not found.');
        }

        return $this->payload($request, ['data' => $user]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        $attributes = $this->validate($request->input(), requireAll: true);

        if (isset($attributes['error'])) {
            return Response::error(422, $attributes['error']);
        }

        return $this->payload($request, ['data' => $this->repository->create($attributes)], 201);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $attributes = $this->validate($request->input(), requireAll: false);

        if (isset($attributes['error'])) {
            return Response::error(422, $attributes['error']);
        }

        $user = $this->repository->update($this->id($params), $attributes);

        if ($user === null) {
            return Response::error(404, 'User not found.');
        }

        return $this->payload($request, ['data' => $user]);
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        if (!$this->repository->delete($this->id($params))) {
            return Response::error(404, 'User not found.');
        }

        return new Response(204);
    }

    /** @param array<string, mixed> $payload */
    private function payload(Request $request, array $payload, int $status = 200): Response
    {
        if ($request->format() === 'xml') {
            return Response::xml($payload, $status);
        }

        return Response::json($payload, $status);
    }

    /** @param array<string, string> $params */
    private function id(array $params): int
    {
        return isset($params['id']) && ctype_digit($params['id']) ? (int) $params['id'] : 0;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, email: string}|array{name?: string, email?: string}|array{error: string}
     */
    private function validate(array $input, bool $requireAll): array
    {
        $attributes = [];

        if (isset($input['name']) && is_string($input['name']) && trim($input['name']) !== '') {
            $attributes['name'] = trim($input['name']);
        } elseif ($requireAll) {
            return ['error' => 'The name field is required.'];
        }

        if (isset($input['email']) && is_string($input['email']) && filter_var($input['email'], FILTER_VALIDATE_EMAIL) !== false) {
            $attributes['email'] = trim($input['email']);
        } elseif ($requireAll) {
            return ['error' => 'A valid email field is required.'];
        }

        if (!$requireAll && $attributes === []) {
            return ['error' => 'At least one updatable field is required.'];
        }

        return $attributes;
    }
}
