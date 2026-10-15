<?php

declare(strict_types=1);

namespace App\Tests\Plugin\MailActionBundle\Plugin;

use App\Entity\Email;
use JBSNewMedia\MailActionBundle\Plugin\EmailAction\EmailDeleteActionPlugin;
use JBSNewMedia\MailActionBundle\Plugin\EmailAction\EmailForwardActionPlugin;
use JBSNewMedia\MailActionBundle\Plugin\EmailAction\EmailMarkSpamActionPlugin;
use JBSNewMedia\MailActionBundle\Plugin\EmailAction\EmailMoveActionPlugin;
use JBSNewMedia\MailActionBundle\Plugin\EmailAction\EmailPrintActionPlugin;
use JBSNewMedia\MailActionBundle\Plugin\EmailAction\EmailReplyAllActionPlugin;
use JBSNewMedia\MailActionBundle\Plugin\EmailAction\EmailReplyOrEditActionPlugin;
use JBSNewMedia\MailActionBundle\Plugin\EmailAction\EmailToggleReadActionPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailActionPluginsTest extends TestCase
{
    public static function provideSimpleActionPlugins(): iterable
    {
        yield [EmailDeleteActionPlugin::class, '@MailAction/mail_action/email/actions/delete.html.twig'];
        yield [EmailForwardActionPlugin::class, '@MailAction/mail_action/email/actions/forward.html.twig'];
        yield [EmailMarkSpamActionPlugin::class, '@MailAction/mail_action/email/actions/mark_spam.html.twig'];
        yield [EmailMoveActionPlugin::class, '@MailAction/mail_action/email/actions/move.html.twig'];
        yield [EmailPrintActionPlugin::class, '@MailAction/mail_action/email/actions/print.html.twig'];
        yield [EmailToggleReadActionPlugin::class, '@MailAction/mail_action/email/actions/toggle_read.html.twig'];
    }

    #[DataProvider('provideSimpleActionPlugins')]
    public function testSimpleActionPluginsRenderTheirTemplate(string $pluginClass, string $template): void
    {
        $view = (new $pluginClass())->build(new Email());

        self::assertSame($template, $view?->getTemplate());
        self::assertSame([], array_diff_key($view?->getContext() ?? [], ['email' => true]));
        self::assertArrayHasKey('email', $view?->getContext() ?? []);
    }

    public function testReplyOrEditPluginBuildsReplyViewForIncomingEmail(): void
    {
        $view = (new EmailReplyOrEditActionPlugin())->build(new Email());

        self::assertSame('@MailAction/mail_action/email/actions/reply.html.twig', $view?->getTemplate());
    }

    public function testReplyAllPluginBuildsViewForIncomingEmail(): void
    {
        $view = (new EmailReplyAllActionPlugin())->build(new Email());

        self::assertSame('@MailAction/mail_action/email/actions/reply_all.html.twig', $view?->getTemplate());
    }

    public function testReplyAllPluginSkipsOutgoingEmail(): void
    {
        $email = (new Email())->setIsOutgoing(true);

        self::assertNull((new EmailReplyAllActionPlugin())->build($email));
    }

    public function testReplyOrEditPluginBuildsEditViewForOutgoingDraft(): void
    {
        $email = (new Email())
            ->setIsOutgoing(true)
            ->setIsDraft(true);

        $view = (new EmailReplyOrEditActionPlugin())->build($email);

        self::assertSame('@MailAction/mail_action/email/actions/edit.html.twig', $view?->getTemplate());
    }

    public function testReplyOrEditPluginReturnsNullForSentOutgoingEmail(): void
    {
        $email = (new Email())
            ->setIsOutgoing(true)
            ->setIsSent(true);

        self::assertNull((new EmailReplyOrEditActionPlugin())->build($email));
    }
}
