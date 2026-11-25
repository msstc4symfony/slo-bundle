# Известные ловушки

- **`kernel.build_dir` в тестовом контейнере.** `PhpFileLoader` Symfony 7.4 читает этот параметр,
  8.x — нет; без него интеграционные тесты падают только в ячейке 7.4.
- **Теги для своих интерфейсов** регистрировать в `loadExtension()` через
  `registerForAutoconfiguration()`, а не `#[AutoconfigureTag]` на интерфейсе: атрибут на интерфейсе
  из vendor-пакета приложение не подхватывает.
- **`gh run watch` может вернуть 0 на упавшем прогоне.** Итог проверять через
  `gh run view <id> --json conclusion,jobs`.
- **`actions/checkout` по sha делает shallow-клон**; push из такого клона отклоняется как
  "fetch first" — нужен `fetch-depth: 0`.
- **Пороги Infection** (`infection-min-msi`, `infection-min-covered-msi` в `checks.yml`) = замер минус запас,
  с датой замера (UTC) в комментарии; пересчитывать при заметном изменении тестов.
- **`exec()` портит вывод с путями**: режет по строкам и срезает пробелы в конце строки, поэтому
  `git ls-files -z` через него теряет символы в именах. Читать через `proc_open` целиком. В тестах не
  читать stdout и stderr из двух pipe по очереди (deadlock при заполненном буфере) — stderr писать в файл.
- **Код должен разбираться на PHP 8.2** (bundle-standard v3, профиль `php82`): без типизированных констант
  (`const string X` — 8.3) и без `new X()->m()` / `new X()(...)` (8.4) — только `(new X())->m()`. PHPStan (`phpVersion.min: 80200`) ловит
  типизированную константу (`classConstant.nativeTypeNotSupported`), `new X()->m()` не ловит ни один
  инструмент на 8.4 — только `php -l` в job «minimal» (PHP 8.2) и ячейки PHPUnit на 8.2. Шаблоны
  Rector (`withPhpVersion(PHP_82)`) и CS-Fixer (`new_expression_parentheses`) обратно не поднимают.
  `#[Override]` (8.3) допустим: на 8.2 атрибут не инстанцируется. Функции 8.4 (`array_any/all/find`) —
  только с `symfony/polyfill-php84` в `require`.
- **`git archive HEAD` скелета неполный**: `.gitattributes` помечает `export-ignore` почти всё служебное
  (`.github`, `Makefile`, `tests`, `.claude` ...). Копировать дерево скелета через
  `git ls-files -z | tar --null -T - -c`.
- **`bin/init` требует чистое дерево и обновляет `composer-ci.json`-lock**: без сети/совместимого PHP
  `composer update --lock` падает и init откатывать нужно вручную (`git reset --hard && git clean -fd`).
  `roave/security-advisories: dev-latest` конфликтует с PHP старше нижней границы патч-версии (8.4.17 на этой
  машине < 8.4.28): для локальной установки `--ignore-platform-req=php`.
- **metrics-bundle 2.3.0 выпущен 2026-11-22 UTC** (тег `v2.3.0`), `composer-ci.lock` пересобран на него.
  Git-ignored `composer-local.json` (path-репозитории `../metrics-bundle` как `2.3.0`, `../sql-labeller` как `1.0.0`)
  остаётся для работы с соседними чекаутами. Пакеты семейства не на Packagist: ставятся через vcs-репозитории.
- **Symfony 7.4: `AbstractBundle`-расширение в голом `ContainerBuilder`** читает `kernel.environment` и
  `kernel.build_dir` (`ExtensionTrait::executeConfiguratorCallback`); на 8.x — нет. Локально (Symfony 8) тест зелёный,
  в CI ячейки 7.4 падают с `ParameterNotFoundException`: юнит-тесты конфигурации задают оба параметра.
- **Ячейка CI «without optional libraries»** не ставит logic-bundle: тест, который просто бросает исключение, не должен
  брать класс из logic-bundle (`ExpectedFailure` в тестах реализует `ExpectedFailureInterface`).
- **`composer-ci.lock` у metrics-bundle держит Elastica 7** (`--with ruflin/elastica:^7.3` при `composer update`):
  на Elastica 9 PHPStan падает на `Elastica\Transport\AbstractTransport` в `TimingTransport`.

## Ревизия: любое изменение канонической формы меняет все ревизии

Формат входа `RevisionCalculator` (набор и имена полей, порядок ключей) зафиксирован тестом `testPinnedRevision`; его изменение меняет ревизию у всех SLO и рвёт непрерывность рядов `symfony_slo_events_total`. Менять только осознанно.

## Discovery (Task 5)

- Приватные сервисы/алиасы бандла после `compile()` удаляются: `TestContainer` делает публичными только определения
  `Msstc4Symfony\SloBundle\*` (не алиасы) — для проверки `SloCatalogInterface` в тесте ставится свой публичный алиас.
- Rector (`RemoveUnusedPrivateMethodRector`) удаляет непубличные неиспользуемые методы фикстур — не полагаться на них
  в тестах discovery (исключение статических методов проверяется публичным static-методом).
- Тесты ошибок discovery кладут фикстуры в `tests/Fixtures/*/Invalid/`; в одном контейнере нельзя регистрировать
  `ProcessPaymentHandler` вместе с `Invalid\LegacyPaymentHandler` — это и есть ошибка коллизии коротких имён.

## Маршруты и CLI (Task 6)

- `TestContainer` (голый `ContainerBuilder`) регистрирует заглушки `router` и `LoggerInterface` и параметр `kernel.cache_dir`
  (нужен `RouteIndexFile`). Роутер подключается как `service('router')->nullOnInvalid()`: с `framework.router.enabled: false`
  контейнер компилируется, маршрутов просто нет (`NoRouterKernelTest`).
- `tests/Integration/Kernel/RoutedTestKernel` — ядро с произвольным набором контроллеров и маршрутов (`add()->controller([Class,
  method])`); кеш-каталог зависит от хеша сценария, иначе ядра с разными маршрутами в одном процессе делят кеш.
- Правило `slo:validate` про `httpIgnoredRoutes` сравнивает имя маршрута точно, как `RequestEventListener` metrics-bundle.
- Сервис контроллера с id, не равным классу, и маршрутом `service_id::method` разрешается только через тег
  `controller.service_arguments` (так их находит discovery); контроллеры без этого тега в `controllerServices` не попадут.
- Пространство имён `Application\Catalog\List` допустимо (`List` — зарезервированное слово только как имя класса).

## Strategy resolver (Task 7)

- `SeriesBinding` хранит матчеры как `Matcher`, а не `array<string,string>`: оператор (`=`, `!=`, `=~`, `!~`) нельзя однозначно закодировать в строке.
- Формат `le` (`(string) $bucket`) проверен (ревью Task 7 и promtool-тесты Task 12) на экспорте metrics-bundle: `0.5`, `1`.
- `slo:list --format=json` пишется с `OUTPUT_RAW`, ячейки таблицы экранируются `OutputFormatter::escape` (имена маршрутов могут содержать `<...>`).

## RecordEvent и регистрация метрики (Task 8)

- Метрики-бандл читает `msstc4symfony_metrics.metric_enums` через `#[Autowire(param:)]`, т.е. значение подставляется после BEFORE_OPTIMIZATION; поэтому проход SloBundle на этой стадии успевает добавить enum.
- `TestContainer::compile()` теперь по умолчанию задаёт пустой `metric_enums` и заглушку `MetricRecorderInterface` (`NullMetricRecorder`); `withMetricsBundle: false` воспроизводит ошибку отсутствия MetricsBundle. Старый тест «параметры метрик отсутствуют» стал недостижим (без параметра проход падает) и сужен до `http.ignored_routes`.
- Для HTTP `isDedicated()` обращается к роутеру (ленивый каталог маршрутов); дорого только на первом событии, далее memo.

## Наблюдатели и kernel-тесты (Task 9)

- `messenger:consume` без `--no-reset` вызывает `services_resetter` после каждого сообщения, а `InMemoryTransport::reset()`
  очищает очередь: повтор (retry) теряется и воркер ждёт `--time-limit`. В тестах передавать `'--no-reset' => null`
  (опция VALUE_OPTIONAL: `true` превращается в интервал 1, т.е. сброс после каждого сообщения).
- `messenger:stats` не умеет считать `in-memory://` (не `MessageCountAwareInterface`) — проверять очередь через
  `InMemoryTransport::get()` из тестового контейнера (`messenger.transport.<name>`).
- Экспорт metrics-bundle пишет `symfony_slo_events_total{application,component,slo,objective,revision,result}` — порядок
  меток зафиксирован в `tests/Integration/Kernel/SloEventsExport` (регэксп падает на любой другой форме).
- Синхронно обработанные сообщения не вызывают Messenger-наблюдатель (нет событий воркера) — событий SLO нет.
- HTTP kernel-тест не зависит от времени стены: фикстура `CheckoutController` использует порог 10s; пограничные пороги проверяются
  юнит-тестами наблюдателя с построенными measurement.
- Availability у Messenger по умолчанию Strategy A (ничего не пишется) — фикстуры kernel-тестов используют
  `strategy: StrategyPreference::Dedicated`, чтобы видеть счётчики.
- `justinrainbow/json-schema` (require-dev, ^6.6) валидирует `doc/coverage-schema.json` в тестах. `if/then/else` схемой
  не поддерживается библиотекой надёжно — в схеме используется `oneOf`. `Validator::validate()` берёт значение по ссылке и
  требует объекты (`json_decode(..., false)`), массивы не годятся. `composer-ci.lock` не пересобран (устарел и без этого —
  регенерирует владелец после тега metrics-bundle 2.3.0); `composer update --lock` без доступа к GitHub падает на клонировании logic-bundle.

## Генерация правил и promtool (Task 12)

- **`Makefile` и `phpunit.xml.dist` — `ExactFileRule` bundle-standard** (байт-в-байт с шаблоном), поэтому бриф («цель в Makefile, exclude группы
  в phpunit.xml.dist») не выполним буквально. Сделано: тест `tests/Promtool/PromtoolTest.php` вне testsuite-каталогов (default `make test` и
  Infection его не видят), цель `test-promtool` — в `GNUmakefile` (GNU make читает его раньше `Makefile`, `include Makefile`).
  `vendor/bin/phpunit --group promtool` без пути находит 0 тестов — нужен путь `tests/Promtool`.
- Тест promtool без Docker (или без доступа к сокету, как в песочнице) падает с «Docker is required...», не пропускается (ruling 10).
  В песочнице запускать с отключённым sandbox. Полный прогон ≈ 50 с (сценарии на 2-3 дня вычисляют все правила каждую минуту).
- Алерты читают записи предыдущей оценки другой группы: ticket отстаёт до одной оценки 5m-группы `-window` (до 5 мин) от момента,
  когда coverage стал 1 (в тесте `eval_time: 3d10m`), и после исчезновения данных держит последнее значение до 10 мин
  (`last_over_time(...[10m])` вокруг 3d-rate и coverage). Page-пары читают только записи 1m-группы.
- Порядок правил внутри группы значим: `slo:error_budget:remaining` должен идти после `slo:sli:ratio` (читает его в той же оценке).
- Бюджет, выведенный из SLI, даёт погрешность float (`-49.000000000000004`); promtool сравнивает точно — в тестах `round(..., 1e-6)`.
- promtool режет запросы по числу сэмплов («query processing would load too many samples»): `increase(...[7d])` на 1m-сериях упал около 7d,
  поэтому позитивный сценарий ticket — на окне 3d. В проде лимит `query.max-samples` (50M) — increase за 30d по сырым сериям дорог при большом
  числе серий; при проблемах переходить на агрегацию 5m-записей.
- Владение файлами: `slo:generate` трогает только `GeneratedFileSet::OWNED_FILES` (имена `slo-*` прямо в каталоге), поэтому
  `prometheus.yml` команды в `output_dir/prometheus/` безопасен; `FileWriter::list()` не рекурсивен.
- Coverage-подзапрос берёт окно + 1h (`[721h:1h]` для 30d): иначе первый шаг подзапроса после начала окна даёт `< 1` навсегда и ticket никогда
  не срабатывает.
- Golden-файлы зависят от формата дампера `symfony/yaml` (кавычки строк): сверено, что 7.4.23 и 8.1 дают те же байты.
- Новая CI-job `promtool` ставит `composer-ci.json`, а `composer-ci.lock` устарел до тега metrics-bundle 2.3.0 — job упадёт на install так же,
  как стандартная, до пересборки lock владельцем.
- Записанные `slo:*` и алерты несут статическую rule-метку `application` (из `generation.selector.application`), а все чтения записанных серий
  (`PromQlBuilder::recorded(record, objective, application)`) фильтруют `application=`; в Grafana — `application="$application"`. Два приложения с
  одним именем SLO в одном Prometheus не пересекаются (promtool: `no-data.test.yml`).

## Закалка, бенчмарк и релиз (Task 15)

- **`Kernel::boot()` сбрасывает сервисы после запроса**: после `handle()+terminate()` следующий `boot()` (его вызывает и
  `Application::find()` консоли) делает `services_resetter->reset()`, а `InMemoryTransport::reset()` очищает очередь — сообщение,
  отправленное между запросом и первым обращением к консоли, теряется (воркер ждёт `--time-limit`). `HardeningTestCase::boot()`
  поэтому сразу вызывает `application()->find('list')`.
- **Сбой хранилища в тесте** имитируется подменой `$recorder` у `MetricsEventWriter`, а не хранилища metrics-bundle: собственный
  `PrometheusMetricRecorder` ловит `Throwable` внутри и пишет в лог, поэтому через него fail-open SLO не проверить; `MetricStorageInterface`
  (`export()/clear()`) на запись не влияет.
- **PHPStan и `never`**: замыкание, которое всегда бросает, даёт `never`, и `self::fail()` после `measure()` считается недостижимым —
  в тестах ловить исключение в переменную и сравнивать после `try`.
- **Benchmark** вне testsuite (как `tests/Promtool`), потому что `phpunit.xml.dist`/`Makefile` байт-в-байт из bundle-standard; цель
  `make benchmark` в `GNUmakefile`. Замер 2026-11-21 UTC (после acc-fix A): 4.5 мкс/событие (null recorder), 5.1 мкс с `kernel.reset` после каждого события
  (со старым reset, сбрасывавшим резолв, было 28.7), 10.7 мкс (in-memory хранилище metrics-bundle), PHP 8.4.17.
- **Infection**: порог в `checks.yml` = замер 86.06 % (2246 мутантов, только unit-suite) минус запас, итого 84; основная масса выживших мутантов — дерево
  конфигурации `SloBundle` и `DeclarationDiff`/`StatusSet`/`CollectSloDefinitionsPass` (покрыты интеграционными тестами, которые Infection не гоняет).
  Запуск: `env -u TMPDIR make infection` с отключённым sandbox (нужен записываемый /tmp).
- **`@internal`** в docblock класса — признак «не публичный API»; PHPStan не ругается, пока код в том же корневом namespace `Msstc4Symfony`.
- **Мосты** обязаны регистрировать свой compiler pass с тегом `msstc4symfony_slo.point` в `TYPE_BEFORE_OPTIMIZATION` с приоритетом >= 10 (README, `PointTag`).
- **Ловушка порядка бандлов (rc.2)**: passes одного типа и приоритета идут в порядке `build()` бандлов (порядок `config/bundles.php`).
  Discovery на приоритете 0 не видел enum, добавленный на 0 бандлом, стоящим после slo-bundle (metrics-bridge-logic): бакетов
  `logic_action_duration_histogram_seconds` нет → Strategy A logic-SLO молча становились dedicated. Discovery перенесён на -10;
  тест порядка — `SloBundleTest::testDiscoveryRunsAfterPassesAtPriorityZero`. `TestContainer` вызывает `build()` до `configure`,
  поэтому pass из `configure` на том же приоритете — «бандл после slo-bundle».
- **`router` в `TestContainer`** — `new Definition(Router::class)` без аргументов: сервис, который тянет роутер при инстанцировании
  (`MetricBindingResolver`, `OperationMonitor` через каталог), падает с `ArgumentCountError`. Роутер в бандле `nullOnInvalid()`, поэтому
  такие тесты удаляют определение `router` (`BridgeExtensionPointsTest::withoutRouter()`).

## Финальное ревью ветки (2026-11-21 UTC)

- **Symfony 8.1 `MicroKernelTrait`** грузит свой `framework.router.resource` *после* `configureContainer()`, и это снова
  включает роутер: `'router' => ['enabled' => false]` в `configureContainer()` не работает. `NoRouterTestKernel` переопределяет
  `registerContainerConfiguration()` и догружает `router.enabled: false` последним (на 7.4 порядок обратный, работало и так).
- **Опциональные библиотеки** (symfony/messenger, msstc4symfony/logic-bundle) — только в `composer-ci.json`/`composer-local.json`,
  не в `composer.json` (джоба bundle-standard «PHPUnit without optional libraries» ставит `composer.json`). Тесты с Messenger
  помечены `#[RequiresMethod(MessageBusInterface::class, 'dispatch')]` (или `AsMessage::__construct`), `HardeningKernel`/`NoRouterTestKernel`
  включают messenger только при `interface_exists(MessageBusInterface::class)`. Проверка локально (без vcs-репозиториев, им нужна
  авторизация GitHub): копия `src tests doc phpunit.xml.dist composer.json` в `$TMPDIR/noopt`, в `composer.json` оставить только
  path-репозитории `../metrics-bundle` (2.3.0) и `../sql-labeller` (1.0.0) и `config.cache-read-only: true`, затем
  `composer update --ignore-platform-req=php && vendor/bin/phpunit` — все тесты проходят, skipped только Messenger и logic-bundle.
- **Прогретый индекс маршрутов** читается только при `kernel.debug = false`; kernel-тесты по умолчанию в debug и идут по
  ленивому пути, `HttpSloKernelTest::testWarmedRouteIndexServesRequestsWithoutDebug` проверяет путь `cache:warmup` → файл.
- **Grafana `dateTimeAsIso`** ждёт epoch в миллисекундах: `Warming up until` = `(time() + (1 - (coverage < 0.999999)) * W) * 1000`;
  дашборды в `timezone: utc`. Когда окно полное или нет покрытия — серии нет, панель показывает `No data`.
- **Retention Prometheus** ≥ окно + 1h: `coverageRatio` — подзапрос `[W/1h + 1 h:1h]`, с меньшим retention покрытие никогда не 1.

## acc:code-review high, батч A (2026-11-21 UTC)

- **Prometheus 3 нормализует `le`** классических гистограмм при приёме: экспозиция `le="1"` хранится как `le="1.0"`
  (проверено: `prom/prometheus:v3.15.0` скрапит сам себя в `PrometheusText0.0.4`, `count(...{le="1"})` пусто, `{le="1.0"}` есть;
  `/api/v1/label/le/values` содержит `1.0`, `10.0`, `100000.0`, `1e+06`, `1.6384e+06`). Формат — Go `strconv.FormatFloat(f,'g',-1,64)`
  + `.0` для целых. promphp экспонирует бакет как `(string)$bucket` (precision 14), поэтому `BucketLabel::fromBucket()` сначала
  кастует в строку и обратно, затем форматирует по-гошному. promtool `test rules` входные серии НЕ нормализует — тест
  `latency-bucket.test.yml` подаёт `le="1.0"` явно.
- **Совпадение бакета с порогом** — `abs(bucket*1000 - ms) <= 1e-9*ms` по экспонируемому значению; `round()` больше нет (0.2504 ≠ 250ms).
- **Дамп каталога**: `CatalogShape` с `format` (= `CatalogDump::FORMAT`, с батча D — 3), `point_index` (точка → индексы определений),
  `conflicted_points`, `metric_labels` (точка → `metric_label`) и `message_classes_by_label` (значение метки `message` → классы; метку
  считает `SeriesCatalogInterface::messageLabel()` при компиляции, поэтому `CatalogDump::toArray()` принимает series catalog). `CompiledSloCatalog` гидрирует лениво по точке; несовпадение формата = пусто + одно предупреждение, битое определение
  пропускается само (остальные SLO точки работают), такая точка повторяется после reset (R2-006); `get()` бросает до reset (R2-005);
  диагностические методы (`all/get/conflicts/shared/ignored/extras`) бросают `RuntimeException` «clear the cache».
  Дешёвые `hasHttpPoints()`/`failureTransports()`/`controllerServiceOf()` (R2-001, для `RouterRouteReader`) в `CatalogDiagnosticsInterface` —
  для горячего пути, без гидрации extras.
- **`%` в аргументах сервиса** резолвится как параметр: `CollectSloDefinitionsPass::escape()` удваивает `%` в строковых значениях дампа.
  `ParameterBag::unescapeValue()` НЕ раскавычивает ключи массива (PhpDumper раскавычивает), поэтому `%` в ключах недопустим —
  единственный пользовательский источник ключей с `%` (id тега точки) отклоняется на компиляции.
- **Числовые имена маршрутов** (`404`) становятся int-ключами PHP-массивов: `RouteIndexData` кастует ключи в строку при каждом чтении
  и принимает int-ключи при разборе файла.
- **route_index.php версия 3** (с батча E; 2 — без отпечатка каталога): горячий путь читает и валидирует отдельные записи (`RouteIndexFile::pointsFor()`, `::revisionOf()`,
  `::routesFor()`, с R2-008/009), `::load()` валидирует весь файл (CLI). `revisions` = `"<slo>|<kind>" => 8 hex | ''`
  (`''` — Strategy A), их считает `SloCacheWarmer` резолвером поверх только что прочитанных маршрутов; `WarmedRevisions`
  (`PrecomputedRevisionsInterface`) отдаёт их `RecordEvent\Action`. В debug или без файла — runtime-резолв как раньше.
- **`RecordEvent\Action::reset()`** очищает только предупреждения и неудачные резолвы; успешные резолвы (стратегия+ревизия) живут
  весь процесс. Повторные сбои записи логируются один раз на `slo|objective|класс исключения` до reset (не больше 100 ключей).
  `RoutePointIndex` теперь `ResetInterface`: reset повторяет только неудачную сборку индекса.
- **`OperationMonitor`** классифицирует через `ExpectedFailureClassifierInterface` (logic-bundle `ActionOutcome` или null-адаптер);
  сбой классификации = error, исключение пользователя пробрасывается без замены.
- **`DeliveryStatusMapperInterface`** — шов для тестов наблюдателя Messenger (ветка «неизвестный статус» недостижима с реальным enum).
- **Покрытие**: порог сравнивается по точной дроби (`monitored*100 < required*eligible`), процент округляется только для вывода
  (`Output::$sloFraction`).
- **Grafana**: записи 5m-группы (`slo:events:rate3d`, `slo:error_ratio:rate3d`, `slo:sli:ratio`, `slo:error_budget:remaining`,
  `slo:window:coverage_ratio`) читаются через `PromQlBuilder::latest()`/`lookback()` = `last_over_time(...[10m])`, как в алертах;
  список таких записей — `PromQlBuilder::SLOW_RECORDS`, согласованность с группами проверяет `GenerateRules\ActionTest`.
- **`FileWriter`** создаёт временный файл через `fopen(..., 'x')` со случайным суффиксом (предсказуемое имя позволяло подложить symlink).
- **`generation.selector.application/components`** валидируются `/^[A-Za-z0-9_.:-]{1,128}$/D` в дереве конфигурации.
- **Rector** удаляет неиспользуемые параметры у фикстур-хендлеров (`RemoveUnusedPublicMethodParameterRector`-подобные правила):
  в фикстурах параметр сообщения нужно использовать (`return $message::class;`).

## acc:code-review, батч B

- **Тесты индекса маршрутов** не трогают файловую систему: фейк `tests/Unit/Infrastructure/Symfony/InMemoryRouteIndexStore`
  (пустой = «индекс не прогрет»). Реальный файл проверяет `tests/Unit/Infrastructure/RouteIndex/RouteIndexFileTest`; фейк и файл гоняются одним контрактным тестом
  (`RouteIndexStoreContractTestCase`).
- **`monolog/monolog` убран из `require`** (в `src/` нет импортов Monolog; приходит транзитивно через metrics-bundle и
  monolog-bundle, verifier его не требует). `composer-local.lock` обновлён (`composer update --lock`, нужен доступ к
  GitHub вне песочницы); `composer-ci.lock` был устаревшим и до этого — его перегенерирует владелец после тега metrics-bundle 2.3.0.
- **Сообщение об пересечении статусов** (`Classification`) теперь в канонической форме (`"5xx"`), а не в RE2 (`"5.."`).
- **Messenger latency + `includeErrors`** больше невозможна как состояние: инвариант в конструкторе `SloDefinition`, защитная
  ветка в резолвере удалена.
- **Ревизии в Redis**: поле хэша `PROMETHEUS_:counter:symfony_slo_events_total` — JSON значений меток; README описывает
  `metrics:clear` и точечный `HDEL` по ревизии.

## acc:code-review high #2, батч C (2026-11-21 UTC)

- **PHPDoc `@return list<X>|null [] for …`**: PHPStan читает `null []` как `null[]`, и тип становится `list<X>|array<null>`
  (ошибки «array<X|null>» в вызывающем коде). Описание после типа не начинать с `[]`.
- **Описания `@phpstan-type`** не ставить строкой между тегами (phpdoc приклеивает её к предыдущему тегу) — абзац «Shapes: …» в
  описании класса (`Panel`, `DashboardBuilder`, `SlothSpecBuilder`, `SloBundle`).
- **`@param SloConfig $config` у `SloBundle::loadExtension()` невозможен**: PHPStan `method.childParameterType` (родитель принимает
  `array`); рекурсивных type alias у PHPStan нет, поэтому `flatten()` типизирован `array<array-key, mixed>`.
- **Прототипные list-узлы конфига сохраняют ключи YAML-map** (`{a: 500}`), а `SloBundle::flatten()` разворачивает map в параметры
  по ключам: list-узлы строятся через `listNode()` (`beforeNormalization` → `array_values`), иначе `bad_statuses` терялись молча.
- **Параметры бандла в тестах ломать только compiler pass'ом** (`tests/Integration/CallbackPass`, `TYPE_BEFORE_OPTIMIZATION`, приоритет 10):
  `TestContainer` загружает расширение во время `compile()`, правка параметра в `configure` до этого перезаписывается.
- **Defaults проверяются строго** (`DefaultsReader`): отсутствующий/неверного типа `msstc4symfony_slo.defaults.*` или невалидное окно/статусы —
  ошибка компиляции даже без HTTP-точек (раньше молча `30d`/`[]`).
- **Порог латентности ≤ 999999999 ms** (`LatencyThreshold::MAX_MILLISECONDS`): каноническая форма `toString()` обязана разбираться
  обратно (ms-форма — не больше 9 цифр); дамп каталога восстанавливает порог через `fromMilliseconds()`.
- **`ContainerBuilder::addResource(ClassExistenceResource)`** не запоминает классы, которые уже лежат в vendor (их отслеживает composer).
  Второй аргумент `ClassExistenceResource` — уже установленное существование, не флаг автозагрузки: `false` для существующего класса делает
  ресурс вечно несвежим (пересборка debug-контейнера на каждый запрос, если класс вне vendor). Селектор передаёт результат probe (R3-005);
  тест подменяет `addResource()` наследником `ContainerBuilder` и проверяет `isFresh()`.
- **Окно слитого SLO**: при разном написании одной длины (`30d`/`1mo`) берётся `Window::canonical()` (крупнейшая точная единица, `1mo`);
  одинаковые написания не меняются (golden-файлы те же). Окно не входит в ревизию.
- **Markdown coverage-дашборда**: `$` пишется как `&#36;` (интерполяция Grafana видит `$var`/`${__…}` до рендера Markdown), обратный слэш
  удваивается только перед ASCII-пунктуацией — обратные слэши FQCN остаются как есть.
- **Вне debug отсутствующий `route_index.php`** логируется на `slo_bundle` один раз на процесс (под FPM — на каждый запрос с HTTP SLO):
  это сигнал деградации, а не шум.

## acc:code-review high #2, батч D (2026-11-21 UTC)

- **Rector удаляет проверки, противоречащие PHPDoc**: при `@param list<non-empty-string> $components` проверка
  `array_is_list()`/`in_array('', …)` в конструкторе исчезает как мёртвый код (тест на невалидный ввод падает только после `make fix`).
  Сырой конфиг принимать как `array<array-key, mixed>`, узкий тип — у свойства после проверки. Rector запускать до тестов.
- **`@phpstan-ignore id`** гасит одну ошибку: при нескольких ошибках с одним идентификатором на строке идентификатор повторяют
  (`argument.type, argument.type, argument.type`).
- **opcache.file_update_protection (2 c)**: только что записанный PHP-файл opcache не кеширует и парсит на каждом include
  (`route_index.php` на 500 маршрутов — ~1.4 мс против ~2 мкс из кеша). Бенчмарк «cold request» состаривает файл `touch()`; в проде
  файл после деплоя старше двух секунд, но индекс, сохранённый с пути запроса, первые секунды парсится каждым запросом.
- **Холодный FPM-запрос** (500 HTTP SLO, прогретый индекс): ~195 мкс в батче D, основное — гидрация одного определения
  (`CatalogDump::definition()`: инварианты `Classification`/`StatusSet`, перебор кодов в `intersects()`). С батча E `intersects()` —
  пересечение интервалов: ~70 мкс против ~115 мкс старого кода в том же прогоне (машина шумит, сравнивать только в одном прогоне).
  Запрос пишет одно событие: HTTP availability по умолчанию — Strategy A (ряд metrics-bundle), dedicated только latency.
- **Runtime-индекс сохраняется** (`RouteIndexFile::persist()`): вне debug, один раз за процесс, только если нет корректного индекса своего каталога
  (корректный прогретый файл не перезаписывается; битый и с чужим отпечатком заменяются; чужой отпечаток логируется отдельной формулировкой, не «не прогрет»); read-only кеш с батча E проверяется до сборки (см. батч E). Kernel-тест: удалить файл после первой загрузки, контейнер из кеша не перезапускает warmer.
- **`SortedKeys` отвергает map, который после `ksort` стал бы списком** (ключи ровно 0..n-1 не по порядку): JSON поменял бы объект на
  массив. Смешанные числовые ключи (`'10'`, `'9'`) допустимы.
- **`%` в ключах дампа** отвергается централизованно в `CollectSloDefinitionsPass::escape()` (LogicException); `TaggedPointReader` по-прежнему
  даёт понятную ошибку компиляции раньше.
- **Покрытие с дробным `--min`** сравнивается в базисных пунктах (`monitored*10000 < round(required*100)*eligible`): float давал
  «7 из 10000 ниже 0.07».

## acc:code-review high #3, батч E (2026-11-21 UTC)

- **`RouteIndexFile::persist()`** сначала проверяет запись (`isWritable()`), затем строит: раньше read-only кеш стоил полной гидрации
  каталога и всех ревизий на каждый FPM-запрос (R3-001). Degraded-сообщение двух видов: «try to store the index for the next ones»
  (кеш писуем) и «cache directory is not writable … in every process». Тест read-only каталога пропускается под root (`is_writable`).
- **Отпечаток в `route_index.php`** (`fingerprint`, хэш дампа каталога): рукописные файлы в тестах пишутся хелпером `dump()` с
  `self::FINGERPRINT`, иначе файл отвергается раньше проверяемой структуры. Структурные случаи — в `RouteIndexParserTest`, файловые
  (версия, отпечаток, throw) — в `RouteIndexFileTest`.
- **Тесты файла индекса** пишут в уникальный каталог на тест (`getmypid()` + random): общий путь + opcache с
  `file_update_protection=0` давал чтение файла предыдущего теста (R3-010).
- **`InMemoryRouteIndexStore`** как файл: экземпляр читает то, с чем открыт; `persist()`/`write()` видны только `reopen()`;
  `persist()` не бросает и строит один раз; битая ревизия → null. Контракт проверяет это для фейка и файла (R3-011).
- **`metric_label` у tagged-точек**: одинаковая метка у точек одного типа → метка сбрасывается у всех (SLO → dedicated, warning в extras
  для точек с `#[Slo]`); один сервис с двумя тегами одной точки и разными метками — ошибка компиляции (R3-006/007). Решено не ошибкой
  компиляции: имена действий logic-bundle могут совпадать (короткие имена классов).
- **Порядок coverage** — `strcmp` по точке внутри вида: `<=>` массивов сравнивал числовые имена маршрутов как числа и был нетранзитивен
  (R3-008). Строка вида ниже порога печатает точную дробь `BELOW (m of e eligible points have an SLO)`; JSON и дашборд не менялись (R3-009).
- **`GenerationConfig`**: `application = ''` (env-плейсхолдер) = не настроено → `InvalidGenerationSettings::missingApplication()` (R3-017). Резолвленные `application`/`components` проверяются в `GenerationConfig::selector()` тем же `LABEL_VALUE` (константа живёт в `GenerationConfig`, дерево конфигурации её переиспользует): env-значение вне шаблона даёт `InvalidGenerationSettings` → `slo:generate` завершается с понятным сообщением, а не трейсом (R4-001).
- **Discovery-ридеры** (`HandlerReader`, `TaggedPointReader`) возвращают `Discovery\ReadResult<T>` (`items` + `warnings`); повторный идентичный тег с невалидным `metric_label` даёт ошибку компиляции один раз (R4-004/005).
- **`SortedKeys`** отвергает map с ключами ровно 0..n-1 не по порядку при любом размере (раньше только n ≤ 10, R3-021); разреженные
  int-ключи остаются map.
- **Связка series catalog** — `CollectSloDefinitionsPass` строит индекс меток `new MetricsBundleSeriesCatalog()`; что runtime-алиас тот же,
  закреплено тестом (аргумент `$series` у `MetricBindingResolver`) (R3-018).
- **Помощники публичных доменных типов** (`Classification::appliesTo/pointTypesOf/inapplicableFieldsFor`, `SloDefinition::withWindow`,
  `Window::canonical`, `LatencyThreshold::fromMilliseconds`, `PointType::acceptsIncludeErrors`) — `@internal` на методе; README это оговаривает.
- **Позиционные аргументы скомпилированных определений** в тестах — `tests/Integration/DefinitionArgument::of($definition, Class::class, 'param')`.

## Уникальность дашбордов (rc.3)

- **uid и заголовок дашборда зависят от `generation.selector.components`**: Grafana держит один дашборд на uid, а несколько
  Symfony-приложений одного `application` (showcase: gateway, orders, billing) раньше давали одинаковые uid и заголовки. Без
  компонентов uid = `sha256(title . application)` и golden-файлы не меняются; с компонентами в хеш входит `"\0" . implode(',', components)`,
  заголовок `SLO: <title> (<application>: <c1>, <c2>)`.

## Consumer-сервисы Messenger (rc.4)

- **Async определялся только по routing этого приложения**: в multi-service (showcase: billing потребляет `ProcessPayment`,
  routing — в orders) handler считался sync → ложный WARNING в `slo:validate` и `unsupported` в `slo:coverage` (гейт
  messenger_handler проверял 0 точек), хотя события пишутся и Strategy A читает `symfony_messenger_message_handled`.
  Runtime-путь async не проверяет — баг был только в discovery. Правило теперь в `integrations.md`.
- **Receive-only транспорт, а не «есть хоть один транспорт»**: в монолите с `async` + query bus правило «не
  маршрутизировано и есть транспорт» сделало бы все sync-handlers unmonitored. Ложное срабатывание осталось только когда
  приложение одновременно и consumer, и имеет sync-handlers незамаршрутизированных сообщений: coverage строже (они требуют
  `#[Slo]`/`#[IgnoreMonitoring]`), а `slo:validate` в этих ложно-async случаях молчит про реально sync-only SLO.
- **Не видно на компиляции (обход — `#[AsMessageHandler(fromTransport: '<transport>')]` или явный `routing`)**:
  (a) общий транспорт: сервис шлёт свои сообщения в `async` и читает оттуда же чужие — `async` цель routing, значит не
  receive-only, чужое незамаршрутизированное сообщение остаётся sync (ложный WARNING остаётся; закреплено кейсом
  `shared transport` в `MessengerRoutingTest`); (b) продюсер, чей транспорт — цель только `#[AsMessage]` на сообщениях,
  которые он сам не обрабатывает, или `TransportNamesStamp` — транспорт выглядит receive-only, незамаршрутизированные
  handlers становятся async.
- **Scheduler-транспорты** (`schedule://`, тег без `is_consumable`) исключены из receive-only — см. `integrations.md`.
  DSN ищется в аргументе `0`, `index_0` (replaced у ChildDefinition) или `$dsn`, иначе — у родителя ChildDefinition
  (до 10 уровней). Не распознаётся: DSN из env/параметра (`%env(...)%`, `%param%` — строка не начинается с
  `schedule://`), транспорт из фабрики-сервиса без DSN в аргументах, кастомный ресивер расписания под своим DSN — такой
  транспорт считается receive-only (обход: `fromTransport` или явный routing).
- **Сообщения Scheduler не определяются как async (1.0.1, решение — документировать, не угадывать).** Расписание
  собирается в рантайме (`RecurringMessage` в `ScheduleProviderInterface::getSchedule()`), на компиляции класса
  сообщения нет; `AddScheduleMessengerPass` превращает теги `scheduler.task` (`#[AsCronTask]`/`#[AsPeriodicTask]`) в
  `ServiceCallMessage`/`RunCommandMessage` из `Symfony\` (проверено по symfony/symfony 7.4, `gh api`). Handler
  сообщения расписания без `fromTransport` классифицируется общими правилами (routing, receive-only транспорты), то
  есть через транспорт расписания не распознаётся; обход — `#[AsMessageHandler(fromTransport: 'scheduler_<name>')]`
  (транспорт `schedule://` в `transports` считается worker-consumed).
- **`MessengerTestKernel`**: транспорт `priority` (нужен для per-transport `failure_transport`) стал бы receive-only и
  сделал `SendReceipt` async — поэтому `ProcessPayment` маршрутизирован в `['async', 'priority']`.
- **Сообщения на `sync://`** раньше считались async (непустой список sender'ов); теперь sync. Ревизии и сгенерированные
  файлы флаг async не затрагивает (`RevisionCalculator` его не читает) — golden-файлы не менялись.
- Тест consumer-приложения: `tests/Integration/Kernel/ConsumerOnlyKernelTest` (сообщение кладётся прямо в транспорт —
  имитация продюсера — и `messenger:consume` даёт одно событие).

## Alertmanager (1.1.0)

- `DeterministicYaml` сортирует ключи: в сгенерированных файлах `inhibit_rules` идёт перед `routes`/`receivers`, порядок не как в спецификации. Доки и сниппеты копируют порядок golden-файлов.
- `amtool config routes test` печатает только имя получателя; для лейблов вне фрагмента (другой `application`, `alertname=Other`) в слитом конфиге это корневой получатель пользователя.
- Каталог для amtool-контейнера и файлы внутри должны быть читаемы (`chmod 0755/0644`): `sys_get_temp_dir()` создаёт их с umask хоста, контейнер работает от другого uid.

## Scheduler discovery (1.2, Task 3)

- **`SchedulerTestKernel` регистрирует `clock` сам**: FrameworkExtension удаляет `clock`, если `symfony/clock` установлен только как
  dev-зависимость корневого пакета (`willBeAvailable`), а `scheduler.messenger_transport_factory` на него ссылается. В приложении,
  где scheduler в `require`, этого нет.
- **Rector в фикстурах**: `RemoveEmptyClassMethodRector` удаляет пустые не-магические методы (`daily(): void {}`) — методы фикстур
  возвращают значение; Symfony-правило превращает переопределённый `Command::getDefaultName()` в `#[AsCommand]` — фикстура
  `LegacyNamedCommand` поэтому не наследует `Command`.
- **Порог latency не принимает минуты** (`<int>ms|<decimal>s`), в отличие от `deadline` (`ms|s|m|h`): `new Latency('120s', 95)`.
- **`composer-ci.lock` содержал `git@` source.url** у metrics-bundle, sql-labeller, logic-bundle (из коммита f9bd2cd); в Task 3
  нормализованы в https. После `composer update` проверять `grep git@ composer-ci.lock`.
- CI-lock собирается с `symfony/scheduler` 8.1.10 при framework-bundle/console 7.4.23 — смешанная пара; `getDefaultName()`
  читает только наш `CommandName` (pass 8.1 его не вызывает), расхождение возможно лишь у команды без `#[AsCommand]` с
  переопределённым `getDefaultName()` на console 7.4.

## Worker-слушатель Scheduler (1.2, Task 4)

- **Rector превращает `EventSubscriberInterface` в `#[AsEventListener]`** (Symfony-набор): `getSubscribedEvents()` исчезает, тег
  `kernel.event_subscriber` в `services.php` ставить нельзя — подписка идёт через автоконфигурацию. Приоритеты проверяет
  `ScheduledTaskRunListenerTest` рефлексией атрибутов.
- **Kernel-тест задач**: `SchedulerSloTestKernel` маршрутизирует `ServiceCallMessage`/`RunCommandMessage` в `async` (in-memory,
  `max_retries: 1`, `delay: 0`) и регистрирует `clock`; сбой фикстур — статические флаги `CleanupTask::$failing`,
  `SyncPartnersCommand::$failing` (сбрасываются в `tearDown`). `task` в экспорте Prometheus экранирует `\` (FQCN) — тест снимает
  экранирование `stripslashes()`.
- `HardeningKernel` при установленном scheduler тоже включает `scheduler` и `CleanupTask`; `UseThrowingRecorderPass` подменяет
  recorder и у `MetricsScheduledTaskRunWriter`.
- Бенчмарк (2026-11-25 UTC, PHP 8.4.17) не изменился относительно d17a1dd: RecordEvent null recorder 5.2 мкс, с reset 5.5, in-memory 10.6,
  холодный запрос 40.7 мкс. Накладные расходы слушателя на сообщения воркера бенчмарк не измеряет.


## Незавершённые запуски в правилах (1.2, Task 5)

- Буквальная формула спеки `started[w] offset d - finished[w] offset d` считает незавершённым любой здоровый запуск,
  пересекающий `t-d`. Используется R1: старты в `[t-d-r, t-d]` минус финиши в `[t-d-r, t]`.
- **U — событийная серия** (fix rounds 1–3). Правило `slo:unfinished_runs:step` (первое в 1m-группе каждой task-цели, метки
  как у остальных записей цели) пишет U за один шаг: старты в `[t-d-1m, t-d]` минус финиши в `[t-d-1m, t]`. Убитый запуск
  появляется ровно один раз, ≈ start + d, как 1. Все окна (5m…30d, ratio, SLI, events, coverage-гейт) читают
  `(sum(sum_over_time(slo:unfinished_runs:step{...}[r])) or vector(0))`. Причина: per-series U прямо за окно r требует
  жизни серии ≥ d+r, а `instance`/`pod` меняется при каждой замене пода (metrics-bundle над общим Redis отдаёт счётчики с
  каждого пода) — окна длиннее жизни пода не видели U вовсе (вечно падающая задача → NoData на 6h/3d/30d, ticket не горит).
- Шаг U вычисляется по каждой серии из мгновенных значений: `clamp_min((S offset d - S offset (d+1m)) - (F' - F' offset (d+1m)), 0)
  unless ignoring (outcome) (resets(S[d+1m]) > 0 or resets(F[d+1m]) > 0)`, `F' = sum without (outcome) (F) or (S * 0)`, затем
  `sum(...) or vector(0)`. Increase не годится (экстраполяция разными множителями), `sum()` до вычитания — сбросы и смена набора серий.
- Сопоставление one-to-one по всем меткам, кроме `outcome`: started и finished экспортируются с одним набором меток (так их пишет
  metrics-bundle). Дубли серий (несколько target над общим Redis) умножают и T, и U — ratio не меняется. Не дедуплицировать max-ом.
- **Когда детекция теряется** (никогда не выдумывается): на шаге, где серии нет в одном из моментов `t-d-1m`, `t-d`, `t`
  (outage, неудачный scrape, первые d+1m свежей серии/Prometheus, смена пода) или был сброс счётчика в `[t-d-1m, t]` (flush Redis),
  её убитые запуски этого шага теряются. Пропущенная оценка правила (перегрузка, рестарт Prometheus) теряет U этого шага.
  Финиш другого запуска в `[t-d-1m, t]` может погасить убитый старт того же шага (clamp per series) — цена R1 для частых задач.
  Первый запуск новой серии не считается (серия появляется уже со значением 1).
- Sloth не может полагаться на записи бандла (его спецификации — альтернатива правилам бандла, `generation.prometheus.rules` может
  быть выключен), поэтому получает прямую per-series форму над `{{.window}}`: левый край — `last_over_time((X offset d)[1m:1m]
  offset {{.window}})`, resets — `resets(X[{{.window}}]) or resets(X[d+1m] offset {{.window}})` (на 1m дальше, чтобы покрыть τ-d
  шага сабквери). **Ограничение Sloth**: серия должна жить d + окно Sloth — при замене подов длинные окна Sloth (6h…30d) U не видят.
  `PromtoolTest::testSlothQueriesOfTasksAgreeWithTheRecordingRules` сверяет Sloth с правилами для 5m/30m/1h на стабильных сериях.
- Total задачи — `(total + U) or (U > 0)`: без серий вообще — NoData (`no-data.test.yml`), убитая задача без событий — 100 % bad.
- Coverage и `SloNoEvents` задачи считают started-серию данными (`sum(total) or sum(started)` в `timestamp`, `+ increase(started)`):
  иначе у вечно падающей задачи нет coverage и ticket не срабатывает (promtool `scheduled-task-multi-point.test.yml`, 3d).
- `increase` не экстраполирует к началу окна, если первое значение счётчика 0 (`durationToZero`); promtool-кейсы поэтому
  стартуют с 100 — иначе экстраполяция не проверяется вовсе.
- Recording-записи B несут метку `revision` — `exp_samples` в promtool должны её содержать.

## Итоги 1.2 (Task 7)

- **Упавшая запись `started` всё равно ведёт к записи `finished`**: старт кладётся в `WeakMap` до записи метрики, поэтому при сбое Redis
  на `started` исход всё равно пишет `finished`/событие — возможен дрейф finished > started на один запуск; U его клампит в 0.
- **Ключ `WeakMap` — объект сообщения**: пересоздание сообщения (`new` вместо `Envelope::with()`) теряет старт, исход без старта ничего не пишет.
- **Приоритет retry-слушателя 100**: `willRetry()` ставит `SendFailedMessageForRetryListener`; слушатель `failed` с приоритетом выше 100 принял бы
  повтор за финальную ошибку (kernel-тест `testRetriedAttemptIsNotAnSloEventAndFinishesAsRetried`).
- **Формула U (R1, раунды 3 Task 5)** — не `increase`-формула плана и не буквальная спеки, см. «Незавершённые запуски в правилах»: рекорд
  `slo:unfinished_runs:step` в 1m-группе перед читателями; цена — потеря шага при пропущенной оценке, отсутствующем моменте или сбросе, финиш
  другого запуска в `d+1m` может скрыть убитый старт, первый запуск новой серии не считается.
- **Порядок `-1000` / `0` / `0`** проверяется рефлексией атрибутов, а не kernel-тестом.
