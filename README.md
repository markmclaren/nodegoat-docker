# nodegoat on Docker

Run [nodegoat](https://github.com/nodegoat/nodegoat) locally on [1100CC](https://github.com/LAB1100/1100CC) with Docker Compose.

- `db` is MariaDB 10.11. The 1100CC and nodegoat schema is created on first start.
- `web` is Apache with PHP 8.3, the 1100CC tree containing a `nodegoat` site, and GDAL (`ogr2ogr`).

## Setup

```bash
git clone https://github.com/LAB1100/1100CC src/1100CC
git clone https://github.com/nodegoat/nodegoat src/nodegoat

cp .env.example .env        # then change the passwords
docker compose up -d --build
```

That's it. On every start the `web` container runs Setup 1100CC, creates the test accounts below if they don't exist yet, and schedules Jobs.

| Where | Login | Access |
| --- | --- | --- |
| nodegoat: <http://nodegoat.localhost> | `test` / `test` | nodegoat Admin clearance, with the Management and Model pages |
| CMS: <http://cms.nodegoat.localhost> | `admin` / `admin` | CORE Admin (users, jobs, logs) |

Change these in `.env` before the first start. They are only used to *create* the accounts, so changing them later has no effect on existing accounts; change passwords in the CMS instead.

### Hello nodegoat demo

On first start the container also creates a demo project, **Hello nodegoat**. It's a small correspondence network based on the example in the [nodegoat Guides](https://nodegoat.net/guides). The people and letters are fictional; the cities and their coordinates are real.

| Type | Objects | Shows |
| --- | --- | --- |
| **City** | 6 | a **Location** Sub-Object with a point on the map |
| **Person** | 5 | an **Occupation** field, and **Residence** Sub-Objects (a period of time plus a reference to a City) |
| **Letter** | 12 | **Sender** and **Receiver** references to Persons, a **Summary**, and **Sent** and **Received** Sub-Objects (a date plus a reference to a City) |

To see it, log in as `test`, choose **Hello nodegoat** as the project, and open **Data**. On the Letter list:

- the geographic visualisation shows letters travelling between cities over time
- the social visualisation shows who wrote to whom

The **Model** menu shows how the Types are defined.

Setting `SEED_DEMO=0` stops the demo from being created. To recreate it, delete the project and its Types in nodegoat, then run `docker compose exec web 1100cc-run docker_demo`. The demo is built by [docker/demo.php](docker/demo.php) using the same nodegoat classes as the nodegoat API's `/model` and `/data` store calls, so it also serves as an example for loading your own data.

To add more users, go to CMS → **User Management**. A new nodegoat user needs:

- page clearances for the **management** and **model** pages, or those menus stay hidden
- clearance level **Admin** to manage projects and the model

Chrome and Firefox resolve `*.localhost` to 127.0.0.1 without any setup. Other browsers, such as Safari, may need this line in `/etc/hosts`:

```text
127.0.0.1 nodegoat.localhost cms.nodegoat.localhost
```

## Configuration (`.env`)

| Variable | Default | Notes |
| --- | --- | --- |
| `MARIADB_ROOT_PASSWORD`, `DB_CMS_PASSWORD`, `DB_HOME_PASSWORD` | | Database passwords. The database only reads them when it is first created. |
| `HTTP_PORT` | `80` | Host port. Port 80 is the safest choice because 1100CC routes requests by host name. Other ports get an extra `host:port` alias. |
| `APP_STATE` | `production` | Set to `development` to show PHP errors. Run `docker compose up -d web` after changing it. |
| `WITH_GRAPH_ANALYSIS` | `0` | Set to `1` to compile the Network Analysis service (Boost + graph_analysis). The first build is slower. |
| `CMS_ADMIN_USER`, `CMS_ADMIN_PASSWORD` | `admin` / `admin` | CMS test account. Leave the password empty to skip it. |
| `NODEGOAT_USER`, `NODEGOAT_PASSWORD`, `NODEGOAT_EMAIL` | `test` / `test` | nodegoat test account. Leave the password empty to skip it. |
| `JOBS_AUTOSTART` | `1` | Set to `0` to stop the container from scheduling Jobs on start. |
| `SEED_DEMO` | `1` | Set to `0` to skip creating the Hello nodegoat demo project. |

### Network Analysis

After building with `WITH_GRAPH_ANALYSIS=1`, open CMS → Jobs and edit **nodegoat Analysis Service**:

- set the port to `44444`
- set the timing to **Always**

Then save and make sure Jobs is running.

## How it works

The `Dockerfile` has these stages:

- **assemble** follows both SETUP.md files. It creates the `nodegoat` site from the 1100CC `SITE` templates and copies the nodegoat repo over it. It also patches `APP/SETTINGS/nodegoat/settings.php`:
  - sets the database host to `db`
  - enables `setup_core_user`
  - sets OpenStreetMap map tiles
- **db** is MariaDB with [docker/db-init.sh](docker/db-init.sh). The script creates the users, databases and grants from the SETUP.md files and imports the setup SQL. Users are created as `@'%'` instead of `@localhost` because the web container connects over the network.
- **graph** optionally builds `PROGRAMS/RUN/graph_analysis`.
- **web** is `php:8.3-apache`. [docker/web-entrypoint.sh](docker/web-entrypoint.sh) writes the `SAFE/nodegoat/*.pass` files and `APP/alias` from the environment when the container starts. It then runs [docker/docker-setup.php](docker/docker-setup.php) and [docker/demo.php](docker/demo.php) through 1100CC's command-line entry point, using [docker/1100cc-run](docker/1100cc-run). `docker-setup.php`:
  - runs the Setup 1100CC steps, which create nodegoat's SQL functions
  - creates the test accounts
  - schedules Jobs

  All three steps are safe to repeat.

`docker/php.ini` sets `register_argc_argv = Off`. With it on, 1100CC mistakes web requests for command-line Jobs calls and the CMS returns a 500 error.

## Caveats

- **Code changes:** the site is assembled at build time. After changing anything in `src/`, run `docker compose up -d --build web`.
- **Jobs:** the Jobs process runs inside the `web` container. 1100CC starts it again whenever it's scheduled but not running, at the end of any web request. The `web` healthcheck requests the home page every 30 seconds, so Jobs comes back within about 30 seconds of a restart.
- **Database reset:** the database is initialised only when its volume is empty. `docker compose down -v` wipes the database and uploads so you can start fresh.
- **Mail:** no mail server is configured.
- **Errors:** check CMS → `/cms_log/`, `APP/STORAGE/nodegoat/CMS/PRIVATE/log` in the `web` container, or `docker compose logs web`.
