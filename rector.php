<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Symfony\Set\SymfonySetList;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__.'/src',
        __DIR__ . '/plugins/jbsnewmedia/mail-action-bundle/src',
        __DIR__ . '/plugins/jbsnewmedia/mail-view-bundle/src',
        __DIR__ . '/plugins/jbsnewmedia/pop3-connector-bundle/src',
    ]);
    $rectorConfig->fileExtensions(['php']);

    $rectorConfig->rule(InlineConstructorDefaultToPropertyRector::class);

    $rectorConfig->rule(DeclareStrictTypesRector::class);

    $rectorConfig->sets([
        SymfonySetList::SYMFONY_CODE_QUALITY,
        SymfonySetList::SYMFONY_CONSTRUCTOR_INJECTION,
        LevelSetList::UP_TO_PHP_85,
    ]);
};
