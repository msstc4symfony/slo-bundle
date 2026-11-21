# Интеграции

Заглушка: наполняется задачами плана.

- `msstc4symfony/metrics-bundle` `^2.3` — хранилище и экспорт счётчика `symfony_slo_events_total`.
- Мосты `slo-bridge-logic` / `slo-bridge-profiling` — отдельные пакеты, подключают свои точки через тег
  `msstc4symfony_slo.point`.
