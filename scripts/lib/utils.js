/**
 * 统一工具函数模块
 * 
 * 提供所有脚本共用的工具函数，
 * 避免在每个脚本中重复定义。
 * 
 * @author Frank-Loong
 * @version 2.0.0-beta.1
 */

const chalk = require('chalk');
const fs = require('fs');
const path = require('path');

class Utils {
    /**
     * 日志输出
     */
    static log(message, type = 'info') {
        const timestamp = new Date().toLocaleTimeString();
        const prefix = `[${timestamp}]`;
        
        switch (type) {
            case 'success':
                console.log(chalk.green(`${prefix} ✅ ${message}`));
                break;
            case 'error':
                console.log(chalk.red(`${prefix} ❌ ${message}`));
                break;
            case 'warning':
                console.log(chalk.yellow(`${prefix} ⚠️  ${message}`));
                break;
            case 'info':
            default:
                console.log(chalk.blue(`${prefix} ℹ️  ${message}`));
                break;
        }
    }

    /**
     * 成功消息
     */
    static success(message) {
        this.log(message, 'success');
    }

    /**
     * 错误消息
     */
    static error(message) {
        this.log(message, 'error');
    }

    /**
     * 警告消息
     */
    static warn(message) {
        this.log(message, 'warning');
    }

    /**
     * 信息消息
     */
    static info(message) {
        this.log(message, 'info');
    }

    /**
     * 检查文件是否存在
     */
    static fileExists(filePath) {
        return fs.existsSync(filePath);
    }

    /**
     * 确保目录存在
     */
    static ensureDir(dirPath) {
        if (!fs.existsSync(dirPath)) {
            fs.mkdirSync(dirPath, { recursive: true });
        }
    }

    /**
     * 递归获取目录下的文件
     */
    static getFilesRecursively(dirPath, extensions = [], excludeDirs = []) {
        let files = [];
        
        if (!fs.existsSync(dirPath)) {
            return files;
        }

        const items = fs.readdirSync(dirPath);
        
        for (const item of items) {
            const fullPath = path.join(dirPath, item);
            const stat = fs.statSync(fullPath);
            
            if (stat.isDirectory()) {
                // 检查是否在排除列表中
                if (!excludeDirs.some(excludeDir => fullPath.includes(excludeDir))) {
                    files = files.concat(this.getFilesRecursively(fullPath, extensions, excludeDirs));
                }
            } else if (stat.isFile()) {
                // 检查文件扩展名
                if (extensions.length === 0 || extensions.some(ext => fullPath.endsWith(ext))) {
                    files.push(fullPath);
                }
            }
        }
        
        return files;
    }

    /**
     * 获取文件中指定行的内容
     */
    static getLineNumber(content, searchText) {
        const lines = content.split('\n');
        for (let i = 0; i < lines.length; i++) {
            if (lines[i].includes(searchText)) {
                return i + 1;
            }
        }
        return -1;
    }

    /**
     * 格式化文件大小
     */
    static formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    /**
     * 显示帮助信息
     */
    static showHelp(scriptName, commands) {
        console.log(chalk.bold(`\n📚 ${scriptName} 帮助信息\n`));
        
        Object.entries(commands).forEach(([category, cmds]) => {
            console.log(chalk.blue(`${category}:`));
            Object.entries(cmds).forEach(([cmd, desc]) => {
                console.log(`  ${chalk.cyan(cmd.padEnd(30))} ${desc}`);
            });
            console.log();
        });
    }

    /**
     * 执行命令并返回结果
     */
    static execCommand(command, options = {}) {
        const { execSync } = require('child_process');
        try {
            const result = execSync(command, {
                encoding: 'utf8',
                stdio: 'pipe',
                ...options
            });
            return { success: true, output: result.trim() };
        } catch (error) {
            return { success: false, error: error.message, output: error.stdout || '' };
        }
    }
}

module.exports = Utils;