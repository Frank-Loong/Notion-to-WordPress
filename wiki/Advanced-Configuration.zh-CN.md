# 高级配置 · Advanced Configuration

本节将深入讲解 **Notion·to·WordPress** 的所有可定制选项，帮助你打造最契合工作流的同步方案。

---

## 1. 字段映射 (Field Mapping)
在设置页「字段映射」区域，你可以将 Notion 属性与 WP 字段一一对应，支持逗号分隔的「别名」：

| WordPress 字段 | 默认 Notion 属性 | 说明 |
| -------------- | --------------- | ---- |
| Title          | Title, 标题      | 文章标题 |
| Status         | Status, 状态     | draft / published / private |
| Type           | Type, 类型       | post / page  |
| Date           | Date, 日期       | 显示在 `post_date` |
| Featured Image | Featured Image   | 文件或外链 URL |
| Categories     | Categories, 分类 | 多选或标签属性 |
| Tags           | Tags, 标签       | 多选或标签属性 |
| password       | 默认为空         |当password属性非空时，文章会自动设为加密状态，属性值即为密码 |

> **Tips**
> - 同一属性存在多语言标题时，用英文逗号分隔即可匹配所有可能。
> - 自定义字段映射可无限扩展，支持 `text / number / date / checkbox / select / multi_select / url / email / phone / rich_text` 类型。

---

## 2. 同步计划 (Cron Schedule)
- **manual**：仅手动触发
- **hourly / twice-daily / daily**：WordPress 内置计划
- **weekly / biweekly / monthly**：插件自定义，更灵活

更细颗粒度可通过 `wp cron event` CLI 或 `Advanced Cron Manager` 插件调整。

---

## 3. Webhook
启用后，Notion 更新会实时 POST 到 WP REST API：
```
POST /wp-json/notion-to-wordpress/v1/webhook/{token}
```
- `token` 由插件随机生成，可在设置页查看/重置
- 接收 `page.* / block.*` 事件后即触发 `import_pages()`

详细配置步骤请见 [Webhook 指南](./Webhook-Setup.zh-CN.md)。

---

## 4. 图片与附件下载策略
- **Notion 临时链接**：自动下载到媒体库，避免 404
- **文件大小限制**：默认 5 MB，可在设置中自定义大小
- **MIME 白名单**：`image/jpeg,image/png,image/gif,image/webp`，亦可填写 `*` 允许所有

---

## 5. 调试与日志
- 调试等级 `None / Error / Info / Debug` 控制日志粒度
- 日志存储路径：`wp-content/uploads/notion-to-wordpress-logs/`
- 支持后台一键查看、清空

> 建议在生产环境使用 `Error`，当需要排查问题时切换到 `Debug`。

---

## 6. 常用过滤器 & 动作 Hook
| Hook | 时机 | 用途 |
| ---- | ---- | ---- |
| `ntw_cdn_prefix` | 前端脚本入队前 | 替换 KaTeX/Mermaid CDN |
| `notion_cron_import` | Cron 作业运行 | 自定义导入逻辑 |

```php
// 示例：使用 CDN 加速
add_filter('ntw_cdn_prefix', fn() => 'https://fastly.jsdelivr.net');
```

---

更多问题欢迎阅读 [FAQ](./Troubleshooting-FAQ.zh-CN.md) 或提交 Issue。 