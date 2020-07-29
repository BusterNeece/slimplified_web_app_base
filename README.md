# Web Utility Billing System

## Local Development

```bash
docker-compose build
docker-compose up -d
docker-compose run --rm web bash
```

Inside web container:

```bash
composer install
bin/console migrations:migrate
bin/console load-fixtures
```