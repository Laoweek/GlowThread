/*!
 * GlowThread loader (ext.glowThread.init), loaded on every page with comments.
 *
 * Creates the comment container #glowthread after #bodyContent (where FlowThread put its
 * #flowthread, so site CSS can place it in a skin's grid), fills it with a static skeleton,
 * and loads the app (ext.glowThread.app):
 * - at once when the address points at a comment (#gt-123) or the section (#glowthread, or
 *   #flowthread from links made before a wiki moved from FlowThread),
 * - after the page has loaded when the section is on screen without scrolling,
 * - otherwise only once the reader scrolls and the section comes within a screen. On phones
 *   MobileFrontend collapses the article, so the section often sits just below the first
 *   screen on views where nobody reads the comments: those must not pay for the app.
 *
 * The server reserves the section's space before the first paint (ext.glowThread.reserve,
 * an estimate from the page's comment count), so inserting the container moves nothing.
 *
 * Hand-written ES2017 without a build step. Keep it small: it runs on every article view.
 */
const config = mw.config.get( 'wgGlowThread' );
// The space taken when the server reserved none: 32px margin and the 280px skeleton.
const DEFAULT_SPACE = 312;
const MARGIN = 32;
let state = 0; // 0 waiting, 1 loading, 2 mounted

function wantsComment() {
	return /^#(gt-\d+|glowthread|flowthread)$/.test( location.hash );
}

/** The address points at the section: #glowthread, or #flowthread from older links. */
function wantsSection() {
	return location.hash === '#glowthread' || location.hash === '#flowthread';
}

function skeleton( el, height ) {
	// Pure shapes, so it needs no messages. The app keeps the same height until the list
	// is drawn (data-gt-reserve).
	const bar = ( w, h, r ) => '<span style="display:block;width:' + w + ';height:' + h +
		'px;border-radius:' + ( r || 4 ) + 'px;background:var(--gt-init-fill)"></span>';
	const row = '<div style="display:flex;gap:12px;padding:16px 0">' + bar( '36px', 36, 18 ) +
		'<div style="flex:1;display:grid;gap:8px">' + bar( '96px', 14 ) + bar( '80%', 14 ) +
		bar( '45%', 14 ) + '</div></div>';
	// Rows enough to fill the height (84px of header and composer, about 90px a row).
	const rows = Math.max( 2, Math.min( 30, Math.floor( ( height - 84 ) / 90 ) ) );
	el.innerHTML = '<div aria-hidden="true" style="height:' + height + 'px;overflow:hidden;margin-top:' + MARGIN + 'px;' +
		'--gt-init-fill:rgba(128,128,128,.14)"><div style="display:flex;justify-content:space-between;' +
		'padding:8px 0 12px">' + bar( '72px', 20 ) + bar( '88px', 20 ) + '</div>' +
		bar( '100%', 44, 6 ) + row.repeat( rows ) + '</div>';
	el.setAttribute( 'data-gt-reserve', String( height ) );
	el.setAttribute( 'aria-busy', 'true' );
}

function load( el ) {
	if ( state ) {
		return;
	}
	state = 1;
	// The first page of the list loads alongside the app, which takes it for its first request:
	// the same URL, headers and credentials as its own (restGet in client/src/lib/api.ts), so
	// Varnish serves it from the same cached object.
	const url = config.listUrls && config.listUrls[ config.defaultSort === 'new' ? 'new' : 'hot' ];
	const list = url ? { url, response: fetch( url, { credentials: 'same-origin', headers: { Accept: 'application/json' } } ) } : null;
	if ( list ) {
		// A failure reaches the app when it reads the response, or nobody when the app fails.
		list.response.catch( () => {} );
	}
	mw.loader.using( 'ext.glowThread.app' ).then( ( req ) => {
		req( 'ext.glowThread.app' ).mount( el, list );
		state = 2;
	}, () => {
		// The app module failed (network, or a browser that cannot run it). Remove the
		// reserved space rather than leave a skeleton that never resolves.
		state = 0;
		el.removeAttribute( 'aria-busy' );
		el.innerHTML = '';
	} );
	if ( mw.config.get( 'wgUserName' ) !== null ) {
		// Logged-in readers usually get the composer at once: its module, which anonymous readers
		// skip, loads alongside. A request of its own keeps the app's URL the one every reader caches.
		mw.loader.load( 'ext.glowThread.app.extras' );
	}
}

/**
 * The skin's element with this ID. Page text can carry the same IDs (the parser output, a
 * DISPLAYTITLE in the heading, page indicators): those must neither capture the section nor
 * hide it.
 */
function skinElement( id ) {
	const all = document.querySelectorAll( '[id="' + id + '"]' );
	for ( let i = 0; i < all.length; i++ ) {
		if ( !all[ i ].closest( '.mw-parser-output, h1, .mw-indicators' ) ) {
			return all[ i ];
		}
	}
	return null;
}

/** The container, if the page already has one where the loader puts it. */
function existing( anchor ) {
	const all = document.querySelectorAll( '#glowthread' );
	for ( let i = 0; i < all.length; i++ ) {
		if ( all[ i ].parentNode === anchor || all[ i ].parentNode === anchor.parentNode ) {
			return all[ i ];
		}
	}
	return null;
}

function create( anchor ) {
	const el = document.createElement( 'div' );
	el.id = 'glowthread';
	el.className = 'ext-glowthread';
	if ( mw.config.get( 'skin' ) === 'minerva' ) {
		// Minerva puts the side gutters on #bodyContent and no site CSS places
		// #glowthread there, so the section goes last inside it to share them.
		anchor.appendChild( el );
	} else {
		anchor.parentNode.insertBefore( el, anchor.nextSibling );
		// In a grid with no place for it, the section would land in a free cell above the title
		// (Vector 2022, whose .mw-body is a grid at most widths) or in a new row after the page
		// footer (Citizen: areas "content" and "footer"). In a row of its own it would also move
		// when a column beside the content grows after load (Vector 2022's pinned tools). Unless
		// site CSS gives #glowthread a grid area, it goes last inside the anchor instead, where
		// the server reserved its space.
		if ( ( /grid/.test( getComputedStyle( anchor.parentNode ).display ) ||
			mw.config.get( 'skin' ) === 'vector-2022' ) &&
			getComputedStyle( el ).gridRowStart === 'auto'
		) {
			anchor.appendChild( el );
		}
	}
	return el;
}

/** The space the server reserved at the end of #bodyContent (0 when none). */
function reserved( anchor ) {
	if ( anchor.id !== 'bodyContent' || !document.body.classList.contains( 'ext-glowthread-reserve' ) ) {
		return 0;
	}
	return parseFloat( getComputedStyle( anchor, '::after' ).height ) || 0;
}

/** Runs `fn` once the page's own downloads are done. */
function afterPageLoad( fn ) {
	const later = () => setTimeout( fn, 0 );
	if ( document.readyState === 'complete' ) {
		later();
	} else {
		window.addEventListener( 'load', later, { once: true } );
	}
}

function watch( el ) {
	if ( !( 'IntersectionObserver' in window ) ) {
		load( el );
		return;
	}
	// The reader may scroll before this runs (it waits for the DOM, seconds on a slow phone).
	const scrolled = window.pageYOffset > 0;
	// On screen without scrolling (a short article, or the layout settled with the section in
	// view): load once the page itself has loaded, or at once when the reader scrolled there.
	const inView = new IntersectionObserver( ( entries ) => {
		if ( entries.some( ( e ) => e.isIntersecting ) ) {
			stop();
			if ( scrolled ) {
				load( el );
			} else {
				afterPageLoad( () => load( el ) );
			}
		}
	} );
	// After the reader scrolls: load when the section is within a screen below the viewport.
	let ahead = null;
	function onScroll() {
		ahead = new IntersectionObserver( ( entries ) => {
			if ( entries.some( ( e ) => e.isIntersecting ) ) {
				stop();
				load( el );
			}
		}, { rootMargin: '0px 0px 100% 0px' } );
		ahead.observe( el );
	}
	function stop() {
		inView.disconnect();
		if ( ahead ) {
			ahead.disconnect();
		}
		window.removeEventListener( 'scroll', onScroll );
	}
	inView.observe( el );
	if ( scrolled ) {
		onScroll();
	} else {
		window.addEventListener( 'scroll', onScroll, { passive: true, once: true } );
	}
}

function init() {
	const anchor = skinElement( 'bodyContent' ) || skinElement( 'mw-content-text' );
	if ( !anchor || !anchor.parentNode ) {
		return;
	}
	const el = existing( anchor ) || create( anchor );
	const space = reserved( anchor );
	el.classList.add( 'ext-glowthread' );
	// The skeleton takes over the reserved space in the same frame, so nothing moves.
	skeleton( el, Math.max( ( space || DEFAULT_SPACE ) - MARGIN, 100 ) );
	document.body.classList.remove( 'ext-glowthread-reserve' );
	if ( wantsSection() ) {
		// The container did not exist when the browser looked for the fragment (and the old
		// #flowthread names no element at all).
		el.scrollIntoView();
	}

	window.addEventListener( 'hashchange', () => {
		if ( wantsSection() ) {
			// #flowthread names no element, and page text may carry an element with the ID
			// glowthread: the browser alone would not scroll to the section.
			el.scrollIntoView();
		}
		if ( wantsComment() ) {
			load( el );
		}
	} );
	if ( wantsComment() ) {
		load( el );
		return;
	}
	watch( el );
}

if ( config && config.pageId ) {
	$( init );
}
