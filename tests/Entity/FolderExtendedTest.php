<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Entity;

use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\FolderMailbox;
use AvalynxOffice\Entity\Mailbox;
use PHPUnit\Framework\TestCase;

final class FolderExtendedTest extends TestCase
{
    public function testFolderFunctionsAreNormalizedAndUnique(): void
    {
        $folder = (new Folder())->setFunctions([
            FolderFunction::MAILBOX,
            'INBOX',
            ' inbox ',
            '',
        ]);

        self::assertSame(['mailbox', 'inbox'], $folder->getFunctions());
        self::assertTrue($folder->hasFunction(FolderFunction::MAILBOX));
        self::assertTrue($folder->hasFunction('inbox'));
        self::assertTrue($folder->hasAnyFunction());
    }

    public function testAddAndRemoveFunction(): void
    {
        $folder = new Folder();

        $folder->addFunction(FolderFunction::DRAFTS);
        $folder->addFunction('drafts');
        $folder->removeFunction('DRAFTS');

        self::assertSame([], $folder->getFunctions());
        self::assertFalse($folder->hasAnyFunction());
    }

    public function testGetMailboxesDeduplicatesFromLinks(): void
    {
        $folder = (new Folder())->setName('Inbox');
        $mailboxA = (new Mailbox())->setName('A')->setEmail('a@example.com');
        $mailboxB = (new Mailbox())->setName('B')->setEmail('b@example.com');

        $folder->getMailboxLinks()->add((new FolderMailbox())->setFolder($folder)->setMailbox($mailboxA));
        $folder->getMailboxLinks()->add((new FolderMailbox())->setFolder($folder)->setMailbox($mailboxA));
        $folder->getMailboxLinks()->add((new FolderMailbox())->setFolder($folder)->setMailbox($mailboxB));

        $mailboxes = $folder->getMailboxes();

        self::assertCount(2, $mailboxes);
        self::assertTrue($mailboxes->contains($mailboxA));
        self::assertTrue($mailboxes->contains($mailboxB));
    }
}
