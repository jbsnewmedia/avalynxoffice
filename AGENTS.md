# Project Instructions

## Deployment status

- There are **no production instances yet**. Big schema/data rebuilds are
  therefore cheap: prefer writing a **complete fresh migration** for each
  change instead of stacking data-preserving incremental migrations.
  This policy changes only with the first production system.

## Runtime and tooling

- Run Composer, Node, and all project tools inside Docker.
- Use the `avalynxoffice-web-1` container, the `application` user, and `/app` as the working directory.
- Prefer commands in this form:
    - `docker exec --user=application -w /app avalynxoffice-web-1 bash -lc "<command>"`
- Without the compose stack, use the published dev image
  `jbsnewmedia/avalynxoffice-dev` (built from `Dockerfile.dev`, ships the
  dev dependencies and the composer-bin tooling). Run as uid 1000
  (`application`) — permission-based tests cannot pass as root:
    - `docker run --rm --user 1000:1000 --entrypoint /bin/bash --network <db-network> -v "$PWD":/app -w /app -e DATABASE_URL="postgresql://user:pass@db:5432/my_database?serverVersion=16" jbsnewmedia/avalynxoffice-dev -lc "php bin/console doctrine:migrations:migrate --no-interaction && composer test"`
    - Inside the image (without a mount) the schema must be migrated once as well:
      `docker run --rm --user 1000:1000 --entrypoint /bin/bash --network <db-network> -e DATABASE_URL="..." jbsnewmedia/avalynxoffice-dev -lc "php bin/console doctrine:migrations:migrate --no-interaction && vendor-bin/phpunit/vendor/bin/phpunit --configuration=phpunit-no-coverage.xml.dist"`

## Installing dependencies

- When installing code quality tools with Composer, use:
    - `COMPOSER_MEMORY_LIMIT=-1 composer install`

## Required project commands

- PHPStan:
    - `composer bin-phpstan`
    - Do not add exclusions or ignore rules.
    - Do not use stubs.
- PHPUnit:
    - `composer test-coverage`
    - Maintain **100% code coverage** for classes, methods, and lines.
    - Do not add exclusions or ignore rules.
    - Do not use stubs.
- ECS:
    - `composer bin-ecs-fix`
- Rector:
    - `composer bin-rector-process`

## Pre-commit gate (mandatory)

Before **every** commit, the following must run and pass:

1. `composer bin-ecs` — must report **0** fixable files (run `composer bin-ecs-fix` first if needed).
2. `composer bin-rector` — dry-run must report **no changes** (run `composer bin-rector-process` first if needed).

Notes:

- Run these commands inside the Docker container as described above.
- The full quality gate (PHPStan level max, PHPUnit with all tests green,
  PHPUnit coverage with 100 % line coverage — use
  `COMPOSER_PROCESS_TIMEOUT=3600 composer test-coverage`) is enforced by CI
  on every push and must be green before a release; it does not have to run
  before each local commit.
- The test suite resets the shared development database (SchemaTool, without
  migration tracking): after running tests, recreate the test accounts
  (e.g. `php bin/console app:user:create`) and re-sync the migration tracking
  (`php bin/console doctrine:migrations:version --all --add --no-interaction`),
  otherwise the container bootstrap entrypoint fails on the next restart.

## Form validation

- Forms validate **inline only** — no native browser validation popups.
- On forms submitted via `data-avalynxform`, never rely on native blocking
    attributes (`pattern`, `required`, `type="email"`, ...): the browser
    balloon would prevent the submit event from ever firing. Add
    `novalidate` to the form instead.
- The server is the single source of truth: validate there and answer with
    `AvalynxFormResponse::invalid(['field' => message])`. AvalynxForm then
    renders the message inline (`is-invalid` class + `.invalid-feedback`).
- Templates must ship an (empty) `.invalid-feedback` element next to the
    field (wrap field + feedback in a `.form-group` container), otherwise
    AvalynxForm has nowhere to render the inline message.

## Feedback placement (HARD RULE — never violate this)

Every piece of user feedback has a fixed place. **NEVER** render feedback
above the navbar or outside the module content:

1. **Field errors → inline at the field.** Validation errors are answered
   by the server with `AvalynxFormResponse::invalid(['field' => message])`
   and rendered by AvalynxForm directly next to the input
   (`.invalid-feedback`). Never put field errors into a flash alert.
2. **Success / saved / info / warning / danger messages (flashes) → inside
   the module content area**, below the module nav and above/inside the
   module content — never at the top of the page. `base.html.twig` renders
   the `flashes` block above the navbar; **every page that extends
   `base.html.twig` MUST therefore silence it with
   `{% block flashes %}{% endblock %}`** and render the alerts itself
   inside the content via `{% include 'connector/_flashes.html.twig' %}`
   (that partial is the shared, module-independent include despite its
   location). Applies to EVERY page — settings pages, profile pages, mail
   pages, admin pages — not only admin modules.
3. Reference implementations: `templates/connector/settings.html.twig`,
   `templates/admin/system.html.twig`, `templates/legal/admin.html.twig`.
   Before building a new page, copy the skeleton of one of these.

A code review/checklist item for every new template: does the page have
`{% block flashes %}{% endblock %}` AND an in-content
`_flashes.html.twig` include? If not, it is broken.

## Flash messages

(Merged into "Feedback placement" above — the rules there replace this
section; kept as pointer so greps still find it.)

## Test data flow

- PHPUnit tests must generate their own data for each test run.
- For integration and functional tests, the database must be rebuilt in `setUp()` and then freshly populated using `AppFixtures`.
- No test may assume that fixture data from a previous test or from a manually prepared database is still available.

## Tags and releases — maintainer only

**Agents must never create, push, or delete git tags or GitHub releases —
in this repository or in avalynxofficeeas.** Tagging, release creation and
version bumps are done exclusively by the maintainer (Jürgen Schwind).
This includes annotated/lightweight tags, remote tag pushes and any action
that could trigger release workflows on GitHub or Docker Hub. The agent may
report that a commit is a candidate for tagging, but must not tag.
