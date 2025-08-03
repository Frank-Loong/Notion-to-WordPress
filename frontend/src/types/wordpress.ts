/**
 * WordPress相关类型定义
 * 
 * @since 2.0.0
 */

// ==================== WordPress全局配置类型 ====================

export interface WPNotionConfig {
  ajaxUrl: string;
  nonce: string;
  version: string;
  scriptNonce: string;
  i18n: WPNotionI18n;
  apiEndpoints: WPNotionAPIEndpoints;
  currentUser: WPCurrentUser;
  pluginUrl: string;
  assetsUrl: string;
  isDevelopment: boolean;
}

export interface WPNotionI18n {
  importing: string;
  import: string;
  import_error: string;
  testing: string;
  test_connection: string;
  test_error: string;
  fill_fields: string;
  copied: string;
  refreshing_token: string;
  refresh_token: string;
  refresh_error: string;
  saving: string;
  save_settings: string;
  save_error: string;
  loading: string;
  load_error: string;
  confirm_clear_logs: string;
  logs_cleared: string;
  clear_logs_error: string;
  confirm_action: string;
  action_completed: string;
  action_error: string;
  no_data: string;
  invalid_response: string;
  network_error: string;
  timeout_error: string;
  permission_denied: string;
  invalid_nonce: string;
  server_error: string;
  unknown_error: string;
  copy_success: string;
  copy_error: string;
  copy_text_empty: string;
  no_new_verification_token: string;
  details: string;
  verification_token_updated: string;
  language_settings: string;
  webhook_settings: string;
  and: string;
}

export interface WPNotionAPIEndpoints {
  manual_sync: string;
  test_connection: string;
  get_stats: string;
  clear_logs: string;
  view_log: string;
  test_debug: string;
  refresh_verification_token: string;
  get_index_status: string;
  create_database_indexes: string;
  optimize_all_indexes: string;
  remove_database_indexes: string;
  get_async_status: string;
  get_queue_status: string;
  control_async_operation: string;
  cleanup_queue: string;
  cancel_queue_task: string;
  refresh_performance_stats: string;
  reset_performance_stats: string;
  cancel_sync: string;
  retry_failed: string;
  sse_progress: string;
  get_smart_recommendations: string;
  database_indexes_request: string;
  analyze_query_performance: string;
}

export interface WPCurrentUser {
  id: number;
  name: string;
  email: string;
  roles: string[];
  capabilities: Record<string, boolean>;
}

// ==================== WordPress AJAX响应格式 ====================

export interface WPAjaxResponse<T = any> {
  success: boolean;
  data: T;
}

export interface WPAjaxErrorResponse {
  success: false;
  data: string | { message: string };
}

export interface WPAjaxSuccessResponse<T = any> {
  success: true;
  data: T;
}

// ==================== WordPress Post相关类型 ====================

export interface WPPost {
  ID: number;
  post_title: string;
  post_content: string;
  post_excerpt: string;
  post_status: 'publish' | 'draft' | 'private' | 'pending' | 'trash';
  post_type: string;
  post_author: number;
  post_date: string;
  post_date_gmt: string;
  post_modified: string;
  post_modified_gmt: string;
  post_name: string;
  guid: string;
  menu_order: number;
  post_parent: number;
  comment_status: 'open' | 'closed';
  ping_status: 'open' | 'closed';
  post_password: string;
  to_ping: string;
  pinged: string;
  post_content_filtered: string;
  meta: Record<string, any>;
}

export interface WPPostMeta {
  meta_id: number;
  post_id: number;
  meta_key: string;
  meta_value: string;
}

// ==================== WordPress错误类型 ====================

export interface WPError {
  code: string;
  message: string;
  data?: any;
}

// ==================== 全局声明 ====================

declare global {
  interface Window {
    wpNotionConfig: WPNotionConfig;
    notionToWp: WPNotionConfig; // 兼容旧版本
    notion_to_wordpress_ajax: {
      ajax_url: string;
      nonce: string;
    };
    wp: {
      ajax: {
        post: (action: string, data: any) => Promise<any>;
        send: (action: string, options: any) => Promise<any>;
      };
      hooks: {
        addAction: (tag: string, namespace: string, callback: Function, priority?: number) => void;
        addFilter: (tag: string, namespace: string, callback: Function, priority?: number) => void;
        removeAction: (tag: string, namespace: string) => void;
        removeFilter: (tag: string, namespace: string) => void;
        doAction: (tag: string, ...args: any[]) => void;
        applyFilters: (tag: string, value: any, ...args: any[]) => any;
      };
      i18n: {
        __: (text: string, domain?: string) => string;
        _x: (text: string, context: string, domain?: string) => string;
        _n: (single: string, plural: string, number: number, domain?: string) => string;
        _nx: (single: string, plural: string, number: number, context: string, domain?: string) => string;
        sprintf: (format: string, ...args: any[]) => string;
      };
    };
    jQuery: any;
    $: any;
  }
}

// ==================== 导出所有类型 ====================
// 注意：使用export interface而不是export type来避免冲突