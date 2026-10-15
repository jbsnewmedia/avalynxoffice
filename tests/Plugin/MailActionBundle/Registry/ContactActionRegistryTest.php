<?php

declare(strict_types=1);

namespace App\Tests\Plugin\MailActionBundle\Registry;

use App\Entity\Address;
use JBSNewMedia\MailActionBundle\Contract\ContactActionPluginInterface;
use JBSNewMedia\MailActionBundle\Model\TemplateView;
use JBSNewMedia\MailActionBundle\Registry\ContactActionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ContactActionRegistryTest extends TestCase
{
    public function testGetActionsSkipsNonMatchingPluginsAndNullViews(): void
    {
        $validPlugin = new class implements ContactActionPluginInterface {
            public function build(Address $contact): ?TemplateView
            {
                return new TemplateView('contact_action.html.twig', ['contact' => $contact]);
            }
        };

        $nullPlugin = new class implements ContactActionPluginInterface {
            public function build(Address $contact): ?TemplateView
            {
                return null;
            }
        };

        $contact = new Address();
        $registry = new ContactActionRegistry(
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

        $actions = $registry->getActions($contact);

        self::assertCount(1, $actions);
        self::assertSame('contact_action.html.twig', $actions[0]->getTemplate());
        self::assertSame(['contact' => $contact], $actions[0]->getContext());
    }
}
