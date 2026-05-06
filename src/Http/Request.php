<?php

declare(strict_types=1);

namespace RestFullApi\Http;

use JsonException;

final readonly class Request
{
    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $form
     * @param array<string, mixed>|null $json
     */
    private function __construct(
        private string $method,
        private string $path,
        private string $format,
        private array $server,
        private array $query,
        private array $form,
        private string $rawBody,
        private ?array $json,
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    public static function fromArrays(array $server, array $query, array $post, string $rawBody): self
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $format = self::detectFormat($path, (string) ($server['HTTP_ACCEPT'] ?? ''));
        $path = preg_replace('/\.(json|xml)$/i', '', $path) ?? $path;
        $path = '/' . trim($path, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        return new self(
            method: $method,
            path: $path,
            format: $format,
            server: $server,
            query: $query,
            form: self::parseForm($method, $post, $rawBody, (string) ($server['CONTENT_TYPE'] ?? '')),
            rawBody: $rawBody,
            json: self::parseJson($rawBody, (string) ($server['CONTENT_TYPE'] ?? '')),
        );
    }

    public static function fromGlobals(): self
    {
        return self::fromArrays(
            server: $_SERVER,
            query: $_GET,
            post: $_POST,
            rawBody: file_get_contents('php://input') ?: '',
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function format(): string
    {
        return $this->format;
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        return $this->query;
    }

    /** @return array<string, mixed> */
    public function form(): array
    {
        return $this->form;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return $this->json ?? [];
    }

    /** @return array<string, mixed> */
    public function input(): array
    {
        return $this->json ?? $this->form;
    }

    /** @return array<string, mixed> */
    public function server(): array
    {
        return $this->server;
    }

    private static function detectFormat(string $path, string $accept): string
    {
        if (preg_match('/\.xml$/i', $path) === 1) {
            return 'xml';
        }

        if (preg_match('/\.json$/i', $path) === 1) {
            return 'json';
        }

        if (str_contains(strtolower($accept), 'xml')) {
            return 'xml';
        }

        return 'json';
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private static function parseForm(string $method, array $post, string $rawBody, string $contentType): array
    {
        if ($method === 'POST') {
            return $post;
        }

        if (!in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
            return [];
        }

        if (!str_contains(strtolower($contentType), 'application/x-www-form-urlencoded')) {
            return [];
        }

        parse_str($rawBody, $parsed);

        return is_array($parsed) ? $parsed : [];
    }

    /** @return array<string, mixed>|null */
    private static function parseJson(string $rawBody, string $contentType): ?array
    {
        if ($rawBody === '' || !str_contains(strtolower($contentType), 'application/json')) {
            return null;
        }

        try {
            $decoded = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
