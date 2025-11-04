# Dockerfile - Render.com PHP + PDO MySQL
FROM php:8.3-cli

# Install PDO MySQL driver
RUN docker-php-ext-install pdo_mysql

# Optional: mysqli
RUN docker-php-ext-install mysqli

# Copy app
COPY . /app
WORKDIR /app

EXPOSE $PORT

CMD php -S 0.0.0.0:$PORT