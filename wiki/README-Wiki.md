<!-- Switch Links -->
<p align="right">
  English | <a href="./README-Wiki.zh-CN.md">简体中文</a>
</p>

# Notion to WordPress - Complete User Guide

Welcome to the official documentation hub for **Notion to WordPress**! From onboarding to power-user tricks, everything you need is here.

## 📋 Table of Contents

- [🚀 Getting Started](#-getting-started)
- [⚙️ Advanced Configuration](#-advanced-configuration)
- [🔗 Webhook Auto-Sync](#-webhook-auto-sync)
- [🐞 Troubleshooting](#-troubleshooting)
- [❓ Frequently Asked Questions](#-frequently-asked-questions)

---

## 🚀 Getting Started

Follow this **5-minute guide** to complete your first Notion → WordPress sync.

### Prerequisites
1. WordPress 6.0 or later (plugin install rights)
2. PHP 8.0+ with `curl` extension
3. A Notion account & edit access to the target database

### Step 1: Create an Integration & Collect IDs
1. Open [Notion Integrations](https://www.notion.so/my-integrations) → **New integration**
2. Name it e.g. **WordPress Sync**, enable **Read content** & **Read user information**
3. Copy the **Internal Integration Token**
4. Go to your database → *Share* → invite the integration
5. Copy the **Database ID** from the URL (`https://www.notion.so/**DATABASE_ID**?v=...` – 32 chars)

> **Tip**: You can also use *Copy link* in the DB menu – the ID is before `?v=`.

### Step 2: Install the Plugin
1. Grab the latest ZIP from GitHub Releases
2. `WP-Admin → Plugins → Add New → Upload`, then activate **Notion to WordPress**

### Step 3: Configure
1. Sidebar → **Notion to WordPress**
2. Paste your *API Token* & *Database ID*
3. Pick a **Sync schedule** (start with *manual*)
4. Save settings

> For a quick test you can keep the default field mapping.

### Step 4: First Sync
Hit **Manual Sync**, wait for the spinner, then refresh **Posts**: voilà – Notion content is live!

Stats, logs & errors update in real time inside the admin page.

### Common Pitfalls
| Symptom | Likely Cause | Fix |
| --- | --- | --- |
| `Invalid token` | Wrong / expired API token | Regenerate & update settings |
| Database not found | Integration not invited / database private | *Share* the DB with the integration |
| Math / charts not rendering | Theme blocked `katex`/`mermaid` assets | Make sure `wp_footer()` outputs them or disable conflicting plugins |

---

## ⚙️ Advanced Configuration

### 1. Field Mapping Explained
The plugin uses **field mapping** to connect Notion database properties to WordPress fields.

#### Core Field Mappings
- **Post Title**: `Title,标题` - Maps to WordPress post_title
- **Status**: `Status,状态` - Controls post publication status:
  - `Published/已发布/publish/public/公开/live/上线` → Publish post
  - `Private/私密/private_post` → Private post
  - `Draft/草稿/unpublished/未发布` → Draft status
  - Works with password field for password-protected posts
- **Post Type**: `Type,类型` - Specifies WordPress post type (post, page, etc.)
- **Date**: `Date,日期` - Sets post publication date
- **Excerpt**: `Excerpt,摘要` - Post excerpt content
- **Featured Image**: `Featured Image,特色图片` - Featured image URL
- **Categories**: `Categories,分类` - Post categories
- **Tags**: `Tags,标签` - Post tags
- **Password**: `Password,密码` - When this field is not empty, the post is automatically set to password-protected, with the field value as the access password


#### Custom Field Mapping
Map any Notion property to WordPress custom fields, supporting various data types:
- Text, Number, Date, Checkbox
- Select, Multi-select, URL, Email
- Phone, Rich Text, etc.

### 2. Sync Schedule Configuration
- **Manual**: Complete manual control
- **Twice Daily**: For frequently updated content
- **Daily**: Regular update frequency
- **Weekly**: Low-frequency updates
- **Biweekly**: Archive-type content
- **Monthly**: Static content

### 3. Content Processing
- **Notion Block Conversion**: Supports headings, paragraphs, lists, tables, code blocks, quotes, etc.
- **Math Formulas**: Auto-renders KaTeX mathematical formulas
- **Mermaid Charts**: Supports flowcharts, sequence diagrams, etc.
- **Image Processing**: Auto-downloads Notion images to WordPress media library

### 4. Image & Attachment Strategy
- **Notion Temporary Links**: Auto-download to media library to prevent 404s
- **File Size Limits**: Default 5MB, customizable in settings
- **MIME Whitelist**: `image/jpeg,image/png,image/gif,image/webp`, or `*` for all

### 5. Debugging & Logging
- Debug levels `None / Error / Info / Debug` control log granularity
- Log storage path: `wp-content/uploads/notion-to-wordpress-logs/`
- One-click view and clear from admin

> Recommended: Use `Error` in production, switch to `Debug` when troubleshooting.

### 6. Security Settings
- **iframe Whitelist**: Control allowed embedded domains
- **Image Format Restrictions**: Limit allowed image MIME types
- **File Size Limits**: Prevent oversized files from affecting performance

---

## 🔗 Webhook Auto-Sync

Webhook functionality allows automatic WordPress sync when Notion content changes, achieving true real-time synchronization.

### Setup Steps

#### 1. Enable Webhook Support
1. Go to plugin settings → Other Settings
2. Check "Enable Webhook Support"
3. Save settings

#### 2. Get Webhook URL
After enabling, the Webhook URL will be displayed, formatted like:
```
https://yoursite.com/wp-json/notion-to-wordpress/v1/webhook
```

#### 3. Configure Notion Integration
1. Go to [Notion Integrations](https://www.notion.so/my-integrations)
2. Select your integration → Settings → Webhooks
3. Add new Webhook endpoint
4. Paste the above URL
5. Select event types to monitor (recommended: page.updated, page.created)

#### 4. Verify Setup
1. Modify database content in Notion
2. Check if WordPress auto-syncs
3. Review plugin logs to confirm webhook calls

### How Webhooks Work
1. Notion detects database changes
2. Sends POST request to configured Webhook URL
3. Plugin receives request and verifies source
4. Triggers automatic sync process
5. Returns processing result to Notion

### Important Notes
- Webhooks require publicly accessible website
- Recommend configuring SSL certificate for security
- Notion sends verification request on first setup

---

## 🐞 Troubleshooting

### Installation/Activation Issues
| Error Message | Possible Cause | Solution |
| ------------- | -------------- | -------- |
| *Fatal error on plugin activation* | PHP version too low/missing extensions | Upgrade to PHP 8.0+ and enable `curl`, `mbstring` extensions |
| *Upload failed: exceeds maximum upload size* | WP upload size limit | Increase `upload_max_filesize` / `post_max_size` in `php.ini` |

### Sync Errors
| Log/Message | Explanation | Solution |
| ----------- | ----------- | -------- |
| `API error (401): unauthorized` | Invalid token | Regenerate Integration Token and update settings |
| `database is not accessible` | Integration not invited to database | Open Notion → Share → Add integration |
| `Import failed: Invalid page data` | Empty rows or restricted pages in database | Verify data integrity, or filter restricted pages in Notion |
| `Image download failed` | Notion temporary link expired / WP no write permission | Check `wp-content/uploads` permissions, re-sync |

### Content Rendering Issues
| Symptom | Cause | Solution |
| ------- | ----- | -------- |
| Math formulas not displaying | KaTeX resources blocked | Check theme/plugin conflicts, ensure KaTeX loads on frontend |
| Mermaid charts blank | Mermaid.js not loaded | Same as above, ensure Mermaid script loads properly |
| Images show 404 | Image download failed or wrong path | Check media library permissions, re-sync |
| Formatting messy | Theme CSS conflicts | Check theme styles, add custom CSS if necessary |

### Performance Issues
| Symptom | Cause | Solution |
| ------- | ----- | -------- |
| Slow sync speed | Large images/content | Adjust image size limits, batch sync |
| Out of memory | PHP memory limit | Increase `memory_limit` to 256M+ |
| Timeout errors | Execution time limit | Increase `max_execution_time` to 300+ |

### Webhook Issues
| Symptom | Cause | Solution |
| ------- | ----- | -------- |
| Webhook not triggering | URL configuration error | Confirm URL is correct and site accessible |
| Verification failed | Token mismatch | Check Verification Token settings |
| SSL errors | Certificate issues | Configure valid SSL certificate |

---

## ❓ Frequently Asked Questions

### Q: Why aren't my Notion pages being imported?
**A:** Please check the following:
- Confirm your API key and database ID are correct
- Confirm your Notion integration has been shared with the database
- Check if field mapping correctly corresponds to property names in Notion
- Try using the "Refresh All Content" button to re-sync

### Q: How to customize imported content format?
**A:** This plugin preserves Notion formatting as much as possible, including headings, lists, tables, code blocks, etc. For special content (like mathematical formulas, charts), the plugin also provides support.

### Q: How to update content after import?
**A:** After updating content in Notion, you can click the "Refresh All Content" button to manually update, or wait for automatic sync (if configured).

### Q: Which Notion block types are supported?
**A:** Most common block types are supported:
- Text blocks: paragraphs, headings, quotes
- Lists: ordered lists, unordered lists, to-do items
- Media: images, videos, files
- Advanced: tables, code blocks, mathematical formulas, Mermaid charts

### Q: How to handle large data synchronization?
**A:** Recommendations:
- Set reasonable sync frequency
- Use filtered views to reduce sync data volume
- Adjust server performance parameters
- Process large content in batches

### Q: Does the plugin affect website performance?
**A:** The plugin is performance-optimized:
- Sync process runs in background
- Supports incremental sync, only processes changed content
- Configurable sync frequency to avoid frequent operations
- Provides debugging tools to monitor performance

---

## 🔗 Related Links

- [GitHub Repository](https://github.com/Frank-Loong/Notion-to-WordPress)
- [Issue Tracker](https://github.com/Frank-Loong/Notion-to-WordPress/issues)
- [Notion API Documentation](https://developers.notion.com/)
- [WordPress Developer Documentation](https://developer.wordpress.org/)

---

> If this documentation doesn't solve your problem, please [submit an issue](https://github.com/Frank-Loong/Notion-to-WordPress/issues) to discuss with us.
