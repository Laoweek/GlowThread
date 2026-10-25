vcl 4.0;
# Varnish cache policy of the GlowThread dev wiki. It doubles as an example for wikis that run
# GlowThread behind Varnish, so every rule says why it is there.
#
# The base is MediaWiki's documented setup (https://www.mediawiki.org/wiki/Manual:Varnish_caching):
# anonymous page views are cached, requests from logged-in users and everything except GET and
# HEAD go straight to MediaWiki, the Action and REST APIs are not cached, and MediaWiki sends a
# PURGE request when a page changes ($wgUseCdn = true, $wgCdnServers lists this Varnish).
# Two rules differ from the manual on purpose:
#   - PURGE bans the exact Host and URL instead of using return (purge), so that the desktop and
#     the mobile copy of a page are cleared together (see vcl_hash).
#   - Phones get their own cached copy, for MobileFrontend.
# Varnish 4 and later add the client address to X-Forwarded-For by themselves, so the manual's
# X-Forwarded-For line is left out. MediaWiki trusts it from the addresses in
# $wgCdnServers and $wgCdnServersNoPurge. [dev] A client-sent X-Forwarded-For is kept in front
# of that address, so tests can pose as other clients (dev/README.md). In production, a Varnish
# that readers reach directly must replace it (see the top of vcl_recv).
#
# GlowThread adds one carve-out, marked [GlowThread] (parts 1 to 4): anonymous reads of a page's
# comment list (/rest.php/glowthread/v1/page/{id}/comments) are cached under one canonical URL
# per page and sort order, which GlowThread purges whenever the list changes. Everything else
# under /rest.php stays uncached. Rules marked [dev] only make sense in the dev stack.
#
# Tested by dev/varnish/test.sh (varnishtest with policy.vtc) on the stack's Varnish 9.0 image.
import std;

backend default {
	.host = "mediawiki";
	.port = "80";
	.connect_timeout = 5s;
	.first_byte_timeout = 60s;
	.between_bytes_timeout = 10s;
}

# Who may send PURGE (and the [dev] BAN).
# [dev] Docker's default networks: the mediawiki and jobrunner containers, and the host through
# the published port (it arrives from the network's gateway), so tests can purge by hand.
# Container names would pin their addresses when the VCL compiles. A Docker engine set up with
# other address pools needs its range here.
# In production, list only the addresses of the MediaWiki servers and job runners, for example
#     "10.0.0.5";
# Never a whole private range and never "localhost": a load balancer, TLS proxy or ingress in
# front of Varnish connects from such an address for every reader, and every reader could then
# empty the cache.
acl purge {
	"172.16.0.0"/12;
	"192.168.0.0"/16;
}

sub vcl_recv {
	# In production, when readers connect to this Varnish directly, replace a client-sent
	# X-Forwarded-For so that readers cannot choose the address MediaWiki sees:
	#     set req.http.X-Forwarded-For = client.ip;
	# Host names are not case-sensitive: one spelling for cache keys and purges.
	if (req.http.host) {
		set req.http.host = std.tolower(req.http.host);
	}
	# [dev] Only Varnish sets this (see vcl_pass).
	unset req.http.x-dev-pass;
	# [GlowThread] Only Varnish sets this (see part 1).
	unset req.http.x-glowthread-url;

	if (req.method == "PURGE" || req.method == "BAN") {
		if (!client.ip ~ purge) {
			return (synth(405, "Not allowed."));
		}
		# The Host and URL become part of a ban expression: refuse anything that could change
		# its meaning (spaces, quotes, backslashes, operators in the Host).
		if (!req.http.host || req.http.host !~ "^[A-Za-z0-9.-]+(:[0-9]+)?$" ||
		    req.url ~ {"[[:space:]"\\]"}) {
			return (synth(400, "Invalid purge target."));
		}
		if (req.method == "PURGE") {
			# Ban the exact Host and URL. Unlike return (purge), this clears every cached
			# variant of the URL (desktop and mobile). The ban tests headers stored on the
			# object (see vcl_backend_response), so Varnish's ban lurker can apply it in the
			# background and drop it from the ban list.
			if (!std.ban("obj.http.x-ban-host == " + req.http.host + " && obj.http.x-ban-url == " + req.url)) {
				return (synth(500, "Purge failed."));
			}
			return (synth(200, "Purged."));
		}
		# [dev] [GlowThread] carve-out, part 2 of 4: BAN <path> clears every cached URL of the
		# comment read route that starts with <path> followed by "/", "?" or the end, so one
		# request clears both sort orders of a page. Only allowed under the route. GlowThread
		# itself only sends PURGE, so production needs none of this.
		if (req.url !~ "^/rest\.php/glowthread/v1/[A-Za-z0-9/_.%-]*$" || req.url ~ "/\.|%2[eE]") {
			return (synth(405, "BAN is only allowed for /rest.php/glowthread/v1/ paths."));
		}
		if (!std.ban("obj.http.x-ban-host == " + req.http.host + " && obj.http.x-ban-url ~ ^" +
		    regsuball(req.url, "[.]", "[.]") + "([/?]|$)")) {
			return (synth(500, "Ban failed."));
		}
		return (synth(200, "Banned."));
	}

	# Mobile flag for MobileFrontend: it serves the mobile view when the X-Subdomain header is
	# present ($wgMFMobileHeader), and vcl_hash keeps a separate copy for it. The pattern must
	# match every user agent that MobileFrontend itself would treat as mobile, or mobile HTML
	# ends up in the desktop copy. Clients cannot set the header themselves.
	unset req.http.x-subdomain;
	if (req.http.User-Agent ~ "(?i)(mobi|android|iphone|ipad|ipod|blackberry|opera mini|kindle|silk)") {
		set req.http.x-subdomain = "m";
	}

	# Only GET and HEAD can be cached. POST and everything else go to MediaWiki.
	if (req.method != "GET" && req.method != "HEAD") {
		return (pass);
	}

	# [GlowThread] carve-out, part 1 of 4: anonymous GET and HEAD of the comment list are looked
	# up in the cache under one canonical URL per page and sort order. GlowThread purges exactly
	# /rest.php/glowthread/v1/page/{id}/comments?sort=hot and ?sort=new on the Host of $wgServer,
	# so only those URLs on that Host may be cached. Only responses with an explicit s-maxage are
	# stored (part 3). Everything else under the route is passed.
	if (req.url ~ "^/rest\.php/glowthread/v1/") {
		# The list route only: a page ID in plain decimal without leading zeros, then the end
		# or a query string. Other routes (comment, thread, replies, floor, moderation,
		# settings) and other spellings of the list path (leading zeros, a trailing slash, dot
		# segments, percent-encoding) are passed. MediaWiki answers them private, and a dot
		# segment ("/." or "%2e") could make the web server run another script, such as api.php.
		if (req.url !~ "^/rest\.php/glowthread/v1/page/[1-9][0-9]{0,9}/comments(\?|$)") {
			return (pass);
		}
		# URLs over 2000 characters are passed. The sort regex below backtracks over the whole
		# URL and fails with a 503 beyond Varnish's pcre2_match_limit (about 10000 characters).
		# The list URLs that clients use are under 100 characters.
		if (req.url ~ "^.{2001}") {
			return (pass);
		}
		# CANONICAL HOST: the host of $wgServer, which is also the Host of GlowThread's purges.
		# Any other Host (127.0.0.1:8150, another name for the same site) would get its own
		# cached copy that purges never reach, so it is passed. In your own VCL compare it with
		# your host in lower case, as a string:
		#     req.http.host != "wiki.example.org"
		# [dev] The dev stack reads it from the environment (GLOWTHREAD_CANONICAL_HOST in
		# compose.yaml), because the port can change. Without it, nothing is cached here.
		if (req.http.host != std.getenv("GLOWTHREAD_CANONICAL_HOST")) {
			return (pass);
		}
		# Requests with Authorization or a session or Token cookie are passed with the URL as
		# sent, exactly like page views.
		if (req.http.Authorization || req.http.Cookie ~ "([sS]ession|Token)=") {
			return (pass);
		}
		# A cursor asks for a later page, which is never cached. Only a parameter named exactly
		# "cursor" counts, and only with a value: an empty one names no later page (MediaWiki
		# refuses it), so "cursor=" or a bare "cursor" is dropped below like junk. When cursor
		# appears more than once, the last one decides, as for sort. Other spellings
		# (%63ursor, cursor[]) are dropped below like any unknown parameter, so such a request
		# gets the first page. Neither pattern repeats a group: on a long URL that would exhaust
		# PCRE's JIT stack and answer 503.
		if (req.url ~ "^[^?]*\?(.*&)?cursor=[^&]" &&
			regsub(req.url, "^[^?]*\?(?:.*&)?cursor(?:=|(?=&|$))([^&]*).*$", "\1") != "") {
			return (pass);
		}
		# Rewrite the URL to the canonical one before hashing, so MediaWiki receives and the
		# cache stores exactly the URL that purges reach, and junk parameters hit the cached
		# object instead of running the list query in PHP. Only sort is kept: "new" stays,
		# any other value (absent, empty, unknown, percent-encoded) becomes "hot", GlowThread's
		# default. Every other parameter is dropped (limit, cache busters, duplicates). When
		# sort appears more than once, the last one wins, which is PHP's rule for repeated keys.
		# PURGE is not rewritten: GlowThread purges the canonical URLs, the only ones stored.
		set req.http.x-glowthread-url = req.url;
		if (regsub(req.url, "^.*[?&]sort=([^&]*).*$", "\1") == "new") {
			set req.url = regsub(req.url, "\?.*$", "") + "?sort=new";
		} else {
			set req.url = regsub(req.url, "\?.*$", "") + "?sort=hot";
		}
		# The other cookies are dropped and the mobile flag is cleared, so each canonical URL
		# has one cached object. MediaWiki adds "Vary: x-restbase-compat" to every REST
		# response, but for this route the header only changes the format of errors. It is
		# dropped too, or each new value would miss and store one more copy of the list.
		unset req.http.Cookie;
		unset req.http.x-subdomain;
		unset req.http.x-restbase-compat;
		return (hash);
	}

	# The Action API and the REST API are not cached.
	if (req.url ~ "^/(api|rest)\.php") {
		return (pass);
	}

	# Pass requests from logged-in users directly. Only cookies with "session" or "Token" in
	# their name count, otherwise nothing would get cached (MediaWiki's manual).
	if (req.http.Authorization || req.http.Cookie ~ "([sS]ession|Token)=") {
		return (pass);
	}

	# Drop the cookies that do not change the page, so anonymous readers share cached pages:
	# analytics cookies starting with "_" and MediaWiki's anonymous mwuser-sessionId cookie.
	# Add the cookies of your own analytics or bot protection here. A cookie whose name
	# contains "session" or "Token" makes every page view of that reader pass (rule above).
	set req.http.Cookie = regsuball(req.http.Cookie, "(^|;\s*)(_[_\d\w]*)=[^;]*", "");
	set req.http.Cookie = regsuball(req.http.Cookie, "(^|;\s*)[\w]+_mwuser-sessionId=[^;]*", "");
	set req.http.Cookie = regsuball(req.http.Cookie, "^[\s;]*", "");

	return (hash);
}

sub vcl_hash {
	# The mobile flag. The built-in vcl_hash adds the URL and the Host.
	hash_data(req.http.x-subdomain);
}

sub vcl_hit {
	# Never serve an expired object: the request goes to MediaWiki instead (MediaWiki's manual).
	# A wiki that prefers speed to freshness can allow a grace period here and set beresp.grace.
	if (obj.ttl <= 0s) {
		return (pass);
	}
}

sub vcl_pass {
	# [dev] Remember the pass for X-Cache-Detail.
	set req.http.x-dev-pass = "1";
}

sub vcl_backend_fetch {
	unset bereq.http.x-dev-pass;
	# [GlowThread] MediaWiki gets the canonical URL only (see part 1).
	unset bereq.http.x-glowthread-url;
}

sub vcl_backend_response {
	# Host and URL of the object, for the bans of PURGE (and BAN). Removed in vcl_deliver.
	set beresp.http.x-ban-host = bereq.http.host;
	set beresp.http.x-ban-url = bereq.url;
	# Server errors are not cached (MediaWiki's manual). The TTL is left alone, so no
	# hit-for-miss object is created.
	if (beresp.status >= 500 && beresp.status <= 504) {
		set beresp.uncacheable = true;
		return (deliver);
	}
	# [GlowThread] carve-out, part 3 of 4: store a response of the read route only when it
	# has an explicit s-maxage (GlowThread sends "public, s-maxage=N" to anonymous readers).
	# Otherwise Varnish would use max-age, or its 2-minute default TTL when there is no
	# Cache-Control at all (a REST 404, for example). The response leaves a 2-minute
	# hit-for-miss object, even when MediaWiki answers private with max-age=0: part 1 sends every
	# anonymous variant of a list to one URL, and without that object concurrent requests for it
	# would wait for each other. A later response with s-maxage replaces it and is stored.
	if (bereq.url ~ "^/rest\.php/glowthread/v1/" && beresp.http.Cache-Control !~ "s-maxage=[1-9]") {
		set beresp.ttl = 120s;
		set beresp.uncacheable = true;
		return (deliver);
	}
	# Responses without a TTL (for example Cache-Control: private, max-age=0) and responses that
	# set a cookie are not cached (MediaWiki's manual).
	if (beresp.ttl <= 0s || beresp.http.Set-Cookie) {
		set beresp.uncacheable = true;
		return (deliver);
	}
	# The rest falls through to Varnish's built-in rules: the TTL comes from s-maxage or max-age
	# (MediaWiki sends s-maxage=$wgCdnMaxAge for anonymous page views), and Cache-Control
	# private, no-cache or no-store or Vary: * make the response uncacheable.
}

sub vcl_deliver {
	unset resp.http.x-ban-host;
	unset resp.http.x-ban-url;
	# HIT when served from the cache, otherwise MISS (passes are labelled MISS too).
	if (obj.hits > 0) {
		set resp.http.X-Cache = "HIT";
	} else {
		set resp.http.X-Cache = "MISS";
	}
	# [dev] X-Cache-Detail tells a pass (sent to MediaWiki without using the cache: passed in
	# vcl_recv, or an expired object) from a miss (looked up, not found).
	if (req.http.x-dev-pass) {
		set resp.http.X-Cache-Detail = "PASS";
	} else {
		set resp.http.X-Cache-Detail = resp.http.X-Cache;
	}
	# [GlowThread] carve-out, part 4 of 4: purges reach only this Varnish, so the client gets
	# every response of part 1 marked private, as MediaWiki does for every other URL. No cache in
	# front of Varnish and no browser keeps a copy that purges cannot reach: not of a URL that
	# part 1 rewrote, which is never purged, and not of the canonical URL, which a shared cache
	# in front would otherwise keep for its s-maxage.
	if (req.http.x-glowthread-url) {
		set resp.http.Cache-Control = "private, must-revalidate, max-age=0, s-maxage=0";
	}
}
