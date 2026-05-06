<?php

declare(strict_types=1);

namespace RestFullApi\Storage;

use RuntimeException;

final class JsonUserRepository
{
    public function __construct(private readonly string $path)
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create data directory.');
        }

        if (!is_file($this->path)) {
            $this->write(['nextId' => 1, 'users' => []]);
        }
    }

    /** @return list<array{id: int, name: string, email: string}> */
    public function all(): array
    {
        return array_values($this->read()['users']);
    }

    /** @return array{id: int, name: string, email: string}|null */
    public function find(int $id): ?array
    {
        foreach ($this->read()['users'] as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }

        return null;
    }

    /** @param array{name: string, email: string} $attributes */
    public function create(array $attributes): array
    {
        $data = $this->read();
        $user = [
            'id' => $data['nextId'],
            'name' => $attributes['name'],
            'email' => $attributes['email'],
        ];

        $data['users'][] = $user;
        $data['nextId']++;
        $this->write($data);

        return $user;
    }

    /** @param array{name?: string, email?: string} $attributes */
    public function update(int $id, array $attributes): ?array
    {
        $data = $this->read();

        foreach ($data['users'] as $index => $user) {
            if ($user['id'] !== $id) {
                continue;
            }

            if (array_key_exists('name', $attributes)) {
                $user['name'] = $attributes['name'];
            }

            if (array_key_exists('email', $attributes)) {
                $user['email'] = $attributes['email'];
            }

            $data['users'][$index] = $user;
            $this->write($data);

            return $user;
        }

        return null;
    }

    public function delete(int $id): bool
    {
        $data = $this->read();
        $initialCount = count($data['users']);
        $data['users'] = array_values(array_filter(
            $data['users'],
            static fn (array $user): bool => $user['id'] !== $id,
        ));

        if (count($data['users']) === $initialCount) {
            return false;
        }

        $this->write($data);

        return true;
    }

    /**
     * @return array{nextId: int, users: list<array{id: int, name: string, email: string}>}
     */
    private function read(): array
    {
        $contents = file_get_contents($this->path);
        $decoded = json_decode(is_string($contents) ? $contents : '', true);

        if (!is_array($decoded)) {
            return ['nextId' => 1, 'users' => []];
        }

        $nextId = isset($decoded['nextId']) && is_int($decoded['nextId']) ? $decoded['nextId'] : 1;
        $users = [];

        if (isset($decoded['users']) && is_array($decoded['users'])) {
            foreach ($decoded['users'] as $user) {
                if (
                    is_array($user)
                    && isset($user['id'], $user['name'], $user['email'])
                    && is_int($user['id'])
                    && is_string($user['name'])
                    && is_string($user['email'])
                ) {
                    $users[] = $user;
                }
            }
        }

        return ['nextId' => $nextId, 'users' => $users];
    }

    /** @param array{nextId: int, users: list<array{id: int, name: string, email: string}>} $data */
    private function write(array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($this->path, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write users data.');
        }
    }
}
