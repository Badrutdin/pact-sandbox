<?php

declare(strict_types=1);

namespace OrderService;

/**
 * Настоящий HTTP-клиент order-service к user-service.
 * Именно этот класс проверяется в pact-тесте: в тесте он ходит не в реальный
 * user-service, а в mock-сервер, поднятый Pact-ом.
 */
final readonly class UserClient
{
    public function __construct(private string $baseUrl)
    {
    }

    public function getUser(int $id): ?User
    {
        $ch = curl_init(sprintf('%s/users/%d', $this->baseUrl, $id));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_TIMEOUT        => 5,
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (404 === $status) {
            return null;
        }

        if (200 !== $status) {
            throw new \RuntimeException("user-service responded with {$status}");
        }

        /** @var array{id: int, name: string, email: string} $data */
        $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);

        // Клиент читает ровно три поля - ровно они и попадут в контракт.
        return new User($data['id'], $data['name'], $data['email']);
    }
}
