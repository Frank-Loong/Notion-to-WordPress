# Notion·to-WordPress

一个现代化的WordPress插件，实现Notion数据库内容与WordPress网站的高效同步，支持自动/手动同步、Webhook、数学公式、Mermaid图表等高级特性。

---

**版本：1.0.8**  
**作者：Frank-Loong**  
**GitHub：[https://github.com/Frank-Loong/Notion-to-WordPress](https://github.com/Frank-Loong/Notion-to-WordPress)**

---

## 插件简介
Notion·to-WordPress 致力于为内容创作者、团队和开发者提供一站式的Notion内容自动发布与同步解决方案。插件支持多种内容格式，兼容主流主题，适合知识库、博客、团队协作等多场景。

## 主要功能
- **自动/手动同步**：定时或一键将Notion数据库内容导入WordPress
- **Webhook支持**：Notion内容变更时自动触发同步
- **高级格式兼容**：支持Katex数学公式、Mermaid流程/序列图、代码块、表格、图片等
- **字段映射**：自定义Notion属性与WordPress字段的对应关系
- **分类/标签/特色图片**：自动识别并同步
- **并发锁机制**：防止重复导入，保障数据一致性
- **多语言支持**：内置i18n国际化
- **安全与权限**：严格的权限与nonce校验，保障数据安全
- **卸载清理**：可选彻底删除所有同步内容与设置

## 安装与配置
1. 下载插件并上传至 `/wp-content/plugins/` 目录
2. 在WordPress后台"插件"页面激活
3. 进入"Notion到WordPress"设置页，填写API密钥与数据库ID
4. 配置同步计划、字段映射等高级选项
5. 保存设置，点击"手动同步"或等待自动同步

### 获取Notion API密钥与数据库ID
- 访问 [Notion开发者平台](https://www.notion.so/my-integrations) 创建集成，获取API密钥
- 在Notion数据库页面点击"Share"，添加集成并复制数据库ID

## 使用说明
- **手动同步**：后台点击"手动同步"按钮，立即导入全部内容
- **自动同步**：根据设置的计划自动同步，无需人工干预
- **Webhook**：配置Webhook后，Notion内容变更可实时推送到WordPress
- **字段映射**：支持自定义Notion属性与WP字段的映射，适配不同数据库结构
- **内容管理**：同步后内容可在WordPress后台正常编辑、发布、删除

## 常见问题（FAQ）
**Q: 数学公式/图表无法正常显示？**  
A: 请确保主题支持自定义CSS/JS，且未与其他公式/图表插件冲突。

**Q: 如何只同步部分内容？**  
A: 可在Notion端设置筛选视图，或自定义字段映射与过滤逻辑。

**Q: 卸载插件会删除哪些数据？**  
A: 可选删除所有同步内容、设置及相关元数据，详见"卸载设置"。

**Q: 支持多站点/多数据库吗？**  
A: 支持多站点环境，单实例建议对应一个Notion数据库。

## 技术支持与反馈
- [GitHub Issue](https://github.com/Frank-Loong/Notion-to-WordPress/issues) 提交Bug/建议
- 邮箱联系：见GitHub主页

## 贡献方式
欢迎PR、Issue、文档完善、翻译等多种形式的贡献！请遵循本项目[贡献指南](https://github.com/Frank-Loong/Notion-to-WordPress/blob/main/CONTRIBUTING.md)。

## License
本插件采用GPL v3 or later许可证，详见LICENSE文件。

---

## English Documentation
See [README-EN.md](./README-EN.md) for English instructions and usage.

## API接口说明（API Reference）

### 1. AJAX Actions (for WordPress Admin)
- `notion_manual_import`：手动同步Notion数据库到WordPress
- `notion_test_connection`：测试API密钥与数据库ID有效性
- `notion_to_wordpress_refresh_all`：刷新全部内容
- `notion_to_wordpress_refresh_single`：刷新单个页面
- `notion_to_wordpress_get_stats`：获取同步统计信息

#### 请求方式
POST，需携带nonce和必要参数，返回JSON。

#### 示例：
```js
jQuery.post(ajaxurl, {
  action: 'notion_manual_import',
  nonce: 'xxx'
}, function(resp) {
  // 处理返回
});
```

### 2. Webhook
支持自定义Webhook触发同步，详见设置页面说明。

---

## 贡献指南（Contribution Guide）

欢迎任何形式的贡献，包括但不限于：
- 提交Bug报告（Issue）
- 新功能建议
- 代码PR（Pull Request）
- 文档完善与翻译

贡献前请阅读 [CONTRIBUTING.md](./CONTRIBUTING.md)。

---

> © 2024 Frank-Loong. Notion·to-WordPress v1.0.8