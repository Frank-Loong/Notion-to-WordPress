#!/usr/bin/env node

/**
 * Utility Functions Library for Notion-to-WordPress Release System
 * 
 * This module provides common utility functions used across the release system,
 * including file operations, command execution, logging, path handling,
 * string processing, and validation functions.
 * 
 * @author Frank-Loong
 * @version 1.0.0
 */

const fs = require('fs');
const path = require('path');
const { execSync, spawn } = require('child_process');
const chalk = require('chalk');
const semver = require('semver');

/**
 * File Operations
 */

/**
 * Read file content safely
 * @param {string} filePath - Path to the file
 * @param {string} encoding - File encoding (default: utf8)
 * @returns {string} File content
 */
function readFile(filePath, encoding = 'utf8') {
    try {
        if (!fs.existsSync(filePath)) {
            throw new Error(`File not found: ${filePath}`);
        }
        return fs.readFileSync(filePath, encoding);
    } catch (error) {
        throw new Error(`Failed to read file ${filePath}: ${error.message}`);
    }
}

/**
 * Write file content safely
 * @param {string} filePath - Path to the file
 * @param {string} content - Content to write
 * @param {string} encoding - File encoding (default: utf8)
 */
function writeFile(filePath, content, encoding = 'utf8') {
    try {
        // Ensure directory exists
        const dir = path.dirname(filePath);
        ensureDir(dir);
        
        fs.writeFileSync(filePath, content, encoding);
    } catch (error) {
        throw new Error(`Failed to write file ${filePath}: ${error.message}`);
    }
}

/**
 * Copy file safely
 * @param {string} sourcePath - Source file path
 * @param {string} targetPath - Target file path
 */
function copyFile(sourcePath, targetPath) {
    try {
        if (!fs.existsSync(sourcePath)) {
            throw new Error(`Source file not found: ${sourcePath}`);
        }
        
        // Ensure target directory exists
        const targetDir = path.dirname(targetPath);
        ensureDir(targetDir);
        
        fs.copyFileSync(sourcePath, targetPath);
    } catch (error) {
        throw new Error(`Failed to copy file from ${sourcePath} to ${targetPath}: ${error.message}`);
    }
}

/**
 * Delete file safely
 * @param {string} filePath - Path to the file
 */
function deleteFile(filePath) {
    try {
        if (fs.existsSync(filePath)) {
            fs.unlinkSync(filePath);
        }
    } catch (error) {
        throw new Error(`Failed to delete file ${filePath}: ${error.message}`);
    }
}

/**
 * Command Execution
 */

/**
 * Execute command synchronously
 * @param {string} command - Command to execute
 * @param {object} options - Execution options
 * @returns {string} Command output
 */
function execCommand(command, options = {}) {
    try {
        const defaultOptions = {
            encoding: 'utf8',
            stdio: 'pipe',
            ...options
        };
        
        return execSync(command, defaultOptions);
    } catch (error) {
        throw new Error(`Command failed: ${command}\nError: ${error.message}`);
    }
}

/**
 * Execute command asynchronously
 * @param {string} command - Command to execute
 * @param {object} options - Execution options
 * @returns {Promise} Promise that resolves with command output
 */
function execCommandAsync(command, options = {}) {
    return new Promise((resolve, reject) => {
        const [cmd, ...args] = command.split(' ');
        const child = spawn(cmd, args, {
            stdio: 'pipe',
            ...options
        });
        
        let stdout = '';
        let stderr = '';
        
        child.stdout.on('data', (data) => {
            stdout += data.toString();
        });
        
        child.stderr.on('data', (data) => {
            stderr += data.toString();
        });
        
        child.on('close', (code) => {
            if (code === 0) {
                resolve(stdout);
            } else {
                reject(new Error(`Command failed with code ${code}: ${stderr}`));
            }
        });
        
        child.on('error', (error) => {
            reject(new Error(`Failed to execute command: ${error.message}`));
        });
    });
}

/**
 * Logging Functions
 */

/**
 * Log regular message
 * @param {string} message - Message to log
 */
function log(message) {
    console.log(message);
}

/**
 * Log success message
 * @param {string} message - Success message
 */
function success(message) {
    console.log(chalk.green('✅ ' + message));
}

/**
 * Log warning message
 * @param {string} message - Warning message
 */
function warn(message) {
    console.log(chalk.yellow('⚠️  ' + message));
}

/**
 * Log error message
 * @param {string} message - Error message
 */
function error(message) {
    console.log(chalk.red('❌ ' + message));
}

/**
 * Log info message
 * @param {string} message - Info message
 */
function info(message) {
    console.log(chalk.blue('ℹ️  ' + message));
}

/**
 * Path Handling Functions
 */

/**
 * Resolve path relative to project root
 * @param {...string} pathSegments - Path segments to resolve
 * @returns {string} Resolved absolute path
 */
function resolvePath(...pathSegments) {
    const projectRoot = path.resolve(__dirname, '..');
    return path.resolve(projectRoot, ...pathSegments);
}

/**
 * Ensure directory exists
 * @param {string} dirPath - Directory path
 */
function ensureDir(dirPath) {
    try {
        if (!fs.existsSync(dirPath)) {
            fs.mkdirSync(dirPath, { recursive: true });
        }
    } catch (error) {
        throw new Error(`Failed to create directory ${dirPath}: ${error.message}`);
    }
}

/**
 * Check if path is a directory
 * @param {string} dirPath - Path to check
 * @returns {boolean} True if path is a directory
 */
function isDirectory(dirPath) {
    try {
        return fs.existsSync(dirPath) && fs.statSync(dirPath).isDirectory();
    } catch (error) {
        return false;
    }
}

/**
 * Check if path is a file
 * @param {string} filePath - Path to check
 * @returns {boolean} True if path is a file
 */
function isFile(filePath) {
    try {
        return fs.existsSync(filePath) && fs.statSync(filePath).isFile();
    } catch (error) {
        return false;
    }
}

/**
 * String Processing Functions
 */

/**
 * Replace version in text using pattern
 * @param {string} text - Text to process
 * @param {string} newVersion - New version to insert
 * @param {RegExp} pattern - Pattern to match
 * @param {string} replacement - Replacement template
 * @returns {string} Text with version replaced
 */
function replaceVersion(text, newVersion, pattern, replacement) {
    try {
        const replacementText = replacement.replace('{VERSION}', newVersion);
        return text.replace(pattern, replacementText);
    } catch (error) {
        throw new Error(`Failed to replace version: ${error.message}`);
    }
}

/**
 * Format current date
 * @param {string} format - Date format (default: YYYY-MM-DD)
 * @returns {string} Formatted date
 */
function formatDate(format = 'YYYY-MM-DD') {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    
    return format
        .replace('YYYY', year)
        .replace('MM', month)
        .replace('DD', day)
        .replace('HH', hours)
        .replace('mm', minutes)
        .replace('ss', seconds);
}

/**
 * Capitalize first letter of string
 * @param {string} str - String to capitalize
 * @returns {string} Capitalized string
 */
function capitalize(str) {
    if (!str || typeof str !== 'string') return str;
    return str.charAt(0).toUpperCase() + str.slice(1);
}

/**
 * Validation Functions
 */

/**
 * Check if version string is valid
 * @param {string} version - Version string to validate
 * @returns {boolean} True if version is valid
 */
function isValidVersion(version) {
    try {
        return semver.valid(version) !== null;
    } catch (error) {
        return false;
    }
}

/**
 * Check if Git working directory is clean
 * @param {string} cwd - Working directory (default: project root)
 * @returns {boolean} True if Git working directory is clean
 */
function isGitClean(cwd = null) {
    try {
        const workingDir = cwd || resolvePath();
        const status = execCommand('git status --porcelain', { cwd: workingDir });
        return status.trim() === '';
    } catch (error) {
        return false;
    }
}

/**
 * Check if current directory is a Git repository
 * @param {string} cwd - Working directory (default: project root)
 * @returns {boolean} True if directory is a Git repository
 */
function isGitRepository(cwd = null) {
    try {
        const workingDir = cwd || resolvePath();
        execCommand('git rev-parse --git-dir', { cwd: workingDir });
        return true;
    } catch (error) {
        return false;
    }
}

/**
 * Check if Node.js version meets requirement
 * @param {string} requiredVersion - Required Node.js version (default: 16.0.0)
 * @returns {boolean} True if Node.js version is sufficient
 */
function isNodeVersionValid(requiredVersion = '16.0.0') {
    try {
        const currentVersion = process.version.slice(1); // Remove 'v' prefix
        return semver.gte(currentVersion, requiredVersion);
    } catch (error) {
        return false;
    }
}

/**
 * Error Handling Utilities
 */

/**
 * Wrap function with error handling
 * @param {Function} fn - Function to wrap
 * @param {string} context - Context for error messages
 * @returns {Function} Wrapped function
 */
function withErrorHandling(fn, context = 'Operation') {
    return function(...args) {
        try {
            return fn.apply(this, args);
        } catch (error) {
            throw new Error(`${context} failed: ${error.message}`);
        }
    };
}

/**
 * Retry function with exponential backoff
 * @param {Function} fn - Function to retry
 * @param {number} maxRetries - Maximum number of retries (default: 3)
 * @param {number} baseDelay - Base delay in milliseconds (default: 1000)
 * @returns {Promise} Promise that resolves with function result
 */
async function retry(fn, maxRetries = 3, baseDelay = 1000) {
    let lastError;
    
    for (let attempt = 0; attempt <= maxRetries; attempt++) {
        try {
            return await fn();
        } catch (error) {
            lastError = error;
            
            if (attempt < maxRetries) {
                const delay = baseDelay * Math.pow(2, attempt);
                await new Promise(resolve => setTimeout(resolve, delay));
            }
        }
    }
    
    throw lastError;
}

// Export all utility functions
module.exports = {
    // File operations
    readFile,
    writeFile,
    copyFile,
    deleteFile,
    
    // Command execution
    execCommand,
    execCommandAsync,
    
    // Logging
    log,
    success,
    warn,
    error,
    info,
    
    // Path handling
    resolvePath,
    ensureDir,
    isDirectory,
    isFile,
    
    // String processing
    replaceVersion,
    formatDate,
    capitalize,
    
    // Validation
    isValidVersion,
    isGitClean,
    isGitRepository,
    isNodeVersionValid,
    
    // Error handling
    withErrorHandling,
    retry
};
