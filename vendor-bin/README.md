# AssetComposerBundle

    COMPOSER_MEMORY_LIMIT=-1 composer run bin-ecs-install
    COMPOSER_MEMORY_LIMIT=-1 composer run bin-phpstan-install
    COMPOSER_MEMORY_LIMIT=-1 composer run bin-phpunit-install
    COMPOSER_MEMORY_LIMIT=-1 composer run bin-rector-install

### Run PHP-CS-Fixer

Check for coding standard violations.

    composer run bin-ecs

Check for coding standard violations and fix them.

    composer run bin-ecs-fix

### Run PHPStan

Check for errors.

    composer run bin-phpstan

### Run Rector

Check for code smells.

    composer run bin-rector

### RUN PHPUnit

Run tests without code coverage.

    composer run bin-phpunit-no-coverage

Run tests with code coverage.

    composer run bin-phpunit-coverage

## Run Development Tools outside Docker

    docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-ecs-fix"

    docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-phpstan"

    docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-phpunit"

    docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-phpunit-no-coverage"

    docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-phpunit-coverage"

    docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-rector-process"
