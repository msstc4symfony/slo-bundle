# msstc4symfony/slo-bundle

![Build Status](https://github.com/msstc4symfony/slo-bundle/actions/workflows/checks.yml/badge.svg?branch=main)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Declarative SLI/SLO for Symfony: discovery, coverage gate, good/bad counters, Prometheus rules and Grafana dashboards

Built on [`msstc4symfony/metrics-bundle`](https://github.com/msstc4symfony/metrics-bundle) 2.3: good and bad events
are counted as `symfony_slo_events_total` through its storage and exported on its metrics endpoint.

> Status: release candidate of 1.0, not released yet.

- [Compatibility](#compatibility)
- [Installation](#installation)
- [Declaring SLOs](#declaring-slos)
- [What is measured](#what-is-measured)
- [Classification of events](#classification-of-events)
- [Strategies: existing series or dedicated counters](#strategies-existing-series-or-dedicated-counters)
- [Revisions and warming up](#revisions-and-warming-up)
- [Operations API](#operations-api)
- [Configuration reference](#configuration-reference)
- [Commands](#commands)
- [Generation and GitOps provisioning](#generation-and-gitops-provisioning)
- [Failure semantics: fail-open and Redis outages](#failure-semantics-fail-open-and-redis-outages)
- [Bridges and measurement points](#bridges-and-measurement-points)
- [Public API](#public-api)
- [Upgrade notes](#upgrade-notes)
- [Layers](#layers)
- [Local development](#local-development)
- [Running the rule tests](#running-the-rule-tests)
- [Overhead](#overhead)

## Compatibility

| Component    | Supported                                                                                     |
|--------------|-----------------------------------------------------------------------------------------------|
| PHP          | 8.2 - 8.5 (`php82` profile of bundle-standard); 8.2 gets security fixes until 2026-12-31 UTC   |
| Symfony      | `^7.4 \| ^8.0`; Symfony 8.x needs PHP 8.4, on 8.2 and 8.3 Composer installs 7.4               |
| metrics-bundle | `^2.3` (2.3.0 is the minimum: HTTP 5xx are invisible to the universal series before it)     |
| Prometheus   | 3.x, rule files tested with `promtool` of 3.15; the rule format has not changed since 2.x      |
| Alertmanager | any version that accepts Prometheus 3 alerting rules; only rules are generated, no Alertmanager config |
| Grafana      | 12.x - 13.x, classic dashboard JSON (`schemaVersion` 41) with file provisioning                |
| RoadRunner   | through `baldinof/roadrunner-bundle ^3.4`; services are reset on `kernel.reset`, see [state isolation](#failure-semantics-fail-open-and-redis-outages) |
| Sloth        | optional generator, spec `prometheus/v1`, validated with Sloth 0.16                            |

## Installation

The packages are not on Packagist yet, so register their GitHub repositories first:

```sh
composer config repositories.msstc4symfony-sql-labeller vcs https://github.com/msstc4symfony/sql-labeller
composer config repositories.msstc4symfony-metrics vcs https://github.com/msstc4symfony/metrics-bundle
composer config repositories.msstc4symfony-slo vcs https://github.com/msstc4symfony/slo-bundle
composer require msstc4symfony/slo-bundle
```

Symfony Flex registers the bundle automatically; without Flex add it to `config/bundles.php` after `MetricsBundle`
(the container fails to compile with a hint when `MetricsBundle` is missing):

```php
Msstc4Symfony\SloBundle\SloBundle::class => ['all' => true],
```

Minimal configuration for generation (the application name is never read from the runtime environment, so the output
is the same on a laptop and in CI):

```yaml
# config/packages/msstc4symfony_slo.yaml
msstc4symfony_slo:
    generation:
        selector:
            application: shop          # the "application" label of your metrics
            components: [web, worker]  # optional, default: every component
```

## Declaring SLOs

`#[Slo]` goes on a controller method, an invokable controller class or its `__invoke`, a Messenger handler class or
method, or a class of any service tagged as a [measurement point](#bridges-and-measurement-points). The attribute is
repeatable: different names are independent SLOs on the same point.

```php
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\IgnoreMonitoring;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;

#[Route('/payments', methods: ['POST'])]
#[Slo(
    name: 'payment.create',
    objectives: [
        new Availability(target: 99.95),
        new Latency(threshold: '200ms', target: 99.0),
    ],
    window: '30d',
)]
public function create(): Response { /* ... */ }

#[IgnoreMonitoring(reason: 'Internal debug endpoint')]
public function debug(): Response { /* ... */ }
```

| Attribute / argument | Meaning |
|----------------------|---------|
| `Availability(target)` | share of eligible events that are not bad, `0 < target < 100`, up to 4 decimals |
| `Latency(threshold, target, includeErrors: false)` | share of eligible events not slower than `threshold` (`<int>ms` or `<decimal>s`, stored as whole milliseconds, 1ms to 999999999ms); `includeErrors` is HTTP only |
| `Combined(threshold, target)` | good = not bad **and** not slower than `threshold`; eligible like availability |
| `name` | unique per application, `^[a-z][a-z0-9_.-]{0,62}$`; becomes the `slo` label |
| `window` | `<N>h`, `<N>d`, `<N>w`, `<N>mo` (month = 30 days; `m` is minutes and rejected), 1h - 90d, default `defaults.window` |
| `badStatuses`, `ineligibleStatuses` | HTTP only: codes, ranges (`500-503`) or classes (`5xx`) |
| `expectedFailureIsGood` | logic actions and operations only |
| `countAttempts` | Messenger only |
| `strategy` | `StrategyPreference::Auto` (default) or `StrategyPreference::Dedicated` (`Domain\Enum\StrategyPreference`) to force dedicated counters |
| `IgnoreMonitoring(reason)` | excludes the point from the coverage gate; the reason is mandatory |

The same name on several points with identical declarations is one SLO over all of them (`slo:list` marks it
`shared by N points`). The same name with different declarations, or twice on one point, does not break the build:
the catalog keeps every declaration as a conflict, nothing is recorded for that name (one warning per process),
`slo:list` marks it `DUPLICATE`, `slo:validate` exits 1 and lists the differing fields, and coverage marks the points
`invalid`. Every other error (bad window, target out of range, `#[Slo]` on two handlers of one message, an option that
does not apply to the point type) is a container compilation error listing all problems at once.

## What is measured

Events come from the metrics-bundle measurement observers, with the duration metrics-bundle measured (no second timer):

- HTTP: one event per recorded main request of a route whose controller has `#[Slo]`. Unrouted requests, `OPTIONS`,
  `http.ignored_routes`, sub-requests and fragments are not events; the page that renders a fragment is one event.
  An uncaught exception is classified by the resulting status (500 by default), once.
- Messenger: one event per final worker outcome of an envelope (`handled`, or `failed` after the last retry);
  retries are events only with `countAttempts: true`, anything consumed from a failure transport
  (`messenger:failed:retry`) never is. Batch handlers (`BatchHandlerInterface`) are counted per envelope like any async message.
  Synchronously handled messages are not measured (`slo:coverage` reports them as `unsupported`, `slo:validate` warns
  about an SLO on a message that is never routed to a transport). `#[Slo]` on a handler is an SLO on the envelope of
  its message; a message with several handlers gets a validation warning. The handler must take a concrete message
  class: events are matched by the dispatched message's class, so `#[Slo]` on a handler of an interface or abstract
  class is a compilation error.
- Operations: one event per finished handle, see [Operations API](#operations-api).
- Logic actions: through the `slo-bridge-logic` package, see [Bridges](#bridges-and-measurement-points).

## Classification of events

Defaults, all overridable per SLO. An *eligible* event enters the denominator; *bad* events lower the ratio.

| Point | Eligible event | Availability: bad | Latency: eligible |
|-------|----------------|-------------------|-------------------|
| HTTP route | finished main request of the route | status `>= 500` (includes uncaught exceptions); 4xx are good, they are client errors | only availability-good events |
| Messenger message | final outcome of the envelope on its source transport: `handled` or `failed` without retry; `retried` and every event on a failure transport are not eligible | `failed` | only `handled` |
| Logic action | action call | `error`; `expected_failure` is good | `success` and `expected_failure` |
| Operation | finished handle | `error` | everything except `error` |

- HTTP and Messenger: latency is eligible only for availability-good events, unless `Latency(includeErrors: true)`
  (then every availability-eligible event; `includeErrors` applies to HTTP routes only, elsewhere it is a compilation
  error). Logic actions and operations: latency covers every run that is not an `error`, also with
  `expectedFailureIsGood: false` (which changes availability and `Combined` only).
  `Combined` is bad when the event is bad or slow.
- `countAttempts: true` (Messenger): every attempt is an event - handled is good, failed and retried are bad;
  failure-transport events stay ineligible.
- Failure transports are detected at compile time from the framework's failure-transport listener and can be set with
  `defaults.messenger.failure_transports`.
- Status sets are canonical: `['5xx', '500-599']` is the same set as `['5xx']`.
- Empty denominator is NoData, never 100 %.

## Strategies: existing series or dedicated counters

For each objective the bundle either reads series metrics-bundle already exports (Strategy A, nothing extra is
written) or counts good and bad events itself (Strategy B, `symfony_slo_events_total`). The choice is visible in
`slo:list` and `slo:validate`.

| Point / objective | Strategy A when | otherwise B |
|-------------------|-----------------|-------------|
| HTTP availability | classification is the default or expressible through the `status` label | observer |
| HTTP latency | `includeErrors: true`, and the threshold equals a bucket of `request_duration_histogram_seconds` | observer |
| Messenger availability | `countAttempts` not set (`handled + failed`, failure transports excluded) and no other handled message shares the short class name (metrics-bundle labels messages by short name; `slo:validate` warns) | observer |
| Messenger latency | `countAttempts` not set (`countAttempts: false`), the threshold is a bucket boundary, and no other handled message shares the short class name (`slo:validate` warns) | observer |
| Logic action | availability: `expectedFailureIsGood` is default; latency: the threshold is a bucket of `logic_action_duration_histogram_seconds` | `slo-bridge-logic` |
| Operation, `Combined` | never | always B |

`strategy: StrategyPreference::Dedicated` forces B. A threshold matches a bucket when the bucket, as metrics-bundle exposes it, equals
`thresholdMs / 1000` up to float noise (relative 1e-9): `0.25` matches `250ms`, `0.2504` does not. The generated
`le` matcher uses the form Prometheus 3 stores on ingestion (Go's shortest float with `.0` for integral values):
a bucket exposed as `le="1"` is selected as `le="1.0"`, `1e6` as `le="1e+06"`.

## Revisions and warming up

Every dedicated series carries `revision`, the first 8 hex digits of a hash of the event semantics: objective kind,
eligibility and good rule, threshold, point type, classification overrides, and for Messenger SLOs the sorted list of
failure transports (it decides which deliveries are eligible). `expectedFailureIsGood` is not part of the latency
revision of logic actions and operations, whose latency does not depend on it. Target, window, name and the list of
points are **not** part of it. Consequences:

- Changing a threshold from `200ms` to `100ms` creates a new revision. Generated rules filter only the current
  revision, so old and new data are never summed; `slo:window:coverage_ratio` stays below 1 until the new revision
  has covered the whole window.
- While the coverage ratio is below 1 the SLO is *warming up*: dashboards show it together with the expected end
  (`Warming up until`, a UTC date computed as `time() + (1 - coverage) * window`), ticket alerts on the long windows
  are suppressed, page alerts on short windows stay active.
- **Prometheus retention must be at least the longest SLO window plus 1h.** The coverage ratio looks one window back;
  with a shorter retention it never reaches 1, the SLO stays warming up forever and the ticket alerts never fire.
- Changing the target or the window is recomputed by the rules instantly from the same data.
- Changing the canonical form of the hash itself would change every revision; it is pinned by a test.

## Operations API

Measure arbitrary operations that are not routes, handlers or actions. Declare them in the configuration (they are
always dedicated) and inject `OperationMonitorInterface`:

```yaml
msstc4symfony_slo:
    operations:
        payment.validation:
            objectives:
                - { type: availability, target: 99.9 }
                - { type: latency, threshold: 50ms, target: 99 }
            window: 30d
```

```php
$result = $monitor->measure('payment.validation', fn (): Result => $this->validator->validate($payment));

$handle = $monitor->start('payment.validation');
try {
    $this->validator->validate($payment);
    $handle->succeed();
} catch (ExpectedProblem $e) {
    $handle->failExpected($e);   // good for availability when expectedFailureIsGood is true
} catch (Throwable $e) {
    $handle->fail($e);
} finally {
    $handle->end();              // idempotent; the first outcome wins, no outcome means success
}
```

`measure()` rethrows the exception of the closure unchanged and classifies it as logic-bundle classifies its Actions
when logic-bundle is installed (`ActionOutcome::of()`: `ExpectedFailureInterface` and Symfony's
`ValidationFailedException` are expected failures); without logic-bundle every exception is an error. An undeclared or invalid
name records nothing and logs one warning per name until the next `kernel.reset`. The clock is `hrtime(true)`, no sampling.

## Configuration reference

```yaml
msstc4symfony_slo:
    defaults:
        window: 30d                          # default window of every SLO
        http:
            bad_statuses: ['5xx']
            ineligible_statuses: []
        logic:
            expected_failure_is_good: true
        messenger:
            count_attempts: false
            failure_transports: null         # null = detect at compile time, or a list of transport names
    coverage:
        http_routes: { required_percent: 95, ignored_routes: [] }
        messenger_handlers: { required_percent: 95 }
        logic_actions: { required_percent: 0 }
    operations: {}                           # see "Operations API"
    generation:
        selector:
            application: ~                   # required by slo:generate; [A-Za-z0-9_.:-]{1,128}
            components: []                   # [] = no component filter; same pattern
        output_dir: '%kernel.project_dir%/infrastructure/observability'
        grafana: { datasource_uid: prometheus, folder: SLO }
        prometheus:
            rules: true
            alerts: true
            no_events_alert_hours: ~         # opt-in "no events for N hours" alert
        sloth: false
```

## Commands

| Command | Purpose | Exit codes |
|---------|---------|------------|
| `slo:list [--format=text\|json]` | declared SLOs with points, routes, strategy and revisions; shared and `DUPLICATE` names are marked | 0 ok, 1 the JSON cannot be encoded, 2 bad option |
| `slo:validate` | validates the catalog against the routes; prints `ERROR`, `WARNING` and `NOTICE` lines | 0 no errors (warnings allowed), 1 errors |
| `slo:coverage [--format=text\|json] [--min=<0-100>]` | which routes, handlers, actions and operations have an SLO and which are already measured by a universal series; statuses `monitored`, `unmonitored`, `ignored`, `invalid`, `unsupported` | 0 passed, 1 any `invalid` point, a kind below its required percent (compared on the exact fraction: 2 of 3 is below 66.67; `--min` overrides all kinds) or the JSON cannot be encoded, 2 bad option |
| `slo:generate [--output=<dir>] [--check]` | writes Prometheus rules, Grafana dashboards and optional Sloth specs; `--check` compares with the disk without writing and prints `stale`, `missing`, `extra` paths | 0 ok / up to date, 1 `--check` found differences, 2 missing `generation.selector.application` or output directory |

`slo:coverage --format=json` is stable and described by the JSON Schema [`doc/coverage-schema.json`](doc/coverage-schema.json).
A CI gate is `bin/console slo:validate && bin/console slo:coverage --min=95 && bin/console slo:generate --check`.

## Generation and GitOps provisioning

`slo:generate` is deterministic: sorted keys, stable UIDs and ids, no timestamps, a trailing newline, and it never
reads the runtime environment (only the explicit `generation.selector`). It manages only its own files in
`output_dir`, so other files next to them are safe:

```
<output_dir>/
    prometheus/slo-recording.rules.yml
    prometheus/slo-alerts.rules.yml
    grafana/dashboards/slo-*.json                  # application overview, HTTP routes, Messenger, business
                                                   # operations, SLO overview, monitoring coverage
    grafana/provisioning/dashboards/slo.yaml       # file provider; options.path is /var/lib/grafana/dashboards
    sloth/slo-<name>.yml                           # only with generation.sloth: true
```

Commit the directory, run `slo:generate --check` in CI ("rules are stale" fails the build), mount
`prometheus/` into `rule_files` and `grafana/dashboards` plus the provisioning file into Grafana.

- Recording rules per SLO and objective: `slo:events:rate<5m|30m|1h|6h|3d>`, `slo:error_ratio:rate<w>`,
  `slo:objective:target`, `slo:sli:ratio`, `slo:error_budget:remaining`, `slo:window:coverage_ratio`. Every series is
  scoped by `application` (and by `component` when configured).
- Alerts are multi-window multi-burn-rate (Google SRE workbook) named `SloErrorBudgetBurn`: page - 2 % of the budget
  in 1h (long) / 5m (short) and 5 % in 6h / 30m; ticket - 10 % in 3d / 6h. The factors scale with the window
  (`share * window / long window`: 14.4, 6 and 1 for 30d). A pair whose long window exceeds the SLO window is
  omitted, as is a pair that can never fire (`factor * (1 - target) >= 1`; `slo:validate` warns). An empty
  denominator never fires. `no_events_alert_hours` adds the opt-in `SloNoEvents` alert.
- Sloth has no per-SLO window: with different windows `slo:generate` warns that one `--default-slo-period` cannot
  match several of them, so SLOs whose window differs from the chosen period get wrong burn-rate thresholds.
- The Monitoring Coverage dashboard is a static table rendered at generation time; it is refreshed only by `slo:generate`.

## Failure semantics: fail-open and Redis outages

- Nothing thrown by SLO code (classification, catalog lookup, writing) reaches application code: it is logged on the
  Monolog channel `slo_bundle` as a warning and swallowed. Exceptions thrown by user code inside `measure()` are
  rethrown unchanged. Pinned by `tests/Integration/Hardening/FailOpenTest.php` for HTTP, Messenger and `measure()`.
- Each event is **one** `increment` per objective on one counter with `result="good|bad"`, so good can never exceed
  the total. When the storage (Redis) is down, **a lost write loses the whole event, not the ratio**: the SLI is
  computed from the events that were written, and a long outage shows up as missing data, not as a skewed percentage.
- Under long-running workers (RoadRunner, Messenger workers) every stateful service is reset through `kernel.reset`:
  the per-process warning sets and every failed resolution. Successful resolutions (strategy and revision of an
  objective) and a successfully built route index survive the reset: catalog and routes are fixed per container. Two
  requests with different routes and outcomes are attributed to their own SLO (`StateLeakageTest`). A router that
  fails while the route index is built disables HTTP SLOs (one warning) until the next `kernel.reset` retries it.
- A storage outage logs one warning per SLO, objective and exception class until the next reset (at most 100, then
  one suppression notice), not one per event.
- A compiled catalog that cannot be read (a cache left over from another bundle version) never throws on the request
  path: its SLOs record nothing and one warning asks to clear the cache; the `slo:*` commands fail with that message.
- Cardinality is bounded: labels are `slo` (declared name), `objective`, `revision` and `result` only. No path,
  query string, user or request value ever becomes a label (`CardinalityTest`: 1000 random URLs, at most two
  `result` values per objective).

## Bridges and measurement points

Any service tagged `msstc4symfony_slo.point` becomes a measurement point of the catalog; the bundle reflects its class
for `#[Slo]` and `#[IgnoreMonitoring]`. Tag attributes: `type` (a `PointType` value), `id` (non-empty, without `%`,
unique across services), optional `metric_label` (the label value of the existing series; valid UTF-8 without control
characters, `{{` or `}}`).
slo-bundle never depends on logic-bundle: the `slo-bridge-logic` package tags `msstc4symfony_logic.action` services and
records their events through `Application\Port\EventRecorderInterface` (inject it, call
`record(new RecordEvent\Input($point, $observation))`).

**A bridge that adds the tag from its own compiler pass must register that pass with `PassConfig::TYPE_BEFORE_OPTIMIZATION`
and a priority of 10 or higher**: discovery runs at priority 0, after the attribute autoconfiguration (100) has tagged
controllers and handlers, and the tag has to exist by then. `PointTag::TAG` is the constant for the tag name.

## Public API

Covered by semantic versioning; everything else is marked `@internal` and may change in any release.

| Part | Contents |
|------|----------|
| Attributes | `Application\Attribute\*` (`Slo`, `Availability`, `Latency`, `Combined`, `IgnoreMonitoring`, `ObjectiveAttributeInterface`) |
| Operations | `Application\Port\OperationMonitorInterface`, `Application\Port\OperationHandleInterface` |
| Bridges | `Application\Discovery\PointTag`, `Application\Port\EventRecorderInterface` (a public service, inject it), `Application\Evaluation\RecordEvent\Input` |
| Catalog | `Domain\Repository\SloCatalogInterface`, `Domain\Entity\SloDefinition` |
| Value objects | `Domain\ValueObject\SloName`, `PointId`, `Objective`, `Target`, `LatencyThreshold`, `Window`, `Classification`, `StatusSet`, `Observation` |
| Enums | `Domain\Enum\PointType`, `ObjectiveKind`, `StrategyPreference`, `DeliveryStatus`, `Outcome` |
| Console | the commands `slo:list`, `slo:validate`, `slo:coverage`, `slo:generate`, their options, exit codes and the JSON of `slo:coverage` |
| Configuration | the `msstc4symfony_slo` configuration keys, the metric `symfony_slo_events_total` with its labels, the generated recording rule and alert names |
| Bundle | `SloBundle` |

## Upgrade notes

1.0 is the first release; there is nothing to migrate. Notes for the next ones:

- metrics-bundle 2.3.0 is the minimum; with an older metrics-bundle the container does not compile.
- A change of event semantics (threshold, classification, point type) changes the `revision`; the SLI of the new
  revision warms up for one window while the generated rules ignore the old one. Regenerate and commit the rules
  (`slo:generate`) together with such a change.
- The label order of `symfony_slo_events_total` is `application, component, slo, objective, revision, result`.
- Retired revisions are not removed: their series stay in the metrics-bundle storage and in its export until the
  storage is cleared, so every revision ever deployed adds its `good`/`bad` series per objective. The generated rules
  and dashboards read the current revision only, so the old series cost cardinality, not correctness.

### Cleaning series of old revisions

With the default Redis storage (`promphp/prometheus_client_php`) the counter is one Redis hash,
`PROMETHEUS_:counter:symfony_slo_events_total` with the default key prefix, with one field per label set (the field
name is the JSON list of label values, so the revision is inside it). Redis sets no TTL on it: the fields of retired
revisions live until they are removed.

- `bin/console metrics:clear` wipes the whole metrics-bundle storage. Every counter restarts from zero, which
  Prometheus `rate()`/`increase()` treat as a counter reset; nothing is lost but the samples of the scrape interval
  around the wipe. Run it after a deploy that changed revisions, when the cardinality matters.
- To drop only the old revisions, delete their fields from the hash, e.g. for revision `abcdef12`:

  ```bash
  redis-cli -n 4 --scan --pattern 'PROMETHEUS_:counter:symfony_slo_events_total' | while read -r key; do
    redis-cli -n 4 hkeys "$key" | grep '"abcdef12"' | while read -r field; do redis-cli -n 4 hdel "$key" "$field"; done
  done
  ```

  The key layout belongs to promphp, not to this bundle, and may change with it; prefer `metrics:clear` when in doubt.

## Layers

`src/` follows the canonical layout of [bundle-standard](https://github.com/msstc4symfony/bundle-standard)
(`templates/deptrac.yaml`). The list is closed - `verify-standard` rejects any other directory:

- `Domain/` - data in `Entity/`, `Document/`, `ValueObject/`, `Enum/`, `Event/`, `Repository/`, `DocumentRepository/`;
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
make infection                            # mutation testing of src/ with the unit suite
make benchmark                            # per-event overhead, see "Overhead"
```

## Running the rule tests

The generated Prometheus rules and Sloth specs are checked by the real tools, never skipped: `promtool check rules`,
`promtool test rules` (rule unit tests for fast burn, NoData, a revision change and warming up) and `sloth validate`,
all through `docker run` (`prom/prometheus:v3.15.0`, `ghcr.io/slok/sloth:v0.16.0`).

```sh
make test-promtool   # needs Docker; about a minute
```

Without Docker the tests fail instead of being skipped. They are not part of `make test` only because the reusable
bundle-standard job runs without Docker; CI runs them in the dedicated `promtool` job of
`.github/workflows/checks.yml`.

## Overhead

`make benchmark` (PHPUnit group `benchmark`, `tests/Benchmark`, outside the test suites, no pass/fail threshold)
measures the cost of the classifier plus writer path for one event with two objectives (availability and latency;
10 % of the events are errors), best of 5 rounds of 100 000 events after a warm-up. Measured on 2026-11-21 UTC,
PHP 8.4.17 (CLI, no opcache/JIT tuning), Intel Core Ultra 7 155H, Linux 6.17, one core:

| Scenario | Cost per event |
|----------|----------------|
| null recorder (classifier, binding, revision memo, writer call) | 4.5 us |
| null recorder, `kernel.reset` after every event (RoadRunner request or consumed message) | 5.1 us |
| the same with revisions warmed into the cache | 5.6 us |
| metrics-bundle in-memory storage (the full `increment` path) | 10.7 us |

Before 1.0 a reset also dropped the resolved strategy and revision of every objective: the reset scenario cost
28.7 us per event (6.4 us with warmed revisions) because every event resolved its binding and hashed its revision again.

A real Redis storage adds its network round trip per `increment`; the events are written after the response is
produced in the HTTP path (`kernel.terminate`) and in the worker loop for Messenger.

**Route index, the cold path.** HTTP SLOs need the route → SLO point index and the route names of each SLO.
FrameworkBundle's `Router::getRouteCollection()` reloads every route from the loaders on each call, and under PHP-FPM
"memoised per process" means once per request, so building the index lazily costs a full route load on the first SLO
event of every request (milliseconds to tens of milliseconds in large applications). The cache warmer avoids it; it
is not optional, so it also runs with `--no-optional-warmers` and when the kernel builds a fresh container. It writes
the index to `%kernel.cache_dir%/msstc4symfony_slo/route_index.php`, a `var_export` array that opcache keeps in shared
memory, and the request path reads only the entries it needs from that file: the SLO points of the matched route, the
precomputed strategy and revision of an objective and, when a revision is missing, the routes of one controller, each
entry validated on its own (the whole file is validated only by the commands). The compiled catalog is hydrated the
same way, per point on first use. The lazy build stays the fallback when the file is missing or unreadable (logged
once per process as degraded mode on the `slo_bundle` channel), and in debug mode, where routes may change without a
cache clear and the file is ignored. An application without HTTP SLOs never touches the router on the request path.
The warmer never fails the warm-up: problems are logged on the `slo_bundle` channel.

## License

MIT - see [LICENSE](LICENSE).
