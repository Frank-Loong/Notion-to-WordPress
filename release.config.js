/**
 * Release Configuration for Notion-to-WordPress Plugin
 * 
 * This file contains all configuration options for the automated release system.
 * You can customize these settings to match your project requirements.
 * 
 * @author Frank-Loong
 * @version 1.0.0
 */

const path = require('path');

/**
 * Release Configuration Object
 */
const releaseConfig = {
    // ========================================
    // Project Information
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
    // Version Management Configuration
    // ========================================
    version: {
        // Files that need version updates
        files: [
            {
                path: 'notion-to-wordpress.php',
                patterns: [
                    {
                        // WordPress Plugin Header version
                        regex: /(\* Version:\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    },
                    {
                        // PHP constant definition
                        regex: /(define\(\s*'NOTION_TO_WORDPRESS_VERSION',\s*')([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(.*\);)/,
                        replacement: '$1{VERSION}$3'
                    }
                ]
            },
            {
                path: 'readme.txt',
                patterns: [
                    {
                        // WordPress plugin stable tag
                        regex: /(Stable tag:\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'includes/class-notion-to-wordpress.php',
                patterns: [
                    {
                        // Class version property
                        regex: /(\$this->version\s*=\s*')([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(';)/,
                        replacement: '$1{VERSION}$3'
                    }
                ]
            },
            {
                path: 'docs/PROJECT_STATUS.md',
                patterns: [
                    {
                        // Documentation version (English)
                        regex: /(>\s*\*\*Current Version\*\*:\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'docs/PROJECT_STATUS-zh_CN.md',
                patterns: [
                    {
                        // Documentation version (Chinese)
                        regex: /(>\s*\*\*当前版本\*\*:\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'package.json',
                patterns: [
                    {
                        // npm package version
                        regex: /("version":\s*")([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(.*")/,
                        replacement: '$1{VERSION}$3'
                    }
                ]
            }
        ],

        // Version validation settings
        validation: {
            enforceConsistency: true,
            allowPrerelease: true,
            semverCompliant: true
        },

        // Backup settings
        backup: {
            enabled: true,
            directory: '.version-backup',
            keepBackups: 5
        }
    },

    // ========================================
    // Build Configuration
    // ========================================
    build: {
        // Output settings
        output: {
            directory: 'build',
            filename: '{PROJECT_NAME}-{VERSION}.zip',
            tempDirectory: 'build/temp'
        },

        // Files and directories to include
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

        // Files and directories to exclude (in addition to .gitignore)
        exclude: {
            files: [
                'package.json',
                'package-lock.json',
                '.gitignore',
                'LICENSE',
                'README.md',
                'README-zh_CN.md',
                'CONTRIBUTING.md',
                'CONTRIBUTING-zh_CN.md'
            ],
            directories: [
                'scripts/',
                '.github/',
                'node_modules/',
                'build/',
                '.version-backup/',
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

        // Compression settings
        compression: {
            level: 9,
            method: 'zip'
        },

        // Validation settings
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
    // Git Configuration
    // ========================================
    git: {
        // Commit message templates
        commitMessage: {
            template: 'Release version {VERSION}',
            includeChangelog: false
        },

        // Tag settings
        tag: {
            prefix: 'v',
            format: '{PREFIX}{VERSION}',
            message: 'Version {VERSION}',
            annotated: true
        },

        // Branch settings
        branch: {
            main: 'main',
            allowedBranches: ['main', 'master', 'develop', 'dev'],
            requireCleanWorkingDirectory: true
        },

        // Remote settings
        remote: {
            name: 'origin',
            pushTags: true,
            pushCommits: true
        }
    },

    // ========================================
    // GitHub Configuration
    // ========================================
    github: {
        // Repository settings
        repository: {
            owner: 'Frank-Loong',
            name: 'Notion-to-WordPress'
        },

        // Release settings
        release: {
            draft: false,
            prerelease: 'auto', // 'auto', true, false
            generateReleaseNotes: true,
            discussionCategory: null
        },

        // Assets to upload
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

### 📚 Documentation
- [Installation Guide]({HOMEPAGE}#installation)
- [Configuration Guide]({HOMEPAGE}#configuration)
- [Troubleshooting]({HOMEPAGE}#troubleshooting)

### 🐛 Issues & Support
If you encounter any issues, please [create an issue]({BUGS_URL}) with detailed information.`
    },

    // ========================================
    // Environment Configuration
    // ========================================
    environment: {
        // Node.js requirements
        node: {
            minVersion: '16.0.0',
            recommendedVersion: '18.0.0'
        },

        // Required tools
        requiredTools: [
            'git',
            'npm'
        ],

        // Environment variables
        variables: {
            GITHUB_TOKEN: {
                required: false,
                description: 'GitHub personal access token for releases'
            },
            NODE_ENV: {
                required: false,
                default: 'production',
                description: 'Node.js environment'
            }
        }
    },

    // ========================================
    // Logging Configuration
    // ========================================
    logging: {
        level: 'info', // 'debug', 'info', 'warn', 'error'
        colors: true,
        timestamps: true,
        logFile: null // Set to file path to enable file logging
    },

    // ========================================
    // Advanced Options
    // ========================================
    advanced: {
        // Dry run settings
        dryRun: {
            enabled: false,
            verbose: true
        },

        // Retry settings
        retry: {
            maxAttempts: 3,
            delay: 1000,
            exponentialBackoff: true
        },

        // Hooks (for custom scripts)
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
 * Configuration validation function
 */
function validateConfig(config) {
    const errors = [];

    // Validate required fields
    if (!config.project?.name) {
        errors.push('project.name is required');
    }

    if (!config.version?.files || !Array.isArray(config.version.files)) {
        errors.push('version.files must be an array');
    }

    if (!config.build?.output?.directory) {
        errors.push('build.output.directory is required');
    }

    if (errors.length > 0) {
        throw new Error(`Configuration validation failed:\n${errors.join('\n')}`);
    }

    return true;
}

/**
 * Get configuration with environment overrides
 */
function getConfig(overrides = {}) {
    const config = JSON.parse(JSON.stringify(releaseConfig)); // Deep clone
    
    // Apply overrides
    if (overrides && typeof overrides === 'object') {
        Object.assign(config, overrides);
    }

    // Apply environment variables
    if (process.env.NODE_ENV) {
        config.environment.variables.NODE_ENV.default = process.env.NODE_ENV;
    }

    // Validate configuration
    validateConfig(config);

    return config;
}

// Export configuration
module.exports = {
    default: releaseConfig,
    getConfig,
    validateConfig
};
