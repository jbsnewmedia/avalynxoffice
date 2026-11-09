<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\EventSubscriber;

use AvalynxOffice\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every response carries the hardening headers; HSTS is only sent over
 * secure requests so plain-HTTP local installs stay cookie-compatible.
 */
final class SecurityHeadersSubscriberTest extends TestCase
{
    public function testResponseCarriesHardeningHeaders(): void
    {
        $response = $this->dispatch(Request::create('http://localhost/mails'), new Response());

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $response->headers->get('Permissions-Policy'));
        self::assertSame("frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self'", $response->headers->get('Content-Security-Policy'));
        self::assertNull($response->headers->get('Strict-Transport-Security'), 'Plain HTTP must not receive HSTS');
    }

    public function testSecureResponseAlsoCarriesHsts(): void
    {
        $request = Request::create('https://localhost/mails');

        $response = $this->dispatch($request, new Response());

        self::assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
    }

    public function testSubscriptionIsRegistered(): void
    {
        self::assertSame([KernelEvents::RESPONSE => 'onKernelResponse'], SecurityHeadersSubscriber::getSubscribedEvents());
    }

    public function testSubRequestsAreIgnored(): void
    {
        $response = new Response();

        $this->dispatch(Request::create('https://localhost/mails'), $response, HttpKernelInterface::SUB_REQUEST);

        self::assertNull($response->headers->get('X-Content-Type-Options'));
    }

    private function dispatch(Request $request, Response $response, int $requestType = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ResponseEvent($kernel, $request, $requestType, $response);

        (new SecurityHeadersSubscriber())->onKernelResponse($event);

        return $response;
    }
}
