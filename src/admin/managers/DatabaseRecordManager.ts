/**
 * 数据库记录管理器 - 现代化TypeScript版本
 * 
 * 从原有admin-interactions.js的数据库管理功能完全迁移，包括：
 * - 数据库记录的获取和显示
 * - 分页和搜索功能
 * - 过滤和排序
 * - 状态管理
 */

import { emit } from '../../shared/core/EventBus';
import { post } from '../../shared/utils/ajax';
import { showError, showInfo } from '../../shared/utils/toast';

export interface DatabaseRecord {
  id: string;
  properties: Record<string, any>;
  created_time: string;
  last_edited_time: string;
  url?: string;
  icon?: {
    type: string;
    emoji?: string;
    file?: { url: string };
    external?: { url: string };
  };
  cover?: {
    type: string;
    file?: { url: string };
    external?: { url: string };
  };
}

export interface DatabaseInfo {
  id: string;
  title: string;
  properties: Record<string, any>;
  created_time: string;
  last_edited_time: string;
  url?: string;
}

export interface DatabaseFilter {
  property?: string;
  condition?: string;
  value?: any;
}

export interface DatabaseSort {
  property: string;
  direction: 'ascending' | 'descending';
}

export interface DatabaseQuery {
  database_id: string;
  filter?: DatabaseFilter[];
  sorts?: DatabaseSort[];
  start_cursor?: string;
  page_size?: number;
}

export interface DatabaseRecordManagerOptions {
  pageSize?: number;
  enableSearch?: boolean;
  enableFilter?: boolean;
  enableSort?: boolean;
  autoRefresh?: boolean;
  refreshInterval?: number;
}

export interface DatabaseState {
  loading: boolean;
  records: DatabaseRecord[];
  totalCount: number;
  currentPage: number;
  hasMore: boolean;
  nextCursor?: string;
  error?: string;
}

/**
 * 数据库记录管理器类
 */
export class DatabaseRecordManager {
  private static instance: DatabaseRecordManager | null = null;
  
  private options!: Required<DatabaseRecordManagerOptions>;
  private databases = new Map<string, DatabaseState>();
  private refreshTimers = new Map<string, NodeJS.Timeout>();
  private currentQuery = new Map<string, DatabaseQuery>();

  constructor(options: DatabaseRecordManagerOptions = {}) {
    if (DatabaseRecordManager.instance) {
      return DatabaseRecordManager.instance;
    }
    
    DatabaseRecordManager.instance = this;
    
    this.options = {
      pageSize: 20,
      enableSearch: true,
      enableFilter: true,
      enableSort: true,
      autoRefresh: false,
      refreshInterval: 30000, // 30秒
      ...options
    };
    
    this.init();
  }

  /**
   * 获取单例实例
   */
  static getInstance(options?: DatabaseRecordManagerOptions): DatabaseRecordManager {
    if (!DatabaseRecordManager.instance) {
      DatabaseRecordManager.instance = new DatabaseRecordManager(options);
    }
    return DatabaseRecordManager.instance;
  }

  /**
   * 初始化管理器
   */
  private init(): void {
    this.setupEventListeners();
    
    console.log('📊 [数据库记录管理器] 已初始化');
    emit('database:record:manager:initialized');
  }

  /**
   * 设置事件监听器
   */
  private setupEventListeners(): void {
    // 监听页面可见性变化
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && this.options.autoRefresh) {
        this.refreshAllDatabases();
      }
    });

    // 监听窗口焦点变化
    window.addEventListener('focus', () => {
      if (this.options.autoRefresh) {
        this.refreshAllDatabases();
      }
    });
  }

  /**
   * 获取数据库记录
   */
  async getDatabaseRecords(
    databaseId: string, 
    query: Partial<DatabaseQuery> = {}
  ): Promise<DatabaseRecord[]> {
    const finalQuery: DatabaseQuery = {
      database_id: databaseId,
      page_size: this.options.pageSize,
      ...query
    };

    this.currentQuery.set(databaseId, finalQuery);
    
    // 更新状态为加载中
    this.updateDatabaseState(databaseId, { loading: true, error: undefined });

    try {
      const response = await post('notion_to_wordpress_get_database_records', finalQuery);
      
      if (response && response.data) {
        const { records, has_more, next_cursor, total_count } = response.data;
        
        // 更新状态
        this.updateDatabaseState(databaseId, {
          loading: false,
          records: records || [],
          totalCount: total_count || 0,
          hasMore: has_more || false,
          nextCursor: next_cursor,
          error: undefined
        });

        emit('database:records:loaded', { 
          databaseId, 
          records: records || [], 
          totalCount: total_count || 0 
        });

        return records || [];
      } else {
        throw new Error('获取数据库记录失败');
      }
    } catch (error) {
      console.error('获取数据库记录失败:', error);
      
      this.updateDatabaseState(databaseId, {
        loading: false,
        error: (error as Error).message
      });

      emit('database:records:error', { databaseId, error });
      throw error;
    }
  }

  /**
   * 加载更多记录
   */
  async loadMoreRecords(databaseId: string): Promise<DatabaseRecord[]> {
    const state = this.databases.get(databaseId);
    const query = this.currentQuery.get(databaseId);
    
    if (!state || !query || !state.hasMore || state.loading) {
      return [];
    }

    try {
      const moreQuery = {
        ...query,
        start_cursor: state.nextCursor
      };

      const response = await post('notion_to_wordpress_get_database_records', moreQuery);
      
      if (response && response.data) {
        const { records, has_more, next_cursor } = response.data;
        
        // 合并记录
        const allRecords = [...state.records, ...(records || [])];
        
        this.updateDatabaseState(databaseId, {
          records: allRecords,
          hasMore: has_more || false,
          nextCursor: next_cursor
        });

        emit('database:records:more:loaded', { 
          databaseId, 
          newRecords: records || [], 
          allRecords 
        });

        return records || [];
      } else {
        throw new Error('加载更多记录失败');
      }
    } catch (error) {
      console.error('加载更多记录失败:', error);
      showError(`加载更多记录失败: ${(error as Error).message}`);
      throw error;
    }
  }

  /**
   * 搜索记录
   */
  async searchRecords(
    databaseId: string, 
    searchTerm: string, 
    searchProperty?: string
  ): Promise<DatabaseRecord[]> {
    if (!this.options.enableSearch) {
      console.warn('搜索功能已禁用');
      return [];
    }

    const filter: DatabaseFilter = {
      property: searchProperty || 'title',
      condition: 'contains',
      value: searchTerm
    };

    return this.getDatabaseRecords(databaseId, {
      filter: [filter]
    });
  }

  /**
   * 过滤记录
   */
  async filterRecords(
    databaseId: string, 
    filters: DatabaseFilter[]
  ): Promise<DatabaseRecord[]> {
    if (!this.options.enableFilter) {
      console.warn('过滤功能已禁用');
      return [];
    }

    return this.getDatabaseRecords(databaseId, {
      filter: filters
    });
  }

  /**
   * 排序记录
   */
  async sortRecords(
    databaseId: string, 
    sorts: DatabaseSort[]
  ): Promise<DatabaseRecord[]> {
    if (!this.options.enableSort) {
      console.warn('排序功能已禁用');
      return [];
    }

    return this.getDatabaseRecords(databaseId, {
      sorts: sorts
    });
  }

  /**
   * 刷新数据库记录
   */
  async refreshDatabase(databaseId: string): Promise<void> {
    const query = this.currentQuery.get(databaseId);
    
    if (query) {
      // 重置分页
      const refreshQuery = {
        ...query,
        start_cursor: undefined
      };
      
      await this.getDatabaseRecords(databaseId, refreshQuery);
      showInfo('数据库记录已刷新');
    }
  }

  /**
   * 刷新所有数据库
   */
  async refreshAllDatabases(): Promise<void> {
    const promises = Array.from(this.databases.keys()).map(databaseId => 
      this.refreshDatabase(databaseId).catch(error => {
        console.error(`刷新数据库 ${databaseId} 失败:`, error);
      })
    );

    await Promise.all(promises);
  }

  /**
   * 获取数据库状态
   */
  getDatabaseState(databaseId: string): DatabaseState | undefined {
    return this.databases.get(databaseId);
  }

  /**
   * 获取所有数据库状态
   */
  getAllDatabaseStates(): Map<string, DatabaseState> {
    return new Map(this.databases);
  }

  /**
   * 更新数据库状态
   */
  private updateDatabaseState(databaseId: string, updates: Partial<DatabaseState>): void {
    const currentState = this.databases.get(databaseId) || {
      loading: false,
      records: [],
      totalCount: 0,
      currentPage: 1,
      hasMore: false
    };

    const newState = { ...currentState, ...updates };
    this.databases.set(databaseId, newState);

    emit('database:state:changed', { databaseId, state: newState });
  }

  /**
   * 启动自动刷新
   */
  startAutoRefresh(databaseId: string): void {
    if (!this.options.autoRefresh) {
      return;
    }

    // 清除现有定时器
    this.stopAutoRefresh(databaseId);

    // 设置新定时器
    const timer = setInterval(() => {
      this.refreshDatabase(databaseId).catch(error => {
        console.error(`自动刷新数据库 ${databaseId} 失败:`, error);
      });
    }, this.options.refreshInterval);

    this.refreshTimers.set(databaseId, timer);
  }

  /**
   * 停止自动刷新
   */
  stopAutoRefresh(databaseId: string): void {
    const timer = this.refreshTimers.get(databaseId);
    if (timer) {
      clearInterval(timer);
      this.refreshTimers.delete(databaseId);
    }
  }

  /**
   * 停止所有自动刷新
   */
  stopAllAutoRefresh(): void {
    this.refreshTimers.forEach((timer) => {
      clearInterval(timer);
    });
    this.refreshTimers.clear();
  }

  /**
   * 清理数据库状态
   */
  clearDatabaseState(databaseId: string): void {
    this.databases.delete(databaseId);
    this.currentQuery.delete(databaseId);
    this.stopAutoRefresh(databaseId);
    
    emit('database:state:cleared', { databaseId });
  }

  /**
   * 清理所有状态
   */
  clearAllStates(): void {
    this.databases.clear();
    this.currentQuery.clear();
    this.stopAllAutoRefresh();
    
    emit('database:all:states:cleared');
  }

  /**
   * 获取配置选项
   */
  getOptions(): Required<DatabaseRecordManagerOptions> {
    return { ...this.options };
  }

  /**
   * 更新配置选项
   */
  updateOptions(options: Partial<DatabaseRecordManagerOptions>): void {
    this.options = { ...this.options, ...options };
    emit('database:options:updated', this.options);
  }

  /**
   * 销毁管理器
   */
  destroy(): void {
    this.stopAllAutoRefresh();
    this.clearAllStates();
    
    // 清理事件监听器
    document.removeEventListener('visibilitychange', this.refreshAllDatabases);
    window.removeEventListener('focus', this.refreshAllDatabases);
    
    DatabaseRecordManager.instance = null;
    emit('database:record:manager:destroyed');
    console.log('📊 [数据库记录管理器] 已销毁');
  }
}

// 导出单例实例
export const databaseRecordManager = DatabaseRecordManager.getInstance();

export default DatabaseRecordManager;
