<?php

declare(strict_types=1);

namespace RestFullApi\Http;

final class RestUtils
{
    public static function processRequest(): Request
    {
        return Request::fromGlobals();
    }

    /** @param array<string, mixed>|string $body */
    public static function sendResponse(int $status = 200, array|string $body = '', string $contentType = 'text/html; charset=utf-8'): never
    {
        $responseBody = is_array($body)
            ? json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $body;

        (new Response($status, $responseBody, ['Content-Type' => $contentType]))->send();
    }

    public static function getStatusCodeMessage(int $status): string
    {
        return StatusCodes::reasonPhrase($status);
    }
}
