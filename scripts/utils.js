#!/usr/bin/env node

/**
 * Notion-to-WordPress 发布系统通用工具函数库
 * 
 * 本模块提供发布系统常用的工具函数，包括文件操作、命令执行、日志、路径处理、
 * 字符串处理和校验等。
 * 
 * @author Frank-Loong
 * @version 2.0.0-beta.2
 */

const fs = require('fs');
const path = require('path');
const { execSync, spawn } = require('child_process');
const chalk = require('chalk');
const semver = require('semver');

/**
 * 文件操作
 */

/**
 * 安全读取文件内容
 * @param {string} filePath - 文件路径
 * @param {string} encoding - 文件编码（默认 utf8）
 * @returns {string} 文件内容
 */
function readFile(filePath, encoding = 'utf8') {
    try {
        if (!fs.existsSync(filePath)) {
            throw new Error(`文件未找到: ${filePath}`);
        }
        return fs.readFileSync(filePath, encoding);
    } catch (error) {
        throw new Error(`读取文件 ${filePath} 失败: ${error.message}`);
    }
}

/**
 * 安全写入文件内容
 * @param {string} filePath - 文件路径
 * @param {string} content - 写入内容
 * @param {string} encoding - 文件编码（默认 utf8）
 */
function writeFile(filePath, content, encoding = 'utf8') {
    try {
        // 确保目录存在
        const dir = path.dirname(filePath);
        ensureDir(dir);
        
        fs.writeFileSync(filePath, content, encoding);
    } catch (error) {
        throw new Error(`写入文件 ${filePath} 失败: ${error.message}`);
    }
}

/**
 * 安全复制文件
 * @param {string} sourcePath - 源文件路径
 * @param {string} targetPath - 目标文件路径
 */
function copyFile(sourcePath, targetPath) {
    try {
        if (!fs.existsSync(sourcePath)) {
            throw new Error(`源文件未找到: ${sourcePath}`);
        }
        
        // 确保目标目录存在
        const targetDir = path.dirname(targetPath);
        ensureDir(targetDir);
        
        fs.copyFileSync(sourcePath, targetPath);
    } catch (error) {
        throw new Error(`复制文件从 ${sourcePath} 到 ${targetPath} 失败: ${error.message}`);
    }
}

/**
 * 安全删除文件
 * @param {string} filePath - 文件路径
 */
function deleteFile(filePath) {
    try {
        if (fs.existsSync(filePath)) {
            fs.unlinkSync(filePath);
        }
    } catch (error) {
        throw new Error(`删除文件 ${filePath} 失败: ${error.message}`);
    }
}

/**
 * 命令执行
 */

/**
 * 同步执行命令
 * @param {string} command - 要执行的命令
 * @param {object} options - 执行选项
 * @returns {string} 命令输出
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
        throw new Error(`命令执行失败: ${command}\n错误: ${error.message}`);
    }
}

/**
 * 异步执行命令
 * @param {string} command - 要执行的命令
 * @param {object} options - 执行选项
 * @returns {Promise} 返回命令输出的 Promise
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
                reject(new Error(`命令执行失败，错误代码 ${code}: ${stderr}`));
            }
        });
        
        child.on('error', (error) => {
            reject(new Error(`命令执行失败: ${error.message}`));
        });
    });
}

/**
 * 日志函数
 */

/**
 * 普通日志
 * @param {string} message - 日志内容
 */
function log(message) {
    console.log(message);
}

/**
 * 成功日志
 * @param {string} message - 成功信息
 */
function success(message) {
    console.log(chalk.green('✅ ' + message));
}

/**
 * 警告日志
 * @param {string} message - 警告信息
 */
function warn(message) {
    console.log(chalk.yellow('⚠️  ' + message));
}

/**
 * 错误日志
 * @param {string} message - 错误信息
 */
function error(message) {
    console.log(chalk.red('❌ ' + message));
}

/**
 * 信息日志
 * @param {string} message - 信息内容
 */
function info(message) {
    console.log(chalk.blue('ℹ️  ' + message));
}

/**
 * 路径处理函数
 */

/**
 * 相对项目根目录解析路径
 * @param {...string} pathSegments - 路径片段
 * @returns {string} 绝对路径
 */
function resolvePath(...pathSegments) {
    const projectRoot = path.resolve(__dirname, '..');
    return path.resolve(projectRoot, ...pathSegments);
}

/**
 * 确保目录存在
 * @param {string} dirPath - 目录路径
 */
function ensureDir(dirPath) {
    try {
        if (!fs.existsSync(dirPath)) {
            fs.mkdirSync(dirPath, { recursive: true });
        }
    } catch (error) {
        throw new Error(`创建目录 ${dirPath} 失败: ${error.message}`);
    }
}

/**
 * 判断路径是否为目录
 * @param {string} dirPath - 待检测路径
 * @returns {boolean} 是否为目录
 */
function isDirectory(dirPath) {
    try {
        return fs.existsSync(dirPath) && fs.statSync(dirPath).isDirectory();
    } catch (error) {
        return false;
    }
}

/**
 * 判断路径是否为文件
 * @param {string} filePath - 待检测路径
 * @returns {boolean} 是否为文件
 */
function isFile(filePath) {
    try {
        return fs.existsSync(filePath) && fs.statSync(filePath).isFile();
    } catch (error) {
        return false;
    }
}

/**
 * 字符串处理函数
 */

/**
 * 用正则替换文本中的版本号
 * @param {string} text - 待处理文本
 * @param {string} newVersion - 新版本号
 * @param {RegExp} pattern - 匹配正则
 * @param {string} replacement - 替换模板
 * @returns {string} 替换后的文本
 */
function replaceVersion(text, newVersion, pattern, replacement) {
    try {
        const replacementText = replacement.replace('{VERSION}', newVersion);
        return text.replace(pattern, replacementText);
    } catch (error) {
        throw new Error(`替换版本号失败: ${error.message}`);
    }
}

/**
 * 格式化当前日期
 * @param {string} format - 日期格式（默认 YYYY-MM-DD）
 * @returns {string} 格式化后的日期
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
 * 首字母大写
 * @param {string} str - 待处理字符串
 * @returns {string} 首字母大写后的字符串
 */
function capitalize(str) {
    if (!str || typeof str !== 'string') return str;
    return str.charAt(0).toUpperCase() + str.slice(1);
}

/**
 * 校验函数
 */

/**
 * 校验版本号字符串是否合法
 * @param {string} version - 待校验版本号
 * @returns {boolean} 是否合法
 */
function isValidVersion(version) {
    try {
        return semver.valid(version) !== null;
    } catch (error) {
        return false;
    }
}

/**
 * 检查 Git 工作区是否干净
 * @param {string} cwd - 工作目录（默认项目根目录）
 * @returns {boolean} 是否干净
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
 * 检查当前目录是否为 Git 仓库
 * @param {string} cwd - 工作目录（默认项目根目录）
 * @returns {boolean} 是否为 Git 仓库
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
 * 检查 Node.js 版本是否满足要求
 * @param {string} requiredVersion - 要求的 Node.js 版本（默认 16.0.0）
 * @returns {boolean} 是否满足
 */
function isNodeVersionValid(requiredVersion = '16.0.0') {
    try {
        const currentVersion = process.version.slice(1); // 去掉 'v' 前缀
        return semver.gte(currentVersion, requiredVersion);
    } catch (error) {
        return false;
    }
}

/**
 * 错误处理工具
 */

/**
 * 包裹函数，自动处理异常
 * @param {Function} fn - 需要包裹的函数
 * @param {string} context - 错误上下文
 * @returns {Function} 包裹后的函数
 */
function withErrorHandling(fn, context = '操作') {
    return function(...args) {
        try {
            return fn.apply(this, args);
        } catch (error) {
            throw new Error(`${context}失败: ${error.message}`);
        }
    };
}

/**
 * 带重试的函数调用（指数退避）
 * @param {Function} fn - 需要重试的函数
 * @param {number} maxRetries - 最大重试次数（默认 3）
 * @param {number} baseDelay - 基础延迟毫秒（默认 1000）
 * @returns {Promise} 返回函数结果
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

/**
 * 显示友好的 npm scripts 帮助信息
 */
function showHelp() {
    const packageJson = JSON.parse(readFile(path.join(__dirname, '..', 'package.json')));
    const scripts = packageJson.scripts || {};

    console.log(chalk.cyan('\n🚀 Notion-to-WordPress 开发脚本\n'));

    // 按分类组织脚本
    const categories = {
        '🏗️  构建 (Build)': {
            'build': '构建 WordPress 插件包',
            'build:clean': '清理构建目录',
            'build:verify': '验证构建结果'
        },
        '📦 版本管理 (Version)': {
            'version:check': '检查版本一致性',
            'version:patch': '升级补丁版本 (1.0.0 → 1.0.1)',
            'version:minor': '升级小版本 (1.0.0 → 1.1.0)',
            'version:major': '升级主版本 (1.0.0 → 2.0.0)',
            'version:beta': '升级测试版本 (1.0.0 → 1.0.1-beta.1)',
            'version:help': '显示版本管理帮助'
        },
        '🔧 自定义版本 (Custom Version)': {
            'node scripts/version-bump.js --version=X.Y.Z': '设置自定义版本号'
        },
        '🚀 发布 (Release)': {
            'release:patch': '发布补丁版本',
            'release:minor': '发布小版本',
            'release:major': '发布主版本',
            'release:beta': '发布测试版本',
            'release:dry-run': '模拟发布（不实际执行）',
            'release:help': '显示发布帮助'
        },
        '🚀 自定义发布 (Custom Release)': {
            'node scripts/release.js custom --version=X.Y.Z --dry-run': '发布自定义版本'
        },
        '🧪 测试 (Test)': {
            'test': '运行默认测试',
            'test:integration': '运行集成测试',
            'test:syntax': '检查语法',
            'test:release': '测试发布流程',
            'test:release:patch': '测试补丁发布',
            'test:release:minor': '测试小版本发布',
            'test:release:major': '测试主版本发布',
            'test:release:beta': '测试测试版发布'
        },
        '✅ 验证 (Validate)': {
            'validate': '运行所有验证',
            'validate:config': '验证配置文件',
            'validate:github-actions': '验证 GitHub Actions',
            'validate:version': '验证版本一致性'
        },
        '🛠️  开发 (Development)': {
            'dev': '开发环境快速部署',
            'dev:deploy': '部署到本地 WordPress'
        },
        '🔧 工具 (Utilities)': {
            'clean': '清理构建文件',
            'help': '显示此帮助信息'
        }
    };

    // 显示分类帮助
    Object.keys(categories).forEach(categoryName => {
        console.log(chalk.yellow(categoryName));
        const categoryScripts = categories[categoryName];

        Object.keys(categoryScripts).forEach(scriptName => {
            const description = categoryScripts[scriptName];
            if (scripts[scriptName]) {
                // npm scripts
                console.log(`  ${chalk.green('npm run ' + scriptName.padEnd(20))} ${description}`);
            } else if (scriptName.startsWith('node ')) {
                // node commands
                console.log(`  ${chalk.green(scriptName.padEnd(35))} ${description}`);
            }
        });
        console.log('');
    });
    
    // 显示更多信息
    console.log(chalk.cyan('📚 更多信息:\n'));
    console.log(`  开发文档: ${chalk.blue('docs/DEVELOPER_GUIDE.md')}`);
    console.log(`  中文文档: ${chalk.blue('docs/DEVELOPER_GUIDE-zh_CN.md')}`);
    console.log(`  项目主页: ${chalk.blue(packageJson.homepage || 'N/A')}`);
    console.log('');
}

// 处理命令行参数
if (require.main === module) {
    const args = process.argv.slice(2);
    if (args.includes('--help') || args.includes('-h')) {
        showHelp();
    }
}

// 导出所有工具函数
module.exports = {
    // 文件操作
    readFile,
    writeFile,
    copyFile,
    deleteFile,
    
    // 命令执行
    execCommand,
    execCommandAsync,
    
    // 日志
    log,
    success,
    warn,
    error,
    info,
    
    // 路径处理
    resolvePath,
    ensureDir,
    isDirectory,
    isFile,
    
    // 字符串处理
    replaceVersion,
    formatDate,
    capitalize,
    
    // 校验
    isValidVersion,
    isGitClean,
    isGitRepository,
    isNodeVersionValid,
    
    // 错误处理
    withErrorHandling,
    retry,

    // Help 系统
    showHelp
};
