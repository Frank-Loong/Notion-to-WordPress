=== Notion·to-WordPress ===
Contributors: Frank-Loong
Donate link: https://github.com/Frank-Loong/Notion-to-WordPress
Tags: notion, import, content, sync, database, api, mathjax, mermaid
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

== Description ==
Notion·to-WordPress 是一款专业的WordPress插件，实现Notion数据库内容与WordPress网站的高效同步，支持自动/手动同步、Webhook、数学公式、Mermaid图表等高级特性。

= 主要功能 =
* 自动/手动同步Notion数据库内容到WordPress
* 支持Webhook，Notion内容变更时自动触发同步
* 兼容Katex数学公式、Mermaid流程/序列图、代码块、表格、图片等
* 字段映射：自定义Notion属性与WordPress字段的对应关系
* 分类/标签/特色图片自动识别与同步
* 并发锁机制，防止重复导入
* 多语言支持，内置i18n国际化
* 严格的权限与nonce校验，保障数据安全
* 可选彻底删除所有同步内容与设置

== Installation ==
1. 上传插件到 `/wp-content/plugins/` 目录
2. 在WordPress后台"插件"页面激活
3. 进入"Notion到WordPress"设置页，填写API密钥与数据库ID
4. 配置同步计划、字段映射等高级选项
5. 保存设置，点击"手动同步"或等待自动同步

== Usage ==
- 手动同步：后台点击"手动同步"按钮，立即导入全部内容
- 自动同步：根据设置的计划自动同步，无需人工干预
- Webhook：配置Webhook后，Notion内容变更可实时推送到WordPress
- 字段映射：支持自定义Notion属性与WP字段的映射，适配不同数据库结构
- 内容管理：同步后内容可在WordPress后台正常编辑、发布、删除

== Frequently Asked Questions ==
= 数学公式/图表无法正常显示？ =
请确保主题支持自定义CSS/JS，且未与其他公式/图表插件冲突。

= 如何只同步部分内容？ =
可在Notion端设置筛选视图，或自定义字段映射与过滤逻辑。

= 卸载插件会删除哪些数据？ =
可选删除所有同步内容、设置及相关元数据，详见"卸载设置"。

= 支持多站点/多数据库吗？ =
支持多站点环境，单实例建议对应一个Notion数据库。

== Screenshots ==
1. 管理界面 - 同步管理
2. 设置页面 - API配置
3. 内容显示 - WordPress前端
4. Webhook设置 - 自动更新配置

== Changelog ==
= 1.1.0 =
* 重大架构重构：引入ImportCoordinator和策略模式，提升代码可维护性
* 修复同步逻辑问题：解决"四篇文章同步了一篇"的关键bug
* 增强错误处理：完善JSON解码、内存管理和异常处理机制
* 优化性能：改进缓存策略、减少内存泄漏、添加递归深度限制
* 提升安全性：加强数据验证、改进iframe白名单机制
* 统一版本管理：解决版本号不一致问题，提升PHP兼容性要求至7.4
* 代码质量提升：遵循WordPress编码标准，优化数据库查询性能

= 1.0.9 =
* 核心功能修复，包括AJAX、定时任务和脚本加载。
* 增强内容渲染，支持更多文章状态、修复公式和图表渲染。
* 优化图片处理，区分外部和内部图片，避免重复下载。
* 引入日志系统和调试工具，提升插件健壮性。
* 统一代码风格，完成中文化注释。

= 1.0.8 =
* 全面优化代码结构，统一版本号与作者信息
* 增强安全性与稳定性，修复多项潜在Bug
* 新增统计信息展示与刷新全部内容功能
* 优化卸载流程，支持彻底清理所有数据

== Support & Contribution ==
- GitHub Issue: https://github.com/Frank-Loong/Notion-to-WordPress/issues
- 欢迎PR、Issue、文档完善、翻译等多种形式的贡献！

== License ==
本插件采用GPL v3 or later许可证，详见LICENSE文件。

== Author ==
Frank-Loong  
GitHub: https://github.com/Frank-Loong/Notion-to-WordPress

== Upgrade Notice ==
= 1.1.0 =
重大更新：架构重构、同步逻辑修复、性能优化、安全增强。强烈建议所有用户升级，注意PHP版本要求提升至7.4+。

= 1.0.9 =
重要更新：此版本包含核心功能修复、渲染增强和健壮性提升，强烈建议所有用户升级。

= 1.0.8 =
推荐升级：统一版本号、增强安全性、优化体验。

