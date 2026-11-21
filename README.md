# msstc4symfony/slo-bundle

![Build Status](https://github.com/msstc4symfony/slo-bundle/actions/workflows/checks.yml/badge.svg?branch=main)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Declarative SLI/SLO for Symfony: discovery, coverage gate, good/bad counters, Prometheus rules and Grafana dashboards

## Compatibility

| Bundle | PHP  | Symfony  |
|--------|------|----------|
| 1.x    | 8.2+ | 7.4, 8.x |

Symfony 8.x needs PHP 8.4; on PHP 8.2 and 8.3 Composer installs Symfony 7.4.

## Installation

The package is not on Packagist yet, so register its GitHub repository first:

```sh
composer config repositories.msstc4symfony-slo-bundle vcs https://github.com/msstc4symfony/slo-bundle
composer require msstc4symfony/slo-bundle
```

Symfony Flex registers the bundle automatically; without Flex add it to `config/bundles.php`:

```php
Msstc4Symfony\SloBundle\SloBundle::class => ['all' => true],
```

## Configuration

```yaml
msstc4symfony_slo:
    greeting: Hello   # non-empty string
```

## Usage

```php
use Msstc4Symfony\SloBundle\Application\Greeting\Greet\Action;
use Msstc4Symfony\SloBundle\Application\Greeting\Greet\Input;

final readonly class Welcome
{
    public function __construct(private Action $greet)
    {
    }

    public function __invoke(): string
    {
        return ($this->greet)(new Input('Ada'))->message; // "Hello, Ada!"
    }
}
```

## Layers

`src/` follows the canonical layout of [bundle-standard](https://github.com/msstc4symfony/bundle-standard)
(`templates/deptrac.yaml`). The list is closed — `verify-standard` rejects any other directory:

- `Domain/` — data in `Entity/`, `Document/`, `ValueObject/`, `Enum/`, `Event/`, `Repository/`, `DocumentRepository/`;
  operations in `Service/`, `Helper/`, `Specification/`;
- `Application/`, `Infrastructure/`, `Presentation/`, `Framework/`;
- `DependencyInjection/` (compiler passes in `CompilerPass/`);
- `Resources/` (configuration only, no classes) and the bundle class `<Name>Bundle.php`.

Empty layers need no directory. `deptrac.yaml` starts with the full canonical ruleset; narrow it as the bundle's
layers settle, never widen it.

## Local development

```sh
make install-ci                        # composer-ci.json profile with every CI tool
COMPOSER=composer-ci.json make check   # lint, PHPStan, CS, composer validate/audit, Rector, deptrac
make test                              # unit + integration
```

## License

MIT — see [LICENSE](LICENSE).
