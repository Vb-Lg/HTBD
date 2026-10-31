# HTBD 全仓代码质量审查

- 审查范围：`hyx-translator-for-baidu-translate.php`、`includes/` 全部 14 个文件、`assets/js/admin.js`、`uninstall.php`、`build.sh`、`.github/workflows/`、`README.md` / `README.zh-CN.md` / `readme.txt`
- 基线：`a1f7933`（master，v0.3.1 之后）
- 方法：逐文件静态审查 + WordPress 钩子签名核对（`single_term_title`、`term_description`、`do_parse_request`、`home_url` 等）

## 一、结论概览

| 级别 | 数量 | 含义 |
| --- | --- | --- |
| P0 | 2 | 会直接 500 / 违反插件目录规范，必须尽快修 |
| P1 | 6 | 功能实际不生效或产生重复开销 |
| P2 | 12 | 性能、健壮性、可维护性问题 |

架构分层方向是对的：路由 / Provider / Service / Store / 渲染 / 后台分离清晰，Provider 通过接口隔离，identity 由「源文指纹 + 源语言 + 目标语言 + 字段上下文 + provider 版本」构成，符合 `AGENTS.md` 约定。主要问题集中在**钩子签名、读写路径一致性、以及「README 承诺 > 代码实现」**三处。

## 二、P0 问题

### P0-1 `single_term_title` 回调参数数量不匹配 → 分类/标签归档页致命错误

`includes/class-content-controller.php:25`：

```php
add_filter( 'single_term_title', array( $this, 'translate_term_title' ), 20, 2 );
```

`includes/class-content-controller.php:219`：

```php
public function translate_term_title( $term_name, $term ) {
	return $this->escaped_translated_value( $term_name, 'term:' . $term->term_id . ':name' );
}
```

WordPress 的 `single_term_title` 只传 1 个参数（`wp-includes/general-template.php`：`apply_filters( 'single_term_title', $term->name )`）。`WP_Hook::apply_filters()` 在 `accepted_args >= num_args` 时只按实际参数个数调用，不会补位，因此 PHP 8 会抛 `ArgumentCountError`。凡是调用 `single_term_title()` / `get_the_archive_title()` 的归档页（绝大多数主题）都会白屏。

修复建议（同时消除对 `$term` 的依赖）：

```php
public function translate_term_title( $term_name, $term = null ) {
	$term_id = $term instanceof WP_Term ? $term->term_id : (int) get_queried_object_id();

	if ( ! $term_id ) {
		return $term_name;
	}

	return $this->escaped_translated_value( $term_name, 'term:' . $term_id . ':name' );
}
```

并建议全仓复核一次钩子签名（`accepted_args` 必须 ≥ 回调中**无默认值**的参数个数）。其余已核对无误的钩子：`the_title`(2)、`get_the_excerpt`(2)、`get_post_metadata`(4)、`get_term`(3)、`document_title_parts`(1)、`post_link`/`term_link`(2)、`home_url`/`site_url`(4)、`do_parse_request`(3)、`wp_get_attachment_image_attributes`(2)。

### P0-2 README 宣称的 canonical / hreflang 在代码中不存在

```bash
$ grep -rn "hreflang\|canonical\|wp_head" --include=*.php .
# 无输出
```

但 `README.md:62-70`、`README.zh-CN.md:62-70` 的「Multilingual SEO / 多语言 SEO」明确列出 *Canonical URLs* 与 *`hreflang` alternate links*，`readme.txt` 第 21 行也暗示路由与 SEO 输出完备，`AGENTS.md` 同样把它列为硬性要求。

插件已通过 WordPress.org 审核，此时「文档承诺 > 实际功能」属于典型的高风险项（审核复查 / 用户投诉 / 支持成本）。二选一，不能放着：

- 实现：在 `wp_head` 输出 `<link rel="canonical">` 与全语言 `<link rel="alternate" hreflang="…">`，并在 sitemap 里补 `xhtml:link` alternate（`class-sitemap-controller.php` 已经有改写 sitemap 的能力，可直接复用 `localized_url()`）；
- 或先删掉 README / readme.txt 中的对应表述，等功能上线再加回。

**建议直接实现**，因为 SEO 是多语言插件的第一卖点，且 `localized_url()` 已经能算出所有语言 URL，成本不高。

## 三、P1 问题

### P1-1 纯文本文章永远翻译不出来（读写 key 不一致）

`includes/class-content-controller.php:173-177`：

```php
public function translate_content( $content ) {
	$post_id = get_the_ID();
	if ( ! $post_id || false === strpos( $content, '<' ) ) {
		return $this->escaped_translated_value( $content, 'post:' . $post_id . ':post_content' );
	}
```

而写入侧（`class-content-translator.php:30`、`class-admin.php:98`）一律使用带序号的分段 key：`post:{id}:post_content:text:{n}`。所以不含任何 HTML 标签的正文（纯段落文本、经典编辑器未开自动 `<p>` 时）读端查的是 `post:{id}:post_content`，永远 miss，回退原文。

另外两个连带问题：

1. `$post_id` 为 0 时（在非主循环上下文调用 `the_content`）会生成 `post:0:post_content` 这种脏 key；
2. 整段 `esc_html()`：正文中的 `&`、`&amp;`、`©` 会被二次编码，纯文本正文渲染后出现 `&amp;amp;`。

修复：删掉这个短路分支，统一走 `segments()` 路径（单段文本时 `segments()` 返回 1 个元素，`text_index = 0`，与写端自然对齐）；无 `$post_id` 时直接返回原文。

### P1-2 同一文章可能被排入两个重复翻译任务

| 位置 | 调度参数 |
| --- | --- |
| `class-content-controller.php:52` | `array( $post_id, $target_language )` |
| `class-admin.php:333` | `array( $post_id, $target_language, true )` |

`wp_next_scheduled()` / `wp_unschedule_event()` 的匹配依据是 `md5(serialize($args))`，2 个参数与 3 个参数是两条不同事件。后果：

- `schedule_post_translation()` 的去重判断永远看不到后台排入的事件 → 保存文章会再排一次；
- `clear_scheduled_event()` 清不掉 2 参数版本 → 清缓存后旧任务仍会跑。

term 侧同理（`false` vs `true`，参数个数相同但值不同，也是两条事件）。统一成固定 3 参数，或统一在调度函数里做参数规范化。

### P1-3 后台操作缺少反馈提示（dead code）

`class-admin.php:348` 与 `:377` 分别重定向到 `?htbd_queued=…`、`?htbd_cache_cleared=…`，但 `render_settings_page()` 里没有任何读取这两个参数的代码，也没有 `admin_notices` 处理器。用户点「重新翻译全部内容」后页面只是刷新了一下，不知道排了多少条、是否成功。

修复：注册 `admin_notices`（限定 `settings_page_htbd`），按 `htbd_queued` 输出 `_n()` 计数提示，按 `htbd_cache_cleared` 输出清理成功提示，并 `remove_query_arg` 避免刷新重复提示。

### P1-4 翻译请求无节流、无批处理、无重试

`class-content-translator.php:30` 对每个文本段发一次 `wp_remote_post`，串行、无间隔。百度通用文本翻译标准版限制 1 QPS，长文（几十段）必然触发 `54003 访问频率受限`，而这些失败会被记为 `failed` 并在后台列出「翻译失败」。

可落地的三步改造：

1. **批处理**：百度支持 `q` 传多行（`\n` 分隔，总长 ≤ 6000 字节），把 N 段合成 1 次请求，响应 `trans_result` 按行返回，`implode("\n", $dst)` 已有此假设，说明原本就设计成多行，只是没启用；
2. **节流**：串行请求之间 `usleep()`（可配 300–1000ms）或令牌桶；
3. **重试**：对 `54003`/`52003`/`request_failed` 做指数退避重试 2 次，仍失败再落 `failed`。

### P1-5 `get_term` 全局过滤会污染后台与 REST

`class-content-controller.php:42,277-287`：`get_term` 过滤器返回 `clone $term`，且无条件生效。影响：

- 后台文章列表 / 分类编辑页显示的是译文名，管理员无法确认原文；
- `$term` 不是同一个对象实例，任何依赖 `===` 或对象缓存的逻辑（含 `WP_Term_Query` 的对象缓存回填）行为微妙；
- REST / WP-CLI 输出也会变成译文。

建议加语境限制（`! is_admin() && ! wp_doing_ajax() && ! ( defined('REST_REQUEST') && REST_REQUEST ) && ! wp_doing_cron()`），或改为只在渲染层（`single_term_title` / `term_description` / `get_the_terms` 输出处）翻译。

### P1-6（已撤销，审查误判）

初稿判断「`wp-login.php` 不触发 `init`，所以 `/en/wp-login.php` 不会跳回源站」。复查 `wp-settings.php` 后确认：`wp-login.php` 顶部 `require wp-load.php`，而 `wp-settings.php` 末尾无条件执行 `do_action( 'init' )` 与 `do_action( 'wp_loaded' )`，因此 `init` 在该请求中同样会触发，重定向逻辑有效。原文结论作废，无需改动。

## 四、P2 问题

| # | 位置 | 问题 | 建议 |
| --- | --- | --- | --- |
| 1 | `class-translation-store.php:46` | `find_valid()` 无缓存，标题/摘要/alt/term/链接每次渲染都发 1 条 SQL，归档页轻松几十条 | 加 `wp_cache_get/add`（非持久组，请求内有效）或 `static` 数组，命中率接近 100% |
| 2 | `class-content-controller.php:338` | 每次 `translated_value()` 都 `new HTBD_Baidu_Provider()` 只为取常量 `baidu-vip-v1`；`HTBD_Settings::get()` 同一请求被调上千次（每次 `wp_parse_args`） | provider 版本改类常量；`HTBD_Settings::get()` 加请求级静态缓存，`update_option` 时清 |
| 3 | `class-sitemap-controller.php:62` | 用 `wp_safe_remote_get` 请求自己的 `home_url()/sitemap.xml`；单 worker PHP-FPM 会自锁超时；5MB XML 存 `wp_options` transient | 改为内部生成或走 loopback 白名单 + 只缓存 URL 列表（小体积） |
| 4 | `class-translation-store.php:12` | `install()` 只在激活时跑，无 `htbd_db_version`；重命名 legacy 表后 `dbDelta` 无法补齐旧表结构差异 | 增加版本号 + `plugins_loaded` 升级检查；legacy 迁移改为「建新表 + INSERT SELECT」 |
| 5 | `class-translation-store.php:64` | `REPLACE` 每次覆盖 `created_at`，且 delete+insert 会消耗自增 ID | 改 `INSERT ... ON DUPLICATE KEY UPDATE`，不更新 `created_at` |
| 6 | `class-translation-store.php:174,209` | `SUBSTRING_INDEX(field_context,':',2) IN (...)` + `LIKE` 无法走索引；上万 ID 时 SQL 极长 | 增加 `item_type`/`item_id` 两列并建索引，进度统计直接按列聚合 |
| 7 | `class-content-controller.php:371` | SEO 任务把整段原文塞进 cron args，序列化进 `cron` option | args 只传 `post_id` + 语言 + 上下文，正文在执行时再取 |
| 8 | `class-baidu-provider.php:75` | `sanitize_key($context)` 会把 `post:12:post_content` 变成 `post12post_content`（冒号被删），与设置页文案「field」不符 | 用 `sanitize_text_field` 或自定义白名单 |
| 9 | `class-admin.php:154` | 允许 `routing_mode` 为空数组、`target_languages` 为空 → 插件静默失效，无任何提示 | 保存时校验至少一种路由模式 ≥ 1 种目标语言，否则 `add_settings_error` |
| 10 | `class-settings.php:54` | `is_supported_language('auto')` 在上游为真，`auto` 被当成合法语言参与校验 | 显式排除 `auto` |
| 11 | `class-admin.php:201` | 密钥回填进 `type=password` 的 `value`，明文出现在后台 HTML | 改为掩码 + 「重新输入密钥」流程，或仅在常量/环境变量存在时隐藏 |
| 12 | `assets/js/admin.js:111` | 每 5 秒无条件轮询，页面隐藏/任务完成后继续跑；nonce 24h 过期后永久停留在错误提示 | `document.hidden` 暂停、`percent===100 && !failed.length` 停表、错误态指数退避 |

### 其他零散项

- `class-plugin.php:40` 与 `class-settings.php:7` 与 `class-admin.php:170` 三处各自维护一份默认值，容易漂移 → 抽 `HTBD_Settings::defaults()`。
- `class-admin.php:183-277` 大段单行 HTML echo，可读性与 WPCS 行宽都吃亏 → 用 `?>`/`<?php` 模板化。
- `class-uninstaller.php:61` 无条件 `DROP TABLE btranslate_translations`，若用户还在用原版插件会误删 → 增加「是否由本插件创建」的标记判断。
- `class-content-controller.php:223` 的 `$taxonomy` 参数未使用；`class-sitemap-controller.php:7` 的可变状态 `$this->target_language` 建议改为参数传递。
- 全仓没有任何自动化测试。Provider 接口已经隔离好，是最适合补测试的地方：用 fake provider 覆盖 identity 生成、缓存复用/失效、路由前缀改写、`segments()` 保护 `<pre>/<code>`。`AGENTS.md` 已声明「不引入 Composer 依赖」，至少可以加一个不依赖 WP 的 `tests/` + 手写 runner。

## 五、新功能建议

按「投入产出比」排序：

| 优先级 | 功能 | 说明 | 主要改动点 |
| --- | --- | --- | --- |
| 1 | **hreflang / canonical 输出** | 见 P0-2，补齐 README 已承诺的 SEO 能力；顺便给 sitemap 加 `xhtml:link` alternates | 新增 `class-seo-head.php`，挂 `wp_head`；复用 `router->localized_url()` |
| 2 | **批量 + 节流 + 重试的翻译管线** | 见 P1-4，直接把 API 调用量降到 1/N，并消灭 `54003` 类失败 | `class-baidu-provider.php` 支持多行 `q`；`class-translation-service.php` 增加 batch 接口 |
| 3 | **语言切换器** | 目前用户无法在页面间切换语言，只能手改 URL，这是多语言插件最基础的用户路径 | Block（`block.json`）+ `[htbd_language_switcher]` 短代码 + 导航菜单项，输出 `hreflang`/`lang` 属性 |
| 4 | **WP-CLI 命令** | `wp htbd translate --all --language=en`、`wp htbd status`、`wp htbd export/import`，绕开后台超时，方便迁移与批量运维 | `includes/class-cli.php`，仅 `defined('WP_CLI')` 时加载 |
| 5 | **术语表 / 不翻译列表** | 品牌名、产品名、代码标识符经常被翻坏，这是用户投诉第一名 | 设置页新增 `glossary` 列表；发送前用占位符保护（`{{HTBD_n}}`），返回后回填 |
| 6 | **自定义文章类型与分类法** | 目前硬编码 `post`/`page` + `category`/`post_tag`，WooCommerce/自定义 CPT 站点完全用不了 | 设置页多选；`is_translatable_post_type()` 统一判定，替换 4 处硬编码 |
| 7 | **翻译质量工作流** | 已支持手工改译文，但缺少「原文/译文对照 + 未翻译项高亮 + 批量重试失败项」 | 编辑器侧栏面板（`PluginDocumentSettingPanel`）+ 后台「重试全部失败」按钮 |
| 8 | **健康检查页** | 一键测试凭据、检测 rewrite 是否刷新、检测 sitemap 是否可达、显示 cron 积压数 | 设置页新增 tab，复用 `HTBD_Baidu_Provider` 发一条最短测试请求 |

## 六、README 变更

已完成（`README.md`、`README.zh-CN.md`）：

1. 审核状态由「尚未通过、只能手动安装」改为「已通过并上架 WordPress.org」，附目录链接；
2. 安装章节新增「方法一：后台直接安装（推荐）」，原 ZIP 上传 / 服务器上传顺延为方法二 / 方法三；
3. 未改动 `readme.txt`（其中本就没有审核状态描述），因此 `languages/wp-plugins-…-readme-zh-cn.po` 无需同步；若后续改动 `readme.txt` 正文，必须同步该 PO。

## 七、实施状态（0.4.0）

已修复：P0-1（`single_term_title` 参数）、P0-2（hreflang / canonical 补齐）、P1-1（纯文本 key 不一致）、P1-2（cron 参数统一）、P1-3（后台操作提示）、P1-4（批量 + 限速 + 重试）、P1-5（`get_term` 语境限制），以及 P2 中的 `find_valid()` 缓存、`REPLACE` 覆盖 `created_at`、`sanitize_key` 破坏 context、空配置静默失效、`auto` 参与语言校验。

新增功能：hreflang 与 sitemap alternates（功能 1）、批量限速管线（功能 2）、语言切换器（功能 3）、WP-CLI（功能 4）、自定义文章类型与分类法（功能 6）、诊断面板（功能 8）。

剩余项已于同一天全部处理：

- sitemap 自请求 → 改为 `HTBD_Cron::SITEMAP_REFRESH` 异步抓取 + 15 分钟缓存 + 首次请求返回最小 sitemap（`tests/sitemap-fallback.php`）。
- `SUBSTRING_INDEX` 全表扫描 → 新增 `item_type` / `item_id` 列与 `item_lookup` 索引，进度、失败、列表列查询全部改用索引列。
- SEO 任务把正文塞进 cron args → 改为 transient 传值，args 只留键名。
- `install()` 无升级路径 → 新增 `htbd_db_version` 与 `maybe_upgrade()`，升级时 `dbDelta` + 回填历史行。
- 后台 JS 每 5 秒轮询 → 完成后停表、隐藏暂停、失败指数退避（上限 60s）。
- 密钥明文回填 → 输出空值 + 占位提示 + 「删除已保存密钥」开关，留空保留旧值。
- 卸载误删 legacy 数据 → 新增迁移标记，仅在本插件接管过数据时才清理 `btranslate_*`。
- `normalize_domain` 拒绝本地主机 → 支持 `localhost` 与 IPv4（`tests/domain-normalize.php`）。
- `the_title` 二次转义 → 改为 `wp_strip_all_tags()` 后返回纯文本。

测试覆盖从 2 个文件扩到 6 个：`provider-batching`、`cron-scheduling`、`sitemap-fallback`、`store-context`、`domain-normalize`、`language-router`。路由层通过假 `HTBD_Settings` 与桩函数覆盖了前缀剥离、子目录与域名 URL 生成、反向还原源站地址、以及从 query var 解析当前语言，共 18 项断言。

唯一仍未覆盖的是 `redirect_admin_request_to_source()`：它在命中时会 `wp_redirect()` 并 `exit`，无法在进程内断言，只能靠真实环境验证。
