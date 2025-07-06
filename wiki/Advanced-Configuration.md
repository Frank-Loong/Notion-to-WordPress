<!-- Lang Switch -->
<p align="right">
  English | <a href="./Advanced-Configuration.zh-CN.md">中文</a>
</p>

# Advanced Configuration

Fine-tune **Notion-to-WordPress** to fit any publishing workflow.

---

## 1. Field Mapping
In **Field Mapping** you bind Notion properties to WordPress fields. Use commas for aliases (multi-language column titles, etc.).

| WordPress Field | Default Notion Prop | Notes |
| --------------- | ------------------- | ----- |
| Title           | `Title, 标题`        | Post/page title |
| Status          | `Status, 状态`       | `draft` / `published` / `private` |
| Type            | `Type, 类型`         | `post` / `page` (or any CPT) |
| Date            | `Date, 日期`         | Saved as `post_date` |
| Featured Image  | `Featured Image`     | URL or file property |
| Categories      | `Categories, 分类`   | Multi-select / relation |
| Tags            | `Tags, 标签`         | Same as above |
| Password        | _(empty)_            | When non-empty the post is password-protected with this value |

> Tips  
> • Add unlimited **Custom Field Mappings** – supports `text, number, date, checkbox, select, multi_select, url, email, phone, rich_text`.  
> • Aliases let you keep multi-language Notion DBs without extra views.

---

## 2. Sync Schedule (Cron)
* **manual** – only on button press  
* **hourly / twice-daily / daily** – WP built-ins  
* **weekly / biweekly / monthly** – custom intervals added by the plugin

Need minute-level granularity? Use `wp cron event run` CLI or plugins like *Advanced Cron Manager*.

---

## 3. Webhook
When enabled, Notion fires updates instantly:
```text
POST /wp-json/notion-to-wordpress/v1/webhook/{token}
```
`{token}` is generated automatically. Events `page.*` & `block.*` trigger `import_pages()`.
See the [Webhook Guide](./Webhook-Setup.md) for full setup.

---

## 4. Media Handling
- **Temporary Notion URLs** are auto-downloaded to the Media Library (avoids 404s)  
- **File size limit** defaults to 5 MB – configurable up to 20 MB  
- **MIME whitelist** defaults to `image/jpeg,image/png,image/gif,image/webp` – `*` permits all

---

## 5. Debug & Logs
- Levels: `None / Error / Info / Debug`  
- Log path: `wp-content/uploads/notion-to-wordpress-logs/`  
- View / clear logs in the admin UI

> Recommendation: keep *Error* in production, switch to *Debug* when tracing issues.

---

## 6. Useful Hooks
| Hook | Fired | Purpose |
| ---- | ----- | ------- |
| `ntw_cdn_prefix` | Before front-end assets enqueue | Replace KaTeX / Mermaid CDN |
| `notion_cron_import` | At each cron run | Custom import routine |

```php
// Example: use JSDelivr mirror
add_filter('ntw_cdn_prefix', fn () => 'https://fastly.jsdelivr.net');
```

---

Need more? Check the [Troubleshooting & FAQ](./Troubleshooting-FAQ.md) or open an Issue. 