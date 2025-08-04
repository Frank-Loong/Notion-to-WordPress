/**
 * 管理界面工具函数集合 - 现代化TypeScript版本
 * 
 * 从原有admin-interactions.js的NotionUtils完全迁移，包括：
 * - 防抖和节流函数
 * - AJAX错误处理
 * - 按钮状态管理
 * - 进度管理工具
 * - 表单验证工具
 */

import { showError } from '../../shared/utils/toast';

export interface ButtonLoadingOptions {
  loadingText?: string;
  originalText?: string;
}

export interface ValidationResult {
  isValid: boolean;
  message: string;
  level: 'success' | 'error' | 'warning';
}

/**
 * 管理界面工具函数类
 */
export class AdminUtils {
  /**
   * 防抖函数 - 延迟执行函数，在指定时间内多次调用只执行最后一次
   */
  static debounce<T extends (...args: any[]) => any>(
    func: T,
    wait: number,
    immediate = false
  ): (...args: Parameters<T>) => void {
    let timeout: NodeJS.Timeout | null = null;
    
    return function executedFunction(this: any, ...args: Parameters<T>) {
      const later = () => {
        timeout = null;
        if (!immediate) func.apply(this, args);
      };
      
      const callNow = immediate && !timeout;
      
      if (timeout) {
        clearTimeout(timeout);
      }
      
      timeout = setTimeout(later, wait);
      
      if (callNow) {
        func.apply(this, args);
      }
    };
  }

  /**
   * 节流函数 - 限制函数执行频率
   */
  static throttle<T extends (...args: any[]) => any>(
    func: T,
    limit: number
  ): (...args: Parameters<T>) => void {
    let inThrottle = false;
    
    return function throttledFunction(this: any, ...args: Parameters<T>) {
      if (!inThrottle) {
        func.apply(this, args);
        inThrottle = true;
        setTimeout(() => {
          inThrottle = false;
        }, limit);
      }
    };
  }

  /**
   * 统一的AJAX错误处理
   */
  static handleAjaxError(
    xhr: JQuery.jqXHR,
    status: string,
    error: string,
    context = ''
  ): void {
    console.error(`AJAX Error ${context}:`, { xhr, status, error });
    
    let message = '网络请求失败';
    
    if (xhr.responseJSON?.data?.message) {
      message = xhr.responseJSON.data.message;
    } else if (error) {
      message = error;
    } else if (status === 'timeout') {
      message = '请求超时，请稍后重试';
    } else if (status === 'abort') {
      message = '请求被取消';
    } else if (xhr.status === 0) {
      message = '网络连接失败，请检查网络';
    } else if (xhr.status >= 500) {
      message = '服务器内部错误';
    } else if (xhr.status >= 400) {
      message = '请求错误';
    }
    
    showError(message);
  }

  /**
   * 按钮状态管理 - 设置加载状态
   */
  static setButtonLoading(
    button: HTMLButtonElement | HTMLInputElement,
    loading = true,
    options: ButtonLoadingOptions = {}
  ): void {
    const loadingText = options.loadingText || '处理中...';
    
    if (loading) {
      // 保存原始文本
      if (!button.dataset.originalText) {
        if (button instanceof HTMLInputElement) {
          button.dataset.originalText = button.value;
        } else {
          button.dataset.originalText = button.textContent || '';
        }
      }
      
      // 设置加载状态
      button.disabled = true;
      button.classList.add('loading');
      
      if (button instanceof HTMLInputElement) {
        button.value = loadingText;
      } else {
        button.textContent = loadingText;
      }
    } else {
      // 恢复原始状态
      button.disabled = false;
      button.classList.remove('loading');
      
      const originalText = options.originalText || button.dataset.originalText;
      if (originalText) {
        if (button instanceof HTMLInputElement) {
          button.value = originalText;
        } else {
          button.textContent = originalText;
        }
      }
      
      // 清理数据属性
      delete button.dataset.originalText;
    }
  }

  /**
   * 更新进度显示
   */
  static updateProgress(percentage: number, stepText?: string): void {
    const progressElement = document.getElementById('sync-progress');
    if (!progressElement) return;

    const fillElement = progressElement.querySelector('.progress-fill') as HTMLElement;
    const stepElement = progressElement.querySelector('.current-step') as HTMLElement;
    const percentageElement = progressElement.querySelector('.progress-percentage') as HTMLElement;

    // 显示进度条
    if (progressElement.classList.contains('notion-wp-hidden')) {
      progressElement.classList.remove('notion-wp-hidden');
      progressElement.style.display = 'block';
    }

    // 更新进度
    if (fillElement) {
      fillElement.style.width = `${percentage}%`;
    }
    
    if (stepElement && stepText) {
      stepElement.textContent = stepText;
    }
    
    if (percentageElement) {
      percentageElement.textContent = `${Math.round(percentage)}%`;
    }
  }

  /**
   * 隐藏进度显示
   */
  static hideProgress(): void {
    const progressElement = document.getElementById('sync-progress');
    if (!progressElement) return;

    progressElement.style.display = 'none';
    progressElement.classList.add('notion-wp-hidden');

    // 重置进度
    const fillElement = progressElement.querySelector('.progress-fill') as HTMLElement;
    const stepElement = progressElement.querySelector('.current-step') as HTMLElement;
    const percentageElement = progressElement.querySelector('.progress-percentage') as HTMLElement;

    if (fillElement) fillElement.style.width = '0%';
    if (stepElement) stepElement.textContent = '准备同步...';
    if (percentageElement) percentageElement.textContent = '0%';
  }

  /**
   * 设置同步按钮状态
   */
  static setSyncButtonState(
    button: HTMLButtonElement,
    state: 'loading' | 'success' | 'error' | 'normal',
    message?: string
  ): void {
    // 清除所有状态类
    button.classList.remove('loading', 'success', 'error');

    switch (state) {
      case 'loading':
        this.setButtonLoading(button, true);
        break;
        
      case 'success':
        this.setButtonLoading(button, false);
        button.classList.add('success');
        
        if (message) {
          const textElement = button.querySelector('.button-text');
          if (textElement) {
            textElement.textContent = message;
          }
        }
        
        // 3秒后恢复原始状态
        setTimeout(() => {
          button.classList.remove('success');
          const originalText = button.dataset.originalText;
          if (originalText) {
            const textElement = button.querySelector('.button-text');
            if (textElement) {
              textElement.textContent = originalText;
            }
          }
        }, 3000);
        break;
        
      case 'error':
        this.setButtonLoading(button, false);
        button.classList.add('error');
        
        if (message) {
          const textElement = button.querySelector('.button-text');
          if (textElement) {
            textElement.textContent = message;
          }
        }
        
        // 3秒后恢复原始状态
        setTimeout(() => {
          button.classList.remove('error');
          const originalText = button.dataset.originalText;
          if (originalText) {
            const textElement = button.querySelector('.button-text');
            if (textElement) {
              textElement.textContent = originalText;
            }
          }
        }, 3000);
        break;
        
      default:
        this.setButtonLoading(button, false);
    }
  }

  /**
   * 表单输入验证
   */
  static validateInput(input: HTMLInputElement, type: 'api-key' | 'database-id'): ValidationResult {
    const value = input.value.trim();
    let result: ValidationResult = {
      isValid: false,
      message: '',
      level: 'error'
    };

    switch (type) {
      case 'api-key':
        if (!value) {
          result.message = 'API密钥不能为空';
        } else if (value.length < 30 || value.length > 80) {
          result.message = 'API密钥长度可能不正确，请检查是否完整';
          result.level = 'warning';
        } else if (!/^[a-zA-Z0-9_-]+$/.test(value)) {
          result.message = 'API密钥格式可能不正确，应只包含字母、数字、下划线和连字符';
          result.level = 'warning';
        } else {
          result.message = 'API密钥格式正确';
          result.level = 'success';
          result.isValid = true;
        }
        break;

      case 'database-id':
        if (!value) {
          result.message = '数据库ID不能为空';
        } else if (value.length !== 32) {
          result.message = '数据库ID长度应为32位字符';
        } else if (!/^[a-f0-9]{32}$/i.test(value)) {
          result.message = '数据库ID格式不正确，应为32位十六进制字符';
        } else {
          result.message = '数据库ID格式正确';
          result.level = 'success';
          result.isValid = true;
        }
        break;
    }

    // 更新UI反馈
    this.updateValidationFeedback(input, result);

    return result;
  }

  /**
   * 更新验证反馈显示
   */
  private static updateValidationFeedback(input: HTMLInputElement, result: ValidationResult): void {
    const container = input.closest('.input-with-validation');
    if (!container) return;

    const feedback = container.querySelector('.validation-feedback') as HTMLElement;
    if (!feedback) return;

    // 更新反馈样式和文本
    feedback.className = `validation-feedback ${result.level}`;
    feedback.textContent = result.message;

    // 更新输入框样式
    input.className = input.className.replace(/\b(valid|invalid|warning)\b/g, '');
    if (result.isValid) {
      input.classList.add('valid');
    } else if (result.level === 'warning') {
      input.classList.add('warning');
    } else {
      input.classList.add('invalid');
    }
  }

  /**
   * 格式化日期时间显示
   */
  static formatDateTime(dateTime: string | null): string {
    if (!dateTime) return '从未';
    
    // 如果包含空格，将时间部分换行显示
    if (dateTime.includes(' ')) {
      const firstSpace = dateTime.indexOf(' ');
      return dateTime.slice(0, firstSpace) + '<br>' + dateTime.slice(firstSpace + 1);
    }
    
    return dateTime;
  }

  /**
   * 生成唯一ID
   */
  static generateId(prefix = 'id'): string {
    return `${prefix}_${Date.now()}_${Math.random().toString(36).substring(2, 11)}`;
  }

  /**
   * 安全的JSON解析
   */
  static safeJsonParse<T>(jsonString: string, defaultValue: T): T {
    try {
      return JSON.parse(jsonString);
    } catch (error) {
      console.warn('JSON解析失败:', error);
      return defaultValue;
    }
  }

  /**
   * 检查元素是否在视口中
   */
  static isElementInViewport(element: HTMLElement): boolean {
    const rect = element.getBoundingClientRect();
    return (
      rect.top >= 0 &&
      rect.left >= 0 &&
      rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
      rect.right <= (window.innerWidth || document.documentElement.clientWidth)
    );
  }
}
