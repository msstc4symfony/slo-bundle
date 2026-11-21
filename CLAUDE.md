# CLAUDE.md

Guidance for Claude Code in this repository. Deep references live under `.claude/docs/`.

## What this is

Symfony bundle (`msstc4symfony/slo-bundle`, namespace `Msstc4Symfony\SloBundle`, config alias
`msstc4symfony_slo`): Declarative SLI/SLO for Symfony: discovery, coverage gate, good/bad counters, Prometheus rules and Grafana dashboards. PHP >= 8.2, Symfony 7.4 / 8.x.
Library code only.

## Common commands

metrics-bundle 2.3.0 is unreleased: develop against the git-ignored local profile
`COMPOSER=composer-local.json composer install` (path repos `../metrics-bundle`, `../sql-labeller`; on a PHP
older than the `roave/security-advisories` floor add `--ignore-platform-req=php`).

- `make check` — `php -l`, PHPStan level 10, PHP-CS-Fixer, `composer validate --strict`,
  `composer audit`, Rector dry-run, deptrac. Run as `COMPOSER=composer-local.json make check`.
- `make test` — unit + integration suites; `make infection`, `make fix`, `make regenerate-baseline`.
- `php ../bundle-standard/bin/verify-standard.php .` — standard compliance (the same check CI runs).

## Architecture in 60 seconds

- `SloBundle` (`AbstractBundle`) defines the config tree and imports `src/Resources/config/services.php`.
- Use-cases are vertical slices `src/Application/<Aggregate>/<UseCase>/{Action,Input,Output}.php`.
- Domain model, integrations: `.claude/docs/domain-model.md`, `.claude/docs/integrations.md` (filled as tasks land).
- Layers and allowed dependencies are enforced by `deptrac.yaml`.

Details: `.claude/docs/architecture.md`. **Read `.claude/docs/known-issues.md` before chasing a
"weird" failure.**

## Pointers

- `.claude/docs/architecture.md` — layers, wiring, service ids.
- `.claude/docs/domain-model.md` — SLO model (stub).
- `.claude/docs/integrations.md` — metrics-bundle and bridges (stub).
- `.claude/docs/conventions.md` — layout, tests, commits.
- `.claude/docs/known-issues.md` — family-wide Symfony/CI traps.
