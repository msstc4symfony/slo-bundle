# Модель предметной области

Модель SLO: точки измерения, цели, окно, классификация событий, ревизии, каталог (разделы ниже по задачам плана).
Метки метрик конечны и известны на этапе компиляции: `slo`, `objective`, `revision`, `result`.

## Реализовано (Task 2, `src/Domain/`)

- Значения: `SloName`, `Target` (0 < t < 100, до 4 знаков, NaN/INF отвергаются), `Window` (`h|d|w|mo`, 1h..90d,
  месяц = 30 дней; `toPromQl()` — наибольшая точная единица из `w|d|h`), `LatencyThreshold` (целые миллисекунды),
  `Revision`, `PointId` (`<type>:<id>`), `StatusSet` (канонический вид — отсортированные строки `429`, `5xx`,
  `500-503`; `toRegex()` раскрывает диапазон в перечисление кодов), `Objective`, `Classification`, `Observation`.
- `SloDefinition` — неизменяемая сущность; `sameSemanticsAs()` сравнивает всё, кроме точек (ruling 1).
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
  `expectedFailureIsGood` на HTTP/Messenger, `badStatuses`/`ineligibleStatuses` вне HTTP, strategy не auto|dedicated,
  `#[Slo]` на двух handlers одного сообщения, одинаковое короткое имя у двух SLO-сообщений, `#[Slo]` на handler
  интерфейса/абстрактного класса сообщения (runtime ищет точку по конкретному классу, CR-003), `%` в `id` тега точки. Сообщение с SLO и > 1
  handler — warning в `extras()->warnings`.

## Наблюдения (CR-027)

- `Observation::outcome(PointType, Outcome, float)` принимает только `LogicAction|Operation` (иначе `InvalidArgumentException`);
  `RecordEvent\Input` проверяет, что тип наблюдения совпадает с типом точки.
