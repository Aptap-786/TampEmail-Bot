# Official PHP image use karo
FROM php:8.2-cli

# Extensions install karo (mysqli, pdo_mysql etc.)
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Project files copy karo
WORKDIR /app
COPY . .

# Dependencies install karo agar composer.json hai
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer install --no-dev --optimize-autoloader || true

# Expose port for web service
EXPOSE 10000

# Default command
CMD ["php", "-S", "0.0.0.0:10000", "webhook.php"]
