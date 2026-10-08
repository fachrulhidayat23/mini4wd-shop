FROM dunglas/frankenphp:1-php8.4-bookworm

WORKDIR /app

COPY . /app

CMD ["sh", "-c", "frankenphp php-server --root /app --listen 0.0.0.0:${PORT}"]