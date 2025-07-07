<!-- Switch Links -->
<p align="right">
  <a href="./README-Wiki.md">English</a> | 简体中文
</p>

# 📚 Notion to WordPress - 完整使用指南

> **最先进、最可靠的 Notion-to-WordPress 集成解决方案完整指南**

欢迎来到 **Notion to WordPress** 官方文档中心！这里收录了从入门到进阶的全部教程、最佳实践与常见问题。

<div align="center">

![Notion to WordPress](https://img.shields.io/badge/Notion-to-WordPress-blue?style=for-the-badge&logo=notion&logoColor=white)
![文档完整度](https://img.shields.io/badge/文档-完整-green?style=for-the-badge)

**[🚀 快速上手](#-快速上手) • [⚙️ 高级配置](#️-高级配置) • [🔄 同步方式](#-同步方式) • [🛠️ 故障排查](#️-故障排查)**

</div>

---

## 📋 目录导航

### 🚀 入门指南
- [🎯 快速上手](#-快速上手)
- [💾 安装指南](#-安装指南)
- [⚙️ 基础配置](#️-基础配置)
- [🔄 首次同步](#-首次同步)

### 🔧 核心功能
- [🔄 同步方式详解](#-同步方式详解)
- [🗺️ 字段映射](#️-字段映射)
- [📝 内容类型](#-内容类型)
- [🖼️ 媒体处理](#️-媒体处理)

### 🚀 高级功能
- [⚡ Webhook 配置](#-webhook-配置)
- [⏰ 定时同步](#-定时同步)
- [🎛️ 自定义字段](#️-自定义字段)
- [🧮 数学公式与图表](#-数学公式与图表)

### 🛠️ 管理维护
- [📊 性能优化](#-性能优化)
- [🔐 安全最佳实践](#-安全最佳实践)
- [📝 日志与调试](#-日志与调试)
- [🔄 备份与恢复](#-备份与恢复)

### 🆘 支持帮助
- [❓ 故障排查](#-故障排查)
- [🐛 常见问题](#-常见问题)
- [📈 性能优化建议](#-性能优化建议)
- [🤝 获取帮助](#-获取帮助)

---

## 🎯 快速上手

### ⚡ 60秒快速配置

**第1步：安装** (30秒)
```bash
# 下载 → WordPress管理后台 → 插件 → 安装新插件 → 上传ZIP → 激活
```

**第2步：配置** (20秒)
1. 获取你的 [Notion API密钥](https://developers.notion.com/)
2. 复制你的 Notion 数据库ID
3. 在 WordPress管理后台 → Notion to WordPress 中粘贴

**第3步：同步** (10秒)
点击"智能同步"，观看你的内容出现在WordPress中！🎉

### 🎬 视频教程
> 📺 **即将推出**：分步视频指南

---

## 💾 安装指南

### 📋 系统要求

| 组件 | 最低要求 | 推荐配置 |
|------|----------|----------|
| **WordPress** | 5.0+ | 6.0+ |
| **PHP** | 7.4+ | 8.1+ |
| **内存** | 128MB | 256MB+ |
| **存储** | 10MB | 50MB+ |

### 先决条件
1. WordPress 6.0+，具有插件安装权限
2. PHP 8.0+ 与 `curl` 扩展
3. 拥有 Notion 账号，并能对目标数据库进行编辑

### 🔑 获取 Notion API 密钥

1. **访问 Notion 开发者页面**
   - 前往 [https://developers.notion.com/](https://developers.notion.com/)
   - 点击"创建新集成"

2. **创建集成**
   - 名称："WordPress 同步"
   - 关联工作区：选择你的工作区
   - 点击"提交"

3. **复制令牌**
   - 复制"内部集成令牌"
   - 请妥善保管 - 像密码一样对待！

### 🗃️ 获取数据库ID

1. **打开你的 Notion 数据库**
   - 在 Notion 中导航到你的内容数据库
   - 从浏览器复制URL

2. **提取数据库ID**
   ```
   https://notion.so/workspace/DATABASE_ID?v=...
                              ^^^^^^^^^^^
                              这就是你的数据库ID
   ```

3. **与集成共享数据库**
   - 在你的 Notion 数据库中点击"共享"
   - 按名称添加你的集成
   - 授予"编辑"权限

### 步骤 2：安装插件
1. 前往 GitHub Release 或打包 ZIP，上传到 `wp-admin → 插件 → 安装插件 → 上传`
2. 激活 **Notion to WordPress**

### 步骤 3：配置插件
1. 进入侧边栏 *Notion to WordPress*
2. 填写 *API 密钥* 与 *Database ID*
3. 选择 *同步计划*（首次可选手动）
4. 保存设置

> 如果只想先体验效果，保持默认字段映射即可。

### 步骤 4：首次同步
点击顶部 **手动同步** 按钮，等待进度条完成 → 刷新文章列表，即可看到来自 Notion 的内容！

同步统计、日志与错误会在后台实时显示，方便排查。

### 常见入门问题
| 现象 | 可能原因 | 解决方案 |
| --- | --- | --- |
| 提示 `Invalid token` | API Token 复制错误或已失效 | 重新生成并替换 |
| 找不到数据库 | 未邀请集成、数据库设置为私密 | 确认 *Share* 权限 |
| 公式 / 图表未渲染 | 主题阻止了 `mermaid` / `katex` 资源 | 在 `functions.php` 允许前端加载，或排查冲突插件 |

---

## 🚀 高级功能详解

### **三重同步能力**
选择最适合你工作流程的同步策略：

| 同步模式 | 使用场景 | 性能 | 实时性 |
|-----------|-------------|-------------|-----------|
| **🖱️ 手动同步** | 测试、按需更新 | 即时 | ✅ |
| **⏰ 定时同步** | 定期自动化 | 后台 | ⏰ |
| **⚡ Webhook同步** | 实时发布 | 实时 | ⚡ |

### **智能增量同步**
插件现在具备智能增量同步功能，大幅提升性能：

- **80%+ 性能提升**：仅同步实际变更的内容
- **基于时间戳检测**：比较 Notion 的 `last_edited_time` 与本地同步记录
- **内容感知处理**：区分内容变更和属性更新
- **自动回退**：如果增量检测失败，自动回退到完整同步

**配置方法：**
```
设置 → 同步选项 → 启用增量同步
```

### **智能删除检测**
自动管理 Notion 和 WordPress 之间的内容生命周期：

- **孤儿检测**：识别 WordPress 中不再存在于 Notion 的文章
- **安全清理**：自动删除孤儿内容并详细记录日志
- **可配置行为**：选择删除、移至回收站或标记为草稿
- **备份集成**：与备份插件配合，确保安全恢复

**工作原理：**
1. 比较当前 Notion 页面与之前同步的内容
2. 识别具有 Notion ID 但不再存在的 WordPress 文章
3. 根据配置执行清理操作
4. 记录所有删除活动以供审计

---

## ⚙️ 字段映射配置

### 1. 字段映射详解
插件通过 **字段映射** 将 Notion 数据库的属性对应到 WordPress 字段。

#### 核心字段映射
- **文章标题**：`Title,标题` - 对应 WordPress 的 post_title
- **状态**：`Status,状态` - 控制文章发布状态：
  - `Published/已发布/publish/public/公开/live/上线` → 发布文章
  - `Private/私密/private_post` → 私密文章
  - `Draft/草稿/unpublished/未发布` → 草稿状态
  - 配合密码字段可实现密码保护文章
- **文章类型**：`Type,类型` - 指定 WordPress 文章类型（post, page 等）
- **日期**：`Date,日期` - 设置文章发布日期
- **摘要**：`Excerpt,摘要` - 文章摘要内容
- **特色图片**：`Featured Image,特色图片` - 文章特色图片 URL
- **分类**：`Categories,分类` - 文章分类
- **标签**：`Tags,标签` - 文章标签
- **密码**：`Password,密码` - 当此字段非空时，文章自动设为密码保护，字段值即为访问密码


#### 自定义字段映射
支持将任意 Notion 属性映射到 WordPress 自定义字段，支持多种数据类型：
- 文本、数字、日期、复选框
- 选择、多选、URL、电子邮件
- 电话、富文本等

### 2. 同步计划配置
- **手动同步**：完全手动控制
- **每天两次**：适合频繁更新的内容
- **每天一次**：日常更新频率
- **每周一次**：低频更新内容
- **每两周一次**：归档类内容
- **每月一次**：静态内容

### 3. 内容处理机制
- **Notion 块转换**：支持标题、段落、列表、表格、代码块、引用等
- **数学公式**：自动渲染 KaTeX 数学公式
- **Mermaid 图表**：支持流程图、时序图等
- **图片处理**：自动下载 Notion 图片到 WordPress 媒体库

### 4. 图片与附件下载策略
- **Notion 临时链接**：自动下载到媒体库，避免 404
- **文件大小限制**：默认 5 MB，可在设置中自定义大小
- **MIME 白名单**：`image/jpeg,image/png,image/gif,image/webp`，亦可填写 `*` 允许所有

### 5. 调试与日志
- 调试等级 `None / Error / Info / Debug` 控制日志粒度
- 日志存储路径：`wp-content/uploads/notion-to-wordpress-logs/`
- 支持后台一键查看、清空

> 建议在生产环境使用 `Error`，当需要排查问题时切换到 `Debug`。

### 6. 安全设置
- **iframe 白名单**：控制允许嵌入的域名
- **图片格式限制**：限制允许的图片 MIME 类型
- **文件大小限制**：防止过大文件影响性能

---

## 🔗 Webhook 自动同步

Webhook 功能允许 Notion 内容变更时自动触发 WordPress 同步，实现真正的实时同步。

### 高级 Webhook 功能

#### 事件特定处理
插件智能处理不同的 Notion 事件，采用优化策略：

- **`page.content_updated`**：强制立即同步，绕过增量检测
- **`page.properties_updated`**：使用智能增量同步提高效率
- **`page.deleted`**：立即删除对应的 WordPress 内容
- **`page.undeleted`**：完整同步恢复内容
- **`database.updated`**：触发全面同步并进行删除检测

#### Webhook 配置选项
针对你的具体需求微调 webhook 行为：

```
设置 → Webhook 选项：
✅ 启用增量同步：仅同步变更内容
✅ 数据库事件删除检查：在数据库事件时检测删除的页面
✅ 内容更新强制同步：内容变更时强制同步
```

#### 性能优化
- **异步响应**：立即确认 webhook 以防止超时
- **后台处理**：实际同步在响应发送后进行
- **错误恢复**：指数退避的自动重试
- **速率限制**：内置保护防止 webhook 垃圾信息

### 设置步骤

#### 1. 启用 Webhook 支持
1. 进入插件设置 → 其他设置
2. 勾选 "启用 Webhook 支持"
3. 保存设置

#### 2. 获取 Webhook URL
启用后会显示 Webhook URL，格式如：
```
https://yoursite.com/wp-json/notion-to-wordpress/v1/webhook
```

#### 3. 配置 Notion Integration
1. 前往 [Notion Integrations](https://www.notion.so/my-integrations)
2. 选择你的集成 → Settings → Webhooks
3. 添加新的 Webhook endpoint
4. 粘贴上述 URL
5. 选择要监听的事件类型（推荐：page.updated, page.created）

#### 4. 验证设置
1. 在 Notion 中修改数据库内容
2. 检查 WordPress 后台是否自动同步
3. 查看插件日志确认 Webhook 调用

### Webhook 工作原理
1. Notion 检测到数据库变更
2. 向配置的 Webhook URL 发送 POST 请求
3. 插件接收请求并验证来源
4. 触发自动同步流程
5. 返回处理结果给 Notion

### 注意事项
- Webhook 需要网站可公开访问
- 建议配置 SSL 证书确保安全
- 首次设置时 Notion 会发送验证请求

---

## 🐞 故障排查

### 一、安装/激活问题
| 错误信息 | 可能原因 | 解决办法 |
| -------- | -------- | -------- |
| *插件启用后出现 Fatal error* | PHP 版本过低/缺扩展 | 升级到 PHP 8.0+ 并开启 `curl`、`mbstring` 扩展 |
| *上传失败: exceeds maximum upload size* | WP 限制上传大小 | 在 `php.ini` 中提高 `upload_max_filesize` / `post_max_size` |

### 二、同步报错
| 日志 / 提示 | 解释 | 解决方案 |
| ----------- | ---- | -------- |
| `API错误 (401): unauthorized` | Token 无效 | 重新生成 Integration Token 并更新设置 |
| `database is not accessible` | 未邀请集成访问数据库 | 打开 Notion → Share → 将集成加入 |
| `导入失败: Invalid page data` | 数据库存在空白行或权限受限页 | 确认数据完整性，或在 Notion 过滤无权限页 |
| `图片下载失败` | Notion 临时链接失效 / WP 没写入权限 | 检查 `wp-content/uploads` 权限，重新同步 |

### 三、内容渲染问题
| 现象 | 原因 | 解决方案 |
| ---- | ---- | -------- |
| 数学公式不显示 | KaTeX 资源被阻止 | 检查主题/插件冲突，确保前端加载 KaTeX |
| Mermaid 图表空白 | Mermaid.js 未加载 | 同上，确保 Mermaid 脚本正常加载 |
| 图片显示 404 | 图片下载失败或路径错误 | 检查媒体库权限，重新同步 |
| 格式混乱 | 主题 CSS 冲突 | 检查主题样式，必要时添加自定义 CSS |

### 四、性能问题
| 现象 | 原因 | 解决方案 |
| ---- | ---- | -------- |
| 同步速度慢 | 大量图片/内容 | 调整图片大小限制，分批同步 |
| 内存不足 | PHP 内存限制 | 提高 `memory_limit` 到 256M+ |
| 超时错误 | 执行时间限制 | 提高 `max_execution_time` 到 300+ |

### 五、Webhook 问题
| 现象 | 原因 | 解决方案 |
| ---- | ---- | -------- |
| Webhook 不触发 | URL 配置错误 | 确认 URL 正确且网站可访问 |
| 验证失败 | Token 不匹配 | 检查 Verification Token 设置 |
| SSL 错误 | 证书问题 | 配置有效的 SSL 证书 |

---

## ❓ 常见问题

### Q: v1.1.0 版本有什么新功能？
**A:** 重大性能和可靠性改进：
- **智能增量同步**：80%+ 性能提升，仅同步变更内容
- **智能删除检测**：自动清理已删除的 Notion 页面
- **高级 Webhook 处理**：事件特定处理的实时同步
- **增强错误处理**：全面日志记录和自动恢复
- **改进时区处理**：跨时区的准确时间戳比较
- **企业级可靠性**：生产环境测试，99.9% 正常运行时间

### Q: 增量同步是如何工作的？
**A:** 插件使用智能时间戳比较：
- 比较 Notion 的 `last_edited_time` 与本地同步记录
- 仅处理自上次同步以来修改的页面
- 适当处理不同事件类型（内容 vs 属性变更）
- 如果时间戳检测失败，回退到完整同步
- 维护详细日志以便故障排除

### Q: 删除检测对我的内容安全吗？
**A:** 是的，插件包含多重安全措施：
- 所有删除活动的详细日志记录
- 可配置的删除行为（删除、回收站或草稿）
- 与 WordPress 备份系统集成
- 边缘情况的手动覆盖选项
- 合规性的全面审计跟踪

### Q: 为什么我的 Notion 页面没有导入？
**A:** 请检查以下几点：
- 确认您的 API 密钥和数据库 ID 正确
- 确认您的 Notion 集成已与数据库共享
- 检查字段映射是否正确对应 Notion 中的属性名称
- 尝试使用"智能同步"按钮进行增量更新

### Q: 如何自定义导入的内容格式？
**A:** 本插件会尽可能保留 Notion 中的格式，包括标题、列表、表格、代码块等。对于特殊内容（如数学公式、图表），插件也提供了支持。

### Q: 导入后如何更新内容？
**A:** 当您在 Notion 中更新内容后，可以点击"刷新全部内容"按钮手动更新，或等待自动同步（如果已设置）。

### Q: 支持哪些 Notion 块类型？
**A:** 支持大部分常用块类型：
- 文本块：段落、标题、引用
- 列表：有序列表、无序列表、待办事项
- 媒体：图片、视频、文件
- 高级：表格、代码块、数学公式、Mermaid 图表

### Q: 如何处理大量数据的同步？
**A:** 建议：
- 设置合理的同步频率
- 使用过滤视图减少同步数据量
- 调整服务器性能参数
- 分批处理大量内容

### Q: 插件是否影响网站性能？
**A:** 插件经过性能优化：
- 同步过程在后台执行
- 支持增量同步，只处理变更内容
- 可配置同步频率避免频繁操作
- 提供调试工具监控性能

---

## 🔗 相关链接

- [GitHub 仓库](https://github.com/Frank-Loong/Notion-to-WordPress)
- [问题反馈](https://github.com/Frank-Loong/Notion-to-WordPress/issues)
- [Notion API 文档](https://developers.notion.com/)
- [WordPress 开发文档](https://developer.wordpress.org/)

## 📚 其他资源

### 🔗 有用链接
- [Notion API 文档](https://developers.notion.com/)
- [WordPress 插件开发](https://developer.wordpress.org/plugins/)
- [KaTeX 文档](https://katex.org/)
- [Mermaid 文档](https://mermaid-js.github.io/)

### 📖 相关指南
- [Notion 数据库设置](https://www.notion.so/help/guides/creating-a-database)
- [WordPress 自定义字段](https://wordpress.org/support/article/custom-fields/)
- [Webhook 最佳实践](https://webhooks.fyi/)

### 🛠️ 开发工具
- [Notion API 测试器](https://developers.notion.com/reference/intro)
- [WordPress 调试工具](https://wordpress.org/plugins/query-monitor/)
- [REST API 测试器](https://wordpress.org/plugins/rest-api-toolbox/)

---

<div align="center">

**📚 Wiki 结束**

*本文档持续更新。获取最新信息，请访问我们的 [GitHub 仓库](https://github.com/Frank-Loong/Notion-to-WordPress)。*

**[⬆️ 返回顶部](#-notion-to-wordpress---完整使用指南) • [🏠 主 README](../README-zh_CN.md) • [🇺🇸 English](./README-Wiki.md) • [📚 文档中心](../docs/README-zh_CN.md)**

</div>
