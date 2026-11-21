<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Action;

use Msstc4Symfony\SloBundle\Application\Attribute\IgnoreMonitoring;

#[IgnoreMonitoring('cron only')]
final class CleanupAction
{
    public function handle(): string
    {
        return 'cleaned';
    }
}
