# Webhook 自动同步指南

通过 Notion 官方 Webhook，你可以在内容更新后 **秒级** 将改动推送到 WordPress，而无需等待 Cron。

---

## 一、前置条件
- 插件 `v1.0.9+`
- 有效的 **Internal Integration Token**
- WordPress 站点可被外网访问（用于接收 POST 请求）

---

## 二、启用 Webhook
1. 在插件设置页勾选 **启用 Webhook**
2. 点击 **生成 token**，系统会随机创建 32 位安全字符
3. 保存设置后，将看到示例回调 URL：
   ```
   https://your-site.com/wp-json/notion-to-wordpress/v1/webhook/{token}
   ```

---

## 三、在 Notion 配置订阅
> 当前 Notion Webhook 仍处于 Beta，需加入 [Notion Developer Beta](https://developers.notion.com/beta)。

1. 进入 Integration 设置 → *Webhook* tab
2. 选择事件：`page.updated`, `block.updated` 建议全部勾选
3. 回调 URL 填写上一步生成的地址
4. 点击 *Save*，Notion 会立即发送 `verification_token` 请求用于验证
5. 插件会自动保存并返回 `verification_token`（日志可见）

> 如果你的站点启用了 WAF / CDN，请确保放行该 Endpoint。

---

## 四、测试
1. 在 Notion 数据库修改任意标题 → 保存
2. 打开 WP 后台 *同步日志*，应看到 `Webhook trigger import_pages()` 日志
3. 对应文章被更新，说明配置成功

---

## 常见问题
| 问题 | 解决方案 |
| ---- | ---- |
| 收不到回调 | 检查服务器防火墙 / CDN，或使用 `ngrok` 本地调试 |
| 返回 403 | token 与插件保存的不一致，重新复制回调 URL |
| Notion 提示无效 URL | 确保使用 **HTTPS**，并能被外网直接访问 |

如仍有疑问，请附带日志文件在 GitHub Issue 提问。 