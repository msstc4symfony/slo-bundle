<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\Enum;

enum DeliveryStatus: string
{
    case Handled = 'handled';
    case Failed = 'failed';
    case Retried = 'retried';
}
