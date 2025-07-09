# 🚀 Automated Release System Guide

This document explains how to use the automated release system for the Notion-to-WordPress plugin.

## 📋 Overview

The automated release system provides a complete CI/CD pipeline that:

- ✅ **Automatically updates version numbers** across all project files
- ✅ **Builds WordPress-standard plugin packages** (ZIP files)
- ✅ **Creates Git commits and tags** with proper versioning
- ✅ **Triggers GitHub Actions** for automated releases
- ✅ **Generates GitHub Releases** with download links and release notes
- ✅ **Provides security checksums** for package verification

## 🎯 Quick Start

### 1. Local Release (Recommended)

Use the release controller to handle everything automatically:

```bash
# Patch release (1.1.0 → 1.1.1)
npm run release:patch

# Minor release (1.1.0 → 1.2.0)
npm run release:minor

# Major release (1.1.0 → 2.0.0)
npm run release:major

# Beta release (1.1.0 → 1.1.1-beta.1)
npm run release:beta
```

### 2. Preview Mode (Dry Run)

Test the release process without making changes:

```bash
# Preview a patch release
npm run test:release patch

# Or use the release script directly
node scripts/release.js patch --dry-run
```

## 🔧 How It Works

### Step 1: Version Update
- Updates version in `notion-to-wordpress.php`
- Updates version in `readme.txt`
- Updates version in `package.json`
- Updates version in class files and documentation

### Step 2: Build Package
- Creates WordPress-standard ZIP package
- Excludes development files (scripts, docs, etc.)
- Includes only runtime-necessary files
- Generates optimized 1MB package

### Step 3: Git Operations
- Creates commit with version changes
- Creates version tag (e.g., `v1.1.1`)
- Pushes to GitHub repository

### Step 4: GitHub Actions
- Automatically triggered by version tag push
- Builds and validates the package
- Creates GitHub Release with:
  - Download links for ZIP package
  - Security checksums (SHA256, MD5)
  - Auto-generated release notes
  - Installation instructions

## 🛡️ Safety Features

### Environment Validation
- Checks for uncommitted changes
- Verifies Git repository status
- Validates Node.js version compatibility
- Ensures all required tools are available

### Error Handling & Rollback
- Automatic backup before changes
- Complete rollback on any failure
- Detailed error messages and logging
- Safe operation with confirmation prompts

### Version Consistency
- Validates version format (semantic versioning)
- Ensures consistency across all files
- Prevents duplicate or invalid releases

## 📦 Release Types

| Command | Version Change | Use Case |
|---------|---------------|----------|
| `release:patch` | 1.1.0 → 1.1.1 | Bug fixes, small improvements |
| `release:minor` | 1.1.0 → 1.2.0 | New features, enhancements |
| `release:major` | 1.1.0 → 2.0.0 | Breaking changes, major updates |
| `release:beta` | 1.1.0 → 1.1.1-beta.1 | Testing, pre-release versions |

## 🔐 Security & Verification

### Package Checksums
Every release includes checksums for security verification:

```bash
# Verify SHA256 checksum
sha256sum notion-to-wordpress-1.1.1.zip

# Verify MD5 checksum  
md5sum notion-to-wordpress-1.1.1.zip
```

### GitHub Token Permissions
The workflow uses minimal required permissions:
- `contents: write` - For creating releases and uploading files
- `actions: read` - For accessing workflow artifacts

## 🚨 Troubleshooting

### Common Issues

**"Working directory has uncommitted changes"**
```bash
# Commit your changes first
git add .
git commit -m "Your changes"

# Or force release (not recommended)
node scripts/release.js patch --force
```

**"Version mismatch detected"**
- Ensure all files have consistent version numbers
- Run version validation: `node scripts/version-bump.js`

**"Build failed"**
- Check Node.js version (requires 16+)
- Verify all dependencies: `npm install`
- Test build manually: `npm run build`

### Debug Mode

Enable detailed logging for troubleshooting:

```bash
# Dry run with full output
node scripts/release.js patch --dry-run --force

# Manual step-by-step testing
node scripts/version-bump.js patch
npm run build
node scripts/validate-github-actions.js
```

## 📚 Advanced Usage

### Custom Configuration

The system can be customized through:
- `package.json` - npm scripts and dependencies
- `.github/workflows/release.yml` - GitHub Actions workflow
- `scripts/` - Individual tool configurations

### Manual GitHub Actions Trigger

If needed, you can manually trigger the release workflow:

1. Go to GitHub Actions tab
2. Select "🚀 Release WordPress Plugin" workflow
3. Click "Run workflow"
4. Enter the tag name (e.g., `v1.1.1`)

### Integration with Other Tools

The release system integrates with:
- **Git** - Version control and tagging
- **npm** - Package management and scripts
- **GitHub** - Repository hosting and releases
- **WordPress** - Plugin packaging standards

## 🎉 Success Indicators

A successful release will:
- ✅ Update all version numbers consistently
- ✅ Create a clean Git commit and tag
- ✅ Generate a WordPress-compatible ZIP package
- ✅ Trigger GitHub Actions workflow
- ✅ Create a GitHub Release with downloads
- ✅ Provide security checksums
- ✅ Generate release notes automatically

## 📞 Support

If you encounter issues:
1. Check this guide for common solutions
2. Review the workflow logs in GitHub Actions
3. Create an issue with detailed error information
4. Include relevant log outputs and system information

---

**Happy Releasing! 🚀**