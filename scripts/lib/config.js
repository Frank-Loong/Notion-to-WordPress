/**
 * 统一配置管理模块
 * 
 * 提供统一的配置接口，消除配置重复，
 * 所有脚本都从这里读取配置。
 * 
 * @author Frank-Loong
 * @version 2.0.0-beta.1
 */

const path = require('path');
const fs = require('fs');

class ConfigManager {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '../..');
        this.configPath = path.join(this.projectRoot, 'release.config.js');
        this._config = null;
    }

    /**
     * 获取完整配置
     */
    getConfig() {
        if (!this._config) {
            this.loadConfig();
        }
        return this._config;
    }

    /**
     * 加载配置文件
     */
    loadConfig() {
        try {
            if (!fs.existsSync(this.configPath)) {
                throw new Error('配置文件不存在: release.config.js');
            }

            // 清除缓存并重新加载
            delete require.cache[require.resolve(this.configPath)];
            const configModule = require(this.configPath);
            this._config = configModule.getConfig();
        } catch (error) {
            throw new Error(`加载配置失败: ${error.message}`);
        }
    }

    /**
     * 获取版本管理配置
     */
    getVersionConfig() {
        const config = this.getConfig();
        return config.version;
    }

    /**
     * 获取构建配置
     */
    getBuildConfig() {
        const config = this.getConfig();
        return config.build;
    }

    /**
     * 获取项目信息
     */
    getProjectInfo() {
        const config = this.getConfig();
        return config.project;
    }

    /**
     * 获取Git配置
     */
    getGitConfig() {
        const config = this.getConfig();
        return config.git;
    }

    /**
     * 获取GitHub配置
     */
    getGitHubConfig() {
        const config = this.getConfig();
        return config.github;
    }

    /**
     * 获取环境配置
     */
    getEnvironmentConfig() {
        const config = this.getConfig();
        return config.environment;
    }

    /**
     * 验证配置完整性
     */
    validateConfig() {
        const config = this.getConfig();
        const required = ['project', 'version', 'build', 'git', 'github', 'environment'];
        
        for (const key of required) {
            if (!config[key]) {
                throw new Error(`缺少必需的配置项: ${key}`);
            }
        }

        return true;
    }

    /**
     * 获取项目根目录
     */
    getProjectRoot() {
        return this.projectRoot;
    }
}

// 导出单例实例
module.exports = new ConfigManager();