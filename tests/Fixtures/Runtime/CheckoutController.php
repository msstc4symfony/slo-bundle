<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Runtime;

use Msstc4Symfony\SloBundle\Application\Attribute\Combined;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final readonly class CheckoutController
{
    public const PAGE_SLO = 'checkout.page';

    public const FRAGMENT_SLO = 'checkout.fragment';

    public function __construct(private HttpKernelInterface $kernel)
    {
    }

    #[Slo(name: self::PAGE_SLO, objectives: [new Combined('200ms', 99), new Latency('200ms', 99)])]
    public function page(string $outcome): Response
    {
        return match ($outcome) {
            'bad-request' => new Response('bad request', Response::HTTP_BAD_REQUEST),
            'not-found' => new Response('not found', Response::HTTP_NOT_FOUND),
            'redirect' => new RedirectResponse('/checkout/ok'),
            'throw' => throw new RuntimeException('checkout failed'),
            default => new Response('ok'),
        };
    }

    #[Slo(name: self::FRAGMENT_SLO, objectives: [new Combined('200ms', 99)])]
    public function withFragment(): Response
    {
        $fragment = $this->kernel->handle(Request::create('/checkout/ok'), HttpKernelInterface::SUB_REQUEST);

        return new Response('page:' . $fragment->getContent());
    }
}
