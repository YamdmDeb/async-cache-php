# Use PHP 8.2 CLI on Alpine Linux for a minimal and secure base image
FROM php:8.2-cli-alpine

# Install git and unzip which are required by Composer to fetch some packages
RUN apk add --no-cache git unzip

# Import the Composer binary from the official image to manage PHP dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Define the working directory inside the container for all subsequent commands
WORKDIR /app

# Copy only dependency definitions first to optimize Docker layer caching for faster builds
COPY composer.json composer.lock* ./

# Install project dependencies including development ones needed for the demo server
RUN composer install --optimize-autoloader --no-scripts --no-interaction

# Copy the entire project source code into the container's working directory
COPY . .

# Generate a final optimized autoloader to include any newly copied source files
RUN composer dump-autoload --optimize

# Document that the application listens on port 8080 by default
EXPOSE 8080

# Start the demo application by executing the public entry point using the PHP interpreter
# We use the JSON array format to ensure the PHP process receives OS signals correctly
CMD ["php", "public/index.php"]
