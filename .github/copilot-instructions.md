# Copilot Instructions

## Runtime and tooling

- Run Composer, Node, and all project tools inside Docker.
- Use the `avalynxoffice-web-1` container, the `application` user, and `/app` as the working directory.
- Prefer commands in this form:
  - `docker exec --user=application -w /app avalynxoffice-web-1 bash -lc "<command>"`

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

## Test data flow

- PHPUnit tests must generate their own data for each test run.
- For integration and functional tests, the database must be rebuilt in `setUp()` and then freshly populated using `AppFixtures`.
- No test may assume that fixture data from a previous test or from a manually prepared database is still available.
