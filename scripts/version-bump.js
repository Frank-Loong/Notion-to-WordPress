#!/usr/bin/env node

/**
 * Version Bump Tool for Notion-to-WordPress Plugin
 * 
 * This tool automatically updates version numbers across all relevant files
 * in the WordPress plugin project, ensuring consistency and supporting
 * semantic versioning (patch, minor, major, beta).
 * 
 * @author Frank-Loong
 * @version 1.0.0
 */

const fs = require('fs');
const path = require('path');
const semver = require('semver');
const chalk = require('chalk');

class VersionBumper {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '..');
        this.backupDir = path.join(this.projectRoot, '.version-backup');
        this.currentVersion = null;
        
        // Define files that need version updates
        this.versionFiles = [
            {
                path: 'notion-to-wordpress.php',
                patterns: [
                    {
                        regex: /(\* Version:\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    },
                    {
                        regex: /(define\(\s*'NOTION_TO_WORDPRESS_VERSION',\s*')([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(.*\);)/,
                        replacement: '$1{VERSION}$3'
                    }
                ]
            },
            {
                path: 'readme.txt',
                patterns: [
                    {
                        regex: /(Stable tag:\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'includes/class-notion-to-wordpress.php',
                patterns: [
                    {
                        regex: /(\$this->version\s*=\s*')([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(';)/,
                        replacement: '$1{VERSION}$3'
                    }
                ]
            },
            {
                path: 'docs/PROJECT_STATUS.md',
                patterns: [
                    {
                        regex: /(>\s*\*\*Current Version\*\*:\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'docs/PROJECT_STATUS-zh_CN.md',
                patterns: [
                    {
                        regex: /(>\s*\*\*当前版本\*\*:\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'package.json',
                patterns: [
                    {
                        regex: /("version":\s*")([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(.*")/,
                        replacement: '$1{VERSION}$3'
                    }
                ]
            }
        ];
    }

    /**
     * Get current version from the main plugin file
     */
    getCurrentVersion() {
        try {
            const mainFile = path.join(this.projectRoot, 'notion-to-wordpress.php');
            const content = fs.readFileSync(mainFile, 'utf8');
            
            const versionMatch = content.match(/\* Version:\s+([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/);
            if (!versionMatch) {
                throw new Error('Could not find version in main plugin file');
            }
            
            this.currentVersion = versionMatch[1];
            return this.currentVersion;
        } catch (error) {
            this.error(`Failed to get current version: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * Validate version format and consistency across files
     */
    validateVersion() {
        this.log('Validating version consistency across files...');
        
        const versions = [];
        
        for (const fileConfig of this.versionFiles) {
            const filePath = path.join(this.projectRoot, fileConfig.path);
            
            if (!fs.existsSync(filePath)) {
                this.warn(`File not found: ${fileConfig.path}`);
                continue;
            }
            
            const content = fs.readFileSync(filePath, 'utf8');
            
            for (const pattern of fileConfig.patterns) {
                const match = content.match(pattern.regex);
                if (match && match[2]) {
                    versions.push({
                        file: fileConfig.path,
                        version: match[2]
                    });
                }
            }
        }
        
        // Check if all versions are consistent
        const uniqueVersions = [...new Set(versions.map(v => v.version))];
        
        if (uniqueVersions.length > 1) {
            this.error('Version inconsistency detected:');
            versions.forEach(v => {
                console.log(`  ${v.file}: ${v.version}`);
            });
            process.exit(1);
        }
        
        if (uniqueVersions.length === 0) {
            this.error('No version found in any file');
            process.exit(1);
        }
        
        this.success(`All files have consistent version: ${uniqueVersions[0]}`);
        return uniqueVersions[0];
    }

    /**
     * Calculate new version based on bump type
     */
    bumpVersion(currentVersion, bumpType) {
        try {
            let newVersion;
            
            switch (bumpType) {
                case 'patch':
                    newVersion = semver.inc(currentVersion, 'patch');
                    break;
                case 'minor':
                    newVersion = semver.inc(currentVersion, 'minor');
                    break;
                case 'major':
                    newVersion = semver.inc(currentVersion, 'major');
                    break;
                case 'beta':
                    if (currentVersion.includes('-beta')) {
                        newVersion = semver.inc(currentVersion, 'prerelease', 'beta');
                    } else {
                        newVersion = semver.inc(currentVersion, 'patch') + '-beta.1';
                    }
                    break;
                default:
                    throw new Error(`Invalid bump type: ${bumpType}`);
            }
            
            if (!newVersion) {
                throw new Error(`Failed to calculate new version from ${currentVersion}`);
            }
            
            return newVersion;
        } catch (error) {
            this.error(`Failed to bump version: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * Create backup of all files before modification
     */
    createBackup() {
        this.log('Creating backup of files...');
        
        if (fs.existsSync(this.backupDir)) {
            fs.rmSync(this.backupDir, { recursive: true, force: true });
        }
        fs.mkdirSync(this.backupDir, { recursive: true });
        
        for (const fileConfig of this.versionFiles) {
            const sourcePath = path.join(this.projectRoot, fileConfig.path);
            
            if (fs.existsSync(sourcePath)) {
                const backupPath = path.join(this.backupDir, fileConfig.path);
                const backupDir = path.dirname(backupPath);
                
                if (!fs.existsSync(backupDir)) {
                    fs.mkdirSync(backupDir, { recursive: true });
                }
                
                fs.copyFileSync(sourcePath, backupPath);
            }
        }
        
        this.success('Backup created successfully');
    }

    /**
     * Update version in a specific file
     */
    updateFileVersion(fileConfig, newVersion) {
        const filePath = path.join(this.projectRoot, fileConfig.path);
        
        if (!fs.existsSync(filePath)) {
            this.warn(`File not found: ${fileConfig.path}`);
            return false;
        }
        
        let content = fs.readFileSync(filePath, 'utf8');
        let updated = false;
        
        for (const pattern of fileConfig.patterns) {
            const replacement = pattern.replacement.replace('{VERSION}', newVersion);
            
            if (pattern.regex.test(content)) {
                content = content.replace(pattern.regex, replacement);
                updated = true;
            }
        }
        
        if (updated) {
            fs.writeFileSync(filePath, content, 'utf8');
            this.success(`Updated ${fileConfig.path}`);
            return true;
        } else {
            this.warn(`No version pattern found in ${fileConfig.path}`);
            return false;
        }
    }

    /**
     * Update all files with new version
     */
    updateAllFiles(newVersion) {
        this.log(`Updating all files to version ${newVersion}...`);
        
        let updatedCount = 0;
        
        for (const fileConfig of this.versionFiles) {
            if (this.updateFileVersion(fileConfig, newVersion)) {
                updatedCount++;
            }
        }
        
        this.success(`Updated ${updatedCount} files successfully`);
        return updatedCount > 0;
    }

    /**
     * Restore files from backup
     */
    restoreFromBackup() {
        this.log('Restoring files from backup...');
        
        if (!fs.existsSync(this.backupDir)) {
            this.error('No backup found to restore from');
            return false;
        }
        
        for (const fileConfig of this.versionFiles) {
            const backupPath = path.join(this.backupDir, fileConfig.path);
            const targetPath = path.join(this.projectRoot, fileConfig.path);
            
            if (fs.existsSync(backupPath)) {
                fs.copyFileSync(backupPath, targetPath);
            }
        }
        
        this.success('Files restored from backup');
        return true;
    }

    /**
     * Clean up backup directory
     */
    cleanupBackup() {
        if (fs.existsSync(this.backupDir)) {
            fs.rmSync(this.backupDir, { recursive: true, force: true });
        }
    }

    /**
     * Main execution function
     */
    run(bumpType) {
        try {
            this.log(chalk.bold('🚀 Notion-to-WordPress Version Bump Tool'));
            this.log(`Bump type: ${chalk.cyan(bumpType)}`);
            
            // Get and validate current version
            const currentVersion = this.getCurrentVersion();
            this.validateVersion();
            
            // Calculate new version
            const newVersion = this.bumpVersion(currentVersion, bumpType);
            
            this.log(`Current version: ${chalk.yellow(currentVersion)}`);
            this.log(`New version: ${chalk.green(newVersion)}`);
            
            // Create backup before making changes
            this.createBackup();
            
            try {
                // Update all files
                const success = this.updateAllFiles(newVersion);
                
                if (success) {
                    this.success(`✅ Version successfully updated from ${currentVersion} to ${newVersion}`);
                    this.cleanupBackup();
                } else {
                    throw new Error('No files were updated');
                }
                
            } catch (updateError) {
                this.error(`Update failed: ${updateError.message}`);
                this.restoreFromBackup();
                process.exit(1);
            }
            
        } catch (error) {
            this.error(`Version bump failed: ${error.message}`);
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
    const args = process.argv.slice(2);
    const command = args[0];

    if (command === 'rollback') {
        const bumper = new VersionBumper();
        if (bumper.restoreFromBackup()) {
            bumper.success('✅ Successfully rolled back to previous version');
        } else {
            bumper.error('❌ Rollback failed');
            process.exit(1);
        }
        return;
    }

    const bumpType = command;

    if (!bumpType || !['patch', 'minor', 'major', 'beta'].includes(bumpType)) {
        console.log(chalk.red('❌ Invalid or missing bump type'));
        console.log('Usage: node version-bump.js <patch|minor|major|beta|rollback>');
        console.log('');
        console.log('Examples:');
        console.log('  node version-bump.js patch     # 1.1.0 → 1.1.1');
        console.log('  node version-bump.js minor     # 1.1.0 → 1.2.0');
        console.log('  node version-bump.js major     # 1.1.0 → 2.0.0');
        console.log('  node version-bump.js beta      # 1.1.0 → 1.1.1-beta.1');
        console.log('  node version-bump.js rollback  # Restore from backup');
        process.exit(1);
    }

    const bumper = new VersionBumper();
    bumper.run(bumpType);
}

module.exports = VersionBumper;
