#!/usr/bin/env bash
# Bring up the GlowThread dev wiki. Safe to run again at any time: it only does what is missing,
# runs update.php (so schema changes apply), seeds only pages without comments, recreates
# containers whose configuration changed and empties the Varnish cache.
#
#   ./setup.sh                          start or update (GlowThread)
#   ./setup.sh --reset                  delete the wiki (database, data volume) first
#   GLOWTHREAD_DEV_LEGACY=1 ./setup.sh  legacy mode: FlowThread instead of GlowThread
#   GLOWTHREAD_DEV_DB=mysql ./setup.sh  MySQL 8.0 instead of MariaDB, with its own volumes
#
# README.md explains the rest.
set -euo pipefail
cd "$(dirname "$0")"
started=$(date +%s)

export GLOWTHREAD_DEV_PORT=${GLOWTHREAD_DEV_PORT:-8150}
export GLOWTHREAD_DEV_DIRECT_PORT=${GLOWTHREAD_DEV_DIRECT_PORT:-8151}
export GLOWTHREAD_DEV_LEGACY=${GLOWTHREAD_DEV_LEGACY:-0}
export GLOWTHREAD_DEV_LEGACY_COMPAT=${GLOWTHREAD_DEV_LEGACY_COMPAT:-0}
export GLOWTHREAD_DEV_DB=${GLOWTHREAD_DEV_DB:-mariadb}
# Comments generated on the page Sandbox/Load test (seeded only while it has none).
GLOWTHREAD_DEV_LOAD_TEST_COMMENTS=${GLOWTHREAD_DEV_LOAD_TEST_COMMENTS:-400}
# The extension under test: the project root, or another checkout (a path Docker can mount).
export GLOWTHREAD_SRC=${GLOWTHREAD_SRC:-..}
WIKI="http://localhost:$GLOWTHREAD_DEV_PORT"
# Composer, only to install CheckUser's runtime dependency (firebase/php-jwt).
COMPOSER_IMAGE=composer:2.10.3

log() { printf '\033[1msetup:\033[0m %s\n' "$*"; }
die() {
	echo "setup: $*" >&2
	exit 1
}

case "$GLOWTHREAD_DEV_LEGACY$GLOWTHREAD_DEV_LEGACY_COMPAT" in
00 | 01 | 10) ;;
*) die "GLOWTHREAD_DEV_LEGACY and GLOWTHREAD_DEV_LEGACY_COMPAT must be 0 or 1, and not both 1" ;;
esac
case "$GLOWTHREAD_DEV_DB" in
mariadb) export GLOWTHREAD_DEV_DB_IMAGE=${GLOWTHREAD_DEV_DB_IMAGE:-mariadb:10.11.18} ;;
mysql) export GLOWTHREAD_DEV_DB_IMAGE=${GLOWTHREAD_DEV_DB_IMAGE:-mysql:8.0.46} ;;
*) die "GLOWTHREAD_DEV_DB must be mariadb or mysql" ;;
esac
case "$GLOWTHREAD_DEV_LOAD_TEST_COMMENTS" in
'' | *[!0-9]*) die "GLOWTHREAD_DEV_LOAD_TEST_COMMENTS must be a number" ;;
esac
case "${1:-}" in
'' | --reset) ;;
*) die "unknown option $1 (use --reset or nothing)" ;;
esac
docker info >/dev/null 2>&1 || die "Docker is not running. Start it first (for example: colima start)."
[ -f "$GLOWTHREAD_SRC/extension.json" ] || die "no extension.json in $GLOWTHREAD_SRC (GLOWTHREAD_SRC)"

if [ "${1:-}" = "--reset" ]; then
	log "deleting the containers and the $GLOWTHREAD_DEV_DB volumes (database, installer output, logs)"
	docker compose down --volumes --remove-orphans
fi

log "fetching third-party code (pinned)"
./fetch.sh | sed 's/^/  /'

# Accounts: name|groups|flags (maintenance/SeedAccounts.php). The regular members are
# registered long before their seeded comments, so GlowThread's new-account gate
# ($wgGlowThreadNewAccountHours, 24 hours) lets them through. Newbie is registered six hours
# before each run (before its seeded comment), so the gate holds its comments for the next 18
# hours. Trent is in the dev group "trusted" (glowthread-trusted, LocalSettings.dev.php).
accounts=(
	"Admin|sysop,bureaucrat,checkuser|age:1825d"
	"Moderator|sysop|age:1460d"
	"Alice|-|age:1100d"
	"Bob|-|age:1000d"
	"Carol|-|age:900d"
	"Dave|-|age:800d"
	"Erin|-|age:700d"
	"Trent|trusted|age:2200d"
	"Mallory|-|age:640d"
	"Newbie|-|renew:6h"
	"Blocked|-|age:700d,block"
	"PartialBlocked|-|age:700d,partialblock:Banana bread"
	"BlockedMod|sysop|age:1000d,block"
)
# Members who only write on the Chinese demo page (番茄炒蛋, for the screenshots of the Chinese
# documentation). They never log in, so they have no password.
demo_accounts=(
	"老陈|-|age:2000d"
	"麦穗|-|age:1500d"
	"林小满|-|age:1200d"
	"阿芳|-|age:900d"
	"橘子汽水|-|age:800d"
	"豆豆不吃豆|-|age:600d"
	"养生小贴士|-|age:45d"
	"闲置转让|-|age:12d"
)

# Dev-only passwords, never printed: the two database users and one per account.
keys=(GLOWTHREAD_DEV_DB_ROOT_PASSWORD GLOWTHREAD_DEV_DB_PASSWORD)
for account in "${accounts[@]}"; do
	keys+=("GLOWTHREAD_DEV_PASSWORD_$(printf '%s' "${account%%|*}" | tr '[:lower:]' '[:upper:]')")
done
(umask 077 && touch .env.local)
chmod 600 .env.local
new_keys=()
for key in "${keys[@]}"; do
	grep -q "^$key=" .env.local || new_keys+=("$key")
done
if [ ${#new_keys[@]} -gt 0 ]; then
	# The database keeps the passwords it was initialised with. Check before writing anything.
	case " ${new_keys[*]} " in
	*" GLOWTHREAD_DEV_DB_"*)
		if docker volume inspect "glowthread_db_$GLOWTHREAD_DEV_DB" >/dev/null 2>&1; then
			die "dev/.env.local has no database passwords, but the database volume exists with the old ones. Run ./setup.sh --reset to start over."
		fi
		;;
	esac
	for key in "${new_keys[@]}"; do
		printf '%s=%s\n' "$key" "$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)" >> .env.local
	done
	log "generated dev passwords in dev/.env.local: ${new_keys[*]}"
fi
set -a
# shellcheck disable=SC1091
. ./.env.local
set +a

# CheckUser needs firebase/php-jwt, which MediaWiki 1.43 does not ship. Composer runs in a
# throwaway container on CheckUser's "require" list only (its dev tools are not needed) and
# writes extensions/CheckUser/vendor.
if [ ! -f extensions/CheckUser/vendor/autoload.php ]; then
	log "installing CheckUser's Composer dependencies ($COMPOSER_IMAGE)"
	docker run --rm --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
		-v "$PWD/extensions/CheckUser:/work" --entrypoint sh "$COMPOSER_IMAGE" -ec '
			mkdir -p /tmp/runtime
			php -r '\''
				$manifest = json_decode( file_get_contents( "/work/composer.json" ), true );
				file_put_contents( "/tmp/runtime/composer.json", json_encode( [
					"require" => $manifest["require"],
					"config" => [ "vendor-dir" => "/work/vendor", "platform" => [ "php" => "8.1.0" ] ],
				] ) );
			'\''
			composer install -d /tmp/runtime --no-scripts --no-plugins --no-interaction --no-progress --quiet
		'
fi

mode="GlowThread"
[ "$GLOWTHREAD_DEV_LEGACY" = 1 ] && mode="legacy mode: FlowThread"
[ "$GLOWTHREAD_DEV_LEGACY_COMPAT" = 1 ] && mode="GlowThread with \$wgGlowThreadLegacyCompat"
log "starting the containers ($mode, $GLOWTHREAD_DEV_DB_IMAGE)"
docker compose up -d --quiet-pull --remove-orphans mediawiki jobrunner varnish 2>&1 | sed 's/^/  /'

mw() { docker compose exec -T -u www-data mediawiki "$@"; }
for i in $(seq 1 60); do
	if mw true 2>/dev/null; then break; fi
	[ "$i" = 60 ] && die "the mediawiki container did not start (docker compose logs mediawiki)"
	sleep 1
done
# The data volume starts empty and owned by root.
docker compose exec -T mediawiki sh -c 'mkdir -p /var/www/data/logs && chown -R www-data:www-data /var/www/data'

if ! mw test -f /var/www/data/LocalSettings.php; then
	log "installing MediaWiki (English, database glowthread, table prefix gt_)"
	# MW_CONFIG_FILE points at a missing file so the installer runs without settings.
	# The passwords arrive on stdin and reach the installer as files, never as arguments.
	printf '%s\n%s\n' "$GLOWTHREAD_DEV_PASSWORD_ADMIN" "$GLOWTHREAD_DEV_DB_PASSWORD" | mw sh -c '
		umask 077
		read -r admin_pass
		read -r db_pass
		printf "%s" "$admin_pass" > /tmp/glowthread-dev-pass
		printf "%s" "$db_pass" > /tmp/glowthread-dev-dbpass
		MW_CONFIG_FILE=/tmp/no-settings.php php maintenance/run.php install \
			--dbtype=mysql --dbserver=db --dbname=glowthread --dbprefix=gt_ \
			--dbuser=wikiuser --dbpassfile=/tmp/glowthread-dev-dbpass \
			--server="$GLOWTHREAD_DEV_SERVER" --scriptpath="" --lang=en \
			--confpath=/var/www/data --passfile=/tmp/glowthread-dev-pass \
			--skins=Vector,MinervaNeue \
			"GlowThread Dev" Admin
		status=$?
		rm -f /tmp/glowthread-dev-pass /tmp/glowthread-dev-dbpass
		exit $status
	' | sed 's/^/  /' || die "the installer failed. If the database already has tables, run ./setup.sh --reset."
fi

log "updating the database schema (update.php)"
if ! out=$(mw php maintenance/run.php update --quick 2>&1); then
	printf '%s\n' "$out" | sed 's/^/  /'
	die "update.php failed"
fi
printf '%s\n' "$out" | tail -n 2 | sed 's/^/  /'

log "seeding pages, accounts and blocks"
mw php maintenance/run.php /dev-maintenance/SeedPages.php --dir=/dev-seed/content | sed 's/^/  /'
{
	for account in "${accounts[@]}"; do
		IFS='|' read -r name groups flags <<<"$account"
		var="GLOWTHREAD_DEV_PASSWORD_$(printf '%s' "$name" | tr '[:lower:]' '[:upper:]')"
		printf '%s\t%s\t%s\t%s\n' "$name" "${!var}" "$groups" "$flags"
	done
	for account in "${demo_accounts[@]}"; do
		IFS='|' read -r name groups flags <<<"$account"
		printf '%s\t-\t%s\t%s\n' "$name" "$groups" "$flags"
	done
} | mw php maintenance/run.php /dev-maintenance/SeedAccounts.php | sed 's/^/  /'
mw php maintenance/run.php /dev-maintenance/SeedPages.php --dir=/dev-seed/site | sed 's/^/  /'

if [ "$GLOWTHREAD_DEV_LEGACY" = 1 ]; then
	log "seeding FlowThread comments (pages without any)"
	mw php maintenance/run.php /dev-maintenance/SeedFlowThread.php | sed 's/^/  /'
else
	log "seeding GlowThread comments (pages without any)"
	mw php maintenance/run.php /dev-maintenance/SeedComments.php \
		--load-test-comments="$GLOWTHREAD_DEV_LOAD_TEST_COMMENTS" | sed 's/^/  /'
fi

log "running queued jobs"
mw php maintenance/run.php runJobs --quiet >/dev/null

# Cached anonymous HTML may come from the other mode or older code.
log "emptying the Varnish cache"
docker compose exec -T varnish varnishadm ban req.url '~' . >/dev/null

cat <<EOF

Dev wiki is up ($(($(date +%s) - started)) s, $mode).
  Wiki:      $WIKI/wiki/Main_Page   (through Varnish)
  Direct:    http://localhost:$GLOWTHREAD_DEV_DIRECT_PORT/wiki/Main_Page   (Apache, no Varnish)
  Accounts:  $(for a in "${accounts[@]}"; do printf '%s ' "${a%%|*}"; done)
  Passwords: dev/.env.local (GLOWTHREAD_DEV_PASSWORD_<NAME>)
EOF
