/**
 * 统计管理器 - 现代化TypeScript版本
 *
 * 从原有admin-interactions.js的统计功能完全迁移，包括：
 * - 统计数据获取和显示
 * - 数据格式化和展示
 *
 * 收口迭代（2026-11-16）：移除单页面刷新功能。后端从未注册
 * notion_to_wordpress_refresh_single，且后台模板没有 .refresh-single
 * 按钮，该链路从入口到请求都是死的。
 */

import { emit } from '../../shared/core/EventBus';
import { AdminUtils } from '../utils/AdminUtils';
import { showError, showInfo } from '../../shared/utils/toast';
import { post } from '../../shared/utils/ajax';

export interface StatsData {
  imported_count: number;
  published_count: number;
  last_update: string | null;
  next_run: string | null;
}

/**
 * 统计管理器类
 */
export class StatsManager {
  private static instance: StatsManager | null = null;
  private refreshTimer: NodeJS.Timeout | null = null;
  private autoRefreshInterval = 30000; // 30秒自动刷新
  private isAutoRefreshEnabled = false;

  constructor() {
    if (StatsManager.instance) {
      return StatsManager.instance;
    }
    StatsManager.instance = this;
    this.init();
  }

  /**
   * 获取单例实例
   */
  static getInstance(): StatsManager {
    if (!StatsManager.instance) {
      StatsManager.instance = new StatsManager();
    }
    return StatsManager.instance;
  }

  /**
   * 初始化统计管理器
   */
  private init(): void {
    this.setupEventHandlers();
    this.fetchStats(); // 初始加载

    console.log('📊 [统计管理器] 已初始化');
  }

  /**
   * 设置事件处理器
   */
  private setupEventHandlers(): void {
    // 统计刷新按钮
    const refreshStatsButton = document.getElementById('refresh-stats');
    if (refreshStatsButton) {
      refreshStatsButton.addEventListener('click', e => {
        e.preventDefault();
        this.fetchStats();
      });
    }

    // 自动刷新开关
    const autoRefreshToggle = document.getElementById(
      'auto-refresh-stats'
    ) as HTMLInputElement;
    if (autoRefreshToggle) {
      autoRefreshToggle.addEventListener('change', () => {
        this.toggleAutoRefresh(autoRefreshToggle.checked);
      });
    }
  }

  /**
   * 获取统计信息
   */
  async fetchStats(): Promise<void> {
    const statCards = document.querySelectorAll(
      '.notion-stats-grid .stat-card h3, .notion-stats-grid .stat-card span'
    );

    // 显示加载状态
    statCards.forEach(card => card.classList.add('loading'));

    try {
      const response = await post('notion_to_wordpress_get_stats', {});

      if (response.data.success) {
        const stats: StatsData = response.data.data;
        this.updateStatsDisplay(stats);
        emit('stats:updated', stats);
      } else {
        throw new Error(response.data.data.message || '获取统计失败');
      }
    } catch (error) {
      console.error('❌ [统计获取] 失败:', error);
      showError(`获取统计失败: ${(error as Error).message}`);
      emit('stats:error', error);
    } finally {
      // 移除加载状态
      statCards.forEach(card => card.classList.remove('loading'));
    }
  }

  /**
   * 更新统计显示
   */
  private updateStatsDisplay(stats: StatsData): void {
    // 更新导入数量
    const importedCountElement = document.querySelector('.stat-imported-count');
    if (importedCountElement) {
      importedCountElement.textContent = (stats.imported_count || 0).toString();
    }

    // 更新发布数量
    const publishedCountElement = document.querySelector(
      '.stat-published-count'
    );
    if (publishedCountElement) {
      publishedCountElement.textContent = (
        stats.published_count || 0
      ).toString();
    }

    // 更新最后更新时间
    const lastUpdateElement = document.querySelector('.stat-last-update');
    if (lastUpdateElement) {
      lastUpdateElement.innerHTML = AdminUtils.formatDateTime(
        stats.last_update
      );
    }

    // 更新下次运行时间
    const nextRunElement = document.querySelector('.stat-next-run');
    if (nextRunElement) {
      const nextRunText = stats.next_run || '未计划';
      nextRunElement.innerHTML = AdminUtils.formatDateTime(nextRunText);
    }

    console.log('📊 [统计显示] 已更新:', stats);
  }

  /**
   * 切换自动刷新
   */
  toggleAutoRefresh(enabled: boolean): void {
    this.isAutoRefreshEnabled = enabled;

    if (enabled) {
      this.startAutoRefresh();
      showInfo('已启用统计自动刷新');
    } else {
      this.stopAutoRefresh();
      showInfo('已禁用统计自动刷新');
    }

    emit('stats:auto-refresh:toggled', enabled);
  }

  /**
   * 开始自动刷新
   */
  private startAutoRefresh(): void {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
    }

    this.refreshTimer = setInterval(() => {
      if (this.isAutoRefreshEnabled && !document.hidden) {
        this.fetchStats();
      }
    }, this.autoRefreshInterval);

    console.log('📊 [自动刷新] 已启动');
  }

  /**
   * 停止自动刷新
   */
  private stopAutoRefresh(): void {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
      this.refreshTimer = null;
    }

    console.log('📊 [自动刷新] 已停止');
  }

  /**
   * 设置自动刷新间隔
   */
  setAutoRefreshInterval(interval: number): void {
    this.autoRefreshInterval = interval;

    if (this.isAutoRefreshEnabled) {
      this.startAutoRefresh(); // 重启以应用新间隔
    }

    emit('stats:auto-refresh:interval:changed', interval);
  }

  /**
   * 获取当前统计数据
   */
  getCurrentStats(): StatsData | null {
    const importedCount = document.querySelector(
      '.stat-imported-count'
    )?.textContent;
    const publishedCount = document.querySelector(
      '.stat-published-count'
    )?.textContent;
    const lastUpdate = document.querySelector('.stat-last-update')?.textContent;
    const nextRun = document.querySelector('.stat-next-run')?.textContent;

    if (!importedCount || !publishedCount) {
      return null;
    }

    return {
      imported_count: parseInt(importedCount) || 0,
      published_count: parseInt(publishedCount) || 0,
      last_update: lastUpdate || null,
      next_run: nextRun || null,
    };
  }

  /**
   * 检查是否启用自动刷新
   */
  isAutoRefreshActive(): boolean {
    return this.isAutoRefreshEnabled;
  }

  /**
   * 销毁统计管理器
   */
  destroy(): void {
    this.stopAutoRefresh();
    StatsManager.instance = null;
    console.log('📊 [统计管理器] 已销毁');
  }
}

// 导出单例实例
export const statsManager = StatsManager.getInstance();
