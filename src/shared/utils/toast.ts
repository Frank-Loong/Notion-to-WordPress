/**
 * Toast通知系统
 */

export type ToastType = 'success' | 'error' | 'warning' | 'info';

export interface ToastOptions {
  type?: ToastType;
  duration?: number;
  closable?: boolean;
  position?: 'top-right' | 'top-left' | 'bottom-right' | 'bottom-left' | 'top-center' | 'bottom-center';
  className?: string;
  onClick?: () => void;
  onClose?: () => void;
}

export interface ToastItem {
  id: string;
  message: string;
  type: ToastType;
  duration: number;
  closable: boolean;
  element: HTMLElement;
  timer?: NodeJS.Timeout;
  options: ToastOptions;
}

/**
 * Toast管理器
 */
export class ToastManager {
  private toasts = new Map<string, ToastItem>();
  private container: HTMLElement | null = null;
  private position: ToastOptions['position'] = 'top-right';
  private maxToasts = 5;

  constructor(options: { position?: ToastOptions['position']; maxToasts?: number } = {}) {
    this.position = options.position || 'top-right';
    this.maxToasts = options.maxToasts || 5;
    this.createContainer();
  }

  /**
   * 显示Toast
   */
  show(message: string, options: ToastOptions = {}): string {
    const id = this.generateId();
    const toast: ToastItem = {
      id,
      message,
      type: options.type || 'info',
      duration: options.duration ?? 4000,
      closable: options.closable ?? true,
      element: this.createElement(id, message, options),
      options
    };

    // 如果超过最大数量，移除最旧的
    if (this.toasts.size >= this.maxToasts) {
      const firstToast = this.toasts.values().next().value;
      if (firstToast) {
        this.remove(firstToast.id);
      }
    }

    this.toasts.set(id, toast);
    this.container?.appendChild(toast.element);

    // 设置自动关闭
    if (toast.duration > 0) {
      toast.timer = setTimeout(() => {
        this.remove(id);
      }, toast.duration);
    }

    // 添加进入动画
    requestAnimationFrame(() => {
      toast.element.classList.add('toast-enter');
    });

    return id;
  }

  /**
   * 移除Toast
   */
  remove(id: string): void {
    const toast = this.toasts.get(id);
    if (!toast) return;

    // 清除定时器
    if (toast.timer) {
      clearTimeout(toast.timer);
    }

    // 添加退出动画
    toast.element.classList.add('toast-exit');
    
    setTimeout(() => {
      if (toast.element.parentNode) {
        toast.element.parentNode.removeChild(toast.element);
      }
      this.toasts.delete(id);
      
      // 调用关闭回调
      if (toast.options.onClose) {
        toast.options.onClose();
      }
    }, 300);
  }

  /**
   * 清除所有Toast
   */
  clear(): void {
    for (const id of this.toasts.keys()) {
      this.remove(id);
    }
  }

  /**
   * 成功消息
   */
  success(message: string, options: Omit<ToastOptions, 'type'> = {}): string {
    return this.show(message, { ...options, type: 'success' });
  }

  /**
   * 错误消息
   */
  error(message: string, options: Omit<ToastOptions, 'type'> = {}): string {
    return this.show(message, { ...options, type: 'error', duration: options.duration ?? 6000 });
  }

  /**
   * 警告消息
   */
  warning(message: string, options: Omit<ToastOptions, 'type'> = {}): string {
    return this.show(message, { ...options, type: 'warning' });
  }

  /**
   * 信息消息
   */
  info(message: string, options: Omit<ToastOptions, 'type'> = {}): string {
    return this.show(message, { ...options, type: 'info' });
  }

  /**
   * 设置最大Toast数量
   */
  setMaxToasts(max: number): void {
    this.maxToasts = max;
  }

  /**
   * 设置位置
   */
  setPosition(position: ToastOptions['position']): void {
    this.position = position;
    if (this.container) {
      this.updateContainerPosition();
    }
  }

  private createContainer(): void {
    this.container = document.createElement('div');
    this.container.className = 'toast-container';
    this.updateContainerPosition();
    document.body.appendChild(this.container);

    // 添加样式
    this.injectStyles();
  }

  private updateContainerPosition(): void {
    if (!this.container) return;

    const positions = {
      'top-right': { top: '20px', right: '20px' },
      'top-left': { top: '20px', left: '20px' },
      'bottom-right': { bottom: '20px', right: '20px' },
      'bottom-left': { bottom: '20px', left: '20px' },
      'top-center': { top: '20px', left: '50%', transform: 'translateX(-50%)' },
      'bottom-center': { bottom: '20px', left: '50%', transform: 'translateX(-50%)' }
    };

    const pos = positions[this.position || 'top-right'];
    Object.assign(this.container.style, {
      position: 'fixed',
      zIndex: '10000',
      pointerEvents: 'none',
      ...pos
    });
  }

  private createElement(id: string, message: string, options: ToastOptions): HTMLElement {
    const toast = document.createElement('div');
    toast.className = `toast toast-${options.type || 'info'} ${options.className || ''}`;
    toast.setAttribute('data-toast-id', id);

    const icon = this.getIcon(options.type || 'info');
    const closeButton = options.closable !== false ? 
      '<button class="toast-close" type="button">&times;</button>' : '';

    toast.innerHTML = `
      <div class="toast-content">
        <div class="toast-icon">${icon}</div>
        <div class="toast-message">${message}</div>
        ${closeButton}
      </div>
    `;

    // 添加事件监听
    if (options.closable !== false) {
      const closeBtn = toast.querySelector('.toast-close');
      closeBtn?.addEventListener('click', () => this.remove(id));
    }

    if (options.onClick) {
      toast.addEventListener('click', options.onClick);
      toast.style.cursor = 'pointer';
    }

    // 鼠标悬停暂停自动关闭
    const toastItem = this.toasts.get(id);
    if (toastItem && toastItem.duration > 0) {
      toast.addEventListener('mouseenter', () => {
        if (toastItem.timer) {
          clearTimeout(toastItem.timer);
          toastItem.timer = undefined;
        }
      });

      toast.addEventListener('mouseleave', () => {
        toastItem.timer = setTimeout(() => {
          this.remove(id);
        }, 1000); // 鼠标离开后1秒关闭
      });
    }

    return toast;
  }

  private getIcon(type: ToastType): string {
    const icons = {
      success: '✓',
      error: '✕',
      warning: '⚠',
      info: 'ℹ'
    };
    return icons[type] || icons.info;
  }

  private generateId(): string {
    return `toast_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
  }

  private injectStyles(): void {
    if (document.querySelector('#toast-styles')) return;

    const style = document.createElement('style');
    style.id = 'toast-styles';
    style.textContent = `
      .toast-container {
        display: flex;
        flex-direction: column;
        gap: 10px;
        max-width: 400px;
      }

      .toast {
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        pointer-events: auto;
        transform: translateX(100%);
        opacity: 0;
        transition: all 0.3s ease;
        border-left: 4px solid;
      }

      .toast.toast-enter {
        transform: translateX(0);
        opacity: 1;
      }

      .toast.toast-exit {
        transform: translateX(100%);
        opacity: 0;
      }

      .toast-success {
        border-left-color: #10b981;
      }

      .toast-error {
        border-left-color: #ef4444;
      }

      .toast-warning {
        border-left-color: #f59e0b;
      }

      .toast-info {
        border-left-color: #3b82f6;
      }

      .toast-content {
        display: flex;
        align-items: flex-start;
        padding: 16px;
        gap: 12px;
      }

      .toast-icon {
        flex-shrink: 0;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: bold;
        color: white;
      }

      .toast-success .toast-icon {
        background: #10b981;
      }

      .toast-error .toast-icon {
        background: #ef4444;
      }

      .toast-warning .toast-icon {
        background: #f59e0b;
      }

      .toast-info .toast-icon {
        background: #3b82f6;
      }

      .toast-message {
        flex: 1;
        font-size: 14px;
        line-height: 1.5;
        color: #374151;
      }

      .toast-close {
        flex-shrink: 0;
        background: none;
        border: none;
        font-size: 18px;
        color: #9ca3af;
        cursor: pointer;
        padding: 0;
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: all 0.2s ease;
      }

      .toast-close:hover {
        background: #f3f4f6;
        color: #374151;
      }

      /* 左侧位置的动画 */
      .toast-container[style*="left"] .toast {
        transform: translateX(-100%);
      }

      .toast-container[style*="left"] .toast.toast-enter {
        transform: translateX(0);
      }

      .toast-container[style*="left"] .toast.toast-exit {
        transform: translateX(-100%);
      }
    `;

    document.head.appendChild(style);
  }
}

// 默认实例
export const toast = new ToastManager();

// 便捷函数
export function showToast(message: string, type: ToastType = 'info', options: ToastOptions = {}): string {
  return toast.show(message, { ...options, type });
}

export function showSuccess(message: string, options: Omit<ToastOptions, 'type'> = {}): string {
  return toast.success(message, options);
}

export function showError(message: string, options: Omit<ToastOptions, 'type'> = {}): string {
  return toast.error(message, options);
}

export function showWarning(message: string, options: Omit<ToastOptions, 'type'> = {}): string {
  return toast.warning(message, options);
}

export function showInfo(message: string, options: Omit<ToastOptions, 'type'> = {}): string {
  return toast.info(message, options);
}
