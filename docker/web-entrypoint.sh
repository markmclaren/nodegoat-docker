#!/bin/bash
set -euo pipefail

ROOT=/var/1100CC
SITE=nodegoat
HOST="${SITE_HOST:-nodegoat.localhost}"
PORT="${HTTP_PORT:-80}"

: "${DB_CMS_PASSWORD:?DB_CMS_PASSWORD must be set}"
: "${DB_HOME_PASSWORD:?DB_HOME_PASSWORD must be set}"

# Database passwords (no trailing newline)
chmod u+w "$ROOT/SAFE/$SITE"
printf '%s' "$DB_CMS_PASSWORD" > "$ROOT/SAFE/$SITE/database_cms.pass"
printf '%s' "$DB_HOME_PASSWORD" > "$ROOT/SAFE/$SITE/database_home.pass"
chown root:www-data "$ROOT/SAFE/$SITE"/*.pass
chmod 0440 "$ROOT/SAFE/$SITE"/*.pass
chmod u-w "$ROOT/SAFE/$SITE"

# Host alias; HTTP_HOST includes the port when not 80
{
	echo "# HOST_NAME : SITE_NAME"
	echo "$HOST $SITE"
	if [ "$PORT" != "80" ]; then
		echo "$HOST:$PORT $SITE"
	fi
} > "$ROOT/APP/alias"
chown root:www-data "$ROOT/APP/alias"
chmod 0640 "$ROOT/APP/alias"

# Named volumes may be created root-owned
chown -R www-data:www-data "$ROOT/APP/STORAGE" "$ROOT/APP/CACHE"

# Wait for the database
for i in $(seq 1 60); do
	if nc -z "${DB_HOST:-db}" 3306; then
		break
	fi
	echo "Waiting for database..."
	sleep 2
done

exec "$@"
