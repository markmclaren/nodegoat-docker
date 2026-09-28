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

Then:

1. Open <http://cms.nodegoat.localhost> and log in with a username and password of your choice. When no CMS user exists yet, the first login creates the **CORE Admin** with those credentials.
2. Go to **Admin** and run **Setup 1100CC** once.
3. Go to **User Management** and add a user under 'User' with the page clearances you need.
4. Go to **Jobs** and start the Jobs process.
5. Log in with that user at <http://nodegoat.localhost>.

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
- **web** is `php:8.3-apache`. [docker/web-entrypoint.sh](docker/web-entrypoint.sh) writes the `SAFE/nodegoat/*.pass` files and `APP/alias` from the environment when the container starts.

`docker/php.ini` sets `register_argc_argv = Off`. With it on, 1100CC mistakes web requests for command-line Jobs calls and the CMS returns a 500 error.

## Caveats

- **Code changes:** the site is assembled at build time. After changing anything in `src/`, run `docker compose up -d --build web`.
- **Jobs:** the Jobs process runs inside the `web` container and stops when the container restarts. Start it again from CMS → Jobs.
- **Database reset:** the database is initialised only when its volume is empty. `docker compose down -v` wipes the database and uploads so you can start fresh.
- **Mail:** no mail server is configured.
- **Errors:** check CMS → `/cms_log/`, `APP/STORAGE/nodegoat/CMS/PRIVATE/log` in the `web` container, or `docker compose logs web`.
