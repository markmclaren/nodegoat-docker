# syntax=docker/dockerfile:1

# nodegoat on 1100CC
# Build context expects local checkouts in ./src/1100CC and ./src/nodegoat.
# Targets: db (MariaDB with setup SQL), web (Apache + PHP + assembled 1100CC/nodegoat).

ARG PHP_VERSION=8.3
ARG MARIADB_VERSION=10.11

############################################
# assemble: build the /var/1100CC tree with a 'nodegoat' SITE (see both SETUP.md files)
############################################
FROM debian:bookworm-slim AS assemble

COPY src/1100CC /var/1100CC
COPY src/nodegoat /tmp/nodegoat

WORKDIR /var/1100CC

RUN set -eux; \
	# 1100CC: create the nodegoat SITE from the SITE templates
	cp -a APP/SITE APP/nodegoat; \
	cp -a APP/SETTINGS/SITE APP/SETTINGS/nodegoat; \
	cp -a APP/STORAGE/SITE APP/STORAGE/nodegoat; \
	cp -a SAFE/SITE SAFE/nodegoat; \
	# nodegoat: overlay the repository onto the new SITE
	cp -a /tmp/nodegoat/APP/nodegoat/. APP/nodegoat/; \
	cp -a /tmp/nodegoat/APP/SETTINGS/nodegoat/. APP/SETTINGS/nodegoat/; \
	mkdir -p APP/STORAGE/nodegoat/CMS/css; \
	cp /tmp/nodegoat/APP/STORAGE/nodegoat/CMS/css/templates.css APP/STORAGE/nodegoat/CMS/css/templates.css; \
	cp -a /tmp/nodegoat/PROGRAMS/graph_analysis PROGRAMS/graph_analysis; \
	# Database engine
	printf 'mariadb' > APP/SETTINGS/nodegoat/database; \
	# Settings: DB host is the 'db' service, allow first CMS login to create the CORE admin, OSM map tiles
	sed -i \
		-e "s/'localhost'/'db'/g" \
		-e "s#'YOUR_URL'#'//tile.openstreetmap.org/{z}/{x}/{y}.png'#" \
		-e "s#'YOUR_ATTRIBUTION'#'Map data \&copy; OpenStreetMap contributors'#" \
		APP/SETTINGS/nodegoat/settings.php; \
	printf "\r\n\tSettings::set('setup_core_user', true);\r\n" >> APP/SETTINGS/nodegoat/settings.php; \
	grep -q "'db'" APP/SETTINGS/nodegoat/settings.php; \
	# Setup SQL for the db image
	mkdir -p /setup-sql; \
	cp setup/*.sql /tmp/nodegoat/setup/*.sql /setup-sql/

############################################
# db: MariaDB, initialised on first start with the 1100CC + nodegoat schema
############################################
FROM mariadb:${MARIADB_VERSION} AS db

COPY --from=assemble /setup-sql /setup-sql
COPY docker/db-init.sh /docker-entrypoint-initdb.d/10-init.sh

############################################
# graph: optionally compile the nodegoat Network Analysis service (graph_analysis)
############################################
FROM debian:bookworm AS graph

ARG WITH_GRAPH_ANALYSIS=0
ARG BOOST_VERSION=1.86.0

COPY --from=assemble /var/1100CC/PROGRAMS /var/1100CC/PROGRAMS

WORKDIR /var/1100CC/PROGRAMS

RUN set -eux; \
	mkdir -p RUN LIBRARIES/boost/lib; \
	if [ "$WITH_GRAPH_ANALYSIS" != "1" ]; then exit 0; fi; \
	apt-get update; \
	apt-get install -y --no-install-recommends build-essential cmake curl ca-certificates git; \
	# Boost (thread component; BGL is header-only)
	BOOST_US=$(echo "$BOOST_VERSION" | tr . _); \
	curl -fsSL "https://archives.boost.io/release/${BOOST_VERSION}/source/boost_${BOOST_US}.tar.gz" | tar -xz -C /tmp; \
	cd "/tmp/boost_${BOOST_US}"; \
	./bootstrap.sh --prefix=/var/1100CC/PROGRAMS/LIBRARIES/boost; \
	./b2 install --with-thread --build-dir=/tmp/build-boost -j"$(nproc)"; \
	cd /var/1100CC/PROGRAMS; \
	# RapidJSON (headers only)
	git clone --depth 1 https://github.com/Tencent/rapidjson.git LIBRARIES/rapidjson; \
	# Build graph_analysis (what creation_station.sh does, non-interactively)
	mkdir -p /tmp/build/graph_analysis; \
	cd /tmp/build/graph_analysis; \
	cmake /var/1100CC/PROGRAMS/graph_analysis; \
	make -j"$(nproc)"; \
	make install; \
	test -x /var/1100CC/PROGRAMS/RUN/graph_analysis

############################################
# web: Apache + PHP running 1100CC/nodegoat
############################################
FROM php:${PHP_VERSION}-apache AS web

RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends \
		gdal-bin procps netcat-openbsd \
		libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
		libzip-dev libicu-dev libyaml-dev libpq-dev; \
	docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
	docker-php-ext-install -j"$(nproc)" gd mysqli pgsql zip intl bcmath opcache; \
	pecl install yaml; \
	docker-php-ext-enable yaml; \
	rm -rf /var/lib/apt/lists/* /tmp/pear

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-1100cc.ini
COPY docker/apache-1100cc.conf /etc/apache2/sites-available/1100cc.conf

RUN set -eux; \
	a2enmod rewrite headers deflate; \
	a2dissite 000-default; \
	a2ensite 1100cc

COPY --from=assemble /var/1100CC /var/1100CC
COPY --from=graph /var/1100CC/PROGRAMS/RUN /var/1100CC/PROGRAMS/RUN
COPY --from=graph /var/1100CC/PROGRAMS/LIBRARIES/boost/lib /var/1100CC/PROGRAMS/LIBRARIES/boost/lib

# Permissions (replaces permissions.sh): code read-only for www-data, data writable, SAFE read-only
RUN set -eux; \
	cd /var/1100CC; \
	chown -R root:www-data .; \
	find . -type d -exec chmod 2750 {} +; \
	find . -type f -exec chmod 0640 {} +; \
	find PROGRAMS/RUN -type f -exec chmod 0750 {} +; \
	chown -R www-data:www-data APP/STORAGE APP/CACHE; \
	find APP/STORAGE APP/CACHE -type d -exec chmod 2770 {} +; \
	find APP/STORAGE APP/CACHE -type f -exec chmod 0660 {} +; \
	chmod -R u=rX,g=rX,o= SAFE

COPY --chmod=0755 docker/web-entrypoint.sh /usr/local/bin/web-entrypoint.sh
COPY docker/cli.php docker/docker-setup.php docker/demo.php /usr/local/lib/1100cc/
COPY docker/1100cc-run /usr/local/bin/1100cc-run

ENV APP_STATE=production

ENTRYPOINT ["web-entrypoint.sh"]
CMD ["apache2-foreground"]
