<?php

declare(strict_types=1);

require __DIR__ . '/../src/UserRepository.php';

use UserService\UserRepository;

$repository = new UserRepository();
$path       = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method     = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json');

/*
 * Эндпоинт provider states. Его вызывает Pact-верификатор ПЕРЕД каждой
 * interaction: "приведи себя в состояние given(...)".
 * В реальном сервисе тут создают записи в БД тестового окружения.
 */
if ('/_pact/provider_states' === $path && 'POST' === $method) {
    $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);

    match ($payload['state'] ?? null) {
        'user 42 exists'          => $repository->save(42, 'Ann', 'ann@example.com'),
        'user 99 does not exist'  => $repository->delete(99),
        default                   => null,
    };

    echo json_encode(['result' => 'ok']);

    return;
}

if (preg_match('#^/users/(\d+)$#', (string) $path, $matches) && 'GET' === $method) {
    $user = $repository->find((int) $matches[1]);

    if (null === $user) {
        http_response_code(404);
        echo json_encode(['error' => 'user not found']);

        return;
    }

    /*
     * BREAK_CONTRACT=1 переименовывает поле name -> full_name.
     * Для клиента это ломающее изменение, и верификация контракта это поймает.
     */
    if ('1' === getenv('BREAK_CONTRACT')) {
        $user['full_name'] = $user['name'];
        unset($user['name']);
    }

    echo json_encode($user);

    return;
}

http_response_code(404);
echo json_encode(['error' => 'route not found']);
