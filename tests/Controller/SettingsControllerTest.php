<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\AppSetting;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Service\ThemeService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('functional')]
final class SettingsControllerTest extends WebTestCase
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
        $this->seedUsers();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $this->restoreDefaultTheme();
    }

    public function testSettingsOverviewRendersDashboardForAdmins(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/settings', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        // Settings pages embed session state: the body must never be cacheable.
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="settingsNavDropdown"', $content);
        // The overview is a neutral dashboard: no account card, no appearance form.
        self::assertStringNotContainsString('Manage Accounts', $content);
        self::assertStringNotContainsString('primary_color', $content);
    }

    public function testDesignPageShowsAppearanceCardForAdmins(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/settings/design', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        // The design page embeds the appearance CSRF token: the body must never be cacheable.
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(ThemeService::DEFAULT_PRIMARY_COLOR, $content);
        self::assertStringContainsString('primary_color', $content);
        self::assertStringContainsString('id="settingsNavDropdown"', $content);
    }

    public function testDesignPageHidesAppearanceCardForRegularUsers(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/settings/design', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('primary_color', $content);
    }

    public function testLanguagePageRendersForAdmins(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/settings/language', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="settingsNavDropdown"', $content);
        // The language switch behaves like the theme group: buttons with
        // data-locale-value, no disabled links.
        self::assertStringContainsString('data-locale-value="de"', $content);
        self::assertStringContainsString('data-locale-value="en"', $content);
        self::assertStringContainsString('data-locale-url="/locale/de"', $content);
        self::assertStringContainsString('data-locale-url="/locale/en"', $content);
        self::assertStringContainsString('aria-pressed="true"', $content);
        self::assertStringNotContainsString('class="btn btn-outline-primary d-flex align-items-center active disabled"', $content);
    }

    public function testSettingsSubPagesRedirectToLoginForGuests(): void
    {
        $this->client->request('GET', '/settings/language');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/settings/design');
        self::assertResponseRedirects('/login');
    }

    public function testAppearanceSaveRequiresAdminRole(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->setCsrfToken('save_appearance');
        $this->client->request('POST', '/settings/appearance', [
            '_token' => $token,
            'primary_color' => '#ff8800',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->settingValue());
    }

    public function testAppearanceSaveRequiresCsrfToken(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/settings/appearance', [
            'primary_color' => '#ff8800',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->settingValue());
    }

    public function testAppearanceSaveRejectsInvalidColor(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('save_appearance');
        $this->client->request('POST', '/settings/appearance', [
            '_token' => $token,
            'primary_color' => 'not-a-color',
        ]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('primary_color', $data['invalid']);
        self::assertNull($this->settingValue());
    }

    public function testAppearanceSaveStoresColorAndRecompilesCss(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('save_appearance');
        $this->client->request('POST', '/settings/appearance', [
            '_token' => $token,
            'primary_color' => '#FF8800',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);
        self::assertSame('#ff8800', $this->settingValue());

        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $compiled = (string) file_get_contents($projectDir.'/assets/css/bootstrap.min.css');
        self::assertStringContainsString('#ff8800', $compiled);
    }

    private function seedUsers(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->admin = new User();
        $this->admin->setEmail('admin@example.com');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->admin->setPassword($hasher->hashPassword($this->admin, 'admin'));
        $this->admin->setLastAuthAt(new \DateTimeImmutable('-1 hour'));

        $this->user = new User();
        $this->user->setEmail('user@example.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'user'));
        $this->user->setLastAuthAt(new \DateTimeImmutable('-1 hour'));

        $this->em->persist($this->admin);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function settingValue(): ?string
    {
        $repository = $this->em->getRepository(AppSetting::class);

        return $repository->getValue(ThemeService::SETTING_PRIMARY_COLOR);
    }

    private function setCsrfToken(string $tokenId): string
    {
        $this->client->request('GET', '/mails');
        $session = $this->client->getRequest()->getSession();
        $value = 'test-csrf-token-'.bin2hex(random_bytes(8));
        $session->set('_csrf/'.$tokenId, $value);
        $session->save();

        return $value;
    }

    private function restoreDefaultTheme(): void
    {
        try {
            $themeService = static::getContainer()->get(ThemeService::class);
            $themeService->compile(ThemeService::DEFAULT_PRIMARY_COLOR);
        } catch (\Throwable) {
            // The kernel might already be shut down; nothing to restore.
        }
    }

    public function testTimePageShowsCurrentUserTimezoneAndFormat(): void
    {
        $this->admin->setTimezone('Europe/Berlin');
        $this->admin->setTimeFormat('medium');
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/settings/time');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="timezoneSelect"', $content);
        self::assertStringContainsString('<option value="Europe/Berlin" selected>', $content);
        self::assertStringContainsString('<option value="medium" selected>', $content);
        self::assertStringContainsString('id="settings-time-form"', $content);
    }

    public function testTimeSaveStoresTimezoneAndFormat(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/settings/time');
        $token = (string) $crawler->filter('#settings-time-form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/time', [
            '_token' => $token,
            'timezone' => 'Europe/Berlin',
            'time_format' => 'medium',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertSame('/settings/time', $payload['redirect']);

        $this->em->clear();
        $user = $this->em->find(User::class, $this->admin->getId());
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Europe/Berlin', $user->getTimezone());
        self::assertSame('medium', $user->getTimeFormat());
    }

    public function testTimeSaveRequiresCsrfToken(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/settings/time');
        $this->client->request('POST', '/settings/time', [
            'timezone' => 'Europe/Berlin',
        ]);

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['success']);
        self::assertSame('Invalid token.', $payload['message']);
    }

    public function testTimeSaveRejectsInvalidTimezone(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/settings/time');
        $token = (string) $crawler->filter('#settings-time-form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/time', [
            '_token' => $token,
            'timezone' => 'Mars/Olympus_Mons',
            'time_format' => 'short',
        ]);

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['success']);
        self::assertArrayHasKey('timezone', $payload['invalid']);
    }

    public function testTimeSaveRejectsInvalidFormat(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/settings/time');
        $token = (string) $crawler->filter('#settings-time-form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/time', [
            '_token' => $token,
            'timezone' => 'Europe/Berlin',
            'time_format' => 'fancy',
        ]);

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['success']);
        self::assertArrayHasKey('time_format', $payload['invalid']);
    }

    public function testTimePageRedirectsToLoginForGuests(): void
    {
        $this->client->request('GET', '/settings/time');

        self::assertResponseRedirects('/login');
    }
}
