# 🚀 自动化发布系统指南

欢迎使用 Notion-to-WordPress 自动化发布系统的完整指南！这个系统提供了完整的 CI/CD 流水线，让发布新版本变得像运行一个命令一样简单。

## 📋 系统概述

自动化发布系统将复杂的 WordPress 插件发布过程转化为流线型的一键操作。以下是它为您提供的功能：

### ✨ 核心特性

- **🔄 自动版本更新** - 在所有项目文件中一致地更新版本号
- **📦 WordPress 标准打包** - 创建优化的 ZIP 包，可直接在 WordPress 中安装
- **🏷️ Git 标签管理** - 自动创建和推送版本标签
- **🚀 GitHub 发布自动化** - 创建带有下载链接和发布说明的 GitHub 发布
- **🔐 安全校验和** - 生成 SHA256 和 MD5 校验和用于包验证
- **🛡️ 安全特性** - 完整的回滚功能和环境验证

### 🎯 支持的发布类型

| 发布类型 | 版本变化 | 使用场景 | 命令 |
|---------|---------|---------|------|
| **补丁版本** | 1.1.0 → 1.1.1 | 错误修复、小改进 | `npm run release:patch` |
| **小版本** | 1.1.0 → 1.2.0 | 新功能、增强功能 | `npm run release:minor` |
| **大版本** | 1.1.0 → 2.0.0 | 重大变更、主要更新 | `npm run release:major` |
| **测试版本** | 1.1.0 → 1.1.1-beta.1 | 测试、预发布版本 | `npm run release:beta` |
| **自定义** | 任何有效的语义版本 | 热修复、候选版本 | `npm run release:custom --version=X.Y.Z` |

## 🛠️ 安装与设置

### 前置要求

在使用自动化发布系统之前，请确保您有：

- **Node.js 16+**（推荐 18+）
- **Git** 并具有仓库访问权限
- **npm** 包管理器
- **GitHub 仓库** 并具有适当权限

### 快速设置

1. **安装依赖**
   ```bash
   npm install
   ```

2. **验证安装**
   ```bash
   npm run validate:config
   npm run validate:github-actions
   ```

3. **测试系统**
   ```bash
   npm run test:release patch
   ```

### GitHub 配置

对于自动 GitHub 发布，您需要：

1. **仓库权限**：确保您对仓库有写入权限
2. **GitHub Token**：系统在 GitHub Actions 中自动使用 `GITHUB_TOKEN`
3. **分支保护**：如需要，配置分支保护规则

## 📦 本地打包测试

在进行正式发布之前，你可以创建本地测试包来验证更改是否正常工作。

### 🎯 本地打包功能

- ✅ 更新版本号但不进行Git操作
- ✅ 创建WordPress测试用ZIP包
- ✅ 支持自定义版本号（如 `1.2.0-test.1`）
- ✅ 预览模式，应用前查看更改
- ✅ 自动备份，出错时自动回滚

### 🚀 快速本地打包

```bash
# 使用自定义版本号创建测试版本
npm run package:local --version=1.2.0-test.1

# 更新补丁版本并打包
npm run package:local patch

# 预览更改但不应用
npm run package:local --version=1.2.0-test.1 --dry-run

# 仅创建包（不更新版本）
npm run package:local --build-only
```

### 📋 可用的本地命令

| 命令 | 描述 | 示例 |
|------|------|------|
| `npm run package:local:patch` | 补丁版本更新 | 1.2.0 → 1.2.1 |
| `npm run package:local:minor` | 次版本更新 | 1.2.0 → 1.3.0 |
| `npm run package:local:major` | 主版本更新 | 1.2.0 → 2.0.0 |
| `npm run package:local:beta` | Beta版本更新 | 1.2.0 → 1.2.1-beta.1 |
| `npm run package:local --version=X.Y.Z` | 自定义版本 | 任何有效的语义版本 |
| `npm run package:local --build-only` | 仅打包 | 不更改版本 |
| `npm run package:local --version-only` | 仅更新版本 | 不创建包 |

### 🧪 测试工作流程

1. **创建测试包**
   ```bash
   npm run package:local --version=1.2.0-test.1
   ```

2. **在WordPress中测试**
   - 上传 `build/notion-to-wordpress-1.2.0-test.1.zip` 到WordPress
   - 验证所有功能正常工作

3. **满意后提交**
   ```bash
   git add .
   git commit -m "feat: 添加新功能"
   ```

4. **进行正式发布**
   ```bash
   # 标准发布
   npm run release:minor

   # 或自定义版本发布
   npm run release:custom --version=1.2.0-rc.1
   ```

### ⚠️ 本地打包注意事项

- **安全测试**：不进行Git操作，不会影响仓库
- **自动备份**：更改前创建备份，出错时自动回滚
- **文件更新**：自动更新所有相关文件中的版本号
- **干净输出**：生成的ZIP可直接用于WordPress安装

## �🚀 快速开始指南

### 您的第一次发布

1. **确保工作目录干净**
   ```bash
   git status  # 应该显示没有未提交的更改
   ```

2. **选择发布类型并执行**
   ```bash
   # 错误修复发布
   npm run release:patch

   # 功能发布
   npm run release:minor

   # 主要更新
   npm run release:major

   # Beta版本发布
   npm run release:beta

   # 自定义版本发布（热修复、候选版本等）
   npm run release:custom --version=1.2.0-hotfix.1
   npm run release:custom --version=1.3.0-rc.1
   npm run release:custom --version=2.0.0-alpha.1
   ```

3. **监控过程**
   - 脚本会显示每个步骤的进度
   - 在提示时确认（除非使用 `--force`）
   - 等待成功消息

4. **验证发布**
   - 检查 GitHub 上的新发布
   - 验证 ZIP 包可供下载
   - 在 WordPress 环境中测试包

### 预览模式（首次用户推荐）

始终先使用干运行模式测试：

```bash
# 预览补丁发布会做什么
npm run test:release patch

# 或直接使用发布脚本
node scripts/release.js patch --dry-run
```

## 📖 详细使用说明

### 命令行选项

发布系统支持多个命令行选项：

```bash
node scripts/release.js <类型> [选项]

# 发布类型：
#   patch     - 补丁发布 (1.1.0 → 1.1.1)
#   minor     - 小版本发布 (1.1.0 → 1.2.0)
#   major     - 大版本发布 (1.1.0 → 2.0.0)
#   beta      - 测试版发布 (1.1.0 → 1.1.1-beta.1)

# 选项：
#   --dry-run    预览更改而不执行
#   --force      跳过确认提示
#   --help       显示帮助信息
```

### 使用示例

```bash
# 带确认的标准发布
npm run release:patch
npm run release:minor

# 强制发布，无提示（CI/CD）
node scripts/release.js patch --force

# 预览主要发布
node scripts/release.js major --dry-run

# 测试版发布
npm run release:beta
```

### 发布过程中发生的事情

1. **环境验证**
   - 检查 Git 仓库状态
   - 验证 Node.js 版本兼容性
   - 确保所有必需工具可用
   - 验证工作目录干净

2. **版本管理**
   - 从主插件文件读取当前版本
   - 根据发布类型计算新版本
   - 更新所有相关文件中的版本：
     - `notion-to-wordpress.php`
     - `readme.txt`
     - `package.json`
     - `includes/class-notion-to-wordpress.php`
     - 文档文件

3. **包构建**
   - 创建 WordPress 标准 ZIP 包
   - 排除开发文件和文档
   - 仅包含运行时必需文件
   - 生成优化后的最简插件包

4. **Git 操作**
   - 创建版本更改的提交
   - 创建带注释的版本标签（如 `v1.1.1`）
   - 推送提交和标签到 GitHub

5. **GitHub Actions 触发**
   - 由版本标签推送自动触发
   - 构建和验证包
   - 创建 GitHub 发布，包含：
     - ZIP 包的下载链接
     - 安全校验和（SHA256、MD5）
     - 自动生成的发布说明
     - 安装说明

## ⚙️ 配置

### 发布配置文件

系统使用 `release.config.js` 进行配置。主要部分包括：

```javascript
// 项目信息
project: {
  name: 'notion-to-wordpress',
  displayName: 'Notion-to-WordPress',
  author: 'Frank-Loong'
}

// 版本管理
version: {
  files: [/* 要更新的文件 */],
  validation: {/* 验证规则 */}
}

// 构建设置
build: {
  output: {/* 输出配置 */},
  include: {/* 要包含的文件 */},
  exclude: {/* 要排除的文件 */}
}
```

### 自定义配置

1. **编辑配置**
   ```bash
   # 编辑配置文件
   nano release.config.js
   ```

2. **验证更改**
   ```bash
   npm run validate:config
   ```

3. **测试配置**
   ```bash
   node scripts/test-config.js
   ```

### 常见配置更改

- **更改输出目录**：修改 `build.output.directory`
- **添加/删除文件**：更新 `version.files` 数组
- **自定义提交消息**：编辑 `git.commitMessage.template`
- **修改 GitHub 设置**：更新 `github` 部分

## 🔧 故障排除

### 常见问题和解决方案

#### "工作目录有未提交的更改"

**问题**：Git 工作目录不干净

**解决方案**：
```bash
# 检查哪些文件被修改
git status

# 提交您的更改
git add .
git commit -m "您的更改"

# 或强制发布（不推荐）
node scripts/release.js patch --force
```

#### "检测到版本不匹配"

**问题**：文件间版本号不一致

**解决方案**：
```bash
# 检查版本一致性
node scripts/version-bump.js

# 手动修复或使用版本更新工具
node scripts/version-bump.js patch
```

#### "构建失败"

**问题**：包构建遇到错误

**解决方案**：
```bash
# 检查 Node.js 版本
node --version  # 应该是 16+

# 重新安装依赖
rm -rf node_modules package-lock.json
npm install

# 手动测试构建
npm run build
```

#### "GitHub Actions 工作流失败"

**问题**：自动发布工作流失败

**解决方案**：
1. 检查 GitHub 仓库中的 Actions 选项卡
2. 查看工作流日志了解具体错误
3. 确保 GitHub token 有适当权限
4. 验证工作流文件语法：
   ```bash
   npm run validate:github-actions
   ```

### 调试模式

详细故障排除：

```bash
# 带完整输出的干运行
node scripts/release.js patch --dry-run --force

# 手动逐步测试
node scripts/version-bump.js patch
npm run build
git status
```

### 获取帮助

如果遇到此处未涵盖的问题：

1. **检查日志**：查看控制台输出的具体错误消息
2. **验证配置**：运行 `npm run validate:config`
3. **测试组件**：使用单独的脚本隔离问题
4. **创建问题**：报告带有详细信息的错误

## 🔐 安全考虑

### 包验证

每个发布都包含安全校验和：

```bash
# 从发布中下载 checksums.txt
# 验证 SHA256 校验和
sha256sum notion-to-wordpress-1.1.1.zip

# 验证 MD5 校验和
md5sum notion-to-wordpress-1.1.1.zip
```

### GitHub Token 安全

- 系统使用 GitHub 的自动 `GITHUB_TOKEN`
- 无需手动 token 配置
- 权限自动限定在仓库范围内

### 安全发布实践

1. **始终先用干运行测试**
2. **确认前审查更改**
3. **保留重要配置的备份**
4. **一致使用语义化版本**
5. **在暂存环境中测试发布**

## 📚 最佳实践

### 发布工作流

1. **开发阶段**
   - 在功能分支中进行更改
   - 合并前彻底测试
   - 根据需要更新文档

2. **预发布**
   - 合并到主分支
   - 运行测试和验证
   - 使用干运行预览发布

3. **发布**
   - 选择适当的版本类型
   - 执行发布命令
   - 监控 GitHub Actions

4. **发布后**
   - 验证发布可用
   - 在 WordPress 中测试安装
   - 更新任何外部文档

### 版本策略

- **补丁 (1.1.0 → 1.1.1)**：错误修复、安全更新、小改进
- **小版本 (1.1.0 → 1.2.0)**：新功能、增强功能、非破坏性更改
- **大版本 (1.1.0 → 2.0.0)**：破坏性更改、主要重写、API 更改
- **测试版 (1.1.0 → 1.1.1-beta.1)**：测试版本、实验性功能

### 维护

- **定期更新**：保持依赖项更新
- **配置审查**：定期审查和更新配置
- **文档**：保持文档与更改同步
- **备份**：维护配置和重要文件的备份

## 🎉 成功指标

成功的发布将显示：

- ✅ 所有版本号一致更新
- ✅ 创建干净的 Git 提交和标签
- ✅ 生成 WordPress 兼容的 ZIP 包
- ✅ GitHub Actions 工作流成功完成
- ✅ 创建带有可用下载的 GitHub 发布
- ✅ 生成并上传安全校验和
- ✅ 自动生成发布说明

## 📞 支持

需要帮助？以下是您的选择：

1. **文档**：查看本指南和项目 README
2. **配置**：运行验证工具检查您的设置
3. **问题**：创建带有详细信息的 GitHub 问题
4. **社区**：检查现有问题寻找类似问题

---

**发布愉快！🚀**

*这个自动化发布系统旨在让您的生活更轻松。如果您有改进建议，请告诉我们！*

---

## 📖 其他资源

- [English Version](./RELEASE_GUIDE.md)
- [GitHub Actions 配置](./.github/workflows/release.yml)
- [发布配置](../release.config.js)
- [项目 README](../README-zh_CN.md)
