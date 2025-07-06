<!-- Lang Switch -->
<p align="right">
  English | <a href="./Webhook-Setup.zh-CN.md">中文</a>
</p>

# Webhook Guide

Use Notion's official Webhook to push updates to WordPress **within seconds**, no Cron delay required.

---

## 1. Requirements
- Plugin version `v1.0.9+`
- A valid **Internal Integration Token**
- Your WP site is publicly reachable (HTTPS recommended)

---

## 2. Enable Webhook in the Plugin
1. In the settings page check **Enable Webhook**
2. Click **Generate token** – a 32-char secure string will appear
3. Save settings; you'll see an endpoint like:
   ```text
   https://your-site.com/wp-json/notion-to-wordpress/v1/webhook/{token}
   ```

---

## 3. Subscribe in Notion (Beta)
> Webhook support is currently in developer beta – join [Notion Developer Beta](https://developers.notion.com/beta).

1. Integration → *Webhook* tab
2. Select events: **`page.updated`**, **`block.updated`** (all recommended)
3. Paste the callback URL
4. Hit *Save* – Notion immediately sends a verification request; the plugin stores the `verification_token` automatically (visible in logs)
5. Done!

> Using a CDN or WAF? Whitelist the endpoint to avoid 403s.

---

## 4. Test
1. Edit any title in the database → save
2. Open **Sync Logs** in WP admin – you should see `Webhook trigger import_pages()`
3. The corresponding post is updated – success!

---

## 5. FAQ
| Problem | Solution |
| ------- | -------- |
| No callbacks received | Check firewall / CDN, or tunnel locally with `ngrok` |
| 403 Forbidden | Token mismatch – copy the fresh URL again |
| "Invalid URL" in Notion | Use **HTTPS** & ensure the site is publicly accessible |

Still stuck? Attach log excerpts when opening an Issue. 