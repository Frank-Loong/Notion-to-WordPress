/**
 * 设置组件 - 现代化TypeScript版本
 * 
 * 提供设置管理的完整用户界面，包括：
 * - 设置表单和验证
 * - 自动保存和手动保存
 * - 导入导出功能
 * - 连接测试
 */

import { BaseComponent, ComponentOptions } from './BaseComponent';
import { settingsManager, SettingsData } from '../managers/SettingsManager';
import { on } from '../../shared/core/EventBus';
import { showSuccess, showError } from '../../shared/utils/toast';

export interface SettingsComponentOptions extends ComponentOptions {
  enableAutoSave?: boolean;
  enableImportExport?: boolean;
  enableConnectionTest?: boolean;
  showAdvancedSettings?: boolean;
}

/**
 * 设置组件类
 */
export class SettingsComponent extends BaseComponent {
  protected options!: SettingsComponentOptions;
  
  protected defaultOptions: SettingsComponentOptions = {
    enableAutoSave: true,
    enableImportExport: true,
    enableConnectionTest: true,
    showAdvancedSettings: false
  };

  private elements: {
    container?: HTMLElement;
    form?: HTMLFormElement;
    saveButton?: HTMLButtonElement;
    resetButton?: HTMLButtonElement;
    testButton?: HTMLButtonElement;
    exportButton?: HTMLButtonElement;
    importButton?: HTMLButtonElement;
    importFile?: HTMLInputElement;
    advancedToggle?: HTMLButtonElement;
    advancedSection?: HTMLElement;
    statusIndicator?: HTMLElement;
    unsavedIndicator?: HTMLElement;
  } = {};

  private currentSettings: SettingsData | null = null;
  private fieldElements: Map<string, HTMLElement> = new Map();

  constructor(options: SettingsComponentOptions) {
    const finalOptions = {
      ...{
        enableAutoSave: true,
        enableImportExport: true,
        enableConnectionTest: true,
        showAdvancedSettings: false
      },
      ...options
    };
    super(finalOptions);
    this.options = finalOptions;
  }

  /**
   * 组件初始化回调
   */
  onInit(): void {
    this.createUI();
    this.setupSettingsManagerIntegration();
    this.loadInitialSettings();
    
    console.log('⚙️ [设置组件] 已初始化');
  }

  /**
   * 组件挂载回调
   */
  onMount(): void {
    this.setupEventListeners();
  }

  /**
   * 组件卸载回调
   */
  onUnmount(): void {
    // 清理事件监听器
  }

  /**
   * 组件销毁回调
   */
  onDestroy(): void {
    // 清理资源
  }

  /**
   * 渲染组件
   */
  onRender(): void {
    // 渲染逻辑在createUI中处理
  }

  /**
   * 绑定事件
   */
  bindEvents(): void {
    // 事件绑定逻辑在setupEventListeners中处理
  }

  /**
   * 状态变化回调
   */
  onStateChange(state: any): void {
    console.log('设置组件状态变化:', state);
  }

  /**
   * 创建UI
   */
  private createUI(): void {
    if (!this.element) return;

    this.element.className = 'notion-settings-component';
    this.element.innerHTML = `
      <div class="settings-header">
        <h2>插件设置</h2>
        <div class="settings-status">
          <span class="unsaved-indicator" style="display: none;">
            <span class="icon">●</span>
            有未保存的更改
          </span>
          <span class="status-indicator">就绪</span>
        </div>
      </div>
      
      <form class="settings-form" id="notion-settings-form">
        <!-- API配置 -->
        <div class="settings-section">
          <h3>API配置</h3>
          <div class="form-group">
            <label for="notion_api_key">Notion API密钥 *</label>
            <input type="password" id="notion_api_key" name="notion_api_key" required>
            <small class="help-text">从Notion开发者页面获取的API密钥</small>
            <div class="field-error" style="display: none;"></div>
          </div>
          
          <div class="form-group">
            <label for="notion_database_id">数据库ID *</label>
            <input type="text" id="notion_database_id" name="notion_database_id" required>
            <small class="help-text">要同步的Notion数据库ID</small>
            <div class="field-error" style="display: none;"></div>
          </div>
          
          ${this.options.enableConnectionTest ? `
            <div class="form-group">
              <button type="button" class="test-connection-button">
                <span class="icon">🔗</span>
                测试连接
              </button>
            </div>
          ` : ''}
        </div>
        
        <!-- 同步配置 -->
        <div class="settings-section">
          <h3>同步配置</h3>
          <div class="form-group">
            <label for="sync_interval">同步间隔（分钟）</label>
            <input type="number" id="sync_interval" name="sync_interval" min="1" max="1440" value="60">
            <small class="help-text">自动同步的时间间隔</small>
          </div>
          
          <div class="form-group">
            <label class="checkbox-label">
              <input type="checkbox" id="auto_sync" name="auto_sync">
              <span class="checkmark"></span>
              启用自动同步
            </label>
          </div>
          
          <div class="form-group">
            <label class="checkbox-label">
              <input type="checkbox" id="webhook_enabled" name="webhook_enabled">
              <span class="checkmark"></span>
              启用Webhook
            </label>
          </div>
          
          <div class="form-group webhook-secret-group" style="display: none;">
            <label for="webhook_secret">Webhook密钥</label>
            <input type="password" id="webhook_secret" name="webhook_secret">
            <small class="help-text">用于验证Webhook请求的密钥</small>
          </div>
        </div>
        
        <!-- 内容配置 -->
        <div class="settings-section">
          <h3>内容配置</h3>
          <div class="form-group">
            <label for="default_post_status">默认文章状态</label>
            <select id="default_post_status" name="default_post_status">
              <option value="draft">草稿</option>
              <option value="publish">发布</option>
              <option value="private">私密</option>
            </select>
          </div>
          
          <div class="form-group">
            <label for="default_post_type">默认文章类型</label>
            <select id="default_post_type" name="default_post_type">
              <option value="post">文章</option>
              <option value="page">页面</option>
            </select>
          </div>
          
          <div class="form-group">
            <label class="checkbox-label">
              <input type="checkbox" id="enable_math_rendering" name="enable_math_rendering">
              <span class="checkmark"></span>
              启用数学公式渲染
            </label>
          </div>
          
          <div class="form-group">
            <label class="checkbox-label">
              <input type="checkbox" id="enable_mermaid_diagrams" name="enable_mermaid_diagrams">
              <span class="checkmark"></span>
              启用Mermaid图表
            </label>
          </div>
        </div>
        
        <!-- 高级设置 -->
        <div class="settings-section advanced-section" style="display: none;">
          <div class="advanced-header">
            <h3>高级设置</h3>
            <button type="button" class="advanced-toggle">
              <span class="icon">▼</span>
              显示高级设置
            </button>
          </div>
          
          <div class="advanced-content" style="display: none;">
            <!-- 性能配置 -->
            <div class="form-group">
              <label for="api_page_size">API页面大小</label>
              <input type="number" id="api_page_size" name="api_page_size" min="1" max="100" value="20">
              <small class="help-text">每次API请求获取的记录数</small>
            </div>
            
            <div class="form-group">
              <label for="concurrent_requests">并发请求数</label>
              <input type="number" id="concurrent_requests" name="concurrent_requests" min="1" max="10" value="3">
              <small class="help-text">同时进行的API请求数</small>
            </div>
            
            <div class="form-group">
              <label for="batch_size">批处理大小</label>
              <input type="number" id="batch_size" name="batch_size" min="1" max="50" value="10">
              <small class="help-text">批量处理的记录数</small>
            </div>
            
            <div class="form-group">
              <label class="checkbox-label">
                <input type="checkbox" id="enable_performance_mode" name="enable_performance_mode">
                <span class="checkmark"></span>
                启用性能模式
              </label>
            </div>
            
            <!-- 缓存配置 -->
            <div class="form-group">
              <label class="checkbox-label">
                <input type="checkbox" id="enable_caching" name="enable_caching">
                <span class="checkmark"></span>
                启用缓存
              </label>
            </div>
            
            <div class="form-group cache-duration-group" style="display: none;">
              <label for="cache_duration">缓存时长（秒）</label>
              <input type="number" id="cache_duration" name="cache_duration" min="1" max="86400" value="3600">
              <small class="help-text">缓存数据的有效时间</small>
            </div>
            
            <!-- 安全配置 -->
            <div class="form-group">
              <label class="checkbox-label">
                <input type="checkbox" id="delete_protection" name="delete_protection">
                <span class="checkmark"></span>
                删除保护
              </label>
            </div>
            
            <div class="form-group">
              <label class="checkbox-label">
                <input type="checkbox" id="image_optimization" name="image_optimization">
                <span class="checkmark"></span>
                图片优化
              </label>
            </div>
            
            <!-- 调试配置 -->
            <div class="form-group">
              <label class="checkbox-label">
                <input type="checkbox" id="debug_mode" name="debug_mode">
                <span class="checkmark"></span>
                调试模式
              </label>
            </div>
            
            <div class="form-group debug-level-group" style="display: none;">
              <label for="log_level">日志级别</label>
              <select id="log_level" name="log_level">
                <option value="error">错误</option>
                <option value="warning">警告</option>
                <option value="info">信息</option>
                <option value="debug">调试</option>
              </select>
            </div>
          </div>
        </div>
        
        <!-- 操作按钮 -->
        <div class="settings-actions">
          <div class="actions-left">
            <button type="submit" class="save-button primary">
              <span class="icon">💾</span>
              保存设置
            </button>
            
            <button type="button" class="reset-button">
              <span class="icon">🔄</span>
              重置
            </button>
          </div>
          
          ${this.options.enableImportExport ? `
            <div class="actions-right">
              <button type="button" class="export-button">
                <span class="icon">📤</span>
                导出设置
              </button>
              
              <button type="button" class="import-button">
                <span class="icon">📥</span>
                导入设置
              </button>
              
              <input type="file" class="import-file" accept=".json" style="display: none;">
            </div>
          ` : ''}
        </div>
      </form>
    `;

    this.bindElements();
  }

  /**
   * 绑定DOM元素
   */
  private bindElements(): void {
    if (!this.element) return;

    this.elements = {
      container: this.element,
      form: this.element.querySelector('.settings-form') as HTMLFormElement,
      saveButton: this.element.querySelector('.save-button') as HTMLButtonElement,
      resetButton: this.element.querySelector('.reset-button') as HTMLButtonElement,
      testButton: this.element.querySelector('.test-connection-button') as HTMLButtonElement,
      exportButton: this.element.querySelector('.export-button') as HTMLButtonElement,
      importButton: this.element.querySelector('.import-button') as HTMLButtonElement,
      importFile: this.element.querySelector('.import-file') as HTMLInputElement,
      advancedToggle: this.element.querySelector('.advanced-toggle') as HTMLButtonElement,
      advancedSection: this.element.querySelector('.advanced-content') as HTMLElement,
      statusIndicator: this.element.querySelector('.status-indicator') as HTMLElement,
      unsavedIndicator: this.element.querySelector('.unsaved-indicator') as HTMLElement
    };

    // 收集所有表单字段
    this.collectFormFields();
  }

  /**
   * 收集表单字段
   */
  private collectFormFields(): void {
    if (!this.elements.form) return;

    const fields = this.elements.form.querySelectorAll('input, select, textarea');
    fields.forEach(field => {
      const element = field as HTMLElement;
      const name = element.getAttribute('name');
      if (name) {
        this.fieldElements.set(name, element);
      }
    });
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // 表单提交
    if (this.elements.form) {
      this.elements.form.addEventListener('submit', (e) => {
        e.preventDefault();
        this.handleSave();
      });
    }

    // 重置按钮
    if (this.elements.resetButton) {
      this.elements.resetButton.addEventListener('click', () => {
        this.handleReset();
      });
    }

    // 测试连接按钮
    if (this.elements.testButton) {
      this.elements.testButton.addEventListener('click', () => {
        this.handleTestConnection();
      });
    }

    // 导出按钮
    if (this.elements.exportButton) {
      this.elements.exportButton.addEventListener('click', () => {
        this.handleExport();
      });
    }

    // 导入按钮
    if (this.elements.importButton) {
      this.elements.importButton.addEventListener('click', () => {
        this.elements.importFile?.click();
      });
    }

    // 导入文件
    if (this.elements.importFile) {
      this.elements.importFile.addEventListener('change', (e) => {
        const target = e.target as HTMLInputElement;
        if (target.files && target.files[0]) {
          this.handleImport(target.files[0]);
        }
      });
    }

    // 高级设置切换
    if (this.elements.advancedToggle) {
      this.elements.advancedToggle.addEventListener('click', () => {
        this.toggleAdvancedSettings();
      });
    }

    // 表单字段变化监听
    this.setupFieldListeners();

    // 条件显示逻辑
    this.setupConditionalDisplay();
  }

  /**
   * 设置字段监听器
   */
  private setupFieldListeners(): void {
    this.fieldElements.forEach((element, name) => {
      element.addEventListener('input', () => {
        this.handleFieldChange(name, this.getFieldValue(name));
      });

      element.addEventListener('change', () => {
        this.handleFieldChange(name, this.getFieldValue(name));
      });
    });
  }

  /**
   * 设置条件显示逻辑
   */
  private setupConditionalDisplay(): void {
    // Webhook密钥显示/隐藏
    const webhookEnabled = this.fieldElements.get('webhook_enabled') as HTMLInputElement;
    const webhookSecretGroup = this.element?.querySelector('.webhook-secret-group') as HTMLElement;
    
    if (webhookEnabled && webhookSecretGroup) {
      const toggleWebhookSecret = () => {
        webhookSecretGroup.style.display = webhookEnabled.checked ? 'block' : 'none';
      };
      
      webhookEnabled.addEventListener('change', toggleWebhookSecret);
      toggleWebhookSecret(); // 初始状态
    }

    // 缓存时长显示/隐藏
    const cacheEnabled = this.fieldElements.get('enable_caching') as HTMLInputElement;
    const cacheDurationGroup = this.element?.querySelector('.cache-duration-group') as HTMLElement;
    
    if (cacheEnabled && cacheDurationGroup) {
      const toggleCacheDuration = () => {
        cacheDurationGroup.style.display = cacheEnabled.checked ? 'block' : 'none';
      };
      
      cacheEnabled.addEventListener('change', toggleCacheDuration);
      toggleCacheDuration(); // 初始状态
    }

    // 调试级别显示/隐藏
    const debugMode = this.fieldElements.get('debug_mode') as HTMLInputElement;
    const debugLevelGroup = this.element?.querySelector('.debug-level-group') as HTMLElement;
    
    if (debugMode && debugLevelGroup) {
      const toggleDebugLevel = () => {
        debugLevelGroup.style.display = debugMode.checked ? 'block' : 'none';
      };
      
      debugMode.addEventListener('change', toggleDebugLevel);
      toggleDebugLevel(); // 初始状态
    }
  }

  /**
   * 设置设置管理器集成
   */
  private setupSettingsManagerIntegration(): void {
    // 监听设置管理器事件
    on('settings:loaded', (_event, data) => {
      this.handleSettingsLoaded(data.settings);
    });

    on('settings:saved', (_event, data) => {
      this.handleSettingsSaved(data.settings);
    });

    on('settings:reset', (_event, data) => {
      this.handleSettingsReset(data.settings);
    });

    on('settings:changed', (_event, data) => {
      this.handleSettingChanged(data.key, data.value);
    });

    on('settings:loading:changed', (_event, data) => {
      this.setLoading(data.loading);
    });

    on('settings:saving:changed', (_event, data) => {
      this.setSaving(data.saving);
    });

    on('settings:validation:error', (_event, data) => {
      this.showFieldError(data.field, data.errors.join(', '));
    });

    on('settings:connection:success', () => {
      showSuccess('连接测试成功');
    });

    on('settings:connection:failed', (_event, data) => {
      showError(`连接测试失败: ${data.message}`);
    });
  }

  /**
   * 加载初始设置
   */
  private async loadInitialSettings(): Promise<void> {
    try {
      const settings = await settingsManager.loadSettings();
      this.populateForm(settings);
    } catch (error) {
      console.error('加载初始设置失败:', error);
    }
  }

  /**
   * 填充表单
   */
  private populateForm(settings: SettingsData): void {
    this.currentSettings = settings;

    Object.entries(settings).forEach(([key, value]) => {
      this.setFieldValue(key, value);
    });

    this.clearAllFieldErrors();
    this.updateUnsavedIndicator(false);
  }

  /**
   * 获取字段值
   */
  private getFieldValue(name: string): any {
    const element = this.fieldElements.get(name);
    if (!element) return undefined;

    if (element instanceof HTMLInputElement) {
      if (element.type === 'checkbox') {
        return element.checked;
      } else if (element.type === 'number') {
        return parseInt(element.value) || 0;
      } else {
        return element.value;
      }
    } else if (element instanceof HTMLSelectElement) {
      return element.value;
    } else if (element instanceof HTMLTextAreaElement) {
      return element.value;
    }

    return undefined;
  }

  /**
   * 设置字段值
   */
  private setFieldValue(name: string, value: any): void {
    const element = this.fieldElements.get(name);
    if (!element) return;

    if (element instanceof HTMLInputElement) {
      if (element.type === 'checkbox') {
        element.checked = Boolean(value);
      } else {
        element.value = String(value);
      }
    } else if (element instanceof HTMLSelectElement) {
      element.value = String(value);
    } else if (element instanceof HTMLTextAreaElement) {
      element.value = String(value);
    }
  }



  /**
   * 处理保存
   */
  private async handleSave(): Promise<void> {
    try {
      await settingsManager.saveSettings();
    } catch (error) {
      console.error('保存设置失败:', error);
    }
  }

  /**
   * 处理重置
   */
  private async handleReset(): Promise<void> {
    if (!confirm('确定要重置所有设置吗？此操作不可撤销。')) {
      return;
    }

    try {
      await settingsManager.resetSettings();
    } catch (error) {
      console.error('重置设置失败:', error);
    }
  }

  /**
   * 处理测试连接
   */
  private async handleTestConnection(): Promise<void> {
    try {
      await settingsManager.testConnection();
    } catch (error) {
      console.error('测试连接失败:', error);
    }
  }

  /**
   * 处理导出
   */
  private handleExport(): void {
    settingsManager.exportSettings();
  }

  /**
   * 处理导入
   */
  private async handleImport(file: File): Promise<void> {
    try {
      await settingsManager.importSettings(file);
      // 重新填充表单
      const settings = settingsManager.getCurrentSettings();
      if (settings) {
        this.populateForm(settings);
      }
    } catch (error) {
      console.error('导入设置失败:', error);
    }
  }

  /**
   * 切换高级设置
   */
  private toggleAdvancedSettings(): void {
    if (!this.elements.advancedSection || !this.elements.advancedToggle) return;

    const isVisible = this.elements.advancedSection.style.display !== 'none';
    
    this.elements.advancedSection.style.display = isVisible ? 'none' : 'block';
    
    const icon = this.elements.advancedToggle.querySelector('.icon');
    if (icon) {
      icon.textContent = isVisible ? '▼' : '▲';
    }
    
    const text = this.elements.advancedToggle.childNodes[1];
    if (text) {
      text.textContent = isVisible ? '显示高级设置' : '隐藏高级设置';
    }
  }

  /**
   * 处理字段变化
   */
  private handleFieldChange(name: string, value: any): void {
    settingsManager.updateSetting(name as keyof SettingsData, value);
    this.clearFieldError(name);
  }

  /**
   * 处理设置加载完成
   */
  private handleSettingsLoaded(settings: SettingsData): void {
    this.populateForm(settings);
  }

  /**
   * 处理设置保存完成
   */
  private handleSettingsSaved(settings: SettingsData): void {
    this.currentSettings = settings;
    this.updateUnsavedIndicator(false);
  }

  /**
   * 处理设置重置完成
   */
  private handleSettingsReset(settings: SettingsData): void {
    this.populateForm(settings);
  }

  /**
   * 处理单个设置变化
   */
  private handleSettingChanged(_key: string, _value: any): void {
    this.updateUnsavedIndicator(true);
  }

  /**
   * 显示字段错误
   */
  private showFieldError(field: string, message: string): void {
    const element = this.fieldElements.get(field);
    if (!element) return;

    const errorElement = element.parentElement?.querySelector('.field-error') as HTMLElement;
    if (errorElement) {
      errorElement.textContent = message;
      errorElement.style.display = 'block';
    }

    element.classList.add('error');
  }

  /**
   * 清除字段错误
   */
  private clearFieldError(field: string): void {
    const element = this.fieldElements.get(field);
    if (!element) return;

    const errorElement = element.parentElement?.querySelector('.field-error') as HTMLElement;
    if (errorElement) {
      errorElement.style.display = 'none';
    }

    element.classList.remove('error');
  }

  /**
   * 清除所有字段错误
   */
  private clearAllFieldErrors(): void {
    this.fieldElements.forEach((_element, name) => {
      this.clearFieldError(name);
    });
  }

  /**
   * 设置加载状态
   */
  private setLoading(loading: boolean): void {
    if (this.elements.statusIndicator) {
      this.elements.statusIndicator.textContent = loading ? '加载中...' : '就绪';
    }

    // 禁用/启用表单
    if (this.elements.form) {
      const formElements = this.elements.form.querySelectorAll('input, select, textarea, button');
      formElements.forEach(element => {
        (element as HTMLElement).style.pointerEvents = loading ? 'none' : '';
        (element as HTMLElement).style.opacity = loading ? '0.6' : '';
      });
    }
  }

  /**
   * 设置保存状态
   */
  private setSaving(saving: boolean): void {
    if (this.elements.saveButton) {
      this.elements.saveButton.disabled = saving;
      this.elements.saveButton.textContent = saving ? '保存中...' : '保存设置';
    }

    if (this.elements.statusIndicator) {
      this.elements.statusIndicator.textContent = saving ? '保存中...' : '就绪';
    }
  }

  /**
   * 更新未保存指示器
   */
  private updateUnsavedIndicator(hasChanges: boolean): void {
    if (this.elements.unsavedIndicator) {
      this.elements.unsavedIndicator.style.display = hasChanges ? 'inline-flex' : 'none';
    }
  }

  /**
   * 获取当前设置
   */
  getCurrentSettings(): SettingsData | null {
    return this.currentSettings;
  }

  /**
   * 销毁组件
   */
  destroy(): void {
    super.destroy();
    console.log('🗑️ 设置组件已销毁');
  }
}

export default SettingsComponent;
