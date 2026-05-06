<?php

declare(strict_types=1);

namespace RestFullApi\Http;

use JsonException;

final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        private int $status,
        private string $body = '',
        private array $headers = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            $body = json_encode([
                'error' => 'Response encoding failed.',
                'detail' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR);
            $status = 500;
        }

        return new self($status, $status === 204 ? '' : $body, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    /** @param array<string, mixed> $payload */
    public static function xml(array $payload, int $status = 200, string $root = 'response'): self
    {
        return new self($status, self::toXml($payload, $root), [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }

    public static function error(int $status, string $message): self
    {
        return self::json([
            'error' => [
                'status' => $status,
                'message' => $message,
            ],
        ], $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function reasonPhrase(): string
    {
        return StatusCodes::reasonPhrase($this->status);
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): never
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->body !== '') {
            echo $this->body;
        }

        exit;
    }

    /** @param array<string, mixed> $payload */
    private static function toXml(array $payload, string $root): string
    {
        $xml = new \SimpleXMLElement('<' . $root . '/>');
        self::appendXml($xml, $payload);

        return $xml->asXML() ?: '';
    }

    /** @param array<string, mixed> $payload */
    private static function appendXml(\SimpleXMLElement $xml, array $payload): void
    {
        foreach ($payload as $key => $value) {
            $elementName = is_string($key) && preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $key) === 1 ? $key : 'item';

            if (is_array($value)) {
                $child = $xml->addChild($elementName);
                self::appendXml($child, $value);
                continue;
            }

            $xml->addChild($elementName, htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
        }
    }
}
