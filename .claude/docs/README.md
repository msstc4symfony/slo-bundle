# База знаний

- [architecture.md](architecture.md) — слои, подключение, id сервисов, порты маршрутов, прогретый индекс маршрутов, CLI, warmer.
- [domain-model.md](domain-model.md) — модель SLO, точки, каталог и конфликты имён.
- [integrations.md](integrations.md) — metrics-bundle, Messenger, мосты: что читает discovery.
- [conventions.md](conventions.md) — раскладка, тесты, коммиты.
- Покрытие и `slo:coverage`: см. раздел в [architecture.md](architecture.md).
- Закалка и бенчмарк: раздел «Закалка и релиз-кандидат» в [architecture.md](architecture.md) и [known-issues.md](known-issues.md).
- [known-issues.md](known-issues.md) — ловушки Symfony и CI, общие для семейства, и локальной сборки; раздел
  «acc:code-review high, батч A» — нормализация `le` в Prometheus 3, формат дампа каталога и route_index v2, `%` в аргументах.
