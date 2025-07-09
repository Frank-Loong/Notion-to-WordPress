# 🚀 自动化发布系统指南

本文档介绍了如何使用 Notion-to-WordPress 插件的自动化发布系统。

## 📋 概述

自动化发布系统提供了完整的 CI/CD 流水线，包含：

- ✅ **自动更新所有项目文件中的版本号**
- ✅ **构建符合 WordPress 标准的插件包**（ZIP 文件）
- ✅ **使用正确的版本号创建 Git 提交和标签**
- ✅ **触发 GitHub Actions 实现自动发布**
- ✅ **生成带有下载链接和发布说明的 GitHub Release**
- ✅ **提供包验证用的安全校验码**

## 🎯 快速开始

### 1. 本地发布（推荐）

使用发布控制器自动完成所有操作：

```bash
# 补丁发布（1.1.0 → 1.1.1）
npm run release:patch

# 小版本发布（1.1.0 → 1.2.0）
npm run release:minor

# 主版本发布（1.1.0 → 2.0.0）
npm run release:major

# Beta 预发布（1.1.0 → 1.1.1-beta.1）
npm run release:beta
```

### 2. 预览模式（演练）

无需实际更改即可测试发布流程：

```bash
# 预览补丁发布
npm run test:release patch

# 或直接使用发布脚本
node scripts/release.js patch --dry-run
```

## 🔧 工作原理

### 步骤 1：版本号更新
- 更新 `notion-to-wordpress.php` 中的版本号
- 更新 `readme.txt` 中的版本号
- 更新 `package.json` 中的版本号
- 更新类文件和文档中的版本号

### 步骤 2：构建插件包
- 创建符合 WordPress 标准的 ZIP 包
- 排除开发文件（脚本、文档等）
- 仅包含运行时所需文件
- 生成优化后的 1MB 包

### 步骤 3：Git 操作
- 创建包含版本变更的提交
- 创建版本标签（如 `v1.1.1`）
- 推送到 GitHub 仓库

### 步骤 4：GitHub Actions
- 通过推送版本标签自动触发
- 构建并验证插件包
- 创建包含以下内容的 GitHub Release：
  - ZIP 包下载链接
  - 安全校验码（SHA256、MD5）
  - 自动生成的发布说明
  - 安装说明

## 🛡️ 安全特性

### 环境校验
- 检查是否有未提交的更改
- 验证 Git 仓库状态
- 校验 Node.js 版本兼容性
- 确保所有必需工具可用

### 错误处理与回滚
- 变更前自动备份
- 任何失败时自动回滚
- 提供详细错误信息和日志
- 操作安全，带有确认提示

### 版本一致性
- 校验版本号格式（语义化版本）
- 确保所有文件版本一致
- 防止重复或无效发布

## 📦 发布类型

| 命令 | 版本变更 | 适用场景 |
|---------|---------------|----------|
| `release:patch` | 1.1.0 → 1.1.1 | Bug 修复、小幅改进 |
| `release:minor` | 1.1.0 → 1.2.0 | 新功能、增强 |
| `release:major` | 1.1.0 → 2.0.0 | 重大变更、主版本升级 |
| `release:beta` | 1.1.0 → 1.1.1-beta.1 | 测试、预发布版本 |

## 🔐 安全与验证

### 包校验码
每次发布都会包含安全校验码以便验证：

```bash
# 校验 SHA256
sha256sum notion-to-wordpress-1.1.1.zip

# 校验 MD5  
md5sum notion-to-wordpress-1.1.1.zip
```

### GitHub Token 权限
工作流仅使用最小所需权限：
- `contents: write` - 用于创建 Release 和上传文件
- `actions: read` - 用于访问工作流产物

## 🚨 故障排查

### 常见问题

**“工作目录有未提交的更改”**
```bash
# 先提交你的更改
git add .
git commit -m "Your changes"

# 或强制发布（不推荐）
node scripts/release.js patch --force
```

**“检测到版本不一致”**
- 确保所有文件的版本号一致
- 运行版本校验：`node scripts/version-bump.js`

**“构建失败”**
- 检查 Node.js 版本（需 16+）
- 校验依赖：`npm install`
- 手动测试构建：`npm run build`

### 调试模式

启用详细日志以便排查问题：

```bash
# 带完整输出的演练
dode scripts/release.js patch --dry-run --force

# 手动分步测试
node scripts/version-bump.js patch
npm run build
node scripts/validate-github-actions.js
```

## 📚 高级用法

### 自定义配置

系统可通过以下方式自定义：
- `package.json` - npm 脚本和依赖
- `.github/workflows/release.yml` - GitHub Actions 工作流
- `scripts/` - 各类工具配置

### 手动触发 GitHub Actions

如有需要，可手动触发发布工作流：

1. 进入 GitHub Actions 标签页
2. 选择“🚀 Release WordPress Plugin”工作流
3. 点击“Run workflow”
4. 输入标签名（如 `v1.1.1`）

### 与其他工具集成

发布系统可集成：
- **Git** - 版本控制与打标签
- **npm** - 包管理与脚本
- **GitHub** - 仓库托管与发布
- **WordPress** - 插件打包标准

## 🎉 成功标志

一次成功的发布将：
- ✅ 一致更新所有版本号
- ✅ 创建干净的 Git 提交和标签
- ✅ 生成 WordPress 兼容的 ZIP 包
- ✅ 触发 GitHub Actions 工作流
- ✅ 创建带下载的 GitHub Release
- ✅ 提供安全校验码
- ✅ 自动生成发布说明

## 📞 支持

如遇问题：
1. 先查阅本指南的常见问题
2. 查看 GitHub Actions 的工作流日志
3. 创建 issue 并详细描述错误
4. 附上相关日志输出和系统信息

---

**祝你发布顺利！🚀**