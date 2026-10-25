#!/usr/bin/env bash
# Runs GlowThread's PHP checks in a throwaway MediaWiki container per version (default 1.43,
# the oldest supported release, and 1.46): php -l, extension.json validation, PHPUnit unit and
# integration tests (integration tests on a SQLite wiki installed in the container) and phpcs
# with the MediaWiki coding standard. Prints a summary and removes the containers. Needs Docker
# (Colima). Takes a few minutes per version, mostly installing MediaWiki's dev dependencies.
#
#   scripts/php-test.sh              MediaWiki 1.43 and 1.46 (images mediawiki:1.43, mediawiki:1.46)
#   scripts/php-test.sh 1.45         any other versions, by image tag
#
# Environment:
#   GLOWTHREAD_TEST_EXTENSIONS  extensions loaded with GlowThread (default "Echo CheckUser").
#                               Bundled ones come from the image, the others are cloned from the
#                               REL1_xx branch that matches the version. Set it to "" for none.
#   GLOWTHREAD_SRC              the checkout to test (default: the project root). Its files are
#                               copied into the container, so any local path works.
#   GLOWTHREAD_TEST_IMAGE       image to use instead of mediawiki:<version>, for example a local
#                               build of MediaWiki master (then the version is only a label).
set -euo pipefail

ROOT="$(cd "${GLOWTHREAD_SRC:-$(dirname "$0")/..}" && pwd)"
EXT="GlowThread"
DEPS="${GLOWTHREAD_TEST_EXTENSIONS-Echo CheckUser}"
if [ $# -gt 0 ]; then VERSIONS=("$@"); else VERSIONS=(1.43 1.46); fi
# Composer downloads are cached between runs in this Docker volume (delete it to clear the cache).
CACHE_VOLUME="glowthread-php-test-composer-cache"

if [ ! -f "$ROOT/extension.json" ]; then
	echo "php-test: $ROOT has no extension.json yet, nothing to check."
	exit 0
fi

containers=()
cleanup() {
	for name in ${containers[@]+"${containers[@]}"}; do
		docker rm -f "$name" >/dev/null 2>&1 || true
	done
}
trap cleanup EXIT

# Test files in a directory of the extension, relative to the extension root.
has_tests() {
	[ -d "$ROOT/$1" ] && [ -n "$(find "$ROOT/$1" -name '*Test.php' -print -quit)" ]
}
# PHP to lint and sniff: these folders when they exist, plus PHP files in the root
# (such as GlowThread.alias.php), as paths inside the container.
php_paths() {
	local path
	for path in includes maintenance tests; do
		if [ -d "$ROOT/$path" ]; then printf 'extensions/%s/%s ' "$EXT" "$path"; fi
	done
	for path in "$ROOT"/*.php; do
		if [ -f "$path" ]; then printf 'extensions/%s/%s ' "$EXT" "$(basename "$path")"; fi
	done
}

# PHPUnit runs: tests/phpunit/unit, tests/phpunit/integration, then any other directory or
# file directly in tests/phpunit, each on its own.
phpunit_targets=()
if has_tests tests/phpunit/unit; then phpunit_targets+=(unit); fi
if has_tests tests/phpunit/integration; then phpunit_targets+=(integration); fi
if [ -d "$ROOT/tests/phpunit" ]; then
	for path in "$ROOT"/tests/phpunit/*; do
		name=$(basename "$path")
		case "$name" in unit | integration) continue ;; esac
		if [ -d "$path" ] && has_tests "tests/phpunit/$name"; then
			phpunit_targets+=("$name")
		elif [ -f "$path" ] && [[ "$name" == *Test.php ]]; then
			phpunit_targets+=("$name")
		fi
	done
fi
php_dirs=$(php_paths)

overall=0
summary=()
for version in "${VERSIONS[@]}"; do
	image="${GLOWTHREAD_TEST_IMAGE:-mediawiki:$version}"
	name="glowthread-php-test-${version//./}-$$"
	echo "### MediaWiki $version ($image)"
	containers+=("$name")
	docker run -d --name "$name" --entrypoint sleep \
		-v "$CACHE_VOLUME:/composer-cache" -e COMPOSER_CACHE_DIR=/composer-cache \
		-e COMPOSER_ALLOW_SUPERUSER=1 "$image" infinity >/dev/null

	# Copy the extension in, without the dev stack, private notes, build folders, and hidden
	# files and folders at any depth (.git, .env files, data snapshots).
	docker exec "$name" mkdir -p "/var/www/html/extensions/$EXT"
	# GNU tar (Linux, CI) lets wildcards match '/', so '.?*' would exclude everything under './'.
	tar_glob=()
	if tar --version 2>/dev/null | grep -q 'GNU tar'; then
		tar_glob=( --no-wildcards-match-slash )
	fi
	COPYFILE_DISABLE=1 tar --no-xattrs ${tar_glob[@]+"${tar_glob[@]}"} -C "$ROOT" \
		--exclude ./dev --exclude ./private --exclude node_modules --exclude '.?*' \
		-cf - . | docker exec -i "$name" tar --no-same-owner -C "/var/www/html/extensions/$EXT" -xf -

	# The image ships no Composer or unzip and the release tarball has no PHPUnit configuration.
	if ! docker exec -e DEPS="$DEPS" "$name" sh -ec '
		export DEBIAN_FRONTEND=noninteractive
		apt-get update -qq > /dev/null && apt-get install -y -qq unzip > /dev/null
		cd /var/www/html
		version=$(grep -m1 -o "MW_VERSION'"'"', '"'"'[0-9.]*" includes/Defines.php | grep -o "[0-9][0-9.]*$")
		branch="REL$(echo "$version" | cut -d. -f1)_$(echo "$version" | cut -d. -f2)"
		# Composer is pinned and checked against the sha256 published on getcomposer.org/download
		# (also when it comes from the cache volume). Change the version and the hash together.
		composer_version=2.10.3
		composer_sha256=7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6
		phar="/composer-cache/composer-$composer_version.phar"
		if ! echo "$composer_sha256  $phar" | sha256sum -c --quiet - > /dev/null 2>&1; then
			curl -fsSL --retry 4 --retry-all-errors "https://getcomposer.org/download/$composer_version/composer.phar" -o "$phar.$$"
			mv "$phar.$$" "$phar"
		fi
		echo "$composer_sha256  $phar" | sha256sum -c --quiet -
		install -m 755 "$phar" /usr/local/bin/composer
		# MediaWiki 1.46 replaced phpunit.xml.dist with phpunit.xml.template (and a generator).
		phpunit_config=phpunit.xml.dist
		if [ -f tests/phpunit/generatePHPUnitConfig.php ]; then phpunit_config=phpunit.xml.template; fi
		# A git checkout of core (a master image) has it already, and 1.47.0-alpha has no tag
		if [ ! -f "$phpunit_config" ]; then
			curl -fsSL --retry 4 --retry-all-errors "https://raw.githubusercontent.com/wikimedia/mediawiki/$version/$phpunit_config" -o "$phpunit_config"
		fi
		# The dev dependencies of core, without a lockfile (release tarballs ship none). No scripts.
		# Plugins stay on: only the three that the allow-plugins list of core names can run, and one of
		# them registers the coding standard for phpcs. The container has no host mounts and no
		# secrets. Extensions are cloned from the REL branch of the version, as the CI of MediaWiki
		# does, so they include security backports.
		composer update --no-scripts --no-interaction --no-progress --quiet
		php maintenance/run.php install --dbtype sqlite --dbpath /var/www/data --server http://localhost \
			--scriptpath "" --lang en --pass "glowthread-$(date +%s)-test" GlowThreadTest Admin > /dev/null
		for dep in $DEPS; do
			if [ ! -f "extensions/$dep/extension.json" ]; then
				echo "cloning $dep $branch"
				git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$branch" \
					"https://github.com/wikimedia/mediawiki-extensions-$dep.git" "extensions/$dep"
				# Runtime dependencies only: pinned dev tools of extensions may no longer resolve.
				mkdir -p "/tmp/runtime-$dep"
				php -r '"'"'$m = json_decode( (string)@file_get_contents( $argv[1] . "/composer.json" ), true ) ?: [];
					$req = array_filter( $m["require"] ?? [], fn ( $p ) => !str_starts_with( $p, "php" ) && !str_starts_with( $p, "ext-" ), ARRAY_FILTER_USE_KEY );
					if ( $req ) { file_put_contents( $argv[2] . "/composer.json", json_encode( [ "require" => $req, "config" => [ "vendor-dir" => $argv[1] . "/vendor" ] ] ) ); }'"'"' \
					"$PWD/extensions/$dep" "/tmp/runtime-$dep"
				if [ -f "/tmp/runtime-$dep/composer.json" ]; then
					composer install -d "/tmp/runtime-$dep" --no-scripts --no-plugins --no-interaction --no-progress --quiet
				fi
			fi
			echo "wfLoadExtension( '"'"'$dep'"'"' );" >> LocalSettings.php
		done
		echo "wfLoadExtension( '"'"'GlowThread'"'"' );" >> LocalSettings.php
		# Creates the tables of the loaded extensions, including GlowThread.
		php maintenance/run.php update --quick > /dev/null
	'; then
		echo "-- setup: FAIL (see above)"
		summary+=("MediaWiki $version: setup failed")
		overall=1
		continue
	fi

	status=0
	run() {
		local label="$1"
		shift
		echo "== $label"
		if docker exec -w /var/www/html "$name" "$@"; then
			echo "-- $label: pass"
		else
			echo "-- $label: FAIL"
			status=1
		fi
	}

	if [ -n "$php_dirs" ]; then
		# php -l prints parse errors on stdout: show everything but the per-file success lines.
		run "php -l" sh -c "find $php_dirs -name '*.php' -print0 | xargs -0 -n1 php -l > /tmp/lint.txt 2>&1 || { grep -v '^No syntax errors detected' /tmp/lint.txt; exit 1; }"
	fi
	run "extension.json" php maintenance/run.php validateRegistrationFile "extensions/$EXT/extension.json"
	if [ ${#phpunit_targets[@]} -eq 0 ]; then
		echo "== PHPUnit: skipped, no tests yet (*Test.php under tests/phpunit/unit or tests/phpunit/integration)"
	else
		for target in "${phpunit_targets[@]}"; do
			run "PHPUnit $target" composer phpunit:entrypoint -- "extensions/$EXT/tests/phpunit/$target"
		done
	fi
	if [ -n "$php_dirs" ]; then
		run "phpcs" sh -c "vendor/bin/phpcs -p -s --standard=vendor/mediawiki/mediawiki-codesniffer/MediaWiki --extensions=php $php_dirs"
	fi

	if [ "$status" = 0 ]; then
		summary+=("MediaWiki $version: all PHP checks passed")
	else
		summary+=("MediaWiki $version: some PHP checks failed")
		overall=1
	fi
	docker rm -f "$name" >/dev/null 2>&1 || true
done

echo "### Summary"
printf '%s\n' "${summary[@]}"
exit "$overall"
