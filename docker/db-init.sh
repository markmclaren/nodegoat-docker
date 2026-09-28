#!/bin/bash
# Initialise the 1100CC CORE and nodegoat databases (1100CC & nodegoat SETUP.md).
# Runs once, when the MariaDB data volume is empty.
set -euo pipefail

: "${DB_CMS_PASSWORD:?DB_CMS_PASSWORD must be set}"
: "${DB_HOME_PASSWORD:?DB_HOME_PASSWORD must be set}"

SQL=/setup-sql

db() {
	mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$@"
}

esc() {
	printf '%s' "$1" | sed "s/'/''/g"
}

echo "1100CC: creating users, databases and grants"

db <<SQL_EOF
CREATE USER '1100CC_cms'@'%' IDENTIFIED BY '$(esc "$DB_CMS_PASSWORD")';
CREATE USER '1100CC_home'@'%' IDENTIFIED BY '$(esc "$DB_HOME_PASSWORD")';

CREATE DATABASE \`1100CC\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE nodegoat_cms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE nodegoat_home CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE nodegoat_content CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE nodegoat_temp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT SELECT, INSERT, UPDATE, DELETE ON \`1100CC\`.* TO '1100CC_cms'@'%';
GRANT SELECT ON \`1100CC\`.* TO '1100CC_home'@'%';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES, SHOW VIEW, EXECUTE ON nodegoat_home.* TO '1100CC_cms'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON nodegoat_home.* TO '1100CC_home'@'%';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES, SHOW VIEW, EXECUTE ON nodegoat_cms.* TO '1100CC_cms'@'%';
GRANT SELECT ON nodegoat_cms.* TO '1100CC_home'@'%';

GRANT SELECT, INSERT, UPDATE, DELETE, DROP, INDEX, ALTER ON nodegoat_content.* TO '1100CC_cms'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON nodegoat_content.* TO '1100CC_home'@'%';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, CREATE TEMPORARY TABLES, EXECUTE, CREATE ROUTINE, ALTER ROUTINE ON nodegoat_temp.* TO '1100CC_cms'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, CREATE TEMPORARY TABLES, EXECUTE ON nodegoat_temp.* TO '1100CC_home'@'%';

FLUSH PRIVILEGES;
SQL_EOF

import() {
	local database="$1" file="$2"
	echo "1100CC: importing $file into $database"
	db "$database" < "$SQL/$file"
}

import 1100CC 1100CC.sql
import 1100CC 1100CC.core_language.sql
import 1100CC 1100CC.core_labels.en.sql

import nodegoat_cms SITE_cms.sql
import nodegoat_cms SITE_cms.default.sql
import nodegoat_cms nodegoat_cms.cms_labels.sql
import nodegoat_cms nodegoat_cms.various.sql

import nodegoat_home SITE_home.sql
import nodegoat_home nodegoat_home.sql
import nodegoat_home nodegoat_home.changes.sql
import nodegoat_home nodegoat_home.various.sql

import nodegoat_content nodegoat_content.sql

echo "1100CC: database initialisation done"
