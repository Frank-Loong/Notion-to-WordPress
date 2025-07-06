# 快速上手 · Getting Started

本文档将手把手教你在 **5 分钟内** 完成 Notion 与 WordPress 的首次同步。

---

## 先决条件
1. WordPress 6.0+，具有插件安装权限
2. PHP 8.0+ 与 `curl` 扩展
3. 拥有 Notion 账号，并能对目标数据库进行编辑

---

## 步骤 1：获取 Notion 凭证
1. 打开 [Notion Integrations](https://www.notion.so/my-integrations) → *New integration*
2. 命名，例如 `WordPress Sync`，勾选 **Read content** 与 **Read user information** 权限
3. 创建后复制 *Internal Integration Token*
4. 回到 Notion，给目标 **Database → Share → Invite** 集成
5. 在浏览器地址栏复制数据库 ID（位于 `https://www.notion.so/xxx?v=...` 之前 32 位字符）

> **提示**：数据库 ID 也可在菜单 *复制链接* 中找到，位于 `?v=` 之前。

---

## 步骤 2：安装插件
1. 前往 GitHub Release 或打包 ZIP，上传到 `wp-admin → 插件 → 安装插件 → 上传`
2. 激活 **Notion·to·WordPress**

---

## 步骤 3：配置插件
1. 进入侧边栏 *Notion to WordPress*
2. 填写 *API 密钥* 与 *Database ID*
3. 选择 *同步计划*（首次可选手动）
4. 保存设置

> 如果只想先体验效果，保持默认字段映射即可。

---

## 步骤 4：首次同步
点击顶部 **手动同步** 按钮，等待进度条完成 → 刷新文章列表，即可看到来自 Notion 的内容！

同步统计、日志与错误会在后台实时显示，方便排查。

---

## 常见入门问题
| 现象 | 可能原因 | 解决方案 |
| --- | --- | --- |
| 提示 `Invalid token` | API Token 复制错误或已失效 | 重新生成并替换 |
| 找不到数据库 | 未邀请集成、数据库设置为私密 | 确认 *Share* 权限 |
| 公式 / 图表未渲染 | 主题阻止了 `mermaid` / `katex` 资源 | 在 `functions.php` 允许前端加载，或排查冲突插件 |

> 更多疑难请查阅 [故障排查文档](./Troubleshooting-FAQ.zh-CN.md)。 