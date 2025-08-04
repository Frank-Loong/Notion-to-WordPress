/**
 * 设置管理器 - 现代化TypeScript版本
 * 
 * 从原有Settings.ts和admin-interactions.js的设置功能完全迁移，包括：
 * - 设置的加载、保存、验证
 * - 自动保存和表单验证
 * - 设置导入导出
 * - 连接测试和重置
 */

import { emit } from '../../shared/core/EventBus';
import { post } from '../../shared/utils/ajax';
import { showSuccess, showError, showInfo } from '../../shared/utils/toast';
import { debounce } from '../../shared/utils/dom';

export interface SettingsData {
  // API配置
  notion_api_key: string;
  notion_database_id: string;
  
  // 同步配置
  sync_interval: number;
  auto_sync: boolean;
  webhook_enabled: boolean;
  webhook_secret: string;
  
  // 内容配置
  default_post_status: string;
  default_post_type: string;
  enable_math_rendering: boolean;
  enable_mermaid_diagrams: boolean;
  
  // 性能配置
  api_page_size: number;
  concurrent_requests: number;
  batch_size: number;
  enable_performance_mode: boolean;
  
  // 缓存配置
  enable_caching: boolean;
  cache_duration: number;
  
  // 安全配置
  delete_protection: boolean;
  image_optimization: boolean;
  
  // 调试配置
  debug_mode: boolean;
  log_level: string;
}

export interface SettingsValidationRule {
  field: keyof SettingsData;
  type: 'required' | 'email' | 'url' | 'number' | 'custom';
  message?: string;
  validator?: (value: any) => boolean | string;
  min?: number;
  max?: number;
}

export interface SettingsManagerOptions {
  autoSave?: boolean;
  autoSaveDelay?: number;
  validateOnChange?: boolean;
  enableImportExport?: boolean;
  enableConnectionTest?: boolean;
}

/**
 * 设置管理器类
 */
export class SettingsManager {
  private static instance: SettingsManager | null = null;
  
  private options!: Required<SettingsManagerOptions>;
  private settings: SettingsData | null = null;
  private validationRules: SettingsValidationRule[] = [];
  private autoSaveTimer: NodeJS.Timeout | null = null;
  private isLoading = false;
  private isSaving = false;
  private hasUnsavedChanges = false;

  constructor(options: SettingsManagerOptions = {}) {
    if (SettingsManager.instance) {
      return SettingsManager.instance;
    }
    
    SettingsManager.instance = this;
    
    this.options = {
      autoSave: true,
      autoSaveDelay: 3000,
      validateOnChange: true,
      enableImportExport: true,
      enableConnectionTest: true,
      ...options
    };
    
    this.init();
  }

  /**
   * 获取单例实例
   */
  static getInstance(options?: SettingsManagerOptions): SettingsManager {
    if (!SettingsManager.instance) {
      SettingsManager.instance = new SettingsManager(options);
    }
    return SettingsManager.instance;
  }

  /**
   * 初始化管理器
   */
  private init(): void {
    this.setupValidationRules();
    this.setupEventListeners();
    this.setupAutoSave();
    
    console.log('⚙️ [设置管理器] 已初始化');
    emit('settings:manager:initialized');
  }

  /**
   * 设置验证规则
   */
  private setupValidationRules(): void {
    this.validationRules = [
      {
        field: 'notion_api_key',
        type: 'required',
        message: 'Notion API密钥不能为空'
      },
      {
        field: 'notion_api_key',
        type: 'custom',
        message: 'API密钥格式不正确',
        validator: (value: string) => {
          if (!value) return true; // 空值由required规则处理
          return /^[a-zA-Z0-9_-]{30,80}$/.test(value.trim());
        }
      },
      {
        field: 'notion_database_id',
        type: 'required',
        message: '数据库ID不能为空'
      },
      {
        field: 'notion_database_id',
        type: 'custom',
        message: '数据库ID格式不正确',
        validator: (value: string) => {
          if (!value) return true;
          return /^[a-f0-9]{32}$/.test(value.replace(/-/g, ''));
        }
      },
      {
        field: 'sync_interval',
        type: 'number',
        message: '同步间隔必须是正整数',
        min: 1,
        max: 1440
      },
      {
        field: 'api_page_size',
        type: 'number',
        message: 'API页面大小必须在1-100之间',
        min: 1,
        max: 100
      },
      {
        field: 'concurrent_requests',
        type: 'number',
        message: '并发请求数必须在1-10之间',
        min: 1,
        max: 10
      },
      {
        field: 'batch_size',
        type: 'number',
        message: '批处理大小必须在1-50之间',
        min: 1,
        max: 50
      },
      {
        field: 'cache_duration',
        type: 'number',
        message: '缓存时长必须是正整数',
        min: 1,
        max: 86400
      }
    ];
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // 监听页面卸载事件
    window.addEventListener('beforeunload', (e) => {
      if (this.hasUnsavedChanges) {
        e.preventDefault();
        e.returnValue = '您有未保存的设置更改，确定要离开吗？';
        return e.returnValue;
      }
    });

    // 监听键盘快捷键
    document.addEventListener('keydown', (e) => {
      // Ctrl/Cmd + S 保存设置
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        this.saveSettings();
      }
    });
  }

  /**
   * 设置自动保存
   */
  private setupAutoSave(): void {
    if (!this.options.autoSave) return;

    this.debouncedAutoSave = debounce(() => {
      if (this.hasUnsavedChanges && this.settings) {
        this.saveSettings(true);
      }
    }, this.options.autoSaveDelay);
  }

  private debouncedAutoSave?: () => void;

  /**
   * 加载设置
   */
  async loadSettings(): Promise<SettingsData> {
    this.setLoading(true);

    try {
      const response = await post('notion_to_wordpress_get_settings', {});
      
      if (response && response.data) {
        this.settings = this.normalizeSettings(response.data);
        this.hasUnsavedChanges = false;
        
        emit('settings:loaded', { settings: this.settings });
        return this.settings;
      } else {
        throw new Error('获取设置失败');
      }
    } catch (error) {
      console.error('加载设置失败:', error);
      showError(`加载设置失败: ${(error as Error).message}`);
      throw error;
    } finally {
      this.setLoading(false);
    }
  }

  /**
   * 标准化设置数据
   */
  private normalizeSettings(data: any): SettingsData {
    return {
      // API配置
      notion_api_key: data.notion_api_key || '',
      notion_database_id: data.notion_database_id || '',
      
      // 同步配置
      sync_interval: parseInt(data.sync_interval) || 60,
      auto_sync: Boolean(data.auto_sync),
      webhook_enabled: Boolean(data.webhook_enabled),
      webhook_secret: data.webhook_secret || '',
      
      // 内容配置
      default_post_status: data.default_post_status || 'draft',
      default_post_type: data.default_post_type || 'post',
      enable_math_rendering: Boolean(data.enable_math_rendering),
      enable_mermaid_diagrams: Boolean(data.enable_mermaid_diagrams),
      
      // 性能配置
      api_page_size: parseInt(data.api_page_size) || 20,
      concurrent_requests: parseInt(data.concurrent_requests) || 3,
      batch_size: parseInt(data.batch_size) || 10,
      enable_performance_mode: Boolean(data.enable_performance_mode),
      
      // 缓存配置
      enable_caching: Boolean(data.enable_caching),
      cache_duration: parseInt(data.cache_duration) || 3600,
      
      // 安全配置
      delete_protection: Boolean(data.delete_protection),
      image_optimization: Boolean(data.image_optimization),
      
      // 调试配置
      debug_mode: Boolean(data.debug_mode),
      log_level: data.log_level || 'info'
    };
  }

  /**
   * 保存设置
   */
  async saveSettings(silent = false): Promise<void> {
    if (!this.settings) {
      throw new Error('没有可保存的设置');
    }

    // 验证设置
    const validation = this.validateSettings(this.settings);
    if (!validation.isValid) {
      const errorMessage = validation.errors.join(', ');
      if (!silent) {
        showError(`设置验证失败: ${errorMessage}`);
      }
      throw new Error(errorMessage);
    }

    this.setSaving(true);

    try {
      const response = await post('notion_to_wordpress_save_settings', this.settings);
      
      if (response && response.data) {
        this.hasUnsavedChanges = false;
        
        if (!silent) {
          showSuccess('设置保存成功');
        }
        
        emit('settings:saved', { settings: this.settings });
        return;
      } else {
        throw new Error('保存设置失败');
      }
    } catch (error) {
      console.error('保存设置失败:', error);
      if (!silent) {
        showError(`保存设置失败: ${(error as Error).message}`);
      }
      throw error;
    } finally {
      this.setSaving(false);
    }
  }

  /**
   * 验证设置
   */
  validateSettings(settings: SettingsData): { isValid: boolean; errors: string[]; warnings: string[] } {
    const errors: string[] = [];
    const warnings: string[] = [];

    this.validationRules.forEach(rule => {
      const value = settings[rule.field];
      let isValid = true;
      let errorMessage = rule.message || `${rule.field} 验证失败`;

      switch (rule.type) {
        case 'required':
          isValid = value !== null && value !== undefined && value !== '';
          break;

        case 'number':
          const numValue = Number(value);
          isValid = !isNaN(numValue);
          if (isValid && rule.min !== undefined) {
            isValid = numValue >= rule.min;
          }
          if (isValid && rule.max !== undefined) {
            isValid = numValue <= rule.max;
          }
          break;

        case 'custom':
          if (rule.validator) {
            const result = rule.validator(value);
            if (typeof result === 'boolean') {
              isValid = result;
            } else {
              isValid = false;
              errorMessage = result;
            }
          }
          break;
      }

      if (!isValid) {
        errors.push(errorMessage);
      }
    });

    // 添加警告检查
    if (settings.debug_mode) {
      warnings.push('调试模式已启用，可能影响性能');
    }

    if (settings.concurrent_requests > 5) {
      warnings.push('并发请求数较高，可能触发API限制');
    }

    return {
      isValid: errors.length === 0,
      errors,
      warnings
    };
  }

  /**
   * 重置设置
   */
  async resetSettings(): Promise<void> {
    try {
      const response = await post('notion_to_wordpress_reset_settings', {});
      
      if (response && response.data) {
        this.settings = this.normalizeSettings(response.data);
        this.hasUnsavedChanges = false;
        
        emit('settings:reset', { settings: this.settings });
        showSuccess('设置已重置为默认值');
        return;
      } else {
        throw new Error('重置设置失败');
      }
    } catch (error) {
      console.error('重置设置失败:', error);
      showError(`重置设置失败: ${(error as Error).message}`);
      throw error;
    }
  }

  /**
   * 测试连接
   */
  async testConnection(): Promise<boolean> {
    if (!this.settings?.notion_api_key || !this.settings?.notion_database_id) {
      showError('请先配置API密钥和数据库ID');
      return false;
    }

    try {
      const response = await post('notion_to_wordpress_test_connection', {
        api_key: this.settings.notion_api_key,
        database_id: this.settings.notion_database_id
      });
      
      if (response && response.data && response.data.success) {
        showSuccess('连接测试成功');
        emit('settings:connection:success');
        return true;
      } else {
        const message = response?.data?.message || '连接测试失败';
        showError(`连接测试失败: ${message}`);
        emit('settings:connection:failed', { message });
        return false;
      }
    } catch (error) {
      console.error('连接测试失败:', error);
      const message = (error as Error).message;
      showError(`连接测试失败: ${message}`);
      emit('settings:connection:failed', { message });
      return false;
    }
  }

  /**
   * 导出设置
   */
  exportSettings(): void {
    if (!this.settings) {
      showError('没有可导出的设置');
      return;
    }

    try {
      // 创建导出数据（移除敏感信息）
      const exportData = {
        ...this.settings,
        notion_api_key: '', // 不导出API密钥
        webhook_secret: '', // 不导出Webhook密钥
        exported_at: new Date().toISOString(),
        version: '2.0.0'
      };

      const content = JSON.stringify(exportData, null, 2);
      const filename = `notion-wp-settings-${new Date().toISOString().split('T')[0]}.json`;
      
      this.downloadFile(content, filename, 'application/json');
      
      emit('settings:exported', { filename });
      showSuccess('设置已导出');
    } catch (error) {
      console.error('导出设置失败:', error);
      showError(`导出设置失败: ${(error as Error).message}`);
    }
  }

  /**
   * 导入设置
   */
  async importSettings(file: File): Promise<void> {
    try {
      const content = await this.readFileContent(file);
      const importData = JSON.parse(content);
      
      // 验证导入数据
      if (!this.validateImportData(importData)) {
        throw new Error('导入文件格式不正确');
      }

      // 合并设置（保留当前的敏感信息）
      const mergedSettings = {
        ...importData,
        notion_api_key: this.settings?.notion_api_key || '',
        webhook_secret: this.settings?.webhook_secret || ''
      };

      // 标准化并验证
      this.settings = this.normalizeSettings(mergedSettings);
      const validation = this.validateSettings(this.settings);
      
      if (!validation.isValid) {
        throw new Error(`导入的设置无效: ${validation.errors.join(', ')}`);
      }

      this.hasUnsavedChanges = true;
      
      emit('settings:imported', { settings: this.settings });
      showSuccess('设置已导入，请检查并保存');
      
      if (validation.warnings.length > 0) {
        showInfo(`警告: ${validation.warnings.join(', ')}`);
      }
    } catch (error) {
      console.error('导入设置失败:', error);
      showError(`导入设置失败: ${(error as Error).message}`);
      throw error;
    }
  }

  /**
   * 验证导入数据
   */
  private validateImportData(data: any): boolean {
    if (!data || typeof data !== 'object') {
      return false;
    }

    // 检查必要字段
    const requiredFields = ['sync_interval', 'default_post_status'];
    return requiredFields.every(field => field in data);
  }

  /**
   * 读取文件内容
   */
  private readFileContent(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = (e) => resolve(e.target?.result as string);
      reader.onerror = () => reject(new Error('文件读取失败'));
      reader.readAsText(file);
    });
  }

  /**
   * 下载文件
   */
  private downloadFile(content: string, filename: string, mimeType: string): void {
    const blob = new Blob([content], { type: mimeType });
    const url = URL.createObjectURL(blob);
    
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.style.display = 'none';
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    URL.revokeObjectURL(url);
  }

  /**
   * 更新单个设置
   */
  updateSetting<K extends keyof SettingsData>(key: K, value: SettingsData[K]): void {
    if (!this.settings) return;

    const oldValue = this.settings[key];
    this.settings[key] = value;
    
    // 检查是否有变化
    if (oldValue !== value) {
      this.hasUnsavedChanges = true;
      
      // 验证单个字段
      if (this.options.validateOnChange) {
        const fieldRules = this.validationRules.filter(rule => rule.field === key);
        const tempSettings = { ...this.settings };
        const validation = this.validateSettings(tempSettings);
        
        if (!validation.isValid) {
          const fieldErrors = validation.errors.filter(error => 
            fieldRules.some(rule => error.includes(rule.message || ''))
          );
          
          if (fieldErrors.length > 0) {
            emit('settings:validation:error', { field: key, errors: fieldErrors });
          }
        }
      }
      
      emit('settings:changed', { key, value, oldValue });
      
      // 触发自动保存
      if (this.options.autoSave && this.debouncedAutoSave) {
        this.debouncedAutoSave();
      }
    }
  }

  /**
   * 设置加载状态
   */
  private setLoading(loading: boolean): void {
    this.isLoading = loading;
    emit('settings:loading:changed', { loading });
  }

  /**
   * 设置保存状态
   */
  private setSaving(saving: boolean): void {
    this.isSaving = saving;
    emit('settings:saving:changed', { saving });
  }

  /**
   * 获取当前设置
   */
  getCurrentSettings(): SettingsData | null {
    return this.settings ? { ...this.settings } : null;
  }

  /**
   * 检查是否有未保存的更改
   */
  hasChanges(): boolean {
    return this.hasUnsavedChanges;
  }

  /**
   * 检查是否正在加载
   */
  isLoadingSettings(): boolean {
    return this.isLoading;
  }

  /**
   * 检查是否正在保存
   */
  isSavingSettings(): boolean {
    return this.isSaving;
  }

  /**
   * 获取配置选项
   */
  getOptions(): Required<SettingsManagerOptions> {
    return { ...this.options };
  }

  /**
   * 更新配置选项
   */
  updateOptions(options: Partial<SettingsManagerOptions>): void {
    this.options = { ...this.options, ...options };
    emit('settings:options:updated', this.options);
  }

  /**
   * 销毁管理器
   */
  destroy(): void {
    // 清理自动保存定时器
    if (this.autoSaveTimer) {
      clearTimeout(this.autoSaveTimer);
    }

    // 清理事件监听器
    window.removeEventListener('beforeunload', () => {});
    document.removeEventListener('keydown', () => {});
    
    SettingsManager.instance = null;
    emit('settings:manager:destroyed');
    console.log('⚙️ [设置管理器] 已销毁');
  }
}

// 导出单例实例
export const settingsManager = SettingsManager.getInstance();

export default SettingsManager;
