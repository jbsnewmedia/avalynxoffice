<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\UserRepository;
use AvalynxOffice\Service\Backup\BackupScheduleService;
use AvalynxOffice\Service\Backup\BackupService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('integration')]
#[Group('functional')]
final class AdminBackupControllerTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $admin;

    private User $user;

    private string $backupDir;

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

        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        $this->backupDir = rtrim($projectDir, '/').'/data/backups';
        $this->clearBackupFiles();
    }

    protected function tearDown(): void
    {
        $this->clearBackupFiles();
        parent::tearDown();
    }

    private function clearBackupFiles(): void
    {
        if (!is_dir($this->backupDir)) {
            return;
        }

        foreach (scandir($this->backupDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->backupDir.'/'.$entry);
            }
        }
    }

    private function backupService(): BackupService
    {
        $service = static::getContainer()->get(BackupService::class);
        \assert($service instanceof BackupService);

        return $service;
    }

    private function xhr(): array
    {
        return ['HTTP_X-Requested-With' => 'XMLHttpRequest'];
    }

    private function userRepository(): UserRepository
    {
        $repository = static::getContainer()->get(UserRepository::class);
        \assert($repository instanceof UserRepository);

        return $repository;
    }

    private function scheduleService(): BackupScheduleService
    {
        $service = static::getContainer()->get(BackupScheduleService::class);
        \assert($service instanceof BackupScheduleService);

        return $service;
    }

    public function testPageRequiresLogin(): void
    {
        $this->client->request('GET', '/admin/backup');

        self::assertResponseRedirects('/login');
    }

    public function testPageRequiresAdminRole(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/admin/backup');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPageShowsAllSections(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/backup');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('#backup-create-form')->count());
        self::assertSame(1, $crawler->filter('#backup-upload-form')->count());
        // Without any backup the restore card shows a hint instead of the form.
        self::assertSame(0, $crawler->filter('#backup-restore-form')->count());
        self::assertStringContainsString('No backups', (string) $this->client->getResponse()->getContent());

        $this->backupService()->create(false, null);
        $crawler = $this->client->request('GET', '/admin/backup');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('#backup-restore-form')->count());
    }

    public function testCreatePlainBackupStoresArchiveInBackupDir(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/backup', [], [], $this->xhr());

        $form = $crawler->filter('#backup-create-form')->form();
        $values = $form->getPhpValues();
        $values['backup_create']['includeFiles'] = false;
        unset($values['backup_create']['encrypt']);

        $this->client->request('POST', '/admin/backup/create', $values, [], $this->xhr());
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $backups = $this->backupService()->listBackups();
        self::assertCount(1, $backups);
        self::assertFalse($backups[0]->encrypted);
        self::assertStringEndsWith('.aobak', $backups[0]->filename);
        self::assertIsArray($backups[0]->manifest);
    }

    public function testCreateEncryptedBackupRequiresLongPassword(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/backup', [], [], $this->xhr());

        $form = $crawler->filter('#backup-create-form')->form();
        $values = $form->getPhpValues();
        $values['backup_create']['includeFiles'] = false;
        $values['backup_create']['encrypt'] = '1';
        $values['backup_create']['password'] = 'short';
        $values['backup_create']['passwordRepeat'] = 'short';

        $this->client->request('POST', '/admin/backup/create', $values, [], $this->xhr());
        self::assertResponseStatusCodeSame(422);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('backup_create[password]', $data['invalid']);
        self::assertCount(0, $this->backupService()->listBackups());
    }

    public function testDownloadServesArchive(): void
    {
        $filename = $this->backupService()->create(false, null);

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/backup/download/'.$filename);

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertStringContainsString($filename, (string) ($response->headers->get('Content-Disposition') ?? ''));
    }

    public function testDownloadRejectsForeignFileNames(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/admin/backup/download/..%2F.env');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRemovesArchive(): void
    {
        $service = $this->backupService();
        $filename = $service->create(false, null);

        $this->client->loginUser($this->admin);
        // The delete token is rendered inside the row form of the backup list.
        $this->client->request('GET', '/admin/backup');
        $crawler = $this->client->getCrawler();
        $token = $crawler->filter('form[action$="/admin/backup/delete/'.$filename.'"] input[name="_token"]')->attr('value');
        \assert(is_string($token));

        $this->client->request('POST', '/admin/backup/delete/'.$filename, [
            '_token' => $token,
        ], [], $this->xhr());

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertCount(0, $service->listBackups());
    }

    public function testUploadRejectsNonArchiveFiles(): void
    {
        $this->client->loginUser($this->admin);
        $content = 'definitely not a zip archive';
        $uploadId = $this->startChunkedUpload(strlen($content));
        $this->sendUploadChunk($uploadId, 0, $content);
        $this->finishChunkedUpload($uploadId);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertCount(0, $this->backupService()->listBackups());
    }

    public function testUploadStoresValidPlainArchiveInChunks(): void
    {
        $service = $this->backupService();
        $sourceName = $service->create(false, null);
        $content = (string) file_get_contents($service->getBackupDir().'/'.$sourceName);

        $this->client->loginUser($this->admin);
        $uploadId = $this->startChunkedUpload(strlen($content));
        // Two pieces to prove the sequential multi-chunk flow.
        $this->sendUploadChunk($uploadId, 0, substr($content, 0, 100));
        $this->sendUploadChunk($uploadId, 1, substr($content, 100));
        $this->finishChunkedUpload($uploadId);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);

        $names = array_map(
            static fn ($info): string => $info->filename,
            $service->listBackups(),
        );
        self::assertCount(2, $names);
        $uploaded = array_values(array_filter(
            $names,
            static fn (string $name): bool => str_contains($name, 'avalynxoffice-uploaded-'),
        ));
        self::assertCount(1, $uploaded);
    }

    public function testUploadInitRejectsSizeBeyondHalfOfFreeDisk(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->uploadCsrfToken();

        $this->client->request('POST', '/admin/backup/upload/init', [
            'totalSize' => (string) PHP_INT_MAX,
        ], [], $this->uploadHeaders($token));

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertMatchesRegularExpression('/\d+(\.\d+)? (KiB|MiB|GiB|TiB)/', (string) $data['message']);
    }

    public function testUploadChunkOrderIsEnforced(): void
    {
        $this->client->loginUser($this->admin);
        $uploadId = $this->startChunkedUpload(100);

        // First chunk must have index 0 — sending 1 is rejected.
        $this->client->request('POST', '/admin/backup/upload/chunk?uploadId='.$uploadId.'&index=1', [], [], $this->uploadHeaders($this->uploadCsrfToken(), 'application/octet-stream'), 'abcdef');

        self::assertResponseStatusCodeSame(409);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
    }

    public function testUploadUnknownSessionIsRejected(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('POST', '/admin/backup/upload/chunk?uploadId='.str_repeat('a', 32).'&index=0', [], [], $this->uploadHeaders($this->uploadCsrfToken(), 'application/octet-stream'), 'abcdef');

        self::assertResponseStatusCodeSame(404);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsStringIgnoringCase('unknown', (string) $data['message']);
    }

    /**
     * Starts a chunked upload session and returns the server-side upload id.
     */
    private function startChunkedUpload(int $totalSize): string
    {
        $this->client->request('POST', '/admin/backup/upload/init', [
            'totalSize' => (string) $totalSize,
        ], [], $this->uploadHeaders($this->uploadCsrfToken()));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        $uploadId = $data['uploadId'] ?? null;
        self::assertIsString($uploadId);

        return $uploadId;
    }

    private function sendUploadChunk(string $uploadId, int $index, string $content): void
    {
        $this->client->request(
            'POST',
            '/admin/backup/upload/chunk?uploadId='.$uploadId.'&index='.(string) $index,
            [],
            [],
            $this->uploadHeaders($this->uploadCsrfToken(), 'application/octet-stream'),
            $content,
        );
        self::assertResponseIsSuccessful();
    }

    private function finishChunkedUpload(string $uploadId): void
    {
        $this->client->request('POST', '/admin/backup/upload/finish', [
            'uploadId' => $uploadId,
        ], [], $this->uploadHeaders($this->uploadCsrfToken()));
    }

    private function uploadCsrfToken(): string
    {
        $this->client->request('GET', '/admin/backup');
        $crawler = $this->client->getCrawler();
        $token = $crawler->filter('#backup-upload-form')->attr('data-csrf');
        \assert(is_string($token));

        return $token;
    }

    /**
     * @return array<string, string>
     */
    private function uploadHeaders(string $csrfToken, string $contentType = 'application/x-www-form-urlencoded'): array
    {
        return [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
            'HTTP_X-CSRF-Token' => $csrfToken,
            'CONTENT_TYPE' => $contentType,
        ];
    }

    public function testRestoreRoundtripBringsBackDeletedUser(): void
    {
        $service = $this->backupService();
        $filename = $service->create(false, null);

        // Destructive change after the backup was taken.
        $this->em->remove($this->user);
        $this->em->flush();
        $repository = $this->userRepository();
        self::assertNull($repository->findOneByEmail('user@example.com'));

        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/backup', [], [], $this->xhr());

        $form = $crawler->filter('#backup-restore-form')->form();
        $values = $form->getPhpValues();
        $values['restoreFilename'] = $filename;

        $this->client->request('POST', '/admin/backup/restore', $values, [], $this->xhr());
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);

        $restoredUser = $repository->findOneByEmail('user@example.com');
        self::assertNotNull($restoredUser);
        self::assertNotNull($repository->findOneByEmail('admin@example.com'));

        // A safety backup of the pre-restore state was created automatically.
        $names = array_map(
            static fn ($info): string => $info->filename,
            $service->listBackups(),
        );
        self::assertCount(2, $names);
        $preRestore = array_values(array_filter(
            $names,
            static fn (string $name): bool => str_contains($name, 'avalynxoffice-pre-restore-'),
        ));
        self::assertCount(1, $preRestore);
    }

    public function testRestoreEncryptedRoundtripWithWrongAndRightPassword(): void
    {
        $service = $this->backupService();
        $filename = $service->create(false, 'super-secret-password');

        $this->em->remove($this->user);
        $this->em->flush();
        $repository = $this->userRepository();
        self::assertNull($repository->findOneByEmail('user@example.com'));

        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/backup', [], [], $this->xhr());

        $form = $crawler->filter('#backup-restore-form')->form();
        $values = $form->getPhpValues();
        $values['restoreFilename'] = $filename;
        $values['restorePassword'] = 'totally-wrong';

        $this->client->request('POST', '/admin/backup/restore', $values, [], $this->xhr());
        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertStringContainsString('wrong', (string) $data['message']);
        self::assertNull($repository->findOneByEmail('user@example.com'));

        $values['restorePassword'] = 'super-secret-password';
        $this->client->request('POST', '/admin/backup/restore', $values, [], $this->xhr());
        self::assertResponseIsSuccessful();

        self::assertNotNull($repository->findOneByEmail('user@example.com'));
    }

    public function testRestoreEncryptedArchiveRequiresPassword(): void
    {
        $service = $this->backupService();
        $filename = $service->create(false, 'super-secret-password');

        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/backup', [], [], $this->xhr());

        $form = $crawler->filter('#backup-restore-form')->form();
        $values = $form->getPhpValues();
        $values['restoreFilename'] = $filename;
        unset($values['restorePassword']);

        $this->client->request('POST', '/admin/backup/restore', $values, [], $this->xhr());
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('restorePassword', $data['invalid']);
    }

    public function testPageShowsAutoBackupCardWithCronUrl(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/backup');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('#backup-schedule-form')->count());
        $cronUrl = $crawler->filter('[data-copy-value]')->attr('data-copy-value');
        self::assertIsString($cronUrl);
        self::assertStringContainsString('/cron/backup?token=', $cronUrl);
        self::assertMatchesRegularExpression('/token=[0-9a-f]{48}$/', $cronUrl);
    }

    public function testSaveScheduleSettingsPersistsValues(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/backup', [], [], $this->xhr());

        $form = $crawler->filter('#backup-schedule-form')->form();
        $values = $form->getPhpValues();
        $values['backup_schedule']['enabled'] = '1';
        $values['backup_schedule']['intervalHours'] = '12';
        // Unchecked checkboxes are omitted by browsers entirely.
        unset($values['backup_schedule']['includeFiles']);
        $values['backup_schedule']['retention'] = '5';

        $this->client->request('POST', '/admin/backup/schedule', $values, [], $this->xhr());
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);

        $settings = $this->scheduleService()->all();
        self::assertTrue($settings['enabled']);
        self::assertSame(12, $settings['intervalHours']);
        self::assertFalse($settings['includeFiles']);
        self::assertSame(5, $settings['retention']);
    }

    public function testSaveScheduleClampsOutOfRangeValues(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/backup', [], [], $this->xhr());

        $form = $crawler->filter('#backup-schedule-form')->form();
        $values = $form->getPhpValues();
        $values['backup_schedule']['enabled'] = '1';
        $values['backup_schedule']['intervalHours'] = '9999';
        $values['backup_schedule']['retention'] = '-3';

        $this->client->request('POST', '/admin/backup/schedule', $values, [], $this->xhr());
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('backup_schedule[intervalHours]', $data['invalid']);
        self::assertArrayHasKey('backup_schedule[retention]', $data['invalid']);
    }

    public function testRegenerateCronTokenChangesUrl(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/backup');
        $urlBefore = (string) $crawler->filter('[data-copy-value]')->attr('data-copy-value');

        $form = $crawler->filter('form[action$="/cron-token/regenerate"]')->form();

        $this->client->request('POST', '/admin/backup/cron-token/regenerate', $form->getPhpValues(), [], $this->xhr());
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/admin/backup');
        $urlAfter = (string) $crawler->filter('[data-copy-value]')->attr('data-copy-value');
        self::assertStringContainsString('/cron/backup?token=', $urlAfter);
        self::assertNotSame($urlBefore, $urlAfter);
    }
}
