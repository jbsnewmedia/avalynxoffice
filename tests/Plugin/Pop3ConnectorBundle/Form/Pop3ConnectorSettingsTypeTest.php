<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Plugin\Pop3ConnectorBundle\Form;

use JBSNewMedia\Pop3ConnectorBundle\Form\Pop3ConnectorSettingsType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;

final class Pop3ConnectorSettingsTypeTest extends TestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        $this->factory = Forms::createFormFactoryBuilder()->getFormFactory();
    }

    public function testSubmitsSettingsAndKeepsEmptyPasswords(): void
    {
        $form = $this->factory->create(Pop3ConnectorSettingsType::class, [
            'pop3Host' => 'mail.example.com',
            'pop3Port' => 995,
            'pop3Username' => 'user',
            'pop3Password' => 'old-pop-secret',
            'pop3Security' => 'ssl',
            'pop3DeleteAfterFetch' => true,
            'smtpHost' => 'smtp.example.com',
            'smtpPort' => 465,
            'smtpUsername' => 'smtp-user',
            'smtpPassword' => 'old-smtp-secret',
            'smtpSecurity' => 'ssl',
            'server_total' => 251,
        ]);

        $form->submit([
            'server_total' => '251',
            'pop3Host' => 'new.example.com',
            'pop3Port' => '110',
            'pop3Username' => 'new-user',
            'pop3Password' => '',
            'pop3Security' => 'tls',
            'pop3DeleteAfterFetch' => '1',
            'smtpHost' => 'new-smtp.example.com',
            'smtpPort' => '25',
            'smtpUsername' => 'new-smtp-user',
            'smtpPassword' => '',
            'smtpSecurity' => 'ssl',
            'save' => '',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        $data = $form->getData();
        self::assertSame('new.example.com', $data['pop3Host']);
        self::assertSame(110, $data['pop3Port']);
        self::assertSame('new-user', $data['pop3Username']);
        self::assertSame('old-pop-secret', $data['pop3Password']);
        self::assertSame('tls', $data['pop3Security']);
        self::assertTrue($data['pop3DeleteAfterFetch']);
        self::assertSame('new-smtp.example.com', $data['smtpHost']);
        self::assertSame(25, $data['smtpPort']);
        self::assertSame('new-smtp-user', $data['smtpUsername']);
        self::assertSame('old-smtp-secret', $data['smtpPassword']);
        self::assertSame('ssl', $data['smtpSecurity']);
        self::assertSame('251', $data['server_total']);
    }

    public function testNewConfigDefaultsToEmptyValues(): void
    {
        $form = $this->factory->create(Pop3ConnectorSettingsType::class, []);

        $view = $form->createView();

        self::assertSame('0', $view['server_total']->vars['value']);
        self::assertSame('', $view['pop3Password']->vars['value']);
    }

    public function testNullDataIsIgnoredByTheDefaultListener(): void
    {
        $form = $this->factory->create(Pop3ConnectorSettingsType::class, null);

        self::assertNull($form->getData());
    }

    public function testNewConfigStoresSubmittedValuesIncludingUncheckedCheckbox(): void
    {
        $form = $this->factory->create(Pop3ConnectorSettingsType::class, []);

        $form->submit([
            'server_total' => '0',
            'pop3Host' => 'mail.example.com',
            'pop3Username' => 'user',
            'pop3Password' => 'secret',
            'pop3Security' => 'ssl',
            'smtpHost' => 'smtp.example.com',
            'save' => '',
        ]);

        $data = $form->getData();
        self::assertSame('mail.example.com', $data['pop3Host']);
        self::assertSame('secret', $data['pop3Password']);
        self::assertFalse($data['pop3DeleteAfterFetch']);
        self::assertNull($data['pop3Port']);
        self::assertNull($data['smtpPort']);
    }

    public function testPasswordOverwriteWithNewValue(): void
    {
        $form = $this->factory->create(Pop3ConnectorSettingsType::class, [
            'pop3Password' => 'old',
            'smtpPassword' => 'old-smtp',
        ]);

        $form->submit([
            'server_total' => '0',
            'pop3Password' => 'new-secret',
            'smtpPassword' => 'new-smtp-secret',
        ]);

        $data = $form->getData();
        self::assertSame('new-secret', $data['pop3Password']);
        self::assertSame('new-smtp-secret', $data['smtpPassword']);
    }
}
