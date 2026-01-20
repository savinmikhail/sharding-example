# Демонстрация шардирования PostgreSQL через Citus + Symfony

Этот репозиторий — простой, но “живой” пример того, как:

- поднять кластер PostgreSQL с шардированием на базе **Citus** (координатор + 2 воркера);
- прозрачно работать с этим кластером из приложения на **Symfony 7.3** через Doctrine, как будто это обычная одна база;
- посмотреть, как строки распределяются по шардам и как выполняются распределённые запросы.

Symfony‑приложение **ничего не знает о шардах**: в `DATABASE_URL` указан обычный хост `db`, Doctrine работает с одной БД `app`.  
Шардированием занимается только Citus на стороне PostgreSQL.

---

## Архитектура и стек

Все сервисы описаны в `docker-compose.yml`:

- `php` — PHP‑FPM 8.4 + Symfony 7.3 (API‑приложение).
- `nginx` — фронтовой HTTP‑сервер.
- **Citus кластер (PostgreSQL)**:
  - `db` — координатор (PostgreSQL с расширением `citus`).
  - `db-worker1`, `db-worker2` — два воркера (PostgreSQL с `citus`).

Кластеры Citus настраиваются через файлы в `docker/postgres/`:

- `postgresql.conf` — общий тюнинг PostgreSQL + `shared_preload_libraries = 'citus,pg_stat_statements'`.
- `init.sql` — инициализация координатора:
  - `CREATE EXTENSION citus;`
  - регистрация воркеров:
    - `SELECT citus_add_node('db-worker1', 5432);`
    - `SELECT citus_add_node('db-worker2', 5432);`
- `init-worker.sql` — инициализация воркеров (расширения и пользователь `app`).

---

## Как шардируется таблица `orders`

### Сущность Order

В Symfony‑приложении есть сущность `App\Entity\Order`, которая мапится на таблицу `orders`:

- `id` — идентификатор заказа.
- `userId` — идентификатор пользователя (**ключ шардирования**).
- `amount` — сумма (NUMERIC).
- `status` — статус.
- `createdAt` / `updatedAt` — даты.

### Миграция

Миграция `app/migrations/Version20250102000000.php`:

1. Создаёт обычную PostgreSQL‑таблицу:

   ```sql
   CREATE TABLE orders (
       id SERIAL NOT NULL,
       user_id INT NOT NULL,
       amount NUMERIC(10, 2) NOT NULL,
       status VARCHAR(50) NOT NULL,
       created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
       updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
   );

   CREATE INDEX idx_orders_user_id ON orders(user_id);
   ```

   Обрати внимание: **нет PRIMARY KEY** — Citus не разрешает делать распределённой таблицу с PK/UNIQUE, в которых нет колонки шардирования.

2. Включает расширение Citus (на случай, если оно ещё не создано):

   ```sql
   CREATE EXTENSION IF NOT EXISTS citus;
   ```

3. Говорит Citus, что таблицу нужно шардировать по `user_id` и создать 2 шарда:

   ```sql
   SET citus.shard_count TO 2;
   SELECT create_distributed_table('orders', 'user_id');
   ```

### Принцип шардирования

- Шард‑ключ: `user_id`.
- Citus использует **hash‑шардирование** по значению `user_id`.
- В конфигурации `citus.shard_count = 2` для таблицы `orders` создаются **2 шарда**, которые Citus размещает на двух воркерах.

Типичный вид шардов (можно посмотреть из `psql` на координаторе):

```sql
SELECT
  logicalrelid,
  shardid,
  shardminvalue,
  shardmaxvalue
FROM pg_dist_shard
WHERE logicalrelid = 'orders'::regclass;
```

Пример (похож на то, что получится у тебя):

```text
 logicalrelid | shardid |  shardminvalue  |  shardmaxvalue
--------------+---------+-----------------+-----------------
 orders       | 102008  | -2147483648     | -1
 orders       | 102009  | 0               | 2147483647
```

Интервалы здесь — диапазоны хеш‑значений (для `integer`), а не “прямые” `user_id`.  
Для демонстрации важно другое: **все заказы одного `user_id` гарантированно оказываются в одном шарде**.

---

## Что знает Symfony‑приложение

### Подключение к БД

В `app/.env`:

```dotenv
DATABASE_URL="postgresql://app:app@db:5432/app?serverVersion=16&charset=utf8"
```

Важно:

- приложение знает только один хост `db` и одну БД `app`;
- никакой специальной логики под Citus в коде **нет**;
- Doctrine ORM работает как с обычным PostgreSQL, а Citus прозрачно маршрутизирует запросы.

### API поверх шардированной таблицы

Для наглядности поверх `orders` есть простой контроллер `App\Controller\OrderController`:

- `POST /orders`
  - создаёт заказ:
    ```json
    { "userId": 1, "amount": 9.99, "status": "new" }
    ```
  - `status` необязателен, по умолчанию `"new"`.

- `GET /orders?userId=...`
  - возвращает последние заказы конкретного пользователя (до 50 штук, сортировка по `id DESC`);
  - с точки зрения Citus это **роутинг‑запрос**: ходит только в один шард, где живёт `user_id`.

- `GET /orders/summary`
  - возвращает сумму заказов по пользователям:
    ```json
    [
      { "userId": 1, "total": "99.90" },
      { "userId": 2, "total": "10.00" }
    ]
    ```
  - это **распределённый запрос** — Citus собирает данные со всех шардов.

Вся работа с БД идёт через Doctrine (`OrderRepository`), никакого SQL с `citus_*` в коде приложения нет.

---

## Команда для демонстрации шардирования

Главный инструмент — консольная команда `app:sharding-demo` (`app/src/Command/ShardingDemoCommand.php`).

Запуск (из корня проекта):

```bash
make up migrate php

php bin/console app:sharding-demo --truncate --users=10 --orders-per-user=5
```

Опции:

- `--truncate` — очистить `orders` перед генерацией.
- `--users` — сколько разных `userId` использовать.
- `--orders-per-user` — сколько заказов создавать на каждого пользователя.

Команда делает несколько шагов:

1. Показывает Citus‑ноды (`pg_dist_node`):

   ```text
   Citus nodes (pg_dist_node)
   --------------------------

   ------------ ------ ---------
    Node         Port   Role
   ------------ ------ ---------
    db-worker1   5432   primary
    db-worker2   5432   primary
   ------------ ------ ---------
   ```

2. Показывает шардирование таблицы `orders` и на каких нодах лежат шарды:

   ```text
   Orders table shards (pg_dist_shard / pg_dist_placement)
   -------------------------------------------------------

   ---------- ------------- ------------ ------------ ------
    Shard ID   Min value     Max value    Node         Port
   ---------- ------------- ------------ ------------ ------
    102008     -2147483648   -1           db-worker1   5432
    102009     0             2147483647   db-worker2   5432
   ---------- ------------- ------------ ------------ ------
   ```

3. Генерирует данные через Doctrine (приложение пишет в координатор, а Citus распределяет записи по воркерам):

   ```text
   Generating demo orders: 10 users × 5 orders
   ------------------------------------------

   [OK] Demo orders generated via Doctrine ORM (coordinator will route writes to shards).
   ```

4. Выполняет агрегирующий запрос через `OrderRepository::getTotalAmountByUser()` — распределённый `SELECT`:

   ```text
   Aggregated totals per user (distributed query)
   ----------------------------------------------

   --------- --------------
    User ID   Total amount
   --------- --------------
    1         224.93
    2         246.24
    3         217.29
    4         224.10
    5         148.86
    6         292.59
    7         223.31
    8         345.22
    9         244.43
    10        189.37
   --------- --------------
   ```

5. Показывает, какой `userId` попал в какой шард (через `get_shard_id_for_distribution_column`), отсортировано по `Shard ID`:

   ```text
   User-to-shard mapping (which shard each userId goes to)
   -------------------------------------------------------

   --------- ----------
    User ID   Shard ID
   --------- ----------
    1         102008
    3         102008
    4         102008
    5         102008
    7         102008
    8         102008
    10        102008
    2         102009
    6         102009
    9         102009
   --------- ----------
   ```

Так хорошо видно:

- есть 2 шарда (`102008`, `102009`);
- пользователи распределены по ним;
- приложение при этом не знает ни про `shardid`, ни про воркеров.

---

## Directory-based шардирование продуктов (изоляция горячих ключей)

В дополнение к Citus‑шардированию `orders` в этом проекте есть **отдельный пример directory-based шардирования** на продуктах.  
Идея: самые “горячие” продукты кладём в отдельное хранилище (`product_hot`), остальные — в `product_cold`.  
Решение о том, куда класть конкретный `product_id`, хранится в таблице‑директории `product_shard_directory`.

### Схема

Миграция `app/migrations/Version20250103000000.php` создаёт три таблицы:

- `product_shard_directory` — директория:
  - `product_id INT PRIMARY KEY`
  - `bucket VARCHAR(10) CHECK (bucket IN ('hot', 'cold'))`
- `product_hot` — “горячее” хранилище:
  - `product_id INT PRIMARY KEY`
  - `name VARCHAR(255) NOT NULL`
  - `price NUMERIC(10, 2) NOT NULL`
  - `updated_at TIMESTAMP NOT NULL`
- `product_cold` — “холодное” хранилище:
  - те же поля, что и у `product_hot`.

Это **directory-based** в том смысле, что:

- сначала мы смотрим в `product_shard_directory`, чтобы понять, к какому бакету (`hot`/`cold`) относится продукт;
- затем пишем/читаем из соответствующей таблицы (`product_hot` или `product_cold`).

### Роутер в приложении

В Symfony есть сервис `App\Service\ProductDirectoryRouter`, который:

- умеет назначать бакет в директории:

  ```php
  $router->setBucket($productId, 'hot'); // или 'cold'
  ```

- умеет по умолчанию определять бакет на основе детерминированного правила и доли горячих ключей (`determineDefaultBucket($productId, $hotRatio)`).
- пишет/читает продукт в/из нужного бакета (`product_hot` или `product_cold`) через `upsertProduct()` и `getProduct()`.

Приложение таким образом **само управляет** тем, какие ключи “горячие”, и может в любой момент передумать, просто обновив запись в директории.

### Команда‑демо

Команда `app:directory-sharding-products` (`app/src/Command/DirectoryShardingProductsCommand.php`) показывает directory-based шардирование на продуктах.

Запуск:

```bash
make php
php bin/console app:directory-sharding-products --truncate --products=20 --hot-ratio=0.3
```

Опции:

- `--truncate` — очистить `product_shard_directory`, `product_hot`, `product_cold` перед запуском.
- `--products` — сколько `product_id` сгенерировать (по умолчанию 50).
- `--hot-ratio` — доля горячих ключей в диапазоне `[0,1]` (по умолчанию 0.2 = 20%).

Что делает команда:

1. Генерирует N продуктов.
2. Для каждого `product_id` выбирает бакет `hot`/`cold` (детерминированно, с заданной долей `hot-ratio`) и записывает это решение в `product_shard_directory`.
3. Через `ProductDirectoryRouter` вставляет/обновляет данные в `product_hot` или `product_cold` (в зависимости от бакета).
4. Показывает:
   - пример содержимого директории;
   - сколько строк в `product_hot` и `product_cold`;
   - маппинг `product_id → bucket`, отсортированный по бакету и идентификатору.

Пример вывода (усечённый):

```text
Directory-based шардирование продуктов (горячие/холодные ключи)
================================================================

Очистка product_shard_directory, product_hot, product_cold
----------------------------------------------------------
[OK] Таблицы очищены.

Генерация 50 продуктов с долей горячих ключей 0.20
--------------------------------------------------
[OK] Данные для продуктов сгенерированы через directory-based роутер.

Пример содержимого product_shard_directory (первые 20 строк)
-----------------------------------------------------------

----------- --------
 Product ID Bucket
----------- --------
 1          hot
 2          cold
 3          cold
 4          hot
 ...        ...

Статистика по бакетам (product_hot / product_cold)
--------------------------------------------------

-------- ----------
 Bucket  Row count
-------- ----------
 hot     10
 cold    40
-------- ----------

Отображение продуктов на бакеты (первые 20 строк, сортировка по bucket, затем product_id)
-----------------------------------------------------------------------------------------

----------- --------
 Product ID Bucket
----------- --------
 1          hot
 4          hot
 7          hot
 ...        ...
 2          cold
 3          cold
 ...        ...
```

---

## Запуск проекта “с нуля”

Требования:

- Docker + Docker Compose.
- `make`.

Шаги:

```bash
git clone <this-repo-url>
cd sharding-example

make up
make migrate
```

Демо шардирования:

```bash
make php
php bin/console app:sharding-demo --truncate --users=10 --orders-per-user=5
```

---


Основной фокус этого репозитория — **простая демонстрация шардирования PostgreSQL через Citus** и работающего поверх этого Symfony‑приложения, которое об этих шардах не знает.  
Дальше можно:

- усложнять схему шардирования;
- добавлять другие шард‑ключи;
- экспериментировать с кросс‑шардовыми транзакциями и запросами;
- развивать directory-based сценарии (перенос горячих ключей в отдельные кластера/БД, динамическое переключение бакетов и т.д.).
