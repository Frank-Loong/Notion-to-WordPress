<!-- Lang Switch -->
<p align="right">
  English | <a href="./Troubleshooting-FAQ.zh-CN.md">中文</a>
</p>

# Troubleshooting & FAQ

A living list of common issues and proven fixes.

---

## 1. Installation / Activation
| Error Message | Likely Cause | Fix |
| ------------- | ----------- | --- |
| *Fatal error on activation* | PHP version too old / missing extensions | Upgrade to PHP 8.0+, enable `curl`, `mbstring` |
| *Upload exceeds maximum size* | WP upload limit | Increase `upload_max_filesize` / `post_max_size` in `php.ini` |

---

## 2. Sync Errors
| Log / Notice | Meaning | Solution |
| ------------ | ------- | -------- |
| `API error (401): unauthorized` | Invalid token | Regenerate Integration Token & update settings |
| `database is not accessible` | Integration not invited | Notion → Share → invite integration |
| `Import failed: Invalid page data` | Blank rows or pages without permission | Clean DB or filter view |
| `Image download failed` | Temporary URL expired / no write permission | Check `wp-content/uploads` perms & re-sync |

---

## 3. Front-end Display
| Symptom | Check | Fix |
| ------- | ----- | --- |
| Math not rendered | Does `katex.min.js` load? | Ensure theme prints `wp_footer()` & no conflicting plugins |
| Mermaid blank | Any JS errors? | Disable other Mermaid plugins and retry |
| No code highlighting | Theme lacks Prism/Highlight | Add a syntax highlighter or choose a theme that does |

---

## 4. FAQ
1. **Multi-database?** One DB per site right now; use WP multisite for more.  
2. **Sync a single view only?** Create a filtered view in Notion; the plugin respects it.  
3. **Delete behaviour?** By default posts stay; write a custom hook if you need auto-delete.  
4. **Hide generated meta fields?** Use *ACF* or filter `register_meta`.

---

> Still need help? Include logs / PHP errors when opening an Issue. 