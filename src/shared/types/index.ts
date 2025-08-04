/**
 * 类型定义统一导出
 */

// WordPress相关类型
export * from './wordpress';

// API相关类型
export * from './api';

// 配置相关类型
export * from './config';

// 通用类型定义
export interface Dictionary<T = any> {
  [key: string]: T;
}

export interface KeyValuePair<T = any> {
  key: string;
  value: T;
}

export interface Callback<T = any> {
  (...args: any[]): T;
}

export interface AsyncCallback<T = any> {
  (...args: any[]): Promise<T>;
}

export interface EventCallback {
  (event: Event, ...args: any[]): void;
}

export interface ErrorCallback {
  (error: Error, ...args: any[]): void;
}

// 组件相关类型
export interface ComponentConfig {
  selector: string;
  options?: Dictionary;
  events?: Dictionary<EventCallback>;
}

export interface ComponentInstance {
  element: HTMLElement;
  config: ComponentConfig;
  init(): void;
  destroy(): void;
  update(options: Dictionary): void;
}

// 状态管理相关类型
export interface StateManager<T = any> {
  getState(): T;
  setState(state: Partial<T>): void;
  subscribe(callback: (state: T) => void): () => void;
  dispatch(action: StateAction): void;
}

export interface StateAction {
  type: string;
  payload?: any;
}

export interface StateReducer<T = any> {
  (state: T, action: StateAction): T;
}

// 事件系统相关类型
export interface EventBus {
  on(event: string, callback: EventCallback): void;
  off(event: string, callback?: EventCallback): void;
  emit(event: string, ...args: any[]): void;
  once(event: string, callback: EventCallback): void;
}

export interface CustomEvent<T = any> {
  type: string;
  detail: T;
  timestamp: number;
}

// 工具函数相关类型
export interface DebounceOptions {
  leading?: boolean;
  trailing?: boolean;
  maxWait?: number;
}

export interface ThrottleOptions {
  leading?: boolean;
  trailing?: boolean;
}

export interface RetryOptions {
  attempts: number;
  delay: number;
  backoff?: 'linear' | 'exponential';
  maxDelay?: number;
}

// HTTP相关类型
export interface HttpClient {
  get<T = any>(url: string, config?: RequestConfig): Promise<T>;
  post<T = any>(url: string, data?: any, config?: RequestConfig): Promise<T>;
  put<T = any>(url: string, data?: any, config?: RequestConfig): Promise<T>;
  patch<T = any>(url: string, data?: any, config?: RequestConfig): Promise<T>;
  delete<T = any>(url: string, config?: RequestConfig): Promise<T>;
}

export interface RequestConfig {
  headers?: Dictionary<string>;
  params?: Dictionary<any>;
  timeout?: number;
  retries?: number;
  validateStatus?: (status: number) => boolean;
}

export interface ResponseData<T = any> {
  data: T;
  status: number;
  statusText: string;
  headers: Dictionary<string>;
}

// 存储相关类型
export interface StorageAdapter {
  get(key: string): any;
  set(key: string, value: any): void;
  remove(key: string): void;
  clear(): void;
  has(key: string): boolean;
  keys(): string[];
}

export interface CacheOptions {
  ttl?: number; // Time to live in milliseconds
  maxSize?: number;
  onExpire?: (key: string, value: any) => void;
}

// 日志相关类型
export interface Logger {
  debug(message: string, ...args: any[]): void;
  info(message: string, ...args: any[]): void;
  warn(message: string, ...args: any[]): void;
  error(message: string, ...args: any[]): void;
  log(level: string, message: string, ...args: any[]): void;
}

export interface LogEntry {
  level: string;
  message: string;
  timestamp: number;
  args: any[];
  context?: Dictionary;
}

// 验证相关类型
export interface Validator<T = any> {
  validate(value: T): ValidationResult;
}

export interface ValidationResult {
  valid: boolean;
  errors: ValidationError[];
}

export interface ValidationError {
  field: string;
  message: string;
  code: string;
}

// 进度相关类型
export interface ProgressTracker {
  start(total: number): void;
  update(current: number, message?: string): void;
  complete(message?: string): void;
  error(error: Error): void;
  getProgress(): ProgressInfo;
}

export interface ProgressInfo {
  current: number;
  total: number;
  percentage: number;
  message: string;
  status: 'idle' | 'running' | 'completed' | 'error';
  startTime: number;
  endTime?: number;
  duration?: number;
}

// 模块加载相关类型
export interface ModuleLoader {
  load<T = any>(moduleName: string): Promise<T>;
  preload(moduleNames: string[]): Promise<void>;
  isLoaded(moduleName: string): boolean;
  unload(moduleName: string): void;
}

export interface ModuleDefinition {
  name: string;
  dependencies: string[];
  factory: (...deps: any[]) => any;
}

// 性能监控相关类型
export interface PerformanceMonitor {
  mark(name: string): void;
  measure(name: string, startMark?: string, endMark?: string): number;
  getEntries(type?: string): PerformanceEntry[];
  clear(type?: string): void;
}

export interface PerformanceEntry {
  name: string;
  entryType: string;
  startTime: number;
  duration: number;
}

export {};
