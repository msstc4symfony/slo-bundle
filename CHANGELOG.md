# Changelog

## [Unreleased]

### Added

- `PointType::ScheduledTask` (`scheduled_task`), a new enum case: an exhaustive `match` over `PointType` in user code
  needs an arm for it.
- `Observation::outcome()` accepts `scheduled_task` and a fourth `$onFailureTransport` argument; such observations are
  not eligible for any objective.
- `#[Slo(deadline: ...)]` (`<int>ms`, `<decimal>s`, `<int>m`, `<int>h`; scheduled tasks only): how long a run may take
  before it counts as unfinished; required when the SLO has neither a latency nor a combined objective, otherwise the
  threshold is the deadline. `Deadline` value object and `SloDefinition::deadline()` / `runDeadline()`.
- `generation.prometheus.scrape_interval_seconds` (default 60): `slo:validate` reports a scheduled-task deadline below it
  as an error. `slo:list` shows the deadline of task SLOs (`deadline` key in JSON).
- Discovery of Symfony Scheduler attribute tasks (`#[AsCronTask]` / `#[AsPeriodicTask]`, the `scheduler.task` tag) as
  `scheduled_task` points: `<service id>::<method>` (method from the attribute, default `__invoke`) or
  `command:<command name>` for console commands; `#[Slo]` is read from the class and, for service tasks, from the task
  method. Repeated task attributes on one class or method share one point. A command task whose name cannot be resolved
  is a compile error only when its class declares `#[Slo]` or `#[IgnoreMonitoring]`. `symfony/scheduler` is suggested.
- Coverage kind `scheduled_task` in `slo:coverage` (an additive value of the JSON `kind` enum and of the per-kind
  percent maps) with `coverage.scheduled_tasks.required_percent` (default 0, applied when `--min` is not given).
- Per-task run metrics of Scheduler tasks consumed by a Messenger worker, written for every attempt of a task with an
  SLO: `symfony_slo_scheduled_task_started_total{task}`, `symfony_slo_scheduled_task_finished_total{task,outcome}` and
  the histogram `symfony_slo_scheduled_task_duration_seconds{task,outcome}` (buckets 0.005, 0.01, 0.025, 0.05, 0.1,
  0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 180, 300, 600, 1800, 3600 s). `task` is the point id, `outcome` one of `success`,
  `error`, `expected_failure`, `retried`.
- SLO events of scheduled tasks: a worker listener (registered when symfony/scheduler and symfony/messenger are
  installed) times each `ServiceCallMessage` / `RunCommandMessage` run itself and records the final outcome; a retried
  attempt is no event, a failure is classified on the handler's nested exception (a failing command is always an
  error), and runs on a failure transport are not eligible. A task message the application dispatches itself counts
  under the task's point too. A run longer than its deadline plus the 1m unfinished-runs step records no SLO event
  (per SLO): the rules certainly counted it, as unfinished. A run counts at most once outside a duration band of about
  `(d - scrape interval, d + 1m]` (about two minutes with a 60 s scrape); within the band it may count twice, towards
  bad, and it is never lost. An explicit `deadline` above the latency threshold narrows the latency distortion.
  `SloDefinition::countsAsUnfinished()` applies the cut-off; `Deadline::UNFINISHED_RUN_STEP_MILLISECONDS` is the step
  shared with the generated rules.
- Unfinished runs counted as bad events of scheduled-task SLOs in the generated Prometheus rules and Sloth specs: runs
  started in `[t-d-r, t-d]` minus runs finished in `[t-d-r, t]` (`d` the run deadline, `r` the range), clamped at 0,
  are added to the bad and the total events of every objective, so a hung or killed run is bad and a task that always
  dies reads 100 % bad instead of NoData. A run still within its deadline is not counted; a frequent task with a long
  deadline can hide a hung run behind later runs that finished. A new record `slo:unfinished_runs:step` counts, every
  minute and per series from instant counter values, the runs that passed their deadline without finishing; every
  window sums it, so pod replacements do not blind long windows. Unfinished runs are never invented: a step in which a
  series is missing (outage, failed scrape, pod replacement), its counters reset (Redis flush) or the rule evaluation
  is missed loses that step's runs, and the first run of a new series is not counted. Sloth specs cannot rely on the
  bundle's records and compute the same per-series form over their window, which needs series that live for the deadline
  plus the window.
  Sloth specs of task SLOs use `increase` queries. The window coverage and the no-events alert of a task SLO count
  started runs as data, so the ticket alert of a task that always dies can fire.
- Generated Grafana dashboard `slo-scheduled-tasks.json` (Scheduled Tasks): runs started, runs finished by outcome, p95 run
  duration and runs started minus finished over the time range per task, then the SLO rows of scheduled-task objectives; `slo:generate --check`
  reports it as missing until regenerated.

### Changed

- `slo:coverage --min` now also applies to `scheduled_task` points (like every other kind), so a `--min` gate fails on
  scheduled tasks without an SLO.
- `SloDefinition::sameSemanticsAs()` and the `slo:validate` conflict report compare the effective run deadline: an
  explicit deadline equal to the latency threshold is the same declaration as no deadline.
- The generated `slo-monitoring-coverage` dashboard gains a `scheduled_task` row: `slo:generate --check` reports drift
  after the upgrade; regenerate with `slo:generate`.
- Compiled catalog dump format 4 (`deadline_ms` per definition, `scheduled_tasks` in the extras): clear the cache on
  upgrade, as any deploy does.

## [1.1.0] - 2026-11-23

### Added

- Opt-in Alertmanager output of `slo:generate` (`generation.alertmanager.enabled`, default `false`): a full
  `alertmanager/slo-alertmanager.yml` and an `alertmanager/slo-alertmanager-routes.yml` fragment (`routes` and
  `inhibit_rules`) that route `SloErrorBudgetBurn` / `SloNoEvents` by `severity` and let a page alert inhibit its ticket twin.
- `generation.alertmanager.receivers.{page,ticket}` (defaults `slo-page`, `slo-ticket`) and the
  `slo:generate --page-receiver` / `--ticket-receiver` options.
- A configuration error when `generation.alertmanager.enabled` is set while `generation.prometheus.alerts` is `false`
  (the same check runs at generation time, because env placeholders bypass the configuration tree).
- `slo:generate` exits 2 for `--page-receiver` / `--ticket-receiver` while Alertmanager generation is off, and for an
  invalid or empty receiver name (from the options or from an env placeholder that resolved to one); receiver names
  are also validated at configuration load.
- `make test-amtool` and a CI job validate the generated files with `amtool` (`prom/alertmanager:v0.34.0`).

## [1.0.1] - 2026-11-22

### Fixed

- The warning "SLO operation exception could not be classified" on channel `slo_bundle` now carries the `operation` name.

### Documentation

- Handlers of Symfony Scheduler messages are not reliably detected as worker-consumed (schedules are built at runtime);
  bind them with `fromTransport`.

## [1.0.0] - 2026-11-22

### Changed

- First stable release; e2e-verified in bundles-showcase.

## [1.0.0-rc.4] - 2026-11-22

### Fixed

- A Messenger handler of a service that consumes messages another service routes (the transport and the worker are
  here, the `routing` entry is in the producer) is now worker-consumed: `slo:validate` no longer warns that its SLO
  never records events and `slo:coverage` judges it `monitored`/`unmonitored` instead of `unsupported`. A handler counts
  as worker-consumed when its message is routed to a transport other than `sync://`, when it is bound with
  `fromTransport`/`from_transport` to such a transport, or when its message is not routed in the app and the app has a
  receive-only transport (not `sync://`, not a failure transport, not a Scheduler `schedule://` transport, targeted by
  no routing entry or `#[AsMessage]` of a handled message). Unrouted framework messages (`Symfony\*`) stay synchronous.
  A message routed only to `sync://` and a handler bound to a failure transport are now synchronous (before they
  counted as worker-consumed). Two cases are not detectable at compile time: a transport that the app both routes its
  own messages to and consumes other services' messages from (a false `slo:validate` warning remains), and a transport
  targeted only by `#[AsMessage]` of unhandled messages or by `TransportNamesStamp` (it looks receive-only, so unrouted
  handlers look worker-consumed). For both, bind the handler with `fromTransport` or add a `routing` entry. The
  `slo:validate` warning now reads "is not consumed by a worker (routed nowhere or only to sync://)". Apps with a
  receive-only transport may see the messenger coverage gate fall: their unrouted handlers now need `#[Slo]` or
  `#[IgnoreMonitoring]`. Revisions, generated rules and dashboards do not change: the flag only feeds `slo:validate`
  and `slo:coverage`.

## [1.0.0-rc.3] - 2026-11-22

### Fixed

- Dashboards of several applications sharing one `application` label no longer collide: uid and title include
  `generation.selector.components` when set. Applications that set `generation.selector.components` get new dashboard
  uids and titles once: provisioned Grafana replaces the old dashboards and bookmarks to the old uid break.

## [1.0.0-rc.2] - 2026-11-22

### Changed

- Discovery (`CollectSloDefinitionsPass`) runs at `TYPE_BEFORE_OPTIMIZATION` priority -10 instead of 0, so metric enums
  appended at priority 0 by bundles registered after slo-bundle (metrics-bridge-logic) are seen in any bundle order;
  bridge passes that add the `msstc4symfony_slo.point` tag still use priority 10 or higher.

### Added

- `Application\Port\ExpectedFailureClassifierInterface` is public API (injectable service): bridges that decorate
  `OperationMonitorInterface` classify exceptions like `measure()`.

### Fixed

- Composer `suggest` text of `msstc4symfony/slo-bridge-profiling`: it opens a profiling-bundle span around every SLO
  operation.

## [1.0.0-rc.1] - 2026-11-22

### Added

- Package scaffold from `skeleton-bundle`: `SloBundle`, canonical layers, quality gate of bundle-standard v4.1.0; dependency on `msstc4symfony/metrics-bundle` `^2.3`.
- Declarative SLOs: repeatable `#[Slo]` with `Availability`, `Latency` and `Combined` objectives, `#[IgnoreMonitoring]` with a mandatory reason, windows `h|d|w|mo` (1h - 90d), thresholds in `ms` or `s`.
- Compile-time catalog of HTTP routes, Messenger handlers, operations and generic points (tag `msstc4symfony_slo.point`, bridge passes at `TYPE_BEFORE_OPTIMIZATION` priority 10 or higher); one error list for all problems; shared SLO names merged, conflicting declarations kept as conflicts.
- Event classification defaults per point type with overrides (`badStatuses`, `ineligibleStatuses`, `expectedFailureIsGood`, `countAttempts`, `includeErrors`), failure-transport detection, stable `revision` of the event semantics.
- Per-objective strategy: existing metrics-bundle series (Strategy A) or dedicated `symfony_slo_events_total{slo,objective,revision,result}` counters (Strategy B) written through metrics-bundle storage, one increment per event and objective.
- Fail-open HTTP (`HttpSloObserver`) and Messenger (`MessengerSloObserver`) observers on the metrics-bundle measurements, without a second timer; errors are logged on the `slo_bundle` channel and never reach application code.
- `OperationMonitorInterface` / `OperationHandleInterface` for measuring arbitrary operations declared in the `operations` configuration (`start()`, `measure()`).
- Commands `slo:list` (text, JSON), `slo:validate`, `slo:coverage` (text, JSON with `doc/coverage-schema.json`, `--min`, exit codes) and `slo:generate` (`--output`, `--check`); a cache warmer reporting catalog problems.
- Generated Prometheus recording rules and multi-window multi-burn-rate alerts scaled to the SLO window, warming-up and NoData handling, optional no-events alert, application and component scoping.
- Generated Grafana dashboards (application overview, HTTP routes, Messenger, business operations, SLO overview, monitoring coverage) with file provisioning, and optional Sloth `prometheus/v1` specs.
- Rule tests with `promtool` and `sloth validate` in Docker (`make test-promtool`, CI job `promtool`).
- Hardening regression tests: fail-open with an unreachable metrics storage (HTTP, Messenger, `measure()`), state isolation across `kernel.reset`, bounded label cardinality; `make benchmark` overhead benchmark.
- Route index warmed into `%kernel.cache_dir%/msstc4symfony_slo/route_index.php` by the (non-optional) cache warmer, so the HTTP path does not reload the route collection per request under PHP-FPM; lazy fallback when the file is missing (logged once per process as degraded mode) and in debug mode; applications without HTTP SLOs never read the routes. Works with `framework.router.enabled: false` (no routes).
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

### Changed

- Bridges record events through the public port `Application\Port\EventRecorderInterface::record(RecordEvent\Input)`
  (public service); `RecordEvent\Action` is internal.
- `#[Slo]` takes `strategy: StrategyPreference::Dedicated` (`Domain\Enum\StrategyPreference`) instead of a string;
  `ObjectiveAttributeInterface::kind()` returns `Domain\Enum\ObjectiveKind`.
- `SloDefinition` rejects `includeErrors` outside `http_route` points and classification options that do not apply to
  the point type, so such options can never change a revision.
- Domain value objects no longer render PromQL (`Window::toPromQl()` and `StatusSet::toRegex()` are gone); the
  generated rules, dashboards and Sloth specs are unchanged. The overlapping-statuses error names the statuses as
  declared (`5xx`).
- The console command classes are marked `@internal`; their names, options, output and exit codes stay public.
- A compiled catalog with one unreadable definition breaks only that definition's point; without warmed revisions the
  request path resolves strategies from cheap per-point catalog reads; the cache warmer still writes the route index
  when the revisions cannot be precomputed.
- `monolog/monolog` is no longer a direct requirement (nothing in the bundle uses it).
- The cache warmer is no longer optional; outside debug mode a missing route index is logged once per process as degraded
  mode. The request path reads single entries of the route index (points of a route, revision of an objective, routes of
  a controller) instead of validating whole sections, and resolves controller service ids without hydrating the
  catalog extras.
- A corrupt definition in the compiled catalog skips only that definition, and its point is retried after
  `kernel.reset`; deterministic resolution and route-index failures are retried after `kernel.reset` but logged once per
  process.
- Configuration: a YAML map given to a list option (`bad_statuses`, `ineligible_statuses`, `ignored_routes`,
  `components`) is taken as its values; `defaults.*` are validated once per compilation, also without HTTP points;
  operation objectives reject `threshold` on availability, `include_errors` outside latency and a missing threshold on
  latency/combined.
- Latency thresholds are at most `999999999ms` (`999999.999s`), so every threshold survives the compiled catalog.
- Compilation errors for a tagged point id used by two services and for a `metric_label` with control characters, invalid
  UTF-8, `{{` or `}}`; generated PromQL strings escape control characters and reject invalid UTF-8; free text in the
  coverage dashboard is escaped against HTML, links and Grafana variables.
- `slo:validate` no longer reports equal windows spelled differently (`30d`, `1mo`) as a difference; such a shared SLO
  uses the canonical spelling (`1mo`).
- Public API listed type by type in the README; `Domain\Enum\CoverageStatus` and `Domain\Enum\Strategy` moved to
  `Application\Coverage\Analyze\CoverageStatus` and `Application\Binding\Strategy` (internal); `Revision` and
  `EventResult` are internal. The Grafana datasource, folder and dashboards path are settings of the Grafana adapter.
- Outside debug mode an index the request path had to build (no warmed `route_index.php`) is stored in the cache
  directory with its objective revisions, so the rebuild and the degraded-mode warning happen once per deploy; a
  read-only cache directory is skipped. The cache warmer and the request path share one `RouteIndexBuilder`.
- The compiled catalog dump is format 3 (point → `metric_label` and `message` label → message classes indexes for the
  request path); a dump left in a stale cache records nothing and asks to clear the cache, as before.
- `SloDefinition::strategy()` is renamed to `strategyPreference()`.
- Strategy decisions on the request path no longer build the series binding (matchers and patterns) they do not use.
- `slo:coverage` compares a kind with its required percent in integer basis points, so a fractional `--min` such as
  `0.07` is met by 7 of 10000 points.
- Deterministic JSON and YAML output rejects a map that key sorting would turn into a list; dumped catalog keys with
  `%` are rejected centrally.
- `GenerationConfig` validates its settings; burn-rate alert severities are an enum (`page`, `ticket`), generated files
  unchanged.
- The route index is format 3 and carries a fingerprint of the compiled catalog: a file stored by another container
  (an old process during an in-place `cache:clear --no-warmup`) is not trusted and is replaced. The request path checks
  that the cache directory is writable before it builds an index to store (a read-only one costs a few stat calls and
  is named in the degraded-mode warning), logs a failed write once per process and replaces a malformed index.
- Tagged points of one type sharing a `metric_label` drop it, so their SLOs use dedicated counters (`slo:validate`
  warning); one service tagged twice for the same point with different `metric_label` values is a compilation error.
- `slo:coverage` sorts points by name as strings (numeric route names no longer make the order depend on the input) and
  prints the exact fraction of a kind below its required percent (`BELOW (2 of 3 eligible points have an SLO)`).
- `slo:generate` validates the resolved `generation.selector.application` and `components` (e.g. from an env placeholder) with the same label-value pattern as the configuration tree and fails with a clear message.
- An empty `generation.selector.application` (an env placeholder resolved to nothing) is reported by `slo:generate` as
  not configured instead of failing the service construction.
- Deterministic JSON and YAML output rejects a map keyed exactly `0..n-1` out of order at any size.
- `StatusSet::intersects()` compares the item bounds instead of walking every status code; the cold PHP-FPM request
  benchmark (500 SLOs) drops from about 115 us to about 70 us on the same machine.
- Public methods of the public domain types used only by discovery and the compiled catalog
  (`Classification::appliesTo()`, `pointTypesOf()`, `inapplicableFieldsFor()`, `SloDefinition::withWindow()`,
  `Window::canonical()`, `LatencyThreshold::fromMilliseconds()`, `PointType::acceptsIncludeErrors()`) are `@internal`.

### Documentation

- README: bridges build `RecordEvent\Input` inside their own `try` (building it can throw, `record()` cannot); the cold
  PHP-FPM request benchmark (500 SLOs) and the stored runtime route index.
- README: cleaning the series of retired revisions from the Redis storage; Messenger latency uses dedicated counters
  when the message short name is shared.

[Unreleased]: https://github.com/msstc4symfony/slo-bundle/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/msstc4symfony/slo-bundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/msstc4symfony/slo-bundle/compare/v1.0.0-rc.4...v1.0.0
[1.0.0-rc.4]: https://github.com/msstc4symfony/slo-bundle/compare/v1.0.0-rc.3...v1.0.0-rc.4
[1.0.0-rc.3]: https://github.com/msstc4symfony/slo-bundle/compare/v1.0.0-rc.2...v1.0.0-rc.3
[1.0.0-rc.2]: https://github.com/msstc4symfony/slo-bundle/compare/v1.0.0-rc.1...v1.0.0-rc.2
[1.0.0-rc.1]: https://github.com/msstc4symfony/slo-bundle/releases/tag/v1.0.0-rc.1
