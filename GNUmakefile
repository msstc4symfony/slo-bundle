# GNU make reads this file before Makefile, which must stay byte-identical to the bundle-standard template.
include Makefile

test-promtool: ## Validate the generated Prometheus rules with promtool in Docker (prom/prometheus image)
	vendor/bin/phpunit --group promtool tests/Promtool

.PHONY: test-promtool
