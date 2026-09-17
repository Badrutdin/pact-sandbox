<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Psr7\Uri;
use PhpPact\Standalone\ProviderVerifier\Model\Config\PublishOptions;
use PhpPact\Standalone\ProviderVerifier\Model\Source\Broker;
use PhpPact\Standalone\ProviderVerifier\Model\VerifierConfig;
use PhpPact\Standalone\ProviderVerifier\Verifier;

/**
 * PROVIDER-сторона контракта.
 *
 * Забирает из брокера все контракты, где user-service указан провайдером, и для
 * каждой interaction: ставит provider state -> шлёт записанный запрос в живой
 * user-service -> сравнивает ответ с ожиданием консьюмера.
 */
$providerVersion = getenv('PROVIDER_VERSION') ?: 'dev';
$providerHost    = getenv('PROVIDER_HOST') ?: 'provider';
$brokerUrl       = getenv('PACT_BROKER_BASE_URL') ?: 'http://broker:9292';

$config = new VerifierConfig();
$config->getProviderInfo()
    ->setName('user-service')
    ->setScheme('http')
    ->setHost($providerHost)
    ->setPort(8000);

// Куда слать "приведи себя в состояние X" перед каждой interaction.
$config->getProviderState()
    ->setStateChangeUrl(new Uri(sprintf('http://%s:8000/_pact/provider_states', $providerHost)))
    ->setStateChangeAsBody(true);

// Результат проверки уезжает обратно в брокер: он и есть источник правды для can-i-deploy.
$publishOptions = new PublishOptions();
$publishOptions
    ->setProviderVersion($providerVersion)
    ->setProviderBranch('main');
$config->setPublishOptions($publishOptions);

$broker = new Broker();
$broker
    ->setUrl(new Uri($brokerUrl))
    ->setUsername(getenv('PACT_BROKER_USERNAME') ?: 'pact')
    ->setPassword(getenv('PACT_BROKER_PASSWORD') ?: 'pact');
// Новый, ещё ни разу не прошедший контракт не роняет сборку провайдера.
$broker->setEnablePending(true);

$verifier = new Verifier($config);
$verifier->addBroker($broker);

$success = $verifier->verify();

echo $success ? "\nPACT VERIFICATION OK\n" : "\nPACT VERIFICATION FAILED\n";

exit($success ? 0 : 1);
