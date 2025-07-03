# Notion·to-WordPress

A modern, professional WordPress plugin for synchronizing Notion database content to your WordPress site. Supports automatic/manual sync, Webhook, MathJax, Mermaid diagrams, field mapping, and more.

---

**Version: 1.0.8**  
**Author: Frank-Loong**  
**GitHub: https://github.com/Frank-Loong/Notion-to-WordPress**

---

## Introduction
Notion·to-WordPress provides a one-stop solution for creators, teams, and developers to automatically publish and synchronize Notion content to WordPress. It supports various content formats and is compatible with most themes, suitable for knowledge bases, blogs, and team collaboration.

## Features
- **Automatic/Manual Sync**: Schedule or one-click import of Notion database content to WordPress
- **Webhook Support**: Automatically trigger sync when Notion content changes
- **Advanced Format Compatibility**: c, Mermaid diagrams, code blocks, tables, images, etc.
- **Field Mapping**: Custom mapping between Notion properties and WordPress fields
- **Categories/Tags/Featured Images**: Auto-detection and sync
- **Concurrency Lock**: Prevent duplicate imports, ensure data consistency
- **Multi-language Support**: Built-in i18n
- **Security & Permissions**: Strict permission and nonce validation
- **Uninstall Cleanup**: Optionally delete all synced content and settings

## Installation
1. Download and upload the plugin to `/wp-content/plugins/`
2. Activate in the WordPress admin "Plugins" page
3. Go to the "Notion to WordPress" settings page, enter your API key and database ID
4. Configure sync schedule, field mapping, and other advanced options
5. Save settings and click "Manual Sync" or wait for automatic sync

## Configuration
- **Get Notion API Key**: Create an integration at [Notion Developer Platform](https://www.notion.so/my-integrations) and copy the API key
- **Get Database ID**: In your Notion database, click "Share", add the integration, and copy the database ID

## Usage
- **Manual Sync**: Click "Manual Sync" in the admin to import all content immediately
- **Automatic Sync**: Content is synced automatically based on your schedule
- **Webhook**: Configure a webhook to push Notion changes to WordPress in real time
- **Field Mapping**: Map Notion properties to WP fields for flexible structure
- **Content Management**: Synced content can be edited, published, or deleted in WordPress

## API Reference
### 1. AJAX Actions (WordPress Admin)
- `notion_manual_import`: Manually sync Notion database to WordPress
- `notion_test_connection`: Test API key and database ID
- `notion_to_wordpress_refresh_all`: Refresh all content
- `notion_to_wordpress_refresh_single`: Refresh a single page
- `notion_to_wordpress_get_stats`: Get sync statistics

**Request:** POST, with nonce and required params, returns JSON.

**Example:**
```js
jQuery.post(ajaxurl, {
  action: 'notion_manual_import',
  nonce: 'xxx'
}, function(resp) {
  // handle response
});
```

### 2. Webhook
Custom webhook triggers are supported. See the settings page for details.

## FAQ
**Q: Math formulas or diagrams not displaying?**  
A: Ensure your theme supports custom CSS/JS and there are no conflicts with other plugins.

**Q: How to sync only part of the content?**  
A: Use Notion filters or customize field mapping and filtering logic.

**Q: What data is deleted on uninstall?**  
A: Optionally delete all synced content, settings, and metadata. See "Uninstall Settings".

**Q: Multisite/multidatabase support?**  
A: Multisite is supported. One instance per Notion database is recommended.

## Contribution
Contributions are welcome! Please read [CONTRIBUTING.md](./CONTRIBUTING.md) before submitting issues or pull requests.

## License
This plugin is licensed under GPL v3 or later. See LICENSE for details.

---

> © 2024 Frank-Loong. Notion·to-WordPress v1.0.8 