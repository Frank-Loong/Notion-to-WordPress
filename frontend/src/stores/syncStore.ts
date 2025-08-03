import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import { getApiService } from '../services/api';
import type {
  SyncRequest,
  SyncStatusType,
  SyncStatusDetail,
  StatsData,
  SSEEvent
} from '../types';

// 同步状态接口
interface SyncState {
  // 同步状态
  isRunning: boolean;
  progress: number;
  status: SyncStatusType;
  currentStep: string;
  taskId: string | null;
  syncType: string | null;
  startTime: number | null;
  
  // 统计数据
  stats: StatsData | null;
  
  // SSE连接状态
  sseConnected: boolean;
  sseEventSource: EventSource | null;
  
  // 错误状态
  error: string | null;
  lastError: string | null;
}

// 同步操作接口
interface SyncActions {
  // 同步操作
  startSync: (request: SyncRequest) => Promise<void>;
  stopSync: () => Promise<void>;
  cancelSync: () => Promise<void>;
  retryFailedSync: () => Promise<void>;
  
  // 进度更新
  updateProgress: (progress: number, step?: string) => void;
  updateStatus: (status: SyncStatusType, detail?: SyncStatusDetail) => void;
  
  // 统计数据
  loadStats: () => Promise<void>;
  updateStats: (stats: Partial<StatsData>) => void;
  
  // SSE连接管理
  connectSSE: (taskId: string) => void;
  disconnectSSE: () => void;
  handleSSEEvent: (event: SSEEvent) => void;
  
  // 错误处理
  setError: (error: string) => void;
  clearError: () => void;
  
  // 重置状态
  reset: () => void;
}

// 完整的同步Store类型
type SyncStore = SyncState & SyncActions;

// 默认状态
const defaultState: SyncState = {
  isRunning: false,
  progress: 0,
  status: 'idle' as SyncStatusType,
  currentStep: '',
  taskId: null,
  syncType: null,
  startTime: null,
  stats: null,
  sseConnected: false,
  sseEventSource: null,
  error: null,
  lastError: null,
};

// 创建同步Store
export const useSyncStore = create<SyncStore>()(
  persist(
    (set, get) => ({
      ...defaultState,

      // ==================== 同步操作 ====================
      
      startSync: async (request: SyncRequest) => {
        try {
          set({
            isRunning: true,
            status: 'running' as SyncStatusType,
            progress: 0,
            currentStep: '准备同步...',
            syncType: request.type || 'manual',
            startTime: Date.now(),
            error: null,
          });

          const apiService = getApiService();
          const response = await apiService.startSync(request);

          if (response.success && response.data?.taskId) {
            const taskId = response.data.taskId;
            set({ 
              taskId,
              currentStep: '同步已启动',
            });

            // 启动SSE连接监听进度
            get().connectSSE(taskId);
          } else {
            throw new Error(response.message || '启动同步失败');
          }
        } catch (error) {
          const errorMessage = error instanceof Error ? error.message : '启动同步时发生未知错误';
          set({
            isRunning: false,
            status: 'failed' as SyncStatusType,
            error: errorMessage,
            lastError: errorMessage,
          });
          console.error('❌ [同步Store] 启动同步失败:', error);
        }
      },

      stopSync: async () => {
        try {
          const { taskId } = get();
          if (!taskId) return;

          const apiService = getApiService();
          await apiService.cancelSync(taskId);
          
          get().disconnectSSE();
          set({
            isRunning: false,
            status: 'cancelled' as SyncStatusType,
            currentStep: '同步已停止',
          });
        } catch (error) {
          console.error('❌ [同步Store] 停止同步失败:', error);
          get().setError('停止同步失败');
        }
      },

      cancelSync: async () => {
        await get().stopSync();
      },

      retryFailedSync: async () => {
        const { syncType } = get();
        if (!syncType) return;

        // 重置错误状态并重新开始同步
        set({ error: null, lastError: null });
        await get().startSync({ type: syncType as 'smart' | 'full' });
      },

      // ==================== 进度更新 ====================
      
      updateProgress: (progress: number, step?: string) => {
        set(state => ({
          progress: Math.max(0, Math.min(100, progress)),
          currentStep: step || state.currentStep,
        }));
      },

      updateStatus: (status: SyncStatusType, detail?: SyncStatusDetail) => {
        set(state => ({
          status,
          isRunning: status === 'running',
          currentStep: detail?.message || state.currentStep,
          progress: detail?.progress !== undefined ? detail.progress : state.progress,
        }));

        // 如果同步完成或失败，断开SSE连接
        if (status === 'completed' || status === 'failed' || status === 'cancelled') {
          get().disconnectSSE();

          // 同步完成后刷新统计数据
          if (status === 'completed') {
            setTimeout(() => get().loadStats(), 1000);
          }
        }
      },

      // ==================== 统计数据 ====================
      
      loadStats: async () => {
        try {
          const apiService = getApiService();
          const stats = await apiService.getStats();
          set({ stats });
        } catch (error) {
          console.error('❌ [同步Store] 加载统计数据失败:', error);
        }
      },

      updateStats: (statsUpdate: Partial<StatsData>) => {
        set(state => ({
          stats: state.stats ? { ...state.stats, ...statsUpdate } : null,
        }));
      },

      // ==================== SSE连接管理 ====================
      
      connectSSE: (taskId: string) => {
        // 先断开现有连接
        get().disconnectSSE();

        try {
          const apiService = getApiService();
          const eventSource = apiService.createSSEConnection(taskId);
          
          eventSource.onopen = () => {
            set({ sseConnected: true, sseEventSource: eventSource });
            console.log('🔗 [同步Store] SSE连接已建立');
          };

          eventSource.onmessage = (event) => {
            try {
              const sseEvent: SSEEvent = JSON.parse(event.data);
              get().handleSSEEvent(sseEvent);
            } catch (error) {
              console.error('❌ [同步Store] SSE消息解析失败:', error);
            }
          };

          eventSource.onerror = (error) => {
            console.error('❌ [同步Store] SSE连接错误:', error);
            get().disconnectSSE();
          };

        } catch (error) {
          console.error('❌ [同步Store] 创建SSE连接失败:', error);
        }
      },

      disconnectSSE: () => {
        const { sseEventSource } = get();
        if (sseEventSource) {
          sseEventSource.close();
          set({ sseConnected: false, sseEventSource: null });
          console.log('🔌 [同步Store] SSE连接已断开');
        }
      },

      handleSSEEvent: (event: SSEEvent) => {
        const { type, data } = event;

        switch (type) {
          case 'progress':
            if (data.progress !== undefined) {
              get().updateProgress(data.progress, data.message);
            }
            break;

          case 'status':
            if (data.status) {
              get().updateStatus(data.status as SyncStatusType, {
                step: 'status_update',
                status: 'running',
                message: data.message || '状态更新',
                timestamp: new Date().toISOString(),
                progress: data.progress
              });
            }
            break;

          case 'stats':
            if (data.stats) {
              get().updateStats(data.stats);
            }
            break;

          case 'error':
            get().setError(data.message || '同步过程中发生错误');
            break;

          case 'complete':
            get().updateStatus('completed' as SyncStatusType, {
              step: 'complete',
              status: 'completed',
              message: data.message || '同步完成',
              timestamp: new Date().toISOString(),
              progress: 100
            });
            break;

          default:
            console.log('📨 [同步Store] 收到未知SSE事件:', event);
        }
      },

      // ==================== 错误处理 ====================
      
      setError: (error: string) => {
        set({ error, lastError: error });
      },

      clearError: () => {
        set({ error: null });
      },

      // ==================== 重置状态 ====================
      
      reset: () => {
        get().disconnectSSE();
        set(defaultState);
      },
    }),
    {
      name: 'notion-wp-sync-store',
      storage: createJSONStorage(() => localStorage),
      // 只持久化关键状态，不持久化SSE连接等临时状态
      partialize: (state) => ({
        taskId: state.taskId,
        syncType: state.syncType,
        startTime: state.startTime,
        stats: state.stats,
        lastError: state.lastError,
      }),
    }
  )
);

// 导出类型供其他组件使用
export type { SyncStore, SyncState, SyncActions };
