<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        'src',
        'plugins/jbsnewmedia/mail-action-bundle/src',
        'plugins/jbsnewmedia/mail-view-bundle/src',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'strict_param' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder)
;
