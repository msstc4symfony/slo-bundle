# Соглашения

- Use-case — каталог `src/Application/<Aggregate>/<UseCase>/` с `Action` (`final readonly`, `__invoke(Input): Output`),
  `Input` (инварианты в конструкторе) и `Output`.
- Тесты: `tests/Unit/<путь класса>`; `tests/Integration` — реальный `ContainerBuilder` через
  `TestContainer::compile($config)`, `MakeBundleServicesPublicPass` открывает сервисы бандла.
- Файлы инструментов байт-в-байт из `bundle-standard/templates`; менять их можно только в стандарте. Исключения:
  `phpstan.dist.neon` — свой `level:` (здесь 10, в шаблоне 9); `deptrac.yaml` — значение `BundleRoot` и
  комментарии (сравнивается разобранный YAML), ruleset можно сузить.
- Группы PHPUnit вне testsuite: `tests/Promtool` (`make test-promtool`, Docker) и `tests/Benchmark` (`make benchmark`); цели в `GNUmakefile`.
- Регрессионные тесты закалки — `tests/Integration/Hardening/` (общее `HardeningKernel`, база `HardeningTestCase`).
- Класс вне списка публичного API (README) помечается `@internal`.
- Коммиты — одна строка на английском, ≤ 20 слов; теги `vX.Y.Z`.
