FROM dunglas/frankenphp:1-php8.4-bookworm

WORKDIR /app

COPY . /app

ENV SERVER_NAME=:80

CMD ["frankenphp", "php-server", "--root", "/app", "--listen", ":80"]