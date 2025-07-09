**🏠 主页** • [📚 使用指南](wiki/README-Wiki.zh-CN.md) • [📊 项目状态](docs/PROJECT_STATUS-zh_CN.md) • [🔄 更新日志](docs/CHANGELOG-zh_CN.md) • [⚖️ 功能对比](docs/FEATURES_COMPARISON-zh_CN.md) • [🤝 贡献指南](CONTRIBUTING-zh_CN.md)

**🌐 语言：** [English](README.md) • **中文**

---

# <img src="assets/icon.svg" width="40" height="40" align="top"> Notion·to·WordPress

> 🚀 一键将 Notion 变身 WordPress — 告别复制粘贴，实现全自动内容发布与同步

![GitHub stars](https://img.shields.io/github/stars/Frank-Loong/Notion-to-WordPress?style=social) ![GitHub release (latest by tag)](https://img.shields.io/github/v/tag/Frank-Loong/Notion-to-WordPress) ![License](https://img.shields.io/github/license/Frank-Loong/Notion-to-WordPress)

---

## 简介
**Notion·to·WordPress** 是一款现代化 WordPress 插件，让你无需写一行代码，就能把 Notion 数据库里的文章、页面、图片、数学公式、Mermaid 图表等内容批量同步到 WordPress，并保持实时更新。

*告别重复粘贴，专注内容创作，让 Notion 成为你的「内容 CMS」，WordPress 负责高可用发布与 SEO。*

---

## 核心特性
- **⚡ 极速同步**：支持手动、一键刷新、定时 Cron 以及 Notion Webhook 四种触发方式
- **🧠 智能增量同步**：仅同步变更内容，性能提升 80%+
- **🔄 三重同步模式**：手动控制 + 自动调度 + 实时 Webhook
- **🗑️ 智能删除检测**：自动清理已删除的 Notion 页面
- **🧠 智能映射**：可视化字段映射，轻松绑定分类、标签、自定义字段与特色图
- **📐 完美排版**：KaTeX 数学公式、mhchem 化学式、Mermaid 流程 / 时序图原生渲染
- **🔒 安全稳定**：严格 nonce、权限 与 CSP 校验，附件下载自动校验 MIME & 大小
- **🗂 多场景支持**：博客、知识库、团队协作、课程站点、一键搞定
- **🌍 多语言**：内置 i18n，现已支持简体中文 / English
- **📝 一键卸载**：可选清理所有设置与日志，干净无残留

> 想了解所有高级玩法？访问 [Wiki 📚](./wiki/README-Wiki.zh-CN.md) – [中文](./wiki/README-Wiki.zh-CN.md) | [English](./wiki/README-Wiki.md)

---

## 快速上手

### 📸 项目演示
<div align="center">
  <img src="docs/images/demo-overview.gif" alt="Notion to WordPress Demo-Overview" width="800">
  <p><em>🎬 Notion to WordPress 后台界面演示</em></p>
</div>

### 🚀 三步上手
1. **安装**：下载 ZIP → WordPress 后台上传 → 激活插件
2. **配置**：在「Notion to WordPress」菜单中填写 *Internal Integration Token* 及 *Database ID*
3. **同步**：点击「手动同步」或等待自动/Webhook 触发，Notion 内容即刻出现在 WordPress！

### 📋 Notion 数据库模板
我们为你准备了开箱即用的 Notion 数据库模板：

| 模板类型 | 链接 |
|---------|------|
| 📝 **中文模板** | [复制模板](https://frankloong.notion.site/22a7544376be808abbadc3d09fde153d?v=22a7544376be813dba3c000c778c0099&source=copy_link) |
| 📚 **English template** | [复制模板](https://frankloong.notion.site/22a7544376be80799930fc75da738a5b?v=22a7544376be819793c8000cc2623ae3&source=copy_link) |

#### 🔗 **NotionNext 兼容性**
本项目已对 Notion 数据库做了完美兼容，**完全适配 [NotionNext](https://github.com/tangly1024/NotionNext)**！你也可以直接使用 NotionNext 提供的数据库模板：

| 模板类型 | 链接 |
|---------|------|
| 🇨🇳 **NotionNext 中文模板** | [NotionNext 博客](https://tanghh.notion.site/02ab3b8678004aa69e9e415905ef32a5?v=b7eb215720224ca5827bfaa5ef82cf2d) |
| 🇺🇸 **NotionNext English Template** | [NotionNext Blog](https://www.notion.so/tanghh/7c1d570661754c8fbc568e00a01fd70e?v=8c801924de3840b3814aea6f13c8484f&pvs=4) |

> 🚀 **双平台发布**：通过 NotionNext 兼容性，你可以在 Notion 写作，文章实时同步至 **NotionNext 和 WordPress 两个平台**！为内容创作者提供最大化的平台覆盖。

> 💡 **提示**：复制模板后，记得在 Notion 中将你的集成添加到数据库中！

### 🎥 图文教程
> 详细图文教程请见 [Wiki · 快速上手](./wiki/README-Wiki.zh-CN.md#快速上手) 获取：
> - 📷 **逐步截图指南**
> - 🎬 **动图演示教程**
> - 🔧 **常见问题解决**

---

## 🚀 高级功能

### **三重同步能力**
| 同步模式 | 使用场景 | 性能 | 实时性 |
|-----------|-------------|-------------|-----------|
| **🖱️ 手动同步** | 按需控制 | 即时 | ✅ |
| **⏰ 定时同步** | 自动化设置 | 后台 | ⏰ |
| **⚡ Webhook同步** | 实时更新 | 实时 | ⚡ |

### **智能同步技术**
- **增量同步**：仅处理变更内容（快80%+）
- **内容感知检测**：处理文本、图片、公式和代码
- **智能删除**：自动清理孤立的WordPress文章
- **冲突解决**：优雅处理并发编辑

## 常见场景
| 场景 | 操作 | 结果 |
| ---- | ---- | ---- |
| 博客双写 | Notion 写草稿，WordPress 自动发布 | 节省排版时间 80%+ |
| 多人团队 | 给每位作者分配 Notion Page | WordPress 权限 & SEO 无缝继承 |
| 在线课程 | Mermaid + KaTeX + PDF 统一托管 | 复杂内容也能一键同步 |
| 实时发布 | Webhook触发即时同步 | 边写边发布 |

---

## 📈 性能与可靠性

### **基准测试结果**
- **同步速度**：增量同步快80%+
- **内存使用**：针对大型数据库优化（1000+页面）
- **错误恢复**：高级错误处理与详细日志
- **运行时间**：生产环境99.9%可靠性

### **企业级特性**
- ✅ **全面日志记录** 3 级调试系统
- ✅ **健壮错误处理** 自动恢复机制
- ✅ **安全加固** 遵循 WordPress 标准
- ✅ **性能优化** 适用于高流量站点
- ✅ **备份友好** 干净卸载选项

## 📚 文档资源

### 📖 **完整指南**
- **[📖 完整 Wiki](./wiki/README-Wiki.md)** - 综合使用指南 (English)
- **[🇨🇳 中文 Wiki](./wiki/README-Wiki.zh-CN.md)** - 完整中文使用指南
- **[📚 文档中心](./docs/README-zh_CN.md)** - 所有文档索引

### 🛠️ **开发相关**
- **[🤝 贡献指南](./CONTRIBUTING-zh_CN.md)** - 如何贡献项目
- **[🇺🇸 Contributing](./CONTRIBUTING.md)** - How to contribute (English)

### 📊 **项目信息**
- **[📋 更新日志](./docs/CHANGELOG-zh_CN.md)** - 版本历史和更新
- **[📊 项目状态](./docs/PROJECT_STATUS-zh_CN.md)** - 当前项目状态
- **[🏆 功能对比](./docs/FEATURES_COMPARISON-zh_CN.md)** - 为什么选择我们

## 🌟 Star 历史

[![Star History Chart](https://api.star-history.com/svg?repos=Frank-Loong/Notion-to-WordPress&type=Date)](https://star-history.com/#Frank-Loong/Notion-to-WordPress&Date)

## 贡献 & Star
如果这个项目帮助到了你，请 **点个 ⭐Star 支持一下**！同时欢迎 PR、Issue、翻译与任何形式的贡献。

- [贡献指南](./CONTRIBUTING-zh_CN.md)
- [提交 Issue](https://github.com/Frank-Loong/Notion-to-WordPress/issues)
- [功能请求](https://github.com/Frank-Loong/Notion-to-WordPress/discussions)

---

## 致谢与参考

本项目的开发过程中参考了以下优秀的开源项目，在此表示感谢：

- **[NotionNext](https://github.com/tangly1024/NotionNext)** - 基于 Notion 的强大静态博客系统，为 Notion API 集成和内容处理提供了宝贵的参考
- **[Elog](https://github.com/LetTTGACO/elog)** - 支持多平台的开源博客写作客户端，为多平台内容同步提供了优秀的参考方案
- **[notion-content](https://github.com/pchang78/notion-content)** - 内容管理解决方案，帮助我们完善了 Notion 内容处理的方法

感谢这些项目及其维护者对开源社区的贡献，正是有了他们的努力，才让本项目得以实现。

---

## License
GPL-3.0-or-later

> © 2025 Frank-Loong · Notion·to·WordPress v1.1.0