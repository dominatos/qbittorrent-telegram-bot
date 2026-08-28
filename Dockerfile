FROM php:8.5-cli-alpine

# Install required PHP extensions and yt-dlp dependencies
RUN apk add --no-cache \
    curl-dev \
    python3 \
    py3-pip \
    ffmpeg \
    && docker-php-ext-install curl \
    && pip3 install --break-system-packages --no-cache-dir yt-dlp

# Set working directory
WORKDIR /app

# Copy application file
COPY qbot.php /app/

# Create data directory for state and logs
RUN mkdir -p /app/data

# Run the bot
CMD ["php", "/app/qbot.php"]
