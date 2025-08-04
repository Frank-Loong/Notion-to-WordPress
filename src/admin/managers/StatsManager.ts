/**
 * 统计管理器 - 现代化TypeScript版本
 * 
 * 从原有admin-interactions.js的统计功能完全迁移，包括：
 * - 统计数据获取和显示
 * - 单页面刷新功能
 * - 数据格式化和展示
 */

import { emit } from '../../shared/core/EventBus';
import { AdminUtils } from '../utils/AdminUtils';
import { showSuccess, showError, showInfo } from '../../shared/utils/toast';
import { post } from '../../shared/utils/ajax';

export interface StatsData {
  imported_count: number;
  published_count: number;
  last_update: string | null;
  next_run: string | null;
}

export interface RefreshSingleOptions {
  pageId: string;
  showOverlay?: boolean;
  timeout?: number;
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
    // 单页面刷新按钮
    document.addEventListener('click', (e) => {
      const target = e.target as HTMLElement;
      
      if (target.classList.contains('refresh-single')) {
        e.preventDefault();
        this.handleRefreshSingle(target);
      }
    });

    // 统计刷新按钮
    const refreshStatsButton = document.getElementById('refresh-stats');
    if (refreshStatsButton) {
      refreshStatsButton.addEventListener('click', (e) => {
        e.preventDefault();
        this.fetchStats();
      });
    }

    // 自动刷新开关
    const autoRefreshToggle = document.getElementById('auto-refresh-stats') as HTMLInputElement;
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
    const statCards = document.querySelectorAll('.notion-stats-grid .stat-card h3, .notion-stats-grid .stat-card span');
    
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
    const publishedCountElement = document.querySelector('.stat-published-count');
    if (publishedCountElement) {
      publishedCountElement.textContent = (stats.published_count || 0).toString();
    }

    // 更新最后更新时间
    const lastUpdateElement = document.querySelector('.stat-last-update');
    if (lastUpdateElement) {
      lastUpdateElement.innerHTML = AdminUtils.formatDateTime(stats.last_update);
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
   * 处理单页面刷新
   */
  private async handleRefreshSingle(element: HTMLElement): Promise<void> {
    const pageId = element.dataset.pageId;
    
    if (!pageId || typeof pageId !== 'string' || pageId.trim() === '') {
      showError('无效的页面ID');
      return;
    }

    const notionToWp = (window as any).notionToWp;
    if (!notionToWp?.nonce || !notionToWp?.ajax_url) {
      showError('安全参数缺失');
      return;
    }

    await this.refreshSinglePage({ pageId, showOverlay: true });
  }

  /**
   * 刷新单个页面
   */
  async refreshSinglePage(options: RefreshSingleOptions): Promise<void> {
    const { pageId, showOverlay = false, timeout = 60000 } = options;
    
    let overlay: HTMLElement | null = null;
    
    if (showOverlay) {
      overlay = this.showLoadingOverlay();
    }

    try {
      const response = await this.performSingleRefresh(pageId, timeout);

      if (response.success) {
        showSuccess('页面刷新成功');
        
        // 刷新统计信息
        await this.fetchStats();
        
        emit('stats:single:refreshed', { pageId, response });
      } else {
        throw new Error(response.data?.message || '刷新失败');
      }
    } catch (error) {
      console.error('❌ [单页刷新] 失败:', error);
      
      let errorMessage = '页面刷新失败';
      if ((error as Error).message.includes('timeout')) {
        errorMessage = '刷新超时，请稍后重试';
      } else if ((error as Error).message) {
        errorMessage += `: ${(error as Error).message}`;
      }
      
      showError(errorMessage);
      emit('stats:single:error', { pageId, error });
    } finally {
      if (overlay) {
        this.hideLoadingOverlay(overlay);
      }
    }
  }

  /**
   * 执行单页面刷新请求
   */
  private async performSingleRefresh(pageId: string, timeout: number): Promise<any> {
    const notionToWp = (window as any).notionToWp;
    
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      
      xhr.open('POST', notionToWp.ajax_url);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.timeout = timeout;
      
      xhr.onload = () => {
        try {
          const response = JSON.parse(xhr.responseText);
          resolve(response);
        } catch (error) {
          reject(new Error('响应解析失败'));
        }
      };
      
      xhr.onerror = () => reject(new Error('网络错误'));
      xhr.ontimeout = () => reject(new Error('请求超时'));
      
      const params = new URLSearchParams({
        action: 'notion_to_wordpress_refresh_single',
        nonce: notionToWp.nonce,
        page_id: pageId
      });
      
      xhr.send(params.toString());
    });
  }

  /**
   * 显示加载遮罩
   */
  private showLoadingOverlay(): HTMLElement {
    const overlay = document.createElement('div');
    overlay.className = 'notion-loading-overlay';
    overlay.innerHTML = `
      <div class="loading-content">
        <div class="spinner"></div>
        <p>正在刷新页面...</p>
      </div>
    `;
    
    overlay.style.cssText = `
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
    `;
    
    document.body.appendChild(overlay);
    
    // 淡入动画
    setTimeout(() => {
      overlay.style.opacity = '1';
    }, 10);
    
    return overlay;
  }

  /**
   * 隐藏加载遮罩
   */
  private hideLoadingOverlay(overlay: HTMLElement): void {
    overlay.style.opacity = '0';
    
    setTimeout(() => {
      if (overlay.parentNode) {
        overlay.parentNode.removeChild(overlay);
      }
    }, 300);
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
    const importedCount = document.querySelector('.stat-imported-count')?.textContent;
    const publishedCount = document.querySelector('.stat-published-count')?.textContent;
    const lastUpdate = document.querySelector('.stat-last-update')?.textContent;
    const nextRun = document.querySelector('.stat-next-run')?.textContent;
    
    if (!importedCount || !publishedCount) {
      return null;
    }
    
    return {
      imported_count: parseInt(importedCount) || 0,
      published_count: parseInt(publishedCount) || 0,
      last_update: lastUpdate || null,
      next_run: nextRun || null
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
