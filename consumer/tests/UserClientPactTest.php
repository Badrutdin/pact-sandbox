<?php

declare(strict_types=1);

namespace OrderService\Tests;

use OrderService\UserClient;
use PhpPact\Consumer\InteractionBuilder;
use PhpPact\Consumer\Matcher\Matcher;
use PhpPact\Consumer\Model\ConsumerRequest;
use PhpPact\Consumer\Model\ProviderResponse;
use PhpPact\Standalone\MockService\MockServerConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * CONSUMER-сторона контракта.
 *
 * Тут проверяется НЕ user-service, а наш собственный UserClient:
 *  1. Pact поднимает mock-сервер, который отвечает так, как мы описали;
 *  2. настоящий UserClient делает в него настоящий HTTP-запрос;
 *  3. если запрос совпал с ожидаемым - тест зелёный, и Pact пишет pacts/*.json.
 *
 * Этот json потом прогоняется против настоящего user-service (см. provider/verify).
 */
#[CoversClass(UserClient::class)]
final class UserClientPactTest extends TestCase
{
    private MockServerConfig $config;
    private InteractionBuilder $builder;
    private Matcher $matcher;

    protected function setUp(): void
    {
        $this->config = new MockServerConfig();
        $this->config
            ->setConsumer('order-service')
            ->setProvider('user-service')
            ->setPactDir(__DIR__ . '/../pacts')
            ->setPactSpecificationVersion('3.0.0');
        $this->config->setHost('127.0.0.1')->setPort(7200);

        $this->builder = new InteractionBuilder($this->config);
        $this->matcher = new Matcher();
    }

    public function testGetExistingUser(): void
    {
        $request = new ConsumerRequest();
        $request
            ->setMethod('GET')
            ->setPath('/users/42')
            ->addHeader('Accept', 'application/json');

        $response = new ProviderResponse();
        $response
            ->setStatus(200)
            ->addHeader('Content-Type', 'application/json')
            // В контракт пишем ФОРМУ, а не конкретные значения:
            // provider волен вернуть любое имя, лишь бы это была строка.
            ->setBody([
                'id'    => $this->matcher->integer(42),
                'name'  => $this->matcher->like('Ann'),
                'email' => $this->matcher->email('ann@example.com'),
            ]);

        $this->builder
            // provider state: предусловие, которое user-service обязан себе создать
            ->given('user 42 exists')
            ->uponReceiving('a request for user 42')
            ->with($request)
            ->willRespondWith($response);

        $client = new UserClient('http://127.0.0.1:7200');
        $user   = $client->getUser(42);

        self::assertNotNull($user);
        self::assertSame(42, $user->id);
        self::assertSame('Ann', $user->name);

        // verify() = "mock-сервер получил ровно то, что мы обещали". Без него pact не пишется.
        self::assertTrue($this->builder->verify());
    }

    public function testGetMissingUserReturnsNull(): void
    {
        $request = new ConsumerRequest();
        $request
            ->setMethod('GET')
            ->setPath('/users/99')
            ->addHeader('Accept', 'application/json');

        $response = new ProviderResponse();
        $response->setStatus(404);

        $this->builder
            ->given('user 99 does not exist')
            ->uponReceiving('a request for a missing user')
            ->with($request)
            ->willRespondWith($response);

        $client = new UserClient('http://127.0.0.1:7200');

        self::assertNull($client->getUser(99));
        self::assertTrue($this->builder->verify());
    }
}
