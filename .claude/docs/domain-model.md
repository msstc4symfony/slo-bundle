# Модель предметной области

Заглушка: наполняется задачами плана (SLO, точки измерения, цели, окно, классификация событий).
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

- `RevisionCalculator::revision(SloDefinition, ObjectiveKind)`: sha256 (первые 8 hex) от канонического JSON (`JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES`, ключи отсортированы): `bad_statuses`, `count_attempts`, `expected_failure_is_good`, `include_errors`, `ineligible_statuses`, `kind`, `point_type`, `threshold_ms`. Цель, окно, имя, точки, стратегия в ревизию не входят. Закреплённый эталон: latency 200ms, http_route, дефолты = `bd652502`. Объявленный, но отсутствующий objective kind -> `InvalidArgumentException`.
- `EventClassifier::classify(...)`: `null` = событие не учитывается. Latency: eligible, если availability-eligible и не bad (кроме `includeErrors`). Combined: bad -> bad, иначе по порогу. Delivery с `null` duration для latency и для не-bad combined даёт `null`.
- `StatusSet::canonical()` семантический: элементы, целиком входящие в другой элемент, отбрасываются (`['5xx','500']` -> `['5xx']`); пересекающиеся диапазоны не склеиваются. Поэтому избыточные декларации дают одну ревизию и `Classification::equals`.
