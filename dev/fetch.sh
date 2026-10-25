#!/usr/bin/env bash
# Fetch the third-party code the dev wiki needs that the mediawiki:1.43 image does not ship.
# Everything lands in dev/extensions and dev/skins (gitignored) and only ever runs inside the
# containers. Pinned to exact commits, chosen on 2026-10-10:
#   CheckUser      REL1_43 branch  e4471ddb (Wikimedia Gerrit, or its GitHub mirror)
#   MobileFrontend REL1_43 branch  bbc93d3e (Wikimedia Gerrit, or its GitHub mirror)
#   Citizen        v3.23.0 tag     0fb706df (needs MediaWiki >= 1.43)
#   FlowThread     v1.3.3 tag      89344e09 (2023-01-15), legacy mode only. Its newer master commits
#                  type-hint MediaWiki\Skin\Skin, which MediaWiki 1.43 does not have, so every page
#                  view fails there.
# To update one, change its commit here and delete its folder.
#
# Idempotent: a checkout at the right commit is kept. A checkout at another commit is left alone
# (it may hold local changes) and stops the script: delete it to fetch again.
# Each source is tried three times before the next one. Gerrit sometimes refuses requests from
# CI runners (HTTP 403), and the mirror serves the same commits, which the hash check verifies.
set -euo pipefail
cd "$(dirname "$0")"

# Anonymous fetches of public repositories: ignore personal Git settings such as URL rewrites
# from https to ssh.
export GIT_CONFIG_GLOBAL=/dev/null GIT_TERMINAL_PROMPT=0

# fetch <commit> <dest> <url>...
fetch() {
	local commit=$1 dest=$2
	shift 2
	if [ -d "$dest/.git" ] && [ "$(git -C "$dest" rev-parse HEAD 2>/dev/null)" = "$commit" ]; then
		echo "fetch: have $dest at ${commit:0:8}"
		return
	fi
	if [ -e "$dest" ]; then
		echo "fetch: $dest exists but is not at ${commit:0:8}. Delete it to fetch again." >&2
		exit 1
	fi
	# Into a temporary folder first, so a failed fetch leaves nothing that blocks the next run.
	local tmp="$dest.fetching" url attempt
	for url in "$@"; do
		for attempt in 1 2 3; do
			echo "fetch: $url at ${commit:0:8} into $dest (attempt $attempt)"
			rm -rf "$tmp"
			mkdir -p "$tmp"
			git -C "$tmp" init --quiet
			if git -C "$tmp" fetch --quiet --depth 1 "$url" "$commit" &&
				git -C "$tmp" -c advice.detachedHead=false checkout --quiet FETCH_HEAD &&
				[ "$(git -C "$tmp" rev-parse HEAD)" = "$commit" ]; then
				mv "$tmp" "$dest"
				return
			fi
			[ "$attempt" = 3 ] || sleep $((attempt * 5))
		done
	done
	rm -rf "$tmp"
	echo "fetch: could not fetch ${commit:0:8} into $dest from any source" >&2
	exit 1
}

fetch e4471ddb35c9a64ff9fa097103e3c17a5136d0db extensions/CheckUser \
	https://gerrit.wikimedia.org/r/mediawiki/extensions/CheckUser \
	https://github.com/wikimedia/mediawiki-extensions-CheckUser.git
fetch bbc93d3e00bfa7b96198da308b284735b28a58fb extensions/MobileFrontend \
	https://gerrit.wikimedia.org/r/mediawiki/extensions/MobileFrontend \
	https://github.com/wikimedia/mediawiki-extensions-MobileFrontend.git
fetch 0fb706dfbebea00bb40e6c58659b51eea51a504a skins/Citizen \
	https://github.com/StarCitizenTools/mediawiki-skins-Citizen.git
fetch 89344e0949235e58f75a6dd2c2c5fd6a1729aa4e extensions/FlowThread \
	https://github.com/nbdd0121/MW-FlowThread.git
