# Use an official PHP image with Apache
FROM php:8.2-apache

# Install necessary system packages for SQLite development headers and unzip
# apt-get update refreshes the package list
# libsqlite3-dev provides the development files for SQLite
# unzip is needed for Composer
RUN apt-get update && apt-get install -y libsqlite3-dev unzip \
    # Clean up apt caches to keep the image size down
    && rm -rf /var/lib/apt/lists/*

# Install PDO and PDO SQLite extensions
# These are necessary for PHP to interact with the SQLite database
RUN docker-php-ext-install pdo pdo_sqlite

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set the working directory inside the container
WORKDIR /var/www/html

# Copy Composer files (composer.json)
COPY composer.json .

# Run composer install to get dependencies
# --no-dev: Skips installing development dependencies
# --optimize-autoloader: Optimizes the autoloader for faster performance
RUN composer install --no-dev --optimize-autoloader

# Copy the application files from your local directory to the container's web root
COPY index.php .
COPY detail.php .
COPY upload.php .

# Create directories for data and uploaded documents and set appropriate permissions
# These directories will be mounted as volumes for persistence
RUN mkdir -p /var/www/html/data /var/www/html/uploads
RUN chown -R www-data:www-data /var/www/html/data /var/www/html/uploads
RUN chmod -R 775 /var/www/html/data /var/www/html/uploads

# Expose port 80, which Apache listens on by default
EXPOSE 80
