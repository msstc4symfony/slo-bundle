# Интеграции

- `msstc4symfony/metrics-bundle` `^2.3` — хранилище и экспорт счётчика `symfony_slo_events_total`.
- Мосты `slo-bridge-logic` / `slo-bridge-profiling` — отдельные пакеты, подключают свои точки через тег
  `msstc4symfony_slo.point` (атрибуты `type` = значение `PointType`, `id`, опционально `metric_label`); pass моста —
  приоритет >= 10 в `TYPE_BEFORE_OPTIMIZATION`. Discovery — приоритет -10 (с rc.2): `RegisterLogicMetricsPass`
  metrics-bridge-logic добавляет гистограмму `logic_action_duration_histogram_seconds` в `metric_enums` на приоритете 0, и при
  discovery на 0 бандл, зарегистрированный после slo-bundle, отрабатывал позже — logic-SLO молча уходили в dedicated
  (`BridgeExtensionPointsTest::testDiscoverySeesMetricEnumsAddedByLaterBundles`). `metric_label`, общий для нескольких
  точек одного типа, сбрасывается (их SLO — dedicated, warning); у одной точки — одно значение метки, иначе ошибка компиляции.
- `ExpectedFailureClassifierInterface` — публичный API с rc.2 (приватный алиас на выбранный адаптер, автовайринг работает):
  декоратор `OperationMonitorInterface` в slo-bridge-profiling классифицирует исключения как `measure()`, не завися от
  logic-bundle. Декорирование публичного алиаса `OperationMonitorInterface` (`.inner` = `OperationMonitor`) закреплено тестом.

## Что discovery читает из чужих бандлов (Task 5)

- Messenger (FrameworkExtension): маршрутизация — аргумент 0 `messenger.senders_locator` (тип сообщения → имена
  sender'ов; ключи — класс, родители, интерфейсы, `Ns\*`, `*` как в `SendersLocator`, sender'ы объединяются); если ни один
  не совпал — как `SendersLocator`, смотрим `#[AsMessage(transport: ...)]` (IS_INSTANCEOF) на классе, родителях и
  интерфейсах. Транспорты — теги `messenger.receiver` (`alias`, `is_failure_transport`, `is_consumable: false` у
  `sync://`; DSN из env FrameworkExtension не видит → считается worker-транспортом). Ресиверы без `alias` пропускаются
  (консервативно: не дают receive-only). Префикс `messenger.transport.` срезается и у sender'ов. Без определения
  `messenger.senders_locator` — всегда false.
- Scheduler: `AddScheduleMessengerPass` (FrameworkBundle, BEFORE_OPTIMIZATION 0 — раньше нашего -10) регистрирует
  `messenger.transport.scheduler_<name>` с тегом `messenger.receiver` только с `alias` (без `is_consumable`) и DSN
  `schedule://<name>` в аргументе 0 (проверено по symfony/symfony 7.4, `gh api`). Такие транспорты в receive-only не
  входят, иначе любой монолит со Scheduler делал бы все незамаршрутизированные handlers async.
- Handler async (rc.4, `MessengerRouting::isAsync`): (1) `from_transport` → async, если этот транспорт не `sync://` и не
  failure (неизвестное имя → async); (2) сообщение маршрутизировано → async, если хоть один sender не `sync://` (чужой
  sender-сервис → async); (3) не маршрутизировано → async, если в приложении есть receive-only транспорт (не `sync://`, не
  failure, не цель ни одной записи routing и ни одного `#[AsMessage]` обрабатываемых сообщений) и сообщение не из
  `Symfony\` (RunCommand/Redispatch/RunProcess приложение шлёт само, иначе в consumer-приложении они стали бы
  unmonitored). Receive-only — не `sync://`, не failure, не `schedule://`. Считается в `fromContainer($container,
  $failureTransports, $messages)`: `HandlerReader::read($container, $failureTransports)` сначала собирает все handlers,
  потом строит `MessengerRouting` (одна фаза у объекта, временной связности нет). Sender, который является failure
  транспортом, тоже не worker.
  Failure-транспорты = флаг тега ∪ результат `failureTransports()` (с учётом `defaults.messenger.failure_transports`).
- Failure-транспорты: явный `defaults.messenger.failure_transports` (не null) побеждает; иначе определение
  `messenger.failure.send_failed_message_to_failure_transport_listener`: аргумент 2 (транспорт → имя failure-транспорта)
  и локатор в аргументе 0 (`ServiceClosureArgument(Reference('messenger.transport.<name>'))`), префикс срезается.
  Если failure-транспорта нет, FrameworkExtension удаляет определение → `[]`. Проверено реальным ядром
  (`tests/Integration/Kernel/MessengerTestKernel.php`).
- Handlers: тег `messenger.message_handler` (`handles`, иначе тип первого параметра `method`/`__invoke`, union → несколько
  сообщений; `from_transport`). В `extras()->handlers` попадают и handlers самого Symfony (`RunCommandMessage`,
  `RedispatchMessage`, `RunProcessMessage`).
- metrics-bundle: `msstc4symfony_metrics.metric_enums` (сейчас уже содержит `BuiltInMetric`; добавляем его и сами на
  случай старой раскладки) → бакеты `request_duration_histogram_seconds`,
  `messenger_message_handling_duration_histogram_seconds`, `logic_action_duration_histogram_seconds` (`getBatches()`,
  ключа нет, если ни один enum не объявляет метрику); `msstc4symfony_metrics.http.ignored_routes`. Нет параметра —
  пустые значения (ошибку зависимости бросит RegisterSloMetricPass, Task 8).

## Монитор операций и логирование (Task 10)

- Порты `Application/Port/{OperationMonitorInterface,OperationHandleInterface,MonotonicClockInterface}`; публичный алиас
  `OperationMonitorInterface` -> `Application/Operation/OperationMonitor` (его будет декорировать slo-bridge-profiling);
  `OperationHandle`/`NoopOperationHandle` там же. Это реализация driving-порта с двумя методами и состоянием
  (`warned`), поэтому не слайс `Action/Input/Output`. Часы `Infrastructure/Clock/HrtimeClock` (`hrtime(true)`,
  наносекунды); в тестах `FakeClock`. Классификация исключений `measure()` — порт
  `ExpectedFailureClassifierInterface`: `ActionOutcomeClassifier` (`ActionOutcome::of()`), для logic-bundle до
  `ActionOutcome` — `ExpectedFailureInterfaceClassifier`, без logic-bundle — `NullExpectedFailureClassifier` (всё — error);
  выбирает `ExpectedFailureClassifierSelector` при сборке контейнера.
- Публичный driving-порт для мостов — `Application/Port/EventRecorderInterface::record(RecordEvent\Input)` (публичный
  алиас на `RecordEvent\Action`, который `@internal`).
- Имя операции проверяется в `start()`/`measure()`: нет SLO и нет конфликта для `PointId::of(Operation, $name)` либо имя
  не проходит `SloName` (в т.ч. пустое) -> `NoopOperationHandle` + одно предупреждение на имя (`reset()` очищает, тег
  `kernel.reset`). Конфликтное имя считается объявленным: предупреждение даёт `RecordEvent\Action`.
- `OperationHandle`: первый исход побеждает; `end()` идемпотентен и пишет `Observation::outcome(исход ?? Success, elapsed)`;
  деструктора нет — не завершённый handle ничего не пишет. `measure()` пробрасывает то же исключение; классификация
  через `ActionOutcome::of()` logic-bundle (`ExpectedFailureInterface` и `ValidationFailedException` → expected), если
  `enum_exists(ActionOutcome)`, иначе fallback `interface_exists(ExpectedFailureInterface) && instanceof`
  (logic-bundle только в composer-ci.json/composer-local.json).
- Логирование: все fail-open классы получают `Infrastructure/Logging/FailSafeLogger` (обёртка над каналом `slo_bundle`,
  глотает Throwable) через `->arg('$logger', service(FailSafeLogger::class))` в `services.php`; тег
  `monolog.logger` стоит только на самом `FailSafeLogger` (warmer тоже получает `FailSafeLogger`). Application-классы зависят лишь от
  `Psr\Log\LoggerInterface`; try/catch вокруг `warning()` больше нигде не нужен.

## Имена серий и promtool (Task 12)

- Экспорт metrics-bundle: префикс `symfony_` (`PrometheusMetricRecorder::NAMESPACE`), имя счётчика = значение enum без добавления `_total`
  (`symfony_http_response`, `symfony_messenger_message_handled`, `symfony_slo_events_total`); гистограммы — `<name>_bucket{le}` / `_count`.
  promphp экспонирует `le` как `(string) $bucket` (`"1"`, `"0.5"`), но Prometheus 3 при приёме нормализует его в
  `"1.0"`; генератор пишет нормализованную форму (`BucketLabel`), см. known-issues, батч A.
- `promtool` — образ `prom/prometheus:v3.15.0`, `docker run --rm -v <dir>:/work:ro -w /work --entrypoint promtool ... check rules|test rules`.
  Golden-файлы `tests/Fixtures/Generated/prometheus/*.yml` проверяются `check rules`, сценарии `tests/Fixtures/Promtool/*.test.yml`
  ссылаются на них через `../Generated/...`.

## Alertmanager и amtool (1.1.0)

- Бандл генерирует конфигурацию, но не интеграции: получатели (`slo-page`, `slo-ticket`, `slo-unrouted`) пустые, Slack/Jira/Telegram добавляет пользователь.
- Фрагмент `slo-alertmanager-routes.yml` вставляется в чужой конфиг: `routes` в `route.routes`, `inhibit_rules` на верхний уровень, по одному разу на `application`.
- Проверка: `make test-amtool` — `amtool check-config` (полный файл и фрагмент, слитый в пользовательский конфиг) и `amtool config routes test`; Docker `prom/alertmanager:v0.34.0`, `--entrypoint amtool`, смонтированный каталог `:ro`.

## Задачи Symfony Scheduler (1.2, Task 3)

- `ScheduledTaskReader` читает теги `scheduler.task`, которые FrameworkBundle ставит по `#[AsCronTask]`/`#[AsPeriodicTask]`
  (`get_object_vars($attribute) + ['trigger' => 'every'|'cron']`; у атрибута на методе добавляется `method`, у атрибута на классе
  `method` = **null**, `arguments` = null/строка/массив). Одинаково в 7.4 и 8.1 (FrameworkExtension 7.4.23 в vendor).
- Какая задача — команда, повторяет `AddScheduleMessengerPass` (`CommandName`): 7.4 — любой тег `console.command` → `RunCommandMessage`,
  имя = `tags[0]['command'] ?? #[AsCommand]->name ?? getDefaultName()`; 8.1 — тег `console.command` с тем же `method` (без `method` =
  `__invoke`), иначе первый тег, если ни один тег не называет метод; `getDefaultName()` в 8.x нет. Имя режется по `|`, первая непустая
  часть (скрытая команда `|app:x` → `app:x`). Версии проверены по `vendor/symfony/scheduler` 8.1.10 и GitHub `symfony/scheduler@7.4`.
- Точки: сервисная задача `scheduled_task:<service id>::<method>` (id — как в `ServiceCallMessage`; при автоконфигурации id = FQCN),
  командная — `scheduled_task:command:<name>`. `#[Slo]` класса применяется ко всем задачам класса, `#[Slo]` метода — только к своей;
  у команд — только класс (R9). Повторяемые атрибуты (несколько расписаний одного метода) = одна точка, `apply()` один раз.
- Без `symfony/scheduler` тегов нет → `scheduledTasks === []`. `symfony/scheduler` только в `require-dev` `composer-ci.json`.

## Запуски задач Scheduler в воркере (1.2, Task 4)

- `Infrastructure\Scheduler\ScheduledTaskRunListener` слушает события воркера Messenger через `#[AsEventListener]` (Rector
  переписывает `EventSubscriberInterface` в атрибуты, как в metrics-bundle): received -1000 (после чужих `shouldHandle()`),
  handled 0, failed 0 (после `SendFailedMessageForRetryListener` на 100, который ставит `willRetry()`). Регистрируется в
  `services.php` только при `class_exists(ServiceCallMessage)` и `class_exists(WorkerMessageReceivedEvent)`; подписка — через
  автоконфигурацию FrameworkBundle (в голом `TestContainer` слушатель есть, но тегов слушателя нет).
- Точка сообщения — `ScheduledTaskMessage::pointOf()`: `ServiceCallMessage` → `<serviceId>::<method>`, `RunCommandMessage` →
  `StringInput($input)->getFirstArgument()` (снимает кавычки `'app:foo'` от `ArrayInput`); `RedispatchMessage` и прочее → null.
  `StringInput` не падает на незакрытой кавычке (`'x` → токен `'x`), исключение парсера практически недостижимо.
- Старт хранится в `WeakMap` по объекту сообщения (`Envelope::with()` его сохраняет; in-memory транспорт повторяет тот же объект).
  Исход без старта (после `reset()`, `shouldHandle(false)`) ничего не пишет. Длительность — свой `MonotonicClockInterface`.
- Каждая попытка пишет `slo_scheduled_task_started_total` / `finished_total{outcome}` / `duration_seconds{outcome}` через
  `MetricsScheduledTaskRunWriter` (metrics-bundle добавляет префикс `symfony_`); повтор — `outcome="retried"`, без события SLO.
  Финальный сбой классифицируется по `HandlerFailedException::getPrevious()`; `RunCommandFailedException` всегда `error`.
  Запуски на failure-транспорте пишут метрики запуска, событие SLO — с флагом `onFailureTransport` (не eligible).
- Fail-open: каждый обработчик и запись метрик/события в своём `try`, предупреждение `Scheduled task run was not recorded.`
  (`task` = id точки) или `Scheduled task exception could not be classified, it counts as an error.` в канал `slo_bundle`.
- `RunCommandMessageHandler` оборачивает в `RunCommandFailedException` любое исключение команды (и не только ненулевой код выхода) —
  в 7.4 и 8.0; поэтому сбой командной задачи всегда `error`, а для сервисной задачи классифицируется `HandlerFailedException::getPrevious()`
  через `ExpectedFailureClassifierInterface`.
- Что читается: `scheduler.task` — атрибуты `#[AsCronTask]`/`#[AsPeriodicTask]` (набор полей одинаков в 7.4 и 8.x); `console.command` —
  только чтобы узнать имя команды (правила 7.4 и 8.1 различаются, см. выше). Расписания, собранные вручную (`RecurringMessage` в провайдере),
  не обнаруживаются; сообщение, отправленное приложением само, учитывается под точкой задачи.
