/**
 * 管理界面交互系统 - 现代化TypeScript版本
 * 
 * 完全替代原有的admin-interactions.js，包括：
 * - 所有管理器的统一初始化和管理
 * - 全局事件处理和协调
 * - 向后兼容性支持
 * - 错误处理和恢复
 */

import { emit, on } from '../shared/core/EventBus';
import { AdminUtils } from './utils/AdminUtils';
import { SyncStatusManager, syncStatusManager } from './managers/SyncStatusManager';
import { FormManager, formManager } from './managers/FormManager';
import { StatsManager, statsManager } from './managers/StatsManager';
import { showError, showInfo } from '../shared/utils/toast';

export interface AdminInteractionsConfig {
  enableAutoRefresh?: boolean;
  autoRefreshInterval?: number;
  enableFormValidation?: boolean;
  enableSyncStatusMonitoring?: boolean;
}

/**
 * 管理界面交互系统主类
 */
export class AdminInteractions {
  private static instance: AdminInteractions | null = null;
  private initialized = false;
  private config!: AdminInteractionsConfig;

  // 管理器实例
  private syncStatusManager!: SyncStatusManager;
  private formManager!: FormManager;
  private statsManager!: StatsManager;

  constructor(config: AdminInteractionsConfig = {}) {
    if (AdminInteractions.instance) {
      return AdminInteractions.instance;
    }

    AdminInteractions.instance = this;

    // 初始化配置
    this.config = {
      enableAutoRefresh: true,
      autoRefreshInterval: 30000,
      enableFormValidation: true,
      enableSyncStatusMonitoring: true,
      ...config
    };

    // 初始化管理器实例
    this.syncStatusManager = syncStatusManager;
    this.formManager = formManager;
    this.statsManager = statsManager;
  }

  /**
   * 获取单例实例
   */
  static getInstance(config?: AdminInteractionsConfig): AdminInteractions {
    if (!AdminInteractions.instance) {
      AdminInteractions.instance = new AdminInteractions(config);
    }
    return AdminInteractions.instance;
  }

  /**
   * 初始化管理界面交互系统
   */
  init(): void {
    if (this.initialized) {
      console.warn('⚠️ [管理界面交互] 已经初始化，跳过重复初始化');
      return;
    }

    console.log('🚀 [管理界面交互] 开始初始化...');

    try {
      this.setupGlobalEventHandlers();
      this.setupManagerCoordination();
      this.setupErrorHandling();
      this.setupCompatibilityLayer();
      this.applyConfiguration();
      
      this.initialized = true;
      
      emit('admin:interactions:initialized');
      console.log('✅ [管理界面交互] 初始化完成');
      
      // 显示初始化成功提示（仅在开发模式）
      if (process.env.NODE_ENV === 'development') {
        showInfo('管理界面交互系统已启动');
      }
    } catch (error) {
      console.error('❌ [管理界面交互] 初始化失败:', error);
      showError('管理界面初始化失败，部分功能可能不可用');
      throw error;
    }
  }

  /**
   * 设置全局事件处理器
   */
  private setupGlobalEventHandlers(): void {
    // 页面卸载前的清理
    window.addEventListener('beforeunload', () => {
      this.cleanup();
    });

    // 页面可见性变化处理
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        emit('admin:page:hidden');
      } else {
        emit('admin:page:visible');
      }
    });

    // 全局键盘快捷键
    document.addEventListener('keydown', (e) => {
      this.handleGlobalKeyboard(e);
    });

    // 全局点击事件委托
    document.addEventListener('click', (e) => {
      this.handleGlobalClick(e);
    });

    console.log('🎯 [全局事件] 已设置');
  }

  /**
   * 设置管理器协调
   */
  private setupManagerCoordination(): void {
    // 同步状态变化时更新统计
    on('sync:status:cleared', () => {
      this.statsManager.fetchStats();
    });

    // 表单保存成功后刷新相关状态
    on('form:settings:saved', () => {
      this.syncStatusManager.checkSyncStatus();
    });

    // 统计更新后通知其他组件
    on('stats:updated', (_event, stats) => {
      emit('admin:stats:changed', stats);
    });

    console.log('🔗 [管理器协调] 已设置');
  }

  /**
   * 设置错误处理
   */
  private setupErrorHandling(): void {
    // 全局错误捕获
    window.addEventListener('error', (e) => {
      console.error('🚨 [全局错误]:', e.error);
      
      // 只对我们的代码显示错误提示
      if (e.filename?.includes('notion-to-wordpress') || 
          e.error?.stack?.includes('notion-to-wordpress')) {
        showError('发生了一个错误，请刷新页面重试');
      }
    });

    // Promise 拒绝处理
    window.addEventListener('unhandledrejection', (e) => {
      console.error('🚨 [未处理的Promise拒绝]:', e.reason);
      
      // 防止默认的控制台错误
      e.preventDefault();
    });

    console.log('🛡️ [错误处理] 已设置');
  }

  /**
   * 设置兼容性层
   */
  private setupCompatibilityLayer(): void {
    // 为了向后兼容，在全局对象上暴露一些功能
    const globalNotionWp = (window as any).notionToWp || {};
    
    // 暴露工具函数
    globalNotionWp.utils = {
      debounce: AdminUtils.debounce,
      throttle: AdminUtils.throttle,
      setButtonLoading: AdminUtils.setButtonLoading,
      updateProgress: AdminUtils.updateProgress,
      hideProgress: AdminUtils.hideProgress,
      validateInput: AdminUtils.validateInput,
      formatDateTime: AdminUtils.formatDateTime
    };

    // 暴露管理器实例
    globalNotionWp.managers = {
      syncStatus: this.syncStatusManager,
      form: this.formManager,
      stats: this.statsManager
    };

    // 暴露主实例
    globalNotionWp.adminInteractions = this;

    (window as any).notionToWp = globalNotionWp;

    console.log('🔄 [兼容性层] 已设置');
  }

  /**
   * 应用配置
   */
  private applyConfiguration(): void {
    if (this.config.enableAutoRefresh) {
      this.statsManager.toggleAutoRefresh(true);
      
      if (this.config.autoRefreshInterval) {
        this.statsManager.setAutoRefreshInterval(this.config.autoRefreshInterval);
      }
    }

    if (this.config.enableSyncStatusMonitoring) {
      this.syncStatusManager.startStatusMonitoring();
    }

    console.log('⚙️ [配置应用] 完成:', this.config);
  }

  /**
   * 处理全局键盘事件
   */
  private handleGlobalKeyboard(e: KeyboardEvent): void {
    // Ctrl/Cmd + S 保存设置
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      const settingsForm = document.getElementById('notion-to-wordpress-settings-form') as HTMLFormElement;
      if (settingsForm) {
        e.preventDefault();
        settingsForm.dispatchEvent(new Event('submit'));
      }
    }

    // Ctrl/Cmd + R 刷新统计（在我们的页面上）
    if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
      const isOurPage = document.querySelector('.notion-wp-admin');
      if (isOurPage) {
        e.preventDefault();
        this.statsManager.fetchStats();
      }
    }
  }

  /**
   * 处理全局点击事件
   */
  private handleGlobalClick(e: Event): void {
    const target = e.target as HTMLElement;
    
    // 处理带有特殊数据属性的元素
    if (target.dataset.notionAction) {
      e.preventDefault();
      this.handleDataAction(target, target.dataset.notionAction);
    }
  }

  /**
   * 处理数据动作
   */
  private handleDataAction(_element: HTMLElement, action: string): void {
    switch (action) {
      case 'refresh-stats':
        this.statsManager.fetchStats();
        break;
        
      case 'check-sync-status':
        this.syncStatusManager.checkSyncStatus();
        break;
        
      case 'clear-sync-status':
        this.syncStatusManager.clearSyncStatus();
        break;
        
      default:
        console.warn('🤷 [数据动作] 未知动作:', action);
    }
  }

  /**
   * 获取管理器实例
   */
  getSyncStatusManager(): SyncStatusManager {
    return this.syncStatusManager;
  }

  getFormManager(): FormManager {
    return this.formManager;
  }

  getStatsManager(): StatsManager {
    return this.statsManager;
  }

  /**
   * 获取配置
   */
  getConfig(): AdminInteractionsConfig {
    return { ...this.config };
  }

  /**
   * 更新配置
   */
  updateConfig(newConfig: Partial<AdminInteractionsConfig>): void {
    this.config = { ...this.config, ...newConfig };
    this.applyConfiguration();
    emit('admin:config:updated', this.config);
  }

  /**
   * 检查是否已初始化
   */
  isInitialized(): boolean {
    return this.initialized;
  }

  /**
   * 获取系统状态
   */
  getSystemStatus(): {
    initialized: boolean;
    managersActive: {
      syncStatus: boolean;
      form: boolean;
      stats: boolean;
    };
    config: AdminInteractionsConfig;
  } {
    return {
      initialized: this.initialized,
      managersActive: {
        syncStatus: this.syncStatusManager.hasActivSync(),
        form: true, // FormManager 总是活跃的
        stats: this.statsManager.isAutoRefreshActive()
      },
      config: this.config
    };
  }

  /**
   * 清理资源
   */
  cleanup(): void {
    if (!this.initialized) return;

    console.log('🧹 [管理界面交互] 开始清理...');

    try {
      this.syncStatusManager.destroy();
      this.formManager.destroy();
      this.statsManager.destroy();
      
      this.initialized = false;
      AdminInteractions.instance = null;
      
      emit('admin:interactions:destroyed');
      console.log('✅ [管理界面交互] 清理完成');
    } catch (error) {
      console.error('❌ [管理界面交互] 清理失败:', error);
    }
  }

  /**
   * 销毁实例
   */
  destroy(): void {
    this.cleanup();
  }
}

// 导出单例实例
export const adminInteractions = AdminInteractions.getInstance();

// 自动初始化（如果在管理页面）
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    if (document.querySelector('.notion-wp-admin')) {
      adminInteractions.init();
    }
  });
} else {
  if (document.querySelector('.notion-wp-admin')) {
    adminInteractions.init();
  }
}

export default AdminInteractions;
