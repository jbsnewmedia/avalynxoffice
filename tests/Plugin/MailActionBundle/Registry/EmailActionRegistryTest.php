<?php

declare(strict_types=1);

namespace App\Tests\Plugin\MailActionBundle\Registry;

use App\Entity\Email;
use JBSNewMedia\MailActionBundle\Contract\EmailActionPluginInterface;
use JBSNewMedia\MailActionBundle\Model\TemplateView;
use JBSNewMedia\MailActionBundle\Registry\EmailActionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class EmailActionRegistryTest extends TestCase
{
    public function testGetActionsSkipsNonMatchingPluginsAndNullViews(): void
    {
        $validPlugin = new class implements EmailActionPluginInterface {
            public function build(Email $email): ?TemplateView
            {
                return new TemplateView('action.html.twig', ['email' => $email]);
            }
        };

        $nullPlugin = new class implements EmailActionPluginInterface {
            public function build(Email $email): ?TemplateView
            {
                return null;
            }
        };

        $email = new Email();
        $registry = new EmailActionRegistry(
            new ServiceLocator([
                'valid' => static fn () => $validPlugin,
                'null' => static fn () => $nullPlugin,
                'other' => static fn () => new \stdClass(),
            ]),
            [
                ['id' => 'valid', 'serviceId' => 'valid', 'priority' => 0, 'position' => 10],
                ['id' => 'null', 'serviceId' => 'null', 'priority' => 0, 'position' => 5],
                ['id' => 'other', 'serviceId' => 'other', 'priority' => 0, 'position' => 1],
            ]
        );

        $actions = $registry->getActions($email);

        self::assertCount(1, $actions);
        self::assertSame('action.html.twig', $actions[0]->getTemplate());
        self::assertSame(['email' => $email], $actions[0]->getContext());
    }
}
