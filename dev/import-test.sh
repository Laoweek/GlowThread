#!/usr/bin/env bash
# Import test: move the dev wiki's FlowThread comments into GlowThread with
# GlowThread:importFlowThread, the way a wiki coming from FlowThread would.
#
#   ./import-test.sh          run the test, then return to the normal GlowThread mode
#   ./import-test.sh --keep   stay in GlowThread mode with $wgGlowThreadLegacyCompat on
#
# Steps:
#   1. Legacy mode (GLOWTHREAD_DEV_LEGACY=1 ./setup.sh): FlowThread is loaded instead of
#      GlowThread, and seed/content/flowthread.json is posted through FlowThread's own API on
#      pages that have no FlowThread comments yet.
#   2. Back to GlowThread with $wgGlowThreadLegacyCompat on (GLOWTHREAD_DEV_LEGACY_COMPAT=1).
#   3. importFlowThread --dry-run, then the real run, then --verify, which exits 1 on any
#      mismatch between FlowThread's tables and GlowThread's.
#   4. Checks that every FlowThread comment has a GlowThread copy and that the read-only
#      action=flowthread route of the compatibility layer answers.
# Safe to run again: the importer keys on FlowThread's IDs and only adds what is missing.
set -euo pipefail
cd "$(dirname "$0")"
started=$(date +%s)

case "${1:-}" in '' | --keep) ;; *) echo "usage: ./import-test.sh [--keep]" >&2; exit 1 ;; esac
export GLOWTHREAD_DEV_PORT=${GLOWTHREAD_DEV_PORT:-8150}
WIKI="http://localhost:$GLOWTHREAD_DEV_PORT"
# Table prefix of the dev wiki (setup.sh installs with --dbprefix=gt_). The sql maintenance
# script does not add it.
P=gt_

log() { printf '\033[1mimport-test:\033[0m %s\n' "$*"; }
fail() {
	echo "import-test: FAILED: $*" >&2
	exit 1
}
mw() { docker compose exec -T -u www-data mediawiki "$@"; }
sql() { mw php maintenance/run.php sql --json --query "$1"; }
# Whatever happens, end in the normal GlowThread mode unless --keep was given.
restore() {
	status=$?
	if [ "${keep:-}" != 1 ]; then
		log "back to the normal GlowThread mode"
		./setup.sh | sed 's/^/  /' || status=1
	fi
	[ "$status" = 0 ] && log "passed in $(($(date +%s) - started)) s"
	exit "$status"
}
[ "${1:-}" = --keep ] && keep=1
trap restore EXIT

log "1/4 legacy mode: FlowThread, seeded from flowthread.json"
GLOWTHREAD_DEV_LEGACY=1 GLOWTHREAD_DEV_LEGACY_COMPAT=0 ./setup.sh | sed 's/^/  /'

log "2/4 GlowThread with \$wgGlowThreadLegacyCompat"
GLOWTHREAD_DEV_LEGACY=0 GLOWTHREAD_DEV_LEGACY_COMPAT=1 ./setup.sh | sed 's/^/  /'

log "3/4 importFlowThread: --dry-run, import, --verify"
mw php maintenance/run.php GlowThread:importFlowThread --dry-run | sed 's/^/  /'
mw php maintenance/run.php GlowThread:importFlowThread | sed 's/^/  /'
mw php maintenance/run.php GlowThread:importFlowThread --verify | sed 's/^/  /' ||
	fail "--verify found mismatches (see above)"

log "4/4 checks"
flowthread=$(sql "SELECT COUNT(*) AS n FROM ${P}FlowThread" | jq -r '.[0].n')
imported=$(sql "SELECT COUNT(*) AS n FROM ${P}glowthread_post WHERE gtp_legacy_id IS NOT NULL" | jq -r '.[0].n')
echo "  FlowThread comments: $flowthread, imported into GlowThread: $imported"
[ "$flowthread" -gt 0 ] || fail "FlowThread has no comments"
[ "$flowthread" = "$imported" ] || fail "not every FlowThread comment was imported"
page=$(sql "SELECT flowthread_pageid AS id FROM ${P}FlowThread LIMIT 1" | jq -r '.[0].id')
compat=$(curl --noproxy '*' -fsS "$WIKI/api.php?action=flowthread&type=list&pageid=$page&format=json" |
	jq -r '.flowthread.count // empty')
[ -n "$compat" ] || fail "the action=flowthread compatibility route did not answer for page $page"
echo "  action=flowthread&type=list for page $page: $compat comments"

