# HTBD 1.0.0

```markdown
## HTBD 1.0.0

First stable release. Everything below is new since v0.3.1.

### Highlights

- Translate any public post type and public taxonomy, not only posts, pages, categories, and tags.
- `hreflang` alternates on singular views and in localized sitemaps, including an `x-default` entry and a canonical fallback.
- Language switcher available as a block, the `[htbd_language_switcher]` shortcode, and a classic widget.
- Batched translation: the text segments of one post share a multi line Baidu request, requests respect a configurable interval, and transient provider errors are retried automatically.
- WP-CLI commands: `wp htbd translate`, `translate-terms`, `status`, `export`, and `import`.
- Diagnostics panel that checks the translation table, permalinks, URL routing, the cron backlog, and the Baidu credentials.

### Fixes

- Fatal error on category and tag archives caused by the `single_term_title` callback signature.
- Plain text content never resolved its stored translation.
- Duplicate translation jobs when the same post was scheduled with different argument lists.
- No confirmation after queueing translations or clearing the cache.
- The `get_term` filter changed term names in the admin, REST, and WP-CLI.
- The localized sitemap blocked on a request to the site itself.
- The Baidu secret key was printed into the settings page markup.
- Uninstalling removed the original plugin's data.
- Titles were escaped twice, so an ampersand rendered as `&amp;amp;`.

### Performance

- Translation lookups are cached per request.
- Progress and failure queries use new indexed columns instead of parsing `field_context`.
- Generated SEO values reach scheduled jobs through a transient instead of the cron option.
- Admin progress polling stops when the queue is done, pauses in background tabs, and backs off on errors.

### Compatibility and upgrade notes

- Requires WordPress 6.4 and PHP 8.1.
- The translation table gains `item_type`, `item_id`, and an `item_lookup` index. An `htbd_db_version` option triggers the upgrade and the backfill on the first request after updating; no reactivation is needed.
- Third party providers must implement `HTBD_Translation_Provider::translate_batch()`.
- New settings: post types, taxonomies, and request interval. Existing settings keep working; missing keys fall back to defaults.
- The stored Baidu secret key is no longer echoed into the form. Leave the field blank to keep it, or tick the new checkbox to delete it.
- `the_title` and `single_term_title` now return plain text instead of pre-escaped HTML.
- Translation jobs queued by 0.3.x are recognised and cleared before new jobs are scheduled.

### Tests

Six standalone checks with 80 assertions run without WordPress, network access, or credentials: request batching, cron arguments, sitemap fallback, context parsing, domain normalization, and URL routing.
```

```markdown
## HTBD 1.0.0

首个稳定版本。以下均为 v0.3.1 之后的新增内容。

### 主要更新

- 支持翻译任意公开文章类型与公开分类法，不再局限于文章、页面、分类目录与标签。
- 单页视图与本地化站点地图输出 `hreflang` 多语言链接，包含 `x-default`，并在主题移除核心 canonical 时提供回退。
- 语言切换器提供区块、`[htbd_language_switcher]` 短代码与传统小工具三种形式。
- 批量翻译：同一篇文章的文本段合并为一次百度多行请求，请求之间按可配置间隔限速，可恢复的服务端错误自动重试。
- WP-CLI 命令：`wp htbd translate`、`translate-terms`、`status`、`export`、`import`。
- 诊断面板，检查翻译数据表、固定链接、语言路由、计划任务队列与百度凭据。

### 修复

- 修复 `single_term_title` 回调签名导致的分类与标签归档页致命错误。
- 修复纯文本正文始终无法命中已保存译文的问题。
- 修复同一文章因参数列表不同而被重复排入翻译任务的问题。
- 修复排队翻译与清空缓存后没有任何结果提示的问题。
- 修复 `get_term` 过滤器改动后台、REST 与 WP-CLI 中分类项名称的问题。
- 修复本地化站点地图会向本站自身发起请求、可能阻塞的问题。
- 修复百度密钥被写入设置页 HTML 的问题。
- 修复卸载时会删除原版插件数据的问题。
- 修复标题被二次转义、导致 `&` 渲染为 `&amp;amp;` 的问题。

### 性能

- 译文查询增加请求级缓存。
- 进度与失败查询改用新增的索引列，不再解析 `field_context`。
- 动态生成的 SEO 取值通过 transient 传给计划任务，不再写入 cron 选项。
- 后台进度轮询在任务完成后停止，标签页隐藏时暂停，请求失败时退避。

### 兼容性与升级注意

- 需要 WordPress 6.4 与 PHP 8.1。
- 翻译数据表新增 `item_type`、`item_id` 列与 `item_lookup` 索引；升级后的首次请求会依据 `htbd_db_version` 自动升级并回填历史数据，无需重新启用插件。
- 自研翻译服务需实现 `HTBD_Translation_Provider::translate_batch()`。
- 新增设置项：文章类型、分类法、请求间隔。原有配置继续生效，缺失字段回退默认值。
- 已保存的百度密钥不再回显到表单。留空表示保留，勾选新增的复选框可删除。
- `the_title` 与 `single_term_title` 现在返回纯文本，不再返回已转义的 HTML。
- 0.3.x 残留的翻译任务会在排入新任务前被识别并清理。

### 测试

六个独立检查、共 80 项断言，无需 WordPress、网络或凭据即可运行：批量请求、计划任务参数、站点地图回退、上下文解析、域名归一化、URL 路由。
```
