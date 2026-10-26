<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        'src',
        'plugins/jbsnewmedia/bootstrap-bundle/src',
        'plugins/jbsnewmedia/exchange-connector-bundle/src',
        'plugins/jbsnewmedia/mail-action-bundle/src',
        'plugins/jbsnewmedia/mail-view-bundle/src',
        'plugins/jbsnewmedia/pop3-connector-bundle/src',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'strict_param' => true,
        'declare_strict_types' => true,
        'header_comment' => [
            'header' => "Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>\nLicensed under the AvalynxOffice Public License (APL) version 1.0.",
        ],
    ])
    ->setFinder($finder)
;
