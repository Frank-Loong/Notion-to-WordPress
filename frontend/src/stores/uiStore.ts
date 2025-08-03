import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';

// 通知类型
export type NotificationType = 'success' | 'error' | 'warning' | 'info';

// 通知接口
export interface Notification {
  id: string;
  type: NotificationType;
  title: string;
  message: string;
  duration?: number; // 自动关闭时间（毫秒），0表示不自动关闭
  actions?: Array<{
    label: string;
    action: () => void;
    variant?: 'primary' | 'secondary';
  }>;
  timestamp: number;
}

// 模态框接口
export interface Modal {
  id: string;
  type: 'confirm' | 'alert' | 'custom';
  title: string;
  content: string;
  onConfirm?: () => void;
  onCancel?: () => void;
  confirmText?: string;
  cancelText?: string;
  isOpen: boolean;
}

// 标签页类型
export type TabType = 'sync' | 'settings' | 'field-mapping' | 'performance' | 'monitoring' | 'debug' | 'help' | 'about';

// UI状态接口
interface UIState {
  // 标签页状态
  activeTab: TabType;
  tabHistory: TabType[];
  
  // 通知系统
  notifications: Notification[];
  maxNotifications: number;
  
  // 模态框系统
  modals: Modal[];
  
  // 加载状态
  globalLoading: boolean;
  loadingMessage: string;
  
  // 侧边栏状态
  sidebarCollapsed: boolean;
  
  // 主题设置
  theme: 'light' | 'dark' | 'auto';
  
  // 页面可见性
  isPageVisible: boolean;
  
  // 响应式状态
  isMobile: boolean;
  isTablet: boolean;
  
  // 调试模式
  debugMode: boolean;
}

// UI操作接口
interface UIActions {
  // 标签页管理
  setActiveTab: (tab: TabType) => void;
  goBack: () => void;
  goForward: () => void;
  
  // 通知管理
  showNotification: (notification: Omit<Notification, 'id' | 'timestamp'>) => string;
  hideNotification: (id: string) => void;
  clearNotifications: () => void;
  
  // 快捷通知方法
  showSuccess: (title: string, message: string, duration?: number) => string;
  showError: (title: string, message: string, duration?: number) => string;
  showWarning: (title: string, message: string, duration?: number) => string;
  showInfo: (title: string, message: string, duration?: number) => string;
  
  // 模态框管理
  showModal: (modal: Omit<Modal, 'id' | 'isOpen'>) => string;
  hideModal: (id: string) => void;
  hideAllModals: () => void;
  
  // 快捷模态框方法
  showConfirm: (title: string, content: string, onConfirm?: () => void, onCancel?: () => void) => string;
  showAlert: (title: string, content: string, onConfirm?: () => void) => string;
  
  // 加载状态
  setGlobalLoading: (loading: boolean, message?: string) => void;
  
  // 侧边栏
  toggleSidebar: () => void;
  setSidebarCollapsed: (collapsed: boolean) => void;
  
  // 主题
  setTheme: (theme: 'light' | 'dark' | 'auto') => void;
  
  // 页面可见性
  setPageVisible: (visible: boolean) => void;
  
  // 响应式状态
  setResponsiveState: (isMobile: boolean, isTablet: boolean) => void;
  
  // 调试模式
  toggleDebugMode: () => void;
  setDebugMode: (enabled: boolean) => void;
}

// 完整的UI Store类型
type UIStore = UIState & UIActions;

// 默认状态
const defaultState: UIState = {
  activeTab: 'sync',
  tabHistory: ['sync'],
  notifications: [],
  maxNotifications: 5,
  modals: [],
  globalLoading: false,
  loadingMessage: '',
  sidebarCollapsed: false,
  theme: 'auto',
  isPageVisible: true,
  isMobile: false,
  isTablet: false,
  debugMode: false,
};

// 生成唯一ID
const generateId = () => `${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;

// 创建UI Store
export const useUIStore = create<UIStore>()(
  persist(
    (set, get) => ({
      ...defaultState,

      // ==================== 标签页管理 ====================
      
      setActiveTab: (tab: TabType) => {
        set(state => {
          const newHistory = [...state.tabHistory];
          
          // 移除历史中的重复项
          const existingIndex = newHistory.indexOf(tab);
          if (existingIndex > -1) {
            newHistory.splice(existingIndex, 1);
          }
          
          // 添加到历史末尾
          newHistory.push(tab);
          
          // 限制历史长度
          if (newHistory.length > 10) {
            newHistory.shift();
          }
          
          return {
            activeTab: tab,
            tabHistory: newHistory,
          };
        });
      },

      goBack: () => {
        const { tabHistory, activeTab } = get();
        const currentIndex = tabHistory.indexOf(activeTab);
        
        if (currentIndex > 0) {
          get().setActiveTab(tabHistory[currentIndex - 1]);
        }
      },

      goForward: () => {
        const { tabHistory, activeTab } = get();
        const currentIndex = tabHistory.indexOf(activeTab);
        
        if (currentIndex < tabHistory.length - 1) {
          get().setActiveTab(tabHistory[currentIndex + 1]);
        }
      },

      // ==================== 通知管理 ====================
      
      showNotification: (notification: Omit<Notification, 'id' | 'timestamp'>) => {
        const id = generateId();
        const newNotification: Notification = {
          ...notification,
          id,
          timestamp: Date.now(),
          duration: notification.duration ?? 5000, // 默认5秒自动关闭
        };

        set(state => {
          let notifications = [...state.notifications, newNotification];
          
          // 限制通知数量
          if (notifications.length > state.maxNotifications) {
            notifications = notifications.slice(-state.maxNotifications);
          }
          
          return { notifications };
        });

        // 自动关闭通知
        if (newNotification.duration && newNotification.duration > 0) {
          setTimeout(() => {
            get().hideNotification(id);
          }, newNotification.duration);
        }

        return id;
      },

      hideNotification: (id: string) => {
        set(state => ({
          notifications: state.notifications.filter(n => n.id !== id),
        }));
      },

      clearNotifications: () => {
        set({ notifications: [] });
      },

      // 快捷通知方法
      showSuccess: (title: string, message: string, duration = 5000) => {
        return get().showNotification({ type: 'success', title, message, duration });
      },

      showError: (title: string, message: string, duration = 0) => {
        return get().showNotification({ type: 'error', title, message, duration });
      },

      showWarning: (title: string, message: string, duration = 8000) => {
        return get().showNotification({ type: 'warning', title, message, duration });
      },

      showInfo: (title: string, message: string, duration = 5000) => {
        return get().showNotification({ type: 'info', title, message, duration });
      },

      // ==================== 模态框管理 ====================
      
      showModal: (modal: Omit<Modal, 'id' | 'isOpen'>) => {
        const id = generateId();
        const newModal: Modal = {
          ...modal,
          id,
          isOpen: true,
        };

        set(state => ({
          modals: [...state.modals, newModal],
        }));

        return id;
      },

      hideModal: (id: string) => {
        set(state => ({
          modals: state.modals.filter(m => m.id !== id),
        }));
      },

      hideAllModals: () => {
        set({ modals: [] });
      },

      // 快捷模态框方法
      showConfirm: (title: string, content: string, onConfirm?: () => void, onCancel?: () => void) => {
        return get().showModal({
          type: 'confirm',
          title,
          content,
          onConfirm,
          onCancel,
          confirmText: '确认',
          cancelText: '取消',
        });
      },

      showAlert: (title: string, content: string, onConfirm?: () => void) => {
        return get().showModal({
          type: 'alert',
          title,
          content,
          onConfirm,
          confirmText: '确定',
        });
      },

      // ==================== 加载状态 ====================
      
      setGlobalLoading: (loading: boolean, message = '') => {
        set({ globalLoading: loading, loadingMessage: message });
      },

      // ==================== 侧边栏 ====================
      
      toggleSidebar: () => {
        set(state => ({ sidebarCollapsed: !state.sidebarCollapsed }));
      },

      setSidebarCollapsed: (collapsed: boolean) => {
        set({ sidebarCollapsed: collapsed });
      },

      // ==================== 主题 ====================
      
      setTheme: (theme: 'light' | 'dark' | 'auto') => {
        set({ theme });
        
        // 应用主题到document
        const root = document.documentElement;
        if (theme === 'dark') {
          root.classList.add('dark');
        } else if (theme === 'light') {
          root.classList.remove('dark');
        } else {
          // auto模式：根据系统偏好设置
          const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
          if (prefersDark) {
            root.classList.add('dark');
          } else {
            root.classList.remove('dark');
          }
        }
      },

      // ==================== 页面可见性 ====================
      
      setPageVisible: (visible: boolean) => {
        set({ isPageVisible: visible });
      },

      // ==================== 响应式状态 ====================
      
      setResponsiveState: (isMobile: boolean, isTablet: boolean) => {
        set({ isMobile, isTablet });
      },

      // ==================== 调试模式 ====================
      
      toggleDebugMode: () => {
        set(state => ({ debugMode: !state.debugMode }));
      },

      setDebugMode: (enabled: boolean) => {
        set({ debugMode: enabled });
      },
    }),
    {
      name: 'notion-wp-ui-store',
      storage: createJSONStorage(() => localStorage),
      // 持久化用户偏好设置
      partialize: (state) => ({
        activeTab: state.activeTab,
        sidebarCollapsed: state.sidebarCollapsed,
        theme: state.theme,
        debugMode: state.debugMode,
      }),
    }
  )
);

// 导出类型供其他组件使用
export type { UIStore, UIState, UIActions };
