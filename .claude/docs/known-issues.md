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
- **metrics-bundle 2.3.0 не выпущен**: разработка идёт через git-ignored `composer-local.json` (path-репозитории
  `../metrics-bundle` как `2.3.0` и `../sql-labeller` как `1.0.0`, плюс vcs). `composer-ci.lock` остался от
  скелета и не пересобран; владелец пересобирает его после тега 2.3.0.

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
  `strategy: 'dedicated'`, чтобы видеть счётчики.
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
- **Дамп каталога**: `CatalogShape` теперь с `format` (= `CatalogDump::FORMAT`, 2), `point_index` (точка → индексы определений) и
  `conflicted_points`. `CompiledSloCatalog` гидрирует лениво по точке; несовпадение формата/битый дамп на runtime-пути = пусто + одно
  предупреждение, диагностические методы (`all/get/conflicts/shared/ignored/extras`) бросают `RuntimeException` «clear the cache».
  Дешёвые `hasHttpPoints()`/`failureTransports()` в `CatalogDiagnosticsInterface` — для наблюдателей, без гидрации extras.
- **`%` в аргументах сервиса** резолвится как параметр: `CollectSloDefinitionsPass::escape()` удваивает `%` в строковых значениях дампа.
  `ParameterBag::unescapeValue()` НЕ раскавычивает ключи массива (PhpDumper раскавычивает), поэтому `%` в ключах недопустим —
  единственный пользовательский источник ключей с `%` (id тега точки) отклоняется на компиляции.
- **Числовые имена маршрутов** (`404`) становятся int-ключами PHP-массивов: `RouteIndexData` кастует ключи в строку при каждом чтении
  и принимает int-ключи при разборе файла.
- **route_index.php версия 2**: секции `routes`, `controllers`, `points`, `revisions` валидируются по отдельности (`RouteIndexFile::points()`,
  `::revisions()`, `::load()`); горячий путь трогает только `points` и `revisions`. `revisions` = `"<slo>|<kind>" => 8 hex | ''`
  (`''` — Strategy A), их считает `SloCacheWarmer` резолвером поверх только что прочитанных маршрутов; `WarmedRevisions`
  (`PrecomputedRevisionsInterface`) отдаёт их `RecordEvent\Action`. В debug или без файла — runtime-резолв как раньше.
- **`RecordEvent\Action::reset()`** очищает только предупреждения и неудачные резолвы; успешные резолвы (стратегия+ревизия) живут
  весь процесс. Повторные сбои записи логируются один раз на `slo|objective|класс исключения` до reset (не больше 100 ключей).
  `RoutePointIndex` теперь `ResetInterface`: reset повторяет только неудачную сборку индекса.
- **`OperationMonitor`** принимает необязательный `Closure(Throwable): bool $isExpectedFailure` (по умолчанию logic-bundle
  `ActionOutcome`); сбой классификации = error, исключение пользователя пробрасывается без замены.
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
