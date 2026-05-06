<?php

declare(strict_types=1);

namespace RestFullApi\Http;

use Closure;
use Throwable;

final class Router
{
    /** @var list<array{method: string, pattern: string, handler: Closure(Request, array<string, string>): Response}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $this->compilePattern($pattern),
            'handler' => Closure::fromCallable($handler),
        ];
    }

    public function dispatch(Request $request): Response
    {
        $methodAllowed = false;

        foreach ($this->routes as $route) {
            $matches = [];

            if (preg_match($route['pattern'], $request->path(), $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $request->method()) {
                $methodAllowed = true;
                continue;
            }

            try {
                return $route['handler']($request, $this->namedMatches($matches));
            } catch (Throwable $exception) {
                return Response::error(500, $exception->getMessage());
            }
        }

        if ($methodAllowed) {
            return Response::error(405, 'Method not allowed for this route.');
        }

        return Response::error(404, 'Route not found.');
    }

    private function compilePattern(string $pattern): string
    {
        $quoted = preg_quote('/' . trim($pattern, '/'), '#');
        $regex = preg_replace('#\\\\\{([A-Za-z_][A-Za-z0-9_]*)\\\\\}#', '(?P<$1>[^/]+)', $quoted);

        return '#^' . ($regex ?? $quoted) . '$#';
    }

    /**
     * @param array<string|int, string> $matches
     * @return array<string, string>
     */
    private function namedMatches(array $matches): array
    {
        $named = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $named[$key] = $value;
            }
        }

        return $named;
    }
}
