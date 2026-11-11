<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\AppSettingRepository;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('integration')]
#[Group('functional')]
final class AdminEasPolicyControllerTest extends WebTestCase
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

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

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

    public function testPageRequiresLogin(): void
    {
        $this->client->request('GET', '/admin/eas-policy');

        self::assertResponseRedirects('/login');
    }

    public function testPageRequiresAdminRole(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/admin/eas-policy');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPageRendersFormWithAllDefaultsOff(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/eas-policy');

        self::assertResponseIsSuccessful();

        // The form posts via AvalynxForm and carries every policy key.
        self::assertSame(1, $crawler->filter('#eas-policy-form[data-avalynxform="1"]')->count());
        self::assertStringContainsString('name="eas_policy[devpwenabled]"', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('name="eas_policy[allowcam]"', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('name="eas_policy[maxdevpwfailedattempts]"', (string) $this->client->getResponse()->getContent());

        // No checkbox is pre-checked and no number field pre-filled.
        self::assertSame(0, $crawler->filter('#eas-policy-form input[type="checkbox"]:checked')->count());
        self::assertSame(0, $crawler->filter('#eas-policy-form input[type="number"][value]:not([value=""])')->count());
    }

    public function testPageShowsNotConfiguredHintWhenEasMissing(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/eas-policy');

        self::assertResponseIsSuccessful();

        // EAS_BASE_URL is not set in the test environment.
        self::assertSame(1, $crawler->filter('.alert-warning')->count());
    }

    public function testSaveRequiresXmlHttpRequest(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/eas-policy');

        self::assertResponseStatusCodeSame(403);
    }

    public function testSaveStoresSubmittedPolicies(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/eas-policy', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        $form = $crawler->filter('form#eas-policy-form')->form();
        $values = $form->getPhpValues();
        // Checked = the exact activating value (allowcam's restriction value is "0").
        $values['eas_policy']['devpwenabled'] = '1';
        $values['eas_policy']['allowcam'] = '0';
        $values['eas_policy']['mindevpwlenngth'] = '8';

        $this->client->request('POST', '/admin/eas-policy', $values, [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);
        self::assertStringContainsString('/admin/eas-policy', (string) $data['redirect']);

        $repository = $this->em->getRepository(\AvalynxOffice\Entity\AppSetting::class);
        self::assertSame('1', $repository->getValue('eas.policy.devpwenabled'));
        self::assertSame('0', $repository->getValue('eas.policy.allowcam'));
        self::assertSame('8', $repository->getValue('eas.policy.mindevpwlenngth'));
        self::assertNull($repository->getValue('eas.policy.allowwifi'));
    }

    public function testSaveRejectsOutOfRangeNumberInline(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/eas-policy', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        $form = $crawler->filter('form#eas-policy-form')->form();
        $values = $form->getPhpValues();
        $values['eas_policy']['maxdevpwfailedattempts'] = '1';

        $this->client->request('POST', '/admin/eas-policy', $values, [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(422);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('eas_policy[maxdevpwfailedattempts]', $data['invalid']);

        $repository = $this->em->getRepository(\AvalynxOffice\Entity\AppSetting::class);
        self::assertNull($repository->getValue('eas.policy.maxdevpwfailedattempts'));
    }
}
