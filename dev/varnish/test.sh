#!/usr/bin/env bash
# Tests the cache policy in default.vcl (MediaWiki's rules and the GlowThread carve-out) with
# varnishtest against a fake backend, in a throwaway container of the stack's Varnish image.
# Needs Docker, not the running wiki. Run it after every change to default.vcl.
set -euo pipefail
cd "$(dirname "$0")"

vcl=$(sed -e 's/\.host = "mediawiki";/.host = "${s1_addr}";/' -e 's/\.port = "80";/.port = "${s1_port}";/' default.vcl)
if ! grep -q 's1_addr' <<<"$vcl"; then
	echo "test: could not find the mediawiki backend in default.vcl" >&2
	exit 1
fi

image=$(docker compose -f ../compose.yaml config --images | grep '^varnish:')

# Run the varnishtest file $1 with the VCL in place of its @VCL@ line. The other arguments go to
# docker run (environment variables for varnishd).
run() {
	local file=$1
	shift
	{ sed '/^@VCL@$/,$d' "$file"; printf '%s\n' "$vcl"; sed '1,/^@VCL@$/d' "$file"; } |
		docker run --rm -i ${1+"$@"} --entrypoint sh "$image" -c '
			test_file=$(mktemp)
			cat > "$test_file"
			status=0
			out=$(varnishtest "$test_file" 2>&1) || status=$?
			# On failure, show the failed expectation.
			[ "$status" = 0 ] || printf "%s\n" "$out" | grep -E "^(---- |#.*FAILED)"
			exit "$status"
		'
}

# The whole policy, with the canonical Host of the default dev stack.
run policy.vtc -e GLOWTHREAD_CANONICAL_HOST=localhost:8150

# Without GLOWTHREAD_CANONICAL_HOST, no comment list is cached.
no_host=$(mktemp)
trap 'rm -f "$no_host"' EXIT
cat > "$no_host" <<'EOF'
varnishtest "Without GLOWTHREAD_CANONICAL_HOST no comment list is cached"
server s1 {
	rxreq
	txresp -hdr "Cache-Control: public, s-maxage=60" -body "first"
	rxreq
	txresp -hdr "Cache-Control: public, s-maxage=60" -body "second"
} -start
varnish v1 -vcl {
@VCL@
} -start
client c1 {
	txreq -url "/rest.php/glowthread/v1/page/1/comments?sort=hot" -hdr "Host: localhost:8150"
	rxresp
	expect resp.http.X-Cache-Detail == "PASS"
	txreq -url "/rest.php/glowthread/v1/page/1/comments?sort=hot" -hdr "Host: localhost:8150"
	rxresp
	expect resp.body == "second"
	expect resp.http.X-Cache-Detail == "PASS"
} -run
EOF
run "$no_host"

echo "varnish policy: all cases passed"
