# SCSS 构建指南

本目录下的 `.scss` 文件为后台样式源代码，使用模块化结构：

- `_variables.scss`：全局 CSS 变量
- `_mixins.scss`：常用混合宏
- `admin.scss`：后台主入口，负责导入各组件
- 未来可新增 `components/` 子目录，将按钮、卡片、表单等拆分为独立文件

## 构建

在项目根目录执行：

```bash
npm install    # 安装 sass 等依赖
npm run build:css  # 产出 assets/css/admin.css
```

`package.json` 可添加：

```json
"scripts": {
  "build:css": "sass --no-source-map assets/scss/admin.scss assets/css/admin.css --style=compressed"
}
```

在生产环境将 `admin.css` 上传到 `assets/css/` 并在 `Notion_To_WordPress_Admin::enqueue_styles()` 中加载。

> 当前仍使用旧版 `admin-modern.css`，待所有样式迁移完成后再切换。 