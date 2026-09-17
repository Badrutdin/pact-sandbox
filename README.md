# Pact sandbox: order-service (consumer) + user-service (provider)

Две крошечные PHP-службы и настоящий Pact Broker, чтобы руками пройти весь цикл
контрактного тестирования.

```
order-service (consumer)                     user-service (provider)
  UserClient::getUser(42)                      GET /users/{id}
        |                                            ^
        | 1. тест гоняет клиент в pact mock          | 3. verifier шлёт записанные
        v                                            |    запросы в живой сервис
  pacts/order-service-user-service.json  --2-->  Pact Broker  --4--> can-i-deploy
        (контракт)                             (хранит контракты,
                                                результаты и кто где задеплоен)
```

## Что где лежит

| Файл | Роль |
|---|---|
| `consumer/src/UserClient.php` | настоящий HTTP-клиент; именно он проверяется в pact-тесте |
| `consumer/tests/UserClientPactTest.php` | consumer-сторона: описание interactions, matchers |
| `consumer/pacts/*.json` | сгенерированный контракт |
| `provider/public/index.php` | сам user-service + эндпоинт provider states |
| `provider/verify/verify.php` | provider-сторона: забирает контракты из брокера и проверяет себя |
| `docker-compose.yml` | брокер + postgres + оба сервиса + pact-cli |

## Запуск

```bash
make init     # собрать образ, поднять брокер/провайдера, composer install
make demo     # весь цикл: тест -> publish -> verify -> record-deployment -> can-i-deploy
```

Broker UI: http://localhost:9292 (`pact` / `pact`).

## Цикл по шагам

```bash
make consumer-test   # 1. pact-тест консьюмера пишет contract в consumer/pacts/
make pact            #    посмотреть, что получилось
make publish         # 2. контракт уезжает в брокер (версия 1.0.0, ветка main)
make verify          # 3. provider поднимается и проверяет себя по контракту из брокера
make record-deployment  # 4. отметить, что обе версии уехали в production
make can-i-deploy    # 5. можно ли выкатывать консьюмера
make matrix          #    матрица совместимости версий
```

## Главный эксперимент: ломающее изменение

```bash
make break     # provider переименовывает поле name -> full_name
make verify    # PACT VERIFICATION FAILED: "Actual map is missing the following keys: name"
make fix       # вернуть как было
```

Именно это Pact и покупает: провайдер узнаёт о поломке у консьюмера на своей
сборке, до деплоя, без поднятого консьюмера.

## Что стоит потрогать руками

1. **Толерантность.** Добавь в ответ провайдера новое поле (`"phone" => "123"`)
   и запусти `make verify`. Пройдёт: лишние поля игнорируются, поэтому добавлять
   поля безопасно, а удалять - нет.
2. **Матчеры.** В `UserClientPactTest` поменяй `$this->matcher->like('Ann')` на
   голую строку `'Ann'`, потом в provider states сохрани другое имя. Верификация
   упадёт на сравнении значений: в контракт нужно писать форму, а не данные.
3. **Pending pacts.** Добавь в тест новую interaction (`/users/7`), опубликуй с
   новой версией консьюмера и запусти `make verify` без реализации на провайдере.
   Первый раз это не уронит сборку провайдера - контракт в pending.
4. **can-i-deploy без задеплоенного консьюмера.** Пока `order-service` не записан
   в production, can-i-deploy для провайдера отвечает "yes": проверять нечего.
   Зависимости появляются только после `record-deployment`.
5. **Provider state с параметрами.** `given('user exists', ['id' => 42])` вместо
   захардкоженного `user 42 exists` - и обработка `params` в `/_pact/provider_states`.

## Как это переносится на реальный проект

- Один сервис обычно выступает в обеих ролях сразу: провайдером для тех, кто ходит
  в его API, и консьюмером там, где сам ходит HTTP-клиентом в соседние сервисы.
  В брокере это один участник с одним именем.
- В образ нужен `ext-ffi`: pact-php работает через FFI поверх Rust-ядра Pact
  (см. `Dockerfile`).
- Самая трудоёмкая часть в реальном сервисе - не interactions, а provider states:
  подготовка данных в тестовой БД под каждое `given(...)`.
- Имя участника это имя деплой-юнита, а не класса клиента. Выбирается один раз:
  вся история верификаций и деплоев висит на этой строке.
