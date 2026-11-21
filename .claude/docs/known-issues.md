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
