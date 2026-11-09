# Supertext Translation for Silverstripe: public demo (Railway). Build context = repository root:
#   docker build -f demo/Dockerfile -t supertext-silverstripe-demo .
FROM public.ecr.aws/docker/library/php:8.3-apache

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/2.13.2/install-php-extensions /usr/local/bin/
RUN install-php-extensions mysqli pdo_mysql intl gd zip bcmath exif opcache \
 && (a2dismod -f mpm_event mpm_worker >/dev/null 2>&1 || true) \
 && a2enmod mpm_prefork rewrite headers \
 && printf 'memory_limit=256M\nmax_execution_time=300\nupload_max_filesize=16M\npost_max_size=16M\n' > /usr/local/etc/php/conf.d/silverstripe.ini \
 && sed -ri 's#/var/www/html#/app/demo/project/public#g' /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf \
 && printf '<Directory /app/demo/project/public>\n  AllowOverride All\n  Require all granted\n</Directory>\n' > /etc/apache2/conf-enabled/silverstripe.conf

COPY --from=public.ecr.aws/docker/library/composer:2 /usr/bin/composer /usr/bin/composer

# The module goes to demo/module, which the demo project installs through a path repository.
WORKDIR /app
COPY composer.json /app/demo/module/
COPY _config /app/demo/module/_config
COPY client /app/demo/module/client
COPY lang /app/demo/module/lang
COPY lib /app/demo/module/lib
COPY src /app/demo/module/src
COPY templates /app/demo/module/templates
COPY demo/project /app/demo/project
COPY demo/docker /app/demo/docker
WORKDIR /app/demo/project
RUN rm -f .env && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --no-progress --prefer-dist \
 && mkdir -p public/assets silverstripe-cache \
 && chown -R www-data:www-data public/assets silverstripe-cache \
 && chmod +x /app/demo/docker/entrypoint.sh

ENV SS_ENVIRONMENT_TYPE=live TEMP_PATH=/app/demo/project/silverstripe-cache PORT=8080
EXPOSE 8080
CMD ["/app/demo/docker/entrypoint.sh"]
