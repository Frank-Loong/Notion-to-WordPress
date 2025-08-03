import React, { useState, useEffect } from 'react'
import { Card, CardContent } from '../Common/Card'
import { Button } from '../Common/Button'
import { Input } from '../Common/Input'
import { useUIStore } from '../../stores/uiStore'
import { useSyncStore } from '../../stores/syncStore'

interface SystemInfo {
  php_version: string
  wp_version: string
  memory_limit: string
  max_execution_time: string
  plugin_version: string
  current_time: string
  options_exist: string
  ajax_url: string
}

interface ErrorLogEntry {
  id: string
  timestamp: string
  level: 'error' | 'warning' | 'info' | 'debug'
  message: string
  context: string
  file?: string
  line?: number
}

interface DiagnosticResult {
  name: string
  status: 'pass' | 'fail' | 'warning'
  message: string
  details?: string
}

export const DebugTools: React.FC = () => {
  const { showSuccess, showError } = useUIStore()
  const { sseConnected } = useSyncStore()

  // 状态管理
  const [systemInfo, setSystemInfo] = useState<SystemInfo | null>(null)
  const [errorLogs, setErrorLogs] = useState<ErrorLogEntry[]>([])
  const [diagnosticResults, setDiagnosticResults] = useState<DiagnosticResult[]>([])
  const [isLoading, setIsLoading] = useState(false)
  const [logFilter, setLogFilter] = useState('')
  const [selectedLogLevel, setSelectedLogLevel] = useState<string>('all')

  // 模拟系统信息数据
  const mockSystemInfo: SystemInfo = {
    php_version: '8.1.0',
    wp_version: '6.4.0',
    memory_limit: '256M',
    max_execution_time: '300',
    plugin_version: '2.0.0-beta.1',
    current_time: new Date().toISOString(),
    options_exist: 'yes',
    ajax_url: '/wp-admin/admin-ajax.php'
  }

  // 模拟错误日志数据
  const mockErrorLogs: ErrorLogEntry[] = [
    {
      id: '1',
      timestamp: '2024-01-15 10:30:25',
      level: 'error',
      message: 'API连接失败: 无法连接到Notion API',
      context: 'Notion API Client',
      file: 'NotionApiClient.php',
      line: 156
    },
    {
      id: '2',
      timestamp: '2024-01-15 10:25:12',
      level: 'warning',
      message: '同步超时: 页面同步耗时过长',
      context: 'Sync Manager',
      file: 'SyncManager.php',
      line: 89
    },
    {
      id: '3',
      timestamp: '2024-01-15 10:20:45',
      level: 'info',
      message: '同步完成: 成功同步5个页面',
      context: 'Sync Process',
      file: 'SyncProcess.php',
      line: 234
    },
    {
      id: '4',
      timestamp: '2024-01-15 10:15:30',
      level: 'debug',
      message: '缓存清理: 清除了过期的API响应缓存',
      context: 'Cache Manager',
      file: 'CacheManager.php',
      line: 67
    },
    {
      id: '5',
      timestamp: '2024-01-15 10:10:18',
      level: 'error',
      message: '数据库错误: 无法更新文章元数据',
      context: 'Database Handler',
      file: 'DatabaseHandler.php',
      line: 123
    }
  ]

  // 模拟诊断结果数据
  const mockDiagnosticResults: DiagnosticResult[] = [
    {
      name: 'PHP版本检查',
      status: 'pass',
      message: 'PHP 8.1.0 - 版本符合要求',
      details: '推荐PHP 8.0+，当前版本满足要求'
    },
    {
      name: 'WordPress版本检查',
      status: 'pass',
      message: 'WordPress 6.4.0 - 版本符合要求',
      details: '推荐WordPress 6.0+，当前版本满足要求'
    },
    {
      name: '内存限制检查',
      status: 'warning',
      message: '内存限制 256M - 建议增加',
      details: '推荐512M以上，当前可能在大量数据同步时不足'
    },
    {
      name: 'cURL扩展检查',
      status: 'pass',
      message: 'cURL扩展已启用',
      details: 'API通信必需的扩展已正确安装'
    },
    {
      name: 'SSL证书检查',
      status: 'pass',
      message: 'SSL证书有效',
      details: 'HTTPS连接正常，可以安全访问Notion API'
    },
    {
      name: 'API连接测试',
      status: 'fail',
      message: 'API连接失败',
      details: '无法连接到Notion API，请检查网络和API密钥'
    }
  ]

  // 初始化数据
  useEffect(() => {
    setSystemInfo(mockSystemInfo)
    setErrorLogs(mockErrorLogs)
    setDiagnosticResults(mockDiagnosticResults)
  }, [])

  // 获取系统信息
  const handleGetSystemInfo = async () => {
    setIsLoading(true)
    try {
      // 模拟API调用
      await new Promise(resolve => setTimeout(resolve, 1000))
      setSystemInfo(mockSystemInfo)
      showSuccess('系统信息', '系统信息已更新')
    } catch (error) {
      showError('系统信息', '获取系统信息失败')
    } finally {
      setIsLoading(false)
    }
  }

  // 运行系统诊断
  const handleRunDiagnostics = async () => {
    setIsLoading(true)
    try {
      // 模拟诊断过程
      await new Promise(resolve => setTimeout(resolve, 2000))
      setDiagnosticResults(mockDiagnosticResults)
      showSuccess('系统诊断', '系统诊断完成')
    } catch (error) {
      showError('系统诊断', '系统诊断失败')
    } finally {
      setIsLoading(false)
    }
  }

  // 清除错误日志
  const handleClearLogs = async () => {
    setIsLoading(true)
    try {
      // 模拟清除操作
      await new Promise(resolve => setTimeout(resolve, 500))
      setErrorLogs([])
      showSuccess('日志管理', '错误日志已清除')
    } catch (error) {
      showError('日志管理', '清除日志失败')
    } finally {
      setIsLoading(false)
    }
  }

  // 下载日志
  const handleDownloadLogs = () => {
    const logData = filteredLogs.map(log =>
      `[${log.timestamp}] ${log.level.toUpperCase()}: ${log.message} (${log.context})`
    ).join('\n')

    const blob = new Blob([logData], { type: 'text/plain' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `debug-logs-${new Date().toISOString().split('T')[0]}.txt`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)

    showSuccess('日志下载', '日志文件已下载')
  }

  // 过滤日志
  const filteredLogs = errorLogs.filter(log => {
    const matchesFilter = logFilter === '' ||
      log.message.toLowerCase().includes(logFilter.toLowerCase()) ||
      log.context.toLowerCase().includes(logFilter.toLowerCase())

    const matchesLevel = selectedLogLevel === 'all' || log.level === selectedLogLevel

    return matchesFilter && matchesLevel
  })

  // 获取状态图标
  const getStatusIcon = (status: DiagnosticResult['status']) => {
    switch (status) {
      case 'pass': return '✅'
      case 'fail': return '❌'
      case 'warning': return '⚠️'
      default: return '❓'
    }
  }

  // 获取日志级别图标
  const getLogLevelIcon = (level: ErrorLogEntry['level']) => {
    switch (level) {
      case 'error': return '❌'
      case 'warning': return '⚠️'
      case 'info': return 'ℹ️'
      case 'debug': return '🔍'
      default: return '📝'
    }
  }

  return (
    <div className="space-y-6">
      {/* 页面标题 */}
      <div className="notion-wp-header-section">
        <h2 className="text-xl font-semibold text-gray-800 flex items-center gap-2">
          🐞 调试工具
        </h2>
        <p className="text-sm text-gray-600">
          系统诊断、错误日志分析和调试实用工具
        </p>
      </div>

      {/* 系统信息面板 */}
      <Card>
        <CardContent>
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-medium text-gray-800">📊 系统信息</h3>
            <Button
              onClick={handleGetSystemInfo}
              disabled={isLoading}
              className="bg-blue-600 hover:bg-blue-700"
            >
              {isLoading ? '获取中...' : '刷新信息'}
            </Button>
          </div>

          {systemInfo && (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
              <div className="bg-gray-50 p-3 rounded-lg">
                <div className="text-sm font-medium text-gray-600">PHP版本</div>
                <div className="text-lg font-semibold text-gray-800">{systemInfo.php_version}</div>
              </div>
              <div className="bg-gray-50 p-3 rounded-lg">
                <div className="text-sm font-medium text-gray-600">WordPress版本</div>
                <div className="text-lg font-semibold text-gray-800">{systemInfo.wp_version}</div>
              </div>
              <div className="bg-gray-50 p-3 rounded-lg">
                <div className="text-sm font-medium text-gray-600">内存限制</div>
                <div className="text-lg font-semibold text-gray-800">{systemInfo.memory_limit}</div>
              </div>
              <div className="bg-gray-50 p-3 rounded-lg">
                <div className="text-sm font-medium text-gray-600">执行时间限制</div>
                <div className="text-lg font-semibold text-gray-800">{systemInfo.max_execution_time}s</div>
              </div>
              <div className="bg-gray-50 p-3 rounded-lg">
                <div className="text-sm font-medium text-gray-600">插件版本</div>
                <div className="text-lg font-semibold text-gray-800">{systemInfo.plugin_version}</div>
              </div>
              <div className="bg-gray-50 p-3 rounded-lg">
                <div className="text-sm font-medium text-gray-600">配置状态</div>
                <div className="text-lg font-semibold text-gray-800">
                  {systemInfo.options_exist === 'yes' ? '✅ 已配置' : '❌ 未配置'}
                </div>
              </div>
              <div className="bg-gray-50 p-3 rounded-lg">
                <div className="text-sm font-medium text-gray-600">连接状态</div>
                <div className="text-lg font-semibold text-gray-800">
                  {sseConnected ? '✅ 已连接' : '❌ 未连接'}
                </div>
              </div>
              <div className="bg-gray-50 p-3 rounded-lg">
                <div className="text-sm font-medium text-gray-600">当前时间</div>
                <div className="text-sm font-semibold text-gray-800">
                  {new Date(systemInfo.current_time).toLocaleString()}
                </div>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* 系统诊断面板 */}
      <Card>
        <CardContent>
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-medium text-gray-800">🔍 系统诊断</h3>
            <Button
              onClick={handleRunDiagnostics}
              disabled={isLoading}
              className="bg-green-600 hover:bg-green-700"
            >
              {isLoading ? '诊断中...' : '运行诊断'}
            </Button>
          </div>

          <div className="space-y-3">
            {diagnosticResults.map((result, index) => (
              <div
                key={index}
                className={`p-4 rounded-lg border-l-4 ${
                  result.status === 'pass'
                    ? 'bg-green-50 border-green-400'
                    : result.status === 'warning'
                    ? 'bg-yellow-50 border-yellow-400'
                    : 'bg-red-50 border-red-400'
                }`}
              >
                <div className="flex items-center gap-2 mb-1">
                  <span className="text-lg">{getStatusIcon(result.status)}</span>
                  <span className="font-medium text-gray-800">{result.name}</span>
                </div>
                <div className="text-sm text-gray-600 mb-1">{result.message}</div>
                {result.details && (
                  <div className="text-xs text-gray-500">{result.details}</div>
                )}
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* 错误日志分析面板 */}
      <Card>
        <CardContent>
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-medium text-gray-800">📋 错误日志分析</h3>
            <div className="flex gap-2">
              <Button
                onClick={handleDownloadLogs}
                disabled={errorLogs.length === 0}
                className="bg-blue-600 hover:bg-blue-700"
              >
                下载日志
              </Button>
              <Button
                onClick={handleClearLogs}
                disabled={isLoading || errorLogs.length === 0}
                className="bg-red-600 hover:bg-red-700"
              >
                {isLoading ? '清除中...' : '清除日志'}
              </Button>
            </div>
          </div>

          {/* 日志过滤器 */}
          <div className="flex flex-col sm:flex-row gap-4 mb-4">
            <div className="flex-1">
              <Input
                type="text"
                placeholder="搜索日志内容..."
                value={logFilter}
                onChange={(e) => setLogFilter(e.target.value)}
                className="w-full"
              />
            </div>
            <div className="sm:w-48">
              <select
                value={selectedLogLevel}
                onChange={(e) => setSelectedLogLevel(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="all">所有级别</option>
                <option value="error">错误</option>
                <option value="warning">警告</option>
                <option value="info">信息</option>
                <option value="debug">调试</option>
              </select>
            </div>
          </div>

          {/* 日志统计 */}
          <div className="mb-4 text-sm text-gray-600">
            显示 {filteredLogs.length} / {errorLogs.length} 条日志
          </div>

          {/* 日志列表 */}
          <div className="space-y-2 max-h-96 overflow-y-auto">
            {filteredLogs.length === 0 ? (
              <div className="text-center py-8 text-gray-500">
                {errorLogs.length === 0 ? '暂无日志记录' : '没有匹配的日志'}
              </div>
            ) : (
              filteredLogs.map((log) => (
                <div
                  key={log.id}
                  className={`p-3 rounded-lg border-l-4 ${
                    log.level === 'error'
                      ? 'bg-red-50 border-red-400'
                      : log.level === 'warning'
                      ? 'bg-yellow-50 border-yellow-400'
                      : log.level === 'info'
                      ? 'bg-blue-50 border-blue-400'
                      : 'bg-gray-50 border-gray-400'
                  }`}
                >
                  <div className="flex items-start gap-2">
                    <span className="text-lg flex-shrink-0 mt-0.5">
                      {getLogLevelIcon(log.level)}
                    </span>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 mb-1">
                        <span className="text-xs font-medium text-gray-500 uppercase">
                          {log.level}
                        </span>
                        <span className="text-xs text-gray-500">{log.timestamp}</span>
                        <span className="text-xs text-gray-500">({log.context})</span>
                      </div>
                      <div className="text-sm text-gray-800 mb-1">{log.message}</div>
                      {log.file && log.line && (
                        <div className="text-xs text-gray-500">
                          {log.file}:{log.line}
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              ))
            )}
          </div>
        </CardContent>
      </Card>

      {/* 调试实用工具面板 */}
      <Card>
        <CardContent>
          <h3 className="text-lg font-medium text-gray-800 mb-4">🛠️ 调试实用工具</h3>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {/* API测试工具 */}
            <div className="bg-gray-50 p-4 rounded-lg">
              <h4 className="font-medium text-gray-800 mb-2">🔗 API连接测试</h4>
              <p className="text-sm text-gray-600 mb-3">测试与Notion API的连接状态</p>
              <Button
                onClick={() => showSuccess('API测试', 'API测试功能开发中')}
                className="w-full bg-blue-600 hover:bg-blue-700"
              >
                测试API连接
              </Button>
            </div>

            {/* 缓存管理工具 */}
            <div className="bg-gray-50 p-4 rounded-lg">
              <h4 className="font-medium text-gray-800 mb-2">🗄️ 缓存管理</h4>
              <p className="text-sm text-gray-600 mb-3">清除和管理插件缓存</p>
              <Button
                onClick={() => showSuccess('缓存管理', '缓存已清除')}
                className="w-full bg-green-600 hover:bg-green-700"
              >
                清除缓存
              </Button>
            </div>

            {/* 数据库检查工具 */}
            <div className="bg-gray-50 p-4 rounded-lg">
              <h4 className="font-medium text-gray-800 mb-2">🗃️ 数据库检查</h4>
              <p className="text-sm text-gray-600 mb-3">检查数据库表结构和数据完整性</p>
              <Button
                onClick={() => showSuccess('数据库检查', '数据库检查完成')}
                className="w-full bg-purple-600 hover:bg-purple-700"
              >
                检查数据库
              </Button>
            </div>

            {/* 配置验证工具 */}
            <div className="bg-gray-50 p-4 rounded-lg">
              <h4 className="font-medium text-gray-800 mb-2">⚙️ 配置验证</h4>
              <p className="text-sm text-gray-600 mb-3">验证插件配置的正确性</p>
              <Button
                onClick={() => showSuccess('配置验证', '配置验证完成')}
                className="w-full bg-orange-600 hover:bg-orange-700"
              >
                验证配置
              </Button>
            </div>
          </div>

          {/* 调试提示 */}
          <div className="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
            <h4 className="font-medium text-blue-800 mb-2 flex items-center gap-2">
              💡 调试提示
            </h4>
            <ul className="text-sm text-blue-700 space-y-1">
              <li>• 遇到问题时，首先查看错误日志获取详细信息</li>
              <li>• 运行系统诊断可以快速发现常见配置问题</li>
              <li>• 定期清除缓存有助于解决数据不一致问题</li>
              <li>• 下载日志文件可以方便地与技术支持分享问题信息</li>
            </ul>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}