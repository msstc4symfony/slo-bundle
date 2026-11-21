# Архитектура

Пакет `msstc4symfony/slo-bundle`: декларативные SLI/SLO для Symfony (обнаружение, coverage gate, счётчики
good/bad, правила Prometheus, дашборды Grafana) поверх `metrics-bundle` 2.3.

- `SloBundle` — `AbstractBundle`: `configure()` описывает дерево `msstc4symfony_slo` (полное, см. README «Configuration reference»),
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
- Дерево конфигурации — в `SloBundle::configure()` (узлы собирают `defaultsNode()/coverageNode()/operationsNode()/generationNode()`);
  нормализованная форма описана `@phpstan-type SloConfig`. `loadExtension()` раскладывает дерево в параметры
  `msstc4symfony_slo.<путь>` генерически (`flatten()`: вложенные map → точечный путь, списки и `operations` целиком).
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
  DTO `CatalogContents`, `CatalogExtras`, `HandlerInfo`, `TaggedPoint`, `SloDeclaration`, `PointTag`); маршалинг дампа контейнера
  (snake_case-скаляры) — `Infrastructure/Catalog/CatalogDump` (R2-021: им пользуются только DI и Infrastructure, новых рёбер deptrac нет);
  чтение контейнера — `@internal` классы в `src/DependencyInjection/CompilerPass/Discovery/`
  (`ControllerReader`, `HandlerReader`, `TaggedPointReader`, `OperationReader`, `MessengerRouting`,
  `MetricsParameters`, `AttributeReader`, `ServiceClass`).
- Конфиг `operations` превращается в `Slo(..., strategy: StrategyPreference::Dedicated)`, поэтому валидация у всех источников одна —
  `DefinitionFactory::fromAttribute()`.
- Каталог: сервис `CompiledSloCatalog` (алиас `SloCatalogInterface`), аргумент `$catalog` — плоский массив
  `CatalogDump::toArray($contents, SeriesCatalogInterface)` (`@phpstan-type CatalogShape`: `format` (3), `definitions`, `conflicts`, `ignored`, `extras`,
  `point_index`, `conflicted_points`, `metric_labels`, `message_classes_by_label` — последние четыре индексы для горячего пути, R2-037), никаких объектов в дампе; `%` в строках экранируется `CollectSloDefinitionsPass::escape()`.
  Гидрация ленивая: по точке в `forPoint()`, целиком — в диагностических методах; конструктор не бросает (см. known-issues, батч A).
  Точка в дампе — строка `PointId::toString()`, разбирается по первому `:` (`CatalogDump::point()`).
- Все ошибки компиляции копятся в `DiscoveryErrors` и бросаются одним `InvalidConfigurationException`
  ("SLO discovery found N errors:"), сообщения отсортированы и начинаются с точки (`http_route:Class::method: ...`).

## Привязка к маршрутам, CLI и warmer (Task 6)

- Порты в `src/Application/Port/`: `RouteCatalogInterface` (`routesFor('Class::method')`, `all()`; `RouteInfo{name, path, methods,
  controller}`) и `CatalogDiagnosticsInterface` (`conflicts()`, `shared()`, `extras()`, дешёвые `hasHttpPoints()`,
  `failureTransports()`, `metricBuckets()`, `metricLabelOf()`, `handledMessageClassesLabelled(label)`), его реализует `CompiledSloCatalog`.
  Application не зависит от Infrastructure (deptrac) — Validate/List получают диагностику только через порт.
- `Infrastructure/Symfony/RouterRouteCatalog` — `RouteCatalogInterface` поверх `RouteIndexData`: сначала прогретый
  индекс из `RouteIndexStoreInterface`, иначе `RouterRouteReader::read()` один раз (ruling 7). Нормализацию делает `RouterRouteReader`
  (`?RouterInterface` = `service('router')->nullOnInvalid()`; без роутера маршрутов нет). `_controller`: `Class::method`, `service_id::method`, invokable `Class`/`service_id` (→ `::__invoke`), массив
  `[Class|object, method]`; без `_controller` / Closure → `controller = null`. Service id, не равный имени класса,
  переводится через `CatalogExtras::controllerServices` (id => класс; заполняет `ControllerReader::serviceIds()`).
- `Application/Catalog/List/{Action,Output,Row,ObjectiveRow,PointRow}`: строка на SLO (на каждое объявление для
  дубликата), цели отсортированы по kind, точки по строке, имена маршрутов отсортированы. Колонка Strategy и JSON-ключ `strategy` добавлены в Task 7.
- `Application/Catalog/Validate/{Action,Output,DeclarationDiff,FieldDifference}`: `Output{errors, warnings, notices}`.
  Ошибка конфликта многострочная: первая строка по ruling 1, дальше `  <поле> A vs B` для каждого отличающегося поля.
- Команды `slo:list [--format=text|json]` и `slo:validate` — `Presentation/Command/`. JSON: массив по имени, ключи
  `name,status(ok|duplicate),point_type,window,objectives[kind,target,threshold,revision],points[point,routes],shared_points`.
  `slo:validate`: код 0 — нет ошибок (предупреждения и notice печатаются), 1 — есть ошибки.
- `SloCacheWarmer`: `isOptional() = false` (R2-010: запускается и при сборке контейнера ядром, и с `--no-optional-warmers`), тег `kernel.cache_warmer`, логгер — `FailSafeLogger`; ошибки и предупреждения
  валидации логирует как warning, исключения глушит; затем пишет индекс маршрутов и ревизии целей (см. ниже «Прогретый индекс маршрутов»).

## Прогретый индекс маршрутов (финальное ревью, Important 1/2)

- Проблема: `FrameworkBundle\Routing\Router::getRouteCollection()` при каждом вызове заново грузит маршруты через
  `routing.loader`, а «мемоизация на процесс» под FPM = на запрос.
- `SloCacheWarmer::warmUp($cacheDir)` пишет `$cacheDir/msstc4symfony_slo/route_index.php` (`var_export` массива
  `RouteIndexData::toArray()`: `version` (2), `routes` name => {path, methods, controller}, `controllers` controller => имена,
  `points` маршрут => строки `PointId`, `revisions` `"<slo>|<kind>"` => 8 hex | `''`), атомарно (tmp + rename) и с `opcache_invalidate`.
  `RouteIndexData` реализует `RouteCatalogInterface` (warmer резолвит стратегии поверх свежих маршрутов).
- Всё про индекс лежит в `Infrastructure/RouteIndex/` (R2-053): `RouteIndexData`, `RouteIndexParser`, `RouteIndexStoreInterface`,
  `RouteIndexFile`, `RouteIndexBuilder`, `WarmedRevisions`.
- Хранилище — шов `Infrastructure/RouteIndex/RouteIndexStoreInterface` (`load()` — всё, для CLI; горячий путь по ключу: `pointsFor(route)`,
  `revisionOf(ObjectiveKey): Revision|false|null`, `routesFor(controller)`; `write($cacheDir, data)`; R2-008/009),
  адаптер `Infrastructure/RouteIndex/RouteIndexFile` (`%kernel.cache_dir%`, `%kernel.debug%`) читает файл один раз на процесс,
  версию проверяет один раз при include, на горячем пути валидирует только запрошенную запись (`RouteIndexParser::pointsEntry/revisionEntry/
  routesEntry`, мемо по ключу), `parse()` — всё; вне debug отсутствующий/чужой версии файл логируется один раз на процесс
  («degraded mode»); запись — `Filesystem/AtomicFile` (эксклюзивный tmp + rename, общий с `FileWriter`);
  `RouteIndexData` — чистый VO, конструктор проверяет, что controllers ссылаются на известные маршруты); в debug файл игнорируется
  (маршруты меняются без cache:clear, а опциональные warmer'ы при пересборке контейнера в dev не запускаются).
- Сборка индекса — `RouteIndexBuilder::build(list<RouteInfo>)` (точки + ревизии через `resolver->withRoutes()`; сбой ревизий/цели
  логируется и пропускается). Им пользуются warmer и `RoutePointIndex`: если прогретого файла нет, `RoutePointIndex` строит точки сам и
  отдаёт `RouteIndexStoreInterface::persist(Closure)`; `RouteIndexFile` вызывает замыкание и пишет файл только вне debug, один раз за процесс
  и когда текущего индекса нет (прогретый файл не перезаписывается); read-only кеш молча пропускается (R2-010-F). Следующий процесс читает
  файл — перестройка и degraded-предупреждение раз на деплой.
- Ключ ревизий — `Application/Binding/ObjectiveKey::of(SloName, ObjectiveKind)->value` (`"<slo>|<kind>"`), общий для warmer,
  `WarmedRevisions`, `RouteIndexStoreInterface::revisionOf()` и мемо `RecordEvent\Action` (R2-034-F).
  Битый/чужой версии/отсутствующий файл → `null` → ленивый fallback (`RouterRouteReader`, `RouteIndexData::pointsOf`).
- `CatalogExtras::hasHttpPoints` (ставит `CatalogAssembler`, в дампе `has_http_points`): `HttpSloObserver` выходит сразу,
  если нет ни одного http_route SLO/конфликта, — приложение без HTTP SLO не трогает роутер на пути запроса.
- Опциональные warmer'ы запускаются только `cache:warmup` и `cache:clear` (без `--no-optional-warmers`), не при boot ядра; поэтому
  `SloCacheWarmer` с R2-010 не опциональный (цена: прогрев всегда сканирует маршруты).

## Привязка метрик: Strategy A / B (Task 7)

- `Application/Binding/MetricBindingResolver::resolve(SloDefinition, Objective): Binding` и `isDedicated(definition, kind)`; оба идут через
  `existingSeries()` → `?Closure(): SeriesBinding`: решение принимается без сборки матчеров, `isDedicated()` замыкание не вызывает (R2-038).
  `withRoutes(RouteCatalogInterface)` — тот же резолвер поверх других маршрутов (индекс при сборке, R2-047). Решает только
  «может ли существующий ряд выразить цель»; зависит от портов `RouteCatalogInterface`, `CatalogDiagnosticsInterface` (только дешёвые
  чтения: `metricBuckets()`, `metricLabelOf()`, `handledMessageClassesLabelled()`, `failureTransports()` — никогда `extras()`, REREVIEW-A-2)
  и `SeriesCatalogInterface` (CR-024).
- `Application/Port/SeriesCatalogInterface` — имена метрик/меток metrics-bundle и logic-bundle и сборка `SeriesBinding`;
  адаптер `Infrastructure/MetricsBundle/MetricsBundleSeriesCatalog` (константы меток, значения статусов/исходов из
  `DeliveryStatus`/`Outcome`, короткое имя сообщения = `messageLabel()`, бакет → `le` через `Infrastructure/Prometheus/BucketLabel`).
- `Binding{strategy: Application\Binding\Strategy (existing_series|dedicated), series: ?SeriesBinding}`. `SeriesBinding{metric (без префикса),
  kind: SeriesKind (Counter|Histogram), matchers, goodMatchers, badMatchers, le}`; `Matcher` (label, `MatchOperator` без значений, сырое
  значение) — нейтральная модель; синтаксис (операторы, кавычки PromQL, экранирование RE2, окно, шаблон статусов) — только в
  `Infrastructure/Prometheus/PromQlRenderer` (CR-007). badMatchers/goodMatchers перекрывают matchers по label.
- Всегда B: `strategy: dedicated`, Combined, Operation, HTTP без маршрутов, countAttempts (Messenger), Messenger-сообщение,
  чьё короткое имя класса совпадает с другим обрабатываемым сообщением (`extras()->handlers`; HandlerReader даёт warning,
  а SLO-SLO совпадение — ошибка компиляции), logic availability с
  `expectedFailureIsGood=false`, нет бакетов/`metricLabel` (logic). Logic latency от `expectedFailureIsGood` не зависит (CR-013). HTTP latency: A только с `includeErrors` и пустыми `ineligibleStatuses` (у гистограммы нет `status`).
- Порог vs бакет: `BucketLabel::fromBucket($bucket)->equalsMilliseconds($ms)` (относительный допуск 1e-9 по экспонируемому
  значению); `le` = форма Prometheus 3 (`"0.5"`, `"1.0"`, `"1e+06"`), см. known-issues, батч A.
- `slo:list` показывает колонку Strategy (`-` у DUPLICATE), JSON-ключ `strategy`; `slo:validate` пишет NOTICE, когда latency-порог `auto` SLO
  не бакет ("uses dedicated counters"). `Resolver::thresholdMissesBucket()` / `histogramFor()` - публичные хелперы для этого.

## Запись событий: SloMetric и RecordEvent (Task 8)

- `Infrastructure/MetricsBundle/SloMetric::EVENTS` = `slo_events_total` (counter; метки `slo`, `objective` ENUM, `revision`, `result` ENUM). Регистрируется `RegisterSloMetricPass` (TYPE_BEFORE_OPTIMIZATION, приоритет 0, как у metrics-bridge-logic): добавляет enum в параметр `msstc4symfony_metrics.metric_enums` (идемпотентно); без MetricsBundle компиляция падает с `LogicException`.
- Порт `Application/Port/EventWriterInterface`, адаптер `MetricsEventWriter` (один `increment` на (событие, цель)).
- Публичный driving-порт `Application/Port/EventRecorderInterface::record(Input)` (публичный алиас); реализация
  `Application/Evaluation/RecordEvent/Action` (`@internal`, `record(Input{point, observation})`): для каждого SLO точки и каждой цели с `isDedicated()` классифицирует и пишет; стратегия+ревизия берутся из `PrecomputedRevisionsInterface` (прогретые warmer'ом), иначе `MetricBindingResolver` + `RevisionResolver`, и мемоизируются по (slo, kind) на процесс; любой `Throwable` логируется (канал `slo_bundle`, warning, контекст slo/objective/point/exception, один раз на slo|kind|класс до reset, максимум 100) и не пробрасывается. Конфликтные имена (`conflictedNamesFor`): один warning на имя за процесс, записи нет. `ResetInterface` (тег `kernel.reset`) очищает предупреждения о записи и неудачные резолвы, успешные остаются; неудачный резолв
  повторяется после reset, но логируется один раз на `slo|kind|класс` за процесс (отдельный несбрасываемый `WarnOnceLimiter`, R2-011).

## Наблюдатели HTTP и Messenger (Task 9)

- `Infrastructure/MetricsBundle/HttpSloObserver` (`HttpMeasurementObserverInterface`) и `MessengerSloObserver`
  (`MessengerMeasurementObserverInterface`) — тегируются автоконфигурацией metrics-bundle, своих тегов нет. Длительность
  берётся только из measurement (второго таймера нет).
- HTTP: `route === null` → ничего; иначе `RoutePointIndexInterface::pointsFor(route)` → для каждой точки
  `Observation::http(status, duration)` → `RecordEvent\Action`. Пустой список → ранний выход (одна выборка из массива).
- `Application/Port/RoutePointIndexInterface` + `Infrastructure/Symfony/RoutePointIndex`: имя маршрута → `list<PointId>`
  (контроллер `Class::method` из `RouteCatalogInterface::all()`), в индекс попадают только точки с SLO или с
  конфликтным именем (чтобы Action выдал warning о конфликте). Читается по маршруту из прогретого индекса (`RouteIndexStoreInterface::pointsFor()`), иначе строится лениво
  один раз за процесс; ошибка построения мемоизируется пустым индексом (HTTP SLO ничего не пишут до следующего
  `kernel.reset`, который повторяет только неудачную сборку; удачный индекс reset не сбрасывает); warning о сбое — один раз
  на класс исключения за процесс (`WarnOnceLimiter`, не сбрасывается, R2-011).
- Messenger: `PointId(messenger_message, messageClass)`; нет SLO и конфликтов → ранний выход; иначе
  `Observation::delivery(DeliveryStatusMapperInterface::map(status->value), transport ∈ failureTransports(), duration)`;
  неизвестный статус (новый case в metrics-bundle) → событие пропускается, один warning на статус до `kernel.reset`.
- Оба наблюдателя ловят любой `Throwable` (в т.ч. от логгера) и логируют в `slo_bundle`.
- `RecordEvent\Action`: ошибка резолвера/ревизии мемоизируется по (slo, kind) как «пропуск» и логируется один раз
  (контекст `slo`, `objective`, `point`, `exception`); поиск конфликтов в отдельном guard; все вызовы логгера защищены.

## Покрытие (`slo:coverage`, Task 11)

- `Application/Coverage/Analyze/{Action,Input,Output,PointCoverage,CoverageKind,KindCoverage,CoverageVerdict,CoverageStatus}` (`CoverageStatus` и `Binding/Strategy` перенесены из Domain, `@internal`, R2-022)
  (`monitored|unmonitored|ignored|invalid|unsupported`). Читает только скомпилированный каталог и `RouteCatalogInterface::all()`,
  никогда временные ряды (plan §15, последняя строка).
- Виды точек (`CoverageKind`, порядок case = порядок отчёта): `http_route` (point = имя маршрута), `messenger_handler` (point = `HandlerClass::method`,
  метод хранится в `HandlerInfo::$method`), `logic_action` (id точки), `operation` (имя операции из конфига).
- Приоритет статуса: invalid → ignored → unsupported → monitored/unmonitored. Invalid: конфликт имён SLO на точке
  (`conflictedNamesFor`) или SLO на маршруте из `httpIgnoredRoutes` metrics-bundle. Ignored: `_`-маршрут или `httpIgnoredRoutes`
  ("system route"), `coverage.http_routes.ignored_routes`, `#[IgnoreMonitoring]` (ищется по controller-точке / message-точке / точке логики).
- Messenger: точка на обработчик, но SLO и ignore ищутся по сообщению (`messenger_message:<FQCN>`); sync → unsupported
  "sync handling is not measured".
- `Output::of(kind)`/`kinds()` → `KindCoverage{monitored, eligible, sloPercent, measuredPercent}`, порог — `KindCoverage::isBelow()`
  по точной дроби; статус точки решает `CoverageVerdict::decide()`.
- `sloPercent = monitored / (total - ignored - unsupported)`, `measuredPercent = measuredByUniversalMetric / total`; оба округлены до
  2 знаков, при нулевом знаменателе 100.0. Операции: всегда monitored, measured всегда false (только dedicated).
- Команда `slo:coverage --format=text|json --min=<0..100>`: пороги из `coverage.*.required_percent` (у операций порога нет),
  `--min` перекрывает все виды; невалидный `--min`/`--format` → код 2; код 1 при любой invalid точке или виде ниже порога
  (сравнение по округлённому значению). JSON: ключи отсортированы, `JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES`, OUTPUT_RAW,
  схема `doc/coverage-schema.json` (JSON Schema 2020-12; `doc/` допустим для verify-standard). Целые проценты печатаются как `50`, не `50.0`.

## Генерация правил Prometheus и `slo:generate` (Task 12)

- `Application/Generation/`: `GeneratedFile{relativePath, contents}` (путь относительный, без `.`/`..`), `GeneratedFileSet{managedDirectories,
  files}` (файлы отсортированы, каждый внутри управляемого каталога; `merge()` для генераторов Task 13-14 — они добавляют свой каталог
  `grafana`/`sloth` и файлы), `Selector{application, components}` (компоненты отсортированы, уникальны), `BoundObjective{definition,
  objective, binding, revision}` — единица рендеринга всех генераторов, `BoundObjectiveCollector::collect()` (SLO по имени, цели в порядке
  `ObjectiveKind::cases()`; конфликтные имена отсутствуют в `all()` и правил не получают), `BurnRatePolicy` + `BurnRateWindow` (ruling 9).
- `BurnRatePolicy` лежит в Application (а не в Infrastructure, как в брифе): им пользуется и `slo:validate` (warning об опущенной паре), а
  Application не может зависеть от Infrastructure. Числа и длительности PromQL — `PromQlRenderer::number()/duration()`; severity пар — enum `BurnRateSeverity` (`page`/`ticket`).
- Use cases: `GenerateRules\{Action,Input,Output}` (файлы `prometheus/slo-recording.rules.yml`, `prometheus/slo-alerts.rules.yml`, флаги
  `generation.prometheus.rules/alerts`, `no_events_alert_hours`; warnings — конфликтные SLO и опущенные burn-rate пары) и
  `SyncFiles\{Action,Input,Output}` (stale/missing/extra по своим файлам; в режиме записи пишет stale+missing и удаляет extra).
  `rules: false` при `alerts: true` → warning в `slo:generate` и `slo:validate` (`GenerateRules\Action::configurationWarnings()`).
- Порты: `FileWriterInterface` (read/write/list/delete относительно корня; адаптер `Infrastructure/Filesystem/FileWriter` пишет через
  временный файл + rename) и `RuleFileBuilderInterface` (адаптер `Infrastructure/Prometheus/RuleGroupsBuilder`).
- `Infrastructure/Prometheus/PromQlBuilder` — все выражения; `RuleGroupsBuilder` — группы `slo-<name>`, `interval: 1m`;
  `Infrastructure/Yaml/DeterministicYaml` — `Yaml::dump(..., 10, 2, COMPACT_NESTED_MAPPING | EMPTY_ARRAY_AS_SEQUENCE)` с рекурсивно
  отсортированными map-ключами (`groups: []` для пустого каталога).
- `slo:generate [--output] [--check]`: нет `generation.selector.application` → код 2; `--check` → 0 / 1 со списком
  `stale|missing|extra <path>`; warnings — в stderr с префиксом `WARNING`.

## Дашборды Grafana (Task 13)

- Use case `Application/Generation/GenerateDashboards\{Action,Input,Output,DashboardContext}`: собирает `BoundObjectiveCollector` +
  `Coverage\Analyze\Action` + наличие гистограммы logic (`SeriesCatalogInterface::histogramFor()` + `metricBuckets()`) в `DashboardContext` и
  отдаёт `GeneratedFileSet` (каталоги `grafana/dashboards`, `grafana/provisioning/dashboards`; новых управляемых каталогов нет). Порт
  `GrafanaFileBuilderInterface` (адаптер `Infrastructure/Grafana/GrafanaFileBuilder`, создаётся фабрикой
  `GrafanaFileBuilder::standard($datasourceUid, $folder)` из `generation.grafana.*`: опции Grafana — настройки адаптера, не Application;
  `Input` = только `Selector`, `ignoredRoutes` coverage-таблицы приходит в `GenerateDashboards\Action` из `coverage.http_routes.ignored_routes`).
- Файлы: `grafana/dashboards/slo-<slug>.json`, slug = `application-overview|http-routes|messenger|business-operations|slo-overview|
  monitoring-coverage` (т. е. `slo-slo-overview.json` — буквально по брифу), `grafana/provisioning/dashboards/slo.yaml`
  (провайдер `slo`, `folder` из `generation.grafana.folder`, `options.path` = `GrafanaFileBuilder::DASHBOARDS_PATH` `/var/lib/grafana/dashboards`
  — путь внутри контейнера Grafana, не `output_dir`).
- `uid` = `slo-` + первые 12 hex `sha256(<короткое имя дашборда><application>)`, например `Application Overview` + `shop`. Заголовок
  `SLO: <имя> (<application>)`. `schemaVersion` 41, `time now-24h..now`, `refresh 1m`, нет `version`. Переменные: `datasource`
  (type datasource, default = `generation.grafana.datasource_uid`), `application` (constant), `component` (query, multi, includeAll;
  default = `generation.selector.components`, если заданы), `environment` (custom, пустой; в запросах не используется — меток `environment` в
  сериях нет, §5.6). Панели — `${datasource}`.
- Раскладка: `DashboardBuilder` слева направо по 24 колонкам, id панелей 1..n в порядке раскладки (включая `row`), `gridPos` из размера панели.
  `DeterministicJson`: рекурсивно отсортированные ключи объектов, списки сохраняют порядок, `PRETTY_PRINT|UNESCAPED_SLASHES|
  PRESERVE_ZERO_FRACTION`, юникод экранируется (`\u00e9`), перевод строки в конце.
- Базовые серии (Application Overview, HTTP Routes, Messenger, логика Business Operations) — `symfony_*{application="$application",
  component=~"$component"}` (`BaseSeries`); SLO-панели (`SloRows`: строка-row на SLO x objective — target, SLI, budget gauge, window state
  по `slo:window:coverage_ratio` с range-маппингом `<1` -> `warming up`, burn rate 1h/6h = `slo:error_ratio:rate<w> / (1 - slo:objective:target)`)
  читают записанные `slo:*` по `application`, `slo` и `objective`. Все панели с запросами имеют `noValue: "No data"`.
- Monitoring Coverage — две `text`-панели (Markdown-таблицы: покрытие по видам и все точки): статическая таблица Grafana (TestData/snapshot
  datasource) не переносима между инсталляциями, Markdown детерминирован и не зависит от временных рядов. Данные — `Analyze\Output` на момент
  генерации, обновляются только `slo:generate`.
- `slo:generate` запускает оба генератора и сливает `GeneratedFileSet::merge()` в один `SyncFiles` (`--check` покрывает и grafana).
  `coverage.http_routes.ignored_routes` идёт в таблицу покрытия.
- Тесты: golden `tests/Fixtures/Generated/grafana/`, структурный тест (id, uid, ссылки на серии, noValue), интеграция
  `tests/Integration/Presentation/Command/GenerateCommandTest.php` (`RoutedTestKernel` принимает `sloConfig`). Обновить golden: `GenerationFixture::dashboardsAction`
  (`GenerationFixture::dashboardsInput()`) и записать `$output->files->files` в `tests/Fixtures/Generated/`.

### Форма правил

- Базовый селектор: `application="<app>"` + `component=~"<a|b>"` (если компоненты заданы). Strategy B:
  `symfony_slo_events_total{<base>,slo,objective,revision="<текущая>"}`, bad = `+ result="bad"`; сумма по `result` — через `sum(...)` без `by`.
  Strategy A: `symfony_<metric>{<base>, matchers}`, bad = matchers с наложенными badMatchers; гистограмма — `_count`, latency =
  `_count - _bucket{le}`; пустые badMatchers → `vector(0)`.
- Все выражения агрегируются `sum(...)` до одной серии; метки ставит правило: `slo`, `objective`, `window` (окно как объявлено, `1mo`),
  `revision` только у B. Плохая серия может отсутствовать (нет ни одного bad-события) → `(bad or vector(0))`; знаменатель `(total > 0)` → NoData.
- Две группы на SLO: `slo-<name>` (1m) — `slo:events:rate<w>`, `slo:error_ratio:rate<w>` для 5m, 30m, 1h, 6h и
  `slo:objective:target` (`vector(target/100)`); `slo-<name>-window` (5m) — rate3d, `slo:sli:ratio` (increase за окно),
  `slo:error_budget:remaining` = `1 - (1 - slo:sli:ratio{...}) / (1 - target)` (из записи выше в той же группе, NoData сохраняется),
  `slo:window:coverage_ratio` = `clamp((time() - min_over_time(timestamp(sum(<total>))[<W+1h>:1h])) / W, 0, 1)`.
- Алерты (группа `slo-<name>`, 1m): `SloErrorBudgetBurn` page = `(1h & 5m) or (6h & 30m)` одним правилом, ticket =
  `(last_over_time(rate3d[10m]) & 6h) and on() (last_over_time(coverage[10m]) >= 1)` — записи 5m-группы читаются через
  `last_over_time(...[10m])`, чтобы запоздавшая оценка не давала дыр;
  пороги — готовые числа `factor × (1 - target)`; `SloNoEvents` (severity ticket) = `(sum(increase(<total>[Nh])) or vector(0)) == 0`.
  Аннотация только `summary` (имя, objective, target, окно), без runbook.

## Спецификации Sloth (Task 14)

- `GenerateSloth\{Action,Input,Output}` + порт `SlothSpecBuilderInterface{supports, spec}` (адаптер `Infrastructure/Sloth/SlothSpecBuilder`).
  Файл на SLO: `sloth/slo-<slo name>.yml` (`OWNED_FILES['sloth']`), внутри `slos[]` по одной записи на цель.
- Формат `prometheus/v1`: `version`, `service` (= `selector.application`), `labels`, `slos[].{name: <slo>-<kind>, objective: <target %>,
  description, labels{application,objective,slo}, sli.events.{error_query,total_query}, alerting.{name: Slo<PascalName><Kind>, labels,
  page_alert.labels.severity=page, ticket_alert.labels.severity=ticket}}`. Запросы — `PromQlBuilder::badRate()/eventsRate()` с диапазоном
  `[{{.window}}]` (application-scoped, фильтр revision для Strategy B).
- Окно SLO в spec не выражается: Sloth берёт период из своей конфигурации (`--default-slo-period`), `window` декларации игнорируется.
- `generation.sloth: false` (по умолчанию): файлов нет, но каталог `sloth/` остаётся управляемым (устаревшие `slo-*.yml` удаляются).
  `supports()` сейчас всегда true; false → warning в stderr `slo:generate` и цель пропускается (хук §5.6).
- Спецификации проверяются настоящим `sloth validate -i` (образ `ghcr.io/slok/sloth:v0.16.0`, группа promtool; тест перед проверкой
  убеждается, что сгенерировано 3 непустых файла).
- Sloth: у Sloth нет окна на уровне SLO; при разных окнах среди выданных SLO `slo:generate` печатает один WARNING (окна по возрастанию):
  один `--default-slo-period` не может подойти нескольким окнам, SLO с окном, отличным от выбранного периода, получат неверные пороги burn-rate. Spec проверяется `sloth validate -i` из образа `ghcr.io/slok/sloth:v0.16.0` в группе promtool.

## Закалка и релиз-кандидат (Task 15)

- Публичный API (README «Public API»): `Application\Attribute\*`, `Application\Port\{OperationMonitorInterface,OperationHandleInterface}`,
  `Application\Discovery\PointTag`, `Application\Evaluation\RecordEvent\{Action,Input}`, `Domain\Repository\SloCatalogInterface`,
  `Domain\Entity\SloDefinition`, `Domain\ValueObject\*`, `Domain\Enum\*`, команды, ключи конфига, `SloBundle`. Остальные классы помечены
  `@internal` (в docblock класса); новый класс вне этого списка получает `@internal` сразу.
- Регрессионные тесты `tests/Integration/Hardening/` (в testsuite `integration`, общее ядро `HardeningKernel` + база `HardeningTestCase`):
  `FailOpenTest` (окружение `failing_storage`: `UseThrowingRecorderPass` подменяет `$recorder` у `MetricsEventWriter` на `ThrowingRecorder`,
  бросающий `MetricStorageException`; HTTP, Messenger-воркер и `measure()` не меняются, исключение пользователя пробрасывается тем же объектом),
  `StateLeakageTest` (`services_resetter->reset()` между запросами; набор предупреждений `OperationMonitor` очищается),
  `CardinalityTest` (1000 запросов со случайным путём/query -> только 4 допустимых набора меток `slo_events_total`).
- `tests/Benchmark/RecordEventBenchmarkTest.php` (группа `benchmark`, вне testsuite) + цель `make benchmark` в `GNUmakefile`; печатает мкс/событие в
  STDERR, порога нет; цифры — в README «Overhead».

## acc:code-review, батч B (архитектура и публичный API)

- Операции: `Application/Operation/{OperationMonitor,OperationHandle,NoopOperationHandle}` — реализация driving-порта
  `OperationMonitorInterface` с двумя методами и состоянием, поэтому не слайс `Action/Input/Output` (осознанное отклонение
  от предложения CR-025 «Monitor/Action»). Классификация исключений — порт `ExpectedFailureClassifierInterface`, адаптеры
  `Infrastructure/LogicBundle/{ActionOutcomeClassifier,ExpectedFailureInterfaceClassifier,NullExpectedFailureClassifier}`;
  выбор в `SloBundle::loadExtension()` через `DependencyInjection/ExpectedFailureClassifierSelector::select($builder, ?probe)`
  (+ `ClassExistenceResource`, чтобы контейнер пересобирался при установке/удалении logic-bundle; probe — шов для тестов). Часы — `Application/Port/MonotonicClockInterface`, адаптер
  `Infrastructure/Clock/HrtimeClock`.
- `slo:generate`: оркестрация в `Application/Generation/Generate/{Action,Input,Output,GenerationConfig,Exception/InvalidGenerationSettings}`;
  `GenerationConfig` — сервис с параметрами `generation.*` (+ `coverage.http_routes.ignored_routes`); команда тонкая.
  Все `Presentation/Command/*` — `@internal` (контракт — имя, опции, вывод, коды выхода).
- `#[Slo(strategy: StrategyPreference::Dedicated)]` (enum из `Domain/Enum`), `ObjectiveAttributeInterface::kind(): ObjectiveKind`.
  Строка вместо enum → TypeError при `newInstance()` → `AttributeReader` превращает в ошибку компиляции `invalid attribute: ...`.
- `CompiledSloCatalog` — `ResetInterface` (тег `kernel.reset`): неудачная гидратация секции (`contents` — всё, `extras` — только
  extras) мемоизируется до reset; `extras()` не гидратирует определения, так что битое определение ломает лишь свою точку
  (REREVIEW-A-1). Резолвер на горячем пути `extras()` не зовёт (тест: `MetricBindingResolverTest` оборачивает каталог в
  `FailingCatalog(['extras'])`).
- `SloCacheWarmer`: валидация, сборка маршрутов, ревизии и запись — отдельные шаги; падение `catalog->all()` при расчёте
  ревизий не мешает записать маршруты и точки (ревизии пустые → runtime-резолв, REREVIEW-A-3). PSR-3 сообщения статические,
  детали в контексте (`error`, `warning`, `exception`).
- Сериализация: `Infrastructure/Serialization/SortedKeys::of()` — общая рекурсивная сортировка ключей для `DeterministicJson` и
  `DeterministicYaml`. Формы Grafana/Sloth описаны `@phpstan-type` (`Panel`: `PanelJson`, `FieldDefaults`, `ThresholdStep`,
  `ValueMapping`, `TargetJson`, `PanelOptions`; `DashboardBuilder`: `DashboardJson`, `TemplateVariable`; `SlothSpecBuilder`:
  `SlothSpecShape`, `SlothSloShape`, `SlothLabels`).
- deptrac не менялся: новые классы легли в существующие слои.

