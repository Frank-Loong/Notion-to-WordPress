# 🚀 Automated Release System Guide

Welcome to the comprehensive guide for the Notion-to-WordPress automated release system! This system provides a complete CI/CD pipeline that makes releasing new versions as simple as running a single command.

## 📋 Overview

The automated release system transforms the complex process of releasing WordPress plugins into a streamlined, one-command operation. Here's what it does for you:

### ✨ Key Features

- **🔄 Automatic Version Updates** - Updates version numbers across all project files consistently
- **📦 WordPress-Standard Packaging** - Creates optimized ZIP packages ready for WordPress installation
- **🏷️ Git Tag Management** - Automatically creates and pushes version tags
- **🚀 GitHub Release Automation** - Creates GitHub releases with download links and release notes
- **🔐 Security Checksums** - Generates SHA256 and MD5 checksums for package verification
- **🛡️ Safety Features** - Complete rollback capabilities and environment validation

### 🎯 Supported Release Types

| Release Type | Version Change | Use Case | Command |
|--------------|---------------|----------|---------|
| **Patch** | 1.1.0 → 1.1.1 | Bug fixes, small improvements | `npm run release:patch` |
| **Minor** | 1.1.0 → 1.2.0 | New features, enhancements | `npm run release:minor` |
| **Major** | 1.1.0 → 2.0.0 | Breaking changes, major updates | `npm run release:major` |
| **Beta** | 1.1.0 → 1.1.1-beta.1 | Testing, pre-release versions | `npm run release:beta` |
| **Custom** | Any valid semver | Hotfixes, release candidates | `npm run release:custom --version=X.Y.Z` |

## 🛠️ Installation & Setup

### Prerequisites

Before using the automated release system, ensure you have:

- **Node.js 16+** (18+ recommended)
- **Git** with repository access
- **npm** package manager
- **GitHub repository** with appropriate permissions

### Quick Setup

1. **Install Dependencies**
   ```bash
   npm install
   ```

2. **Verify Installation**
   ```bash
   npm run validate:config
   npm run validate:github-actions
   ```

3. **Test the System**
   ```bash
   npm run test:release patch
   ```

### GitHub Configuration

For automatic GitHub releases, you'll need:

1. **Repository Permissions**: Ensure you have write access to the repository
2. **GitHub Token**: The system uses `GITHUB_TOKEN` automatically in GitHub Actions
3. **Branch Protection**: Configure branch protection rules if needed

## 📦 Local Packaging for Testing

Before making official releases, you can create local test packages to verify your changes work correctly.

### 🎯 Local Package Features

- ✅ Update version numbers without Git operations
- ✅ Create ZIP packages for WordPress testing
- ✅ Support custom version numbers (e.g., `1.2.0-test.1`)
- ✅ Preview mode to see changes before applying
- ✅ Automatic backup and rollback on errors

### 🚀 Quick Local Packaging

```bash
# Create test version with custom number
npm run package:local --version=1.2.0-test.1

# Update patch version and package
npm run package:local patch

# Preview changes without applying
npm run package:local --version=1.2.0-test.1 --dry-run

# Only create package (no version update)
npm run package:local --build-only
```

### 📋 Available Local Commands

| Command | Description | Example |
|---------|-------------|---------|
| `npm run package:local:patch` | Patch version update | 1.2.0 → 1.2.1 |
| `npm run package:local:minor` | Minor version update | 1.2.0 → 1.3.0 |
| `npm run package:local:major` | Major version update | 1.2.0 → 2.0.0 |
| `npm run package:local:beta` | Beta version update | 1.2.0 → 1.2.1-beta.1 |
| `npm run package:local --version=X.Y.Z` | Custom version | Any valid semver |
| `npm run package:local --build-only` | Package only | No version change |
| `npm run package:local --version-only` | Version only | No package creation |

### 🧪 Testing Workflow

1. **Create test package**
   ```bash
   npm run package:local --version=1.2.0-test.1
   ```

2. **Test in WordPress**
   - Upload `build/notion-to-wordpress-1.2.0-test.1.zip` to WordPress
   - Verify all features work correctly

3. **Commit when satisfied**
   ```bash
   git add .
   git commit -m "feat: add new features"
   ```

4. **Make official release**
   ```bash
   # Standard release
   npm run release:minor

   # Or custom version release
   npm run release:custom --version=1.2.0-rc.1
   ```

### ⚠️ Local Packaging Notes

- **Safe Testing**: No Git operations, won't affect repository
- **Auto Backup**: Creates backup before changes, auto-rollback on errors
- **File Updates**: Updates version in all relevant files automatically
- **Clean Output**: Generated ZIP ready for WordPress installation

## 🚀 Quick Start Guide

### Your First Release

1. **Ensure Clean Working Directory**
   ```bash
   git status  # Should show no uncommitted changes
   ```

2. **Choose Release Type and Execute**
   ```bash
   # For a bug fix release
   npm run release:patch

   # For a feature release
   npm run release:minor

   # For a major update
   npm run release:major

   # For a beta release
   npm run release:beta

   # For custom version (hotfix, release candidate, etc.)
   npm run release:custom --version=1.2.0-hotfix.1
   npm run release:custom --version=1.3.0-rc.1
   npm run release:custom --version=2.0.0-alpha.1
   ```

3. **Monitor the Process**
   - The script will show progress for each step
   - Confirm when prompted (unless using `--force`)
   - Watch for the success message

4. **Verify the Release**
   - Check GitHub for the new release
   - Verify the ZIP package is available for download
   - Test the package in a WordPress environment

### Preview Mode (Recommended for First-Time Users)

Always test with dry-run mode first:

```bash
# Preview what a patch release would do
npm run test:release patch

# Or use the release script directly
node scripts/release.js patch --dry-run
```

## 📖 Detailed Usage

### Command Line Options

The release system supports several command-line options:

```bash
node scripts/release.js <type> [options]

# Release Types:
#   patch     - Patch release (1.1.0 → 1.1.1)
#   minor     - Minor release (1.1.0 → 1.2.0)
#   major     - Major release (1.1.0 → 2.0.0)
#   beta      - Beta release (1.1.0 → 1.1.1-beta.1)

# Options:
#   --dry-run    Preview changes without executing
#   --force      Skip confirmation prompts
#   --help       Show help information
```

### Examples

```bash
# Standard releases with confirmation
npm run release:patch
npm run release:minor

# Force release without prompts (CI/CD)
node scripts/release.js patch --force

# Preview a major release
node scripts/release.js major --dry-run

# Beta release for testing
npm run release:beta
```

### What Happens During a Release

1. **Environment Validation**
   - Checks Git repository status
   - Verifies Node.js version compatibility
   - Ensures all required tools are available
   - Validates working directory is clean

2. **Version Management**
   - Reads current version from main plugin file
   - Calculates new version based on release type
   - Updates version in all relevant files:
     - `notion-to-wordpress.php`
     - `readme.txt`
     - `package.json`
     - `includes/class-notion-to-wordpress.php`
     - Documentation files

3. **Package Building**
   - Creates WordPress-standard ZIP package
   - Excludes development files and documentation
   - Includes only runtime-necessary files
   - Generates optimized plugin package

4. **Git Operations**
   - Creates commit with version changes
   - Creates annotated version tag (e.g., `v1.1.1`)
   - Pushes commits and tags to GitHub

5. **GitHub Actions Trigger**
   - Automatically triggered by version tag push
   - Builds and validates the package
   - Creates GitHub Release with:
     - Download links for ZIP package
     - Security checksums (SHA256, MD5)
     - Auto-generated release notes
     - Installation instructions

## ⚙️ Configuration

### Release Configuration File

The system uses `release.config.js` for configuration. Key sections include:

```javascript
// Project information
project: {
  name: 'notion-to-wordpress',
  displayName: 'Notion-to-WordPress',
  author: 'Frank-Loong'
}

// Version management
version: {
  files: [/* Files to update */],
  validation: {/* Validation rules */}
}

// Build settings
build: {
  output: {/* Output configuration */},
  include: {/* Files to include */},
  exclude: {/* Files to exclude */}
}
```

### Customizing the Configuration

1. **Edit Configuration**
   ```bash
   # Edit the configuration file
   nano release.config.js
   ```

2. **Validate Changes**
   ```bash
   npm run validate:config
   ```

3. **Test Configuration**
   ```bash
   node scripts/test-config.js
   ```

### Common Configuration Changes

- **Change output directory**: Modify `build.output.directory`
- **Add/remove files**: Update `version.files` array
- **Customize commit messages**: Edit `git.commitMessage.template`
- **Modify GitHub settings**: Update `github` section

## 🔧 Troubleshooting

### Common Issues and Solutions

#### "Working directory has uncommitted changes"

**Problem**: Git working directory is not clean

**Solution**:
```bash
# Check what files are modified
git status

# Commit your changes
git add .
git commit -m "Your changes"

# Or force release (not recommended)
node scripts/release.js patch --force
```

#### "Version mismatch detected"

**Problem**: Version numbers are inconsistent across files

**Solution**:
```bash
# Check version consistency
node scripts/version-bump.js

# Fix manually or use version bump tool
node scripts/version-bump.js patch
```

#### "Build failed"

**Problem**: Package building encountered an error

**Solution**:
```bash
# Check Node.js version
node --version  # Should be 16+

# Reinstall dependencies
rm -rf node_modules package-lock.json
npm install

# Test build manually
npm run build
```

#### "GitHub Actions workflow failed"

**Problem**: Automated release workflow failed

**Solution**:
1. Check the Actions tab in your GitHub repository
2. Review the workflow logs for specific errors
3. Ensure GitHub token has proper permissions
4. Verify the workflow file syntax:
   ```bash
   npm run validate:github-actions
   ```

### Debug Mode

For detailed troubleshooting:

```bash
# Dry run with full output
node scripts/release.js patch --dry-run --force

# Manual step-by-step testing
node scripts/version-bump.js patch
npm run build
git status
```

### Getting Help

If you encounter issues not covered here:

1. **Check the logs**: Review console output for specific error messages
2. **Validate configuration**: Run `npm run validate:config`
3. **Test components**: Use individual scripts to isolate issues
4. **Create an issue**: Report bugs with detailed information

## 🔐 Security Considerations

### Package Verification

Every release includes security checksums:

```bash
# Download checksums.txt from the release
# Verify SHA256 checksum
sha256sum notion-to-wordpress-1.1.1.zip

# Verify MD5 checksum
md5sum notion-to-wordpress-1.1.1.zip
```

### GitHub Token Security

- The system uses GitHub's automatic `GITHUB_TOKEN`
- No manual token configuration required
- Permissions are automatically scoped to the repository

### Safe Release Practices

1. **Always test with dry-run first**
2. **Review changes before confirming**
3. **Keep backups of important configurations**
4. **Use semantic versioning consistently**
5. **Test releases in staging environments**

## 📚 Best Practices

### Release Workflow

1. **Development Phase**
   - Make changes in feature branches
   - Test thoroughly before merging
   - Update documentation as needed

2. **Pre-Release**
   - Merge to main branch
   - Run tests and validation
   - Use dry-run to preview release

3. **Release**
   - Choose appropriate version type
   - Execute release command
   - Monitor GitHub Actions

4. **Post-Release**
   - Verify release is available
   - Test installation in WordPress
   - Update any external documentation

### Version Strategy

- **Patch (1.1.0 → 1.1.1)**: Bug fixes, security updates, minor improvements
- **Minor (1.1.0 → 1.2.0)**: New features, enhancements, non-breaking changes
- **Major (1.1.0 → 2.0.0)**: Breaking changes, major rewrites, API changes
- **Beta (1.1.0 → 1.1.1-beta.1)**: Testing versions, experimental features

### Maintenance

- **Regular Updates**: Keep dependencies updated
- **Configuration Review**: Periodically review and update configuration
- **Documentation**: Keep documentation in sync with changes
- **Backup**: Maintain backups of configuration and important files

## 🎉 Success Indicators

A successful release will show:

- ✅ All version numbers updated consistently
- ✅ Clean Git commit and tag created
- ✅ WordPress-compatible ZIP package generated
- ✅ GitHub Actions workflow completed successfully
- ✅ GitHub Release created with downloads available
- ✅ Security checksums generated and uploaded
- ✅ Release notes automatically generated

## 📞 Support

Need help? Here are your options:

1. **Documentation**: Check this guide and the project README
2. **Configuration**: Run validation tools to check your setup
3. **Issues**: Create a GitHub issue with detailed information
4. **Community**: Check existing issues for similar problems

---

**Happy Releasing! 🚀**

*This automated release system is designed to make your life easier. If you have suggestions for improvements, please let us know!*

---

## 📖 Additional Resources

- [中文版本 (Chinese Version)](./RELEASE_GUIDE-zh_CN.md)
- [GitHub Actions Configuration](./.github/workflows/release.yml)
- [Release Configuration](../release.config.js)
- [Project README](../README.md)
