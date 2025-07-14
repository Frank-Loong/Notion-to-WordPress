/**
 * Notion-to-WordPress 插件发布配置。
 * 包含自动化发布系统的所有配置选项。
 * 你可以根据项目需求自定义这些设置。
 * @since      1.8.2
 * @version    1.8.2
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

const path = require('path');

/**
 * 发布配置对象
 */
const releaseConfig = {
    // ========================================
    // 项目信息
    // ========================================
    project: {
        name: 'notion-to-wordpress',
        displayName: 'Notion-to-WordPress',
        description: 'The most advanced WordPress plugin for syncing Notion databases to WordPress',
        author: 'Frank-Loong',
        license: 'GPL-3.0-or-later',
        homepage: 'https://github.com/Frank-Loong/Notion-to-WordPress',
        repository: {
            type: 'git',
            url: 'https://github.com/Frank-Loong/Notion-to-WordPress.git'
        },
        bugs: {
            url: 'https://github.com/Frank-Loong/Notion-to-WordPress/issues'
        }
    },

    // ========================================
    // 版本管理配置
    // ========================================
    version: {
        // 需要更新版本的文件
        files: [
            {
                path: 'notion-to-wordpress.php',
                patterns: [
                    {
                        // WordPress 插件头部版本
                        regex: /(\* Version:\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    },
                    {
                        // PHP 常量定义
                        regex: /(define\(\s*'NOTION_TO_WORDPRESS_VERSION',\s*')([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(.*\);)/,
                        replacement: '$1{VERSION}$3'
                    }
                ]
            },
            {
                path: 'readme.txt',
                patterns: [
                    {
                        // WordPress 插件稳定标签
                        regex: /(Stable tag:\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'includes/class-notion-to-wordpress.php',
                patterns: [
                    {
                        // 类版本属性
                        regex: /(\$this->version\s*=\s*')([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(';)/,
                        replacement: '$1{VERSION}$3'
                    }
                ]
            },
            {
                path: 'package.json',
                patterns: [
                    {
                        // npm 包版本
                        regex: /("version":\s*")([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(.*")/,
                        replacement: '$1{VERSION}$3'
                    }
                ]
            },
            {
                path: 'package-lock.json',
                patterns: [
                    {
                        // npm 锁定文件版本 - 根级别（第3行）
                        regex: /(^\s*"version":\s*")([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(.*")/m,
                        replacement: '$1{VERSION}$3'
                    },
                    {
                        // npm 锁定文件版本 - packages根级别（第9行左右）
                        regex: /(\s*"":\s*\{[^}]*?"version":\s*")([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(.*")/s,
                        replacement: '$1{VERSION}$3'
                    }
                ]
            }
        ],

        // 版本验证设置
        validation: {
            enforceConsistency: true,
            allowPrerelease: true,
            semverCompliant: true
        }
    },

    // ========================================
    // 文件头部注释配置
    // ========================================
    fileHeaders: {
        // 文件头部注释模板
        templates: {
            // PHP文件模板（WordPress标准）
            php: {
                template: `<?php
declare(strict_types=1);

/**
 * {DESCRIPTION}
 *
 * @package    {PACKAGE}
 * @subpackage {SUBPACKAGE}
 * @since      {VERSION}
 * @author     {AUTHOR}
 * @license    {LICENSE}
 * @link       {LINK}
 */

// 如果直接访问此文件，则退出
if (!defined('ABSPATH')) {
    exit;
}`,
                variables: {
                    DESCRIPTION: '文件描述',
                    PACKAGE: 'Notion_To_WordPress',
                    SUBPACKAGE: '',
                    VERSION: '{VERSION}',
                    AUTHOR: 'Frank-Loong',
                    LICENSE: 'GPL-3.0-or-later',
                    LINK: 'https://github.com/Frank-Loong/Notion-to-WordPress'
                }
            },

            // JavaScript文件模板（JSDoc格式）
            js: {
                template: `/**
 * {DESCRIPTION}
 *
 * @file       {FILE_NAME}
 * @package    {PACKAGE}
 * @since      {VERSION}
 * @author     {AUTHOR}
 * @license    {LICENSE}
 * @link       {LINK}
 */`,
                variables: {
                    DESCRIPTION: '文件描述',
                    FILE_NAME: '{FILE_NAME}',
                    PACKAGE: 'Notion_To_WordPress',
                    VERSION: '{VERSION}',
                    AUTHOR: 'Frank-Loong',
                    LICENSE: 'GPL-3.0-or-later',
                    LINK: 'https://github.com/Frank-Loong/Notion-to-WordPress'
                }
            },

            // CSS文件模板
            css: {
                template: `/**
 * {DESCRIPTION}
 *
 * @package    {PACKAGE}
 * @since      {VERSION}
 * @author     {AUTHOR}
 * @license    {LICENSE}
 */`,
                variables: {
                    DESCRIPTION: '文件描述',
                    PACKAGE: 'Notion_To_WordPress',
                    VERSION: '{VERSION}',
                    AUTHOR: 'Frank-Loong',
                    LICENSE: 'GPL-3.0-or-later'
                }
            }
        },

        // 需要添加头部注释的文件规则
        rules: {
            // 包含的目录和文件类型
            include: {
                directories: [
                    'includes/',
                    'admin/',
                    'assets/js/',
                    'assets/css/'
                ],
                extensions: ['.php', '.js', '.css'],
                // 特定文件
                files: []
            },

            // 排除的文件和目录
            exclude: {
                directories: [
                    'assets/vendor/',
                    'node_modules/',
                    'build/',
                    'languages/'
                ],
                files: [
                    'notion-to-wordpress.php', // 主插件文件有特殊格式
                    'uninstall.php'
                ],
                patterns: [
                    '*.min.js',
                    '*.min.css',
                    'vendor.*',
                    'third-party.*'
                ]
            }
        },

        // 文件描述映射（可选，用于自动生成描述）
        descriptions: {
            'includes/class-notion-to-wordpress.php': '核心插件类',
            'includes/class-notion-api.php': 'Notion API 接口类',
            'includes/class-notion-pages.php': 'Notion 页面处理类',
            'includes/class-notion-to-wordpress-helper.php': '插件辅助工具类',
            'includes/class-notion-to-wordpress-i18n.php': '国际化处理类',
            'includes/class-notion-to-wordpress-loader.php': '插件加载器类',
            'includes/class-notion-to-wordpress-webhook.php': 'Webhook 处理类',
            'includes/class-notion-concurrent-manager.php': '并发管理类',
            'admin/class-notion-to-wordpress-admin.php': '后台管理类',
            'assets/js/anchor-navigation.js': 'Notion 区块锚点导航功能',
            'assets/js/admin.js': '后台管理界面脚本',
            'assets/css/admin-modern.css': 'Notion 内容导入器现代化后台样式',
            'assets/css/public.css': '前台样式文件'
        }
    },

    // ========================================
    // 构建配置
    // ========================================
    build: {
        // 输出设置
        output: {
            directory: 'build',
            filename: '{PROJECT_NAME}-{VERSION}.zip',
            tempDirectory: 'build/temp'
        },

        // 包含的文件和目录
        include: {
            files: [
                'notion-to-wordpress.php',
                'readme.txt',
                'uninstall.php'
            ],
            directories: [
                'admin/',
                'assets/',
                'includes/',
                'languages/'
            ]
        },

        // 排除的文件和目录（除了 .gitignore 外）
        exclude: {
            files: [
                'package.json',
                'package-lock.json',
                '.gitignore',
                'LICENSE',
                'README.md',
                'README-zh_CN.md',
            ],
            directories: [
                'scripts/',
                '.github/',
                'node_modules/',
                'build/',
                'docs/',
                'wiki/',
                '.git/',
                '.vscode/',
                '.idea/',
                '.cursor/',
                '.augment/',
                'coverage/',
                'tests/'
            ],
            patterns: [
                '*.zip',
                '*.tar.gz',
                '*.log',
                '.env*',
                '.DS_Store',
                'Thumbs.db',
                '*.tmp',
                '*.bak',
                '*.swp'
            ]
        },

        // 压缩设置
        compression: {
            level: 9,
            method: 'zip'
        },

        // 验证设置
        validation: {
            maxSize: 50 * 1024 * 1024, // 50MB
            minSize: 100 * 1024,       // 100KB
            requiredFiles: [
                'notion-to-wordpress.php',
                'readme.txt'
            ]
        }
    },

    // ========================================
    // Git 配置
    // ========================================
    git: {
        // 提交信息模板
        commitMessage: {
            template: 'Release version {VERSION}',
            includeChangelog: false
        },

        // 标签设置
        tag: {
            prefix: 'v',
            format: '{PREFIX}{VERSION}',
            message: 'Version {VERSION}',
            annotated: true
        },

        // 分支设置
        branch: {
            main: 'main',
            allowedBranches: ['main', 'master', 'develop', 'dev'],
            requireCleanWorkingDirectory: true
        },

        // 远程设置
        remote: {
            name: 'origin',
            pushTags: true,
            pushCommits: true
        }
    },

    // ========================================
    // GitHub 配置
    // ========================================
    github: {
        // 仓库设置
        repository: {
            owner: 'Frank-Loong',
            name: 'Notion-to-WordPress'
        },

        // 发布设置
        release: {
            draft: false,
            prerelease: 'auto', // 'auto', true, false
            generateReleaseNotes: true,
            discussionCategory: null
        },

        // 要上传的资产
        assets: [
            {
                path: 'build/{PROJECT_NAME}-{VERSION}.zip',
                name: '{PROJECT_NAME}-{VERSION}.zip',
                label: 'WordPress Plugin Package'
            },
            {
                path: 'build/checksums.txt',
                name: 'checksums.txt',
                label: 'Security Checksums'
            }
        ],

        // Release body template
        releaseBodyTemplate: `## 🚀 {PROJECT_DISPLAY_NAME} Plugin Release v{VERSION}

### 📦 Package Information
- **Version**: {VERSION}
- **Package Size**: {PACKAGE_SIZE}
- **Release Type**: {RELEASE_TYPE}

### 📥 Installation
1. Download the \`{PROJECT_NAME}-{VERSION}.zip\` file below
2. Go to your WordPress admin dashboard
3. Navigate to **Plugins** → **Add New** → **Upload Plugin**
4. Choose the downloaded ZIP file and click **Install Now**
5. Activate the plugin after installation

### 🔐 Security
Please verify the package integrity using the provided checksums:
- Download \`checksums.txt\` to verify file integrity
- Use \`sha256sum\` or \`md5sum\` to verify the ZIP file

### 🐛 Issues & Support
If you encounter any issues, please [create an issue]({BUGS_URL}) with detailed information.`
    },

    // ========================================
    // 环境配置
    // ========================================
    environment: {
        // Node.js 要求
        node: {
            minVersion: '18.0.0',
            recommendedVersion: '18.0.0'
        },

        // 必需工具
        requiredTools: [
            'git',
            'npm'
        ],

        // 环境变量
        variables: {
            GITHUB_TOKEN: {
                required: false,
                description: '用于发布的 GitHub 个人访问令牌'
            },
            NODE_ENV: {
                required: false,
                default: 'production',
                description: 'Node.js 环境'
            }
        }
    },

    // ========================================
    // 日志配置
    // ========================================
    logging: {
        level: 'info', // 'debug', 'info', 'warn', 'error'
        colors: true,
        timestamps: true,
        logFile: null // 设置文件路径以启用文件日志
    },

    // ========================================
    // 高级选项
    // ========================================
    advanced: {
        // 干运行设置
        dryRun: {
            enabled: false,
            verbose: true
        },

        // 重试设置
        retry: {
            maxAttempts: 3,
            delay: 1000,
            exponentialBackoff: true
        },

        // 钩子（用于自定义脚本）
        hooks: {
            preRelease: null,
            postRelease: null,
            preVersion: null,
            postVersion: null,
            preBuild: null,
            postBuild: null
        }
    }
};

/**
 * 配置验证函数
 */
function validateConfig(config) {
    const errors = [];

    // 验证必填字段
    if (!config.project?.name) {
        errors.push('project.name 是必填项');
    }

    if (!config.version?.files || !Array.isArray(config.version.files)) {
        errors.push('version.files 必须是一个数组');
    }

    if (!config.build?.output?.directory) {
        errors.push('build.output.directory 是必填项');
    }

    if (errors.length > 0) {
        throw new Error(`配置验证失败：\n${errors.join('\n')}`);
    }

    return true;
}

/**
 * 获取带有环境覆盖的配置
 */
function getConfig(overrides = {}) {
    const config = JSON.parse(JSON.stringify(releaseConfig)); // 深拷贝
    
    // 应用覆盖
    if (overrides && typeof overrides === 'object') {
        Object.assign(config, overrides);
    }

    // 应用环境变量
    if (process.env.NODE_ENV) {
        config.environment.variables.NODE_ENV.default = process.env.NODE_ENV;
    }

    // 验证配置
    validateConfig(config);

    return config;
}

// 导出配置
module.exports = {
    default: releaseConfig,
    getConfig,
    validateConfig
};
