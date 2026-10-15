<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\AppFixtures;
use App\Entity\Address;
use App\Entity\Attachment;
use App\Entity\Body;
use App\Entity\Email;
use App\Entity\EmailAddressLink;
use App\Entity\EmailBodyLink;
use App\Entity\Enum\BodyContentType;
use App\Entity\Enum\EmailAddressType;
use App\Entity\Mailbox;
use App\Entity\MailboxConnector;
use App\Service\Mail\AttachmentStorage;
use App\Service\Mail\FolderManager;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Persistence\Proxy;

final class AppFixturesUnitTest extends TestCase
{
    public function testUnproxyReturnsPlainValueForNonProxyInstances(): void
    {
        $fixtures = $this->createFixtures();
        $reflection = new \ReflectionMethod(AppFixtures::class, 'unproxy');

        $value = new \stdClass();

        self::assertSame($value, $reflection->invoke(null, $value));
    }

    public function testUnproxyUnwrapsProxyInstances(): void
    {
        $reflection = new \ReflectionMethod(AppFixtures::class, 'unproxy');

        $real = new \stdClass();
        $proxy = $this->createStub(Proxy::class);
        $proxy->method('_real')->willReturn($real);

        self::assertSame($real, $reflection->invoke(null, $proxy));
    }

    public function testCreateAttachmentPayloadCoversMimeTypeBranches(): void
    {
        $storage = $this->createStub(AttachmentStorage::class);
        $storage->method('getMaxBytes')->willReturn(64);
        $fixtures = $this->createFixtures($storage);
        $reflection = new \ReflectionMethod(AppFixtures::class, 'createAttachmentPayload');

        $csv = $reflection->invoke($fixtures, (new Attachment())->setName('contacts.csv')->setMimeType('text/csv')->setSize(0)->setSha1('')->setFilePath(''));
        $text = $reflection->invoke($fixtures, (new Attachment())->setName('notes.txt')->setMimeType('text/plain')->setSize(0)->setSha1('')->setFilePath(''));
        $pdf = $reflection->invoke($fixtures, (new Attachment())->setName('manual.pdf')->setMimeType('application/pdf')->setSize(0)->setSha1('')->setFilePath(''));
        $zip = $reflection->invoke($fixtures, (new Attachment())->setName('archive.zip')->setMimeType('application/zip')->setSize(0)->setSha1('')->setFilePath(''));

        self::assertStringStartsWith("name,email\nMax Mustermann,max@example.com\n", $csv);
        self::assertStringStartsWith('Fixture attachment notes.txt', $text);
        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringStartsWith("PK\x03\x04", $zip);
        self::assertSame(64, strlen($csv));
        self::assertSame(64, strlen($text));
    }

    public function testCreateAttachmentPayloadReturnsShortPrefixWhenStorageLimitIsTiny(): void
    {
        $storage = $this->createStub(AttachmentStorage::class);
        $storage->method('getMaxBytes')->willReturn(3);
        $fixtures = $this->createFixtures($storage);
        $reflection = new \ReflectionMethod(AppFixtures::class, 'createAttachmentPayload');

        $payload = $reflection->invoke($fixtures, (new Attachment())->setName('tiny.pdf')->setMimeType('application/pdf')->setSize(0)->setSha1('')->setFilePath(''));

        self::assertSame('%PD', $payload);
    }

    public function testFindOrCreateAddressUpdatesExistingAndCreatesNewAddress(): void
    {
        $existing = (new Address())
            ->setEmail('known@example.com')
            ->setName(null);

        $repository = $this->createStub(ObjectRepository::class);
        $repository->method('findOneBy')
            ->willReturnCallback(fn (array $criteria): ?Address => 'known@example.com' === ($criteria['email'] ?? null) ? $existing : null);

        $manager = $this->createMock(ObjectManager::class);
        $manager->method('getRepository')->willReturn($repository);
        $manager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(Address::class));

        $fixtures = $this->createFixtures();
        $reflection = new \ReflectionMethod(AppFixtures::class, 'findOrCreateAddress');

        $resolved = $reflection->invoke($fixtures, $manager, 'known@example.com', 'Known Name');
        $created = $reflection->invoke($fixtures, $manager, 'new@example.com', 'New Name');

        self::assertSame($existing, $resolved);
        self::assertSame('Known Name', $existing->getName());
        self::assertInstanceOf(Address::class, $created);
        self::assertSame('new@example.com', $created->getEmail());
        self::assertSame('New Name', $created->getName());
    }

    public function testCreateMailboxMailboxAddressesAndDeduplicationHelpers(): void
    {
        $knownAddress = (new Address())
            ->setEmail('known@example.com')
            ->setName('Known');

        $repository = $this->createStub(ObjectRepository::class);
        $repository->method('findOneBy')
            ->willReturnCallback(static fn (array $criteria): ?Address => 'known@example.com' === ($criteria['email'] ?? null) ? $knownAddress : null);

        $persisted = [];
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('getRepository')->willReturn($repository);
        $manager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $manager->expects(self::once())->method('flush');

        $fixtures = $this->createFixtures();

        $createMailbox = new \ReflectionMethod(AppFixtures::class, 'createMailbox');
        $mailbox = $createMailbox->invoke(
            $fixtures,
            $manager,
            'Support',
            'support@example.com',
            'Support Connector',
            'support@connector.example',
            ['known@example.com', 'new@example.com'],
            'support',
        );

        self::assertInstanceOf(Mailbox::class, $mailbox);
        self::assertSame('support@example.com', $mailbox->getEmail());
        self::assertCount(1, $mailbox->getConnectors());
        self::assertSame('smtp.support.example', $mailbox->getPrimaryConnector()?->getSmtpHost());
        self::assertCount(2, $mailbox->getAddresses());
        self::assertContainsOnlyInstancesOf(Mailbox::class, array_filter($persisted, static fn (object $entity): bool => $entity instanceof Mailbox));
        self::assertContainsOnlyInstancesOf(MailboxConnector::class, array_filter($persisted, static fn (object $entity): bool => $entity instanceof MailboxConnector));

        $mailboxAddresses = new \ReflectionMethod(AppFixtures::class, 'mailboxAddresses');
        $resolvedAddresses = $mailboxAddresses->invoke($fixtures, $manager, $mailbox);

        self::assertCount(3, $resolvedAddresses);
        self::assertSame('support@example.com', $resolvedAddresses[0]->getEmail());

        $deduplicate = new \ReflectionMethod(AppFixtures::class, 'deduplicateAddresses');
        $deduplicated = $deduplicate->invoke($fixtures, [
            (new Address())->setEmail('duplicate@example.com')->setName('First'),
            (new Address())->setEmail('DUPLICATE@example.com')->setName('Second'),
        ]);

        self::assertCount(1, $deduplicated);
        self::assertSame('Second', $deduplicated[0]->getName());
    }

    public function testLinkHelpersAndRandomSelectionHelpers(): void
    {
        $persisted = [];
        $manager = $this->createStub(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $fixtures = $this->createFixtures();
        $email = new Email();
        $address = (new Address())->setEmail('linked@example.com');
        $body = (new Body())->setContent('Linked body');

        $linkAddress = new \ReflectionMethod(AppFixtures::class, 'linkAddress');
        $linkAddress->invoke($fixtures, $manager, $email, $address, EmailAddressType::RECIPIENT);

        $linkBody = new \ReflectionMethod(AppFixtures::class, 'linkBody');
        $linkBody->invoke($fixtures, $manager, $email, $body, BodyContentType::HTML);

        self::assertCount(2, $persisted);
        self::assertInstanceOf(EmailAddressLink::class, $persisted[0]);
        self::assertSame($address, $persisted[0]->getAddress());
        self::assertSame(EmailAddressType::RECIPIENT, $persisted[0]->getType());
        self::assertInstanceOf(EmailBodyLink::class, $persisted[1]);
        self::assertSame($body, $persisted[1]->getBody());
        self::assertSame(BodyContentType::HTML, $persisted[1]->getContentType());

        $randomUniqueSubset = new \ReflectionMethod(AppFixtures::class, 'randomUniqueSubset');
        self::assertSame([], $randomUniqueSubset->invoke(null, ['a', 'b'], 0));
        self::assertCount(2, $randomUniqueSubset->invoke(null, ['a', 'b'], 99));

        $randomElement = new \ReflectionMethod(AppFixtures::class, 'randomElement');
        self::assertContains($randomElement->invoke(null, ['left', 'right']), ['left', 'right']);
    }

    private function createFixtures(?AttachmentStorage $storage = null): AppFixtures
    {
        return new AppFixtures(
            $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(FolderManager::class),
            $storage ?? $this->createStub(AttachmentStorage::class),
        );
    }
}
