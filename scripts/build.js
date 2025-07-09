#!/usr/bin/env node

/**
 * WordPress Plugin Build Tool for Notion-to-WordPress
 * 
 * This tool creates a WordPress-standard ZIP package for the plugin,
 * excluding development files and including only runtime-necessary files.
 * The generated ZIP can be directly installed in WordPress admin.
 * 
 * @author Frank-Loong
 * @version 1.0.0
 */

const fs = require('fs');
const path = require('path');
const archiver = require('archiver');
const chalk = require('chalk');
const { glob } = require('glob');

class WordPressBuildTool {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '..');
        this.buildDir = path.join(this.projectRoot, 'build');
        this.tempDir = path.join(this.buildDir, 'temp');
        this.pluginName = 'notion-to-wordpress';
        
        // Files and directories that must be included in WordPress plugin
        this.requiredFiles = [
            'notion-to-wordpress.php',  // Main plugin file
            'readme.txt',               // WordPress plugin description
            'uninstall.php'            // Uninstall script
            // LICENSE file excluded to reduce package size
        ];
        
        // Directories that should be included
        this.requiredDirs = [
            'admin/',                  // Admin interface
            'assets/',                 // Frontend resources
            'includes/',               // Core functionality
            'languages/'               // Internationalization
        ];
        
        // Additional files to include (optional but recommended)
        this.optionalFiles = [
            // Documentation files are excluded to reduce package size
        ];

        // Additional directories to include (documentation)
        this.optionalDirs = [
            // Documentation directories are excluded to reduce package size
        ];
        
        // Development files/directories to exclude (in addition to .gitignore)
        this.developmentExcludes = [
            'scripts/',                // Build scripts
            '.github/',               // GitHub Actions
            'node_modules/',          // Node dependencies
            'package.json',           // npm configuration
            'package-lock.json',      // npm lock file
            '.gitignore',            // Git ignore file
            '.git/',                 // Git repository
            'build/',                // Build output
            '.version-backup/',      // Version backup
            '*.zip',                 // Existing ZIP files
            '*.tar.gz',              // Archive files
            '*.log',                 // Log files
            '.env*',                 // Environment files
            '.DS_Store',             // macOS files
            'Thumbs.db',             // Windows files
            '.vscode/',              // VS Code settings
            '.idea/',                // IntelliJ settings
            '.cursor/',              // Cursor AI settings
            '.augment/',             // Augment settings
            'coverage/',             // Test coverage
            'tests/',                // Test files
            '*.tmp',                 // Temporary files
            '*.bak',                 // Backup files
            '*.swp',                 // Vim swap files
            // Documentation files (not needed for WordPress plugin runtime)
            'docs/',                 // Documentation directory
            'wiki/',                 // Wiki directory
            'README.md',             // Root README file
            'README-zh_CN.md',       // Chinese README file
            'CONTRIBUTING.md',       // Contributing guide
            'CONTRIBUTING-zh_CN.md', // Chinese contributing guide
            'LICENSE'                // License file
        ];
        
        this.gitignoreRules = [];
    }

    /**
     * Read and parse .gitignore file
     */
    readGitignore() {
        const gitignorePath = path.join(this.projectRoot, '.gitignore');
        
        if (fs.existsSync(gitignorePath)) {
            const content = fs.readFileSync(gitignorePath, 'utf8');
            this.gitignoreRules = content
                .split('\n')
                .map(line => line.trim())
                .filter(line => line && !line.startsWith('#'))
                .filter(line => !line.startsWith('!')) // Ignore negation rules for simplicity
                .map(rule => {
                    // Convert gitignore patterns to glob patterns
                    if (rule.endsWith('/')) {
                        return rule + '**';
                    }
                    return rule;
                });
        }
        
        this.log(`Loaded ${this.gitignoreRules.length} gitignore rules`);
    }

    /**
     * Check if a file should be excluded
     */
    shouldExclude(filePath) {
        const relativePath = path.relative(this.projectRoot, filePath);
        const normalizedPath = relativePath.replace(/\\/g, '/');
        
        // Check development excludes
        for (const exclude of this.developmentExcludes) {
            if (exclude.includes('*')) {
                // Handle glob patterns
                if (this.matchGlob(normalizedPath, exclude)) {
                    return true;
                }
            } else if (exclude.endsWith('/')) {
                // Directory exclusion
                if (normalizedPath.startsWith(exclude) || normalizedPath === exclude.slice(0, -1)) {
                    return true;
                }
            } else {
                // File exclusion
                if (normalizedPath === exclude || normalizedPath.endsWith('/' + exclude)) {
                    return true;
                }
            }
        }
        
        // Check gitignore rules
        for (const rule of this.gitignoreRules) {
            if (this.matchGlob(normalizedPath, rule)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Simple glob pattern matching
     */
    matchGlob(str, pattern) {
        // Convert glob pattern to regex
        const regexPattern = pattern
            .replace(/\./g, '\\.')
            .replace(/\*/g, '.*')
            .replace(/\?/g, '.');
        
        const regex = new RegExp('^' + regexPattern + '$');
        return regex.test(str);
    }

    /**
     * Get current plugin version
     */
    getPluginVersion() {
        try {
            const mainFile = path.join(this.projectRoot, 'notion-to-wordpress.php');
            const content = fs.readFileSync(mainFile, 'utf8');
            
            const versionMatch = content.match(/\* Version:\s+([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/);
            if (versionMatch) {
                return versionMatch[1];
            }
            
            return '1.0.0'; // Fallback version
        } catch (error) {
            this.warn(`Could not determine plugin version: ${error.message}`);
            return '1.0.0';
        }
    }

    /**
     * Create build directory structure
     */
    prepareBuildDir() {
        this.log('Preparing build directory...');
        
        // Clean existing build directory
        if (fs.existsSync(this.buildDir)) {
            fs.rmSync(this.buildDir, { recursive: true, force: true });
        }
        
        // Create build and temp directories
        fs.mkdirSync(this.buildDir, { recursive: true });
        fs.mkdirSync(this.tempDir, { recursive: true });
        
        this.success('Build directory prepared');
    }

    /**
     * Copy files to temporary directory
     */
    async copyFiles() {
        this.log('Copying plugin files...');
        
        const pluginTempDir = path.join(this.tempDir, this.pluginName);
        fs.mkdirSync(pluginTempDir, { recursive: true });
        
        // Get all files in project
        const allFiles = await glob('**/*', {
            cwd: this.projectRoot,
            dot: true,
            nodir: true
        });
        
        let copiedCount = 0;
        let skippedCount = 0;
        
        for (const file of allFiles) {
            const sourcePath = path.join(this.projectRoot, file);
            const targetPath = path.join(pluginTempDir, file);
            
            if (this.shouldExclude(sourcePath)) {
                skippedCount++;
                continue;
            }
            
            // Ensure target directory exists
            const targetDir = path.dirname(targetPath);
            if (!fs.existsSync(targetDir)) {
                fs.mkdirSync(targetDir, { recursive: true });
            }
            
            // Copy file
            fs.copyFileSync(sourcePath, targetPath);
            copiedCount++;
        }
        
        this.success(`Copied ${copiedCount} files, skipped ${skippedCount} files`);
        return pluginTempDir;
    }

    /**
     * Create ZIP package
     */
    async createZip(sourceDir) {
        const version = this.getPluginVersion();
        const zipFileName = `${this.pluginName}-${version}.zip`;
        const zipPath = path.join(this.buildDir, zipFileName);
        
        this.log(`Creating ZIP package: ${zipFileName}`);
        
        return new Promise((resolve, reject) => {
            const output = fs.createWriteStream(zipPath);
            const archive = archiver('zip', {
                zlib: { level: 9 } // Maximum compression
            });
            
            output.on('close', () => {
                const sizeInMB = (archive.pointer() / 1024 / 1024).toFixed(2);
                this.success(`ZIP package created: ${zipFileName} (${sizeInMB} MB)`);
                resolve(zipPath);
            });
            
            archive.on('error', (err) => {
                this.error(`ZIP creation failed: ${err.message}`);
                reject(err);
            });
            
            archive.pipe(output);
            
            // Add all files from temp directory
            archive.directory(sourceDir, this.pluginName);
            
            archive.finalize();
        });
    }

    /**
     * Clean up temporary files
     */
    cleanup() {
        if (fs.existsSync(this.tempDir)) {
            fs.rmSync(this.tempDir, { recursive: true, force: true });
            this.log('Temporary files cleaned up');
        }
    }

    /**
     * Validate the created ZIP package
     */
    validatePackage(zipPath) {
        this.log('Validating WordPress plugin package...');
        
        const stats = fs.statSync(zipPath);
        const sizeInMB = (stats.size / 1024 / 1024).toFixed(2);
        
        // Basic validation checks
        const checks = [
            { name: 'File exists', passed: fs.existsSync(zipPath) },
            { name: 'File size > 0', passed: stats.size > 0 },
            { name: 'File size < 50MB', passed: stats.size < 50 * 1024 * 1024 }
        ];
        
        let allPassed = true;
        for (const check of checks) {
            if (check.passed) {
                this.success(`✓ ${check.name}`);
            } else {
                this.error(`✗ ${check.name}`);
                allPassed = false;
            }
        }
        
        if (allPassed) {
            this.success(`Package validation passed (${sizeInMB} MB)`);
            return true;
        } else {
            this.error('Package validation failed');
            return false;
        }
    }

    /**
     * Main build process
     */
    async build() {
        try {
            this.log(chalk.bold('🚀 WordPress Plugin Build Tool'));
            this.log(`Building plugin: ${chalk.cyan(this.pluginName)}`);
            
            // Read gitignore rules
            this.readGitignore();
            
            // Prepare build directory
            this.prepareBuildDir();
            
            // Copy files
            const pluginDir = await this.copyFiles();
            
            // Create ZIP package
            const zipPath = await this.createZip(pluginDir);
            
            // Validate package
            const isValid = this.validatePackage(zipPath);
            
            // Clean up
            this.cleanup();
            
            if (isValid) {
                this.success(`✅ Build completed successfully!`);
                this.log(`Package location: ${chalk.green(zipPath)}`);
                this.log(`You can now install this ZIP file in WordPress admin.`);
            } else {
                throw new Error('Package validation failed');
            }
            
        } catch (error) {
            this.error(`Build failed: ${error.message}`);
            this.cleanup();
            process.exit(1);
        }
    }

    // Utility logging methods
    log(message) {
        console.log(message);
    }

    success(message) {
        console.log(chalk.green('✅ ' + message));
    }

    warn(message) {
        console.log(chalk.yellow('⚠️  ' + message));
    }

    error(message) {
        console.log(chalk.red('❌ ' + message));
    }
}

// CLI execution
if (require.main === module) {
    const builder = new WordPressBuildTool();
    builder.build();
}

module.exports = WordPressBuildTool;
