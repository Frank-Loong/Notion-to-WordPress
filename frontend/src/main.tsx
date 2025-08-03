import React, { useEffect } from 'react'
import ReactDOM from 'react-dom/client'
import { AdminLayout } from './components/Layout/AdminLayout'
import { useSyncStore } from './stores/syncStore'
import { useSettingsStore } from './stores/settingsStore'
import { useProgressSSE } from './hooks/useSSE'
import './index.css'

// 主应用组件
const App: React.FC = () => {
  const { loadStats, taskId } = useSyncStore()
  const { loadSettings } = useSettingsStore()

  // 启用SSE进度推送
  useProgressSSE(taskId)

  // 初始化数据加载
  useEffect(() => {
    const initializeApp = async () => {
      try {
        await Promise.all([
          loadSettings(),
          loadStats()
        ])
        console.log('🚀 [App] 应用初始化完成')
      } catch (error) {
        console.error('❌ [App] 应用初始化失败:', error)
      }
    }

    initializeApp()
  }, [loadSettings, loadStats])

  return (
    <div className="notion-wp-app">
      <AdminLayout />
    </div>
  )
}

// 挂载React应用到WordPress页面
const rootElement = document.getElementById('notion-to-wordpress-react-root')
if (rootElement) {
  ReactDOM.createRoot(rootElement).render(
    <React.StrictMode>
      <App />
    </React.StrictMode>,
  )
} else {
  console.error('React挂载点未找到：#notion-to-wordpress-react-root')
}