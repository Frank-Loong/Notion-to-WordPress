#!/usr/bin/env node

/** Verify a distributable WordPress plugin archive and release metadata. */
const fs = require('fs');
const path = require('path');
const AdmZip = require('adm-zip');

const projectRoot = path.resolve(__dirname, '..');
const buildDir = path.join(projectRoot, 'build');
const pluginPrefix = 'notion-to-wordpress/';

function fail(message) {
  throw new Error(message);
}

function readVersionMetadata() {
  const pluginFile = fs.readFileSync(
    path.join(projectRoot, 'notion-to-wordpress.php'),
    'utf8'
  );
  const readme = fs.readFileSync(path.join(projectRoot, 'readme.txt'), 'utf8');
  const packageVersion = JSON.parse(
    fs.readFileSync(path.join(projectRoot, 'package.json'), 'utf8')
  ).version;
  const header = pluginFile.match(/^\s*\*\s+Version:\s*(.+)$/m);
  const constant = pluginFile.match(
    /NOTION_TO_WORDPRESS_VERSION'\s*,\s*'([^']+)'/
  );
  const stableTag = readme.match(/^Stable tag:\s*(.+)$/im);
  const versions = {
    'plugin header': header?.[1].trim(),
    'plugin constant': constant?.[1],
    'package.json': packageVersion,
    'readme.txt': stableTag?.[1].trim(),
  };

  if (Object.values(versions).some(version => !version)) {
    fail(`Missing version metadata: ${JSON.stringify(versions)}`);
  }
  if (new Set(Object.values(versions)).size !== 1) {
    fail(`Version metadata is inconsistent: ${JSON.stringify(versions)}`);
  }
  return packageVersion;
}

function findArchive() {
  if (!fs.existsSync(buildDir)) {
    fail('Build directory does not exist. Run npm run build first.');
  }
  const archives = fs
    .readdirSync(buildDir)
    .filter(file => file.endsWith('.zip'));
  if (archives.length !== 1) {
    fail(`Expected exactly one ZIP archive, found ${archives.length}.`);
  }
  return path.join(buildDir, archives[0]);
}

function verifyArchive(archivePath, version) {
  const archiveName = path.basename(archivePath);
  if (archiveName !== `notion-to-wordpress-${version}.zip`) {
    fail(`Unexpected archive name: ${archiveName}`);
  }

  const entries = new AdmZip(archivePath)
    .getEntries()
    .filter(entry => !entry.isDirectory)
    .map(entry => entry.entryName);
  const requiredFiles = [
    'notion-to-wordpress.php',
    'readme.txt',
    'uninstall.php',
    'includes/framework/Autoloader.php',
    'assets/dist/js/admin.js',
    'assets/dist/js/frontend.js',
    'assets/dist/js/runtime.js',
    'assets/dist/js/vendors.js',
    'assets/dist/js/common.js',
  ];
  const requiredDirectories = ['admin/', 'assets/', 'includes/', 'languages/'];
  const forbiddenPatterns = [
    /^node_modules\//,
    /^vendor\//,
    /^scripts\//,
    /^\.git\//,
    /^package(-lock)?\.json$/,
    /^\.env(?:\.|$)/,
  ];

  for (const file of requiredFiles) {
    if (!entries.includes(`${pluginPrefix}${file}`)) {
      fail(`Archive is missing required file: ${file}`);
    }
  }
  for (const directory of requiredDirectories) {
    if (
      !entries.some(entry => entry.startsWith(`${pluginPrefix}${directory}`))
    ) {
      fail(`Archive is missing required directory: ${directory}`);
    }
  }
  for (const entry of entries) {
    if (!entry.startsWith(pluginPrefix)) {
      fail(`Archive entry is outside plugin root: ${entry}`);
    }
    const relativeEntry = entry.slice(pluginPrefix.length);
    if (forbiddenPatterns.some(pattern => pattern.test(relativeEntry))) {
      fail(`Archive contains forbidden entry: ${entry}`);
    }
  }
}

try {
  const version = readVersionMetadata();
  const archivePath = findArchive();
  verifyArchive(archivePath, version);
  console.log(`Build verification passed: ${path.basename(archivePath)}`);
} catch (error) {
  console.error(`Build verification failed: ${error.message}`);
  process.exitCode = 1;
}
