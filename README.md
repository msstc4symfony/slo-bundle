# msstc4symfony/slo-bundle

![Build Status](https://github.com/msstc4symfony/slo-bundle/actions/workflows/checks.yml/badge.svg?branch=main)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Declarative SLI/SLO for Symfony: discovery, coverage gate, good/bad counters, Prometheus rules and Grafana dashboards

Built on [`msstc4symfony/metrics-bundle`](https://github.com/msstc4symfony/metrics-bundle) 2.3: good and bad events
are counted as `symfony_slo_events_total` through its storage and exported on its metrics endpoint.

> Status: under development, not released yet.

## Compatibility

| Bundle | PHP  | Symfony  |
|--------|------|----------|
| 1.x    | 8.2+ | 7.4, 8.x |

Symfony 8.x needs PHP 8.4; on PHP 8.2 and 8.3 Composer installs Symfony 7.4.

## Installation

The packages are not on Packagist yet, so register their GitHub repositories first:

```sh
composer config repositories.msstc4symfony-sql-labeller vcs https://github.com/msstc4symfony/sql-labeller
composer config repositories.msstc4symfony-metrics vcs https://github.com/msstc4symfony/metrics-bundle
composer config repositories.msstc4symfony-slo vcs https://github.com/msstc4symfony/slo-bundle
composer require msstc4symfony/slo-bundle
```

Symfony Flex registers the bundle automatically; without Flex add it to `config/bundles.php` after `MetricsBundle`:

```php
Msstc4Symfony\SloBundle\SloBundle::class => ['all' => true],
```

## Layers

`src/` follows the canonical layout of [bundle-standard](https://github.com/msstc4symfony/bundle-standard)
(`templates/deptrac.yaml`). The list is closed — `verify-standard` rejects any other directory:

- `Domain/` — data in `Entity/`, `Document/`, `ValueObject/`, `Enum/`, `Event/`, `Repository/`, `DocumentRepository/`;
  operations in `Service/`, `Helper/`, `Specification/`;
- `Application/`, `Infrastructure/`, `Presentation/`, `Framework/`;
- `DependencyInjection/` (compiler passes in `CompilerPass/`);
- `Resources/` (configuration only, no classes) and the bundle class `SloBundle.php`.

## Local development

metrics-bundle 2.3.0 is unreleased: develop against the git-ignored `composer-local.json` profile (path repositories
for `../metrics-bundle` and `../sql-labeller`).

```sh
COMPOSER=composer-local.json composer install
COMPOSER=composer-local.json make check   # lint, PHPStan, CS, composer validate/audit, Rector, deptrac
make test                                 # unit + integration
```

## License

MIT — see [LICENSE](LICENSE).
