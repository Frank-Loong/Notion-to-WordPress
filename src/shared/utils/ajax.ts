/**
 * AJAX工具函数
 */

import type { AjaxResponse } from '../types/wordpress';

// 本地类型定义
export interface RequestConfig {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  url?: string;
  headers?: Record<string, string>;
  params?: Record<string, any>;
  timeout?: number;
  retries?: number;
}

export interface ResponseData<T = any> {
  data: T;
  status: number;
  statusText: string;
  headers: Record<string, string>;
}

/**
 * AJAX请求配置接口
 */
export interface AjaxRequestConfig extends RequestConfig {
  action?: string;
  nonce?: string;
  data?: any;
}

/**
 * AJAX错误类
 */
export class AjaxError extends Error {
  public status: number;
  public statusText: string;
  public response?: any;

  constructor(message: string, status: number, statusText: string, response?: any) {
    super(message);
    this.name = 'AjaxError';
    this.status = status;
    this.statusText = statusText;
    this.response = response;
  }
}

/**
 * 获取WordPress AJAX URL
 */
export function getAjaxUrl(): string {
  return window.ajaxurl || '/wp-admin/admin-ajax.php';
}

/**
 * 获取nonce值
 */
export function getNonce(): string {
  return window.notionToWp?.nonce || '';
}

/**
 * 创建FormData对象
 */
export function createFormData(data: Record<string, any>): FormData {
  const formData = new FormData();
  
  Object.entries(data).forEach(([key, value]) => {
    if (value instanceof File) {
      formData.append(key, value);
    } else if (Array.isArray(value)) {
      value.forEach((item, index) => {
        formData.append(`${key}[${index}]`, String(item));
      });
    } else if (typeof value === 'object' && value !== null) {
      formData.append(key, JSON.stringify(value));
    } else {
      formData.append(key, String(value));
    }
  });
  
  return formData;
}

/**
 * 处理AJAX响应
 */
function handleResponse<T = any>(response: Response): Promise<ResponseData<T>> {
  return response.text().then(text => {
    let data: any;
    
    try {
      data = JSON.parse(text);
    } catch {
      data = text;
    }
    
    const result: ResponseData<T> = {
      data,
      status: response.status,
      statusText: response.statusText,
      headers: {}
    };
    
    // 转换headers
    response.headers.forEach((value, key) => {
      result.headers[key] = value;
    });
    
    if (!response.ok) {
      throw new AjaxError(
        `Request failed with status ${response.status}`,
        response.status,
        response.statusText,
        data
      );
    }
    
    return result;
  });
}

/**
 * 通用AJAX请求函数
 */
export async function request<T = any>(config: AjaxRequestConfig): Promise<ResponseData<T>> {
  const {
    method = 'POST',
    url = getAjaxUrl(),
    headers = {},
    data,
    action,
    nonce = getNonce(),
    timeout = 30000,
    ...otherConfig
  } = config;
  
  // 准备请求数据
  let requestData: any = data || {};
  
  if (action) {
    requestData.action = action;
  }
  
  if (nonce) {
    requestData.nonce = nonce;
  }
  
  // 准备请求选项
  const requestOptions: RequestInit = {
    method,
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      ...headers
    },
    ...otherConfig
  };
  
  // 处理请求体
  let finalUrl = url;
  if (method.toUpperCase() === 'GET') {
    // GET请求将数据添加到URL参数
    const params = new URLSearchParams();
    Object.entries(requestData).forEach(([key, value]) => {
      params.append(key, String(value));
    });
    const separator = url.includes('?') ? '&' : '?';
    finalUrl = `${url}${separator}${params.toString()}`;
  } else {
    // POST请求处理请求体
    if (requestData instanceof FormData) {
      requestOptions.body = requestData;
    } else {
      requestOptions.headers = {
        ...requestOptions.headers,
        'Content-Type': 'application/x-www-form-urlencoded'
      };
      const params = new URLSearchParams();
      Object.entries(requestData).forEach(([key, value]) => {
        if (typeof value === 'object' && value !== null) {
          params.append(key, JSON.stringify(value));
        } else {
          params.append(key, String(value));
        }
      });
      requestOptions.body = params.toString();
    }
  }
  
  // 设置超时
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeout);
  requestOptions.signal = controller.signal;
  
  try {
    const response = await fetch(finalUrl, requestOptions);
    clearTimeout(timeoutId);
    return await handleResponse<T>(response);
  } catch (error: any) {
    clearTimeout(timeoutId);

    if (error instanceof AjaxError) {
      throw error;
    }

    if (error.name === 'AbortError') {
      throw new AjaxError('Request timeout', 408, 'Request Timeout');
    }

    throw new AjaxError(
      error.message || 'Network error',
      0,
      'Network Error'
    );
  }
}

/**
 * GET请求
 */
export function get<T = any>(action: string, data?: any, config?: Partial<AjaxRequestConfig>): Promise<ResponseData<T>> {
  return request<T>({
    method: 'GET',
    action,
    data,
    ...config
  });
}

/**
 * POST请求
 */
export function post<T = any>(action: string, data?: any, config?: Partial<AjaxRequestConfig>): Promise<ResponseData<T>> {
  return request<T>({
    method: 'POST',
    action,
    data,
    ...config
  });
}

/**
 * 上传文件
 */
export function upload<T = any>(action: string, files: FileList | File[], data?: any, config?: Partial<AjaxRequestConfig>): Promise<ResponseData<T>> {
  const formData = new FormData();
  
  // 添加文件
  const fileArray = Array.from(files);
  fileArray.forEach((file, index) => {
    formData.append(`file_${index}`, file);
  });
  
  // 添加其他数据
  if (data) {
    Object.entries(data).forEach(([key, value]) => {
      if (typeof value === 'object' && value !== null) {
        formData.append(key, JSON.stringify(value));
      } else {
        formData.append(key, String(value));
      }
    });
  }
  
  return request<T>({
    method: 'POST',
    action,
    data: formData,
    ...config
  });
}

/**
 * 批量请求
 */
export async function batch<T = any>(requests: AjaxRequestConfig[]): Promise<ResponseData<T>[]> {
  const promises = requests.map(config => request<T>(config));
  return Promise.all(promises);
}

/**
 * 重试请求
 */
export async function retry<T = any>(
  config: AjaxRequestConfig,
  maxAttempts = 3,
  delay = 1000
): Promise<ResponseData<T>> {
  let lastError: any;

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    try {
      return await request<T>(config);
    } catch (error: any) {
      lastError = error;

      if (attempt < maxAttempts) {
        await new Promise(resolve => setTimeout(resolve, delay * attempt));
      }
    }
  }

  throw lastError!;
}

/**
 * WordPress AJAX响应处理
 */
export function handleWpAjaxResponse<T = any>(response: ResponseData<AjaxResponse<T>>): T {
  const { data } = response;
  
  if (!data.success) {
    throw new AjaxError(
      data.message || 'Request failed',
      response.status,
      response.statusText,
      data
    );
  }
  
  return data.data;
}
