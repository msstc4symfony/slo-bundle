<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle;

use Msstc4Symfony\SloBundle\Application\Binding\Matcher;
use Msstc4Symfony\SloBundle\Application\Binding\SeriesBinding;
use Msstc4Symfony\SloBundle\Application\Binding\SeriesKind;
use Msstc4Symfony\SloBundle\Application\Port\SeriesCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\DeliveryStatus;
use Msstc4Symfony\SloBundle\Domain\Enum\Outcome;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use Msstc4Symfony\SloBundle\Domain\ValueObject\StatusSet;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\BucketLabel;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlRenderer;
use Override;

/**
 * Metric and label names of metrics-bundle 2.3 (HTTP, Messenger) and of the logic-bundle action histogram.
 *
 * @internal
 */
final readonly class MetricsBundleSeriesCatalog implements SeriesCatalogInterface
{
    private const HTTP_COUNTER = 'http_response';

    private const HTTP_HISTOGRAM = 'request_duration_histogram_seconds';

    private const MESSENGER_COUNTER = 'messenger_message_handled';

    private const MESSENGER_HISTOGRAM = 'messenger_message_handling_duration_histogram_seconds';

    private const LOGIC_HISTOGRAM = 'logic_action_duration_histogram_seconds';

    private const LABEL_ROUTE = 'route';

    private const LABEL_STATUS = 'status';

    private const LABEL_MESSAGE = 'message';

    private const LABEL_TRANSPORT = 'transport';

    private const LABEL_ACTION = 'action';

    private const LABEL_OUTCOME = 'outcome';

    #[Override]
    public function histogramFor(PointType $type): ?string
    {
        return match ($type) {
            PointType::HttpRoute => self::HTTP_HISTOGRAM,
            PointType::MessengerMessage => self::MESSENGER_HISTOGRAM,
            PointType::LogicAction => self::LOGIC_HISTOGRAM,
            PointType::Operation => null,
        };
    }

    #[Override]
    public function bucketFor(array $buckets, LatencyThreshold $threshold): ?string
    {
        foreach ($buckets as $bucket) {
            $label = BucketLabel::fromBucket($bucket);
            if ($label->equalsMilliseconds($threshold->milliseconds)) {
                return $label->value;
            }
        }

        return null;
    }

    #[Override]
    public function messageLabel(string $messageClass): string
    {
        $position = strrpos($messageClass, '\\');
        $short = $position === false ? $messageClass : substr($messageClass, $position + 1);

        return $short === '' ? $messageClass : $short;
    }

    #[Override]
    public function httpAvailability(array $routes, StatusSet $bad, StatusSet $ineligible): SeriesBinding
    {
        $route = Matcher::regex(self::LABEL_ROUTE, PromQlRenderer::alternation($routes));
        $badPattern = PromQlRenderer::statusPattern($bad);
        $ineligiblePattern = PromQlRenderer::statusPattern($ineligible);
        $notGood = implode('|', array_filter([$badPattern, $ineligiblePattern], static fn (string $part): bool => $part !== ''));

        return new SeriesBinding(
            self::HTTP_COUNTER,
            SeriesKind::Counter,
            SeriesBinding::index($ineligiblePattern === '' ? [$route] : [$route, Matcher::notRegex(self::LABEL_STATUS, $ineligiblePattern)]),
            SeriesBinding::index($notGood === '' ? [] : [Matcher::notRegex(self::LABEL_STATUS, $notGood)]),
            SeriesBinding::index($badPattern === '' ? [] : [Matcher::regex(self::LABEL_STATUS, $badPattern)]),
            null,
        );
    }

    #[Override]
    public function httpLatency(array $routes, string $bucket): SeriesBinding
    {
        return $this->latency(self::HTTP_HISTOGRAM, [Matcher::regex(self::LABEL_ROUTE, PromQlRenderer::alternation($routes))], $bucket);
    }

    #[Override]
    public function messengerAvailability(array $messageClasses, array $failureTransports): SeriesBinding
    {
        $handled = DeliveryStatus::Handled->value;
        $failed = DeliveryStatus::Failed->value;

        return new SeriesBinding(
            self::MESSENGER_COUNTER,
            SeriesKind::Counter,
            SeriesBinding::index([
                $this->messages($messageClasses),
                Matcher::regex(self::LABEL_STATUS, PromQlRenderer::alternation([$handled, $failed])),
                ...$this->notFailureTransports($failureTransports),
            ]),
            SeriesBinding::index([Matcher::equals(self::LABEL_STATUS, $handled)]),
            SeriesBinding::index([Matcher::equals(self::LABEL_STATUS, $failed)]),
            null,
        );
    }

    #[Override]
    public function messengerLatency(array $messageClasses, array $failureTransports, string $bucket): SeriesBinding
    {
        return $this->latency(self::MESSENGER_HISTOGRAM, [
            $this->messages($messageClasses),
            Matcher::equals(self::LABEL_STATUS, DeliveryStatus::Handled->value),
            ...$this->notFailureTransports($failureTransports),
        ], $bucket);
    }

    #[Override]
    public function logicAvailability(array $actionLabels): SeriesBinding
    {
        return new SeriesBinding(
            self::LOGIC_HISTOGRAM,
            SeriesKind::Histogram,
            SeriesBinding::index([$this->exactOrAlternation(self::LABEL_ACTION, $actionLabels)]),
            SeriesBinding::index([$this->nonError()]),
            SeriesBinding::index([Matcher::equals(self::LABEL_OUTCOME, Outcome::Error->value)]),
            null,
        );
    }

    #[Override]
    public function logicLatency(array $actionLabels, bool $includeErrors, string $bucket): SeriesBinding
    {
        $action = $this->exactOrAlternation(self::LABEL_ACTION, $actionLabels);

        return $this->latency(self::LOGIC_HISTOGRAM, $includeErrors ? [$action] : [$action, $this->nonError()], $bucket);
    }

    /**
     * @param non-empty-string $histogram
     * @param list<Matcher> $matchers
     * @param non-empty-string $bucket
     */
    private function latency(string $histogram, array $matchers, string $bucket): SeriesBinding
    {
        return new SeriesBinding($histogram, SeriesKind::Histogram, SeriesBinding::index($matchers), [], [], $bucket);
    }

    private function nonError(): Matcher
    {
        return Matcher::regex(self::LABEL_OUTCOME, PromQlRenderer::alternation([Outcome::Success->value, Outcome::ExpectedFailure->value]));
    }

    /**
     * @param non-empty-list<non-empty-string> $messageClasses
     */
    private function messages(array $messageClasses): Matcher
    {
        return $this->exactOrAlternation(self::LABEL_MESSAGE, array_map($this->messageLabel(...), $messageClasses));
    }

    /**
     * @param list<non-empty-string> $failureTransports
     *
     * @return list<Matcher>
     */
    private function notFailureTransports(array $failureTransports): array
    {
        sort($failureTransports, SORT_STRING);

        return $failureTransports === [] ? [] : [Matcher::notRegex(self::LABEL_TRANSPORT, PromQlRenderer::alternation($failureTransports))];
    }

    /**
     * @param non-empty-string $label
     * @param non-empty-list<non-empty-string> $values
     */
    private function exactOrAlternation(string $label, array $values): Matcher
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return \count($values) === 1 ? Matcher::equals($label, $values[0]) : Matcher::regex($label, PromQlRenderer::alternation($values));
    }
}
