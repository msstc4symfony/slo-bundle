# Changelog

## [Unreleased]

### Added

- Package scaffold from `skeleton-bundle`: `SloBundle`, canonical layers, quality gate of bundle-standard v4.1.0; dependency on `msstc4symfony/metrics-bundle` `^2.3`.
- Declarative SLOs: repeatable `#[Slo]` with `Availability`, `Latency` and `Combined` objectives, `#[IgnoreMonitoring]` with a mandatory reason, windows `h|d|w|mo` (1h - 90d), thresholds in `ms` or `s`.
- Compile-time catalog of HTTP routes, Messenger handlers, operations and generic points (tag `msstc4symfony_slo.point`, bridge passes at `TYPE_BEFORE_OPTIMIZATION` priority 10 or higher); one error list for all problems; shared SLO names merged, conflicting declarations kept as conflicts.
- Event classification defaults per point type with overrides (`badStatuses`, `ineligibleStatuses`, `expectedFailureIsGood`, `countAttempts`, `includeErrors`), failure-transport detection, stable `revision` of the event semantics.
- Per-objective strategy: existing metrics-bundle series (Strategy A) or dedicated `symfony_slo_events_total{slo,objective,revision,result}` counters (Strategy B) written through metrics-bundle storage, one increment per event and objective.
- Fail-open HTTP (`HttpSloObserver`) and Messenger (`MessengerSloObserver`) observers on the metrics-bundle measurements, without a second timer; errors are logged on the `slo_bundle` channel and never reach application code.
- `OperationMonitorInterface` / `OperationHandleInterface` for measuring arbitrary operations declared in the `operations` configuration (`start()`, `measure()`).
- Commands `slo:list` (text, JSON), `slo:validate`, `slo:coverage` (text, JSON with `doc/coverage-schema.json`, `--min`, exit codes) and `slo:generate` (`--output`, `--check`); optional cache warmer reporting catalog problems.
- Generated Prometheus recording rules and multi-window multi-burn-rate alerts scaled to the SLO window, warming-up and NoData handling, optional no-events alert, application and component scoping.
- Generated Grafana dashboards (application overview, HTTP routes, Messenger, business operations, SLO overview, monitoring coverage) with file provisioning, and optional Sloth `prometheus/v1` specs.
- Rule tests with `promtool` and `sloth validate` in Docker (`make test-promtool`, CI job `promtool`).
- Hardening regression tests: fail-open with an unreachable metrics storage (HTTP, Messenger, `measure()`), state isolation across `kernel.reset`, bounded label cardinality; `make benchmark` overhead benchmark.
- Route index warmed into `%kernel.cache_dir%/msstc4symfony_slo/route_index.php` by the optional cache warmer, so the HTTP path does not reload the route collection per request under PHP-FPM; lazy fallback when the file is missing and in debug mode; applications without HTTP SLOs never read the routes. Works with `framework.router.enabled: false` (no routes).
- Messenger SLOs whose message short class name is shared with another handled message use dedicated counters, with a `slo:validate` warning; unknown delivery statuses are skipped with one warning.
- `measure()` classifies exceptions with logic-bundle's `ActionOutcome::of()` when installed (`ValidationFailedException` is an expected failure).
- Dashboard rows show the expected end of warming up as a UTC date.
- README: installation, attributes, classification, strategies, revisions and warming up, configuration reference, commands and exit codes, GitOps provisioning, failure semantics, public API, compatibility matrix, Prometheus retention, route-index overhead.
- Everything outside the documented public API is marked `@internal`.
- Strategy A latency rules select the bucket in the form Prometheus 3 stores (`le="1.0"` for an exposed `le="1"`);
  a threshold matches a bucket only up to float noise (`0.2504` does not match `250ms`); promtool test with `le="1.0"` input.
- The route index (format 2) also carries the strategy and revision of every objective, computed by the cache warmer;
  the request path validates only the sections it reads, the compiled catalog is hydrated per point, and an unreadable
  catalog dump records nothing with one warning instead of throwing.
- `kernel.reset` keeps successful objective resolutions and a built route index and retries only failed ones; repeated
  recording failures are logged once per SLO, objective and exception class until the next reset.
- Logic-action and operation latency covers every non-error run regardless of `expectedFailureIsGood` (spec 4.4);
  Messenger revisions include the sorted failure transports.
- Compilation errors for `#[Slo]` on a handler of an interface or abstract message class and for `%` in a tagged point
  id; `%` in `#[IgnoreMonitoring]` reasons and other dumped strings is escaped. `generation.selector.application` and
  `components` are validated as label values (`[A-Za-z0-9_.:-]{1,128}`).
- `slo:validate` warns about an SLO on a message that is never routed to a transport; coverage thresholds are compared
  on the exact fraction; dashboards read the 5m window-group records over a 10m lookback, like the alerts.
