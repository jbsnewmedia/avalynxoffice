# Developer Guide

This guide contains all essential information for developing on this project.

## 1. Starting the Docker environment

The project uses Docker Compose for the local development environment.

### Starting the containers
```bash
docker-compose up -d
```

### Entering the container
There are helper scripts in the `docker/` directory to get into the web container quickly:

- As the default user (`application`):
  ```bash
  ./docker/bash.sh
  ```
- As `root`:
  ```bash
  ./docker/root.sh
  ```

The web container is reachable at `http://localhost`.

---

## 2. Installing the dependencies

Once the container is running, the PHP dependencies must be installed. This should be done **inside** the container.

### Main dependencies
```bash
composer install
```

### Vendor-bin tools
The project uses `bamarni/composer-bin-plugin` for isolated installation of the development tools.

To install or update all tools, use the Composer scripts:

```bash
COMPOSER_MEMORY_LIMIT=-1 composer run bin-ecs-install
COMPOSER_MEMORY_LIMIT=-1 composer bin-phpstan-install
COMPOSER_MEMORY_LIMIT=-1 composer bin-phpunit-install
COMPOSER_MEMORY_LIMIT=-1 composer bin-rector-install
```

---

## 3. Setting up the database

The database is started automatically via Docker (PostgreSQL). Initially, the migrations must be executed.

```bash
bin/console doctrine:migrations:migrate --no-interaction
```

---

## 4. Creating test data

To fill the application with test data, Doctrine Fixtures and Zenstruck Foundry are used.

### Loading the fixtures
This command empties the database and loads the predefined test data from `src/DataFixtures/`:

```bash
bin/console doctrine:fixtures:load --no-interaction
```
*(Use the `--append` option if the data should not be deleted.)*

**Note:** By default, the fixtures create an admin user:
- **Email:** `admin@example.com`
- **Password:** `admin`
- **Role:** `ROLE_ADMIN`

Additionally, numerous test emails, various mailboxes and folders are generated.

### Initializing the database (migrations & fixtures)
To rebuild the database from scratch (migrations + fixtures), the following command can be used:
```bash
bin/console doctrine:migrations:migrate --no-interaction && bin/console doctrine:fixtures:load --no-interaction
```

### Emptying the mail data manually
There is a dedicated command to empty only the mail-related tables:
```bash
bin/console app:mail:clear
```

---

## 5. Running the vendor-bin tools

Various tools are available for code quality and tests. They can conveniently be invoked via Composer scripts.

### Code style (ECS / PHP-CS-Fixer)
- Check: `composer bin-ecs`
- Fix automatically: `composer bin-ecs-fix`

### Static analysis (PHPStan)
- Analyze: `composer bin-phpstan`

### Refactoring (Rector)
- Dry run: `composer bin-rector`
- Apply changes: `composer bin-rector-process`

### Tests (PHPUnit)
- Run all tests: `composer test`
- With coverage (HTML): `composer test-coverage` (creates a report in `var/coverage`)
- Complete test suite: `composer test-full`

---

## 6. Application-specific commands

The project contains a few tailor-made commands for mail processing:

- **Fetch emails (POP3):**
  ```bash
  bin/console app:mail:fetch
  ```
- **Send emails:**
  ```bash
  bin/console app:mail:send
  ```
- **Release held (license-related) messages:**
  ```bash
  bin/console app:license:release-mail
  ```
- **Add a mailbox:**
  ```bash
  bin/console app:mailbox:add
  ```
- **Configure SMTP:**
  ```bash
  bin/console app:mailbox:configure-smtp
  ```

---

## 6a. License system

The technical documentation lives in [`licensing.md`](licensing.md); the legal
texts are in `LICENSE`, `LICENSING.md` and `COMMERCIAL-LICENSE.md`.

- **Check the status:** administration area `/admin/license` (role
  `ROLE_ADMIN`) or `/license` for users.
- **ENV configuration:** `AVALYNXOFFICE_LICENSE_KEYS`, `AVALYNXOFFICE_LICENSE_FILE`,
  `AVALYNXOFFICE_LICENSE_PUBLIC_KEYS`, `AVALYNXOFFICE_ORGANIZATION_ID` (see `.env`).
- **Run the license tests:**
  ```bash
  docker exec --user=application -w /app avalynxoffice-web-1 bash -lc "vendor-bin/phpunit/vendor/bin/phpunit --configuration=phpunit-no-coverage.xml.dist tests/Licensing"
  ```
- **Issuance tool (issuer only, not part of the installation):** `bin/license-tool` (keygen / issue / decode / verify).

---

## 7. Assets & frontend

### Compiling SCSS
```bash
bin/console bootstrap:compile
```

### CSS purging (removing unused CSS)
```bash
bin/console assets:css-purge
```

---

## 8. Running the tools from the host (without bash)

If you do not want to enter the Docker container, the commands can also be run directly from the host system:

### Fixing code style
```bash
docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-ecs-fix"
```

### Static analysis
```bash
docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-phpstan"
```

### Rector (refactoring)
```bash
docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-rector-process"
```

### Tests
```bash
# All tests
docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-phpunit"

# Without coverage
docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-phpunit-no-coverage"

# With coverage
docker exec --user=application -w /app avalynxoffice-web-1 bash -c "composer run bin-phpunit-coverage"
```
