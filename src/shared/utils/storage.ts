/**
 * 存储工具函数
 */

export interface StorageOptions {
  ttl?: number; // 过期时间（毫秒）
  prefix?: string; // 键名前缀
}

export interface StorageItem<T = any> {
  value: T;
  timestamp: number;
  ttl?: number;
}

/**
 * 本地存储管理器
 */
export class LocalStorageManager {
  private prefix: string;

  constructor(prefix = 'notion_wp_') {
    this.prefix = prefix;
  }

  /**
   * 设置存储项
   */
  set<T = any>(key: string, value: T, options: StorageOptions = {}): void {
    const item: StorageItem<T> = {
      value,
      timestamp: Date.now(),
      ttl: options.ttl
    };

    const fullKey = this.getFullKey(key, options.prefix);
    
    try {
      window.localStorage.setItem(fullKey, JSON.stringify(item));
    } catch (error) {
      console.warn('LocalStorage set failed:', error);
    }
  }

  /**
   * 获取存储项
   */
  get<T = any>(key: string, defaultValue?: T, prefix?: string): T | undefined {
    const fullKey = this.getFullKey(key, prefix);
    
    try {
      const itemStr = window.localStorage.getItem(fullKey);
      if (!itemStr) return defaultValue;

      const item: StorageItem<T> = JSON.parse(itemStr);
      
      // 检查是否过期
      if (this.isExpired(item)) {
        this.remove(key, prefix);
        return defaultValue;
      }

      return item.value;
    } catch (error) {
      console.warn('LocalStorage get failed:', error);
      return defaultValue;
    }
  }

  /**
   * 移除存储项
   */
  remove(key: string, prefix?: string): void {
    const fullKey = this.getFullKey(key, prefix);
    window.localStorage.removeItem(fullKey);
  }

  /**
   * 清空所有存储项
   */
  clear(prefix?: string): void {
    const targetPrefix = prefix || this.prefix;
    const keys = Object.keys(window.localStorage);

    keys.forEach(key => {
      if (key.startsWith(targetPrefix)) {
        window.localStorage.removeItem(key);
      }
    });
  }

  /**
   * 检查存储项是否存在
   */
  has(key: string, prefix?: string): boolean {
    const fullKey = this.getFullKey(key, prefix);
    const item = window.localStorage.getItem(fullKey);
    
    if (!item) return false;
    
    try {
      const parsedItem: StorageItem = JSON.parse(item);
      return !this.isExpired(parsedItem);
    } catch {
      return false;
    }
  }

  /**
   * 获取所有键名
   */
  keys(prefix?: string): string[] {
    const targetPrefix = prefix || this.prefix;
    const keys = Object.keys(window.localStorage);

    return keys
      .filter(key => key.startsWith(targetPrefix))
      .map(key => key.substring(targetPrefix.length));
  }

  /**
   * 获取存储大小（字节）
   */
  getSize(): number {
    let size = 0;
    const storage = window.localStorage;
    for (const key in storage) {
      if (key.startsWith(this.prefix)) {
        size += (storage as any)[key].length + key.length;
      }
    }
    return size;
  }

  /**
   * 清理过期项
   */
  cleanup(): number {
    let cleanedCount = 0;
    const keys = this.keys();
    
    keys.forEach(key => {
      const item = this.getRawItem(key);
      if (item && this.isExpired(item)) {
        this.remove(key);
        cleanedCount++;
      }
    });
    
    return cleanedCount;
  }

  private getFullKey(key: string, prefix?: string): string {
    return (prefix || this.prefix) + key;
  }

  private isExpired(item: StorageItem): boolean {
    if (!item.ttl) return false;
    return Date.now() - item.timestamp > item.ttl;
  }

  private getRawItem(key: string): StorageItem | null {
    try {
      const itemStr = window.localStorage.getItem(this.getFullKey(key));
      return itemStr ? JSON.parse(itemStr) : null;
    } catch {
      return null;
    }
  }
}

/**
 * 会话存储管理器
 */
export class SessionStorageManager {
  private prefix: string;

  constructor(prefix = 'notion_wp_session_') {
    this.prefix = prefix;
  }

  set<T = any>(key: string, value: T, options: StorageOptions = {}): void {
    const item: StorageItem<T> = {
      value,
      timestamp: Date.now(),
      ttl: options.ttl
    };

    const fullKey = this.getFullKey(key, options.prefix);
    
    try {
      window.sessionStorage.setItem(fullKey, JSON.stringify(item));
    } catch (error) {
      console.warn('SessionStorage set failed:', error);
    }
  }

  get<T = any>(key: string, defaultValue?: T, prefix?: string): T | undefined {
    const fullKey = this.getFullKey(key, prefix);
    
    try {
      const itemStr = window.sessionStorage.getItem(fullKey);
      if (!itemStr) return defaultValue;

      const item: StorageItem<T> = JSON.parse(itemStr);

      if (this.isExpired(item)) {
        this.remove(key, prefix);
        return defaultValue;
      }

      return item.value;
    } catch (error) {
      console.warn('SessionStorage get failed:', error);
      return defaultValue;
    }
  }

  remove(key: string, prefix?: string): void {
    const fullKey = this.getFullKey(key, prefix);
    window.sessionStorage.removeItem(fullKey);
  }

  clear(prefix?: string): void {
    const targetPrefix = prefix || this.prefix;
    const keys = Object.keys(window.sessionStorage);

    keys.forEach(key => {
      if (key.startsWith(targetPrefix)) {
        window.sessionStorage.removeItem(key);
      }
    });
  }

  private getFullKey(key: string, prefix?: string): string {
    return (prefix || this.prefix) + key;
  }

  private isExpired(item: StorageItem): boolean {
    if (!item.ttl) return false;
    return Date.now() - item.timestamp > item.ttl;
  }
}

/**
 * 内存缓存管理器
 */
export class MemoryCache<T = any> {
  private cache = new Map<string, StorageItem<T>>();
  private maxSize: number;

  constructor(maxSize = 100) {
    this.maxSize = maxSize;
  }

  set(key: string, value: T, ttl?: number): void {
    // 如果缓存已满，删除最旧的项
    if (this.cache.size >= this.maxSize) {
      const firstKey = this.cache.keys().next().value;
      if (firstKey) {
        this.cache.delete(firstKey);
      }
    }

    this.cache.set(key, {
      value,
      timestamp: Date.now(),
      ttl
    });
  }

  get(key: string, defaultValue?: T): T | undefined {
    const item = this.cache.get(key);
    if (!item) return defaultValue;

    if (this.isExpired(item)) {
      this.cache.delete(key);
      return defaultValue;
    }

    return item.value;
  }

  has(key: string): boolean {
    const item = this.cache.get(key);
    if (!item) return false;

    if (this.isExpired(item)) {
      this.cache.delete(key);
      return false;
    }

    return true;
  }

  delete(key: string): boolean {
    return this.cache.delete(key);
  }

  clear(): void {
    this.cache.clear();
  }

  size(): number {
    return this.cache.size;
  }

  keys(): string[] {
    return Array.from(this.cache.keys());
  }

  cleanup(): number {
    let cleanedCount = 0;
    for (const [key, item] of this.cache.entries()) {
      if (this.isExpired(item)) {
        this.cache.delete(key);
        cleanedCount++;
      }
    }
    return cleanedCount;
  }

  private isExpired(item: StorageItem<T>): boolean {
    if (!item.ttl) return false;
    return Date.now() - item.timestamp > item.ttl;
  }
}

// 默认实例
export const localStorage = new LocalStorageManager();
export const sessionStorage = new SessionStorageManager();
export const memoryCache = new MemoryCache();
