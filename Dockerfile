# Use official PHP image
FROM php:8.2-cli

# Set working directory
WORKDIR /app

# Copy project files
COPY . /app

# Install Composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm composer-setup.php

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Expose the port Render will use
EXPOSE 10000

# Start PHP built-in web server
CMD ["php", "-S", "0.0.0.0:10000", "callback.php"]

