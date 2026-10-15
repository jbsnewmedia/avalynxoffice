<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\LocaleSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class LocaleSubscriberTest extends TestCase
{
    public function testGetSubscribedEvents(): void
    {
        self::assertSame([
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
        ], LocaleSubscriber::getSubscribedEvents());
    }

    public function testOnKernelRequestReturnsEarlyWithoutSession(): void
    {
        $request = Request::create('/');
        $request->setLocale('fr');

        (new LocaleSubscriber())->onKernelRequest($this->createRequestEvent($request));

        self::assertSame('fr', $request->getLocale());
    }

    public function testOnKernelRequestAppliesQueryLocaleAndPersistsNormalizedValue(): void
    {
        $request = Request::create('/?_locale=de-DE');
        $request->setSession(new Session(new MockArraySessionStorage()));

        (new LocaleSubscriber())->onKernelRequest($this->createRequestEvent($request));

        self::assertSame('de', $request->getLocale());
        self::assertSame('de', $request->getSession()->get('_locale'));
    }

    public function testOnKernelRequestUsesSupportedSessionLocale(): void
    {
        $request = Request::create('/');
        $request->setSession($session = new Session(new MockArraySessionStorage()));
        $session->set('_locale', 'de');

        (new LocaleSubscriber())->onKernelRequest($this->createRequestEvent($request));

        self::assertSame('de', $request->getLocale());
    }

    public function testOnKernelRequestFallsBackToPreferredLanguageAndDefaultEnglish(): void
    {
        $subscriber = new LocaleSubscriber();

        $preferred = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);
        $preferred->setSession(new Session(new MockArraySessionStorage()));
        $subscriber->onKernelRequest($this->createRequestEvent($preferred));
        self::assertSame('de', $preferred->getLocale());

        $default = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']);
        $default->setSession(new Session(new MockArraySessionStorage()));
        $subscriber->onKernelRequest($this->createRequestEvent($default));
        self::assertSame('en', $default->getLocale());
        self::assertSame('en', $default->getSession()->get('_locale'));
    }

    public function testOnKernelRequestIgnoresUnsupportedQueryAndSessionLocales(): void
    {
        $request = Request::create('/?_locale=fr-FR', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);
        $request->setSession($session = new Session(new MockArraySessionStorage()));
        $session->set('_locale', 'es');

        (new LocaleSubscriber())->onKernelRequest($this->createRequestEvent($request));

        self::assertSame('de', $request->getLocale());
        self::assertSame('de', $request->getSession()->get('_locale'));
    }

    public function testOnKernelRequestFallsBackToDefaultEnglishForEmptyLocaleHint(): void
    {
        $request = Request::create('/?_locale=');
        $request->setSession(new Session(new MockArraySessionStorage()));

        (new LocaleSubscriber())->onKernelRequest($this->createRequestEvent($request));

        self::assertSame('en', $request->getLocale());
        self::assertSame('en', $request->getSession()->get('_locale'));
    }

    public function testOnKernelRequestFallsBackToDefaultEnglishWhenNoPreferredLanguageMatches(): void
    {
        $request = new class extends Request {
            #[\Override]
            public function getPreferredLanguage(?array $locales = null): ?string
            {
                return null;
            }
        };
        $request->setSession(new Session(new MockArraySessionStorage()));

        (new LocaleSubscriber())->onKernelRequest($this->createRequestEvent($request));

        self::assertSame('en', $request->getLocale());
        self::assertSame('en', $request->getSession()->get('_locale'));
    }

    private function createRequestEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
