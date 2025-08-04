/**
 * Webhook管理器 - 现代化TypeScript版本
 * 
 * 从原有admin-interactions.js的webhook功能完全迁移，包括：
 * - Webhook配置管理
 * - 令牌生成和验证
 * - 测试功能
 * - 状态监控
 */

import { emit, on } from '../../shared/core/EventBus';
import { post } from '../../shared/utils/ajax';
import { showSuccess, showError, showInfo } from '../../shared/utils/toast';
import { WebhookValidator, WebhookConfig, WebhookValidationResult } from '../utils/WebhookValidator';
import { WebhookTester, WebhookTestResult } from '../utils/WebhookTester';

export interface WebhookManagerOptions {
  autoValidate?: boolean;
  autoTest?: boolean;
  refreshInterval?: number;
}

export interface WebhookStatus {
  enabled: boolean;
  configured: boolean;
  tested: boolean;
  lastTest?: Date;
  lastTestResult?: WebhookTestResult;
  issues: string[];
}

/**
 * Webhook管理器类
 */
export class WebhookManager {
  private static instance: WebhookManager | null = null;
  
  private options!: Required<WebhookManagerOptions>;
  private status!: WebhookStatus;
  private refreshTimer: NodeJS.Timeout | null = null;
  private elements: {
    enabledCheckbox?: HTMLInputElement;
    tokenInput?: HTMLInputElement;
    urlInput?: HTMLInputElement;
    verificationTokenInput?: HTMLInputElement;
    generateTokenButton?: HTMLButtonElement;
    testWebhookButton?: HTMLButtonElement;
    refreshTokenButton?: HTMLButtonElement;
    copyUrlButton?: HTMLButtonElement;
    statusIndicator?: HTMLElement;
    settingsContainer?: HTMLElement;
  } = {};

  constructor(options: WebhookManagerOptions = {}) {
    if (WebhookManager.instance) {
      return WebhookManager.instance;
    }
    
    WebhookManager.instance = this;
    
    this.options = {
      autoValidate: true,
      autoTest: false,
      refreshInterval: 30000, // 30秒
      ...options
    };
    
    this.status = {
      enabled: false,
      configured: false,
      tested: false,
      issues: []
    };
    
    this.init();
  }

  /**
   * 获取单例实例
   */
  static getInstance(options?: WebhookManagerOptions): WebhookManager {
    if (!WebhookManager.instance) {
      WebhookManager.instance = new WebhookManager(options);
    }
    return WebhookManager.instance;
  }

  /**
   * 初始化Webhook管理器
   */
  private init(): void {
    this.bindElements();
    this.setupEventListeners();
    this.loadCurrentConfig();
    
    if (this.options.refreshInterval > 0) {
      this.startStatusRefresh();
    }
    
    console.log('✅ Webhook管理器已初始化');
    emit('webhook:manager:initialized');
  }

  /**
   * 绑定DOM元素
   */
  private bindElements(): void {
    this.elements = {
      enabledCheckbox: document.getElementById('webhook_enabled') as HTMLInputElement,
      tokenInput: document.getElementById('webhook_token') as HTMLInputElement,
      urlInput: document.getElementById('webhook_url') as HTMLInputElement,
      verificationTokenInput: document.getElementById('verification_token') as HTMLInputElement,
      generateTokenButton: document.getElementById('generate-webhook-token') as HTMLButtonElement,
      testWebhookButton: document.getElementById('test-webhook') as HTMLButtonElement,
      refreshTokenButton: document.getElementById('refresh-verification-token') as HTMLButtonElement,
      copyUrlButton: document.querySelector('.copy-webhook-url') as HTMLButtonElement,
      statusIndicator: document.getElementById('webhook-status') as HTMLElement,
      settingsContainer: document.getElementById('webhook-settings') as HTMLElement
    };
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // Webhook启用/禁用
    if (this.elements.enabledCheckbox) {
      this.elements.enabledCheckbox.addEventListener('change', (e) => {
        const enabled = (e.target as HTMLInputElement).checked;
        this.handleWebhookToggle(enabled);
      });
    }

    // 生成令牌
    if (this.elements.generateTokenButton) {
      this.elements.generateTokenButton.addEventListener('click', () => {
        this.generateNewToken();
      });
    }

    // 测试Webhook
    if (this.elements.testWebhookButton) {
      this.elements.testWebhookButton.addEventListener('click', () => {
        this.testWebhook();
      });
    }

    // 刷新验证令牌
    if (this.elements.refreshTokenButton) {
      this.elements.refreshTokenButton.addEventListener('click', () => {
        this.refreshVerificationToken();
      });
    }

    // 复制URL
    if (this.elements.copyUrlButton) {
      this.elements.copyUrlButton.addEventListener('click', () => {
        this.copyWebhookUrl();
      });
    }

    // 令牌输入验证
    if (this.elements.tokenInput) {
      this.elements.tokenInput.addEventListener('input', () => {
        if (this.options.autoValidate) {
          this.validateCurrentConfig();
        }
      });
    }

    // 监听表单提交事件
    on('form:settings:submit', () => {
      this.handleSettingsSave();
    });
  }

  /**
   * 加载当前配置
   */
  private async loadCurrentConfig(): Promise<void> {
    try {
      const response = await post('notion_to_wordpress_get_webhook_config', {});
      
      if (response && response.data) {
        const config = response.data;
        this.updateStatus({
          enabled: config.enabled || false,
          configured: !!(config.token && config.url)
        });
        
        this.updateUI(config);
      }
    } catch (error) {
      console.error('加载Webhook配置失败:', error);
      showError('加载Webhook配置失败');
    }
  }

  /**
   * 处理Webhook启用/禁用
   */
  private handleWebhookToggle(enabled: boolean): void {
    this.updateStatus({ enabled });
    
    // 显示/隐藏设置区域
    if (this.elements.settingsContainer) {
      if (enabled) {
        this.elements.settingsContainer.classList.remove('notion-wp-hidden');
        this.elements.settingsContainer.style.display = 'block';
      } else {
        this.elements.settingsContainer.classList.add('notion-wp-hidden');
        this.elements.settingsContainer.style.display = 'none';
      }
    }
    
    // 如果启用且未配置，自动生成令牌
    if (enabled && !this.status.configured) {
      this.generateNewToken();
    }
    
    emit('webhook:toggled', { enabled });
  }

  /**
   * 生成新的Webhook令牌
   */
  private generateNewToken(): void {
    const newToken = WebhookValidator.generateSecureToken(32);
    
    if (this.elements.tokenInput) {
      this.elements.tokenInput.value = newToken;
    }
    
    // 更新URL
    this.updateWebhookUrl(newToken);
    
    // 验证新令牌
    if (this.options.autoValidate) {
      this.validateCurrentConfig();
    }
    
    showSuccess('已生成新的Webhook令牌');
    emit('webhook:token:generated', { token: newToken });
  }

  /**
   * 更新Webhook URL
   */
  private updateWebhookUrl(token: string): void {
    const baseUrl = (window as any).location.origin;
    const webhookUrl = WebhookValidator.buildWebhookUrl(baseUrl, token);
    
    if (this.elements.urlInput) {
      this.elements.urlInput.value = webhookUrl;
    }
  }

  /**
   * 测试Webhook
   */
  private async testWebhook(): Promise<void> {
    const config = this.getCurrentConfig();
    
    if (!config.enabled) {
      showError('请先启用Webhook功能');
      return;
    }
    
    if (!config.token || !config.url) {
      showError('请先配置Webhook令牌和URL');
      return;
    }
    
    // 显示测试状态
    if (this.elements.testWebhookButton) {
      this.elements.testWebhookButton.disabled = true;
      this.elements.testWebhookButton.textContent = '测试中...';
    }
    
    try {
      showInfo('正在测试Webhook连接...');
      
      const result = await WebhookTester.testWebhook({
        url: config.url,
        token: config.token,
        testType: 'full'
      });
      
      this.updateStatus({
        tested: true,
        lastTest: new Date(),
        lastTestResult: result
      });
      
      if (result.success) {
        showSuccess(`Webhook测试成功！响应时间: ${result.details?.responseTime}ms`);
      } else {
        showError(`Webhook测试失败: ${result.message}`);
        
        if (result.suggestions && result.suggestions.length > 0) {
          showInfo('建议: ' + result.suggestions.join(', '));
        }
      }
      
      emit('webhook:tested', { result });
      
    } catch (error) {
      console.error('Webhook测试失败:', error);
      showError('Webhook测试执行失败');
      
      this.updateStatus({
        tested: true,
        lastTest: new Date(),
        issues: ['测试执行失败: ' + (error as Error).message]
      });
      
    } finally {
      // 恢复按钮状态
      if (this.elements.testWebhookButton) {
        this.elements.testWebhookButton.disabled = false;
        this.elements.testWebhookButton.textContent = '测试Webhook';
      }
    }
  }

  /**
   * 刷新验证令牌
   */
  private async refreshVerificationToken(): Promise<void> {
    if (this.elements.refreshTokenButton) {
      this.elements.refreshTokenButton.disabled = true;
    }
    
    try {
      const response = await post('notion_to_wordpress_refresh_verification_token', {});
      
      if (response && response.data?.verification_token) {
        if (this.elements.verificationTokenInput) {
          this.elements.verificationTokenInput.value = response.data.verification_token;
        }
        
        showSuccess('验证令牌已刷新');
        emit('webhook:verification:refreshed', { token: response.data.verification_token });
      } else {
        showError('刷新验证令牌失败');
      }
      
    } catch (error) {
      console.error('刷新验证令牌失败:', error);
      showError('刷新验证令牌失败');
      
    } finally {
      if (this.elements.refreshTokenButton) {
        this.elements.refreshTokenButton.disabled = false;
      }
    }
  }

  /**
   * 复制Webhook URL
   */
  private async copyWebhookUrl(): Promise<void> {
    const url = this.elements.urlInput?.value;
    
    if (!url) {
      showError('没有可复制的URL');
      return;
    }
    
    try {
      await navigator.clipboard.writeText(url);
      showSuccess('Webhook URL已复制到剪贴板');
      emit('webhook:url:copied', { url });
    } catch (error) {
      // 降级到传统方法
      const textArea = document.createElement('textarea');
      textArea.value = url;
      document.body.appendChild(textArea);
      textArea.select();
      document.execCommand('copy');
      document.body.removeChild(textArea);
      
      showSuccess('Webhook URL已复制到剪贴板');
    }
  }

  /**
   * 验证当前配置
   */
  private validateCurrentConfig(): void {
    const config = this.getCurrentConfig();
    
    if (!config.enabled) {
      return;
    }
    
    const result = WebhookValidator.validateWebhookConfig(config);
    
    this.updateStatus({
      configured: result.isValid,
      issues: result.errors
    });
    
    this.updateValidationUI(result);
    
    emit('webhook:validated', { result });
  }

  /**
   * 获取当前配置
   */
  private getCurrentConfig(): WebhookConfig {
    return {
      enabled: this.elements.enabledCheckbox?.checked || false,
      token: this.elements.tokenInput?.value || '',
      url: this.elements.urlInput?.value || '',
      verificationToken: this.elements.verificationTokenInput?.value || ''
    };
  }

  /**
   * 更新状态
   */
  private updateStatus(updates: Partial<WebhookStatus>): void {
    this.status = { ...this.status, ...updates };
    this.updateStatusUI();
    emit('webhook:status:changed', { status: this.status });
  }

  /**
   * 更新UI
   */
  private updateUI(config: any): void {
    if (this.elements.enabledCheckbox) {
      this.elements.enabledCheckbox.checked = config.enabled || false;
    }
    
    if (this.elements.tokenInput) {
      this.elements.tokenInput.value = config.token || '';
    }
    
    if (this.elements.urlInput) {
      this.elements.urlInput.value = config.url || '';
    }
    
    if (this.elements.verificationTokenInput) {
      this.elements.verificationTokenInput.value = config.verificationToken || '';
    }
    
    // 显示/隐藏设置区域
    if (this.elements.settingsContainer) {
      if (config.enabled) {
        this.elements.settingsContainer.classList.remove('notion-wp-hidden');
      } else {
        this.elements.settingsContainer.classList.add('notion-wp-hidden');
      }
    }
  }

  /**
   * 更新状态UI
   */
  private updateStatusUI(): void {
    if (!this.elements.statusIndicator) return;
    
    const { enabled, configured, tested, issues } = this.status;
    
    let statusClass = 'status-disabled';
    let statusText = '未启用';
    
    if (enabled) {
      if (issues.length > 0) {
        statusClass = 'status-error';
        statusText = '配置错误';
      } else if (configured && tested) {
        statusClass = 'status-success';
        statusText = '正常运行';
      } else if (configured) {
        statusClass = 'status-warning';
        statusText = '未测试';
      } else {
        statusClass = 'status-warning';
        statusText = '未配置';
      }
    }
    
    this.elements.statusIndicator.className = `webhook-status ${statusClass}`;
    this.elements.statusIndicator.textContent = statusText;
  }

  /**
   * 更新验证UI
   */
  private updateValidationUI(result: WebhookValidationResult): void {
    // 这里可以添加具体的验证UI更新逻辑
    // 比如显示错误信息、警告等
    
    if (result.errors.length > 0) {
      console.warn('Webhook配置错误:', result.errors);
    }
    
    if (result.warnings.length > 0) {
      console.warn('Webhook配置警告:', result.warnings);
    }
  }

  /**
   * 处理设置保存
   */
  private handleSettingsSave(): void {
    const config = this.getCurrentConfig();
    
    if (config.enabled && this.options.autoValidate) {
      this.validateCurrentConfig();
    }
    
    emit('webhook:settings:saved', { config });
  }

  /**
   * 开始状态刷新
   */
  private startStatusRefresh(): void {
    this.refreshTimer = setInterval(() => {
      if (this.status.enabled) {
        this.loadCurrentConfig();
      }
    }, this.options.refreshInterval);
  }

  /**
   * 停止状态刷新
   */
  private stopStatusRefresh(): void {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
      this.refreshTimer = null;
    }
  }

  /**
   * 获取当前状态
   */
  getStatus(): WebhookStatus {
    return { ...this.status };
  }

  /**
   * 获取配置建议
   */
  getConfigurationSuggestions(): string[] {
    return WebhookValidator.getConfigurationSuggestions();
  }

  /**
   * 获取安全建议
   */
  getSecurityBestPractices(): string[] {
    return WebhookValidator.getSecurityBestPractices();
  }

  /**
   * 销毁管理器
   */
  destroy(): void {
    this.stopStatusRefresh();
    
    // 清理事件监听器
    // 这里可以添加具体的清理逻辑
    
    WebhookManager.instance = null;
    emit('webhook:manager:destroyed');
    console.log('🗑️ Webhook管理器已销毁');
  }
}

// 导出单例实例
export const webhookManager = WebhookManager.getInstance();

export default WebhookManager;
