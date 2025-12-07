# Symfony 7.3 Docker Template (API‑only)

This repository is a **template** for Symfony 7.3 API services running in Docker with a full set of infrastructure:

- PHP‑FPM 8.4 (Alpine) with Symfony 7.3 (API‑only skeleton)
- Nginx as HTTP entrypoint
- Citus (PostgreSQL‑based sharding)
- Redis
- RabbitMQ
- Doctrine ORM + migrations
- Symfony Messenger (Doctrine transports + failure transport)
- Observability stack: Prometheus, Grafana, Loki, Promtail, exporters
- k6 for HTTP load testing

The goal is to provide a **production‑like environment** for local development and experiments with metrics, logging and messaging.

---

## Docker stack

Defined in `docker-compose.yml`:

- `php` – PHP 8.4 FPM (Alpine)
  - Built from `docker/php/Dockerfile`
  - Uses `install-php-extensions` (intl, opcache, pdo_pgsql, zip, xdebug in dev)
  - Runs as `www-data`, working dir `/var/www/app`
  - Mounts `./app` as project root
- `nginx` – Nginx 1.27 (Alpine)
  - Configured via `docker/nginx/nginx.conf` and `docker/nginx/conf.d/app.conf`
  - Listens on port `8080` in the container
  - Exposed as `APP_HTTP_PORT` (default `8080`) on the host
  - Access log in JSON to stdout (used by Loki)
- Citus cluster (PostgreSQL‑based sharding):
  - `db` – Citus coordinator node
    - Data volume: `db-data`
    - Tuned with `docker/postgres/postgresql.conf`
    - `pg_stat_statements` and `citus` extensions enabled via `docker/postgres/init.sql`
    - Регистрирует воркеры через `citus_add_node('db-worker1', 5432)` и `citus_add_node('db-worker2', 5432)`
    - Healthcheck via `pg_isready`
  - `db-worker1`, `db-worker2` – Citus worker nodes
    - Собственные data‑volume (`db-worker1-data`, `db-worker2-data`)
    - Тот же конфиг `postgresql.conf`
    - Расширения `pg_stat_statements` и `citus` включаются через `docker/postgres/init-worker.sql`
- `redis` – Redis 7 (Alpine)
  - `maxmemory` taken from `APP_REDIS_MEMORY_LIMIT`
- `rabbitmq` – RabbitMQ 3 management
  - Default user/pass: `app/app`
  - Ports:
    - AMQP: `APP_RABBITMQ_PORT` (default `5672`)
    - Management UI: `APP_RABBITMQ_MGMT_PORT` (default `15672`)
- Exporters & observability
  - `postgres-exporter` – PostgreSQL metrics (Prometheus)
  - `redis-exporter` – Redis metrics (`oliver006/redis_exporter`)
  - `rabbitmq-exporter` – RabbitMQ metrics (`kbudde/rabbitmq-exporter`)
  - `prometheus` – metrics storage (`docker/prometheus/prometheus.yml`)
  - `grafana` – dashboards (Redis, RabbitMQ, HTTP, etc.)
  - `loki` – log storage
  - `promtail` – collects Docker logs → Loki
- `k6` – Grafana k6 image for load testing

---

### Order entity & sharding with Citus

Entity: `App\Entity\Order` (table `orders`)

Fields:
- `id` – integer, PK
- `userId` – integer, **shard key** for Citus
- `amount` – decimal(10, 2) (stored as string)
- `status` – string (up to 50 chars)
- `createdAt` – `DateTimeImmutable`
- `updatedAt` – nullable `DateTimeImmutable`

Sharding:
- The `orders` table is converted to a **distributed Citus table** by migration `Version20250102000000.php`.
- Shard key: `user_id` (логика — все заказы одного пользователя живут в одном шарде).
- В миграции используется `create_distributed_table('orders', 'user_id', 'hash', 2)` — таким образом создаётся **2 шарда** для таблицы `orders`.

Контроллер: `App\Controller\OrderController`

Маршруты:

- `POST /orders`
  - Создаёт новый заказ:
    ```json
    { "userId": 1, "amount": 9.99, "status": "new" }
    ```
  - `status` необязателен, по умолчанию `"new"`.
  - На успех: `201 Created` с созданным заказом.
  - На невалидный payload (`userId`/`amount` не числовые): `400 Bad Request`.

- `GET /orders?userId=...`
  - Возвращает последние заказы конкретного пользователя (до 50 штук, сортировка по `id DESC`).
  - Это **роутинг‑запрос** в Citus: по `user_id` попадает в один шард.

- `GET /orders/summary`
  - Возвращает агрегированную сумму заказов по пользователям:
    ```json
    [
      { "userId": 1, "total": "99.90" },
      { "userId": 2, "total": "10.00" }
    ]
    ```
  - Это **распределённый запрос** (aggregation) по всем шардам.

Полезный SQL для просмотра шардов (в psql внутри контейнера `db`):

```sql
SELECT
  logicalrelid,
  shardid,
  shardminvalue,
  shardmaxvalue
FROM pg_dist_shard
WHERE logicalrelid = 'orders'::regclass;
```

### Sharding demo command

To quickly verify that Citus sharding works end‑to‑end from Symfony:

- Generate demo data and see shard layout:

  ```bash
  make php
  php bin/console app:sharding-demo --truncate --users=4 --orders-per-user=5
  ```

  The command will:

  - Show Citus nodes (`pg_dist_node`).
  - Show `orders` table shards and their placements (`pg_dist_shard` / `pg_dist_placement`).
  - Insert demo orders via Doctrine ORM for a few `userId` values (writes go through the coordinator and are sharded by Citus).
  - Run an aggregated query via `OrderRepository::getTotalAmountByUser()` (distributed query).
  - Print which shard each `userId` would be routed to (if `get_shard_id_for_distribution_column` is available in this Citus version).

---

## Makefile commands

From the repository root:

- Start stack:
  - `make up`
  - App URL is printed (uses `APP_HTTP_PORT` from `.env`)
- Rebuild only PHP container:
  - `make php-rebuild`
- Shell inside PHP container:
  - `make php`

Static analysis / code style (run inside PHP container via Docker):

- `make phpstan` – runs PHPStan with `phpstan.neon.dist`
- `make cs-fix` – runs PHP CS Fixer with `.php-cs-fixer.dist.php`
- `make rector` – runs Rector with `rector.php`
 - `make migrate` – runs Doctrine migrations inside PHP container

Load testing:

- `make k6` – runs k6 with `docker/k6/load.js` against the running stack.

---

## Getting started

Prerequisites:

- Docker + Docker Compose
- Make (optional but recommended)

Steps:

1. Clone the repo:

   ```bash
   git clone <this-repo-url>
   cd symfony-template-docker
   ```

2. Start the stack:

   ```bash
   make up
   ```

3. Apply database migrations:

   ```bash
   make migrate
   ```

4. Test the sharding:

   ```bash
   make php
   php bin/console app:sharding-demo --truncate --users=4 --orders-per-user=5
   ```
