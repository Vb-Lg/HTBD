# HTBD: A Free and Open-Source WordPress Multilingual Plugin Powered by the Baidu Translate API

[![GitHub repository](https://img.shields.io/badge/GitHub-HTBD-181717?logo=github)](https://github.com/hanyixuanten/HTBD)

HTBD is a multilingual translation plugin for WordPress. It uses the Baidu Translate API to translate posts, pages, and other website content into different languages, with a dedicated URL for each translated version.

Translations are stored on the server in `{$wpdb->prefix}hyx_bd_translations`. Unless a user explicitly refreshes a translation, the same content is translated only once for each target language. Existing `{$wpdb->prefix}btranslate_translations` data is migrated when the renamed plugin is activated. This avoids calling the translation API on every page visit, reducing API usage and improving page load performance.

## Project Links

- Plugin homepage: [https://www.vblg.top/index.php/archives/147](https://www.vblg.top/index.php/archives/147)
- GitHub repository: [https://github.com/hanyixuanten/HTBD](https://github.com/hanyixuanten/HTBD)
- WordPress plugin page: [https://wordpress.org/plugins/hyx-translator-for-baidu-translate/](https://wordpress.org/plugins/hyx-translator-for-baidu-translate/)

> **Review status:** HTBD has been approved for the WordPress.org Plugin Directory and is listed at [wordpress.org/plugins/hyx-translator-for-baidu-translate](https://wordpress.org/plugins/hyx-translator-for-baidu-translate/). The recommended way to install it is directly from **Plugins > Add New Plugin** in WordPress Admin. GitHub releases remain available for manual installation and for older versions.

## Key Features

### Baidu Translate API Integration

HTBD uses the translation API provided by the Baidu Translate Open Platform. Users can configure their own App ID and secret key in WordPress Admin, and the plugin handles request signing, API calls, and error handling.

### Post, Page, and Term Translation

The plugin supports translating the main content of any public post type and public taxonomy selected in the settings, including:

- Titles
- Body content
- Excerpts
- SEO fields
- Image alternative text
- Tags
- Categories and custom taxonomy terms
- Selected interface text

### Multilingual URLs

HTBD can generate dedicated URLs for different languages, with support for:

- Language subdirectories such as `/en/` and `/jp/`
- A separate domain for each language
- Language-specific permalinks based on the configured routing mode

### Language Switcher

Visitors can move between languages without editing the URL:

- The `[htbd_language_switcher]` shortcode, with `format="list"` or `format="inline"`
- The **HTBD language switcher** block for block themes
- A classic widget for themes without block support

Each link points at the current page in the selected language, keeps the query string, and marks the active language with `aria-current`.

### Persistent Translations

After a translation is generated, it is stored on the WordPress server. Future requests for the same translation use the stored result instead of calling the Baidu Translate API again.

When the source content changes, the corresponding translation is marked as requiring an update, preventing an outdated translation from being used for changed content.

### WordPress Content Compatibility

When translating content, the plugin makes every effort to preserve:

- Gutenberg block markup
- HTML structure
- Shortcodes
- Placeholders
- URLs
- Content inside `<pre>` and `<code>` elements, which is left untranslated
- Protected special content

### Batched and Throttled Requests

Text segments that belong to the same post are sent to Baidu in one multi line request where possible, instead of one request per paragraph. Requests are spaced by the configured interval so the free plan's per-second limit is not exceeded, and transient provider errors are retried automatically.

### Multilingual SEO

HTBD provides essential SEO support for multilingual pages, including:

- Multilingual permalinks
- Canonical URLs
- `hreflang` alternate links on singular views and in localized sitemaps
- `x-default` pointing at the source language
- SEO field translation

### Failure Fallback

If a Baidu Translate API request fails, the plugin does not cause the website page to fail:

- If no translation is available, the website's source-language content is displayed.
- If a previously saved successful translation exists, the previous translation continues to be used.

### Diagnostics

The settings screen includes a diagnostics panel that checks the translation table, the permalink structure, language URL routing, the scheduled task queue, and the Baidu credentials with one short test request.

## WP-CLI

When WP-CLI is available, translations can be managed without the admin screen:

```bash
# Translate every published post into English, 20 at a time
wp htbd translate --language=en --limit=20

# Retranslate a single post
wp htbd translate --post_id=42 --force

# Translate categories and tags
wp htbd translate-terms --taxonomy=category

# Show the configuration, queued jobs, and stored row counts
wp htbd status

# Move stored translations between sites
wp htbd export translations.csv
wp htbd import translations.csv --dry-run
```

## Quick Start

### 1. Get Access to the Baidu Translate API

1. Open the [Baidu Translate Open Platform](https://fanyi-api.baidu.com/).
2. Register or sign in with a Baidu account.
3. Open the management console.
4. Activate the General Text Translation API service.
5. Create an application and enter the required application information.
6. Obtain the **APP ID** and **secret key** from the application management page.

> Your APP ID and secret key are sensitive credentials. Do not commit them to a public GitHub repository or expose them in front-end code or public logs.

For activation instructions, pricing, and usage limits, see the [official Baidu Translate API documentation](https://fanyi-api.baidu.com/doc/23).

### 2. Install the Plugin

HTBD is listed in the WordPress.org Plugin Directory, so WordPress can install and update it automatically.

#### Method 1: Install from WordPress Admin (Recommended)

1. Sign in to WordPress Admin.
2. Open **Plugins > Add New Plugin**.
3. Search for **HTBD** or **hyx Translator for Baidu Translate**.
4. Select **Install Now**, then select **Activate**.

#### Method 2: Upload the ZIP Package

1. Visit the [HTBD GitHub repository](https://github.com/hanyixuanten/HTBD).
2. Select **Releases**.
3. Download the **hyx-translator-for-baidu-translate-*.zip** file from the latest release.
4. Sign in to WordPress Admin.
5. Open **Plugins > Add New Plugin**.
6. Select **Upload Plugin**.
7. Choose the downloaded ZIP file and start the installation.
8. Activate HTBD after installation is complete.

#### Method 3: Upload to the Server

1. Visit the [HTBD GitHub repository](https://github.com/hanyixuanten/HTBD).
2. Select **Releases**.
3. Download the **hyx-translator-for-baidu-translate-*.zip** file from the latest release.
4. Upload the `hyx-translator-for-baidu-translate` directory to the WordPress plugin directory: `wp-content/plugins/hyx-translator-for-baidu-translate/`.
5. Sign in to WordPress Admin.
6. Open **Plugins > Installed Plugins**.
7. Find HTBD and select **Activate**.

### 3. Configure the Plugin

1. Sign in to WordPress Admin.
2. Open the HTBD settings page.
3. Enter the **APP ID** and **secret key** provided by the Baidu Translate Open Platform.
4. Set the website's source language, or enable **Automatically detect the source language** to use Baidu's `auto` source language mode.
5. Add the target languages you want to enable.
6. Select a language URL mode, such as language subdirectories or domain binding.
7. Choose the post types and taxonomies to translate.
8. Adjust the request interval if your Baidu plan allows more than one request per second.
9. Save the settings.

After changing language routing settings, open **Settings > Permalinks** in WordPress Admin and confirm that the permalink configuration is working correctly.

Automatic detection is stored as a separate setting. Entering `auto` in the source language field without enabling automatic detection remains a distinct configuration state.

### 4. Start Translating

1. Open the post or page you want to translate in WordPress Admin.
2. Save or update the content.
3. Use the translation feature provided by HTBD to generate translations for the target languages.
4. Wait for the translation tasks to finish.
5. Check each translation at its language-specific URL.

For example, suppose the source-language page is available at:

```txt
https://example.com/about/
```

When English uses the `/en/` subdirectory, its translated URL is:

```txt
https://example.com/en/about/
```

When Japanese uses the `/jp/` subdirectory, its translated URL is:

```txt
https://example.com/jp/about/
```

Requests for `wp-login.php` or `wp-admin` through a target-language subdirectory or bound domain are redirected to the corresponding source-site URL. Query parameters are preserved.

The plugin prioritizes saved translations. It calls the translation API again only when the source content changes, a translation becomes invalid, or a user explicitly requests a refresh.

## Recommendations

- Test permalinks and multilingual routing in a staging environment before enabling the plugin in production.
- Set appropriate usage quotas and security policies for the Baidu Translate API.
- Do not store API credentials in source code, front-end JavaScript, or public logs.
- Manually review brand names, technical terminology, and important pages before publishing translations.
- After changing source content, check whether the corresponding translations need to be updated.
- When configuring domain binding, also configure DNS, HTTPS certificates, and the server's domain settings.
- After changing language routing, verify that canonical URLs, `hreflang` links, and sitemaps are correct.
- Run the diagnostics panel after any hosting, permalink, or credential change.

## System Requirements

- WordPress 6.4 or later
- PHP 8.1 or later
- A server environment that can connect to the Baidu Translate API
- A valid Baidu Translate Open Platform APP ID and secret key

## Development

```bash
# Lint every PHP file
find . -path './.git' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l

# Run the standalone checks: no WordPress, network access, or credentials needed
for test in tests/*.php; do php "$test" || exit 1; done

# Build the installable ZIP, verified against the header and readme stable tag
./build.sh build
```

The checks in `tests/` stub the WordPress functions they call, so they run on any machine with PHP. They cover translation batching, cron argument normalization, sitemap fallback markup, URL rewriting, domain normalization, and translation context parsing.

## Feedback and Contributions

To report a problem or suggest a feature, visit the GitHub repository:

[https://github.com/hanyixuanten/HTBD](https://github.com/hanyixuanten/HTBD)

When opening an issue, consider including the following information:

- WordPress version
- PHP version
- HTBD version
- Languages and routing mode in use
- Steps to reproduce the problem
- Error logs with sensitive information removed

Contributions through issues and pull requests are welcome.
