/**
 * 通用工具函数
 */

/**
 * 深拷贝对象
 */
export function deepClone<T>(obj: T): T {
  if (obj === null || typeof obj !== 'object') {
    return obj;
  }

  if (obj instanceof Date) {
    return new Date(obj.getTime()) as unknown as T;
  }

  if (obj instanceof Array) {
    return obj.map(item => deepClone(item)) as unknown as T;
  }

  if (typeof obj === 'object') {
    const cloned = {} as T;
    for (const key in obj) {
      if (obj.hasOwnProperty(key)) {
        cloned[key] = deepClone(obj[key]);
      }
    }
    return cloned;
  }

  return obj;
}

/**
 * 对象合并（深度合并）
 */
export function deepMerge<T extends Record<string, any>>(target: T, ...sources: Partial<T>[]): T {
  if (!sources.length) return target;
  const source = sources.shift();

  if (isObject(target) && isObject(source)) {
    for (const key in source) {
      if (isObject(source[key])) {
        if (!target[key]) Object.assign(target, { [key]: {} });
        deepMerge(target[key] as Record<string, any>, source[key] as Record<string, any>);
      } else {
        Object.assign(target, { [key]: source[key] });
      }
    }
  }

  return deepMerge(target, ...sources);
}

/**
 * 检查是否为对象
 */
export function isObject(item: any): item is Record<string, any> {
  return item && typeof item === 'object' && !Array.isArray(item);
}

/**
 * 生成唯一ID
 */
export function generateId(prefix = 'id'): string {
  return `${prefix}_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
}

/**
 * 生成UUID v4
 */
export function generateUUID(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
    const r = Math.random() * 16 | 0;
    const v = c === 'x' ? r : (r & 0x3 | 0x8);
    return v.toString(16);
  });
}

/**
 * 格式化文件大小
 */
export function formatFileSize(bytes: number): string {
  if (bytes === 0) return '0 Bytes';
  
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

/**
 * 格式化数字（添加千分位分隔符）
 */
export function formatNumber(num: number): string {
  return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/**
 * 格式化时间差
 */
export function formatTimeDiff(milliseconds: number): string {
  const seconds = Math.floor(milliseconds / 1000);
  const minutes = Math.floor(seconds / 60);
  const hours = Math.floor(minutes / 60);
  const days = Math.floor(hours / 24);

  if (days > 0) return `${days}天${hours % 24}小时`;
  if (hours > 0) return `${hours}小时${minutes % 60}分钟`;
  if (minutes > 0) return `${minutes}分钟${seconds % 60}秒`;
  return `${seconds}秒`;
}

/**
 * 格式化日期
 */
export function formatDate(date: Date | string | number, format = 'YYYY-MM-DD HH:mm:ss'): string {
  const d = new Date(date);
  
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  const hours = String(d.getHours()).padStart(2, '0');
  const minutes = String(d.getMinutes()).padStart(2, '0');
  const seconds = String(d.getSeconds()).padStart(2, '0');

  return format
    .replace('YYYY', String(year))
    .replace('MM', month)
    .replace('DD', day)
    .replace('HH', hours)
    .replace('mm', minutes)
    .replace('ss', seconds);
}

/**
 * 相对时间格式化
 */
export function formatRelativeTime(date: Date | string | number): string {
  const now = new Date();
  const target = new Date(date);
  const diff = now.getTime() - target.getTime();
  
  const seconds = Math.floor(diff / 1000);
  const minutes = Math.floor(seconds / 60);
  const hours = Math.floor(minutes / 60);
  const days = Math.floor(hours / 24);
  const months = Math.floor(days / 30);
  const years = Math.floor(days / 365);

  if (years > 0) return `${years}年前`;
  if (months > 0) return `${months}个月前`;
  if (days > 0) return `${days}天前`;
  if (hours > 0) return `${hours}小时前`;
  if (minutes > 0) return `${minutes}分钟前`;
  return '刚刚';
}

/**
 * 截断文本
 */
export function truncateText(text: string, maxLength: number, suffix = '...'): string {
  if (text.length <= maxLength) return text;
  return text.substring(0, maxLength - suffix.length) + suffix;
}

/**
 * 首字母大写
 */
export function capitalize(str: string): string {
  return str.charAt(0).toUpperCase() + str.slice(1);
}

/**
 * 驼峰命名转换
 */
export function camelCase(str: string): string {
  return str.replace(/[-_\s]+(.)?/g, (_, char) => char ? char.toUpperCase() : '');
}

/**
 * 短横线命名转换
 */
export function kebabCase(str: string): string {
  return str.replace(/([a-z])([A-Z])/g, '$1-$2').toLowerCase();
}

/**
 * 下划线命名转换
 */
export function snakeCase(str: string): string {
  return str.replace(/([a-z])([A-Z])/g, '$1_$2').toLowerCase();
}

/**
 * 移除HTML标签
 */
export function stripHtml(html: string): string {
  const div = document.createElement('div');
  div.innerHTML = html;
  return div.textContent || div.innerText || '';
}

/**
 * 转义HTML字符
 */
export function escapeHtml(text: string): string {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

/**
 * 反转义HTML字符
 */
export function unescapeHtml(html: string): string {
  const div = document.createElement('div');
  div.innerHTML = html;
  return div.textContent || div.innerText || '';
}

/**
 * 数组去重
 */
export function unique<T>(array: T[]): T[] {
  return [...new Set(array)];
}

/**
 * 数组分组
 */
export function groupBy<T>(array: T[], key: keyof T | ((item: T) => string)): Record<string, T[]> {
  return array.reduce((groups, item) => {
    const groupKey = typeof key === 'function' ? key(item) : String(item[key]);
    if (!groups[groupKey]) {
      groups[groupKey] = [];
    }
    groups[groupKey].push(item);
    return groups;
  }, {} as Record<string, T[]>);
}

/**
 * 数组分块
 */
export function chunk<T>(array: T[], size: number): T[][] {
  const chunks: T[][] = [];
  for (let i = 0; i < array.length; i += size) {
    chunks.push(array.slice(i, i + size));
  }
  return chunks;
}

/**
 * 随机打乱数组
 */
export function shuffle<T>(array: T[]): T[] {
  const shuffled = [...array];
  for (let i = shuffled.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
  }
  return shuffled;
}

/**
 * 获取随机数
 */
export function random(min: number, max: number): number {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

/**
 * 获取随机颜色
 */
export function randomColor(): string {
  return '#' + Math.floor(Math.random() * 16777215).toString(16).padStart(6, '0');
}

/**
 * 睡眠函数
 */
export function sleep(ms: number): Promise<void> {
  return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * 重试函数
 */
export async function retry<T>(
  fn: () => Promise<T>,
  maxAttempts = 3,
  delay = 1000,
  backoff = 'linear'
): Promise<T> {
  let lastError: Error;
  
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    try {
      return await fn();
    } catch (error) {
      lastError = error as Error;
      
      if (attempt < maxAttempts) {
        const waitTime = backoff === 'exponential' ? delay * Math.pow(2, attempt - 1) : delay;
        await sleep(waitTime);
      }
    }
  }
  
  throw lastError!;
}

/**
 * 限制并发数的Promise.all
 */
export async function promiseAllLimit<T>(
  promises: (() => Promise<T>)[],
  limit: number
): Promise<T[]> {
  const results: T[] = [];
  const executing: Promise<void>[] = [];
  
  for (let i = 0; i < promises.length; i++) {
    const promise = promises[i]().then(result => {
      results[i] = result;
    });
    
    executing.push(promise);
    
    if (executing.length >= limit) {
      await Promise.race(executing);
      executing.splice(executing.findIndex(p => p === promise), 1);
    }
  }
  
  await Promise.all(executing);
  return results;
}
