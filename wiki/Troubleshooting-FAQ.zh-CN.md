# 故障排查 & FAQ

本页收集了使用过程中最常见的错误与解决办法，持续更新中。

---

## 一、安装/激活问题
| 错误信息 | 可能原因 | 解决办法 |
| -------- | -------- | -------- |
| *插件启用后出现 Fatal error* | PHP 版本过低/缺扩展 | 升级到 PHP 8.0+ 并开启 `curl`、`mbstring` 扩展 |
| *上传失败: exceeds maximum upload size* | WP 限制上传大小 | 在 `php.ini` 中提高 `upload_max_filesize` / `post_max_size` |

---

## 二、同步报错
| 日志 / 提示 | 解释 | 解决方案 |
| ----------- | ---- | -------- |
| `API错误 (401): unauthorized` | Token 无效 | 重新生成 Integration Token 并更新设置 |
| `database is not accessible` | 未邀请集成访问数据库 | 打开 Notion → Share → 将集成加入 |
| `导入失败: Invalid page data` | 数据库存在空白行或权限受限页 | 确认数据完整性，或在 Notion 过滤无权限页 |
| `图片下载失败` | Notion 临时链接失效 / WP 没写入权限 | 检查 `wp-content/uploads` 权限，重新同步 |

---

## 三、前端展示
| 现象 | 排查 | 处理 |
| ---- | ---- | ---- |
| 数学公式不渲染 | 浏览器控制台是否加载 `katex.min.js` | 检查主题是否取消 `wp_footer()` 或合并脚本 |
| Mermaid 图表渲染空白 | 检查是否有 JS 报错 | 与其它 Mermaid 插件冲突，停用后再试 |
| 代码块高亮缺失 | 主题未加载 Prism/Highlight | 在主题或插件添加代码高亮库 |

---

## 四、常见疑问
1. **是否支持多数据库？** 当前版本一个站点绑定一个数据库，可通过多站点实现多数据库。
2. **能否只同步某 View？** 可以在 Notion 端为同步数据建立单独 View 并设置筛选条件。
3. **删除 Notion 页面会怎样？** 目前插件默认不删除 WP 文章，可在计划任务中自定义钩子实现。
4. **如何隐藏同步生成的元字段？** 使用 `ACF` 或代码过滤 `register_meta` 即可。

---

> 未解决的问题？请携带日志文件 / PHP 错误信息在 GitHub Issue 提出，我们会尽快回复。 