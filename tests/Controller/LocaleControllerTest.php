<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The locale switch stores the language and redirects to a safe target:
 * the ?r= parameter when it is a relative path, otherwise the same-host
 * referer path, otherwise home.
 */
#[Group('integration')]
final class LocaleControllerTest extends WebTestCase
{
    public function testSetLocaleStoresLanguageAndRedirectsToTheSafeTarget(): void
    {
        $client = static::createClient();

        $client->request('GET', '/locale/de', ['r' => '/mails']);
        self::assertResponseRedirects('/mails');

        $session = $client->getRequest()->getSession();
        self::assertSame('de', $session->get('_locale'));
    }

    public function testSetLocaleRejectsUnsafeTargets(): void
    {
        $client = static::createClient();

        $client->request('GET', '/locale/en', ['r' => 'https://evil.example.com/mails']);

        self::assertResponseRedirects('/');
    }

    public function testSetLocaleFallsBackToHomeWithoutReferer(): void
    {
        $client = static::createClient();

        $client->request('GET', '/locale/en');

        self::assertResponseRedirects('/');
    }

    public function testSetLocaleFallsBackToHomeForForeignReferers(): void
    {
        $client = static::createClient();

        $client->request('GET', '/locale/de', [], [], ['HTTP_REFERER' => 'http://evil.example.com/mails?x=1#top']);

        self::assertResponseRedirects('/');
    }

    public function testSetLocaleFallsBackToHomeForUnsafeRefererPaths(): void
    {
        $client = static::createClient();

        // Same host, but the path is not a safe relative target.
        $client->request('GET', '/locale/de', [], [], ['HTTP_REFERER' => 'http://localhost//evil?x=1#top']);

        self::assertResponseRedirects('/');
    }

    public function testSetLocaleRejectsInvalidLocaleValues(): void
    {
        $client = static::createClient();

        $client->request('GET', '/locale/fr');

        self::assertResponseStatusCodeSame(404);
    }
}
