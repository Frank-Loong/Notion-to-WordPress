/**
 * 设置模块 - 懒加载
 */

import { BaseComponent } from '../components/BaseComponent';
import { FormComponent } from '../components/FormComponent';
import { showSuccess, showError } from '../../shared/utils/toast';
import { post } from '../../shared/utils/ajax';

export interface SettingsData {
  api_key: string;
  database_id: string;
  sync_interval: number;
  auto_sync: boolean;
  delete_protection: boolean;
  image_optimization: boolean;
  cache_enabled: boolean;
  debug_mode: boolean;
}

/**
 * 设置模块类
 */
export class SettingsModule extends BaseComponent {
  private formComponent: FormComponent | null = null;
  private settings: SettingsData | null = null;

  protected onInit(): void {
    console.log('Settings module initialized');
  }

  protected onMount(): void {
    this.initializeForm();
    this.loadSettings();
  }

  protected onUnmount(): void {
    if (this.formComponent) {
      this.formComponent.destroy();
    }
  }

  protected onDestroy(): void {
    // 清理资源
  }

  protected onRender(): void {
    this.updateSettingsDisplay();
  }

  protected bindEvents(): void {
    // 绑定保存按钮事件
    const saveButton = this.$('#save-settings');
    if (saveButton) {
      this.addEventListener(saveButton, 'click', this.handleSave.bind(this));
    }

    // 绑定重置按钮事件
    const resetButton = this.$('#reset-settings');
    if (resetButton) {
      this.addEventListener(resetButton, 'click', this.handleReset.bind(this));
    }

    // 绑定测试连接按钮事件
    const testButton = this.$('#test-connection');
    if (testButton) {
      this.addEventListener(testButton, 'click', this.handleTestConnection.bind(this));
    }
  }

  protected onStateChange(_state: any, _prevState: any, _action: any): void {
    // 响应状态变化
  }

  /**
   * 初始化表单
   */
  private initializeForm(): void {
    const formElement = this.$('#settings-form');
    if (formElement) {
      this.formComponent = new FormComponent({
        element: formElement as HTMLFormElement,
        validateOnInput: true,
        validateOnBlur: true,
        autoSave: true,
        autoSaveDelay: 3000
      });

      // 监听表单事件
      this.on('form:submit', this.handleFormSubmit.bind(this));
      this.on('form:autosave', this.handleAutoSave.bind(this));
    }
  }

  /**
   * 加载设置
   */
  private async loadSettings(): Promise<void> {
    try {
      const response = await post('notion_to_wordpress_get_settings', {});
      
      if (response.data.success) {
        this.settings = response.data.data;
        this.populateForm();
        console.log('Settings loaded successfully');
      } else {
        throw new Error(response.data.message || '加载设置失败');
      }
    } catch (error) {
      console.error('Failed to load settings:', error);
      showError(`加载设置失败: ${(error as Error).message}`);
    }
  }

  /**
   * 填充表单
   */
  private populateForm(): void {
    if (!this.settings || !this.formComponent) return;

    Object.entries(this.settings).forEach(([key, value]) => {
      this.formComponent!.setFieldValue(key, String(value));
    });
  }

  /**
   * 更新设置显示
   */
  private updateSettingsDisplay(): void {
    if (!this.settings) return;

    // 更新连接状态显示
    const statusElement = this.$('#connection-status');
    if (statusElement) {
      const hasCredentials = this.settings.api_key && this.settings.database_id;
      statusElement.textContent = hasCredentials ? '已配置' : '未配置';
      statusElement.className = `status ${hasCredentials ? 'connected' : 'disconnected'}`;
    }

    // 更新同步间隔显示
    const intervalElement = this.$('#sync-interval-display');
    if (intervalElement) {
      intervalElement.textContent = `${this.settings.sync_interval} 分钟`;
    }
  }

  /**
   * 处理保存
   */
  private async handleSave(event: Event): Promise<void> {
    event.preventDefault();

    if (!this.formComponent || !this.formComponent.isValid()) {
      showError('请修正表单中的错误');
      return;
    }

    const formData = this.getFormData();
    await this.saveSettings(formData);
  }

  /**
   * 处理表单提交
   */
  private async handleFormSubmit(_event: any, data: any): Promise<void> {
    const formData = this.extractFormData(data.formData);
    await this.saveSettings(formData);
  }

  /**
   * 处理自动保存
   */
  private async handleAutoSave(_event: any, data: any): Promise<void> {
    const formData = this.extractFormData(data.formData);
    
    try {
      await this.saveSettings(formData, true);
      console.log('Settings auto-saved');
    } catch (error) {
      console.error('Auto-save failed:', error);
    }
  }

  /**
   * 获取表单数据
   */
  private getFormData(): SettingsData {
    if (!this.formComponent) {
      throw new Error('Form component not initialized');
    }

    return {
      api_key: this.formComponent.getFieldValue('api_key'),
      database_id: this.formComponent.getFieldValue('database_id'),
      sync_interval: parseInt(this.formComponent.getFieldValue('sync_interval')) || 60,
      auto_sync: this.formComponent.getFieldValue('auto_sync') === 'true',
      delete_protection: this.formComponent.getFieldValue('delete_protection') === 'true',
      image_optimization: this.formComponent.getFieldValue('image_optimization') === 'true',
      cache_enabled: this.formComponent.getFieldValue('cache_enabled') === 'true',
      debug_mode: this.formComponent.getFieldValue('debug_mode') === 'true'
    };
  }

  /**
   * 从FormData提取数据
   */
  private extractFormData(formData: FormData): SettingsData {
    return {
      api_key: formData.get('api_key') as string || '',
      database_id: formData.get('database_id') as string || '',
      sync_interval: parseInt(formData.get('sync_interval') as string) || 60,
      auto_sync: formData.get('auto_sync') === 'true',
      delete_protection: formData.get('delete_protection') === 'true',
      image_optimization: formData.get('image_optimization') === 'true',
      cache_enabled: formData.get('cache_enabled') === 'true',
      debug_mode: formData.get('debug_mode') === 'true'
    };
  }

  /**
   * 保存设置
   */
  private async saveSettings(settings: SettingsData, silent = false): Promise<void> {
    try {
      const response = await post('notion_to_wordpress_save_settings', settings);
      
      if (response.data.success) {
        this.settings = settings;
        this.updateSettingsDisplay();
        
        if (!silent) {
          showSuccess('设置保存成功');
        }
        
        // 发送设置更新事件
        this.emit('settings:updated', { settings });
      } else {
        throw new Error(response.data.message || '保存设置失败');
      }
    } catch (error) {
      console.error('Failed to save settings:', error);
      if (!silent) {
        showError(`保存设置失败: ${(error as Error).message}`);
      }
      throw error;
    }
  }

  /**
   * 处理重置
   */
  private async handleReset(event: Event): Promise<void> {
    event.preventDefault();

    if (!confirm('确定要重置所有设置吗？此操作不可撤销。')) {
      return;
    }

    try {
      const response = await post('notion_to_wordpress_reset_settings', {});
      
      if (response.data.success) {
        this.settings = response.data.data;
        this.populateForm();
        this.updateSettingsDisplay();
        showSuccess('设置已重置');
      } else {
        throw new Error(response.data.message || '重置设置失败');
      }
    } catch (error) {
      console.error('Failed to reset settings:', error);
      showError(`重置设置失败: ${(error as Error).message}`);
    }
  }

  /**
   * 处理测试连接
   */
  private async handleTestConnection(event: Event): Promise<void> {
    event.preventDefault();

    if (!this.settings?.api_key || !this.settings?.database_id) {
      showError('请先配置API密钥和数据库ID');
      return;
    }

    const button = event.target as HTMLButtonElement;
    const originalText = button.textContent;
    
    button.disabled = true;
    button.textContent = '测试中...';

    try {
      const response = await post('notion_to_wordpress_test_connection', {
        api_key: this.settings.api_key,
        database_id: this.settings.database_id
      });

      if (response.data.success) {
        showSuccess(response.data.data.message || '连接测试成功');
      } else {
        throw new Error(response.data.message || '连接测试失败');
      }
    } catch (error) {
      console.error('Connection test failed:', error);
      showError(`连接测试失败: ${(error as Error).message}`);
    } finally {
      button.disabled = false;
      button.textContent = originalText;
    }
  }

  /**
   * 获取当前设置
   */
  public getSettings(): SettingsData | null {
    return this.settings;
  }

  /**
   * 更新特定设置
   */
  public async updateSetting(key: keyof SettingsData, value: any): Promise<void> {
    if (!this.settings) return;

    const updatedSettings = {
      ...this.settings,
      [key]: value
    };

    await this.saveSettings(updatedSettings);
  }
}

// 导出模块创建函数
export default function createSettingsModule(element?: HTMLElement): SettingsModule {
  return new SettingsModule({
    element,
    selector: element ? undefined : '#settings-container'
  });
}
