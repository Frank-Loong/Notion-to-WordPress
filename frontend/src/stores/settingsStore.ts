import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import { getApiService } from '../services/api';
import type {
  SettingsData,
  FieldMapping,
  CustomFieldMapping,
  PerformanceConfig,
  LanguageSettings,
  ConnectionTestResponse,
  ValidationResult
} from '../types';

// 设置状态接口
interface SettingsState {
  // 设置数据
  settings: SettingsData | null;
  
  // 加载状态
  isLoading: boolean;
  isSaving: boolean;
  isTesting: boolean;
  
  // 验证状态
  validationResults: Record<string, ValidationResult>;
  
  // 连接测试
  connectionStatus: 'unknown' | 'testing' | 'connected' | 'failed';
  lastTestResult: ConnectionTestResponse | null;
  
  // 保存状态
  lastSaved: string | null;
  hasUnsavedChanges: boolean;
  
  // 错误状态
  error: string | null;
  fieldErrors: Record<string, string>;
}

// 设置操作接口
interface SettingsActions {
  // 设置加载和保存
  loadSettings: () => Promise<void>;
  saveSettings: (settings?: Partial<SettingsData>) => Promise<boolean>;
  updateSettings: (updates: Partial<SettingsData>) => void;
  resetSettings: () => void;
  
  // 连接测试
  testConnection: (apiKey?: string, databaseId?: string) => Promise<boolean>;
  
  // 字段映射管理
  updateFieldMapping: (mapping: Partial<FieldMapping>) => void;
  addCustomFieldMapping: (mapping: CustomFieldMapping) => void;
  removeCustomFieldMapping: (fieldName: string) => void;
  
  // 性能配置
  updatePerformanceConfig: (config: Partial<PerformanceConfig>) => void;
  
  // 语言设置
  updateLanguageSettings: (language: Partial<LanguageSettings>) => void;
  
  // 验证
  validateField: (fieldName: string, value: any) => ValidationResult;
  validateAllFields: () => boolean;
  clearValidation: () => void;
  
  // 错误处理
  setError: (error: string) => void;
  setFieldError: (field: string, error: string) => void;
  clearErrors: () => void;
  
  // 状态管理
  markAsChanged: () => void;
  markAsSaved: () => void;
}

// 完整的设置Store类型
type SettingsStore = SettingsState & SettingsActions;

// 默认设置
const defaultSettings: SettingsData = {
  api_key: '',
  database_id: '',
  post_type: 'post',
  post_status: 'publish',
  author_id: 1,
  field_mapping: {
    title_field: 'post_title',
    content_field: 'post_content',
    excerpt_field: 'post_excerpt',
    featured_image_field: 'featured_image',
    category_field: 'categories',
    tag_field: 'tags',
    custom_fields: [],
  },
  custom_field_mapping: [],
  performance_config: {
    batch_size: 10,
    request_delay: 1000,
    max_retries: 3,
    timeout: 30000,
    enable_cache: true,
    cache_duration: 3600,
    max_execution_time: 300,
    memory_limit: '256M',
    enable_async_processing: true,
    enable_image_optimization: true,
  },
  language_settings: {
    default_language: 'zh-CN',
    fallback_language: 'en-US',
    auto_detect: true,
    enable_multilingual: false,
    language_mapping: {},
  },
  webhook_url: '',
  enable_webhook: false,
  enable_auto_sync: false,
  sync_interval: 3600,
  enable_debug: false,
  log_level: 'info',
};

// 默认状态
const defaultState: SettingsState = {
  settings: null,
  isLoading: false,
  isSaving: false,
  isTesting: false,
  validationResults: {},
  connectionStatus: 'unknown',
  lastTestResult: null,
  lastSaved: null,
  hasUnsavedChanges: false,
  error: null,
  fieldErrors: {},
};

// 创建设置Store
export const useSettingsStore = create<SettingsStore>()(
  persist(
    (set, get) => ({
      ...defaultState,

      // ==================== 设置加载和保存 ====================
      
      loadSettings: async () => {
        set({ isLoading: true, error: null });
        
        try {
          const apiService = getApiService();
          const settings = await apiService.getSettings();
          
          set({ 
            settings: { ...defaultSettings, ...settings },
            isLoading: false,
            hasUnsavedChanges: false,
          });
        } catch (error) {
          const errorMessage = error instanceof Error ? error.message : '加载设置失败';
          set({ 
            error: errorMessage,
            isLoading: false,
            settings: defaultSettings, // 使用默认设置作为后备
          });
          console.error('❌ [设置Store] 加载设置失败:', error);
        }
      },

      saveSettings: async (settingsUpdate?: Partial<SettingsData>) => {
        const currentSettings = get().settings;
        if (!currentSettings) {
          get().setError('没有可保存的设置数据');
          return false;
        }

        set({ isSaving: true, error: null });

        try {
          const newSettings = settingsUpdate 
            ? { ...currentSettings, ...settingsUpdate }
            : currentSettings;

          // 验证设置
          const isValid = get().validateAllFields();
          if (!isValid) {
            set({ isSaving: false });
            return false;
          }

          const apiService = getApiService();
          await apiService.saveSettings(newSettings);

          set({ 
            settings: newSettings,
            isSaving: false,
            hasUnsavedChanges: false,
            lastSaved: new Date().toISOString(),
          });

          console.log('✅ [设置Store] 设置保存成功');
          return true;
        } catch (error) {
          const errorMessage = error instanceof Error ? error.message : '保存设置失败';
          set({ 
            error: errorMessage,
            isSaving: false,
          });
          console.error('❌ [设置Store] 保存设置失败:', error);
          return false;
        }
      },

      updateSettings: (updates: Partial<SettingsData>) => {
        set(state => ({
          settings: state.settings ? { ...state.settings, ...updates } : null,
          hasUnsavedChanges: true,
        }));
      },

      resetSettings: () => {
        set({ 
          settings: defaultSettings,
          hasUnsavedChanges: true,
          validationResults: {},
          fieldErrors: {},
        });
      },

      // ==================== 连接测试 ====================
      
      testConnection: async (apiKey?: string, databaseId?: string): Promise<boolean> => {
        const settings = get().settings;
        const testApiKey = apiKey || settings?.api_key;
        const testDatabaseId = databaseId || settings?.database_id;

        if (!testApiKey || !testDatabaseId) {
          get().setError('请输入API密钥和数据库ID');
          return false;
        }

        set({ isTesting: true, connectionStatus: 'testing', error: null });

        try {
          const apiService = getApiService();
          const result = await apiService.testConnection({
            api_key: testApiKey,
            database_id: testDatabaseId,
          });

          const isConnected = !!(result.success && result.data?.connected);

          set({
            isTesting: false,
            connectionStatus: isConnected ? 'connected' : 'failed',
            lastTestResult: result,
            error: isConnected ? null : (result.message || '连接测试失败'),
          });

          return isConnected;
        } catch (error) {
          const errorMessage = error instanceof Error ? error.message : '连接测试失败';
          set({ 
            isTesting: false,
            connectionStatus: 'failed',
            error: errorMessage,
          });
          console.error('❌ [设置Store] 连接测试失败:', error);
          return false;
        }
      },

      // ==================== 字段映射管理 ====================
      
      updateFieldMapping: (mapping: Partial<FieldMapping>) => {
        const currentMapping = get().settings?.field_mapping;
        if (currentMapping) {
          get().updateSettings({
            field_mapping: {
              ...currentMapping,
              ...mapping,
            },
          });
        }
      },

      addCustomFieldMapping: (mapping: CustomFieldMapping) => {
        const settings = get().settings;
        if (!settings) return;

        const customMappings = settings.custom_field_mapping || [];
        const existingIndex = customMappings.findIndex(m => m.notion_field === mapping.notion_field);

        if (existingIndex >= 0) {
          // 更新现有映射
          customMappings[existingIndex] = mapping;
        } else {
          // 添加新映射
          customMappings.push(mapping);
        }

        get().updateSettings({
          custom_field_mapping: customMappings,
        });
      },

      removeCustomFieldMapping: (fieldName: string) => {
        const settings = get().settings;
        if (!settings) return;

        const customMappings = settings.custom_field_mapping || [];
        const filteredMappings = customMappings.filter(m => m.notion_field !== fieldName);

        get().updateSettings({
          custom_field_mapping: filteredMappings,
        });
      },

      // ==================== 性能配置 ====================
      
      updatePerformanceConfig: (config: Partial<PerformanceConfig>) => {
        const currentConfig = get().settings?.performance_config;
        if (currentConfig) {
          get().updateSettings({
            performance_config: {
              ...currentConfig,
              ...config,
            },
          });
        }
      },

      // ==================== 语言设置 ====================
      
      updateLanguageSettings: (language: Partial<LanguageSettings>) => {
        const currentSettings = get().settings?.language_settings;
        if (currentSettings) {
          get().updateSettings({
            language_settings: {
              ...currentSettings,
              ...language,
            },
          });
        }
      },

      // ==================== 验证 ====================
      
      validateField: (fieldName: string, value: any): ValidationResult => {
        let isValid = true;
        let message = '';

        switch (fieldName) {
          case 'api_key':
            isValid = typeof value === 'string' && value.length > 0;
            message = isValid ? '' : 'API密钥不能为空';
            break;

          case 'database_id':
            isValid = typeof value === 'string' && value.length > 0;
            message = isValid ? '' : '数据库ID不能为空';
            break;

          case 'webhook_url':
            if (value && typeof value === 'string') {
              try {
                new URL(value);
                isValid = true;
              } catch {
                isValid = false;
                message = 'Webhook URL格式不正确';
              }
            }
            break;

          default:
            isValid = true;
        }

        const result: ValidationResult = { isValid, is_valid: isValid, message };
        
        set(state => ({
          validationResults: {
            ...state.validationResults,
            [fieldName]: result,
          },
          fieldErrors: {
            ...state.fieldErrors,
            [fieldName]: isValid ? '' : message,
          },
        }));

        return result;
      },

      validateAllFields: (): boolean => {
        const settings = get().settings;
        if (!settings) return false;

        const results = [
          get().validateField('api_key', settings.api_key),
          get().validateField('database_id', settings.database_id),
          get().validateField('webhook_url', settings.webhook_url),
        ];

        return results.every(result => result.isValid);
      },

      clearValidation: () => {
        set({ validationResults: {}, fieldErrors: {} });
      },

      // ==================== 错误处理 ====================
      
      setError: (error: string) => {
        set({ error });
      },

      setFieldError: (field: string, error: string) => {
        set(state => ({
          fieldErrors: {
            ...state.fieldErrors,
            [field]: error,
          },
        }));
      },

      clearErrors: () => {
        set({ error: null, fieldErrors: {} });
      },

      // ==================== 状态管理 ====================
      
      markAsChanged: () => {
        set({ hasUnsavedChanges: true });
      },

      markAsSaved: () => {
        set({ 
          hasUnsavedChanges: false,
          lastSaved: new Date().toISOString(),
        });
      },
    }),
    {
      name: 'notion-wp-settings-store',
      storage: createJSONStorage(() => localStorage),
      // 持久化设置数据和保存状态
      partialize: (state) => ({
        settings: state.settings,
        lastSaved: state.lastSaved,
        connectionStatus: state.connectionStatus,
      }),
    }
  )
);

// 导出类型供其他组件使用
export type { SettingsStore, SettingsState, SettingsActions };
