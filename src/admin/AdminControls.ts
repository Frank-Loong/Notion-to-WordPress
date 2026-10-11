import { post } from '../shared/utils/ajax';
import type { AjaxResponse } from '../shared/types/wordpress';
import { showError, showInfo, showSuccess } from '../shared/utils/toast';

interface RecommendationData {
  performance_level: string;
  performance_label: string;
  performance_config: Record<string, string | number>;
  field_template: string;
  field_template_label: string;
  field_mapping: Record<string, string>;
  reason: string[];
}

export class AdminControls {
  private initialized = false;

  init(): void {
    if (this.initialized) return;

    this.bindTabs();
    this.bindFieldTemplate();
    this.bindConnectionTest();
    this.bindRecommendations();
    this.bindPasswordToggle();
    this.bindCustomFields();
    this.bindConditionalControls();
    this.bindClipboardButtons();
    this.bindLogControls();
    this.bindPerformanceControls();
    this.bindAsyncControls();
    this.bindIndexControls();
    this.initialized = true;
  }

  private bindTabs(): void {
    const buttons = Array.from(
      document.querySelectorAll<HTMLButtonElement>('.notion-wp-menu-item')
    );
    buttons.forEach((button, index) => {
      button.addEventListener('click', () => {
        const tabId = button.dataset.tab;
        if (tabId) this.activateTab(tabId, true);
      });

      button.addEventListener('keydown', event => {
        let targetIndex: number | null = null;
        if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
          targetIndex = (index + 1) % buttons.length;
        } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
          targetIndex = (index - 1 + buttons.length) % buttons.length;
        } else if (event.key === 'Home') {
          targetIndex = 0;
        } else if (event.key === 'End') {
          targetIndex = buttons.length - 1;
        }
        if (targetIndex === null) return;
        event.preventDefault();
        const target = buttons[targetIndex];
        const tabId = target.dataset.tab;
        if (tabId) this.activateTab(tabId, false);
        target.focus();
      });
    });

    const savedTab = window.sessionStorage.getItem('ntwp_active_admin_tab');
    if (savedTab && document.getElementById(savedTab)) {
      this.activateTab(savedTab, false);
    }
  }

  private activateTab(tabId: string, focusPanel: boolean): void {
    const targetPanel = document.getElementById(tabId);
    const targetButton = document.querySelector<HTMLButtonElement>(
      `.notion-wp-menu-item[data-tab="${CSS.escape(tabId)}"]`
    );
    if (!targetPanel || !targetButton) return;

    document
      .querySelectorAll<HTMLButtonElement>('.notion-wp-menu-item')
      .forEach(button => {
        const active = button === targetButton;
        button.classList.toggle('active', active);
        button.setAttribute('aria-selected', String(active));
        button.tabIndex = active ? 0 : -1;
      });
    document
      .querySelectorAll<HTMLElement>('.notion-wp-tab-content')
      .forEach(panel => {
        const active = panel === targetPanel;
        panel.classList.toggle('active', active);
        panel.hidden = !active;
      });

    window.sessionStorage.setItem('ntwp_active_admin_tab', tabId);
    window.history.replaceState(null, '', `#${tabId}`);
    if (focusPanel) {
      targetPanel.setAttribute('tabindex', '-1');
      targetPanel.focus({ preventScroll: true });
    }
  }

  private bindFieldTemplate(): void {
    const template = document.getElementById(
      'field_template'
    ) as HTMLSelectElement | null;
    if (!template) return;

    template.addEventListener('change', () => {
      if (template.value === 'custom') {
        this.activateTab('field-mapping', true);
        document.getElementById('mapping_title')?.focus();
        showInfo('已切换到字段映射，请完成配置后保存设置。');
      }
    });

    const performanceLevel = document.getElementById(
      'performance_level'
    ) as HTMLSelectElement | null;
    performanceLevel?.addEventListener('change', () => {
      if (performanceLevel.value === 'custom') {
        this.activateTab('performance-config', true);
        document.getElementById('api_page_size')?.focus();
        showInfo('已切换到性能配置，请完成参数配置后保存设置。');
      }
    });
  }

  private bindConnectionTest(): void {
    const button = document.getElementById(
      'notion-test-connection'
    ) as HTMLButtonElement | null;
    if (!button) return;

    button.addEventListener('click', async () => {
      const apiKey = (
        document.getElementById(
          'notion_to_wordpress_api_key'
        ) as HTMLInputElement | null
      )?.value.trim();
      const databaseId = (
        document.getElementById(
          'notion_to_wordpress_database_id'
        ) as HTMLInputElement | null
      )?.value.trim();
      if (!apiKey || !databaseId) {
        showError('请输入 API 密钥和数据库 ID。');
        return;
      }

      const original = button.innerHTML;
      button.disabled = true;
      button.textContent = window.notionToWp.i18n.testing || '测试中...';
      try {
        const response = await post<AjaxResponse<{ message: string }>>(
          'notion_to_wordpress_test_connection',
          { api_key: apiKey, database_id: databaseId }
        );
        if (!response.data.success) {
          throw new Error(response.data.data?.message || '连接测试失败');
        }
        showSuccess(response.data.data.message || '连接测试成功');
      } catch (error) {
        showError(this.getErrorMessage(error, '连接测试失败'));
      } finally {
        button.disabled = false;
        button.innerHTML = original;
      }
    });
  }

  private bindRecommendations(): void {
    const button = document.getElementById(
      'get-smart-recommendations'
    ) as HTMLButtonElement | null;
    const container = document.getElementById('recommendations-result');
    if (!button || !container) return;

    button.addEventListener('click', async () => {
      button.disabled = true;
      const original = button.textContent || '获取配置建议';
      button.textContent = '分析中...';
      try {
        const response = await post<AjaxResponse<RecommendationData>>(
          'notion_to_wordpress_get_smart_recommendations',
          {}
        );
        if (!response.data.success) {
          throw new Error(String(response.data.data || '获取推荐配置失败'));
        }
        this.renderRecommendations(container, response.data.data);
      } catch (error) {
        container.replaceChildren();
        container.classList.remove('notion-wp-hidden');
        showError(this.getErrorMessage(error, '获取推荐配置失败'));
      } finally {
        button.disabled = false;
        button.textContent = original;
      }
    });
  }

  private renderRecommendations(
    container: HTMLElement,
    data: RecommendationData
  ): void {
    const notice = document.createElement('div');
    notice.className = 'notice notice-info';
    const heading = document.createElement('h4');
    heading.textContent = '💡 智能推荐结果';
    notice.appendChild(heading);
    notice.appendChild(
      this.createRecommendationLine('推荐性能级别', data.performance_label)
    );
    notice.appendChild(
      this.createRecommendationLine('推荐字段模板', data.field_template_label)
    );

    if (data.reason.length > 0) {
      const list = document.createElement('ul');
      data.reason.forEach(reason => {
        const item = document.createElement('li');
        item.textContent = reason;
        list.appendChild(item);
      });
      notice.appendChild(list);
    }

    const apply = document.createElement('button');
    apply.type = 'button';
    apply.id = 'apply-recommendations';
    apply.className = 'button button-primary notion-wp-apply-recommendations';
    apply.textContent = '应用推荐配置';
    apply.addEventListener('click', () => {
      this.applyRecommendations(data);
      apply.disabled = true;
      apply.textContent = '已应用，请保存设置';
      showSuccess('推荐配置已应用，请点击“保存所有设置”使其生效。');
    });
    notice.appendChild(apply);
    container.replaceChildren(notice);
    container.classList.remove('notion-wp-hidden');
  }

  private createRecommendationLine(
    label: string,
    value: string
  ): HTMLParagraphElement {
    const line = document.createElement('p');
    const strong = document.createElement('strong');
    strong.textContent = `${label}：`;
    line.append(strong, document.createTextNode(value));
    return line;
  }

  private applyRecommendations(data: RecommendationData): void {
    this.setSelectValue('performance_level', data.performance_level);
    this.setSelectValue('field_template', data.field_template);
    Object.entries(data.performance_config).forEach(([name, value]) => {
      this.setControlValue(name, value);
    });
    Object.entries(data.field_mapping).forEach(([name, value]) => {
      this.setControlValue(`field_mapping[${name}]`, value);
    });
  }

  private setSelectValue(id: string, value: string): void {
    const select = document.getElementById(id) as HTMLSelectElement | null;
    if (
      !select ||
      !Array.from(select.options).some(option => option.value === value)
    )
      return;
    select.value = value;
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }

  private setControlValue(name: string, value: string | number): void {
    const control = document.querySelector<
      HTMLInputElement | HTMLSelectElement
    >(`[name="${CSS.escape(name)}"]`);
    if (!control) return;
    if (control instanceof HTMLInputElement && control.type === 'checkbox') {
      control.checked = Boolean(Number(value));
    } else {
      control.value = String(value);
    }
    control.dispatchEvent(new Event('change', { bubbles: true }));
  }

  private bindPasswordToggle(): void {
    document
      .querySelectorAll<HTMLButtonElement>('.show-hide-password')
      .forEach(button => {
        button.addEventListener('click', () => {
          const input =
            button.parentElement?.querySelector<HTMLInputElement>('input');
          if (!input) return;
          const reveal = input.type === 'password';
          input.type = reveal ? 'text' : 'password';
          button.setAttribute('aria-pressed', String(reveal));
        });
      });
  }

  private bindCustomFields(): void {
    const container = document.getElementById('custom-field-mappings');
    const addButton = document.getElementById('add-custom-field');
    if (!container || !addButton) return;

    const reindex = () => {
      const rows = Array.from(
        container.querySelectorAll<HTMLElement>('.custom-field-mapping')
      );
      rows.forEach((row, index) => {
        row
          .querySelectorAll<HTMLInputElement | HTMLSelectElement>('[name]')
          .forEach(control => {
            control.name = control.name.replace(/\[\d+\]/, `[${index}]`);
          });
      });
      rows.forEach(row => {
        row
          .querySelector<HTMLElement>('.remove-field')
          ?.classList.toggle('notion-wp-hidden', rows.length === 1);
      });
    };

    addButton.addEventListener('click', () => {
      const template = container.querySelector<HTMLElement>(
        '.custom-field-mapping'
      );
      if (!template) return;
      const clone = template.cloneNode(true) as HTMLElement;
      clone
        .querySelectorAll<HTMLInputElement>('input')
        .forEach(input => (input.value = ''));
      clone
        .querySelectorAll<HTMLSelectElement>('select')
        .forEach(select => (select.selectedIndex = 0));
      container.appendChild(clone);
      reindex();
    });

    container.addEventListener('click', event => {
      const removeButton = (event.target as Element).closest('.remove-field');
      if (!removeButton) return;
      const rows = container.querySelectorAll('.custom-field-mapping');
      if (rows.length <= 1) return;
      removeButton.closest('.custom-field-mapping')?.remove();
      reindex();
    });
    reindex();
  }

  private bindConditionalControls(): void {
    const provider = document.getElementById(
      'cdn_provider'
    ) as HTMLSelectElement | null;
    const customUrl = document.getElementById(
      'custom_cdn_url'
    ) as HTMLInputElement | null;
    const updateCdn = () => {
      if (!provider || !customUrl) return;
      const custom = provider.value === 'custom';
      customUrl.classList.toggle('notion-wp-hidden', !custom);
      customUrl.required = custom;
    };
    provider?.addEventListener('change', updateCdn);
    updateCdn();
  }

  private bindClipboardButtons(): void {
    document
      .querySelectorAll<HTMLButtonElement>('.copy-to-clipboard')
      .forEach(button => {
        button.addEventListener('click', async () => {
          const selector = button.dataset.clipboardTarget;
          const target = selector
            ? document.querySelector<HTMLInputElement>(selector)
            : null;
          if (!target?.value) {
            showError('没有可复制的内容。');
            return;
          }
          try {
            await navigator.clipboard.writeText(target.value);
            showSuccess('已复制到剪贴板。');
          } catch {
            target.select();
            document.execCommand('copy');
            showSuccess('已复制到剪贴板。');
          }
        });
      });
  }

  private bindLogControls(): void {
    const selector = document.getElementById(
      'log-file-selector'
    ) as HTMLSelectElement | null;
    const viewer = document.getElementById(
      'log-viewer'
    ) as HTMLTextAreaElement | null;
    document
      .getElementById('view-log-button')
      ?.addEventListener('click', () => {
        if (!selector?.value || !viewer) {
          showError('请先选择日志文件。');
          return;
        }
        void this.runAjaxAction<string>(
          'notion_to_wordpress_view_log',
          { file: selector.value },
          data => {
            viewer.value = data;
          }
        );
      });
    document
      .getElementById('clear-logs-button')
      ?.addEventListener('click', () => {
        if (!window.confirm('确定要清除所有日志文件吗？此操作不可恢复。'))
          return;
        void this.runAjaxAction<{ message: string }>(
          'notion_to_wordpress_clear_logs',
          {},
          data => {
            if (viewer) viewer.value = '';
            selector?.replaceChildren();
            showSuccess(data.message || '日志已清除。');
          }
        );
      });
  }

  private bindPerformanceControls(): void {
    this.bindActionButton(
      'refresh-performance-stats',
      'notion_to_wordpress_refresh_performance_stats',
      '性能统计已刷新。'
    );
    this.bindActionButton(
      'reset-performance-stats',
      'notion_to_wordpress_reset_performance_stats',
      '性能统计已重置。',
      '确定要重置性能统计吗？'
    );
  }

  private bindAsyncControls(): void {
    document
      .getElementById('refresh-async-status')
      ?.addEventListener('click', () => {
        void this.refreshAsyncStatus();
      });
    this.bindActionButton(
      'cleanup-queue',
      'notion_to_wordpress_cleanup_queue',
      '队列清理完成。',
      '确定要清理已完成和过期的队列任务吗？'
    );
  }

  private async refreshAsyncStatus(): Promise<void> {
    await this.runAjaxAction<{
      status: { status?: string };
      message: string;
    }>('notion_to_wordpress_get_async_status', {}, data => {
      const status = data.status?.status || 'idle';
      const labels: Record<string, string> = {
        idle: '空闲',
        running: '运行中',
        paused: '已暂停',
        error: '错误',
      };
      const value = document.querySelector<HTMLElement>(
        '#async-status-container .status-value'
      );
      if (value) value.textContent = labels[status] || status;
      showSuccess(data.message || '异步状态已刷新。');
    });
  }

  private bindIndexControls(): void {
    document
      .getElementById('refresh-index-status')
      ?.addEventListener('click', () => {
        void this.refreshIndexStatus();
      });
    document
      .getElementById('optimize-all-indexes')
      ?.addEventListener('click', () => {
        void this.runAjaxAction<{ message: string }>(
          'notion_to_wordpress_optimize_all_indexes',
          {},
          data => {
            showSuccess(data.message);
            void this.refreshIndexStatus();
          }
        );
      });
    document
      .getElementById('remove-database-indexes')
      ?.addEventListener('click', () => {
        if (!window.confirm('确定要删除插件创建的数据库索引吗？')) return;
        void this.runAjaxAction<{ message: string }>(
          'notion_to_wordpress_remove_database_indexes',
          {},
          data => {
            showSuccess(data.message);
            void this.refreshIndexStatus();
          }
        );
      });
    void this.refreshIndexStatus(false);
  }

  private async refreshIndexStatus(showNotice = true): Promise<void> {
    await this.runAjaxAction<{
      status: Record<string, unknown>;
      message: string;
    }>('notion_to_wordpress_get_index_status', {}, data => {
      const container = document.getElementById('index-status-container');
      if (container) {
        const entries = Object.entries(data.status || {});
        const list = document.createElement('ul');
        entries.forEach(([name, value]) => {
          const item = document.createElement('li');
          item.textContent = `${name}: ${this.formatStatusValue(value)}`;
          list.appendChild(item);
        });
        container.replaceChildren(list);
      }
      if (showNotice) showSuccess(data.message || '索引状态已刷新。');
    });
  }

  private formatStatusValue(value: unknown): string {
    if (typeof value === 'boolean') return value ? '已创建' : '未创建';
    if (value === null || value === undefined) return '-';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
  }

  private bindActionButton(
    buttonId: string,
    action: string,
    successMessage: string,
    confirmation?: string
  ): void {
    const button = document.getElementById(
      buttonId
    ) as HTMLButtonElement | null;
    button?.addEventListener('click', () => {
      if (confirmation && !window.confirm(confirmation)) return;
      void this.runAjaxAction<unknown>(
        action,
        {},
        () => {
          showSuccess(successMessage);
        },
        button
      );
    });
  }

  private async runAjaxAction<T>(
    action: string,
    data: Record<string, unknown>,
    onSuccess: (data: T) => void,
    button?: HTMLButtonElement
  ): Promise<void> {
    if (button) button.disabled = true;
    try {
      const response = await post<AjaxResponse<T>>(action, data);
      if (!response.data.success) {
        throw new Error(this.extractMessage(response.data.data, '操作失败'));
      }
      onSuccess(response.data.data);
    } catch (error) {
      showError(this.getErrorMessage(error, '操作失败'));
    } finally {
      if (button) button.disabled = false;
    }
  }

  private extractMessage(data: unknown, fallback: string): string {
    if (typeof data === 'string') return data;
    if (data && typeof data === 'object' && 'message' in data) {
      return String((data as { message?: unknown }).message || fallback);
    }
    return fallback;
  }

  private getErrorMessage(error: unknown, fallback: string): string {
    if (error instanceof Error) {
      const response = (
        error as Error & { response?: AjaxResponse<{ message?: string }> }
      ).response;
      return response?.data?.message || error.message || fallback;
    }
    return fallback;
  }
}

export const adminControls = new AdminControls();
