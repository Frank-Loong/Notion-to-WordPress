/**
 * 配置相关类型定义
 */

// 插件主配置接口
export interface PluginConfig {
  // API配置
  notion_api_key: string;
  notion_database_id: string;
  
  // 字段映射配置
  field_mapping: FieldMapping;
  
  // 性能配置
  api_page_size: number;
  concurrent_requests: number;
  batch_size: number;
  log_buffer_size: number;
  enable_performance_mode: boolean;
  
  // 同步配置
  auto_sync_enabled: boolean;
  sync_interval: number;
  webhook_enabled: boolean;
  webhook_secret: string;
  
  // 内容配置
  default_post_status: string;
  default_post_type: string;
  enable_math_rendering: boolean;
  enable_mermaid_diagrams: boolean;
  
  // 缓存配置
  enable_caching: boolean;
  cache_duration: number;
  
  // 调试配置
  debug_mode: boolean;
  log_level: LogLevel;
}

// 字段映射配置接口
export interface FieldMapping {
  title: string;
  content: string;
  excerpt: string;
  status: string;
  author: string;
  date: string;
  categories: string;
  tags: string;
  featured_image: string;
  custom_fields: {
    [wordpressField: string]: string; // WordPress字段 -> Notion属性
  };
}

// 日志级别类型
export type LogLevel = 'debug' | 'info' | 'warn' | 'error';

// 同步状态接口
export interface SyncStatus {
  status: SyncStatusType;
  progress: number;
  total: number;
  current_item: string;
  start_time: string;
  estimated_completion?: string;
  errors: SyncError[];
  warnings: SyncWarning[];
}

// 同步状态类型
export type SyncStatusType = 
  | 'idle'
  | 'running'
  | 'paused'
  | 'completed'
  | 'error'
  | 'cancelled';

// 同步错误接口
export interface SyncError {
  id: string;
  message: string;
  code: string;
  timestamp: string;
  context?: any;
}

// 同步警告接口
export interface SyncWarning {
  id: string;
  message: string;
  timestamp: string;
  context?: any;
}

// 队列状态接口
export interface QueueStatus {
  total_jobs: number;
  pending_jobs: number;
  processing_jobs: number;
  completed_jobs: number;
  failed_jobs: number;
  queue_size: number;
  is_processing: boolean;
  last_processed: string;
}

// 性能统计接口
export interface PerformanceStats {
  api_calls: {
    total: number;
    success: number;
    failed: number;
    average_duration: number;
  };
  database_operations: {
    total: number;
    inserts: number;
    updates: number;
    deletes: number;
    average_duration: number;
  };
  memory_usage: {
    current: number;
    peak: number;
    limit: number;
  };
  cache_stats: {
    hits: number;
    misses: number;
    hit_rate: number;
  };
}

// 验证规则接口
export interface ValidationRule {
  field: string;
  required: boolean;
  type: 'string' | 'number' | 'boolean' | 'array' | 'object';
  min_length?: number;
  max_length?: number;
  pattern?: string;
  custom_validator?: (value: any) => boolean | string;
}

// 表单配置接口
export interface FormConfig {
  fields: FormField[];
  validation_rules: ValidationRule[];
  submit_action: string;
  nonce_field: string;
}

// 表单字段接口
export interface FormField {
  name: string;
  label: string;
  type: FormFieldType;
  required: boolean;
  default_value?: any;
  options?: FormFieldOption[];
  attributes?: {
    [key: string]: any;
  };
  help_text?: string;
  validation?: ValidationRule[];
}

// 表单字段类型
export type FormFieldType = 
  | 'text'
  | 'textarea'
  | 'select'
  | 'checkbox'
  | 'radio'
  | 'number'
  | 'email'
  | 'url'
  | 'password'
  | 'hidden'
  | 'file';

// 表单字段选项接口
export interface FormFieldOption {
  value: any;
  label: string;
  disabled?: boolean;
}

// 标签页配置接口
export interface TabConfig {
  id: string;
  title: string;
  icon?: string;
  content_callback?: string;
  active?: boolean;
  disabled?: boolean;
}

// 通知配置接口
export interface NotificationConfig {
  type: NotificationType;
  message: string;
  duration?: number;
  dismissible?: boolean;
  actions?: NotificationAction[];
}

// 通知类型
export type NotificationType = 'success' | 'error' | 'warning' | 'info';

// 通知操作接口
export interface NotificationAction {
  label: string;
  callback: () => void;
  style?: 'primary' | 'secondary' | 'danger';
}

// 主题配置接口
export interface ThemeConfig {
  primary_color: string;
  secondary_color: string;
  accent_color: string;
  background_color: string;
  text_color: string;
  border_color: string;
  border_radius: string;
  font_family: string;
  font_size: string;
}

// 国际化配置接口
export interface I18nConfig {
  default_locale: string;
  available_locales: string[];
  text_domain: string;
  translations: {
    [locale: string]: {
      [key: string]: string;
    };
  };
}

// 环境配置接口
export interface EnvironmentConfig {
  is_development: boolean;
  is_production: boolean;
  is_debug: boolean;
  wp_version: string;
  php_version: string;
  plugin_version: string;
  plugin_path: string;
  plugin_url: string;
}

export {};
