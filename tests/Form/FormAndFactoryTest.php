<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Form;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Attachment;
use AvalynxOffice\Entity\Body;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Factory\AddressFactory;
use AvalynxOffice\Factory\AttachmentFactory;
use AvalynxOffice\Factory\BodyFactory;
use AvalynxOffice\Factory\EmailFactory;
use AvalynxOffice\Factory\FolderFactory;
use AvalynxOffice\Factory\AccountFactory;
use AvalynxOffice\Form\EmailReplyType;
use AvalynxOffice\Form\ConnectorType;
use AvalynxOffice\Form\AccountType;
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

    public function testAccountTypeBuildsExpectedFieldsAndDefaults(): void
    {
        $addedFields = [];
        $builder = $this->createBuilderSpy($addedFields);
        $options = [
            'aliases_data' => 'aliases',
            'folders_data' => 'folders',
            'users_data' => [],
        ];

        (new AccountType())->buildForm($builder, $options);

        self::assertSame(['name', 'email', 'users', 'aliases', 'folders', 'save'], array_column($addedFields, 0));
        self::assertSame([], $addedFields[2][2]['data']);
        self::assertSame('aliases', $addedFields[3][2]['data']);
        self::assertSame('folders', $addedFields[4][2]['data']);

        $resolver = new OptionsResolver();
        (new AccountType())->configureOptions($resolver);
        $resolved = $resolver->resolve();

        self::assertSame(Account::class, $resolved['data_class']);
        self::assertSame('', $resolved['aliases_data']);
        self::assertSame('', $resolved['folders_data']);
        self::assertSame([], $resolved['users_data']);
        self::assertSame([], $resolved['connector_options']);
    }

    public function testAccountConnectorTypeBuildsExpectedFieldsAndDefaults(): void
    {
        $addedFields = [];
        $builder = $this->createBuilderSpy($addedFields);

        (new ConnectorType())->buildForm($builder, []);

        self::assertSame([
            'name',
            'domain',
            'plugin',
            'fetchFrom',
            'defaultAccount',
            'save',
        ], array_column($addedFields, 0));

        $resolver = new OptionsResolver();
        (new ConnectorType())->configureOptions($resolver);

        $resolved = $resolver->resolve();
        self::assertSame(Connector::class, $resolved['data_class']);
        self::assertSame([], $resolved['pluginChoices']);
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
        yield 'account' => [AccountFactory::class, Account::class, ['name', 'email']];
    }
}
