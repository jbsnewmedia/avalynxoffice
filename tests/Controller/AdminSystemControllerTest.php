<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\SyncRun;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\AppSettingRepository;
use AvalynxOffice\Service\Sync\SyncSettingsService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('integration')]
#[Group('functional')]
final class AdminSystemControllerTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);

        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);

        $this->admin = new User();
        $this->admin->setEmail('admin@example.com');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->admin->setPassword($hasher->hashPassword($this->admin, 'admin'));

        $this->user = new User();
        $this->user->setEmail('user@example.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'user'));

        $this->em->persist($this->admin);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    public function testSystemPageRequiresLogin(): void
    {
        $this->client->request('GET', '/admin/system');

        self::assertResponseRedirects('/login');
    }

    public function testSystemPageRequiresAdminRole(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/admin/system');

        self::assertResponseStatusCodeSame(403);
    }

    public function testSystemPageShowsPlaceholderWhenServiceNeverRan(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/system');

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('System status', $content);
        self::assertStringContainsString('No pass yet', $content);
        // The settings form shows the configured defaults.
        self::assertStringContainsString('name="sync_settings[intervalSeconds]"', $content);
        self::assertStringContainsString('value="300"', $content);
        self::assertStringContainsString('value="2"', $content);
        self::assertStringContainsString('value="100"', $content);
    }

    public function testSystemPageShowsLatestAndRecentRuns(): void
    {
        $latest = new SyncRun();
        $latest->setStatus(SyncRun::STATUS_SUCCESS);
        $latest->setTrigger(SyncRun::TRIGGER_DAEMON);
        $latest->setFinishedAt(new \DateTimeImmutable('+10 seconds'));
        $latest->setMailsImported(12);
        $latest->setMailsSkipped(3);
        $latest->setMailsErrors(1);
        $this->em->persist($latest);

        $older = new SyncRun();
        $older->setStatus(SyncRun::STATUS_ERROR);
        $older->setTrigger(SyncRun::TRIGGER_MANUAL);
        $older->setFinishedAt(new \DateTimeImmutable('+5 seconds'));
        $older->setMessage('POP3 host unreachable.');
        $this->em->persist($older);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/system');

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Successful', $content);
        self::assertStringContainsString('12 imported, 3 skipped, 1 errors', $content);
        // The recent runs table lists the errored pass as well.
        self::assertStringContainsString('POP3 host unreachable.', $content);
        self::assertStringContainsString('Manual', $content);
    }

    public function testSaveStoresSettingsAndAppliesOnNextPass(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/system', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        $crawler = $this->client->request('GET', '/admin/system', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        $form = $crawler->filter('form#sync-settings-form')->form();
        $values = $form->getPhpValues();
        $values['sync_settings']['intervalSeconds'] = '10';
        $values['sync_settings']['busyIntervalSeconds'] = '2';
        $values['sync_settings']['mailsLimit'] = '10';
        $values['sync_settings']['contactsLimit'] = '20';
        $values['sync_settings']['calendarLimit'] = '30';

        $this->client->request('POST', '/admin/system', $values, [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $settings = static::getContainer()->get(SyncSettingsService::class)->all();
        self::assertSame(10, $settings['intervalSeconds']);
        self::assertSame(2, $settings['busyIntervalSeconds']);
        self::assertSame(10, $settings['mailsLimit']);
        self::assertSame(20, $settings['contactsLimit']);
        self::assertSame(30, $settings['calendarLimit']);

        $stored = static::getContainer()->get(AppSettingRepository::class)->getValue(SyncSettingsService::KEY_MAILS_LIMIT);
        self::assertSame('10', $stored);
    }

    public function testSaveRejectsInvalidValuesWithFieldErrors(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/system', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        $form = $crawler->filter('form#sync-settings-form')->form();
        $values = $form->getPhpValues();
        $values['sync_settings']['intervalSeconds'] = '-5';
        $values['sync_settings']['mailsLimit'] = '';

        $this->client->request('POST', '/admin/system', $values, [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('sync_settings[intervalSeconds]', $data['invalid']);
        self::assertArrayHasKey('sync_settings[mailsLimit]', $data['invalid']);
    }

    public function testSaveRequiresXmlHttpRequest(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/system', [
            'sync_settings' => ['intervalSeconds' => '10'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSaveRejectsBusyIntervalGreaterThanIdleInterval(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/system', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        $form = $crawler->filter('form#sync-settings-form')->form();
        $values = $form->getPhpValues();
        $values['sync_settings']['intervalSeconds'] = '10';
        $values['sync_settings']['busyIntervalSeconds'] = '99';

        $this->client->request('POST', '/admin/system', $values, [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('sync_settings[busyIntervalSeconds]', $data['invalid']);
        self::assertStringContainsString('must not be greater', (string) $data['invalid']['sync_settings[busyIntervalSeconds]']);
    }
}
