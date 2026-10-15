<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\EmailDetailTemplateResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class EmailDetailTemplateResolverTest extends TestCase
{
    public function testFallsBackToCoreTemplateWithoutPlugin(): void
    {
        $resolver = new EmailDetailTemplateResolver(new ServiceLocator([]));

        self::assertSame('mail/_email_detail.html.twig', $resolver->resolveTemplate());
    }

    public function testUsesPluginTemplateWhenProviderExists(): void
    {
        $resolver = new EmailDetailTemplateResolver(new ServiceLocator([
            'JBSNewMedia\\MailViewBundle\\View\\EmailDetailTemplateProvider' => static fn (): object => new class {
                public function getTemplate(): string
                {
                    return '@MailView/mail/_email_detail.html.twig';
                }
            },
        ]));

        self::assertSame('@MailView/mail/_email_detail.html.twig', $resolver->resolveTemplate());
    }

    public function testFallsBackToCoreTemplateForInvalidOrEmptyProviderResults(): void
    {
        $invalidResolver = new EmailDetailTemplateResolver(new ServiceLocator([
            'JBSNewMedia\\MailViewBundle\\View\\EmailDetailTemplateProvider' => static fn (): object => new \stdClass(),
        ]));
        $emptyResolver = new EmailDetailTemplateResolver(new ServiceLocator([
            'JBSNewMedia\\MailViewBundle\\View\\EmailDetailTemplateProvider' => static fn (): object => new class {
                public function getTemplate(): string
                {
                    return '';
                }
            },
        ]));

        self::assertSame('mail/_email_detail.html.twig', $invalidResolver->resolveTemplate());
        self::assertSame('mail/_email_detail.html.twig', $emptyResolver->resolveTemplate());
    }
}
