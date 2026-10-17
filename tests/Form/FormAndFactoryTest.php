<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Form;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Attachment;
use AvalynxOffice\Entity\Body;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Entity\MailboxConnector;
use AvalynxOffice\Factory\AddressFactory;
use AvalynxOffice\Factory\AttachmentFactory;
use AvalynxOffice\Factory\BodyFactory;
use AvalynxOffice\Factory\EmailFactory;
use AvalynxOffice\Factory\FolderFactory;
use AvalynxOffice\Factory\MailboxFactory;
use AvalynxOffice\Form\EmailReplyType;
use AvalynxOffice\Form\MailboxConnectorType;
use AvalynxOffice\Form\MailboxType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class FormAndFactoryTest extends TestCase
{
    public function testEmailReplyTypeBuildsExpectedFieldsAndDefaults(): void
    {
        $addedFields = [];
        $builder = $this->createBuilderSpy($addedFields);

        (new EmailReplyType())->buildForm($builder, []);

        self::assertSame(['from', 'to', 'cc', 'bcc', 'replyTo', 'subject', 'content', 'scheduleLater', 'scheduledAt', 'retainedAttachmentIds', 'attachments', 'send', 'saveDraft'], array_column($addedFields, 0));

        $resolver = new OptionsResolver();
        (new EmailReplyType())->configureOptions($resolver);

        self::assertSame(Email::class, $resolver->resolve()['data_class']);
        self::assertSame([], $resolver->resolve()['sender_choices']);
    }

    public function testMailboxTypeBuildsExpectedFieldsAndDefaults(): void
    {
        $addedFields = [];
        $builder = $this->createBuilderSpy($addedFields);
        $options = ['aliases_data' => 'aliases', 'folders_data' => 'folders'];

        (new MailboxType())->buildForm($builder, $options);

        self::assertSame(['name', 'email', 'aliases', 'folders', 'save'], array_column($addedFields, 0));
        self::assertSame('aliases', $addedFields[2][2]['data']);
        self::assertSame('folders', $addedFields[3][2]['data']);

        $resolver = new OptionsResolver();
        (new MailboxType())->configureOptions($resolver);
        $resolved = $resolver->resolve();

        self::assertSame(Mailbox::class, $resolved['data_class']);
        self::assertSame('', $resolved['aliases_data']);
        self::assertSame('', $resolved['folders_data']);
    }

    public function testMailboxConnectorTypeBuildsExpectedFieldsAndDefaults(): void
    {
        $addedFields = [];
        $builder = $this->createBuilderSpy($addedFields);

        (new MailboxConnectorType())->buildForm($builder, []);

        self::assertSame([
            'mailbox',
            'name',
            'email',
            'isPrimary',
            'pop3Host',
            'pop3Port',
            'pop3Username',
            'pop3Password',
            'pop3Security',
            'pop3DeleteAfterFetch',
            'smtpHost',
            'smtpPort',
            'smtpUsername',
            'smtpPassword',
            'smtpSecurity',
            'save',
        ], array_column($addedFields, 0));

        $resolver = new OptionsResolver();
        (new MailboxConnectorType())->configureOptions($resolver);

        self::assertSame(MailboxConnector::class, $resolver->resolve()['data_class']);
    }

    #[DataProvider('factoryProvider')]
    public function testFactoriesExposeEntityClassAndDefaults(string $factoryClass, string $entityClass, array $expectedKeys): void
    {
        self::assertSame($entityClass, $factoryClass::class());

        $reflection = new \ReflectionMethod($factoryClass, 'defaults');
        $defaults = $reflection->invoke(new $factoryClass());

        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $defaults);
        }
    }

    public function testAttachmentFactorySlugifyBaseNameFallsBackToFile(): void
    {
        self::assertSame('hello-world', AttachmentFactory::slugifyBaseName('Hello World'));
        self::assertSame('file', AttachmentFactory::slugifyBaseName('!!!'));
        self::assertSame('file', AttachmentFactory::slugifyBaseName(''));
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: array<mixed>}> $addedFields
     */
    private function createBuilderSpy(array &$addedFields): FormBuilderInterface
    {
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('add')
            ->willReturnCallback(function (string $name, string $type, array $options = []) use (&$addedFields, $builder): FormBuilderInterface {
                $addedFields[] = [$name, $type, $options];

                return $builder;
            });

        return $builder;
    }

    /**
     * @return iterable<string, array{0: class-string, 1: class-string, 2: array<int, string>}>
     */
    public static function factoryProvider(): iterable
    {
        yield 'address' => [AddressFactory::class, Address::class, ['email', 'name']];
        yield 'attachment' => [AttachmentFactory::class, Attachment::class, ['name', 'size', 'mimeType', 'sha1', 'filePath']];
        yield 'body' => [BodyFactory::class, Body::class, ['content']];
        yield 'email' => [EmailFactory::class, Email::class, ['subject', 'previewBody', 'receivedAt']];
        yield 'folder' => [FolderFactory::class, Folder::class, ['functions', 'name']];
        yield 'mailbox' => [MailboxFactory::class, Mailbox::class, ['name', 'email']];
    }
}
