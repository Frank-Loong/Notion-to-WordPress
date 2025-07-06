<!-- Lang Switch -->
<p align="right">
  English | <a href="./Getting-Started.zh-CN.md">中文</a>
</p>

# Getting Started

Follow this **5-minute guide** to complete your first Notion → WordPress sync.

---

## Prerequisites
1. WordPress 6.0 or later (plugin install rights)
2. PHP 8.0 + with `curl`
3. A Notion account & edit access to the target database

---

## Step 1 – Create an Integration & Collect IDs
1. Open [Notion Integrations](https://www.notion.so/my-integrations) → **New integration**
2. Name it e.g. **WordPress Sync**, enable **Read content** & **Read user information**
3. Copy the **Internal Integration Token**
4. Go to your database → *Share* → invite the integration
5. Copy the **Database ID** from the URL (`https://www.notion.so/**DATABASE_ID**?v=...` – 32 chars)

> Tip: you can also use *Copy link* in the DB menu – the ID is before `?v=`.

---

## Step 2 – Install the Plugin
1. Grab the latest ZIP from GitHub Releases
2. `WP-Admin → Plugins → Add New → Upload`, then activate **Notion-to-WordPress**

---

## Step 3 – Configure
1. Sidebar → **Notion to WordPress**
2. Paste your *API Token* & *Database ID*
3. Pick a **Sync schedule** (start with *manual*)
4. Save settings

> For a quick test you can keep the default field mapping.

---

## Step 4 – First Sync
Hit **Manual Sync**, wait for the spinner, then refresh **Posts**: voilà – Notion content is live!

Stats, logs & errors update in real time inside the admin page.

---

## Common Pitfalls
| Symptom | Likely Cause | Fix |
| --- | --- | --- |
| `Invalid token` | Wrong / expired API token | Regenerate & update settings |
| Database not found | Integration not invited / database private | *Share* the DB with the integration |
| Math / charts not rendering | Theme blocked `katex`/`mermaid` assets | Make sure `wp_footer()` outputs them or disable conflicting plugins |

> Still stuck? Check the [Troubleshooting guide](./Troubleshooting-FAQ.md). 