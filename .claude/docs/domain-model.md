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
