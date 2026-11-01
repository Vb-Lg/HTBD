# HTBD 全仓代码质量 / 新功能审查（v1.0.0 之后）

- 审查基线：`e6f87b3`（tag `v1.0.0`，master）
- 审查范围：`hyx-translator-for-baidu-translate.php`、`includes/` 全部 19 个文件、`assets/js/*.js`、`tests/` 6 个脚本、`uninstall.php`、`build.sh`、`.github/workflows/`、`README.md` / `README.zh-CN.md` / `readme.txt` / `languages/*.po`
- 方法：逐文件静态审查 + 钩子签名核对 + 可执行探针复现（`/tmp` 下临时脚本，未入库）+ 构建与测试实跑

## 一、结论概览

| 级别 | 数量 | 含义 |
| --- | --- | --- |
| P0 | 0 | 无 500 / 违反插件目录规范的问题 |
| P1 | 2 | 功能实际不生效、或产生重复收录 |
| P2 | 5 | 一致性、数据膨胀、性能、文档承诺落空 |
| P3 | 5 | 健壮性、死代码、验证盲区 |

v0.3.1 → v1.0.0 那一轮的 P0/P1/P2 已全部落地，且落地质量可以核对：`single_term_title` 签名已改默认值、hreflang/canonical 已实现、cron 参数统一、批量+限速+重试已就位、sitemap 改为异步抓取、`item_type`/`item_id` 索引列已加。

本轮新发现的问题集中在**一个模式**上：**同一条「按段切分并编号」的规则被复制了三份**，而其中一份（后台手动翻译编辑器）漏了 `<pre>/<code>` 保护深度，导致编号漂移。这是本轮唯一会让功能"静默不生效"的缺陷。

## 二、P1 问题

### P1-1 手动翻译编辑器的段落下标漂移 → 手工译文静默不生效

同一段内容在三个地方各自走一遍分段循环：

| 位置 | 循环 | 是否跟踪 `protected_depth` |
| --- | --- | --- |
| 写入（cron） | `includes/class-content-translator.php:22-38` | 是 |
| 读取（前台渲染） | `includes/class-content-controller.php:213-228` | 是 |
| 读取（后台编辑框） | `includes/class-admin.php:140-149` | **否** |

`class-admin.php:142` 只判了 `is_tag() || '' === trim()`，没有调用 `HTBD_Content_Translator::protected_depth_after_tag()`。

实测（`<p>Intro</p><pre><code>echo 1;</code></pre><p>Outro</p>`）：

```
cron   : text:0 => Intro | text:1 => Outro
admin  : text:0 => Intro | text:1 => echo 1; | text:2 => Outro
render : text:0 => Intro | text:1 => Outro
cron/render AGREE
cron/admin DIVERGE
```

后果有两层，都是静默的：

1. 编辑框里显示的是「译文 A 搭配原文 B」的错误对照（第 2 段显示代码块内容，却挂着第 3 段的译文），用户看到的就是错的；
2. 保存时 `save_translation_meta_box()` 用偏移后的 `$field['source_value']` + 偏移后的 context 计算 identity，落库的 `manual` 行与渲染端查的 key 不同 → 手工译文**永远不会出现在前台**，且不报错。只要文章里出现过 `<pre>`/`<code>`，其后所有段落的手工翻译全部失效。

修复建议：把「分段 → (text, context_index)」抽成 `HTBD_Content_Translator` 的一个静态方法（例如 `segments_with_context( $content )`），三处调用同一实现，彻底消掉复制粘贴。参考实现：

```php
/**
 * @return array<int, array{text: string, index: int}> 或 false（解析失败）
 */
public static function translatable_segments( $content ) {
	$segments = self::segments( $content );

	if ( false === $segments ) {
		return false;
	}

	$items  = array();
	$index  = 0;
	$depth  = 0;

	foreach ( $segments as $segment ) {
		if ( self::is_tag( $segment ) ) {
			$depth = self::protected_depth_after_tag( $segment, $depth );
			continue;
		}

		if ( 0 < $depth || '' === trim( $segment ) ) {
			continue;
		}

		$whitespace = self::surrounding_whitespace( $segment );
		$items[]    = array(
			'text'  => $whitespace['text'],
			'index' => $index,
		);

		++$index;
	}

	return $items;
}
```

三处改为消费这个结果。回归测试见下文「测试盲区」。

### P1-2 域名绑定 + 子目录同时开启时，同一译文有两个自指 canonical 的 URL

`HTBD_Settings::subdirectory_languages()`（`class-settings.php:82`）直接返回全部目标语言，没有排除已被域名绑定的语言。于是当 `routing_mode = ['subdirectory','domain']` 且 `en.example.com = en` 时，英文同时可从两个地址访问且各自 canonical 到自己：

```
page https://example.com/en/about/   self URL = https://example.com/en/about/
page https://en.example.com/about/   self URL = https://en.example.com/about/
sitemap/hreflang URL for en               = https://en.example.com/about/
```

即：hreflang 与 sitemap 只承认域名版，但 `/en/` 那份照样 200、照样自指 canonical → 每个英文页被搜索引擎收录两份。这同时违反 `AGENTS.md` 的硬性约定「Avoid serving the same translation at multiple canonical URLs」。

顺带说明：`resolve_domain_language()` 对「绑定域名 + 语言前缀」返回 404 是对的，但反向（源站上的语言前缀）没有对应处理。

修复建议：把被绑定的语言从子目录集合里剔除，一处改动即可同时修好路由、canonical、sitemap、切换器：

```php
public static function subdirectory_languages() {
	$settings = self::get();
	$bound    = empty( $settings['domain_bindings'] ) || ! self::is_routing_mode_enabled( 'domain' )
		? array()
		: array_values( array_map( 'sanitize_key', (array) $settings['domain_bindings'] ) );

	return array_values( array_diff( self::target_languages(), $bound ) );
}
```

并补一条测试：`/en/` 在语言被绑定域名时必须 404（或 301 到域名版，二选一，写进 README）。

## 三、P2 问题

### P2-1 `HTBD_Content_Translator::translate()` 返回的是未翻译的源文

`class-content-translator.php:52` 最终 `return HTBD_Translation_Result::success( $content );` —— 段落译文全部写进了 store，但返回值是原内容。目前唯一调用方（`class-content-controller.php:94`）丢弃了返回值，所以线上没有症状；但这个方法名与返回值给了错误的契约，后续任何人把它接到渲染路径上就会"翻译无效"。

建议改名以表明副作用语义（`warm()` / `prime_store()`），返回结果数组或计数；或者反过来——让它成为唯一实现并应用译文，让 `translate_content()` 只负责查缓存渲染。后者能顺带消灭 P1-1 的三份副本。

### P2-2 `fallback_language` 是死设置，文档承诺落空

`class-settings.php:24` 定义默认值、`class-admin.php:245` 清洗保存，但全仓**没有任何读取点**（`grep -rn fallback_language` 仅命中这两行），设置页也没有对应输入框——所以它永远被重置成 `zh`。

而 `README.md:86-91`「Failure Fallback」写的是"显示源语言内容"，`AGENTS.md` 写的是"render the configured source-language fallback"。二者目前实际行为一致（都是源文），但多出一个永远为 `zh` 的配置项，属于「配置项存在 = 功能存在」的误导。

二选一：删掉该键（defaults + sanitize 两处），或实现它（无译文时按 `fallback_language` 路由）。建议后者放进术语表那一轮一起做。

### P2-3 没有删除生命周期，译文表只会增长

全仓无 `deleted_post` / `before_delete_post` / `delete_term` 钩子。加上内容每次变更都会因 `source_fingerprint` 变化而产生新 identity，旧行既不复用也不回收：

- 删文章 → 行留下（`get_failed_items`/统计里还会出现幽灵条目）；
- 改文章 → 旧指纹行永久残留，`wp htbd status` 的计数随之失真；
- 唯一清理手段是后台「清空翻译缓存」（全库 DELETE）。

建议：挂 `deleted_post`/`deleted_term` 按 `item_type + item_id` 删除；再加 `wp htbd prune --older-than=30d` 清理指纹已失效的历史行。需要 `class-translation-store.php` 增一个 `delete_items( $item_type, $item_ids )`，用已有的 `item_lookup` 索引即可。

### P2-4 后台两处 N+1 查询

1. `class-admin.php:118-159` `get_post_translation_fields()`：对「每个目标语言 × 每个字段 × 每个段落」各发一次 `find_valid()`。一篇 40 段、3 目标语言的文章 ≈ 120+ 次查询，而且**在每次打开编辑页时都会跑**，即使一条译文都没有（先查完再显示「请先生成翻译」）。
2. `class-admin.php:527-558` `render_translation_column()`：列表每行 × 每个语言一次 `get_post_language_status()`，20 行 × 3 语言 = 60 次。

建议：段落用一次 `SELECT ... WHERE item_type='post' AND item_id=%d AND target_language IN (...)` 批量取回再内存匹配（`item_lookup` 索引已覆盖）；列表页用一次 `GROUP BY item_id, target_language` 预取当前页所有 ID。

### P2-5 目标语言不做校验，拼错即静默全站不翻译

`class-admin.php:205` 只做 `sanitize_key`，不校验是否为百度支持的语言码。用户把 `en` 写成 `eng`，会得到：`/eng/` 路由可访问、`is_supported_language('eng')` 为真、发出去百度回 `58001`（不支持的语言方向）→ store 写入 `failed` 行、前台永远显示中文。诊断面板的 `check_provider()` 只测 `languages[0]`，`/eng/` 排在后面就查不出来。

建议：内置一份百度语言码白名单（`hreflang_code()` 的 map 已覆盖大部分），在 `sanitize_settings()` 里对非法码 `add_settings_error()`；`check_provider()` 改为逐语言测试或至少测「最后一个」。

## 四、P3 问题

| 编号 | 问题 | 位置 | 建议 |
| --- | --- | --- | --- |
| P3-1 | `translate_post()` / `translate_term()` 只判 `is_supported_language()`，而该函数对源语言也返回 true，target == source 时会照常调用 API | `class-content-controller.php:61,121` | 加 `$target_language === $settings['source_language']` 早退 |
| P3-2 | 限速用的是 `private static $last_request`，仅在同一进程内有效；每个 cron 事件是独立进程，跨事件毫无节流 | `class-baidu-provider.php:25,254` | 若在意免费版 1 req/s，用 transient 存上次请求时间戳；否则在 README 里说明"仅单请求内生效" |
| P3-3 | 诊断面板会同步自请求站点（5s 超时）。这与 sitemap 当初"避免自请求造成单 worker 死锁"的设计相矛盾，且阻塞后台 POST | `class-health-check.php:143-150` | 改为 cron + transient 回填结果，与 sitemap 同一套模式 |
| P3-4 | 死代码：`htbd_retranslation_batch` 只删不建；`batch_started_at` 进了 AJAX 响应但 `admin.js` 从不读；`register_rewrite_rules()` 是空方法却仍在激活时被调用并触发 `flush_rewrite_rules()` | `class-admin.php:468,514`、`class-language-router.php:85` | 删掉前三者；flush 只在真正注册了规则时保留（或直接删掉，因为本插件靠 `do_parse_request` 剥前缀，不靠 rewrite） |
| P3-5 | `render_admin_notices()` 直接读 `$_GET` 且无 nonce 校验 | `class-admin.php:45-67` | 纯展示、不改变状态，可接受；若要过 PHPCS 严格规则，加 `// phpcs:ignore` 说明或改走 transient 闪存 |

## 五、测试与验证盲区

现有 6 个脚本全部实跑通过（`cron-scheduling` 9 项、`language-router`、`provider-batching`、`sitemap-fallback`、`domain-normalize`、`store-context`），`php -l` 全仓无语法错误，`./build.sh build` 成功产出 zip 并通过全部归档断言（已 `build.sh clean` 清理）。

但覆盖面和风险不匹配——**本轮两个 P1 全在测试盲区里**：

| 缺口 | 建议新增 | 能防住 |
| --- | --- | --- |
| 分段编号一致性 | `tests/content-segments.php`：对含 `<pre>`/`<code>`/空白的 HTML，断言 cron / 渲染 / 后台三条路径产出的 `(text, index)` 序列**完全相同** | P1-1 |
| 路由互斥 | 在 `tests/language-router.php` 追加：绑定域名的语言不得出现在 `subdirectory_languages()`；`/en/` 在绑定场景下的响应 | P1-2 |
| Service 层 | `tests/translation-service.php`：命中缓存时不调 provider、`force_refresh` 生效、失败时保留旧译文、成功时写 `complete` | 缓存复用/失效回归 |
| Store 层 | `tests/store-save.php`：`ON DUPLICATE KEY UPDATE` 保留 `created_at`、`split_context` 与索引列一致 | 数据层回归 |
| 设置清洗 | `tests/settings-sanitize.php`：密钥留空保留旧值、`clear_baidu_secret_key` 生效、非法语言码被拒 | P2-2 / P2-5 |

## 六、下一轮功能建议（按投入产出排序）

| 优先级 | 功能 | 理由 | 主要改动点 |
| --- | --- | --- | --- |
| 1 | **术语表 / 不翻译保护词** | 这是多语言插件投诉率第一的功能（品牌名、产品名、代码标识符被翻坏），且能给 P1 收尾做完整交付 | 设置页新增词表；发送前替换为 `{{HTBD_n}}` 占位符，返回后回填；identity 需包含词表版本，词表变更即失效 |
| 2 | **翻译质量工作流** | 手工编辑已存在但不可用（P1-1）。补「原文/译文对照 + 未翻译高亮 + 一键重试全部失败」才算闭环 | 修 P1-1 后，用 `PluginDocumentSettingPanel` 重做侧栏；失败列表加「全部重试」 |
| 3 | **译文生命周期管理** | 对应 P2-3：残留行会拖垮统计与查询 | `deleted_post`/`deleted_term` 钩子 + `wp htbd prune` + 行级 `status='stale'` |
| 4 | **每语言翻译覆盖率报表** | store 已有 `get_completed_item_counts()` / `get_status_counts()`，只差展示；对"哪些页面还没翻"最有说服力 | 设置页加一张按语言 × 文章类型的矩阵 |
| 5 | **导航菜单语言项** | 切换器已有 shortcode / block / widget，缺 `wp_nav_menu_items`，而多数主题的语言切换实际挂在菜单里 | 复用 `HTBD_Language_Switcher::links()` |
| 6 | **结构化数据** | 多语言站点的 `inLanguage` / `WebPage` 标记，SEO 收益直接 | 在 `HTBD_SEO_Head` 增 JSON-LD，跟随 `current_language()` |

## 七、仓库状态

- `CODE_REVIEW.md` 与 `RELEASE_NOTES-1.0.0.md` 在 v1.0.0 提交里，当前工作区处于**未提交的删除状态**（`git status` 显示两个 `D`）。本轮审查把本文件重写为 v1.0.0 之后的这一轮记录；`RELEASE_NOTES-1.0.0.md` 的删除请自行决定提交或 `git restore`。
- 版本一致性核对通过：插件头 `Version: 1.0.0`、`HTBD_VERSION`、`readme.txt` 的 `Stable tag`、issue 模板与 SVN workflow 示例均为 1.0.0，无残留旧版本号。
- `languages/` 三个文件 `msgfmt -c` 全部通过（`zh_CN` 125 条 msgid，readme 中文 PO 62 条 msgid），无 fuzzy/format 错误。
