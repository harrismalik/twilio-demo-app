FROM dunglas/frankenphp:php8.4-bookworm

ENV SERVER_NAME=":8080"

RUN install-php-extensions @composer pdo_sqlite

RUN apt-get update
RUN apt-get install -y curl gnupg build-essential git python3
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
RUN apt-get install -y nodejs
RUN npm install -g npm@latest
RUN rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY package*.json ./
RUN npm install

COPY . .

RUN composer install \
  --ignore-platform-reqs \
  --optimize-autoloader \
  --prefer-dist \
  --no-interaction \
  --no-progress \
  --no-scripts

RUN npm run build
