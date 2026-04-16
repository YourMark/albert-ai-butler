#!/usr/bin/env bash

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo $TMPDIR | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

download() {
	if [ $(which curl) ]; then
		curl -s "$1" > "$2";
	elif [ $(which wget) ]; then
		wget -nv -O "$2" "$1"
	fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\-(beta|RC)[0-9]+$ ]]; then
	WP_BRANCH=${WP_VERSION%\-*}
	WP_TESTS_TAG="branches/$WP_BRANCH"

elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
		WP_TESTS_TAG="tags/${WP_VERSION%??}"
	else
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	# http serves a hierarchical list of all combos of version, locale, and package type
	download http://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
	grep '[0-9]+\.[0-9]+(\.[0-9]+)?' /tmp/wp-latest.json
	LATEST_VERSION=$(grep -o '"version":"[^"]*' /tmp/wp-latest.json | head -1 | sed 's/"version":"//')
	if [[ -z "$LATEST_VERSION" ]]; then
		echo "Latest WordPress version could not be determined"
		exit 1
	fi
	WP_TESTS_TAG="tags/$LATEST_VERSION"
fi
set -ex

install_wp() {

	if [ -d $WP_CORE_DIR ]; then
		return;
	fi

	mkdir -p $WP_CORE_DIR

	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		mkdir -p $TMPDIR/wordpress-trunk
		rm -rf $TMPDIR/wordpress-trunk/*
		svn export --quiet https://core.svn.wordpress.org/trunk $TMPDIR/wordpress-trunk/wordpress
		mv $TMPDIR/wordpress-trunk/wordpress/* $WP_CORE_DIR
	else
		if [ $WP_VERSION == 'latest' ]; then
			local ARCHIVE_NAME='latest'
		elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+ ]]; then
			# https serves multiple download formats; zip is the only one available for all versions
			local ARCHIVE_NAME="wordpress-$WP_VERSION"
		else
			local ARCHIVE_NAME="wordpress-$WP_VERSION"
		fi
		download https://wordpress.org/${ARCHIVE_NAME}.tar.gz $TMPDIR/wordpress.tar.gz
		tar --strip-components=1 -zxmf $TMPDIR/wordpress.tar.gz -C $WP_CORE_DIR
	fi

	download https://raw.githubusercontent.com/marber/wp-content-config/master/wp-tests-config-sample.php $WP_CORE_DIR/wp-tests-config.php
}

install_test_suite() {
	# portable in-place argument for BSD and GNU sed
	local ioption='-i'
	if [[ $(uname) == 'Darwin' ]]; then
		ioption='-i .bak'
	fi

	# set up testing suite if it doesn't yet exist
	if [ ! -d $WP_TESTS_DIR ]; then
		# set up testing suite
		mkdir -p $WP_TESTS_DIR
		rm -rf $WP_TESTS_DIR/{includes,data}
		svn export --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/ $WP_TESTS_DIR/includes
		svn export --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/ $WP_TESTS_DIR/data
	fi

	if [ ! -f wp-tests-config.php ]; then
		download https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php "$WP_TESTS_DIR"/wp-tests-config.php
		# remove all forward slashes in the end
		WP_CORE_DIR=$(echo $WP_CORE_DIR | sed "s:/\+$::")
		sed $ioption "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
	fi

}

recreate_db() {
	shopt -s nocasematch
	if [[ $1 =~ ^(y|yes)$ ]]
	then
		mysqladmin drop $DB_NAME -f --user="$DB_USER" --password="$DB_PASS"$EXTRA
		create_db
		echo "Recreated the database ($DB_NAME)."
	else
		echo "Leaving the existing database ($DB_NAME) in place."
	fi
	shopt -u nocasematch
}

create_db() {
	mysqladmin create $DB_NAME --user="$DB_USER" --password="$DB_PASS"$EXTRA
}

install_db() {

	if [ ${SKIP_DB_CREATE} = "true" ]; then
		return 0
	fi

	# parse DB_HOST for port or socket references
	local PARTS=(${DB_HOST//\:/ })
	local DB_HOSTNAME=${PARTS[0]};
	local DB_SOCK_OR_PORT=${PARTS[1]};
	local EXTRA=""

	if ! [ -z $DB_HOSTNAME ] ; then
		if [ $(echo $DB_SOCK_OR_PORT | grep -e '^[0-9]\{1,\}$') ]; then
			EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
		elif ! [ -z $DB_SOCK_OR_PORT ] ; then
			EXTRA=" --socket=$DB_SOCK_OR_PORT"
		elif ! [ -z $DB_HOSTNAME ] ; then
			EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
		fi
	fi

	# create database
	if [ $(mysql --user="$DB_USER" --password="$DB_PASS"$EXTRA --execute='show databases;' | grep ^$DB_NAME$) ]
	then
		echo "Reinstalling will delete the existing test database ($DB_NAME)"
		read -p 'Are you sure you want to proceed? [y/N]: ' DELETE_EXISTING_DB
		recreate_db $DELETE_EXISTING_DB
	else
		create_db
	fi
}

resolve_wc_version() {
	# Resolve a WC_VERSION shorthand to a concrete MAJOR.MINOR.PATCH the
	# wordpress.org plugin CDN serves. Accepts:
	#   - 'latest' or empty   → printed as-is (the caller uses the simple URL)
	#   - 'MAJOR.MINOR.PATCH' → printed as-is (already concrete)
	#   - 'MAJOR.MINOR'       → resolved to the highest stable patch in that series
	# Stable patches are versions matching ^X.Y.Z$ (no -beta, -rc, etc.).
	local input=$1

	if [ "$input" = 'latest' ] || [ -z "$input" ]; then
		echo "$input"
		return
	fi

	if [[ "$input" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
		echo "$input"
		return
	fi

	if [[ "$input" =~ ^[0-9]+\.[0-9]+$ ]]; then
		download 'https://api.wordpress.org/plugins/info/1.0/woocommerce.json' "$TMPDIR/wc-info.json"
		# Pull every "X.Y.Z" key from the versions map, filter to this MAJOR.MINOR,
		# and pick the highest by version sort. grep -oE keeps just the version strings.
		local resolved
		resolved=$(grep -oE "\"${input}\.[0-9]+\"" "$TMPDIR/wc-info.json" \
			| tr -d '"' \
			| sort -V \
			| tail -1)

		if [ -z "$resolved" ]; then
			echo "Could not resolve WC_VERSION '$input' to a concrete release." >&2
			exit 1
		fi

		echo "$resolved"
		return
	fi

	echo "Unrecognised WC_VERSION format: '$input'" >&2
	exit 1
}

install_woocommerce() {
	# Skip when no WC_VERSION was provided.
	if [ -z "${WC_VERSION:-}" ]; then
		return 0
	fi

	local PLUGINS_DIR=$WP_CORE_DIR/wp-content/plugins
	local WC_DIR=$PLUGINS_DIR/woocommerce

	# Idempotent: reuse an existing install if it's already in place.
	if [ -d $WC_DIR ]; then
		echo "WooCommerce already installed at $WC_DIR — reusing."
		return 0
	fi

	mkdir -p $PLUGINS_DIR

	local RESOLVED_VERSION
	RESOLVED_VERSION=$(resolve_wc_version "$WC_VERSION")

	local ARCHIVE_URL
	if [ "$RESOLVED_VERSION" = 'latest' ]; then
		ARCHIVE_URL='https://downloads.wordpress.org/plugin/woocommerce.zip'
	else
		ARCHIVE_URL="https://downloads.wordpress.org/plugin/woocommerce.${RESOLVED_VERSION}.zip"
	fi

	echo "Installing WooCommerce $RESOLVED_VERSION from $ARCHIVE_URL"
	download "$ARCHIVE_URL" "$TMPDIR/woocommerce.zip"
	unzip -q -o "$TMPDIR/woocommerce.zip" -d "$PLUGINS_DIR"

	if [ ! -f "$WC_DIR/woocommerce.php" ]; then
		echo "WooCommerce install failed: $WC_DIR/woocommerce.php is missing."
		exit 1
	fi
}

install_wp
install_test_suite
install_db
install_woocommerce
