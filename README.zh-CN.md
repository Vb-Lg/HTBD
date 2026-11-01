# HTBD：基于百度翻译 API 的免费开源 Wordpress 多语言插件

[![GitHub 仓库](https://img.shields.io/badge/GitHub-HTBD-181717?logo=github)](https://github.com/hanyixuanten/HTBD)

HTBD 是一款 WordPress 多语言翻译插件，通过百度翻译开放平台 API，将网站中的文章、页面及其他内容翻译为不同语言，并为译文生成独立的语言访问地址。

插件会将翻译结果保存在服务器的 `{$wpdb->prefix}hyx_bd_translations` 表中。除非用户主动刷新翻译，否则同一内容在同一目标语言下只需翻译一次。启用改名后的插件时，已有 `{$wpdb->prefix}btranslate_translations` 数据会自动迁移，无需在每次访问页面时重复调用翻译 API，有助于减少 API 请求量并提高页面加载速度。

## 项目地址

- 插件主页：[https://www.vblg.top/index.php/archives/147](https://www.vblg.top/index.php/archives/147)
- GitHub 仓库：[https://github.com/hanyixuanten/HTBD](https://github.com/hanyixuanten/HTBD)
- WordPress 插件主页：[https://wordpress.org/plugins/hyx-translator-for-baidu-translate/](https://wordpress.org/plugins/hyx-translator-for-baidu-translate/)

> **审核状态：** HTBD 已通过 WordPress.org 插件目录审核，并已上架 [wordpress.org/plugins/hyx-translator-for-baidu-translate](https://wordpress.org/plugins/hyx-translator-for-baidu-translate/)。推荐直接在 WordPress 后台的「插件」→「安装插件」中搜索安装。GitHub Releases 仍保留，用于手动安装和历史版本下载。

## 主要功能

### 百度翻译 API 集成

HTBD 使用百度翻译开放平台提供的翻译 API。用户可以在 WordPress 后台配置自己的 App ID 和密钥，由插件完成请求签名、翻译调用及错误处理。

### 文章、页面与分类项翻译

插件支持翻译后台选定的任意公开文章类型与公开分类法中的主要内容，包括：

- 标题
- 正文
- 摘要
- SEO 字段
- 图片替代文本
- 标签
- 分类目录与自定义分类法中的分类项
- 部分界面文本

### 多语言访问地址

HTBD 可以为不同语言生成独立的访问地址，支持：

- 语言子目录，例如 `/en/`、`/jp/`
- 不同语言绑定不同域名
- 根据配置生成对应语言的固定链接

### 语言切换器

访客无需手动修改地址即可切换语言：

- `[htbd_language_switcher]` 短代码，支持 `format="list"` 与 `format="inline"`
- 面向区块主题的 **HTBD language switcher** 区块
- 面向不支持区块主题的传统小工具

每个链接指向当前页面对应语言的地址，保留查询参数，并用 `aria-current` 标记当前语言。

### 浏览器语言提示

在“设置 > HTBD”中启用后，若访客浏览器偏好另一种已配置的语言，页面会显示一条可关闭的提示，提供当前页面对应语言的版本：

- 判断在浏览器端完成，因此被缓存的页面不会把某位访客的提示显示给其他人。
- 不会自动跳转。提示中是一个指向该语言同一页面的真实链接，每种语言仍保留自己的规范地址。
- 选择结果记录在 `localStorage`，同一语言下不再重复提示。
- 在订阅源、嵌入页、预览页、404 响应以及仅配置一种语言的站点上不会渲染；浏览器禁用 JavaScript 时该提示始终隐藏。

### 翻译结果持久化

翻译完成后，结果会保存在 WordPress 服务器中。后续访问相同译文时，插件优先读取已经保存的结果，而不是重新请求百度翻译 API。

当原文发生变化时，对应译文会被标记为需要更新，从而避免继续使用与原文不一致的旧译文。

### WordPress 内容兼容

插件在翻译内容时会尽量保留：

- Gutenberg 区块标记
- HTML 结构
- Shortcode 短代码
- 占位符
- URL
- `<pre>` 和 `<code>` 元素内的代码内容（不翻译）
- 受保护的特殊内容

### 批量与限速请求

同一篇文章的多个文本段会尽量合并为一次百度多行请求，而不是每段各发一次。请求之间按配置的间隔限速，避免超出免费版的每秒调用限制；可恢复的服务端错误会自动重试。

### 多语言 SEO

HTBD 为多语言页面提供必要的 SEO 支持，包括：

- 多语言固定链接
- Canonical URL
- 单页与本地化站点地图中的 `hreflang` 多语言链接
- 指向源语言的 `x-default`
- SEO 字段翻译

### 失败回退

如果百度翻译 API 请求失败，插件不会使网站页面报错：

- 如果没有可用译文，则显示网站的源语言内容。
- 如果存在以前成功保存的译文，则继续使用旧译文。

### 诊断

设置页内置诊断面板，可检查翻译数据表、固定链接结构、语言访问地址路由、计划任务队列，并通过一次短测试请求验证百度凭据。

## WP-CLI

安装 WP-CLI 后，可以脱离后台管理站内译文：

```bash
# 每次 20 篇，把已发布文章翻译为英文
wp htbd translate --language=en --limit=20

# 重新翻译单篇文章
wp htbd translate --post_id=42 --force

# 翻译分类目录与标签
wp htbd translate-terms --taxonomy=category

# 查看配置、排队任务与已存行数
wp htbd status

# 在站点之间迁移已存译文
wp htbd export translations.csv
wp htbd import translations.csv --dry-run
```

## 快速开始

### 一、获取百度翻译 API

1. 打开[百度翻译开放平台](https://fanyi-api.baidu.com/)。
2. 注册或登录百度账号。
3. 进入管理控制台。
4. 开通通用文本翻译 API 服务。
5. 创建应用并填写应用信息。
6. 在应用管理页面获取 **APP ID** 和 **密钥**。

> APP ID 和密钥属于敏感信息，请勿提交到公开的 GitHub 仓库，也不要通过网页前端代码或公开日志暴露。

百度翻译 API 的具体开通方式、计费规则和调用限制，请参考[百度翻译 API 官方文档](https://fanyi-api.baidu.com/doc/23)。

### 二、安装插件

HTBD 已上架 WordPress.org 插件目录，可通过 WordPress 后台直接安装并由后台统一更新。

#### 方法一：在 WordPress 后台直接安装（推荐）

1. 登录 WordPress 管理后台。
2. 打开「插件」→「安装插件」。
3. 搜索 **HTBD** 或 **hyx Translator for Baidu Translate**。
4. 点击「立即安装」，然后点击「启用」。

#### 方法二：上传 ZIP 压缩包

1. 访问 [HTBD GitHub 仓库](https://github.com/hanyixuanten/HTBD)。
2. 点击 **Releases**。
3. 从最新版本下载 zip 文件 **hyx-translator-for-baidu-translate-*.zip**
4. 登录 WordPress 管理后台。
5. 打开“插件” → “安装插件”。
6. 点击“上传插件”。
7. 选择下载的 ZIP 文件并开始安装。
8. 安装完成后启用 HTBD。

#### 方法三：上传到服务器

1. 访问 [HTBD GitHub 仓库](https://github.com/hanyixuanten/HTBD)。
2. 点击 **Releases**。
3. 从最新版本下载 zip 文件 **hyx-translator-for-baidu-translate-*.zip**
4. 将 `hyx-translator-for-baidu-translate` 目录上传到 WordPress 的插件目录： `wp-content/plugins/hyx-translator-for-baidu-translate/`
5. 登录 WordPress 管理后台。
6. 打开“插件” → “已安装插件”。
7. 找到 HTBD 并点击“启用”。

### 三、配置插件

1. 登录 WordPress 管理后台。
2. 打开 HTBD 设置页面。
3. 填写百度翻译开放平台提供的 **APP ID** 和 **密钥**。
4. 设置网站的源语言，或勾选“自动识别源语言”以使用百度翻译的 `auto` 源语言模式。
5. 添加需要启用的目标语言。
6. 选择语言 URL 模式，例如语言子目录或域名绑定。
7. 选择需要翻译的文章类型与分类法。
8. 如套餐允许每秒多次调用，可调整请求间隔。
9. 保存设置。

修改语言路由设置后，建议在 WordPress 后台打开“设置” → “固定链接”，确认固定链接配置已经正确生效。

自动识别状态使用独立选项保存。未勾选自动识别时在源语言输入框中主动填写 `auto`，仍属于不同的配置状态。

### 四、开始使用

1. 在 WordPress 后台打开需要翻译的文章或页面。
2. 保存或更新内容。
3. 使用 HTBD 提供的翻译功能生成目标语言译文。
4. 等待翻译任务完成。
5. 通过对应语言的访问地址检查译文。

例如，假设源语言页面地址为：

```txt
https://example.com/about/
```

英语使用 `/en/` 子目录时，译文地址为：

```txt
https://example.com/en/about/
```

日语使用 `/jp/` 子目录时，译文地址为：

```txt
https://example.com/jp/about/
```

通过目标语言子目录或绑定域名请求 `wp-login.php`、`wp-admin` 时，插件会重定向到源站对应地址，并保留查询参数。

插件会优先使用已保存的译文。只有在原文发生变化、译文失效或用户主动要求刷新时，才需要重新调用翻译 API。

## 使用建议

- 正式启用前，请先在测试环境中检查固定链接和多语言路由。
- 为百度翻译 API 设置合理的调用额度和安全策略。
- 不要在源代码、前端 JavaScript或公开日志中保存 API 密钥。
- 发布译文前，建议人工检查品牌名、专业术语和重要页面。
- 修改原文后，应检查对应语言的译文是否需要更新。
- 配置域名绑定时，需要同时完成 DNS、HTTPS 证书和服务器域名设置。
- 修改语言路由后，请检查 Canonical URL、`hreflang` 和站点地图是否正确。
- 更换主机、固定链接或凭据后，运行一次诊断面板。

## 系统要求

- WordPress 6.4 或更高版本
- PHP 8.1 或更高版本
- 可正常访问百度翻译 API 的服务器网络环境
- 有效的百度翻译开放平台 APP ID 和密钥

## 开发

```bash
# 检查全部 PHP 语法
find . -path './.git' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l

# 运行独立检查：不需要 WordPress、网络或 API 凭据
for test in tests/*.php; do php "$test" || exit 1; done

# 构建安装包，并校验插件头与 readme 的版本号是否一致
./build.sh build
```

`tests/` 下的检查通过桩函数替换 WordPress 调用，因此在任何装有 PHP 的机器上都能运行，覆盖批量翻译、计划任务参数、站点地图回退、URL 改写、域名归一化、翻译上下文解析、浏览器语言匹配，以及区块编辑器使用的 PO 到 JED JSON 转换。

脚本译文与 PHP 译文是两条独立通道：区块编辑器通过 `wp_set_script_translations()` 读取 JED JSON，完全不会读取 MO 文件。因此 `build.sh` 会用 `tools/make-json.php` 从 PO 文件生成 `languages/hyx-translator-for-baidu-translate-zh_CN-htbd-language-switcher-block.json`，并在归档中缺少该文件时直接报错退出。也就是说，直接克隆仓库得到的目录里既没有 MO 文件也没有 JSON 文件，必须运行过一次 `./build.sh build`。

## 问题反馈与贡献

如果在使用过程中遇到问题，或者希望提交功能建议，可以前往 GitHub 仓库反馈：

[https://github.com/hanyixuanten/HTBD](https://github.com/hanyixuanten/HTBD)

提交问题时，建议提供以下信息：

- WordPress 版本
- PHP 版本
- HTBD 版本
- 使用的语言和路由模式
- 问题复现步骤
- 已隐藏敏感信息的错误日志

欢迎通过 Issue 或 Pull Request 参与 HTBD 的改进。
