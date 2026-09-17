<?php

declare(strict_types=1);

namespace UserService;

/**
 * Вместо БД - json-файл. Для песочницы важно только то, что provider state
 * умеет подготовить данные перед проверкой interaction.
 */
final class UserRepository
{
    private const STORAGE = '/tmp/pact-users.json';

    /** @return array{id: int, name: string, email: string}|null */
    public function find(int $id): ?array
    {
        return $this->all()[(string) $id] ?? null;
    }

    public function save(int $id, string $name, string $email): void
    {
        $users              = $this->all();
        $users[(string) $id] = ['id' => $id, 'name' => $name, 'email' => $email];
        $this->write($users);
    }

    public function delete(int $id): void
    {
        $users = $this->all();
        unset($users[(string) $id]);
        $this->write($users);
    }

    /** @return array<string, array{id: int, name: string, email: string}> */
    private function all(): array
    {
        if (!file_exists(self::STORAGE)) {
            return [];
        }

        return json_decode((string) file_get_contents(self::STORAGE), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, array{id: int, name: string, email: string}> $users */
    private function write(array $users): void
    {
        file_put_contents(self::STORAGE, json_encode($users, JSON_THROW_ON_ERROR));
    }
}
