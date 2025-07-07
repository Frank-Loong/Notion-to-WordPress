---
**📖 导航：** [🏠 主页](README-zh_CN.md) • [📚 使用指南](wiki/README-Wiki.zh-CN.md) • [📊 项目状态](docs/PROJECT_STATUS-zh_CN.md) • [🔄 更新日志](docs/CHANGELOG-zh_CN.md) • [⚖️ 功能对比](docs/FEATURES_COMPARISON-zh_CN.md) • **🤝 贡献指南**

**🌐 语言：** [English](CONTRIBUTING.md) • **中文**
---

# Notion·to·WordPress 贡献指南

🎉 **感谢您对 Notion to WordPress 项目的关注！**

这个项目因为像您这样的贡献者而蓬勃发展。无论您是修复bug、添加功能、改进文档，还是帮助其他用户，每一份贡献都很重要。

## 🚀 贡献者快速开始

### 开发环境搭建
```bash
# 克隆仓库
git clone https://github.com/Frank-Loong/Notion-to-WordPress.git
cd Notion-to-WordPress

# 创建开发分支
git checkout -b feature/your-feature-name
```

**环境要求：**
- WordPress 6.0+ (用于测试)
- PHP 8.0+ (需要 curl、mbstring 扩展)
- Notion 账户 (用于测试集成)

## 🎯 贡献方式

### 1. 🐛 报告Bug
**报告前请：**
- 搜索现有问题避免重复
- 使用最新版本测试
- 收集详细信息

**需要包含的信息：**
- WordPress版本、PHP版本、插件版本
- 重现步骤（详细的分步说明）
- 期望行为 vs 实际行为
- 错误信息或截图
- 浏览器/环境详情（如相关）

**使用 [GitHub Issues](https://github.com/Frank-Loong/Notion-to-WordPress/issues) 提交bug报告。**

### 2. ✨ 功能建议
- 首先检查现有问题/讨论
- 考虑是否符合插件范围
- 包含使用场景、建议方案和示例
- **使用 [GitHub Discussions](https://github.com/Frank-Loong/Notion-to-WordPress/discussions) 讨论功能想法**

### 3. 🔧 提交拉取请求 (PR)
**代码标准：**
- 遵循 [WordPress 编码标准](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- 兼容 PSR-12 标准（适用时）
- 为复杂逻辑和公共方法添加文档
- 清理输入、转义输出、验证数据

**PR 流程：**
1. Fork 仓库并创建功能分支
2. 按照代码标准进行修改
3. 彻底测试（需要手动测试）
4. 如需要更新文档
5. 提交带有清晰描述的PR

**测试清单：**
- [ ] 完成手动测试
- [ ] 测试边缘情况
- [ ] 跨浏览器测试（如有UI更改）
- [ ] WordPress兼容性测试

### 4. 📚 文档和翻译
**文档领域：**
- Wiki页面和教程
- 代码注释和内联文档
- README和指南
- 故障排除内容

**翻译支持：**
- 当前支持：英语 (en_US)、简体中文 (zh_CN)
- 使用 .pot 文件帮助添加新语言
- 在WordPress中测试翻译

### 5. 💬 交流沟通
- **一般问题**：[GitHub Discussions](https://github.com/Frank-Loong/Notion-to-WordPress/discussions)
- **Bug报告**：[GitHub Issues](https://github.com/Frank-Loong/Notion-to-WordPress/issues)
- **安全问题**：请直接通过邮箱 <a href="mailto:frankloong@qq.com">frankloong@qq.com</a> 联系维护者。

## 🏆 认可与社区

### 贡献者认可
所有贡献者都会在以下地方得到认可：
- README 贡献者部分
- 更新日志中的具体贡献
- GitHub 贡献者图表

### 社区准则
- **相互尊重**：欢迎所有背景的贡献者
- **建设性反馈**：提供有用的、可操作的建议
- **专业语调**：保持尊重的沟通
- 遵循 [WordPress 社区行为准则](https://make.wordpress.org/handbook/community-code-of-conduct/)

## 🛠️ 开发资源

### 有用链接
- [WordPress 插件开发](https://developer.wordpress.org/plugins/)
- [Notion API 文档](https://developers.notion.com/)
- [插件架构指南](./wiki/README-Wiki.zh-CN.md)

### 新手友好问题
寻找以下标签：
- `good first issue`：适合新手的问题
- `help wanted`：欢迎社区贡献
- `documentation`：改进文档和指南
- `translation`：帮助国际化

## 📋 代码示例

### ✅ 好的代码风格
```php
// WordPress 风格与现代 PHP
class Notion_To_WordPress_Sync {
    /**
     * 处理同步操作并进行错误处理
     *
     * @since 1.1.0
     * @param array $pages Notion页面数组
     * @return array 同步结果
     */
    public function process_sync(array $pages): array {
        $results = [];

        foreach ($pages as $page) {
            try {
                $result = $this->sync_single_page($page);
                $results[] = $result;
            } catch (Exception $e) {
                Notion_To_WordPress_Helper::error_log(
                    '页面同步失败: ' . $e->getMessage()
                );
            }
        }

        return $results;
    }
}

// 正确的清理和转义
$user_input = sanitize_text_field($_POST['notion_api_key']);
echo '<p>' . esc_html($message) . '</p>';
```

### ❌ 避免的代码风格
```php
// 没有清理
$api_key = $_POST['notion_api_key'];
echo '<p>' . $message . '</p>';
```

## 🎯 开始贡献

准备好贡献了吗？以下是步骤：

1. **🍴 Fork 仓库**
2. **🔧 搭建开发环境**
3. **🎯 选择问题**（或创建新问题）
4. **💻 进行修改**
5. **🧪 彻底测试**
6. **📝 提交拉取请求**

## 💬 有问题？

- **一般问题**：[GitHub Discussions](https://github.com/Frank-Loong/Notion-to-WordPress/discussions)
- **Bug报告**：[GitHub Issues](https://github.com/Frank-Loong/Notion-to-WordPress/issues)
- **功能想法**：[GitHub Discussions](https://github.com/Frank-Loong/Notion-to-WordPress/discussions)

## 许可证
所有贡献都按照项目的 GPL v3 或更高版本许可证进行许可。

---

<div align="center">

**感谢您为 Notion·to·WordPress 做出贡献！🚀**

*让我们一起构建最好的 Notion-to-WordPress 集成解决方案。*

**[⬆️ 返回顶部](#为-notiontowordpress-做贡献) • [🏠 主 README](./README-zh_CN.md) • [🇺🇸 English](./CONTRIBUTING.md) • [📚 文档中心](./docs/README-zh_CN.md)**

</div>