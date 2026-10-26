<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\ConnectorRepository;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The connector creation flow with plugin choice: choosing a plugin in the
 * new-connector modal jumps straight to the settings card of that plugin,
 * and only the chosen plugin drives the connector.
 */
#[Group('integration')]
#[Group('functional')]
final class ConnectorPluginSelectionTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $admin;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->admin = new User();
        $this->admin->setEmail('admin@example.com');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->admin->setPassword($hasher->hashPassword($this->admin, 'admin'));
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    public function testNewModalOffersEveryPlugin(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/connectors/new', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('name="connector[plugin]"', $content);
        self::assertStringContainsString('value="pop3"', $content);
        self::assertStringContainsString('value="exchange"', $content);
    }

    public function testCreateWithChosenPluginJumpsToItsSettings(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/connectors/new', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="connector[_token]"]')->attr('value');
        \assert(is_string($token));

        $this->client->request('POST', '/admin/connectors/new', [
            'connector' => [
                'name' => 'Exchange Connector',
                'domain' => 'example.com',
                'plugin' => 'exchange',
                'fetchFrom' => '2026-10-17',
                '_token' => $token,
            ],
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['success']);
        $connector = $this->em->getRepository(Connector::class)->findOneBy(['domain' => 'example.com']);
        self::assertNotNull($connector);
        self::assertSame('exchange', $connector->getPluginKey());
        self::assertNotNull($connector->getFetchFrom());
        self::assertSame('2026-10-17', $connector->getFetchFrom()->format('Y-m-d'));
        self::assertIsString($payload['redirect']);
        self::assertStringContainsString('/admin/connectors/'.$connector->getId()?->toRfc4122().'/settings#plugin-exchange', $payload['redirect']);
    }

    public function testSettingsPageShowsOnlyTheChosenPlugin(): void
    {
        $connector = (new Connector())
            ->setName('Exchange Connector')
            ->setDomain('example.com')
            ->setPluginKey('exchange')
            ->setFetchFrom(new \DateTimeImmutable('2026-10-17'));
        $this->em->persist($connector);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/connectors/'.$connector->getId()?->toRfc4122().'/settings');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="plugin-exchange"', $content);
        self::assertStringNotContainsString('id="plugin-pop3"', $content);
        self::assertStringContainsString('ewsHost', $content);
        self::assertStringNotContainsString('pop3Host', $content);
        self::assertStringContainsString('name="connector[fetchFrom]"', $content);
        self::assertStringContainsString('value="2026-10-17"', $content);
    }

    public function testSettingsPageOfLegacyConnectorShowsEveryPlugin(): void
    {
        $connector = (new Connector())
            ->setName('Legacy Connector')
            ->setDomain('example.com');
        $this->em->persist($connector);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/connectors/'.$connector->getId()?->toRfc4122().'/settings');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="plugin-pop3"', $content);
        self::assertStringContainsString('id="plugin-exchange"', $content);
    }

    public function testSystemPageListsConnectorsWithTheirPlugin(): void
    {
        $connector = (new Connector())
            ->setName('Exchange Connector')
            ->setDomain('example.com')
            ->setPluginKey('exchange');
        $this->em->persist($connector);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/system');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Connectors in the sync service', $content);
        self::assertStringContainsString('Exchange Connector', $content);
        self::assertStringContainsString('/admin/connectors/'.$connector->getId()?->toRfc4122().'/settings', $content);
    }
}
