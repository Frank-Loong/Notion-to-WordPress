** **🏠 Home** • [📚 User Guide](wiki/README-Wiki.md) • [📊 Project Status](docs/PROJECT_STATUS.md) • [🔄 Changelog](docs/CHANGELOG.md) • [⚖️ Feature Comparison](docs/FEATURES_COMPARISON.md) • [🤝 Contributing](CONTRIBUTING.md)

**🌐 Language:** **English** • [中文](README-zh_CN.md)

---

# <img src="assets/icon.svg" width="80" height="80" align="center"> Notion-to-WordPress

> 🚀 Transform Notion into WordPress with one click — Say goodbye to copy-pasting and achieve fully automated content publishing and synchronization

![GitHub Stars](https://img.shields.io/github/stars/Frank-Loong/Notion-to-WordPress?style=social) ![Release](https://img.shields.io/github/v/tag/Frank-Loong/Notion-to-WordPress) ![License](https://img.shields.io/github/license/Frank-Loong/Notion-to-WordPress)

---

## Overview
**Notion-to-WordPress** is a modern WP plugin that syncs every block of your Notion database—posts, pages, images, math, Mermaid charts—straight to WordPress and keeps them in perfect harmony.

*Write in Notion, rank with WordPress. Stop copying, start creating.*

---

## Highlights
- **⚡ Lightning-fast import** – manual, one-click refresh, scheduled Cron, or instant Webhook
- **🧠 Smart incremental sync** – only syncs changed content, 80%+ performance boost
- **🔄 Triple sync modes** – Manual control + Automated scheduling + Real-time webhooks
- **🗑️ Intelligent deletion detection** – automatically cleans up removed Notion pages
- **🧠 Visual field mapping** – bind Notion properties to categories, tags, custom fields & featured image
- **📐 Pixel-perfect rendering** – KaTeX math, mhchem, Mermaid flow & sequence diagrams out-of-the-box
- **🔒 Secure by design** – nonce + capability checks, strict CSP, MIME & size validation for downloads
- **🗂 Fits every scenario** – blogs, knowledge bases, course sites, team collaboration, you name it
- **🌍 Multilingual** – i18n built-in (English & Simplified Chinese)
- **📝 Clean uninstall** – optional removal of settings, logs & imported content

> Dive deeper? Check the [Wiki 📚](./wiki/README-Wiki.md) – English | [中文](./wiki/README-Wiki.zh-CN.md)

---

## Quick Start

### 📸 Project Demo
<!-- TODO: Add project demo screenshots/GIFs -->
<div align="center">
  <img src="docs/images/demo-overview-en.gif" alt="Notion to WordPress Demo-Overview" width="800">
  <p><em>🎬 Notion to WordPress backend interface demonstration</em></p>
</div>

### 🚀 3-Step Setup
1. **Install** – upload the ZIP in `Plugins → Add New` and activate.
2. **Configure** – paste your *Internal Integration Token* & *Database ID* under "Notion to WordPress".
3. **Sync** – click "Manual Sync" or wait for Cron/Webhook; your Notion content appears in WordPress.

### 📋 Notion Database Templates
We've prepared ready-to-use Notion database templates for you:

| Template Type | Link |
|---------|------|
| 📝 **Chinese template** | [Copy the template](https://frankloong.notion.site/22a7544376be808abbadc3d09fde153d?v=22a7544376be813dba3c000c778c0099&source=copy_link) |
| 📚 **English template** | [Copy the template](https://frankloong.notion.site/22a7544376be80799930fc75da738a5b?v=22a7544376be819793c8000cc2623ae3&source=copy_link) |

#### 🔗 **NotionNext Compatibility**
Our plugin is **fully compatible with [NotionNext](https://github.com/tangly1024/NotionNext)** database schemas! You can also use NotionNext's official templates:

| Template Type | Link |
|---------|------|
| 🇨🇳 **NotionNext 中文模板** | [NotionNext 博客](https://tanghh.notion.site/02ab3b8678004aa69e9e415905ef32a5?v=b7eb215720224ca5827bfaa5ef82cf2d) |
| 🇺🇸 **NotionNext English Template** | [NotionNext Blog](https://www.notion.so/tanghh/7c1d570661754c8fbc568e00a01fd70e?v=8c801924de3840b3814aea6f13c8484f&pvs=4) |

> 🚀 **Dual Platform Publishing**: With NotionNext compatibility, you can write in Notion and automatically sync to **both NotionNext and WordPress** simultaneously! Perfect for content creators who want maximum reach across platforms.

> 💡 **Tip**: After copying the template, remember to invite your integration to access the database in Notion!

### 🎥 Visual Tutorials
> See the [Getting Started guide](./wiki/README-Wiki.md#getting-started) for:
> - 📷 **Step-by-step screenshots**
> - 🎬 **Animated tutorials**
> - 🔧 **Common issue solutions**

---

## 🚀 Advanced Features

### **Triple Sync Power**
| Sync Mode | When to Use | Performance | Real-time |
|-----------|-------------|-------------|-----------|
| **🖱️ Manual Sync** | On-demand control | Instant | ✅ |
| **⏰ Scheduled Sync** | Set-and-forget automation | Background | ⏰ |
| **⚡ Webhook Sync** | Live updates as you type | Real-time | ⚡ |

### **Smart Sync Technology**
- **Incremental Sync**: Only processes changed content (80%+ faster)
- **Content-Aware Detection**: Handles text, images, formulas, and code
- **Deletion Intelligence**: Automatically removes orphaned WordPress posts
- **Conflict Resolution**: Handles concurrent edits gracefully

## Typical Workflows
| Use-case | How it works | Benefit |
| --- | --- | --- |
| Dual-write blog | Draft in Notion, auto-publish in WP | Save >80 % formatting time |
| Team blogging | Give each author a Notion page | WP roles & SEO preserved |
| Online course | Mermaid, KaTeX, PDF embedded | Complex content, one-click sync |
| Real-time publishing | Webhook triggers instant sync | Live updates as you type |

---

## 📈 Performance & Reliability

### **Benchmark Results**
- **Sync Speed**: 80%+ faster with incremental sync
- **Memory Usage**: Optimized for large databases (1000+ pages)
- **Error Recovery**: Advanced error handling with detailed logging
- **Uptime**: 99.9% reliability in production environments

### **Enterprise Ready**
- ✅ **Comprehensive logging** with 3-level debug system
- ✅ **Robust error handling** with automatic recovery
- ✅ **Security hardened** following WordPress standards
- ✅ **Performance optimized** for high-traffic sites
- ✅ **Backup-friendly** with clean uninstall options

## 📚 Documentation

### 📖 **Complete Guides**
- **[📖 Complete Wiki](./wiki/README-Wiki.md)** - Comprehensive usage guide
- **[🇨🇳 中文Wiki](./wiki/README-Wiki.zh-CN.md)** - 完整中文使用指南
- **[📚 Documentation Hub](./docs/README.md)** - All documentation index

### 🛠️ **Development**
- **[🤝 Contributing](./CONTRIBUTING.md)** - How to contribute to the project
- **[🇨🇳 贡献指南](./CONTRIBUTING-zh_CN.md)** - 如何贡献项目

### 📊 **Project Information**
- **[📋 Changelog](./docs/CHANGELOG.md)** - Version history and updates
- **[📊 Project Status](./docs/PROJECT_STATUS.md)** - Current project status
- **[🏆 Feature Comparison](./docs/FEATURES_COMPARISON.md)** - Why choose us

## 🌟 Star History

[![Star History Chart](https://api.star-history.com/svg?repos=Frank-Loong/Notion-to-WordPress&type=Date)](https://star-history.com/#Frank-Loong/Notion-to-WordPress&Date)

## Contributing ⭐
If this project helps you, please smash that **Star**! PRs, issues, translations and ideas are warmly welcome.

* [Contributing Guide](./CONTRIBUTING.md)
* [Open an Issue](https://github.com/Frank-Loong/Notion-to-WordPress/issues)
* [Feature Requests](https://github.com/Frank-Loong/Notion-to-WordPress/discussions)

---

## Acknowledgments

This project was inspired by and references the following excellent open-source projects:

- **[NotionNext](https://github.com/tangly1024/NotionNext)** - A powerful static blog system based on Notion, providing valuable insights for Notion API integration and content processing
- **[Elog](https://github.com/LetTTGACO/elog)** - An open-source blog writing client that supports multiple platforms, offering great reference for multi-platform content synchronization
- **[notion-content](https://github.com/pchang78/notion-content)** - Content management solutions that helped shape our approach to Notion content handling

We extend our heartfelt gratitude to these projects and their maintainers for their contributions to the open-source community, which made this project possible.

---

## License
GPL-3.0-or-later

> © 2025 Frank-Loong · Notion-to-WordPress v1.1.0