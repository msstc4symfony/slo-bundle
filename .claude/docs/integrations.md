# Интеграции

- `msstc4symfony/metrics-bundle` `^2.3` — хранилище и экспорт счётчика `symfony_slo_events_total`.
- Мосты `slo-bridge-logic` / `slo-bridge-profiling` — отдельные пакеты, подключают свои точки через тег
  `msstc4symfony_slo.point` (атрибуты `type` = значение `PointType`, `id`, опционально `metric_label`); pass моста —
  приоритет >= 10 в `TYPE_BEFORE_OPTIMIZATION`.

## Что discovery читает из чужих бандлов (Task 5)

- Messenger (FrameworkExtension): маршрутизация — аргумент 0 `messenger.senders_locator` (тип сообщения → имена
  sender'ов; ключи — класс, родители, интерфейсы, `Ns\*`, `*` как в `SendersLocator`). Сообщение async, если хоть один
  ключ даёт непустой список. Без Messenger — false.
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
