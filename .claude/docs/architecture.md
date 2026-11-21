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

## Запись событий: SloMetric и RecordEvent (Task 8)

- `Infrastructure/MetricsBundle/SloMetric::EVENTS` = `slo_events_total` (counter; метки `slo`, `objective` ENUM, `revision`, `result` ENUM). Регистрируется `RegisterSloMetricPass` (TYPE_BEFORE_OPTIMIZATION, приоритет 0, как у metrics-bridge-logic): добавляет enum в параметр `msstc4symfony_metrics.metric_enums` (идемпотентно); без MetricsBundle компиляция падает с `LogicException`.
- Порт `Application/Port/EventWriterInterface`, адаптер `MetricsEventWriter` (один `increment` на (событие, цель)).
- `Application/Evaluation/RecordEvent/Action` (`__invoke(Input{point, observation})`): для каждого SLO точки и каждой цели с `isDedicated()` классифицирует и пишет; результат `isDedicated` + ревизия мемоизируются по (slo, kind) на процесс; любой `Throwable` логируется (канал `slo_bundle`, warning, контекст slo/point/exception) и не пробрасывается. Конфликтные имена (`conflictedNamesFor`): один warning на имя за процесс, записи нет. `ResetInterface` (тег `kernel.reset`) очищает memo и набор предупреждений.

## Наблюдатели HTTP и Messenger (Task 9)

- `Infrastructure/MetricsBundle/HttpSloObserver` (`HttpMeasurementObserverInterface`) и `MessengerSloObserver`
  (`MessengerMeasurementObserverInterface`) — тегируются автоконфигурацией metrics-bundle, своих тегов нет. Длительность
  берётся только из measurement (второго таймера нет).
- HTTP: `route === null` → ничего; иначе `RoutePointIndexInterface::pointsFor(route)` → для каждой точки
  `Observation::http(status, duration)` → `RecordEvent\Action`. Пустой список → ранний выход (одна выборка из массива).
- `Application/Port/RoutePointIndexInterface` + `Infrastructure/Symfony/RoutePointIndex`: имя маршрута → `list<PointId>`
  (контроллер `Class::method` из `RouteCatalogInterface::all()`), в индекс попадают только точки с SLO или с
  конфликтным именем (чтобы Action выдал warning о конфликте). Строится лениво один раз за процесс; ошибка построения
  мемоизируется пустым индексом (один warning, HTTP SLO больше ничего не пишут до перезапуска).
- Messenger: `PointId(messenger_message, messageClass)`; нет SLO и конфликтов → ранний выход; иначе
  `Observation::delivery(DeliveryStatus::from(status->value), transport ∈ extras()->failureTransports, duration)`.
- Оба наблюдателя ловят любой `Throwable` (в т.ч. от логгера) и логируют в `slo_bundle`.
- `RecordEvent\Action`: ошибка резолвера/ревизии мемоизируется по (slo, kind) как «пропуск» и логируется один раз
  (контекст `slo`, `objective`, `point`, `exception`); поиск конфликтов в отдельном guard; все вызовы логгера защищены.

## Покрытие (`slo:coverage`, Task 11)

- `Application/Coverage/Analyze/{Action,Input,Output,PointCoverage}` + `Domain/Enum/CoverageStatus`
  (`monitored|unmonitored|ignored|invalid|unsupported`). Читает только скомпилированный каталог и `RouteCatalogInterface::all()`,
  никогда временные ряды (plan §15, последняя строка).
- Виды точек (`PointCoverage::KIND_*`): `http_route` (point = имя маршрута), `messenger_handler` (point = `HandlerClass::method`,
  метод хранится в `HandlerInfo::$method`), `logic_action` (id точки), `operation` (имя операции из конфига).
- Приоритет статуса: invalid → ignored → unsupported → monitored/unmonitored. Invalid: конфликт имён SLO на точке
  (`conflictedNamesFor`) или SLO на маршруте из `httpIgnoredRoutes` metrics-bundle. Ignored: `_`-маршрут или `httpIgnoredRoutes`
  ("system route"), `coverage.http_routes.ignored_routes`, `#[IgnoreMonitoring]` (ищется по controller-точке / message-точке / точке логики).
- Messenger: точка на обработчик, но SLO и ignore ищутся по сообщению (`messenger_message:<FQCN>`); sync → unsupported
  "sync handling is not measured".
- `sloPercent = monitored / (total - ignored - unsupported)`, `measuredPercent = measuredByUniversalMetric / total`; оба округлены до
  2 знаков, при нулевом знаменателе 100.0. Операции: всегда monitored, measured всегда false (только dedicated).
- Команда `slo:coverage --format=text|json --min=<0..100>`: пороги из `coverage.*.required_percent` (у операций порога нет),
  `--min` перекрывает все виды; невалидный `--min`/`--format` → код 2; код 1 при любой invalid точке или виде ниже порога
  (сравнение по округлённому значению). JSON: ключи отсортированы, `JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES`, OUTPUT_RAW,
  схема `doc/coverage-schema.json` (JSON Schema 2020-12; `doc/` допустим для verify-standard). Целые проценты печатаются как `50`, не `50.0`.
