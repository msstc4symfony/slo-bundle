# Интеграции

- `msstc4symfony/metrics-bundle` `^2.3` — хранилище и экспорт счётчика `symfony_slo_events_total`.
- Мосты `slo-bridge-logic` / `slo-bridge-profiling` — отдельные пакеты, подключают свои точки через тег
  `msstc4symfony_slo.point` (атрибуты `type` = значение `PointType`, `id`, опционально `metric_label`); pass моста —
  приоритет >= 10 в `TYPE_BEFORE_OPTIMIZATION`.

## Что discovery читает из чужих бандлов (Task 5)

- Messenger (FrameworkExtension): маршрутизация — аргумент 0 `messenger.senders_locator` (тип сообщения → имена
  sender'ов; ключи — класс, родители, интерфейсы, `Ns\*`, `*` как в `SendersLocator`). Сообщение async, если хоть один
  ключ даёт непустой список; если ни один не совпал — как `SendersLocator`, смотрим `#[AsMessage(transport: ...)]`
  (IS_INSTANCEOF) на классе, родителях и интерфейсах. Без определения `messenger.senders_locator` — всегда false.
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
