# Модель предметной области

Модель SLO: точки измерения, цели, окно, классификация событий, ревизии, каталог (разделы ниже по задачам плана).
Метки метрик конечны и известны на этапе компиляции: `slo`, `objective`, `revision`, `result`.

## Реализовано (Task 2, `src/Domain/`)

- Значения: `SloName`, `Target` (0 < t < 100, до 4 знаков, NaN/INF отвергаются), `Window` (`h|d|w|mo`, 1h..90d,
  месяц = 30 дней), `LatencyThreshold` (целые миллисекунды), `Revision`, `PointId` (`<type>:<id>`), `StatusSet`
  (канонический вид — отсортированные строки `429`, `5xx`, `500-503`), `Objective`, `Classification`, `Observation`.
  Синтаксис PromQL/RE2 в Domain не живёт (spec 4.1, CR-007): окно в PromQL (наибольшая точная единица из `w|d|h`) и
  шаблон статусов (`5..`, диапазон — перечисление кодов) рендерит `Infrastructure/Prometheus/PromQlRenderer`.
- `SloDefinition` — неизменяемая сущность; `sameSemanticsAs()` сравнивает всё, кроме точек (ruling 1). Конструктор
  сам держит инварианты, от которых зависит стабильность ревизии (CR-008): `includeErrors` только у `http_route`;
  поля классификации, неприменимые к типу точки, равны `Classification::defaults()` (статусы — только HTTP,
  `expectedFailureIsGood` — logic/operation, `countAttempts` — Messenger). `DefinitionFactory` проверяет то же раньше,
  чтобы собрать все ошибки разом; `CatalogDump::definition()` идёт через конструктор, так что битый дамп отвергается.
- Равенство VO — явные `equals()` (PHPStan запрещает `==`); пустые списки в `SloDefinition` проверяет
  `assertNotEmpty()`, а не inline-условие: Rector удаляет такую проверку для `non-empty-list`.
- `SloCatalogInterface` включает `conflictedNamesFor(PointId)` (R1, реализация — Task 5).

## Классификация и ревизия (DomainService, Task 3)

- `RevisionCalculator::revision(SloDefinition, ObjectiveKind, list<string> $failureTransports)` (вызывать через `Application\Binding\RevisionResolver`, он подставляет транспорты из каталога): sha256 (первые 8 hex) от канонического JSON (`JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES`, ключи отсортированы): `bad_statuses`, `count_attempts`, `expected_failure_is_good`, `include_errors`, `ineligible_statuses`, `kind`, `point_type`, `threshold_ms`. Для `messenger_message` добавляется `failure_transports` (отсортированный, всегда, даже пустой; CR-014); для latency у logic_action/operation ключ `expected_failure_is_good` удаляется (CR-013). Цель, окно, имя, точки, стратегия в ревизию не входят. Закреплённые эталоны: latency 200ms, http_route, дефолты = `bd652502`; messenger availability с `['failed','dead']` = `a098f2a6`. Объявленный, но отсутствующий objective kind -> `InvalidArgumentException`.
- `EventClassifier::classify(...)`: `null` = событие не учитывается. Latency HTTP/Messenger: eligible, если availability-eligible и не bad (кроме `includeErrors`); latency logic/operation (outcome-точки): eligible всё, кроме `error`, независимо от `expectedFailureIsGood` (CR-013, spec 4.4). Combined: bad -> bad, иначе по порогу. Delivery с `null` duration для latency и для не-bad combined даёт `null`.
- `StatusSet::canonical()` семантический: элементы, целиком входящие в другой элемент, отбрасываются (`['5xx','500']` -> `['5xx']`); при равных границах (`'5xx'` и `'500-599'`) остаётся один — форма `Nxx` (сортируется последней), раньше пропадали оба (CR-001); пересекающиеся диапазоны не склеиваются. Поэтому избыточные декларации дают одну ревизию и `Classification::equals`.

## Каталог и точки (Task 5)

- Точки на компиляции: HTTP — `http_route:Class::method` (маршрут привязывается позже, Task 6), Messenger —
  `messenger_message:<FQCN сообщения>` (SLO на handler = SLO на envelope сообщения), тег — `<type>:<id>` из атрибутов
  тега, операция — `operation:<name>` (имя операции = имя SLO, strategy всегда dedicated).
- `HandlerInfo::$async` («handler читает worker») влияет только на `slo:validate` (WARNING про sync-only SLO) и
  `slo:coverage` (`unsupported`); в ревизию, правила и дашборды не входит. Правило — `integrations.md` (rc.4).
- Классовый `#[Slo]`/`#[IgnoreMonitoring]`: у invokable-контроллера — только `__invoke` (класс и `__invoke` — одна цель,
  `#[Slo]` + `#[IgnoreMonitoring]` между ними = ошибка); у не-invokable — на каждый action-метод, но атрибуты метода
  перекрывают классовые в обе стороны (метод `#[IgnoreMonitoring]` снимает классовый `#[Slo]`, метод `#[Slo]` — классовый
  ignore). Action-метод: публичный, не static, не abstract, не `__*` (кроме `__invoke`), без `#[Required]`, не из
  `AbstractController`. Оба атрибута на одном методе — ошибка; у handler класс и метод складываются (без перекрытия).
- Ruling 1: одинаковые объявления одного имени сливаются (`withPoint`, точки по алфавиту) и видны в `shared()`;
  различающиеся или повтор имени на одной точке — `conflicts()` (имя → `SloDeclaration` по точкам), такого имени нет в
  `all()/get()/forPoint()`, `conflictedNamesFor(point)` его отдаёт.
- Неприменимые к типу точки поля классификации берутся из `Classification::defaults()` (а не из конфига), чтобы смена
  чужого дефолта не меняла ревизии: HTTP — статусы из `defaults.http.*`; Messenger — `count_attempts`; logic/operation —
  `defaults.logic.expected_failure_is_good`.
- Ошибки опций: `countAttempts` вне Messenger, `includeErrors: true` вне HTTP (в т.ч. в `operations`),
  `expectedFailureIsGood` на HTTP/Messenger, `badStatuses`/`ineligibleStatuses` вне HTTP, неверный тип аргумента атрибута (например `strategy` не `StrategyPreference` — TypeError, `invalid attribute: ...`),
  `#[Slo]` на двух handlers одного сообщения, одинаковое короткое имя у двух SLO-сообщений, `#[Slo]` на handler
  интерфейса/абстрактного класса сообщения (runtime ищет точку по конкретному классу, CR-003), `%` в `id` тега точки. Сообщение с SLO и > 1
  handler — warning в `extras()->warnings`.

## Наблюдения (CR-027)

- `Observation::outcome(PointType, Outcome, float)` принимает только `LogicAction|Operation` (иначе `InvalidArgumentException`);
  `RecordEvent\Input` проверяет, что тип наблюдения совпадает с типом точки.
- `SloDefinition::strategyPreference(): StrategyPreference` (до батча D — `strategy()`): объявленное предпочтение (`auto|dedicated`); фактическая стратегия цели (`Application\Binding\Strategy`) решается `MetricBindingResolver`. Ключ цели SLO — `Application\Binding\ObjectiveKey` (`"<slo>|<kind>"`).

## Deadline задач Scheduler (1.2)

- `Deadline` (мс, 1..999 999 999, грамматика `<int>ms|<decimal>s|<int>m|<int>h`) — не входит в ревизию, входит в `sameSemanticsAs`
  (сравнивается эффективный `runDeadline()`: `deadline: '2m'` при `Latency('120s')` ≡ без deadline; так же `DeclarationDiff`)
  и в дамп каталога (`deadline_ms`, `CatalogDump::FORMAT = 4`). `SloDefinition::deadline()` — объявленный; `runDeadline()` — объявленный,
  иначе порог latency, иначе combined; `null` вне `scheduled_task`. Инварианты сущности: deadline только у `scheduled_task`,
  а у задачи `runDeadline() !== null`; понятные сообщения для пользователя выдаёт `DefinitionFactory` до конструктора.
- `SloDefinition::isPastRunDeadline(float)` — длительность строго больше `runDeadline()`. Такой запуск правила уже засчитали как
  незавершённый (bad в момент start + d), поэтому `EventClassifier` делает его наблюдение не eligible для всех целей этого SLO
  (CR-001, вариант A), а `ScheduledTaskRunListener` вообще не зовёт recorder, если запуск опоздал для всех SLO точки.
  Следствие: при deadline = порогу latency медленный успешный запуск bad только через U.
- `slo:validate` сравнивает `runDeadline()` с `generation.prometheus.scrape_interval_seconds` (по умолчанию 60).
- `Observation::outcome(PointType, Outcome, float, bool $onFailureTransport = false)` принимает `LogicAction|Operation|ScheduledTask`; флаг
  failure-транспорта допустим только у `ScheduledTask` (иначе `InvalidArgumentException`) и делает наблюдение не eligible для любой цели.
  `PointType::ScheduledTask` = `scheduled_task`; `expectedFailureIsGood` применим и к задачам (дефолт — `defaults.logic.expected_failure_is_good`).
- Незавершённый запуск — плохое событие любой цели SLO задачи (availability, latency, combined): входит в bad и в total (формула — `known-issues.md`).
