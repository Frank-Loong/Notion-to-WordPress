=== Notion-to-WordPress ===
Contributors: Frank-Loong
Donate link: https://github.com/Frank-Loong/Notion-to-WordPress
Tags: notion, import, sync, api, math, mermaid, cms
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 1.0.9
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

== Description ==
Notion-to-WordPress is a modern plugin that mirrors any Notion database to WordPress.  
Write in Notion, publish on WordPress — zero copy-paste. Supports auto/manual sync, Webhook, KaTeX math, Mermaid diagrams, visual field mapping, media download and more.

*Key Features*
* Lightning-fast import – manual, one-click refresh, Cron, or instant Webhook
* Visual field mapping – connect Notion properties to categories, tags, custom fields & featured image
* Pixel-perfect rendering – KaTeX, mhchem, Mermaid, code blocks, tables, embeds
* Secure by design – nonce & capability checks, CSP, MIME/size validation
* Multilingual – English & Simplified Chinese ready
* Clean uninstall – optional wipe of settings, logs & imported posts

Need screenshots? See the GitHub README.

== Installation ==
1. Upload the ZIP to `/wp-content/plugins/` or install via **Plugins → Add New**.
2. Activate **Notion-to-WordPress**.
3. Open **Notion to WordPress** in the sidebar, paste your **Internal Integration Token** & **Database ID**.
4. Pick a **Sync schedule** (start with *Manual*) and save.

== Usage ==
* **Manual Sync** — click the button to import everything instantly.
* **Automatic Sync** — Cron schedule runs in background.
* **Webhook** — enable in settings, subscribe in Notion Developer Beta for real-time pushes.
* **Field Mapping** — map any Notion property to WP fields, including custom meta.

Detailed guides live in the [Wiki](https://github.com/Frank-Loong/Notion-to-WordPress/wiki).

== Frequently Asked Questions ==
= Math / Mermaid not rendered? =
Ensure your theme prints `wp_footer()` and doesn't block the assets.

= How to sync part of a database? =
Use a filtered view in Notion; the plugin respects it.

= What happens on uninstall? =
You can choose to remove all plugin data or keep imported posts.

== Screenshots ==
1. Admin – Sync Dashboard
2. Settings – API & Schedule
3. Front-end – Rendered Content
4. Webhook – Setup Screen

== Chinese Readme (中文说明) ==
完整中文文档请参阅仓库中的 [README-zh_CN.md](https://github.com/Frank-Loong/Notion-to-WordPress/blob/dev/README-zh_CN.md)。

== Changelog ==
= 1.0.9 =
* Core bug fixes, improved rendering, stronger logging & debugging.

== Support & Contribution ==
Issues, PRs, translations welcome!  
GitHub: https://github.com/Frank-Loong/Notion-to-WordPress

== License ==
This plugin is licensed under GPL v3 or later.

