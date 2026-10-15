<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\Address;
use App\Entity\CalendarEvent;
use App\Entity\Email;
use App\Twig\AppExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

class AppExtensionTest extends TestCase
{
    private AppExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new AppExtension();
    }

    public function testBytesToHuman(): void
    {
        $this->assertEquals('', $this->extension->bytesToHuman(null));
        $this->assertEquals('0 B', $this->extension->bytesToHuman(-1));
        $this->assertEquals('500 B', $this->extension->bytesToHuman(500));
        $this->assertEquals('1.0 KB', $this->extension->bytesToHuman(1024));
        $this->assertEquals('1.0 MB', $this->extension->bytesToHuman(1024 * 1024));
        $this->assertEquals('1.5 MB', $this->extension->bytesToHuman(1572864));
        $this->assertEquals('1.0 GB', $this->extension->bytesToHuman(1024 * 1024 * 1024));
    }

    public function testFileIconClass(): void
    {
        $this->assertEquals('fa-file-pdf', $this->extension->fileIconClass('application/pdf'));
        $this->assertEquals('fa-file-pdf', $this->extension->fileIconClass(null, 'test.pdf'));
        $this->assertEquals('fa-file-image', $this->extension->fileIconClass('image/jpeg'));
        $this->assertEquals('fa-file-image', $this->extension->fileIconClass(null, 'test.png'));
        $this->assertEquals('fa-file-audio', $this->extension->fileIconClass('audio/mpeg'));
        $this->assertEquals('fa-file-video', $this->extension->fileIconClass('video/mp4'));
        $this->assertEquals('fa-file-zipper', $this->extension->fileIconClass('application/zip'));
        $this->assertEquals('fa-file-word', $this->extension->fileIconClass('application/msword'));
        $this->assertEquals('fa-file-excel', $this->extension->fileIconClass('application/vnd.ms-excel'));
        $this->assertEquals('fa-file-powerpoint', $this->extension->fileIconClass('application/vnd.ms-powerpoint'));
        $this->assertEquals('fa-file-lines', $this->extension->fileIconClass('text/plain'));
        $this->assertEquals('fa-file-code', $this->extension->fileIconClass('application/json'));
        $this->assertEquals('fa-file', $this->extension->fileIconClass('application/octet-stream'));
    }

    public function testFileIconColor(): void
    {
        $this->assertEquals('text-danger', $this->extension->fileIconColor('application/pdf'));
        $this->assertEquals('text-primary', $this->extension->fileIconColor(null, 'test.doc'));
        $this->assertNull($this->extension->fileIconColor('image/jpeg'));
    }

    public function testGetFilters(): void
    {
        $filters = $this->extension->getFilters();
        $this->assertCount(1, $filters);
        $this->assertEquals('bytes_to_human', $filters[0]->getName());
    }

    public function testGetFunctions(): void
    {
        $functions = $this->extension->getFunctions();
        $this->assertCount(6, $functions);
        $this->assertEquals('file_icon', $functions[0]->getName());
        $this->assertEquals('file_icon_color', $functions[1]->getName());
        $this->assertEquals('mail_compose_button_views', $functions[2]->getName());
        $this->assertEquals('mail_email_action_views', $functions[3]->getName());
        $this->assertEquals('mail_contact_action_views', $functions[4]->getName());
        $this->assertEquals('mail_calendar_action_views', $functions[5]->getName());
    }

    public function testPluginFunctionsFallBackToEmptyArrays(): void
    {
        $extension = new AppExtension(new ServiceLocator([]));

        $this->assertSame([], $extension->getComposeButtonViews());
        $this->assertSame([], $extension->getEmailActionViews(new Email()));
        $this->assertSame([], $extension->getContactActionViews(new Address()));
        $this->assertSame([], $extension->getCalendarActionViews(new CalendarEvent()));
    }

    public function testPluginFunctionsReturnRegistryViewsAndIgnoreInvalidServices(): void
    {
        $composeRegistry = new class {
            public function getButtons(): array
            {
                return ['compose-button'];
            }
        };
        $emailRegistry = new class {
            public function getActions(Email $email): array
            {
                return [$email->getId()];
            }
        };
        $contactRegistry = new class {
            public function getActions(Address $contact): array
            {
                return [$contact->getId()];
            }
        };
        $calendarRegistry = new class {
            public function getActions(CalendarEvent $event): array
            {
                return [$event->getId()];
            }
        };
        $locator = new ServiceLocator([
            'JBSNewMedia\\MailActionBundle\\Registry\\ComposeButtonRegistry' => static fn (): object => $composeRegistry,
            'JBSNewMedia\\MailActionBundle\\Registry\\EmailActionRegistry' => static fn (): object => $emailRegistry,
            'JBSNewMedia\\MailActionBundle\\Registry\\ContactActionRegistry' => static fn (): object => $contactRegistry,
            'JBSNewMedia\\MailActionBundle\\Registry\\CalendarActionRegistry' => static fn (): object => $calendarRegistry,
        ]);

        $extension = new AppExtension($locator);
        $email = new Email();
        $contact = new Address();
        $event = new CalendarEvent();

        $this->assertSame(['compose-button'], $extension->getComposeButtonViews());
        $this->assertSame([$email->getId()], $extension->getEmailActionViews($email));
        $this->assertSame([$contact->getId()], $extension->getContactActionViews($contact));
        $this->assertSame([$event->getId()], $extension->getCalendarActionViews($event));
    }

    public function testPluginFunctionsReturnEmptyArraysForUnsupportedRegistryShapes(): void
    {
        $composeRegistry = new class {
            public function getButtons(): string
            {
                return 'invalid';
            }
        };
        $locatorWithNonArray = new ServiceLocator([
            'JBSNewMedia\\MailActionBundle\\Registry\\ComposeButtonRegistry' => static fn (): object => $composeRegistry,
            'JBSNewMedia\\MailActionBundle\\Registry\\EmailActionRegistry' => static fn (): object => new \stdClass(),
            'JBSNewMedia\\MailActionBundle\\Registry\\ContactActionRegistry' => static fn (): object => new \stdClass(),
            'JBSNewMedia\\MailActionBundle\\Registry\\CalendarActionRegistry' => static fn (): object => new \stdClass(),
        ]);
        $extension = new AppExtension($locatorWithNonArray);

        $this->assertSame([], $extension->getComposeButtonViews());
        $this->assertSame([], $extension->getEmailActionViews(new Email()));
        $this->assertSame([], $extension->getContactActionViews(new Address()));
        $this->assertSame([], $extension->getCalendarActionViews(new CalendarEvent()));
    }

    public function testComposeButtonViewsReturnEmptyArrayWhenRegistryHasNoButtonsMethod(): void
    {
        $locator = new ServiceLocator([
            'JBSNewMedia\\MailActionBundle\\Registry\\ComposeButtonRegistry' => static fn (): object => new \stdClass(),
        ]);

        $extension = new AppExtension($locator);

        $this->assertSame([], $extension->getComposeButtonViews());
    }

    public function testBytesToHumanCanFormatPetabytes(): void
    {
        $this->assertEquals('1.0 PB', $this->extension->bytesToHuman(1024 ** 5));
    }
}
