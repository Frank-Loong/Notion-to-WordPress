/**
 * WordPress相关类型定义
 */

// WordPress全局对象
declare global {
  interface Window {
    wp: WordPressGlobal;
    jQuery: JQueryStatic;
    $: JQueryStatic;
    ajaxurl: string;
    notionToWp: NotionToWpGlobal;
  }
}

// WordPress核心接口
export interface WordPressGlobal {
  hooks: {
    addAction: (tag: string, callback: Function, priority?: number) => void;
    addFilter: (tag: string, callback: Function, priority?: number) => void;
    doAction: (tag: string, ...args: any[]) => void;
    applyFilters: (tag: string, value: any, ...args: any[]) => any;
  };
  i18n: {
    __: (text: string, domain?: string) => string;
    _e: (text: string, domain?: string) => void;
    _n: (single: string, plural: string, number: number, domain?: string) => string;
  };
}

// 插件全局配置接口
export interface NotionToWpGlobal {
  ajax_url: string;
  nonce: string;
  version: string;
  script_nonce: string;
  debug_mode?: boolean;
  i18n: {
    [key: string]: string;
  };
}

// AJAX响应接口
export interface AjaxResponse<T = any> {
  success: boolean;
  data: T;
  message?: string;
}

// WordPress选项接口
export interface WordPressOptions {
  [key: string]: any;
}

// WordPress用户接口
export interface WordPressUser {
  ID: number;
  user_login: string;
  user_email: string;
  display_name: string;
  user_registered: string;
  roles: string[];
}

// WordPress文章接口
export interface WordPressPost {
  ID: number;
  post_title: string;
  post_content: string;
  post_excerpt: string;
  post_status: string;
  post_type: string;
  post_date: string;
  post_modified: string;
  post_author: number;
  guid: string;
  post_name: string;
  meta?: {
    [key: string]: any;
  };
}

// WordPress分类接口
export interface WordPressCategory {
  term_id: number;
  name: string;
  slug: string;
  description: string;
  parent: number;
  count: number;
}

// WordPress标签接口
export interface WordPressTag {
  term_id: number;
  name: string;
  slug: string;
  description: string;
  count: number;
}

// WordPress媒体接口
export interface WordPressMedia {
  ID: number;
  title: string;
  filename: string;
  url: string;
  alt: string;
  author: number;
  description: string;
  caption: string;
  name: string;
  status: string;
  uploaded_to: number;
  date: string;
  modified: string;
  menu_order: number;
  mime_type: string;
  type: string;
  subtype: string;
  icon: string;
  width?: number;
  height?: number;
  sizes?: {
    [key: string]: {
      file: string;
      width: number;
      height: number;
      mime_type: string;
      url: string;
    };
  };
}

// WordPress REST API响应接口
export interface WordPressRestResponse<T = any> {
  data: T;
  headers: {
    [key: string]: string;
  };
  status: number;
  statusText: string;
}

// WordPress数据库表前缀
export type WpTablePrefix = string;

// WordPress钩子类型
export type WordPressHookCallback = (...args: any[]) => any;
export type WordPressFilterCallback<T = any> = (value: T, ...args: any[]) => T;

// WordPress能力类型
export type WordPressCapability = 
  | 'manage_options'
  | 'edit_posts'
  | 'edit_pages'
  | 'edit_others_posts'
  | 'publish_posts'
  | 'manage_categories'
  | 'moderate_comments'
  | 'upload_files'
  | 'edit_themes'
  | 'install_plugins'
  | 'update_plugins'
  | 'delete_plugins';

// WordPress文章状态类型
export type WordPressPostStatus = 
  | 'publish'
  | 'draft'
  | 'pending'
  | 'private'
  | 'trash'
  | 'auto-draft'
  | 'inherit';

// WordPress文章类型
export type WordPressPostType = 
  | 'post'
  | 'page'
  | 'attachment'
  | 'revision'
  | 'nav_menu_item'
  | string; // 自定义文章类型

export {};
