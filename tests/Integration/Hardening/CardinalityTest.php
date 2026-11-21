<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Hardening;

use Msstc4Symfony\SloBundle\Test\Fixtures\Runtime\CheckoutController;

final class CardinalityTest extends HardeningTestCase
{
    private const REQUESTS = 1000;

    public function testRandomPathsAndQueryStringsNeverBecomeLabelValues(): void
    {
        $this->boot();

        for ($i = 0; $i < self::REQUESTS; $i++) {
            $this->serve(\sprintf('/checkout/%s?user=%s&page=%d', bin2hex(random_bytes(8)), bin2hex(random_bytes(4)), $i));
        }

        $export = $this->export();
        preg_match_all('/^symfony_slo_events_total\{(.*)\} \S+$/m', $export, $matches);
        $labelSets = array_values(array_unique($matches[1]));
        sort($labelSets);

        self::assertNotSame([], $labelSets);
        foreach ($labelSets as $labels) {
            self::assertMatchesRegularExpression(
                '/^application="test",component="test",slo="checkout\.page",objective="(combined|latency)",revision="[0-9a-f]{8}",result="(good|bad)"$/',
                $labels,
            );
        }
        self::assertLessThanOrEqual(4, \count($labelSets), 'at most two results per objective');

        $counts = $this->counts();
        foreach (['combined', 'latency'] as $objective) {
            self::assertSame(
                self::REQUESTS,
                ($counts[CheckoutController::PAGE_SLO . '|' . $objective . '|good'] ?? 0) + ($counts[CheckoutController::PAGE_SLO . '|' . $objective . '|bad'] ?? 0),
            );
        }
    }
}
