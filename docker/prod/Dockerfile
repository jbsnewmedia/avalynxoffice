FROM webdevops/php-apache:8.5

ENV WEB_DOCUMENT_ROOT=/app/public \
    APP_ENV=prod \
    APP_DEBUG=0 \
    PHP_MEMORY_LIMIT=512M \
    PHP_MAX_EXECUTION_TIME=300 \
    PHP_POST_MAX_SIZE=200M \
    PHP_UPLOAD_MAX_FILESIZE=100M \
    PHP_DISMOD=ioncube \
    PHP_ENMOD=mailparse

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . /app

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && chown -R application:application /app

# Container bootstrap: wait for the database, run the bundled migrations and
# create the env-defined administrator (OFFICE_ADMIN_EMAIL/OFFICE_ADMIN_PASSWORD).
COPY docker/prod/office-entrypoint.sh /usr/local/bin/office-entrypoint
RUN chmod +x /usr/local/bin/office-entrypoint

ENTRYPOINT ["/usr/local/bin/office-entrypoint"]
CMD ["supervisord"]
