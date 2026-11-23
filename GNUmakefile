# GNU make reads this file before Makefile, which must stay byte-identical to the bundle-standard template.
include Makefile

test-promtool: ## Validate the generated Prometheus rules with promtool in Docker (prom/prometheus image)
	vendor/bin/phpunit --group promtool tests/Promtool

test-amtool: ## Validate the generated Alertmanager files with amtool in Docker (prom/alertmanager image)
	vendor/bin/phpunit --group amtool tests/Amtool

benchmark: ## Measure the per-event overhead of the SLO recording path (no pass/fail threshold)
	vendor/bin/phpunit --group benchmark tests/Benchmark

.PHONY: test-promtool test-amtool benchmark
