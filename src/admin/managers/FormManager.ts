/**
 * 表单管理器 - 现代化TypeScript版本
 *
 * 从原有admin-interactions.js的表单处理功能完全迁移，包括：
 * - 表单验证和提交
 * - AJAX表单处理
 * - 设置保存和更新
 * - 验证令牌刷新
 */

import { emit } from '../../shared/core/EventBus';
import { AdminUtils } from '../utils/AdminUtils';
import { showSuccess, showError, showInfo } from '../../shared/utils/toast';
import { post } from '../../shared/utils/ajax';

export interface FormSubmitOptions {
  validateBeforeSubmit?: boolean;
  showLoadingState?: boolean;
  successMessage?: string;
  errorMessage?: string;
}

export interface FormFieldConfig {
  name: string;
  type:
    | 'text'
    | 'email'
    | 'password'
    | 'checkbox'
    | 'select'
    | 'api-key'
    | 'database-id';
  required?: boolean;
  validation?: (value: string) => { isValid: boolean; message: string };
}

/**
 * 表单管理器类
 */
export class FormManager {
  private static instance: FormManager | null = null;
  private forms: Map<string, HTMLFormElement> = new Map();
  private originalValues: Map<string, Record<string, any>> = new Map();

  constructor() {
    if (FormManager.instance) {
      return FormManager.instance;
    }
    FormManager.instance = this;
    this.init();
  }

  /**
   * 获取单例实例
   */
  static getInstance(): FormManager {
    if (!FormManager.instance) {
      FormManager.instance = new FormManager();
    }
    return FormManager.instance;
  }

  /**
   * 初始化表单管理器
   */
  private init(): void {
    this.setupFormHandlers();
    this.setupValidationHandlers();
    this.setupSpecialHandlers();

    console.log('📝 [表单管理器] 已初始化');
  }

  /**
   * 设置表单处理器
   */
  private setupFormHandlers(): void {
    // 主设置表单
    const settingsForm = document.getElementById(
      'notion-to-wordpress-settings-form'
    ) as HTMLFormElement;
    if (settingsForm) {
      this.registerForm('settings', settingsForm);
      this.storeOriginalValues('settings', settingsForm);

      settingsForm.addEventListener('submit', e => {
        e.preventDefault();
        this.handleSettingsFormSubmit(settingsForm);
      });
    }

    // 其他表单可以在这里添加
  }

  /**
   * 设置验证处理器
   */
  private setupValidationHandlers(): void {
    // API密钥验证
    const apiKeyInput = document.getElementById(
      'notion_to_wordpress_api_key'
    ) as HTMLInputElement;
    if (apiKeyInput) {
      const debouncedValidation = AdminUtils.debounce(() => {
        AdminUtils.validateInput(apiKeyInput, 'api-key');
      }, 500);

      apiKeyInput.addEventListener('input', debouncedValidation);
      apiKeyInput.addEventListener('blur', debouncedValidation);
    }

    // 数据库ID验证
    const dbIdInput = document.getElementById(
      'notion_to_wordpress_database_id'
    ) as HTMLInputElement;
    if (dbIdInput) {
      const debouncedValidation = AdminUtils.debounce(() => {
        AdminUtils.validateInput(dbIdInput, 'database-id');
      }, 500);

      dbIdInput.addEventListener('input', debouncedValidation);
      dbIdInput.addEventListener('blur', debouncedValidation);
    }
  }

  /**
   * 设置特殊处理器
   */
  private setupSpecialHandlers(): void {
    // 自动导入选项切换
    const autoImportCheckbox = document.getElementById(
      'notion_to_wordpress_auto_import'
    ) as HTMLInputElement;
    if (autoImportCheckbox) {
      const scheduleField = document.getElementById(
        'auto_import_schedule_field'
      );

      const toggleScheduleField = AdminUtils.debounce(() => {
        if (scheduleField) {
          if (autoImportCheckbox.checked) {
            scheduleField.style.display = 'block';
          } else {
            scheduleField.style.display = 'none';
          }
        }
      }, 200);

      autoImportCheckbox.addEventListener('change', toggleScheduleField);

      // 初始状态
      toggleScheduleField();
    }

    // 验证令牌刷新
    const refreshTokenButton = document.getElementById(
      'refresh-verification-token'
    ) as HTMLButtonElement;
    if (refreshTokenButton) {
      const debouncedRefresh = AdminUtils.debounce((e: Event) => {
        e.preventDefault();
        this.handleRefreshVerificationToken(refreshTokenButton);
      }, 500);

      refreshTokenButton.addEventListener('click', debouncedRefresh);
    }
  }

  /**
   * 注册表单
   */
  registerForm(id: string, form: HTMLFormElement): void {
    this.forms.set(id, form);
    emit('form:registered', { id, form });
  }

  /**
   * 存储表单原始值
   */
  private storeOriginalValues(formId: string, form: HTMLFormElement): void {
    const formData = new FormData(form);
    const values: Record<string, any> = {};

    for (const [key, value] of formData.entries()) {
      values[key] = value;
    }

    this.originalValues.set(formId, values);
  }

  /**
   * 检查表单是否有变更
   */
  hasFormChanged(formId: string): boolean {
    const form = this.forms.get(formId);
    const originalValues = this.originalValues.get(formId);

    if (!form || !originalValues) return false;

    const currentFormData = new FormData(form);

    for (const [key, originalValue] of Object.entries(originalValues)) {
      const currentValue = currentFormData.get(key);
      if (currentValue !== originalValue) {
        return true;
      }
    }

    return false;
  }

  /**
   * 处理设置表单提交
   */
  private async handleSettingsFormSubmit(form: HTMLFormElement): Promise<void> {
    const submitButton = document.getElementById(
      'notion-save-settings'
    ) as HTMLButtonElement;

    if (!submitButton) {
      showError('无法找到保存按钮，请刷新页面重试');
      return;
    }

    // 防止重复提交
    if (submitButton.disabled) {
      return;
    }

    // 基础验证
    const apiKeyInput = document.getElementById(
      'notion_to_wordpress_api_key'
    ) as HTMLInputElement;
    const dbIdInput = document.getElementById(
      'notion_to_wordpress_database_id'
    ) as HTMLInputElement;

    if (!apiKeyInput?.value || !dbIdInput?.value) {
      showError('请填写必填字段');

      if (!apiKeyInput?.value) apiKeyInput?.classList.add('error');
      if (!dbIdInput?.value) dbIdInput?.classList.add('error');

      setTimeout(() => {
        document
          .querySelectorAll('.error')
          .forEach(el => el.classList.remove('error'));
      }, 2000);

      return;
    }

    // 获取原始值用于比较
    const originalValues = this.originalValues.get('settings') || {};
    const newLanguage = (
      document.getElementById('plugin_language') as HTMLSelectElement
    )?.value;
    const newWebhookEnabled = (
      document.getElementById('webhook_enabled') as HTMLInputElement
    )?.checked;

    // 设置加载状态
    AdminUtils.setButtonLoading(submitButton, true, {
      loadingText: '保存中...',
    });

    try {
      // 准备表单数据
      const formData = new FormData(form);
      formData.set('action', 'notion_to_wordpress_save_settings');

      // 确保nonce字段存在
      const nonceField = form.querySelector(
        'input[name="notion_to_wordpress_options_nonce"]'
      ) as HTMLInputElement;
      if (nonceField && !formData.has('notion_to_wordpress_options_nonce')) {
        formData.set('notion_to_wordpress_options_nonce', nonceField.value);
      }

      // 提交表单
      const response = await this.submitFormData(formData);

      if (response.success) {
        // 检查是否需要刷新页面
        const languageChanged = originalValues.plugin_language !== newLanguage;
        const webhookChanged =
          originalValues.webhook_enabled !== newWebhookEnabled;
        const needsRefresh = languageChanged || webhookChanged;

        if (needsRefresh) {
          const refreshReasons = [];
          if (languageChanged) refreshReasons.push('语言设置');
          if (webhookChanged) refreshReasons.push('Webhook设置');

          showSuccess(
            `设置保存成功！页面即将刷新以应用${refreshReasons.join('和')}变更...`
          );

          setTimeout(() => {
            window.location.reload();
          }, 2000);
        } else {
          showSuccess(response.data?.message || '设置保存成功');

          // 更新原始值
          this.storeOriginalValues('settings', form);
        }

        emit('form:settings:saved', { response, needsRefresh });
      } else {
        throw new Error(response.data?.message || '保存失败');
      }
    } catch (error) {
      console.error('❌ [表单提交] 失败:', error);
      showError(`保存失败: ${(error as Error).message}`);
      emit('form:settings:error', error);
    } finally {
      AdminUtils.setButtonLoading(submitButton, false);
    }
  }

  /**
   * 提交表单数据
   */
  private async submitFormData(formData: FormData): Promise<any> {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();

      xhr.open(
        'POST',
        (window as any).notionToWp?.ajax_url || '/wp-admin/admin-ajax.php'
      );

      xhr.onload = () => {
        try {
          const response = JSON.parse(xhr.responseText);
          resolve(response);
        } catch {
          reject(new Error('响应解析失败'));
        }
      };

      xhr.onerror = () => {
        reject(new Error('网络请求失败'));
      };

      xhr.ontimeout = () => {
        reject(new Error('请求超时'));
      };

      xhr.timeout = 30000; // 30秒超时
      xhr.send(formData);
    });
  }

  /**
   * 处理验证令牌刷新
   */
  private async handleRefreshVerificationToken(
    button: HTMLButtonElement
  ): Promise<void> {
    if (button.disabled) return;

    const tokenInput = document.getElementById(
      'verification_token'
    ) as HTMLInputElement;

    AdminUtils.setButtonLoading(button, true, { loadingText: '刷新中...' });

    try {
      const response = await post(
        'notion_to_wordpress_refresh_verification_token',
        {}
      );

      if (response.data.success) {
        if (tokenInput) {
          tokenInput.value = response.data.data.verification_token || '';
        }

        if (response.data.data.verification_token) {
          showSuccess(response.data.data.message || '验证令牌已更新');
        } else {
          showInfo('没有新的验证令牌');
        }

        emit('form:token:refreshed', response.data.data);
      } else {
        throw new Error(response.data.data.message || '刷新失败');
      }
    } catch (error) {
      console.error('❌ [令牌刷新] 失败:', error);
      showError(`刷新失败: ${(error as Error).message}`);
      emit('form:token:error', error);
    } finally {
      AdminUtils.setButtonLoading(button, false);
    }
  }

  /**
   * 验证表单
   */
  validateForm(formId: string): boolean {
    const form = this.forms.get(formId);
    if (!form) return false;

    let isValid = true;
    const inputs = form.querySelectorAll(
      'input[required], select[required], textarea[required]'
    );

    inputs.forEach(input => {
      const element = input as HTMLInputElement;
      if (!element.value.trim()) {
        element.classList.add('error');
        isValid = false;
      } else {
        element.classList.remove('error');
      }
    });

    return isValid;
  }

  /**
   * 重置表单
   */
  resetForm(formId: string): void {
    const form = this.forms.get(formId);
    if (form) {
      form.reset();

      // 清除验证状态
      form
        .querySelectorAll('.error, .valid, .invalid, .warning')
        .forEach(el => {
          el.classList.remove('error', 'valid', 'invalid', 'warning');
        });

      emit('form:reset', { formId, form });
    }
  }

  /**
   * 获取表单数据
   */
  getFormData(formId: string): Record<string, any> | null {
    const form = this.forms.get(formId);
    if (!form) return null;

    const formData = new FormData(form);
    const data: Record<string, any> = {};

    for (const [key, value] of formData.entries()) {
      data[key] = value;
    }

    return data;
  }

  /**
   * 设置表单数据
   */
  setFormData(formId: string, data: Record<string, any>): void {
    const form = this.forms.get(formId);
    if (!form) return;

    Object.entries(data).forEach(([key, value]) => {
      const input = form.querySelector(`[name="${key}"]`) as HTMLInputElement;
      if (input) {
        if (input.type === 'checkbox') {
          input.checked = Boolean(value);
        } else {
          input.value = String(value);
        }
      }
    });

    emit('form:data:set', { formId, data });
  }

  /**
   * 销毁表单管理器
   */
  destroy(): void {
    this.forms.clear();
    this.originalValues.clear();
    FormManager.instance = null;
    console.log('📝 [表单管理器] 已销毁');
  }
}

// 导出单例实例
export const formManager = FormManager.getInstance();
