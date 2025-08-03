import React, { useState, useEffect } from 'react'
import { useSyncStore } from '../../stores/syncStore'
import { useUIStore } from '../../stores/uiStore'
import { Card, CardContent, Button, Loading } from '../Common'

interface SystemInfo {
  php_version: string
  memory_usage: string
  memory_limit: string
  execution_time: string
  wordpress_version: string
  plugin_version: string
  last_sync: string
  total_synced: number
  sync_errors: number
}

interface PerformanceMetrics {
  avg_sync_time: number
  success_rate: number
  error_rate: number
  memory_peak: string
  cpu_usage: number
  active_connections: number
}

export const PerformanceMonitor: React.FC = () => {
  const { sseConnected, startTime, progress } = useSyncStore()
  const { showSuccess, showError } = useUIStore()
  const [systemInfo, setSystemInfo] = useState<SystemInfo | null>(null)
  const [performanceMetrics, setPerformanceMetrics] = useState<PerformanceMetrics | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const [autoRefresh, setAutoRefresh] = useState(false)

  // 模拟系统信息数据
  const mockSystemInfo: SystemInfo = {
    php_version: '8.1.0',
    memory_usage: '45MB',
    memory_limit: '256MB',
    execution_time: '2.3s',
    wordpress_version: '6.4.0',
    plugin_version: '1.0.0',
    last_sync: startTime ? new Date(startTime).toLocaleString() : '从未同步',
    total_synced: 156,
    sync_errors: 3
  }

  // 模拟性能指标数据
  const mockPerformanceMetrics: PerformanceMetrics = {
    avg_sync_time: 1.8,
    success_rate: 98.1,
    error_rate: 1.9,
    memory_peak: '78MB',
    cpu_usage: 23,
    active_connections: 2
  }

  const fetchSystemInfo = async () => {
    setIsLoading(true)
    try {
      // 模拟API调用
      await new Promise(resolve => setTimeout(resolve, 1000))
      setSystemInfo(mockSystemInfo)
      setPerformanceMetrics(mockPerformanceMetrics)
      showSuccess('系统信息已更新', '系统信息已成功刷新')
    } catch (error) {
      showError('获取系统信息失败', '请检查网络连接后重试')
    } finally {
      setIsLoading(false)
    }
  }

  const clearCache = async () => {
    setIsLoading(true)
    try {
      // 模拟清除缓存
      await new Promise(resolve => setTimeout(resolve, 800))
      showSuccess('缓存已清除', '系统缓存已成功清除')
    } catch (error) {
      showError('清除缓存失败', '请稍后重试')
    } finally {
      setIsLoading(false)
    }
  }

  const optimizeDatabase = async () => {
    setIsLoading(true)
    try {
      // 模拟数据库优化
      await new Promise(resolve => setTimeout(resolve, 2000))
      showSuccess('数据库优化完成', '数据库已成功优化')
    } catch (error) {
      showError('数据库优化失败', '请稍后重试')
    } finally {
      setIsLoading(false)
    }
  }

  useEffect(() => {
    fetchSystemInfo()
  }, [])

  useEffect(() => {
    let interval: number
    if (autoRefresh) {
      interval = window.setInterval(fetchSystemInfo, 30000) // 30秒自动刷新
    }
    return () => {
      if (interval) clearInterval(interval)
    }
  }, [autoRefresh])

  return (
    <div className="space-y-6">
      <div className="notion-wp-header-section">
        <h2 className="text-xl font-semibold text-gray-800">
          📊 性能监控
        </h2>
        <p className="text-sm text-gray-600">
          实时监控系统性能和同步状态
        </p>
      </div>

      {/* 系统状态概览 */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Card shadow="sm" className="border-l-4 border-l-green-500">
          <CardContent className="p-4">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-gray-600">连接状态</p>
                <p className="text-lg font-semibold text-green-600">
                  {sseConnected ? '已连接' : '未连接'}
                </p>
              </div>
              <div className="text-2xl">🔗</div>
            </div>
          </CardContent>
        </Card>

        <Card shadow="sm" className="border-l-4 border-l-blue-500">
          <CardContent className="p-4">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-gray-600">同步进度</p>
                <p className="text-lg font-semibold text-blue-600">
                  {progress || 0}%
                </p>
              </div>
              <div className="text-2xl">⚡</div>
            </div>
          </CardContent>
        </Card>

        <Card shadow="sm" className="border-l-4 border-l-purple-500">
          <CardContent className="p-4">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-gray-600">总同步数</p>
                <p className="text-lg font-semibold text-purple-600">
                  {systemInfo?.total_synced || 0}
                </p>
              </div>
              <div className="text-2xl">📈</div>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* 系统信息 */}
      <Card
        title="系统信息"
        subtitle="查看当前系统状态和配置信息"
        shadow="md"
      >
        <CardContent className="space-y-4">
          {isLoading && !systemInfo ? (
            <Loading text="正在加载系统信息..." />
          ) : systemInfo ? (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              <div className="space-y-2">
                <h4 className="font-medium text-gray-700">PHP 版本</h4>
                <p className="text-sm text-gray-600">{systemInfo.php_version}</p>
              </div>
              <div className="space-y-2">
                <h4 className="font-medium text-gray-700">内存使用</h4>
                <p className="text-sm text-gray-600">
                  {systemInfo.memory_usage} / {systemInfo.memory_limit}
                </p>
              </div>
              <div className="space-y-2">
                <h4 className="font-medium text-gray-700">执行时间</h4>
                <p className="text-sm text-gray-600">{systemInfo.execution_time}</p>
              </div>
              <div className="space-y-2">
                <h4 className="font-medium text-gray-700">WordPress 版本</h4>
                <p className="text-sm text-gray-600">{systemInfo.wordpress_version}</p>
              </div>
              <div className="space-y-2">
                <h4 className="font-medium text-gray-700">插件版本</h4>
                <p className="text-sm text-gray-600">{systemInfo.plugin_version}</p>
              </div>
              <div className="space-y-2">
                <h4 className="font-medium text-gray-700">最后同步</h4>
                <p className="text-sm text-gray-600">{systemInfo.last_sync}</p>
              </div>
            </div>
          ) : (
            <p className="text-gray-500">无法获取系统信息</p>
          )}

          <div className="flex flex-wrap gap-2 pt-4 border-t">
            <Button
              variant="primary"
              onClick={fetchSystemInfo}
              loading={isLoading}
              disabled={isLoading}
            >
              刷新信息
            </Button>
            <label className="flex items-center space-x-2">
              <input
                type="checkbox"
                checked={autoRefresh}
                onChange={(e) => setAutoRefresh(e.target.checked)}
                className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <span className="text-sm text-gray-700">自动刷新 (30秒)</span>
            </label>
          </div>
        </CardContent>
      </Card>

      {/* 性能指标 */}
      <Card
        title="性能指标"
        subtitle="查看详细的性能统计数据"
        shadow="md"
      >
        <CardContent className="space-y-4">
          {performanceMetrics ? (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
              <div className="text-center p-4 bg-blue-50 rounded-lg">
                <div className="text-2xl font-bold text-blue-600">
                  {performanceMetrics.avg_sync_time}s
                </div>
                <div className="text-sm text-blue-700">平均同步时间</div>
              </div>
              <div className="text-center p-4 bg-green-50 rounded-lg">
                <div className="text-2xl font-bold text-green-600">
                  {performanceMetrics.success_rate}%
                </div>
                <div className="text-sm text-green-700">成功率</div>
              </div>
              <div className="text-center p-4 bg-red-50 rounded-lg">
                <div className="text-2xl font-bold text-red-600">
                  {performanceMetrics.error_rate}%
                </div>
                <div className="text-sm text-red-700">错误率</div>
              </div>
              <div className="text-center p-4 bg-purple-50 rounded-lg">
                <div className="text-2xl font-bold text-purple-600">
                  {performanceMetrics.memory_peak}
                </div>
                <div className="text-sm text-purple-700">内存峰值</div>
              </div>
              <div className="text-center p-4 bg-yellow-50 rounded-lg">
                <div className="text-2xl font-bold text-yellow-600">
                  {performanceMetrics.cpu_usage}%
                </div>
                <div className="text-sm text-yellow-700">CPU 使用率</div>
              </div>
              <div className="text-center p-4 bg-indigo-50 rounded-lg">
                <div className="text-2xl font-bold text-indigo-600">
                  {performanceMetrics.active_connections}
                </div>
                <div className="text-sm text-indigo-700">活跃连接</div>
              </div>
            </div>
          ) : (
            <p className="text-gray-500">无法获取性能指标</p>
          )}
        </CardContent>
      </Card>

      {/* 维护工具 */}
      <Card
        title="维护工具"
        subtitle="系统维护和优化工具"
        shadow="md"
      >
        <CardContent className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Button
              variant="secondary"
              onClick={clearCache}
              loading={isLoading}
              disabled={isLoading}
              className="w-full"
            >
              清除缓存
            </Button>
            <Button
              variant="secondary"
              onClick={optimizeDatabase}
              loading={isLoading}
              disabled={isLoading}
              className="w-full"
            >
              优化数据库
            </Button>
          </div>

          <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <h4 className="text-sm font-medium text-yellow-800 mb-2">
              ⚠️ 维护提示
            </h4>
            <ul className="text-sm text-yellow-700 space-y-1">
              <li>• 清除缓存会临时影响性能，建议在低峰期执行</li>
              <li>• 数据库优化可能需要较长时间，请耐心等待</li>
              <li>• 建议定期执行维护操作以保持最佳性能</li>
            </ul>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}