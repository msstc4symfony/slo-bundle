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

- `TestContainer` (голый `ContainerBuilder`) регистрирует заглушки `RouterInterface` и `LoggerInterface`: сервисы бандла
  (`RouterRouteCatalog`, `SloCacheWarmer`) автовайрят их из FrameworkBundle, без заглушек компиляция падает.
- `tests/Integration/Kernel/RoutedTestKernel` — ядро с произвольным набором контроллеров и маршрутов (`add()->controller([Class,
  method])`); кеш-каталог зависит от хеша сценария, иначе ядра с разными маршрутами в одном процессе делят кеш.
- Правило `slo:validate` про `httpIgnoredRoutes` сравнивает имя маршрута точно, как `RequestEventListener` metrics-bundle.
- Сервис контроллера с id, не равным классу, и маршрутом `service_id::method` разрешается только через тег
  `controller.service_arguments` (так их находит discovery); контроллеры без этого тега в `controllerServices` не попадут.
- Пространство имён `Application\Catalog\List` допустимо (`List` — зарезервированное слово только как имя класса).

## Strategy resolver (Task 7)

- `SeriesBinding` хранит матчеры как `Matcher`, а не `array<string,string>`: оператор (`=`, `!=`, `=~`, `!~`) нельзя однозначно закодировать в строке.
- Формат `le` (`(string) $bucket`) не сверялся с реальным экспортом metrics-bundle; Task 12 должен проверить на живых сериях.
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
- HTTP kernel-тест полагается на то, что тривиальный контроллер после прогревочного запроса укладывается в 200ms;
  пограничные пороги проверяются юнит-тестами наблюдателя с построенными measurement.
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
