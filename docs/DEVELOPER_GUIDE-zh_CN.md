# 开发指南

## 环境要求

- Node.js 18+
- PHP 8.0+ 与 Composer
- WordPress 5.0+（用于手动插件测试）

## 初始化

```bash
npm ci
composer install
```

## 日常质量检查

```bash
npm run check
vendor/bin/phpcs
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit
```

`npm run check` 会依次运行 Prettier、ESLint、TypeScript 和 Jest。CI 不执行会自动
改写文件的命令；请在提交前有意识地完成格式调整。

## 构建插件 ZIP

```bash
npm run build
npm run build:verify
```

构建产物为 `build/notion-to-wordpress-<version>.zip`。校验会检查所有版本声明和
归档内容。ZIP 使用内置的 `NTWP` 自动加载器，可独立运行，不依赖 Composer 的
运行时 `vendor/` 目录。

## 发布

在插件头部与常量、`package.json`、`readme.txt` 中同步更新版本，并推送相同版本的
`v<version>` 标签。发布工作流会在创建 GitHub Release 前重新运行 Node 与 PHP 的
质量检查；任何失败都会阻止发布。
