/**
 * 管理界面入口文件
 */

import { ready } from '../shared/utils/dom';
import { eventBus, on, emit } from '../shared/core/EventBus';
import {
  appStateManager,
  queueManager,
  progressManager,
  lazyLoader,
  resourcePreloader,
  performanceMonitor,
  codeSplitter,
} from '../shared/core';
import { componentManager } from './components';
import { adminInteractions } from './AdminInteractions';
import { webhookManager } from './managers/WebhookManager';
import { databaseRecordManager } from './managers/DatabaseRecordManager';
import { logManager } from './managers/LogManager';
import { settingsManager } from './managers/SettingsManager';
import { errorManager } from './managers/ErrorManager';
import { adminControls } from './AdminControls';
import { showSuccess, showError } from '../shared/utils/toast';
import { AdminUtils } from './utils/AdminUtils';

// 导入样式
import '../styles/admin/admin.scss';

/**
 * 管理界面主类
 */
class AdminApp {
  private initialized = false;

  /**
   * 初始化应用
   */
  init(): void {
    if (this.initialized) {
      return;
    }

    console.log('🚀 Notion to WordPress Admin App initializing...');

    // 设置事件总线调试模式
    if (window.notionToWp?.debug_mode) {
      eventBus.setDebug(true);
    }

    // 初始化组件
    this.initializeComponents();

    // 绑定全局事件
    this.bindGlobalEvents();

    this.initialized = true;
    emit('admin:initialized');

    console.log('✅ Notion to WordPress Admin App initialized');
  }

  /**
   * 初始化组件
   */
  private initializeComponents(): void {
    // 初始化性能优化系统
    this.initializePerformanceOptimization();

    // 初始化状态管理系统
    this.initializeStateManagement();

    // 初始化同步功能
    this.initializeSyncFeatures();

    // 初始化队列监控
    this.initializeQueueMonitoring();

    // 初始化组件管理器
    this.initializeComponentManager();

    // 初始化当前后台页面的直接交互控件
    adminControls.init();

    // 初始化管理界面交互系统
    this.initializeAdminInteractions();

    // 初始化Webhook处理系统
    this.initializeWebhookManager();

    // 初始化数据库视图管理系统
    this.initializeDatabaseManager();

    // 初始化日志管理系统
    this.initializeLogManager();

    // 初始化设置管理系统
    this.initializeSettingsManager();

    // 初始化错误处理系统
    this.initializeErrorManager();

    emit('admin:components:init');
  }

  /**
   * 初始化组件管理器
   */
  private initializeComponentManager(): void {
    // 初始化组件管理器
    componentManager.init();

    // 监听组件事件
    on('component:mount', (_event, data) => {
      console.log('组件已挂载:', data.component.constructor.name);
    });

    on('component:unmount', (_event, data) => {
      console.log('组件已卸载:', data.component.constructor.name);
    });

    on('tab:change', (_event, data) => {
      console.log('标签页切换:', data.activeTab);
      this.handleTabChange(data.activeTab, data.previousTab);
    });

    on('form:submit', (_event, data) => {
      console.log('表单提交:', data.formData);
      this.handleFormSubmit(data);
    });

    on('form:success', (_event, data) => {
      console.log('表单提交成功:', data.result);
    });

    console.log('✅ 组件管理器已初始化');
  }

  /**
   * 初始化性能优化系统
   */
  private initializePerformanceOptimization(): void {
    // 开始性能监控
    performanceMonitor.startTimer('admin_init');

    // 预加载关键资源
    resourcePreloader.preloadCritical().catch(console.error);

    // 智能预加载代码块
    codeSplitter.smartPreload().catch(console.error);

    // 监听性能事件
    on('performance:metric', (_event, metric) => {
      if (metric.name === 'long_task_duration' && metric.value > 50) {
        console.warn('Long task detected:', metric);
      }
    });

    on('performance:report', (_event, report) => {
      console.log('Performance report:', report.summary);
    });

    // 监听懒加载事件
    on('lazy:loaded', (_event, data) => {
      console.log('Lazy module loaded:', data.moduleId);
    });

    on('lazy:error', (_event, data) => {
      console.error('Lazy module failed:', data.moduleId, data.error);
    });

    // 监听资源预加载事件
    on('resource:preloaded', (_event, result) => {
      if (!result.success) {
        console.warn('Resource preload failed:', result.url, result.error);
      }
    });

    console.log('✅ 性能优化系统已初始化');
  }

  /**
   * 初始化管理界面交互系统
   */
  private initializeAdminInteractions(): void {
    // 初始化现代化的管理界面交互系统
    adminInteractions.init();

    // 监听交互系统事件
    on('admin:interactions:initialized', () => {
      console.log('✅ 管理界面交互系统已初始化');
    });

    on('admin:interactions:destroyed', () => {
      console.log('🔥 管理界面交互系统已销毁');
    });

    // 监听统计更新事件
    on('admin:stats:changed', (_event, stats) => {
      console.log('📊 统计数据已更新:', stats);
    });

    console.log('✅ 管理界面交互系统已初始化');
  }

  /**
   * 初始化Webhook处理系统
   */
  private initializeWebhookManager(): void {
    // Webhook管理器已经通过导入自动初始化
    // 这里可以设置一些全局事件监听

    // 监听Webhook事件
    on('webhook:status:changed', (_event, data) => {
      console.log('🔗 Webhook状态变化:', data.status);
    });

    on('webhook:tested', (_event, data) => {
      console.log('🧪 Webhook测试完成:', data.result);
    });

    on('webhook:token:generated', (_event, _data) => {
      console.log('🔑 Webhook令牌已生成');
    });

    on('webhook:validation:result', (_event, data) => {
      if (!data.result.isValid) {
        console.warn('⚠️ Webhook配置验证失败:', data.result.errors);
      }
    });

    // 确保Webhook管理器可用
    if (webhookManager) {
      console.log('✅ Webhook处理系统已初始化');
    }
  }

  /**
   * 初始化数据库视图管理系统
   */
  private initializeDatabaseManager(): void {
    // 数据库记录管理器已经通过导入自动初始化
    // 这里可以设置一些全局事件监听

    // 监听数据库记录事件
    on('database:records:loaded', (_event, data) => {
      console.log(
        '📊 数据库记录已加载:',
        data.databaseId,
        '记录数:',
        data.totalCount
      );
    });

    on('database:records:error', (_event, data) => {
      console.error('📊 数据库记录加载错误:', data.databaseId, data.error);
    });

    on('database:state:changed', (_event, data) => {
      console.log('📊 数据库状态变化:', data.databaseId, data.state);
    });

    on('database:view:type:changed', (_event, data) => {
      console.log('📊 数据库视图类型变化:', data.databaseId, data.viewType);
    });

    // 确保数据库记录管理器可用
    if (databaseRecordManager) {
      console.log('✅ 数据库视图管理系统已初始化');
    }
  }

  /**
   * 初始化日志管理系统
   */
  private initializeLogManager(): void {
    // 日志管理器已经通过导入自动初始化
    // 这里可以设置一些全局事件监听

    // 监听日志管理器事件
    on('log:manager:initialized', () => {
      console.log('📋 日志管理器已初始化');
    });

    on('log:files:loaded', (_event, data) => {
      console.log('📋 日志文件已加载:', data.files.length, '个文件');
    });

    on('log:entries:loaded', (_event, data) => {
      console.log('📋 日志条目已加载:', data.logs.length, '条记录');
    });

    on('log:content:loaded', (_event, data) => {
      console.log('📋 日志内容已加载:', data.file);
    });

    on('log:cleared', () => {
      console.log('📋 日志已清除');
    });

    on('log:exported', (_event, data) => {
      console.log('📋 日志已导出:', data.format, data.count, '条记录');
    });

    on('log:auto:refresh:started', () => {
      console.log('📋 日志自动刷新已启动');
    });

    on('log:auto:refresh:stopped', () => {
      console.log('📋 日志自动刷新已停止');
    });

    // 确保日志管理器可用
    if (logManager) {
      console.log('✅ 日志管理系统已初始化');
    }
  }

  /**
   * 初始化设置管理系统
   */
  private initializeSettingsManager(): void {
    // 设置管理器已经通过导入自动初始化
    // 这里可以设置一些全局事件监听

    // 监听设置管理器事件
    on('settings:manager:initialized', () => {
      console.log('⚙️ 设置管理器已初始化');
    });

    on('settings:loaded', (_event, data) => {
      console.log(
        '⚙️ 设置已加载:',
        Object.keys(data.settings).length,
        '个配置项'
      );
    });

    on('settings:saved', (_event, data) => {
      console.log(
        '⚙️ 设置已保存:',
        Object.keys(data.settings).length,
        '个配置项'
      );
    });

    on('settings:reset', (_event, data) => {
      console.log(
        '⚙️ 设置已重置:',
        Object.keys(data.settings).length,
        '个配置项'
      );
    });

    on('settings:changed', (_event, data) => {
      console.log('⚙️ 设置项变化:', data.key, '=', data.value);
    });

    on('settings:imported', (_event, data) => {
      console.log(
        '⚙️ 设置已导入:',
        Object.keys(data.settings).length,
        '个配置项'
      );
    });

    on('settings:exported', (_event, data) => {
      console.log('⚙️ 设置已导出:', data.filename);
    });

    on('settings:connection:success', () => {
      console.log('⚙️ 连接测试成功');
    });

    on('settings:connection:failed', (_event, data) => {
      console.log('⚙️ 连接测试失败:', data.message);
    });

    // 确保设置管理器可用
    if (settingsManager) {
      console.log('✅ 设置管理系统已初始化');
    }
  }

  /**
   * 初始化错误处理系统
   */
  private initializeErrorManager(): void {
    // 错误管理器已经通过导入自动初始化
    // 这里可以设置一些全局事件监听

    // 监听错误管理器事件
    on('error:manager:initialized', () => {
      console.log('🛡️ 错误管理器已初始化');
    });

    on('error:handled', (_event, data) => {
      console.log(
        '🚨 错误已处理:',
        data.errorInfo.type,
        data.errorInfo.message
      );
    });

    on('error:resolved', (_event, data) => {
      console.log('✅ 错误已解决:', data.errorInfo.id);
    });

    on('error:retry', (_event, data) => {
      console.log(
        '🔄 错误重试:',
        data.errorInfo.id,
        '第',
        data.retryCount,
        '次'
      );
    });

    on('error:admin:notify', (_event, data) => {
      console.log('📧 管理员通知:', data.message, data.errorInfo);
    });

    on('error:history:cleared', () => {
      console.log('🗑️ 错误历史已清除');
    });

    // 确保错误管理器可用
    if (errorManager) {
      console.log('✅ 错误处理系统已初始化');
    }
  }

  /**
   * 初始化状态管理系统
   */
  private initializeStateManagement(): void {
    // 监听状态变化
    appStateManager.subscribe((state, prevState, action) => {
      console.log('状态变化:', { state, prevState, action });

      // 根据状态变化更新UI
      this.updateUIFromState(state, prevState);
    });

    // 设置初始状态
    appStateManager.setState({
      ui: {
        ...appStateManager.getState().ui,
        activeTab: this.getCurrentTab(),
      },
    });

    console.log('✅ 状态管理系统已初始化');
  }

  /**
   * 初始化同步功能
   *
   * 收口迭代（2026-11-10）：进度条以 PHP 直出的 #sync-progress DOM 为唯一通道，
   * AdminUtils.updateProgress / hideProgress 是唯一写入者。由于同步在单次长 AJAX
   * 内完成、后端没有可轮询的中间进度，这里只给「开始 / 完成 / 失败」的诚实状态，
   * 不伪造中间百分比。toast 由 SyncManager 负责，避免重复提示。
   */
  private initializeSyncFeatures(): void {
    on('sync:start', () => {
      AdminUtils.updateProgress(0, '正在同步...');
    });

    on('sync:complete', () => {
      AdminUtils.updateProgress(100, '同步完成');
      setTimeout(() => AdminUtils.hideProgress(), 3000);
    });

    on('sync:error', (_event, data) => {
      const message = data?.error?.message || '未知错误';
      AdminUtils.updateProgress(0, `同步失败: ${message}`);
      setTimeout(() => AdminUtils.hideProgress(), 5000);
    });

    console.log('✅ 同步功能已初始化');
  }

  /**
   * 初始化队列监控
   */
  private initializeQueueMonitoring(): void {
    // 开始队列监控
    queueManager.startMonitoring();

    // 监听队列状态更新
    on('queue:status:update', (_event, status) => {
      console.log('队列状态更新:', status);
      this.updateQueueDisplay(status);
    });

    console.log('✅ 队列监控已初始化');
  }

  /**
   * 根据状态更新UI
   */
  private updateUIFromState(state: any, prevState: any): void {
    // 更新队列状态显示
    if (state.queue !== prevState.queue) {
      this.updateQueueDisplay(state.queue);
    }

    // 更新活动标签
    if (state.ui.activeTab !== prevState.ui.activeTab) {
      this.updateActiveTab(state.ui.activeTab);
    }
  }

  /**
   * 更新队列状态显示
   */
  private updateQueueDisplay(queueState: any): void {
    const elements = {
      total: document.querySelector('#queue-total'),
      pending: document.querySelector('#queue-pending'),
      processing: document.querySelector('#queue-processing'),
      completed: document.querySelector('#queue-completed'),
      failed: document.querySelector('#queue-failed'),
    };

    Object.entries(elements).forEach(([key, element]) => {
      if (element && queueState[key + '_jobs'] !== undefined) {
        element.textContent = queueState[key + '_jobs'].toString();
      }
    });
  }

  /**
   * 更新活动标签
   */
  private updateActiveTab(activeTab: string): void {
    // 移除所有活动状态
    document.querySelectorAll('.nav-tab').forEach(tab => {
      tab.classList.remove('nav-tab-active');
    });

    // 添加活动状态到当前标签
    const currentTab = document.querySelector(`[data-tab="${activeTab}"]`);
    if (currentTab) {
      currentTab.classList.add('nav-tab-active');
    }
  }

  /**
   * 获取当前标签
   */
  private getCurrentTab(): string {
    const activeTab = document.querySelector('.nav-tab-active');
    return activeTab?.getAttribute('data-tab') || 'sync';
  }

  /**
   * 绑定全局事件
   */
  private bindGlobalEvents(): void {
    // 监听页面卸载事件
    window.addEventListener('beforeunload', () => {
      emit('admin:beforeunload');
    });

    // 监听窗口大小变化
    window.addEventListener('resize', () => {
      emit('admin:resize', {
        width: window.innerWidth,
        height: window.innerHeight,
      });
    });

    // 监听页面可见性变化
    document.addEventListener('visibilitychange', () => {
      emit('admin:visibility:change', {
        visible: !document.hidden,
      });
    });
  }

  /**
   * 清理队列
   */
  async cleanupQueue(): Promise<void> {
    try {
      const result = await queueManager.cleanupQueue({
        removeCompleted: true,
        removeFailed: false,
        olderThan: 24,
      });

      if (result.success) {
        showSuccess(result.message);
      } else {
        showError(result.message);
      }
    } catch (error) {
      showError(`清理队列失败: ${(error as Error).message}`);
    }
  }

  /**
   * 处理标签页切换
   */
  private handleTabChange(
    activeTab: string,
    _previousTab: string | null
  ): void {
    performanceMonitor.startTimer(`tab_switch_${activeTab}`);

    // 根据活动标签执行特定逻辑
    switch (activeTab) {
      case 'queue':
        // 刷新队列状态
        this.refreshQueueStatus();
        break;
      case 'settings':
        // 懒加载设置模块
        this.loadSettingsModule();
        break;
      case 'logs':
        // 懒加载日志模块
        this.loadLogsModule();
        break;
    }

    // 更新URL参数
    const url = new URL(window.location.href);
    url.searchParams.set('tab', activeTab);
    window.history.replaceState({}, '', url.toString());

    performanceMonitor.endTimer(`tab_switch_${activeTab}`, { tab: activeTab });
  }

  /**
   * 处理表单提交
   */
  private async handleFormSubmit(data: any): Promise<void> {
    try {
      // 这里可以添加通用的表单提交逻辑
      console.log('处理表单提交:', data);
    } catch (error) {
      console.error('表单提交处理失败:', error);
      showError(`表单提交失败: ${(error as Error).message}`);
    }
  }

  /**
   * 刷新队列状态
   */
  private async refreshQueueStatus(): Promise<void> {
    try {
      const status = await queueManager.getQueueStatus();
      if (status) {
        appStateManager.updateQueueStatus(status);
      }
    } catch (error) {
      console.error('刷新队列状态失败:', error);
    }
  }

  /**
   * 懒加载设置模块
   */
  private async loadSettingsModule(): Promise<void> {
    try {
      const settingsContainer = document.querySelector('#settings-container');
      if (!settingsContainer) {
        console.warn('Settings container not found');
        return;
      }

      // 检查是否已经加载
      if (codeSplitter.isChunkLoaded('admin-settings')) {
        console.log('Settings module already loaded');
        return;
      }

      // 显示加载指示器
      settingsContainer.innerHTML =
        '<div class="loading-indicator">加载设置中...</div>';

      // 懒加载设置模块
      const settingsModule = await codeSplitter.loadChunk('admin-settings');

      if (settingsModule?.default) {
        settingsModule.default(settingsContainer as HTMLElement);
        console.log('Settings module loaded and initialized');
      }
    } catch (error) {
      console.error('Failed to load settings module:', error);
      showError('加载设置模块失败');
    }
  }

  /**
   * 懒加载日志模块
   */
  private async loadLogsModule(): Promise<void> {
    try {
      const logsContainer = document.querySelector('#logs-container');
      if (!logsContainer) {
        console.warn('Logs container not found');
        return;
      }

      // 检查是否已经加载
      if (codeSplitter.isChunkLoaded('admin-logs')) {
        console.log('Logs module already loaded');
        return;
      }

      // 显示加载指示器
      logsContainer.innerHTML =
        '<div class="loading-indicator">加载日志中...</div>';

      // 懒加载日志模块
      const logsModule = await codeSplitter.loadChunk('admin-logs');

      if (logsModule?.default) {
        logsModule.default(logsContainer as HTMLElement);
        console.log('Logs module loaded and initialized');
      }
    } catch (error) {
      console.error('Failed to load logs module:', error);
      showError('加载日志模块失败');
    }
  }

  /**
   * 销毁应用
   */
  destroy(): void {
    if (!this.initialized) {
      return;
    }

    // 记录销毁时间
    performanceMonitor.endTimer('admin_init', { phase: 'destroy' });

    // 停止所有管理器
    queueManager.stopMonitoring();
    progressManager.hide();
    componentManager.destroyAll();

    // 清理性能优化系统
    performanceMonitor.cleanup();
    lazyLoader.cleanup();
    resourcePreloader.cleanup();
    codeSplitter.cleanup();

    // 清理管理界面交互系统
    adminInteractions.destroy();

    // 清理Webhook处理系统
    webhookManager.destroy();

    // 清理数据库视图管理系统
    databaseRecordManager.destroy();

    // 清理日志管理系统
    logManager.destroy();

    // 清理设置管理系统
    settingsManager.destroy();

    // 清理错误处理系统
    errorManager.destroy();

    emit('admin:destroy');
    eventBus.removeAllListeners();
    this.initialized = false;

    console.log('🔥 Notion to WordPress Admin App destroyed');
  }
}

/**
 * 创建全局应用实例
 */
const adminApp = new AdminApp();

/**
 * 导出到全局作用域
 */
declare global {
  interface Window {
    NotionWpAdmin: AdminApp;
  }
}

window.NotionWpAdmin = adminApp;

/**
 * DOM准备就绪后初始化
 */
ready(() => {
  adminApp.init();
});

/**
 * 导出主要功能
 */
export { adminApp };
export default adminApp;
