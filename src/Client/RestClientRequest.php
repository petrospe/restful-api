<?php

declare(strict_types=1);

namespace RestFullApi\Client;

use InvalidArgumentException;
use RuntimeException;

final class RestClientRequest
{
    private ?string $requestBody = null;
    private int $requestLength = 0;
    private ?string $username = null;
    private ?string $password = null;
    private string $acceptType = 'application/json';
    private ?string $responseBody = null;
    /** @var array<string, mixed>|null */
    private ?array $responseInfo = null;

    /** @param array<string, scalar|null>|string|null $requestBody */
    public function __construct(
        private string $url,
        private string $verb = 'GET',
        array|string|null $requestBody = null,
    ) {
        if ($requestBody !== null) {
            $this->setRequestBody($requestBody);
            $this->buildPostBody();
        }
    }

    public function flush(): void
    {
        $this->requestBody = null;
        $this->requestLength = 0;
        $this->verb = 'GET';
        $this->responseBody = null;
        $this->responseInfo = null;
    }

    public function execute(): void
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The curl extension is required to execute REST client requests.');
        }

        $curlHandle = curl_init();

        if ($curlHandle === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        $this->setAuth($curlHandle);

        try {
            match (strtoupper($this->verb)) {
                'GET' => $this->executeGet($curlHandle),
                'POST' => $this->executePost($curlHandle),
                'PUT' => $this->executePut($curlHandle),
                'DELETE' => $this->executeDelete($curlHandle),
                default => throw new InvalidArgumentException('Current verb (' . $this->verb . ') is an invalid REST verb.'),
            };
        } catch (\Throwable $exception) {
            curl_close($curlHandle);
            throw $exception;
        }
    }

    /** @param array<string, scalar|null>|string|null $data */
    public function buildPostBody(array|string|null $data = null): void
    {
        $body = $data ?? $this->requestBody;

        if (is_array($body)) {
            $body = http_build_query($body, '', '&');
        }

        if (!is_string($body)) {
            throw new InvalidArgumentException('Invalid data input for request body. Array or string expected.');
        }

        $this->requestBody = $body;
        $this->requestLength = strlen($body);
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function setVerb(string $verb): void
    {
        $this->verb = strtoupper($verb);
    }

    /** @param array<string, scalar|null>|string $requestBody */
    public function setRequestBody(array|string $requestBody): void
    {
        $this->requestBody = is_array($requestBody)
            ? http_build_query($requestBody, '', '&')
            : $requestBody;
        $this->requestLength = strlen($this->requestBody);
    }

    public function setCredentials(string $username, string $password): void
    {
        $this->username = $username;
        $this->password = $password;
    }

    public function setAcceptType(string $acceptType): void
    {
        $this->acceptType = $acceptType;
    }

    public function requestBody(): string
    {
        return $this->requestBody ?? '';
    }

    public function requestLength(): int
    {
        return $this->requestLength;
    }

    public function responseBody(): ?string
    {
        return $this->responseBody;
    }

    /** @return array<string, mixed>|null */
    public function responseInfo(): ?array
    {
        return $this->responseInfo;
    }

    /** @param \CurlHandle $curlHandle */
    private function executeGet(\CurlHandle $curlHandle): void
    {
        $this->doExecute($curlHandle);
    }

    /** @param \CurlHandle $curlHandle */
    private function executePost(\CurlHandle $curlHandle): void
    {
        if ($this->requestBody === null) {
            $this->buildPostBody('');
        }

        curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $this->requestBody);
        curl_setopt($curlHandle, CURLOPT_POST, true);

        $this->doExecute($curlHandle);
    }

    /** @param \CurlHandle $curlHandle */
    private function executePut(\CurlHandle $curlHandle): void
    {
        if ($this->requestBody === null) {
            $this->buildPostBody('');
        }

        curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $this->requestBody);

        $this->doExecute($curlHandle);
    }

    /** @param \CurlHandle $curlHandle */
    private function executeDelete(\CurlHandle $curlHandle): void
    {
        curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, 'DELETE');

        $this->doExecute($curlHandle);
    }

    /** @param \CurlHandle $curlHandle */
    private function doExecute(\CurlHandle $curlHandle): void
    {
        $this->setCurlOpts($curlHandle);
        $result = curl_exec($curlHandle);

        if ($result === false) {
            throw new RuntimeException(curl_error($curlHandle));
        }

        $this->responseBody = is_string($result) ? $result : '';
        $this->responseInfo = curl_getinfo($curlHandle);

        curl_close($curlHandle);
    }

    /** @param \CurlHandle $curlHandle */
    private function setCurlOpts(\CurlHandle $curlHandle): void
    {
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 10);
        curl_setopt($curlHandle, CURLOPT_URL, $this->url);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, ['Accept: ' . $this->acceptType]);
    }

    /** @param \CurlHandle $curlHandle */
    private function setAuth(\CurlHandle $curlHandle): void
    {
        if ($this->username === null || $this->password === null) {
            return;
        }

        curl_setopt($curlHandle, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($curlHandle, CURLOPT_USERPWD, $this->username . ':' . $this->password);
    }
}
