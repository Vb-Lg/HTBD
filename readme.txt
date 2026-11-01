=== HTBD - hyx Translator powered by Baidu Translate ===
Contributors: hanyixuanten
Homepage: https://www.vblg.top/index.php/archives/147
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPL-3.0-only
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Persistent multilingual WordPress translations powered by the Baidu Translate API.

== Description ==

HTBD translates supported WordPress content with the Baidu Translate API and persists each translation for reuse. It supports language-specific subdirectory URLs and domain bindings.

The WordPress admin interface follows the current WordPress locale. English is the default. Approved WordPress.org language packs take priority, with the bundled Simplified Chinese translation used as a fallback when WordPress is set to `zh_CN`.

Supported values include published titles, content, excerpts, image alternative text, term names and descriptions, and selected SEO fields, for any public post type and public taxonomy selected in the settings. Automatic translation is only scheduled for posts and pages with the `publish` status. Completed translations are stored in the `{$wpdb->prefix}hyx_bd_translations` custom database table. Existing `{$wpdb->prefix}btranslate_translations` data is migrated when the renamed plugin is activated. Front-end rendering reuses stored values and never calls the translation provider dynamically.

Subdirectory routing supports paths such as `/en/example-post/`. Domain routing maps configured hostnames to target languages. Requests for `wp-login.php` or `wp-admin` through a target-language route are redirected to the corresponding source-site URL while preserving query parameters. Translation failures are non-fatal and fall back to source-language content.

Singular views print `hreflang` alternate links for every configured language, and localized sitemaps declare the same alternates. A language switcher is available as the `[htbd_language_switcher]` shortcode, the HTBD language switcher block, and a classic widget.

When browser language suggestions are enabled in the settings, a visitor whose browser prefers another configured language sees a dismissible notice linking to the same page in that language. The suggestion is decided in the browser so a cached page stays correct for every visitor, nothing is redirected automatically, and a suggestion the visitor already answered is not repeated.

Text segments of the same post are sent to Baidu as one multi line request where possible, requests are spaced by the configured interval, and transient provider errors are retried. `wp htbd translate`, `wp htbd translate-terms`, `wp htbd status`, `wp htbd export`, and `wp htbd import` are available when WP-CLI is installed.

== Installation ==

1. Upload the `hyx-translator-for-baidu-translate` directory to `/wp-content/plugins/`.
2. Activate HTBD through the Plugins screen in WordPress.
3. Open Settings > HTBD and configure the Baidu credentials, languages, and routing mode. Enable automatic source-language detection to use Baidu's `auto` source language mode.
4. After saving settings for the first time, manually run Retranslate all content to queue existing content.

Automatic detection is stored independently from the source language value. Manually entering `auto` without enabling automatic detection remains a distinct configuration state.

== External Services ==

HTBD connects to the Baidu Translate Open Platform API, a service provided by Baidu, to translate WordPress content. The plugin cannot generate new translations without this service. A Baidu Translate account, application ID, and secret key are required. Baidu may apply request quotas or usage charges according to the service plan selected by the site administrator.

The API is contacted by scheduled tasks after a published post or page is saved, after a supported category or tag is created or edited, or after an administrator explicitly queues or refreshes translations. Front-end page views reuse stored translations and do not contact Baidu. The plugin also makes no Baidu request when valid persisted translations are already available, unless an administrator explicitly requests a refresh.

For each translation request, the plugin sends the text being translated, the configured source and target language codes, the Baidu application ID, a random salt, and a request signature. Depending on the content being translated, the text may contain published post or page titles, body text, excerpts, selected SEO title and description values, attached image alternative text, and category or tag names and descriptions. Standard network information, including the originating server IP address, is also visible to Baidu as part of the HTTPS request. The secret key is used locally to create the signature and is not sent as a separate request field.

HTBD stores the returned translated text in the WordPress database but does not store complete Baidu response payloads. Data sent to Baidu is subject to Baidu's own terms and privacy practices:

* Baidu Translate API documentation: https://fanyi-api.baidu.com/doc/23
* Baidu Translate Open Platform service agreement: https://fanyi-api.baidu.com/doc/6
* Baidu privacy policy: https://privacy.baidu.com/policy

== Frequently Asked Questions ==

= Does the plugin translate content on every page request? =

No. Each value is translated by a scheduled task and persisted for reuse. Front-end requests read stored translations only.

= Does the plugin translate code in posts or pages? =

No. Content inside HTML `<pre>` and `<code>` elements is preserved in the source language and is not sent for translation.

= How do I retry a failed translation? =

Failed posts, pages, categories, or tags appear below the progress bar on the Settings page. Use the Retranslate button beside an item to retry it.

= What data is removed during uninstall? =

Uninstalling removes plugin settings, scheduled tasks, and the custom translation table, including all persisted translations.

= Does the plugin redirect visitors automatically? =

No. When browser language suggestions are enabled, visitors whose browser prefers another configured language see a dismissible notice with a link to the same page in that language. The notice is off by default and only contains a link, so every language keeps its own canonical URL and no request is redirected without the visitor choosing to follow it.

== Changelog ==

= 1.0.1 =

* Fix Simplified Chinese labels on the settings and diagnostics screens that displayed a neighbouring string instead of their own.
* Fix two Simplified Chinese format strings that dropped or invented a placeholder.
* Translate the language switcher block inside the editor by loading script translations from a bundled JED JSON file.

= 1.0.0 =

* Add `hreflang` alternate links on singular views and in localized sitemaps, plus a canonical fallback when the theme removed the core link.
* Add a language switcher block, `[htbd_language_switcher]` shortcode, and widget.
* Translate any public post type and taxonomy instead of only posts, pages, categories, and tags.
* Batch text segments into multi line Baidu requests, add a configurable request interval, and retry transient provider errors.
* Add WP-CLI commands for bulk translation, status, CSV export, and CSV import.
* Add a diagnostics panel that checks the translation table, permalinks, URL routing, scheduled tasks, and the Baidu credentials.
* Fix a fatal error on category and tag archives caused by the `single_term_title` callback signature.
* Fix plain text content never finding its stored translation.
* Fix duplicate translation jobs caused by scheduling the same post with different argument lists.
* Report the result of queueing and cache clearing actions in the admin.
* Cache translation lookups per request and keep the original `created_at` when a translation is refreshed.
* Serve localized sitemaps from a cached copy and answer the first request with a minimal sitemap instead of waiting on a request to the site itself.
* Index translated rows by item and upgrade the table automatically on update, without needing a reactivation.
* Hand generated SEO values to scheduled jobs through a transient instead of the cron option.
* Keep the secret key out of the settings page markup, and only remove the original plugin's data on uninstall when this plugin migrated it.
* Accept `localhost` and IPv4 addresses in domain bindings.
* Poll translation progress only while the queue can still change.

= 0.3.1 =

* Add per-language controls for translating posts, terms, and clearing persisted translations.
* Fix Baidu Japanese routing to use the supported `jp` language code.
* Preserve target-language routing when submitting comments and following comment redirects.
* Improve WordPress.org localization packaging and release deployment workflows.

= 0.3.0 =

* Add automatic source-language detection using Baidu's `auto` mode.
* Redirect target-language `wp-admin` and `wp-login.php` requests to the source site while preserving query parameters.
* Add automated GitHub Release packaging and WordPress.org SVN deployment workflows.

= 0.2.4 =

* Fix translated titles so singular views use the current post context.
* Preserve content inside HTML `<pre>` and `<code>` elements instead of sending it for translation.
* Improve plugin security metadata and translator attribution.
* Update the plugin homepage URL.

= 0.2.3 =

* Rename the plugin and codebase to HTBD / hyx-translator-for-baidu-translate.
* Migrate existing translation data from the previous custom database table.
* Update packaging, localization, documentation, and issue templates for the new plugin identity.
* Fix the release archive build workflow.