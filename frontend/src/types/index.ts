/**
 * 核心数据类型定义
 * 
 * @since 2.0.0
 */

// ==================== 基础类型 ====================

export interface BaseResponse {
  success: boolean;
  data?: any;
  message?: string;
}

export interface ErrorResponse {
  success: false;
  data: string | { message: string };
  message?: string;
}

export interface SuccessResponse<T = any> {
  success: true;
  data: T;
  message?: string;
}

// ==================== 同步相关类型 ====================

export interface SyncRequest {
  task_id?: string;
  type?: 'smart' | 'full';
  check_deletions?: boolean;
  incremental?: boolean;
  force_refresh?: boolean;
}

export interface SyncResponse extends BaseResponse {
  data?: {
    taskId: string;
    task_id: string;
    status: 'started' | 'running' | 'completed' | 'failed';
    stats?: SyncStats;
  };
}

export interface SyncStats {
  total_pages: number;
  imported_pages: number;
  updated_pages: number;
  failed_pages: number;
  execution_time: number;
  memory_usage: number;
  last_sync_time?: string;
}

// 简单的同步状态类型（用于store）
export type SyncStatusType = 'idle' | 'running' | 'completed' | 'failed' | 'paused' | 'cancelled';

export interface SyncStatus {
  status: SyncStatusType;
  operation: string;
  started_at: string;
  updated_at: string;
  data_count: number;
  progress: number;
  details: SyncStatusDetail[];
}

export interface SyncStatusDetail {
  step: string;
  status: 'pending' | 'running' | 'completed' | 'failed';
  message: string;
  timestamp: string;
  progress?: number;
}

// ==================== 设置相关类型 ====================

export interface SettingsData {
  api_key: string;
  database_id: string;
  post_type: string;
  post_status: string;
  author_id: number;
  enable_auto_sync: boolean;
  sync_interval: number;
  enable_webhook: boolean;
  webhook_url?: string;
  webhook_verify_token?: string;
  field_mapping: FieldMapping;
  custom_field_mapping: CustomFieldMapping[];
  performance_config: PerformanceConfig;
  language_settings: LanguageSettings;
  enable_debug: boolean;
  log_level: string;
}

export interface FieldMapping {
  title_field: string;
  content_field: string;
  excerpt_field?: string;
  featured_image_field?: string;
  category_field?: string;
  tag_field?: string;
  custom_fields: CustomFieldMapping[];
}

export interface CustomFieldMapping {
  notion_field: string;
  wordpress_field: string;
  field_type: 'text' | 'number' | 'date' | 'boolean' | 'select' | 'multi_select';
}

export interface PerformanceConfig {
  enable_cache: boolean;
  cache_duration: number;
  batch_size: number;
  max_execution_time: number;
  memory_limit: string;
  enable_async_processing: boolean;
  enable_image_optimization: boolean;
  max_retries: number;
  timeout: number;
  request_delay: number;
}

export interface LanguageSettings {
  default_language: string;
  enable_multilingual: boolean;
  language_mapping: Record<string, string>;
  fallback_language: string;
  auto_detect: boolean;
}

// ==================== 统计相关类型 ====================

export interface StatsData {
  imported_count: number;
  published_count: number;
  last_update: string;
  next_run: string;
  total_posts: number;
  status_breakdown: Record<string, number>;
  performance_metrics?: PerformanceMetrics;
  smart_cache?: CacheStats;
}

export interface PerformanceMetrics {
  enhanced_fetch_time: number;
  enhanced_fetch_count: number;
  enhanced_fetch_memory: number;
  memory_usage: number;
  peak_memory: number;
  execution_time: number;
}

export interface CacheStats {
  hits: number;
  misses: number;
  hit_ratio: number;
  cache_size: number;
  status: 'enabled' | 'disabled';
}

// ==================== 日志相关类型 ====================

export interface LogEntry {
  id: string;
  timestamp: string;
  level: 'info' | 'warning' | 'error' | 'debug';
  message: string;
  context: string;
  details?: any;
}

export interface LogViewRequest {
  file_name: string;
  lines?: number;
  offset?: number;
}

export interface LogViewResponse {
  content: string;
  file_size: number;
  total_lines: number;
  file_name: string;
}

// ==================== 系统信息类型 ====================

export interface SystemInfo {
  php_version: string;
  wp_version: string;
  memory_limit: string;
  max_execution_time: string;
  plugin_version: string;
  current_time: string;
  options_exist: 'yes' | 'no';
  ajax_url: string;
}

// ==================== 连接测试类型 ====================

export interface ConnectionTestRequest {
  api_key: string;
  database_id: string;
}

export interface ConnectionTestResponse extends BaseResponse {
  data?: {
    connected: boolean;
    database_info?: {
      id: string;
      title: string;
      created_time: string;
      last_edited_time: string;
    };
  };
}

// ==================== 数据库优化类型 ====================

export interface IndexStatus {
  existing_indexes: DatabaseIndex[];
  missing_indexes: DatabaseIndex[];
  optimization_suggestions: OptimizationSuggestion[];
}

export interface DatabaseIndex {
  table: string;
  column: string;
  type: 'primary' | 'unique' | 'index' | 'fulltext';
  status: 'exists' | 'missing' | 'recommended';
}

export interface OptimizationSuggestion {
  type: 'index' | 'query' | 'configuration';
  priority: 'high' | 'medium' | 'low';
  description: string;
  action: string;
  estimated_impact: string;
}

// ==================== 队列管理类型 ====================

export interface QueueStatus {
  total_tasks: number;
  pending_tasks: number;
  running_tasks: number;
  completed_tasks: number;
  failed_tasks: number;
  queue_health: 'healthy' | 'warning' | 'critical';
}

export interface QueueTask {
  id: string;
  type: string;
  status: 'pending' | 'running' | 'completed' | 'failed';
  created_at: string;
  started_at?: string;
  completed_at?: string;
  progress: number;
  data: any;
  error_message?: string;
}

// ==================== SSE事件类型 ====================

export interface SSEEvent {
  type: 'progress' | 'completed' | 'failed' | 'status_update' | 'status' | 'stats' | 'error' | 'complete';
  data: SSEEventData;
  timestamp: string;
}

export interface SSEEventData {
  task_id?: string;
  progress?: number;
  message?: string;
  status?: string;
  stats?: Partial<StatsData>;
  details?: any;
  error?: string;
}

// ==================== API端点类型 ====================

export interface APIEndpoints {
  manual_sync: string;
  test_connection: string;
  get_stats: string;
  get_settings: string;
  save_settings: string;
  view_log: string;
  clear_logs: string;
  get_system_info: string;
  sse_progress: string;
  get_index_status: string;
  create_database_indexes: string;
  get_async_status: string;
  get_queue_status: string;
  control_async_operation: string;
  cleanup_queue: string;
  cancel_queue_task: string;
  refresh_performance_stats: string;
  reset_performance_stats: string;
  cancel_sync: string;
  retry_failed: string;
  get_smart_recommendations: string;
}

// ==================== 用户信息类型 ====================

export interface CurrentUser {
  id: number;
  name: string;
  email: string;
}

// ==================== 验证相关类型 ====================

export interface ValidationResult {
  is_valid: boolean;
  isValid: boolean; // 兼容性别名
  message: string;
  error_message?: string;
  http_code?: number;
}

// ==================== 智能推荐类型 ====================

export interface SmartRecommendation {
  id: string;
  type: 'performance' | 'configuration' | 'maintenance';
  title: string;
  description: string;
  priority: 'high' | 'medium' | 'low';
  action_required: boolean;
  estimated_impact: string;
  implementation_steps: string[];
}

// ==================== 导出所有类型 ====================
// 注意：使用export interface而不是export type来避免冲突