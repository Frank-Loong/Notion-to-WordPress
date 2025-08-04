/**
 * 表单组件
 */

import { BaseComponent, ComponentOptions } from './BaseComponent';
import { isValidEmail, isValidUrl } from '../../shared/utils/validation';
import { addClass, removeClass } from '../../shared/utils/dom';
import { showSuccess, showError } from '../../shared/utils/toast';
import { throttle } from '../../shared/utils/dom';

export interface FormComponentOptions extends ComponentOptions {
  validateOnInput?: boolean;
  validateOnBlur?: boolean;
  submitOnEnter?: boolean;
  autoSave?: boolean;
  autoSaveDelay?: number;
}

export interface ValidationRule {
  type: 'required' | 'email' | 'url' | 'custom';
  message?: string;
  validator?: (value: string) => boolean | string;
}

export interface FormField {
  name: string;
  element: HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;
  rules: ValidationRule[];
  isValid: boolean;
  errorMessage: string;
}

/**
 * 表单组件
 */
export class FormComponent extends BaseComponent {
  private formOptions: FormComponentOptions;
  private fields: Map<string, FormField> = new Map();
  private isSubmitting: boolean = false;
  private autoSaveTimer: NodeJS.Timeout | null = null;
  private throttledValidate: (field: FormField) => void;

  constructor(options: FormComponentOptions) {
    super(options);
    this.formOptions = {
      validateOnInput: true,
      validateOnBlur: true,
      submitOnEnter: false,
      autoSave: false,
      autoSaveDelay: 2000,
      ...options
    };

    this.throttledValidate = throttle(this.validateField.bind(this), 300);
  }

  protected onInit(): void {
    if (this.element) {
      addClass(this.element, 'form-component');
      this.discoverFields();
    }
  }

  protected onMount(): void {
    this.setupValidation();
  }

  protected onUnmount(): void {
    this.clearAutoSaveTimer();
  }

  protected onDestroy(): void {
    this.clearAutoSaveTimer();
    this.fields.clear();
  }

  protected onRender(): void {
    this.updateFieldStates();
  }

  protected bindEvents(): void {
    if (!this.element) return;

    // 绑定表单提交事件
    this.addEventListener(this.element, 'submit', this.handleSubmit.bind(this));

    // 绑定字段事件
    this.fields.forEach((field, _name) => {
      if (this.formOptions.validateOnInput) {
        this.addEventListener(field.element, 'input', () => this.throttledValidate(field));
      }

      if (this.formOptions.validateOnBlur) {
        this.addEventListener(field.element, 'blur', () => this.validateField(field));
      }

      if (this.formOptions.submitOnEnter) {
        this.addEventListener(field.element, 'keydown', (event: Event) => {
          const keyEvent = event as KeyboardEvent;
          if (keyEvent.key === 'Enter' && !keyEvent.shiftKey) {
            keyEvent.preventDefault();
            this.submit();
          }
        });
      }

      if (this.formOptions.autoSave) {
        this.addEventListener(field.element, 'input', () => this.scheduleAutoSave());
      }
    });
  }

  protected onStateChange(_state: any, _prevState: any, _action: any): void {
    // 可以根据应用状态变化更新表单
  }

  /**
   * 发现表单字段
   */
  private discoverFields(): void {
    if (!this.element) return;

    const inputs = this.element.querySelectorAll('input, select, textarea');
    
    inputs.forEach(input => {
      const element = input as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;
      const name = element.name || element.id;
      
      if (name) {
        const rules = this.parseValidationRules(element);
        
        this.fields.set(name, {
          name,
          element,
          rules,
          isValid: true,
          errorMessage: ''
        });
      }
    });
  }

  /**
   * 解析验证规则
   */
  private parseValidationRules(element: HTMLElement): ValidationRule[] {
    const rules: ValidationRule[] = [];
    
    // 从data属性解析规则
    const validationAttr = element.getAttribute('data-validation');
    if (validationAttr) {
      const ruleTypes = validationAttr.split('|');
      
      ruleTypes.forEach(ruleType => {
        switch (ruleType) {
          case 'required':
            rules.push({ type: 'required', message: '此字段为必填项' });
            break;
          case 'email':
            rules.push({ type: 'email', message: '请输入有效的邮箱地址' });
            break;
          case 'url':
            rules.push({ type: 'url', message: '请输入有效的URL地址' });
            break;
        }
      });
    }

    // 从HTML5属性解析规则
    if (element.hasAttribute('required')) {
      rules.push({ type: 'required', message: '此字段为必填项' });
    }

    if (element.getAttribute('type') === 'email') {
      rules.push({ type: 'email', message: '请输入有效的邮箱地址' });
    }

    if (element.getAttribute('type') === 'url') {
      rules.push({ type: 'url', message: '请输入有效的URL地址' });
    }

    return rules;
  }

  /**
   * 设置验证
   */
  private setupValidation(): void {
    this.fields.forEach(field => {
      this.createValidationFeedback(field);
    });
  }

  /**
   * 创建验证反馈元素
   */
  private createValidationFeedback(field: FormField): void {
    const container = field.element.closest('.form-field') || field.element.parentElement;
    if (!container) return;

    // 检查是否已存在反馈元素
    let feedback = container.querySelector('.validation-feedback') as HTMLElement;
    
    if (!feedback) {
      feedback = document.createElement('div');
      feedback.className = 'validation-feedback';
      container.appendChild(feedback);
    }

    field.element.setAttribute('data-feedback-id', feedback.id || `feedback-${field.name}`);
  }

  /**
   * 验证字段
   */
  private validateField(field: FormField): boolean {
    const value = field.element.value.trim();
    let isValid = true;
    let errorMessage = '';

    for (const rule of field.rules) {
      const result = this.applyValidationRule(value, rule);
      
      if (result !== true) {
        isValid = false;
        errorMessage = typeof result === 'string' ? result : rule.message || '验证失败';
        break;
      }
    }

    field.isValid = isValid;
    field.errorMessage = errorMessage;

    this.updateFieldValidationState(field);
    
    return isValid;
  }

  /**
   * 应用验证规则
   */
  private applyValidationRule(value: string, rule: ValidationRule): boolean | string {
    switch (rule.type) {
      case 'required':
        return value.trim().length > 0 || rule.message || '此字段为必填项';
      case 'email':
        return !value || isValidEmail(value) || rule.message || '请输入有效的邮箱地址';
      case 'url':
        return !value || isValidUrl(value) || rule.message || '请输入有效的URL地址';
      case 'custom':
        return rule.validator ? rule.validator(value) : true;
      default:
        return true;
    }
  }

  /**
   * 更新字段验证状态
   */
  private updateFieldValidationState(field: FormField): void {
    const { element, isValid, errorMessage } = field;
    
    // 更新字段样式
    removeClass(element, 'valid', 'invalid');
    addClass(element, isValid ? 'valid' : 'invalid');

    // 更新反馈信息
    const container = element.closest('.form-field') || element.parentElement;
    const feedback = container?.querySelector('.validation-feedback') as HTMLElement;
    
    if (feedback) {
      feedback.textContent = errorMessage;
      removeClass(feedback, 'success', 'error');
      addClass(feedback, isValid ? 'success' : 'error');
    }
  }

  /**
   * 更新所有字段状态
   */
  private updateFieldStates(): void {
    this.fields.forEach(field => {
      this.updateFieldValidationState(field);
    });
  }

  /**
   * 验证整个表单
   */
  private validateForm(): boolean {
    let isFormValid = true;

    this.fields.forEach(field => {
      const isFieldValid = this.validateField(field);
      if (!isFieldValid) {
        isFormValid = false;
      }
    });

    return isFormValid;
  }

  /**
   * 处理表单提交
   */
  private async handleSubmit(event: Event): Promise<void> {
    event.preventDefault();

    if (this.isSubmitting) {
      return;
    }

    // 验证表单
    if (!this.validateForm()) {
      showError('请修正表单中的错误');
      return;
    }

    this.isSubmitting = true;
    this.setSubmitButtonState(true);

    try {
      await this.submit();
    } catch (error) {
      console.error('Form submit error:', error);
      showError(`提交失败: ${(error as Error).message}`);
    } finally {
      this.isSubmitting = false;
      this.setSubmitButtonState(false);
    }
  }

  /**
   * 提交表单
   */
  private async submit(): Promise<void> {
    const formData = this.getFormData();
    
    // 发送提交事件
    this.emit('form:submit', { formData, component: this });

    // 如果是HTML表单，使用AJAX提交
    if (this.element instanceof HTMLFormElement) {
      await this.submitViaAjax(formData);
    }
  }

  /**
   * 通过AJAX提交表单
   */
  private async submitViaAjax(formData: FormData): Promise<void> {
    const form = this.element as HTMLFormElement;
    const action = form.action || window.location.href;
    const method = form.method || 'POST';

    const response = await fetch(action, {
      method,
      body: formData
    });

    const result = await response.json();

    if (result.success) {
      showSuccess(result.data?.message || '保存成功');
      this.emit('form:success', { result, component: this });
    } else {
      throw new Error(result.data?.message || '提交失败');
    }
  }

  /**
   * 获取表单数据
   */
  private getFormData(): FormData {
    const formData = new FormData();

    this.fields.forEach(field => {
      const { name, element } = field;
      
      if (element instanceof HTMLInputElement) {
        if (element.type === 'checkbox' || element.type === 'radio') {
          if (element.checked) {
            formData.append(name, element.value);
          }
        } else if (element.type === 'file') {
          if (element.files) {
            Array.from(element.files).forEach(file => {
              formData.append(name, file);
            });
          }
        } else {
          formData.append(name, element.value);
        }
      } else {
        formData.append(name, element.value);
      }
    });

    return formData;
  }

  /**
   * 设置提交按钮状态
   */
  private setSubmitButtonState(loading: boolean): void {
    const submitButton = this.$('button[type="submit"], input[type="submit"]') as HTMLButtonElement;
    
    if (submitButton) {
      if (loading) {
        submitButton.disabled = true;
        addClass(submitButton, 'loading');
        
        const originalText = submitButton.textContent || submitButton.value;
        submitButton.setAttribute('data-original-text', originalText);
        
        if (submitButton.tagName === 'BUTTON') {
          submitButton.innerHTML = '<span class="spinner is-active"></span> 保存中...';
        } else {
          submitButton.value = '保存中...';
        }
      } else {
        submitButton.disabled = false;
        removeClass(submitButton, 'loading');
        
        const originalText = submitButton.getAttribute('data-original-text');
        if (originalText) {
          if (submitButton.tagName === 'BUTTON') {
            submitButton.textContent = originalText;
          } else {
            submitButton.value = originalText;
          }
        }
      }
    }
  }

  /**
   * 安排自动保存
   */
  private scheduleAutoSave(): void {
    this.clearAutoSaveTimer();
    
    this.autoSaveTimer = setTimeout(() => {
      this.autoSave();
    }, this.formOptions.autoSaveDelay);
  }

  /**
   * 自动保存
   */
  private async autoSave(): Promise<void> {
    if (!this.validateForm()) {
      return;
    }

    try {
      const formData = this.getFormData();
      this.emit('form:autosave', { formData, component: this });
    } catch (error) {
      console.error('Auto save error:', error);
    }
  }

  /**
   * 清除自动保存定时器
   */
  private clearAutoSaveTimer(): void {
    if (this.autoSaveTimer) {
      clearTimeout(this.autoSaveTimer);
      this.autoSaveTimer = null;
    }
  }

  /**
   * 添加验证规则
   */
  public addValidationRule(fieldName: string, rule: ValidationRule): void {
    const field = this.fields.get(fieldName);
    if (field) {
      field.rules.push(rule);
    }
  }

  /**
   * 移除验证规则
   */
  public removeValidationRule(fieldName: string, ruleType: string): void {
    const field = this.fields.get(fieldName);
    if (field) {
      field.rules = field.rules.filter(rule => rule.type !== ruleType);
    }
  }

  /**
   * 获取字段值
   */
  public getFieldValue(fieldName: string): string {
    const field = this.fields.get(fieldName);
    return field ? field.element.value : '';
  }

  /**
   * 设置字段值
   */
  public setFieldValue(fieldName: string, value: string): void {
    const field = this.fields.get(fieldName);
    if (field) {
      field.element.value = value;
      this.validateField(field);
    }
  }

  /**
   * 重置表单
   */
  public reset(): void {
    if (this.element instanceof HTMLFormElement) {
      this.element.reset();
    }

    this.fields.forEach(field => {
      field.isValid = true;
      field.errorMessage = '';
      this.updateFieldValidationState(field);
    });
  }

  /**
   * 检查表单是否有效
   */
  public isValid(): boolean {
    return Array.from(this.fields.values()).every(field => field.isValid);
  }
}
