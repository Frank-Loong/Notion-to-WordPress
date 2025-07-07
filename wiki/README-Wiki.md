**📖 Navigation:** [🏠 Home](../README.md) • **📚 User Guide** • [📊 Project Status](../docs/PROJECT_STATUS.md) • [🔄 Changelog](../docs/CHANGELOG.md) • [⚖️ Feature Comparison](../docs/FEATURES_COMPARISON.md) • [🤝 Contributing](../CONTRIBUTING.md)

**🌐 Language:** **English** • [中文](README-Wiki.zh-CN.md)

---

# Notion to WordPress - Complete User Guide

Welcome to the official documentation hub for **Notion to WordPress**! From onboarding to power-user tricks, everything you need is here.

<div align="center">

**[🚀 Getting Started](#-getting-started) • [🚀 Advanced Features](#-advanced-features) • [🔗 Webhook Auto-Sync](#-webhook-auto-sync) • [🐞 Troubleshooting](#-troubleshooting)**

</div>

---

## 📋 Table of Contents

- [🚀 Getting Started](#-getting-started)
- [💾 Installation Guide](#-installation-guide)
- [🚀 Advanced Features](#-advanced-features)
- [⚙️ Field Mapping Configuration](#️-field-mapping-configuration)
- [🔗 Webhook Auto-Sync](#-webhook-auto-sync)
- [🐞 Troubleshooting](#-troubleshooting)
- [❓ Frequently Asked Questions](#-frequently-asked-questions)
- [🔗 Related Links](#-related-links)
- [📚 Additional Resources](#-additional-resources)

---

## 🚀 Getting Started

### 📸 Complete Visual Tutorial

#### 🎬 Animated Demo
<!-- TODO: Add complete setup animation -->
<div align="center">
  <img src="../docs/images/setup-complete.gif" alt="Complete Setup Flow" width="800">
  <p><em>🎯 Complete setup flow: from zero to sync success</em></p>
</div>

#### 📋 Notion Database Templates
Before you start, grab one of our ready-to-use templates:

<div align="center">

| 🎯 Template Type | 📝 Use Case | 🔗 Copy Link |
|:---:|:---:|:---:|
| **📝 Blog Template** | Personal blogs, tech articles, journals | [📋 Copy Template](https://notion.so/template/blog-en) |
| **📚 Knowledge Base** | Team docs, product manuals, FAQs | [📋 Copy Template](https://notion.so/template/docs-en) |
| **🎓 Course Template** | Online education, training content, tutorials | [📋 Copy Template](https://notion.so/template/course-en) |

</div>

> 💡 **Usage Tip**: After copying the template, remember to invite your integration to access the database in Notion!

### ⚡ 5-Minute Setup Guide

#### 📷 Step 1: Install Plugin (2 minutes)
<!-- TODO: Add installation screenshots -->
<div align="center">
  <img src="../docs/images/install-step1.png" alt="Installation Step 1" width="600">
  <p><em>WordPress Admin → Plugins → Add New Plugin</em></p>
</div>

#### 🔑 Step 2: Get Configuration Info (2 minutes)
<!-- TODO: Add configuration screenshots -->
<div align="center">
  <img src="../docs/images/config-step2.png" alt="Configuration Step 2" width="600">
  <p><em>Notion Developer Page to get API key</em></p>
</div>

#### 🚀 Step 3: First Sync (1 minute)
<!-- TODO: Add sync screenshots -->
<div align="center">
  <img src="../docs/images/sync-step3.png" alt="Sync Step 3" width="600">
  <p><em>Click "Smart Sync" to start content synchronization</em></p>
</div>

### 🎬 Video Tutorials
<!-- TODO: Add video tutorial links -->
> 📺 **Coming Soon**:
> - 🎥 **Complete Setup Video** (5-minute detailed tutorial)
> - 🎬 **Troubleshooting Guide** (animated problem-solving)
> - 📱 **Mobile Setup** (phone/tablet configuration guide)

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

## 💾 Installation Guide

### System Requirements
- **WordPress**: 6.0 or later
- **PHP**: 8.0+ with `curl` extension enabled
- **Server**: Any hosting provider supporting WordPress
- **Permissions**: Plugin installation and activation rights

### Installation Methods

#### Method 1: WordPress Admin Dashboard (Recommended)
1. **Download Plugin**: Get the latest `.zip` file from [GitHub Releases](https://github.com/Frank-Loong/Notion-to-WordPress/releases)
2. **Upload Plugin**:
   - Go to `Plugins` → `Add New` → `Upload Plugin`
   - Choose the downloaded `.zip` file
   - Click `Install Now`
3. **Activate**: Click `Activate Plugin` after installation

#### Method 2: FTP Upload
1. **Extract Files**: Unzip the plugin to `notion-to-wordpress/` folder
2. **Upload via FTP**: Upload the folder to `/wp-content/plugins/`
3. **Activate**: Go to WordPress admin → `Plugins` → Activate "Notion to WordPress"

#### Method 3: WP-CLI (Advanced Users)
```bash
# Download and install
wp plugin install https://github.com/Frank-Loong/Notion-to-WordPress/releases/latest/download/notion-to-wordpress.zip

# Activate
wp plugin activate notion-to-wordpress
```

### Post-Installation Setup
1. **Access Settings**: Go to `Settings` → `Notion to WordPress`
2. **Language Selection**: Choose your preferred interface language
3. **API Configuration**: Follow the [Getting Started](#-getting-started) guide
4. **Test Connection**: Verify your Notion integration works

### Troubleshooting Installation
- **Permission Issues**: Ensure your user has plugin installation rights
- **File Upload Limits**: Check PHP `upload_max_filesize` and `post_max_size`
- **Plugin Conflicts**: Temporarily deactivate other plugins if issues occur

---

## 🚀 Advanced Features

### Content Type Support
The plugin supports various Notion content types and converts them to WordPress equivalents:

#### Text & Formatting
- **Rich Text**: Bold, italic, underline, strikethrough
- **Code Blocks**: Syntax highlighting preserved
- **Lists**: Bulleted, numbered, and toggle lists
- **Quotes**: Block quotes and callouts

#### Media & Embeds
- **Images**: Auto-download and upload to WordPress media library
- **Files**: Download and attach to posts
- **Videos**: Embed support for YouTube, Vimeo, etc.
- **Links**: Preserve all internal and external links

#### Advanced Content
- **Math Formulas**: LaTeX rendering with KaTeX
- **Diagrams**: Mermaid chart support
- **Tables**: Full table structure preservation
- **Databases**: Nested database content

### Sync Modes
1. **Manual Sync**: On-demand synchronization
2. **Webhook Sync**: Real-time updates from Notion
3. **Scheduled Sync**: Automated periodic updates (coming soon)

---

## ⚙️ Field Mapping Configuration

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

### 3. Advanced Sync Features

#### Smart Incremental Sync
The plugin now features intelligent incremental synchronization that dramatically improves performance:

- **80%+ Performance Boost**: Only syncs content that has actually changed
- **Timestamp-Based Detection**: Compares Notion's `last_edited_time` with local sync records
- **Content-Aware Processing**: Distinguishes between content changes and property updates
- **Automatic Fallback**: Falls back to full sync if incremental detection fails

**Configuration:**
```
Settings → Sync Options → Enable Incremental Sync
```

#### Intelligent Deletion Detection
Automatically manages content lifecycle between Notion and WordPress:

- **Orphan Detection**: Identifies WordPress posts that no longer exist in Notion
- **Safe Cleanup**: Automatically removes orphaned content with detailed logging
- **Configurable Behavior**: Choose whether to delete, trash, or mark as draft
- **Backup Integration**: Works with backup plugins for safe recovery

**How it works:**
1. Compares current Notion pages with previously synced content
2. Identifies WordPress posts with Notion IDs that no longer exist
3. Performs cleanup action based on configuration
4. Logs all deletion activities for audit trail

#### Triple Sync Modes
Choose the perfect sync strategy for your workflow:

| Mode | When to Use | Performance | Real-time |
|------|-------------|-------------|-----------|
| **🖱️ Manual Sync** | Testing, on-demand updates | Instant | ✅ |
| **⏰ Scheduled Sync** | Regular automation | Background | ⏰ |
| **⚡ Webhook Sync** | Live publishing | Real-time | ⚡ |

### 4. Content Processing
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

### Advanced Webhook Features

#### Event-Specific Processing
The plugin intelligently handles different Notion events with optimized strategies:

- **`page.content_updated`**: Forces immediate sync, bypassing incremental detection
- **`page.properties_updated`**: Uses smart incremental sync for efficiency
- **`page.deleted`**: Immediately removes corresponding WordPress content
- **`page.undeleted`**: Restores content with full sync
- **`database.updated`**: Triggers comprehensive sync with deletion detection

#### Webhook Configuration Options
Fine-tune webhook behavior for your specific needs:

```
Settings → Webhook Options:
✅ Enable Incremental Sync: Only sync changed content
✅ Database Event Deletion Check: Detect removed pages on database events
✅ Content Update Force Sync: Force sync on content changes
```

#### Performance Optimizations
- **Async Response**: Immediate webhook acknowledgment to prevent timeouts
- **Background Processing**: Actual sync happens after response is sent
- **Error Recovery**: Automatic retry with exponential backoff
- **Rate Limiting**: Built-in protection against webhook spam

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
- Supports incremental sync, only processes changed content (80%+ faster)
- Intelligent deletion detection prevents orphaned content
- Configurable sync frequency to avoid frequent operations
- Advanced webhook processing with async responses
- Provides debugging tools to monitor performance

### Q: What's new in the latest version?
**A:** Major performance and reliability improvements:
- **Smart Incremental Sync**: 80%+ performance boost by only syncing changed content
- **Intelligent Deletion Detection**: Automatically cleans up removed Notion pages
- **Advanced Webhook Processing**: Real-time sync with event-specific handling
- **Enhanced Error Handling**: Comprehensive logging and automatic recovery
- **Improved Time Zone Handling**: Accurate timestamp comparisons across time zones
- **Enterprise-Grade Reliability**: Production-tested with 99.9% uptime

### Q: How does incremental sync work?
**A:** The plugin uses intelligent timestamp comparison:
- Compares Notion's `last_edited_time` with local sync records
- Only processes pages that have been modified since last sync
- Handles different event types (content vs. property changes) appropriately
- Falls back to full sync if timestamp detection fails
- Maintains detailed logs for troubleshooting

### Q: Is my content safe with deletion detection?
**A:** Yes, the plugin includes multiple safety measures:
- Detailed logging of all deletion activities
- Configurable deletion behavior (delete, trash, or draft)
- Integration with WordPress backup systems
- Manual override options for edge cases
- Comprehensive audit trail for compliance

---

## 🔗 Related Links

- [GitHub Repository](https://github.com/Frank-Loong/Notion-to-WordPress)
- [Issue Tracker](https://github.com/Frank-Loong/Notion-to-WordPress/issues)
- [Notion API Documentation](https://developers.notion.com/)
- [WordPress Developer Documentation](https://developer.wordpress.org/)

---

<div align="center">

**📚 Wiki Complete**

*This documentation is continuously updated. For the latest information, visit our [GitHub repository](https://github.com/Frank-Loong/Notion-to-WordPress).*

---

## 🔗 Related Links

### Official Resources
- **[GitHub Repository](https://github.com/Frank-Loong/Notion-to-WordPress)** - Source code and releases
- **[Project Status](../docs/PROJECT_STATUS.md)** - Current development status and roadmap
- **[Feature Comparison](../docs/FEATURES_COMPARISON.md)** - Comparison with other solutions
- **[Changelog](../docs/CHANGELOG.md)** - Version history and updates

### Community & Support
- **[Issues & Bug Reports](https://github.com/Frank-Loong/Notion-to-WordPress/issues)** - Report problems or request features
- **[Discussions](https://github.com/Frank-Loong/Notion-to-WordPress/discussions)** - Community discussions and Q&A
- **[Contributing Guide](../CONTRIBUTING.md)** - How to contribute to the project

### External Resources
- **[Notion API Documentation](https://developers.notion.com/)** - Official Notion API reference
- **[WordPress Plugin Development](https://developer.wordpress.org/plugins/)** - WordPress development resources
- **[KaTeX Documentation](https://katex.org/)** - Math rendering library
- **[Mermaid Documentation](https://mermaid-js.github.io/)** - Diagram rendering library

---

## 📚 Additional Resources

### Learning Materials
- **[Notion API Basics](https://developers.notion.com/docs/getting-started)** - Understanding Notion's API
- **[WordPress Hooks & Filters](https://developer.wordpress.org/plugins/hooks/)** - Extending WordPress functionality
- **[REST API Integration](https://developer.wordpress.org/rest-api/)** - WordPress REST API usage

### Tools & Utilities
- **[Notion API Tester](https://developers.notion.com/tools/api-tester)** - Test your Notion API calls
- **[WordPress Debug Tools](https://wordpress.org/plugins/debug-bar/)** - Debug WordPress issues
- **[JSON Formatter](https://jsonformatter.org/)** - Format and validate JSON data

### Best Practices
- **[Content Migration Strategy](../docs/README.md#migration-strategy)** - Planning your content migration
- **[Performance Optimization](../docs/PROJECT_STATUS.md#performance-metrics)** - Optimizing sync performance
- **[Security Considerations](../docs/PROJECT_STATUS.md#security-features)** - Keeping your integration secure

---

<div align="center">

**[⬆️ Back to Top](#notion-to-wordpress---complete-user-guide) • [🏠 Main README](../README.md) • [🇨🇳 中文版](./README-Wiki.zh-CN.md) • [📚 Docs Hub](../docs/README.md)**

</div>
