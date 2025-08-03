/**
 * Zustand状态管理中心
 * 
 * 统一导出所有状态管理store，提供类型安全的状态管理解决方案
 * 
 * 架构设计：
 * - syncStore: 管理同步状态、进度更新、SSE连接
 * - settingsStore: 管理设置数据、验证、连接测试
 * - uiStore: 管理UI状态、通知、模态框、主题
 * 
 * 特性：
 * - 状态持久化：关键状态自动保存到localStorage
 * - 类型安全：完整的TypeScript类型支持
 * - 性能优化：精确的状态订阅，避免不必要的重渲染
 * - 开发体验：支持Redux DevTools调试
 */

// ==================== Store导出 ====================

// 同步状态管理
export { useSyncStore } from './syncStore';
export type { SyncStore, SyncState, SyncActions } from './syncStore';

// 设置状态管理
export { useSettingsStore } from './settingsStore';
export type { SettingsStore, SettingsState, SettingsActions } from './settingsStore';

// UI状态管理
export { useUIStore } from './uiStore';
export type { 
  UIStore, 
  UIState, 
  UIActions, 
  TabType, 
  Notification, 
  Modal, 
  NotificationType 
} from './uiStore';

// ==================== 组合Hooks ====================

import { useSyncStore } from './syncStore';
import { useSettingsStore } from './settingsStore';
import { useUIStore } from './uiStore';

/**
 * 组合Hook：获取所有store的状态
 * 用于需要访问多个store的组件
 */
export const useAllStores = () => {
  const syncStore = useSyncStore();
  const settingsStore = useSettingsStore();
  const uiStore = useUIStore();

  return {
    sync: syncStore,
    settings: settingsStore,
    ui: uiStore,
  };
};

/**
 * 组合Hook：获取应用初始化状态
 * 用于判断应用是否已完成初始化
 */
export const useAppInitialized = () => {
  const settingsLoaded = useSettingsStore(state => state.settings !== null);
  const syncStoreReady = useSyncStore(() => true); // 同步store总是可用的
  const uiStoreReady = useUIStore(() => true); // UI store总是可用的

  return settingsLoaded && syncStoreReady && uiStoreReady;
};

/**
 * 组合Hook：获取应用加载状态
 * 用于显示全局加载指示器
 */
export const useAppLoading = () => {
  const syncLoading = useSyncStore(state => state.isRunning);
  const settingsLoading = useSettingsStore(state => state.isLoading || state.isSaving);
  const uiLoading = useUIStore(state => state.globalLoading);

  return {
    isLoading: syncLoading || settingsLoading || uiLoading,
    syncLoading,
    settingsLoading,
    uiLoading,
  };
};

/**
 * 组合Hook：获取错误状态
 * 用于统一的错误处理和显示
 */
export const useAppErrors = () => {
  const syncError = useSyncStore(state => state.error);
  const settingsError = useSettingsStore(state => state.error);
  
  const errors = [syncError, settingsError].filter(Boolean);
  
  return {
    hasErrors: errors.length > 0,
    errors,
    syncError,
    settingsError,
  };
};

// ==================== 状态选择器 ====================

/**
 * 同步状态选择器
 * 提供常用的同步状态组合
 */
export const syncSelectors = {
  // 同步进行状态
  syncProgress: (state: ReturnType<typeof useSyncStore.getState>) => ({
    isRunning: state.isRunning,
    progress: state.progress,
    currentStep: state.currentStep,
    status: state.status,
  }),
  
  // 同步统计信息
  syncStats: (state: ReturnType<typeof useSyncStore.getState>) => state.stats,
  
  // SSE连接状态
  sseStatus: (state: ReturnType<typeof useSyncStore.getState>) => ({
    connected: state.sseConnected,
    eventSource: state.sseEventSource,
  }),
};

/**
 * 设置状态选择器
 * 提供常用的设置状态组合
 */
export const settingsSelectors = {
  // 连接配置
  connectionConfig: (state: ReturnType<typeof useSettingsStore.getState>) => ({
    apiKey: state.settings?.api_key || '',
    databaseId: state.settings?.database_id || '',
    connectionStatus: state.connectionStatus,
  }),
  
  // 字段映射
  fieldMapping: (state: ReturnType<typeof useSettingsStore.getState>) => ({
    mapping: state.settings?.field_mapping,
    customMapping: state.settings?.custom_field_mapping,
  }),
  
  // 性能配置
  performanceConfig: (state: ReturnType<typeof useSettingsStore.getState>) => 
    state.settings?.performance_config,
  
  // 保存状态
  saveStatus: (state: ReturnType<typeof useSettingsStore.getState>) => ({
    isSaving: state.isSaving,
    hasUnsavedChanges: state.hasUnsavedChanges,
    lastSaved: state.lastSaved,
  }),
};

/**
 * UI状态选择器
 * 提供常用的UI状态组合
 */
export const uiSelectors = {
  // 导航状态
  navigation: (state: ReturnType<typeof useUIStore.getState>) => ({
    activeTab: state.activeTab,
    tabHistory: state.tabHistory,
    sidebarCollapsed: state.sidebarCollapsed,
  }),
  
  // 通知状态
  notifications: (state: ReturnType<typeof useUIStore.getState>) => state.notifications,
  
  // 模态框状态
  modals: (state: ReturnType<typeof useUIStore.getState>) => state.modals,
  
  // 主题和响应式状态
  display: (state: ReturnType<typeof useUIStore.getState>) => ({
    theme: state.theme,
    isMobile: state.isMobile,
    isTablet: state.isTablet,
    isPageVisible: state.isPageVisible,
  }),
};

// ==================== 工具函数 ====================

/**
 * 重置所有store到初始状态
 * 用于用户登出或重置应用
 */
export const resetAllStores = () => {
  useSyncStore.getState().reset();
  useSettingsStore.getState().resetSettings();
  useUIStore.getState().clearNotifications();
  useUIStore.getState().hideAllModals();
};

/**
 * 初始化应用状态
 * 在应用启动时调用，加载必要的初始数据
 */
export const initializeApp = async () => {
  try {
    // 加载设置数据
    await useSettingsStore.getState().loadSettings();
    
    // 加载统计数据
    await useSyncStore.getState().loadStats();
    
    // 设置页面可见性监听
    const handleVisibilityChange = () => {
      useUIStore.getState().setPageVisible(!document.hidden);
    };
    document.addEventListener('visibilitychange', handleVisibilityChange);
    
    // 设置响应式状态监听
    const updateResponsiveState = () => {
      const isMobile = window.innerWidth < 768;
      const isTablet = window.innerWidth >= 768 && window.innerWidth < 1024;
      useUIStore.getState().setResponsiveState(isMobile, isTablet);
    };
    
    window.addEventListener('resize', updateResponsiveState);
    updateResponsiveState(); // 初始化
    
    // 应用主题
    const theme = useUIStore.getState().theme;
    useUIStore.getState().setTheme(theme);
    
    console.log('✅ [Store] 应用状态初始化完成');
    return true;
  } catch (error) {
    console.error('❌ [Store] 应用状态初始化失败:', error);
    return false;
  }
};

// ==================== 开发工具 ====================

/**
 * 开发环境下的状态调试工具
 * 仅在开发模式下可用
 */
export const devTools = {
  // 获取所有store的当前状态
  getAllStates: () => ({
    sync: useSyncStore.getState(),
    settings: useSettingsStore.getState(),
    ui: useUIStore.getState(),
  }),
  
  // 打印状态到控制台
  logStates: () => {
    if (import.meta.env?.DEV) {
      console.group('🔍 [Store Debug] 当前状态');
      console.log('Sync Store:', useSyncStore.getState());
      console.log('Settings Store:', useSettingsStore.getState());
      console.log('UI Store:', useUIStore.getState());
      console.groupEnd();
    }
  },
  
  // 模拟状态变化（仅用于测试）
  simulateSync: () => {
    if (import.meta.env?.DEV) {
      const syncStore = useSyncStore.getState();
      syncStore.updateProgress(50, '模拟同步中...');
      syncStore.updateStatus('running');
    }
  },
};

// 在开发环境下将devTools暴露到全局
if (import.meta.env?.DEV) {
  (window as any).storeDevTools = devTools;
}
