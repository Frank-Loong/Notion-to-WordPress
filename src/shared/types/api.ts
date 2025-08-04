/**
 * API相关类型定义
 */

// Notion API相关类型
export interface NotionApiConfig {
  apiKey: string;
  databaseId: string;
  version?: string;
}

// Notion数据库属性类型
export interface NotionProperty {
  id: string;
  name: string;
  type: NotionPropertyType;
  [key: string]: any;
}

// Notion属性类型枚举
export type NotionPropertyType = 
  | 'title'
  | 'rich_text'
  | 'number'
  | 'select'
  | 'multi_select'
  | 'date'
  | 'people'
  | 'files'
  | 'checkbox'
  | 'url'
  | 'email'
  | 'phone_number'
  | 'formula'
  | 'relation'
  | 'rollup'
  | 'created_time'
  | 'created_by'
  | 'last_edited_time'
  | 'last_edited_by';

// Notion页面接口
export interface NotionPage {
  id: string;
  created_time: string;
  last_edited_time: string;
  created_by: NotionUser;
  last_edited_by: NotionUser;
  cover?: NotionFile;
  icon?: NotionIcon;
  parent: NotionParent;
  archived: boolean;
  properties: {
    [key: string]: NotionPropertyValue;
  };
  url: string;
  public_url?: string;
}

// Notion用户接口
export interface NotionUser {
  object: 'user';
  id: string;
  name?: string;
  avatar_url?: string;
  type: 'person' | 'bot';
  person?: {
    email: string;
  };
  bot?: {
    owner: {
      type: 'user' | 'workspace';
      user?: NotionUser;
    };
  };
}

// Notion文件接口
export interface NotionFile {
  type: 'external' | 'file';
  external?: {
    url: string;
  };
  file?: {
    url: string;
    expiry_time: string;
  };
  name?: string;
  caption?: NotionRichText[];
}

// Notion图标接口
export interface NotionIcon {
  type: 'emoji' | 'external' | 'file';
  emoji?: string;
  external?: {
    url: string;
  };
  file?: {
    url: string;
    expiry_time: string;
  };
}

// Notion父级接口
export interface NotionParent {
  type: 'database_id' | 'page_id' | 'workspace';
  database_id?: string;
  page_id?: string;
  workspace?: boolean;
}

// Notion属性值接口
export interface NotionPropertyValue {
  id: string;
  type: NotionPropertyType;
  [key: string]: any;
}

// Notion富文本接口
export interface NotionRichText {
  type: 'text' | 'mention' | 'equation';
  text?: {
    content: string;
    link?: {
      url: string;
    };
  };
  mention?: NotionMention;
  equation?: {
    expression: string;
  };
  annotations: NotionAnnotations;
  plain_text: string;
  href?: string;
}

// Notion提及接口
export interface NotionMention {
  type: 'user' | 'page' | 'database' | 'date';
  user?: NotionUser;
  page?: {
    id: string;
  };
  database?: {
    id: string;
  };
  date?: NotionDate;
}

// Notion注释接口
export interface NotionAnnotations {
  bold: boolean;
  italic: boolean;
  strikethrough: boolean;
  underline: boolean;
  code: boolean;
  color: NotionColor;
}

// Notion颜色类型
export type NotionColor = 
  | 'default'
  | 'gray'
  | 'brown'
  | 'orange'
  | 'yellow'
  | 'green'
  | 'blue'
  | 'purple'
  | 'pink'
  | 'red'
  | 'gray_background'
  | 'brown_background'
  | 'orange_background'
  | 'yellow_background'
  | 'green_background'
  | 'blue_background'
  | 'purple_background'
  | 'pink_background'
  | 'red_background';

// Notion日期接口
export interface NotionDate {
  start: string;
  end?: string;
  time_zone?: string;
}

// Notion块接口
export interface NotionBlock {
  object: 'block';
  id: string;
  parent: NotionParent;
  created_time: string;
  last_edited_time: string;
  created_by: NotionUser;
  last_edited_by: NotionUser;
  has_children: boolean;
  archived: boolean;
  type: NotionBlockType;
  [key: string]: any;
}

// Notion块类型
export type NotionBlockType = 
  | 'paragraph'
  | 'heading_1'
  | 'heading_2'
  | 'heading_3'
  | 'bulleted_list_item'
  | 'numbered_list_item'
  | 'to_do'
  | 'toggle'
  | 'child_page'
  | 'child_database'
  | 'embed'
  | 'image'
  | 'video'
  | 'file'
  | 'pdf'
  | 'bookmark'
  | 'callout'
  | 'quote'
  | 'equation'
  | 'divider'
  | 'table_of_contents'
  | 'column'
  | 'column_list'
  | 'link_preview'
  | 'synced_block'
  | 'template'
  | 'link_to_page'
  | 'table'
  | 'table_row'
  | 'unsupported';

// API请求配置接口
export interface ApiRequestConfig {
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  url: string;
  headers?: {
    [key: string]: string;
  };
  data?: any;
  params?: {
    [key: string]: any;
  };
  timeout?: number;
  retries?: number;
}

// API响应接口
export interface ApiResponse<T = any> {
  data: T;
  status: number;
  statusText: string;
  headers: {
    [key: string]: string;
  };
  config: ApiRequestConfig;
}

// API错误接口
export interface ApiError {
  code: string;
  message: string;
  status?: number;
  details?: any;
}

// 分页响应接口
export interface PaginatedResponse<T = any> {
  object: 'list';
  results: T[];
  next_cursor?: string;
  has_more: boolean;
  type?: string;
  page?: {};
}

// 搜索请求接口
export interface SearchRequest {
  query?: string;
  sort?: {
    direction: 'ascending' | 'descending';
    timestamp: 'last_edited_time' | 'created_time';
  };
  filter?: {
    value: 'page' | 'database';
    property: 'object';
  };
  start_cursor?: string;
  page_size?: number;
}

export {};
