# Use the official PHP 8.2 Apache image
FROM php:8.3-apache

# Install system dependencies and PostgreSQL drivers for pgvector
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    supervisor \
    && docker-php-ext-install pdo pdo_pgsql

# Enable Apache mod_rewrite (Required for Laravel routing)
RUN a2enmod rewrite

# Change Apache document root to Laravel's /public directory
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy the application code into the container
COPY . /var/www/html

# Install Laravel dependencies (ignoring dev packages)
RUN composer install --no-dev --optimize-autoloader

# Set correct folder permissions for Laravel
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache

# Copy the Supervisor configuration
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Expose web port
EXPOSE 80

# When the container starts, run migrations, then start Supervisor
# CMD php artisan migrate --force && /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
CMD /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf