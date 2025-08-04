/**
 * 按钮状态管理工具
 */

export interface ButtonState {
  loading: boolean;
  disabled: boolean;
  originalText: string;
  loadingText: string;
}

/**
 * 按钮管理器
 */
export class ButtonManager {
  private buttons = new Map<HTMLElement, ButtonState>();

  /**
   * 设置按钮加载状态
   */
  setLoading(button: HTMLElement, loading = true, loadingText = '处理中...'): void {
    let state = this.buttons.get(button);
    
    if (!state) {
      state = {
        loading: false,
        disabled: button.hasAttribute('disabled'),
        originalText: this.getButtonText(button),
        loadingText
      };
      this.buttons.set(button, state);
    }

    state.loading = loading;
    state.loadingText = loadingText;

    if (loading) {
      // 保存原始文本（如果还没保存）
      if (!state.originalText) {
        state.originalText = this.getButtonText(button);
      }
      
      // 设置加载状态
      button.setAttribute('disabled', 'true');
      button.classList.add('loading');
      this.setButtonText(button, loadingText);
      
      // 添加加载动画
      this.addLoadingSpinner(button);
    } else {
      // 恢复原始状态
      if (!state.disabled) {
        button.removeAttribute('disabled');
      }
      button.classList.remove('loading');
      this.setButtonText(button, state.originalText);
      
      // 移除加载动画
      this.removeLoadingSpinner(button);
    }
  }

  /**
   * 设置按钮禁用状态
   */
  setDisabled(button: HTMLElement, disabled = true): void {
    let state = this.buttons.get(button);
    
    if (!state) {
      state = {
        loading: false,
        disabled: button.hasAttribute('disabled'),
        originalText: this.getButtonText(button),
        loadingText: '处理中...'
      };
      this.buttons.set(button, state);
    }

    state.disabled = disabled;

    if (disabled) {
      button.setAttribute('disabled', 'true');
      button.classList.add('disabled');
    } else {
      if (!state.loading) {
        button.removeAttribute('disabled');
      }
      button.classList.remove('disabled');
    }
  }

  /**
   * 重置按钮状态
   */
  reset(button: HTMLElement): void {
    const state = this.buttons.get(button);
    if (!state) return;

    button.removeAttribute('disabled');
    button.classList.remove('loading', 'disabled');
    this.setButtonText(button, state.originalText);
    this.removeLoadingSpinner(button);
    
    this.buttons.delete(button);
  }

  /**
   * 获取按钮状态
   */
  getState(button: HTMLElement): ButtonState | undefined {
    return this.buttons.get(button);
  }

  /**
   * 检查按钮是否在加载中
   */
  isLoading(button: HTMLElement): boolean {
    const state = this.buttons.get(button);
    return state?.loading || false;
  }

  /**
   * 检查按钮是否被禁用
   */
  isDisabled(button: HTMLElement): boolean {
    const state = this.buttons.get(button);
    return state?.disabled || button.hasAttribute('disabled');
  }

  /**
   * 批量设置按钮状态
   */
  setBatchLoading(buttons: HTMLElement[], loading = true, loadingText = '处理中...'): void {
    buttons.forEach(button => this.setLoading(button, loading, loadingText));
  }

  /**
   * 批量重置按钮状态
   */
  resetBatch(buttons: HTMLElement[]): void {
    buttons.forEach(button => this.reset(button));
  }

  /**
   * 清理所有按钮状态
   */
  clearAll(): void {
    for (const button of this.buttons.keys()) {
      this.reset(button);
    }
  }

  private getButtonText(button: HTMLElement): string {
    if (button instanceof HTMLInputElement) {
      return button.value;
    }
    return button.textContent || button.innerHTML || '';
  }

  private setButtonText(button: HTMLElement, text: string): void {
    if (button instanceof HTMLInputElement) {
      button.value = text;
    } else {
      button.textContent = text;
    }
  }

  private addLoadingSpinner(button: HTMLElement): void {
    // 检查是否已经有加载动画
    if (button.querySelector('.loading-spinner')) return;

    const spinner = document.createElement('span');
    spinner.className = 'loading-spinner';
    spinner.innerHTML = `
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
        <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-dasharray="31.416" stroke-dashoffset="31.416">
          <animate attributeName="stroke-dasharray" dur="2s" values="0 31.416;15.708 15.708;0 31.416" repeatCount="indefinite"/>
          <animate attributeName="stroke-dashoffset" dur="2s" values="0;-15.708;-31.416" repeatCount="indefinite"/>
        </circle>
      </svg>
    `;
    
    // 添加样式
    spinner.style.cssText = `
      display: inline-block;
      margin-right: 8px;
      animation: spin 1s linear infinite;
    `;

    // 添加旋转动画样式
    if (!document.querySelector('#loading-spinner-styles')) {
      const style = document.createElement('style');
      style.id = 'loading-spinner-styles';
      style.textContent = `
        @keyframes spin {
          from { transform: rotate(0deg); }
          to { transform: rotate(360deg); }
        }
        .loading-spinner svg {
          vertical-align: middle;
        }
      `;
      document.head.appendChild(style);
    }

    button.insertBefore(spinner, button.firstChild);
  }

  private removeLoadingSpinner(button: HTMLElement): void {
    const spinner = button.querySelector('.loading-spinner');
    if (spinner) {
      spinner.remove();
    }
  }
}

/**
 * 进度按钮管理器
 */
export class ProgressButtonManager extends ButtonManager {
  private progressBars = new Map<HTMLElement, HTMLElement>();

  /**
   * 设置按钮进度
   */
  setProgress(button: HTMLElement, progress: number, text?: string): void {
    this.setLoading(button, true, text || `${Math.round(progress)}%`);
    
    let progressBar = this.progressBars.get(button);
    if (!progressBar) {
      progressBar = this.createProgressBar(button);
      this.progressBars.set(button, progressBar);
    }

    const fill = progressBar.querySelector('.progress-fill') as HTMLElement;
    if (fill) {
      fill.style.width = `${Math.max(0, Math.min(100, progress))}%`;
    }
  }

  /**
   * 重置按钮（包括进度条）
   */
  reset(button: HTMLElement): void {
    super.reset(button);
    
    const progressBar = this.progressBars.get(button);
    if (progressBar) {
      progressBar.remove();
      this.progressBars.delete(button);
    }
  }

  private createProgressBar(button: HTMLElement): HTMLElement {
    const progressBar = document.createElement('div');
    progressBar.className = 'button-progress-bar';
    progressBar.innerHTML = `
      <div class="progress-track">
        <div class="progress-fill"></div>
      </div>
    `;

    // 添加样式
    progressBar.style.cssText = `
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: rgba(255, 255, 255, 0.3);
      border-radius: 0 0 4px 4px;
      overflow: hidden;
    `;

    const fill = progressBar.querySelector('.progress-fill') as HTMLElement;
    if (fill) {
      fill.style.cssText = `
        height: 100%;
        background: currentColor;
        width: 0%;
        transition: width 0.3s ease;
      `;
    }

    // 确保按钮有相对定位
    const buttonStyle = getComputedStyle(button);
    if (buttonStyle.position === 'static') {
      button.style.position = 'relative';
    }

    button.appendChild(progressBar);
    return progressBar;
  }
}

// 默认实例
export const buttonManager = new ButtonManager();
export const progressButtonManager = new ProgressButtonManager();

// 便捷函数
export function setButtonLoading(button: HTMLElement, loading = true, text = '处理中...'): void {
  buttonManager.setLoading(button, loading, text);
}

export function setButtonDisabled(button: HTMLElement, disabled = true): void {
  buttonManager.setDisabled(button, disabled);
}

export function resetButton(button: HTMLElement): void {
  buttonManager.reset(button);
}

export function setButtonProgress(button: HTMLElement, progress: number, text?: string): void {
  progressButtonManager.setProgress(button, progress, text);
}
