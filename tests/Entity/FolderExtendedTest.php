<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Entity;

use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\FolderAccount;
use AvalynxOffice\Entity\Account;
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

    public function testGetAccountsDeduplicatesFromLinks(): void
    {
        $folder = (new Folder())->setName('Inbox');
        $accountA = (new Account())->setName('A')->setEmail('a@example.com');
        $accountB = (new Account())->setName('B')->setEmail('b@example.com');

        $folder->getAccountLinks()->add((new FolderAccount())->setFolder($folder)->setAccount($accountA));
        $folder->getAccountLinks()->add((new FolderAccount())->setFolder($folder)->setAccount($accountA));
        $folder->getAccountLinks()->add((new FolderAccount())->setFolder($folder)->setAccount($accountB));

        $accounts = $folder->getAccounts();

        self::assertCount(2, $accounts);
        self::assertTrue($accounts->contains($accountA));
        self::assertTrue($accounts->contains($accountB));
    }
}
