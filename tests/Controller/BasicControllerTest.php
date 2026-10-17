<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BasicControllerTest extends WebTestCase
{
    public function testAboutRedirect(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about');

        $this->assertResponseRedirects('/login');
    }

    public function testLoginPath(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
    }

    public function testMailIndexRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseRedirects('/login');
    }
}
