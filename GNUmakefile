# GNU make reads this file before Makefile, which must stay byte-identical to the bundle-standard template.
include Makefile

test-promtool: ## Validate the generated Prometheus rules with promtool in Docker (prom/prometheus image)
	vendor/bin/phpunit --group promtool tests/Promtool

benchmark: ## Measure the per-event overhead of the SLO recording path (no pass/fail threshold)
	vendor/bin/phpunit --group benchmark tests/Benchmark

.PHONY: test-promtool benchmark
