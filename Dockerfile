FROM php:8.2-cli-alpine

# Install required PHP extensions
RUN apk add --no-cache \
    curl-dev \
    && docker-php-ext-install curl

# Set working directory
WORKDIR /app

# Copy application file
COPY qbot.php /app/

# Create data directory for state and logs
RUN mkdir -p /app/data

# Run the bot
CMD ["php", "/app/qbot.php"]
