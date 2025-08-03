import { useEffect, useRef, useCallback } from 'react'
import { useSyncStore } from '../stores/syncStore'
import { getApiService } from '../services/api'

/**
 * SSE连接Hook
 * 用于实时接收同步进度更新
 */
export const useSSE = (taskId: string | null, enabled: boolean = true) => {
  const eventSourceRef = useRef<EventSource | null>(null)
  const reconnectTimeoutRef = useRef<number | null>(null)
  const { updateProgress, updateStatus, handleSSEEvent } = useSyncStore()

  // 清理连接
  const cleanup = useCallback(() => {
    if (eventSourceRef.current) {
      eventSourceRef.current.close()
      eventSourceRef.current = null
    }
    if (reconnectTimeoutRef.current) {
      clearTimeout(reconnectTimeoutRef.current)
      reconnectTimeoutRef.current = null
    }
  }, [])

  // 连接SSE
  const connect = useCallback(() => {
    if (!taskId || !enabled) return

    try {
      cleanup() // 先清理现有连接

      const apiService = getApiService()
      const eventSource = apiService.createSSEConnection(taskId)
      eventSourceRef.current = eventSource

      // 连接打开
      eventSource.onopen = (event) => {
        console.log('🔗 [SSE Hook] 连接已建立', { taskId, event })
      }

      // 接收消息
      eventSource.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data)
          console.log('📨 [SSE Hook] 收到消息:', data)
          
          // 使用store的handleSSEEvent方法处理事件
          handleSSEEvent({
            type: data.type || 'progress',
            data: data,
            timestamp: new Date().toISOString()
          })
        } catch (error) {
          console.error('❌ [SSE Hook] 消息解析失败:', error, event.data)
        }
      }

      // 连接错误
      eventSource.onerror = (error) => {
        console.error('❌ [SSE Hook] 连接错误:', error)
        
        // 如果连接断开且任务仍在运行，尝试重连
        if (eventSource.readyState === EventSource.CLOSED) {
          console.log('🔄 [SSE Hook] 连接已断开，尝试重连...')
          cleanup()
          
          // 延迟重连
          reconnectTimeoutRef.current = setTimeout(() => {
            connect()
          }, 3000)
        }
      }

      // 监听特定事件类型
      eventSource.addEventListener('progress', (event) => {
        try {
          const data = JSON.parse(event.data)
          updateProgress(data.percentage || data.progress || 0, data.message || data.current_status)
        } catch (error) {
          console.error('❌ [SSE Hook] progress事件解析失败:', error)
        }
      })

      eventSource.addEventListener('status', (event) => {
        try {
          const data = JSON.parse(event.data)
          updateStatus(data.status, {
            step: data.step || 'unknown',
            status: data.status || 'running',
            message: data.message,
            timestamp: new Date().toISOString(),
            progress: data.progress
          })
        } catch (error) {
          console.error('❌ [SSE Hook] status事件解析失败:', error)
        }
      })

      eventSource.addEventListener('completed', (event) => {
        try {
          const data = JSON.parse(event.data)
          updateStatus('completed', {
            step: 'completed',
            status: 'completed',
            message: data.message || '同步完成',
            timestamp: new Date().toISOString(),
            progress: 100
          })
          cleanup() // 完成后关闭连接
        } catch (error) {
          console.error('❌ [SSE Hook] completed事件解析失败:', error)
        }
      })

      eventSource.addEventListener('error', (event: MessageEvent) => {
        try {
          const data = JSON.parse(event.data)
          updateStatus('failed', {
            step: 'error',
            status: 'failed',
            message: data.message || '同步发生错误',
            timestamp: new Date().toISOString()
          })
          cleanup() // 错误后关闭连接
        } catch (error) {
          console.error('❌ [SSE Hook] error事件解析失败:', error)
          // 如果解析失败，使用默认错误信息
          updateStatus('failed', {
            step: 'error',
            status: 'failed',
            message: '同步发生未知错误',
            timestamp: new Date().toISOString()
          })
          cleanup()
        }
      })

    } catch (error) {
      console.error('❌ [SSE Hook] 创建SSE连接失败:', error)
    }
  }, [taskId, enabled, cleanup, updateProgress, updateStatus, handleSSEEvent])

  // 当taskId或enabled状态变化时，重新连接
  useEffect(() => {
    if (taskId && enabled) {
      connect()
    } else {
      cleanup()
    }

    return cleanup
  }, [taskId, enabled, connect, cleanup])

  // 组件卸载时清理
  useEffect(() => {
    return cleanup
  }, [cleanup])

  return {
    isConnected: eventSourceRef.current?.readyState === EventSource.OPEN,
    reconnect: connect,
    disconnect: cleanup
  }
}

/**
 * 简化版SSE Hook，用于基本的进度监听
 */
export const useProgressSSE = (taskId: string | null) => {
  return useSSE(taskId, !!taskId)
}