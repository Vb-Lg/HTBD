/**
 * Suggests the language the visitor's browser asks for.
 *
 * Configuration is provided by HTBD_Language_Prompt::config() through
 * wp_localize_script. Detection stays in the browser so a cached page never
 * carries a notice meant for someone else, and nothing is redirected
 * automatically: the visitor chooses.
 */
( function () {
	'use strict';

	var config = window.htbdLanguagePrompt;

	if ( ! config || ! config.links || ! config.links.length ) {
		return;
	}

	var root = document.getElementById( 'htbd-language-prompt' );

	if ( ! root ) {
		return;
	}

	var message = root.querySelector( '.htbd-language-prompt__message' );
	var link    = root.querySelector( '.htbd-language-prompt__link' );
	var dismiss = root.querySelector( '.htbd-language-prompt__dismiss' );

	if ( ! message || ! link ) {
		return;
	}

	/**
	 * Records the language the visitor already answered, so the same notice is
	 * not shown again for that language.
	 *
	 * @param {string} code Language code.
	 */
	function remember( code ) {
		try {
			window.localStorage.setItem( config.storageKey, code );
		} catch ( error ) {
			// Private browsing or a storage policy can reject writes; the notice
			// simply reappears on the next page view.
		}
	}

	/**
	 * @return {string} Language the visitor already answered, if any.
	 */
	function recall() {
		try {
			return window.localStorage.getItem( config.storageKey ) || '';
		} catch ( error ) {
			return '';
		}
	}

	/**
	 * Normalises the browser's language list to lower case BCP 47 tags, most
	 * preferred first.
	 *
	 * @return {string[]} Language tags.
	 */
	function browserLanguages() {
		var list = navigator.languages && navigator.languages.length ? navigator.languages : [ navigator.language || navigator.userLanguage || '' ];

		return list
			.filter( function ( tag ) {
				return typeof tag === 'string' && '' !== tag;
			} )
			.map( function ( tag ) {
				return tag.toLowerCase().replace( '_', '-' );
			} );
	}

	var index = {};

	config.links.forEach( function ( item ) {
		( item.aliases || [] ).forEach( function ( alias ) {
			if ( ! Object.prototype.hasOwnProperty.call( index, alias ) ) {
				index[ alias ] = item;
			}
		} );
	} );

	/**
	 * Matches one browser tag, dropping subtags from the right until a
	 * configured alias matches: zh-Hant-TW becomes zh-hant, then zh.
	 *
	 * @param {string} tag Lower case BCP 47 tag.
	 * @return {Object|null} Matching link, or null.
	 */
	function match( tag ) {
		var candidate = tag;

		while ( candidate ) {
			if ( Object.prototype.hasOwnProperty.call( index, candidate ) ) {
				return index[ candidate ];
			}

			var separator = candidate.lastIndexOf( '-' );

			if ( -1 === separator ) {
				return null;
			}

			candidate = candidate.slice( 0, separator );
		}

		return null;
	}

	var tags  = browserLanguages();
	var found = null;

	for ( var i = 0; i < tags.length && ! found; i++ ) {
		found = match( tags[ i ] );
	}

	if ( ! found || found.code === config.current ) {
		return;
	}

	// The visitor already declined, or already accepted, this language.
	if ( recall() === found.code ) {
		return;
	}

	var i18n = config.i18n || {};

	message.textContent = ( i18n.message || '%s' ).replace( '%s', found.label );
	link.textContent    = ( i18n.action || '%s' ).replace( '%s', found.label );
	link.href           = found.url;
	link.hreflang       = found.hreflang;
	link.lang           = found.hreflang;
	link.hidden         = false;

	root.hidden = false;

	link.addEventListener( 'click', function () {
		remember( found.code );
	} );

	if ( dismiss ) {
		dismiss.addEventListener( 'click', function () {
			remember( found.code );
			root.hidden = true;
		} );
	}
}() );
