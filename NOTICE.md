# NOTICE — Third-party components

This project (AvalynxOffice) is licensed under the **AvalynxOffice Public
License (APL) version 1.0**; see `LICENSE` and `LICENSING.md`. It is **not**
MIT-licensed as a whole.

The project builds on third-party software. Each component keeps its own
license and copyright; the list below documents the most relevant components
and their licenses. A complete machine-readable list of the exact versions is
available via `composer show` (PHP dependencies) in the repository.

## PHP / server side

| Component | License | Copyright / Homepage |
|---|---|---|
| Symfony (framework, messenger, mailer, security, form, twig-bundle, …) | MIT | Fabien Potencier, Symfony SAS — symfony.com |
| Doctrine ORM / DBAL / migrations / fixtures | MIT | Doctrine Project — doctrine-project.org |
| Laminas Mail | BSD-3-Clause | Laminas Project (a Linux Foundation project) |
| Symfony Mailer transport stack | MIT | Symfony SAS |
| Twig | MIT | Fabien Potencier, Symfony SAS |
| Monolog | MIT | Jordi Boggiano |
| scssphp | MIT (MIT-style) | Leaf Corcoran |
| FakerPHP | MIT | FakerPHP contributors |
| jQuery (asset dependencies, if used) | MIT | OpenJS Foundation |

**Note on Exchange ActiveSync:** device synchronization is **not** part of
this repository. It is provided by the separate, independently installed
project **avalynxofficeeas** (AGPL-3.0, based on Z-Push
https://github.com/Z-Hub/Z-Push). That project runs as its own process,
contains no core code, and is licensed and documented in its own repository.

## Frontend assets

| Component | License | Copyright |
|---|---|---|
| Bootstrap 5 | MIT | The Bootstrap Authors, Twitter, Inc. |
| Font Awesome (Free) | CC-BY-4.0 (icons) / MIT (code) / OFL-1.1 (fonts) | Fonticons, Inc. |
| flag-icons | MIT | Copyright (c) 2011-2024, Lipis |
| AvalynxAlert / AvalynxAutocomplete | MIT | JBS New Media GmbH |
| app-specific styles and scripts in `assets/` and `templates/` | project license | JBS New Media GmbH |

## Plugins shipped in this repository

The plugins under `plugins/jbsnewmedia/*` are part of this project and fall
under the project license.

## Own asset notice

"AvalynxOffice", its logo and its source code are copyright
(c) 2026 JBS New Media GmbH. All rights not explicitly granted by the license
are reserved.
