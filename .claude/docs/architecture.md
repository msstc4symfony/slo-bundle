# Архитектура

Пакет `msstc4symfony/slo-bundle`: декларативные SLI/SLO для Symfony (обнаружение, coverage gate, счётчики
good/bad, правила Prometheus, дашборды Grafana) поверх `metrics-bundle` 2.3.

- `SloBundle` — `AbstractBundle`: `configure()` описывает дерево `msstc4symfony_slo` (пока пустое),
  `loadExtension()` импортирует `src/Resources/config/services.php`; `build()` регистрирует compiler passes из
  `src/DependencyInjection/CompilerPass/`.
- Сервисы бандла приватные, id = FQCN; потребитель получает их автовайрингом.
- Слои (`deptrac.yaml`) — копия `bundle-standard/templates/deptrac.yaml` с каноническим ruleset
  (Domain ← DomainService ← Application ← Infrastructure / Presentation / Framework; DependencyInjection — все слои,
  кроме BundleRoot; BundleRoot — Application и DependencyInjection); `BundleRoot` = `^…\\SloBundle$`.
  Ruleset можно только сужать.
- `#[Override]` на всех переопределениях — PHPStan `checkMissingOverrideMethodAttribute`.
  Domain/DomainService различаются подкаталогами `src/Domain/` (данные vs операции).
- Атрибуты (`#[Slo]`, `#[IgnoreMonitoring]`) лежат в `src/Application/Attribute/`.
- Тестовая обвязка: `tests/Integration/TestContainer.php` (голый `ContainerBuilder`) и
  `tests/Integration/Kernel/TestKernel.php` (FrameworkBundle + MonologBundle + MetricsBundle с `inmemory://` + SloBundle).
- slo-bundle не зависит от logic-bundle: он только `require-dev` (для тестов) и `suggest`.

## Атрибуты и конфиг (Task 4)

- Атрибуты в `src/Application/Attribute/`: `Slo` (повторяемый, класс/метод), `Availability`, `Latency`, `Combined`
  (`ObjectiveAttributeInterface::kind()`), `IgnoreMonitoring` (проверяет только непустую причину). Это простые
  держатели сырых значений: окна, пороги, цели валидирует проход discovery (Task 5), чтобы собрать все ошибки разом.
- Дерево конфигурации — в `SloBundle::configure()`; нормализованная форма описана `@phpstan-type SloConfig`.
  `loadExtension()` кладёт каждый лист в параметр `msstc4symfony_slo.<путь>` (`operations` — целиком массивом).
- `operations.<name>`: только `objectives`, `window`, `expected_failure_is_good` (ключа `strategy` нет — всегда dedicated).
  Окна/пороги в дереве проверяются лишь как непустые строки.
- `defaults.messenger.failure_transports`: `null` = автоопределение, список = явное значение
  (`variableNode`: `arrayNode` не допускает `null` по умолчанию).
- `generation.output_dir` содержит `%kernel.project_dir%`, поэтому `TestContainer` задаёт `kernel.project_dir`.
- Rector удаляет пустые публичные методы фикстур — в `tests/Fixtures` методы должны что-то возвращать.

## Discovery и скомпилированный каталог (Task 5)

- `SloBundle::build()` добавляет `CollectSloDefinitionsPass` (`TYPE_BEFORE_OPTIMIZATION`, приоритет 0 — после
  автоконфигурации атрибутов на 100, т.е. теги `#[AsController]`/`#[AsMessageHandler]` уже стоят). Мосты, которые
  ставят тег `msstc4symfony_slo.point` из своего pass, регистрируют его с приоритетом >= 10 (описано в `PointTag`).
- Раскладка: чистая логика в `src/Application/Discovery/` (`DefinitionFactory`, `CatalogAssembler`, `DiscoveryErrors`,
  `CatalogDump` + DTO `CatalogContents`, `CatalogExtras`, `HandlerInfo`, `TaggedPoint`, `SloDeclaration`, `PointTag`);
  чтение контейнера — `@internal` классы в `src/DependencyInjection/CompilerPass/Discovery/`
  (`ControllerReader`, `HandlerReader`, `TaggedPointReader`, `OperationReader`, `MessengerRouting`,
  `MetricsParameters`, `AttributeReader`, `ServiceClass`).
- Конфиг `operations` превращается в `Slo(..., strategy: 'dedicated')`, поэтому валидация у всех источников одна —
  `DefinitionFactory::fromAttribute()`.
- Каталог: сервис `CompiledSloCatalog` (алиас `SloCatalogInterface`), аргумент `$catalog` — плоский массив
  `CatalogDump::toArray()` (`@phpstan-type CatalogShape`), никаких объектов в дампе; гидрация в конструкторе.
  Точка в дампе — строка `PointId::toString()`, разбирается по первому `:` (`CatalogDump::point()`).
- Все ошибки компиляции копятся в `DiscoveryErrors` и бросаются одним `InvalidConfigurationException`
  ("SLO discovery found N errors:"), сообщения отсортированы и начинаются с точки (`http_route:Class::method: ...`).

## Привязка к маршрутам, CLI и warmer (Task 6)

- Порты в `src/Application/Port/`: `RouteCatalogInterface` (`routesFor('Class::method')`, `all()`; `RouteInfo{name, path, methods,
  controller}`) и `CatalogDiagnosticsInterface` (`conflicts()`, `shared()`, `extras()`), его реализует `CompiledSloCatalog`.
  Application не зависит от Infrastructure (deptrac) — Validate/List получают диагностику только через порт.
- `Infrastructure/Symfony/RouterRouteCatalog` — ленивый индекс по `RouterInterface::getRouteCollection()`, строится один раз
  (ruling 7). `_controller`: `Class::method`, `service_id::method`, invokable `Class`/`service_id` (→ `::__invoke`), массив
  `[Class|object, method]`; без `_controller` / Closure → `controller = null`. Service id, не равный имени класса,
  переводится через `CatalogExtras::controllerServices` (id => класс; заполняет `ControllerReader::serviceIds()`).
- `Application/Catalog/List/{Action,Output,Row,ObjectiveRow,PointRow}`: строка на SLO (на каждое объявление для
  дубликата), цели отсортированы по kind, точки по строке, имена маршрутов отсортированы. Колонки strategy нет (Task 7).
- `Application/Catalog/Validate/{Action,Output,DeclarationDiff,FieldDifference}`: `Output{errors, warnings, notices}`.
  Ошибка конфликта многострочная: первая строка по ruling 1, дальше `  <поле> A vs B` для каждого отличающегося поля.
- Команды `slo:list [--format=text|json]` и `slo:validate` — `Presentation/Command/`. JSON: массив по имени, ключи
  `name,status(ok|duplicate),point_type,window,objectives[kind,target,threshold,revision],points[point,routes],shared_points`.
  `slo:validate`: код 0 — нет ошибок (предупреждения и notice печатаются), 1 — есть ошибки.
- `SloCacheWarmer`: `isOptional() = true`, тег `kernel.cache_warmer`, канал логов `slo_bundle`; ошибки и предупреждения
  валидации логирует как warning, исключения глушит.

## Привязка метрик: Strategy A / B (Task 7)

- `Application/Binding/MetricBindingResolver::resolve(SloDefinition, Objective): Binding` и `isDedicated(definition, kind)`. Зависит только
  от портов `RouteCatalogInterface` и `CatalogDiagnosticsInterface::extras()` (бакеты, failure-транспорты, `taggedPoints` с `metricLabel`).
- `Binding{strategy: Domain\Enum\Strategy (existing_series|dedicated), series: ?SeriesBinding}`. `SeriesBinding{metric (без префикса),
  kind counter|histogram, matchers, goodMatchers, badMatchers, le}`; значения матчеров - `Matcher` (label, `MatchOperator`, сырое значение;
  `toPromQl()` экранирует строку PromQL, `escapeRegex()` экранирует RE2). badMatchers/goodMatchers перекрывают matchers по label.
- Всегда B: `strategy: dedicated`, Combined, Operation, HTTP без маршрутов, countAttempts (Messenger), `expectedFailureIsGood=false` или нет
  бакетов/`metricLabel` (logic). HTTP latency: A только с `includeErrors` и пустыми `ineligibleStatuses` (у гистограммы нет `status`).
- Порог vs бакет: `(int) round(bucket * 1000) === thresholdMs`; `le` = `(string) $bucket` ("0.5", "1").
- `slo:list` показывает колонку Strategy (`-` у DUPLICATE), JSON-ключ `strategy`; `slo:validate` пишет NOTICE, когда latency-порог `auto` SLO
  не бакет ("uses dedicated counters"). `Resolver::thresholdMissesBucket()` / `histogramFor()` - публичные хелперы для этого.
