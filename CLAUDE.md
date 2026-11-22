# CLAUDE.md

Guidance for Claude Code in this repository. Deep references live under `.claude/docs/`.

## What this is

Symfony bundle (`msstc4symfony/slo-bundle`, namespace `Msstc4Symfony\SloBundle`, config alias
`msstc4symfony_slo`): Declarative SLI/SLO for Symfony: discovery, coverage gate, good/bad counters, Prometheus rules and Grafana dashboards. PHP >= 8.2, Symfony 7.4 / 8.x.
Library code only.

## Common commands

Install the CI profile: `COMPOSER=composer-ci.json composer install`. To develop against sibling checkouts use the
git-ignored local profile `COMPOSER=composer-local.json composer install` (path repos `../metrics-bundle`, `../sql-labeller`).
`--ignore-platform-req=php` is needed only on the author's machine (local PHP older than the
`roave/security-advisories` floor); it is not a standing instruction.

- `make check` — `php -l`, PHPStan level 10, PHP-CS-Fixer, `composer validate --strict`,
  `composer audit`, Rector dry-run, deptrac. Run as `COMPOSER=composer-ci.json make check` (or with the local profile).
- `make test` — unit + integration suites; `make infection`, `make fix`, `make regenerate-baseline`.
- `make test-promtool` — rule tests with promtool and sloth in Docker (`tests/Promtool`, never skipped);
  `make benchmark` — per-event overhead (`tests/Benchmark`). Both targets live in `GNUmakefile` because
  `Makefile` and `phpunit.xml.dist` are byte-pinned by bundle-standard.
- `php ../bundle-standard/bin/verify-standard.php .` — standard compliance (the same check CI runs).

## Architecture in 60 seconds

- `SloBundle` (`AbstractBundle`) defines the config tree and imports `src/Resources/config/services.php`.
- Use-cases are vertical slices `src/Application/<Aggregate>/<UseCase>/{Action,Input,Output}.php`.
- Domain model, integrations: `.claude/docs/domain-model.md`, `.claude/docs/integrations.md`.
- Runtime is fail-open: nothing from SLO code reaches application code (logged on channel `slo_bundle`);
  public API is listed in the README, everything else is `@internal`.
- Layers and allowed dependencies are enforced by `deptrac.yaml`.

Details: `.claude/docs/architecture.md`. **Read `.claude/docs/known-issues.md` before chasing a
"weird" failure.**

## Pointers

- `.claude/docs/architecture.md` — layers, wiring, service ids.
- `.claude/docs/domain-model.md` — SLO model: points, objectives, classification, revisions, catalog.
- `.claude/docs/integrations.md` — metrics-bundle, Messenger, bridges, what discovery reads.
- `.claude/docs/conventions.md` — layout, tests, commits.
- `.claude/docs/known-issues.md` — Symfony/CI traps, per-task gotchas, hardening and benchmark notes.
