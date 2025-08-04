/**
 * Webhook组件 - 现代化TypeScript版本
 * 
 * 提供Webhook配置界面的交互功能，包括：
 * - 配置表单处理
 * - 实时验证
 * - 状态显示
 * - 测试功能
 */

import { BaseComponent, ComponentOptions } from './BaseComponent';
import { on, emit } from '../../shared/core/EventBus';
import { showSuccess, showError, showWarning } from '../../shared/utils/toast';

export interface WebhookComponentOptions extends ComponentOptions {
  autoValidate?: boolean;
  showAdvancedOptions?: boolean;
  enableTesting?: boolean;
}

/**
 * Webhook组件类
 */
export class WebhookComponent extends BaseComponent {
  protected options: WebhookComponentOptions;

  protected defaultOptions: WebhookComponentOptions = {
    autoValidate: true,
    showAdvancedOptions: false,
    enableTesting: true
  };

  constructor(options: WebhookComponentOptions = {} as WebhookComponentOptions) {
    const finalOptions = {
      autoValidate: true,
      showAdvancedOptions: false,
      enableTesting: true,
      ...options
    };
    super(finalOptions);
    this.options = finalOptions;
  }

  private elements: {
    container?: HTMLElement;
    enabledCheckbox?: HTMLInputElement;
    settingsContainer?: HTMLElement;
    tokenInput?: HTMLInputElement;
    urlDisplay?: HTMLInputElement;
    verificationTokenInput?: HTMLInputElement;
    generateTokenBtn?: HTMLButtonElement;
    testWebhookBtn?: HTMLButtonElement;
    refreshTokenBtn?: HTMLButtonElement;
    copyUrlBtn?: HTMLButtonElement;
    statusIndicator?: HTMLElement;
    advancedToggle?: HTMLButtonElement;
    advancedOptions?: HTMLElement;
    incrementalSyncCheckbox?: HTMLInputElement;
    checkDeletionsCheckbox?: HTMLInputElement;
  } = {};

  /**
   * 组件初始化回调
   */
  onInit(): void {
    this.bindElements();
    this.setupWebhookManagerIntegration();
    this.updateUI();

    console.log('✅ Webhook组件已初始化');
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
    // 渲染逻辑
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
    console.log('Webhook组件状态变化:', state);
  }

  /**
   * 绑定DOM元素
   */
  private bindElements(): void {
    if (!this.element) return;

    this.elements = {
      container: this.element,
      enabledCheckbox: this.element.querySelector('#webhook_enabled') as HTMLInputElement,
      settingsContainer: this.element.querySelector('#webhook-settings') as HTMLElement,
      tokenInput: this.element.querySelector('#webhook_token') as HTMLInputElement,
      urlDisplay: this.element.querySelector('#webhook_url') as HTMLInputElement,
      verificationTokenInput: this.element.querySelector('#verification_token') as HTMLInputElement,
      generateTokenBtn: this.element.querySelector('#generate-webhook-token') as HTMLButtonElement,
      testWebhookBtn: this.element.querySelector('#test-webhook') as HTMLButtonElement,
      refreshTokenBtn: this.element.querySelector('#refresh-verification-token') as HTMLButtonElement,
      copyUrlBtn: this.element.querySelector('.copy-webhook-url') as HTMLButtonElement,
      statusIndicator: this.element.querySelector('.webhook-status') as HTMLElement,
      advancedToggle: this.element.querySelector('#webhook-advanced-toggle') as HTMLButtonElement,
      advancedOptions: this.element.querySelector('#webhook-advanced-options') as HTMLElement,
      incrementalSyncCheckbox: this.element.querySelector('#webhook_incremental_sync') as HTMLInputElement,
      checkDeletionsCheckbox: this.element.querySelector('#webhook_check_deletions') as HTMLInputElement
    };
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // Webhook启用/禁用
    if (this.elements.enabledCheckbox) {
      this.elements.enabledCheckbox.addEventListener('change', (e) => {
        this.handleWebhookToggle((e.target as HTMLInputElement).checked);
      });
    }

    // 生成令牌
    if (this.elements.generateTokenBtn) {
      this.elements.generateTokenBtn.addEventListener('click', () => {
        this.handleGenerateToken();
      });
    }

    // 测试Webhook
    if (this.elements.testWebhookBtn && this.options.enableTesting) {
      this.elements.testWebhookBtn.addEventListener('click', () => {
        this.handleTestWebhook();
      });
    }

    // 刷新验证令牌
    if (this.elements.refreshTokenBtn) {
      this.elements.refreshTokenBtn.addEventListener('click', () => {
        this.handleRefreshVerificationToken();
      });
    }

    // 复制URL
    if (this.elements.copyUrlBtn) {
      this.elements.copyUrlBtn.addEventListener('click', () => {
        this.handleCopyUrl();
      });
    }

    // 高级选项切换
    if (this.elements.advancedToggle) {
      this.elements.advancedToggle.addEventListener('click', () => {
        this.toggleAdvancedOptions();
      });
    }

    // 令牌输入验证
    if (this.elements.tokenInput && this.options.autoValidate) {
      this.elements.tokenInput.addEventListener('input', () => {
        this.validateToken();
      });
    }

    // 监听键盘事件
    this.element?.addEventListener('keydown', (e: KeyboardEvent) => {
      if (e.key === 'Enter' && e.ctrlKey) {
        this.handleTestWebhook();
      }
    });
  }

  /**
   * 设置Webhook管理器集成
   */
  private setupWebhookManagerIntegration(): void {
    // 监听Webhook管理器事件
    on('webhook:status:changed', (_event, data) => {
      this.updateStatusDisplay(data.status);
    });

    on('webhook:tested', (_event, data) => {
      this.handleTestResult(data.result);
    });

    on('webhook:token:generated', (_event, data) => {
      this.updateTokenDisplay(data.token);
    });

    on('webhook:validation:result', (_event, data) => {
      this.handleValidationResult(data.result);
    });
  }

  /**
   * 处理Webhook启用/禁用
   */
  private handleWebhookToggle(enabled: boolean): void {
    // 显示/隐藏设置区域
    if (this.elements.settingsContainer) {
      if (enabled) {
        this.elements.settingsContainer.classList.remove('notion-wp-hidden');
        this.slideDown(this.elements.settingsContainer);
      } else {
        this.slideUp(this.elements.settingsContainer, () => {
          this.elements.settingsContainer?.classList.add('notion-wp-hidden');
        });
      }
    }

    // 更新状态
    this.updateWebhookStatus(enabled);
    
    // 如果启用且没有令牌，自动生成
    if (enabled && !this.elements.tokenInput?.value) {
      this.handleGenerateToken();
    }

    emit('webhook:component:toggled', { enabled });
  }

  /**
   * 处理生成令牌
   */
  private handleGenerateToken(): void {
    // 生成新令牌
    const newToken = this.generateSecureToken();
    
    if (this.elements.tokenInput) {
      this.elements.tokenInput.value = newToken;
    }
    
    // 更新URL显示
    this.updateWebhookUrl(newToken);
    
    // 验证新令牌
    if (this.options.autoValidate) {
      this.validateToken();
    }
    
    showSuccess('已生成新的Webhook令牌');
    emit('webhook:component:token:generated', { token: newToken });
  }

  /**
   * 处理测试Webhook
   */
  private async handleTestWebhook(): Promise<void> {
    if (!this.options.enableTesting) {
      showWarning('测试功能已禁用');
      return;
    }

    const token = this.elements.tokenInput?.value;
    const url = this.elements.urlDisplay?.value;
    
    if (!token || !url) {
      showError('请先配置Webhook令牌和URL');
      return;
    }

    // 设置测试状态
    this.setTestingState(true);
    
    try {
      // 通过Webhook管理器执行测试
      // 这里应该调用公共方法或者直接实现测试逻辑
      console.log('执行Webhook测试...');
    } catch (error) {
      console.error('Webhook测试失败:', error);
      showError('Webhook测试执行失败');
    } finally {
      this.setTestingState(false);
    }
  }

  /**
   * 处理刷新验证令牌
   */
  private async handleRefreshVerificationToken(): Promise<void> {
    if (this.elements.refreshTokenBtn) {
      this.elements.refreshTokenBtn.disabled = true;
      this.elements.refreshTokenBtn.textContent = '刷新中...';
    }
    
    try {
      // 通过Webhook管理器刷新
      // 这里应该调用公共方法或者直接实现刷新逻辑
      console.log('刷新验证令牌...');
    } catch (error) {
      console.error('刷新验证令牌失败:', error);
      showError('刷新验证令牌失败');
    } finally {
      if (this.elements.refreshTokenBtn) {
        this.elements.refreshTokenBtn.disabled = false;
        this.elements.refreshTokenBtn.textContent = '刷新';
      }
    }
  }

  /**
   * 处理复制URL
   */
  private async handleCopyUrl(): Promise<void> {
    const url = this.elements.urlDisplay?.value;
    
    if (!url) {
      showError('没有可复制的URL');
      return;
    }
    
    try {
      await navigator.clipboard.writeText(url);
      showSuccess('Webhook URL已复制到剪贴板');
      
      // 临时改变按钮文本
      if (this.elements.copyUrlBtn) {
        const originalText = this.elements.copyUrlBtn.textContent;
        this.elements.copyUrlBtn.textContent = '已复制!';
        setTimeout(() => {
          if (this.elements.copyUrlBtn) {
            this.elements.copyUrlBtn.textContent = originalText;
          }
        }, 2000);
      }
      
    } catch (error) {
      // 降级到传统方法
      this.fallbackCopyToClipboard(url);
      showSuccess('Webhook URL已复制到剪贴板');
    }
  }

  /**
   * 切换高级选项
   */
  private toggleAdvancedOptions(): void {
    if (!this.elements.advancedOptions || !this.elements.advancedToggle) return;
    
    const isVisible = !this.elements.advancedOptions.classList.contains('notion-wp-hidden');
    
    if (isVisible) {
      this.slideUp(this.elements.advancedOptions, () => {
        this.elements.advancedOptions?.classList.add('notion-wp-hidden');
      });
      this.elements.advancedToggle.textContent = '显示高级选项';
    } else {
      this.elements.advancedOptions.classList.remove('notion-wp-hidden');
      this.slideDown(this.elements.advancedOptions);
      this.elements.advancedToggle.textContent = '隐藏高级选项';
    }
  }

  /**
   * 验证令牌
   */
  private validateToken(): void {
    const token = this.elements.tokenInput?.value || '';
    
    // 这里可以添加实时验证逻辑
    if (token.length < 16) {
      this.showTokenValidation('令牌长度至少需要16个字符', 'warning');
    } else if (!/^[a-zA-Z0-9_-]+$/.test(token)) {
      this.showTokenValidation('令牌只能包含字母、数字、下划线和连字符', 'error');
    } else {
      this.showTokenValidation('令牌格式正确', 'success');
    }
  }

  /**
   * 显示令牌验证结果
   */
  private showTokenValidation(message: string, type: 'success' | 'warning' | 'error'): void {
    // 这里可以添加具体的UI反馈逻辑
    console.log(`Token validation [${type}]: ${message}`);
  }

  /**
   * 生成安全令牌
   */
  private generateSecureToken(length: number = 32): string {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
    let result = '';
    
    for (let i = 0; i < length; i++) {
      result += chars[Math.floor(Math.random() * chars.length)];
    }
    
    return result;
  }

  /**
   * 更新Webhook URL
   */
  private updateWebhookUrl(token: string): void {
    const baseUrl = window.location.origin;
    const webhookUrl = `${baseUrl}/wp-json/notion-to-wordpress/v1/webhook/${token}`;
    
    if (this.elements.urlDisplay) {
      this.elements.urlDisplay.value = webhookUrl;
    }
  }

  /**
   * 更新令牌显示
   */
  private updateTokenDisplay(token: string): void {
    if (this.elements.tokenInput) {
      this.elements.tokenInput.value = token;
    }
    
    this.updateWebhookUrl(token);
  }

  /**
   * 更新状态显示
   */
  private updateStatusDisplay(status: any): void {
    if (!this.elements.statusIndicator) return;
    
    const { enabled, configured, tested, issues } = status;
    
    let statusClass = 'status-disabled';
    let statusText = '未启用';
    let statusIcon = '⚪';
    
    if (enabled) {
      if (issues && issues.length > 0) {
        statusClass = 'status-error';
        statusText = '配置错误';
        statusIcon = '❌';
      } else if (configured && tested) {
        statusClass = 'status-success';
        statusText = '正常运行';
        statusIcon = '✅';
      } else if (configured) {
        statusClass = 'status-warning';
        statusText = '未测试';
        statusIcon = '⚠️';
      } else {
        statusClass = 'status-warning';
        statusText = '未配置';
        statusIcon = '⚠️';
      }
    }
    
    this.elements.statusIndicator.className = `webhook-status ${statusClass}`;
    this.elements.statusIndicator.innerHTML = `${statusIcon} ${statusText}`;
  }

  /**
   * 处理测试结果
   */
  private handleTestResult(result: any): void {
    if (result.success) {
      showSuccess(`Webhook测试成功！响应时间: ${result.details?.responseTime || 0}ms`);
    } else {
      showError(`Webhook测试失败: ${result.message}`);
      
      if (result.suggestions && result.suggestions.length > 0) {
        setTimeout(() => {
          showWarning('建议: ' + result.suggestions.join(', '));
        }, 1000);
      }
    }
  }

  /**
   * 处理验证结果
   */
  private handleValidationResult(result: any): void {
    if (result.errors && result.errors.length > 0) {
      console.warn('Webhook配置错误:', result.errors);
    }
    
    if (result.warnings && result.warnings.length > 0) {
      console.warn('Webhook配置警告:', result.warnings);
    }
  }

  /**
   * 设置测试状态
   */
  private setTestingState(testing: boolean): void {
    if (this.elements.testWebhookBtn) {
      this.elements.testWebhookBtn.disabled = testing;
      this.elements.testWebhookBtn.textContent = testing ? '测试中...' : '测试Webhook';
    }
  }

  /**
   * 更新Webhook状态
   */
  private updateWebhookStatus(enabled: boolean): void {
    // 更新各种状态指示器
    this.element?.classList.toggle('webhook-enabled', enabled);
    this.element?.classList.toggle('webhook-disabled', !enabled);
  }

  /**
   * 降级复制到剪贴板
   */
  private fallbackCopyToClipboard(text: string): void {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    document.execCommand('copy');
    document.body.removeChild(textArea);
  }

  /**
   * 滑动显示元素
   */
  private slideDown(element: HTMLElement): void {
    element.style.height = '0';
    element.style.overflow = 'hidden';
    element.style.transition = 'height 0.3s ease-out';
    
    const height = element.scrollHeight;
    element.style.height = height + 'px';
    
    setTimeout(() => {
      element.style.height = '';
      element.style.overflow = '';
      element.style.transition = '';
    }, 300);
  }

  /**
   * 滑动隐藏元素
   */
  private slideUp(element: HTMLElement, callback?: () => void): void {
    element.style.height = element.scrollHeight + 'px';
    element.style.overflow = 'hidden';
    element.style.transition = 'height 0.3s ease-out';
    
    setTimeout(() => {
      element.style.height = '0';
    }, 10);
    
    setTimeout(() => {
      element.style.height = '';
      element.style.overflow = '';
      element.style.transition = '';
      callback?.();
    }, 300);
  }

  /**
   * 更新UI
   */
  private updateUI(): void {
    // 初始化UI状态
    const enabled = this.elements.enabledCheckbox?.checked || false;
    this.updateWebhookStatus(enabled);
    
    // 如果启用了高级选项，显示相关UI
    if (this.options.showAdvancedOptions && this.elements.advancedOptions) {
      this.elements.advancedOptions.classList.remove('notion-wp-hidden');
    }
  }

  /**
   * 销毁组件
   */
  destroy(): void {
    // 清理事件监听器等
    super.destroy();
    console.log('🗑️ Webhook组件已销毁');
  }
}

export default WebhookComponent;
