import React, { useState, useEffect } from 'react'
import { useUIStore } from '../../stores/uiStore'
import { Card, CardContent, Button, Input } from '../Common'

interface LogEntry {
  id: string
  timestamp: string
  level: 'info' | 'warning' | 'error' | 'debug'
  message: string
  context?: Record<string, any>
}

interface LogFilter {
  level: string
  search: string
  dateFrom: string
  dateTo: string
}

export const LogViewer: React.FC = () => {
  const { showSuccess, showError } = useUIStore()
  const [logs, setLogs] = useState<LogEntry[]>([])
  const [filteredLogs, setFilteredLogs] = useState<LogEntry[]>([])
  const [isLoading, setIsLoading] = useState(false)
  const [filter, setFilter] = useState<LogFilter>({
    level: 'all',
    search: '',
    dateFrom: '',
    dateTo: ''
  })

  // 模拟日志数据
  const mockLogs: LogEntry[] = [
    {
      id: '1',
      timestamp: '2024-01-15 10:30:25',
      level: 'info',
      message: '同步任务开始执行',
      context: { task_id: 'sync_001', pages: 25 }
    },
    {
      id: '2',
      timestamp: '2024-01-15 10:30:45',
      level: 'info',
      message: '成功同步页面: "产品介绍"',
      context: { page_id: 'abc123', title: '产品介绍' }
    },
    {
      id: '3',
      timestamp: '2024-01-15 10:31:02',
      level: 'warning',
      message: 'API请求速率接近限制',
      context: { rate_limit: '80%', remaining: 20 }
    },
    {
      id: '4',
      timestamp: '2024-01-15 10:31:15',
      level: 'error',
      message: '同步失败: 网络连接超时',
      context: { error_code: 'TIMEOUT', retry_count: 3 }
    },
    {
      id: '5',
      timestamp: '2024-01-15 10:31:30',
      level: 'info',
      message: '同步任务完成',
      context: { task_id: 'sync_001', success: 23, failed: 2 }
    },
    {
      id: '6',
      timestamp: '2024-01-15 10:32:00',
      level: 'debug',
      message: '缓存已更新',
      context: { cache_key: 'notion_pages', size: '2.5MB' }
    }
  ]

  const fetchLogs = async () => {
    setIsLoading(true)
    try {
      // 模拟API调用
      await new Promise(resolve => setTimeout(resolve, 800))
      setLogs(mockLogs)
      showSuccess('日志已加载', '日志数据已成功刷新')
    } catch (error) {
      showError('加载日志失败', '请检查网络连接后重试')
    } finally {
      setIsLoading(false)
    }
  }

  const clearLogs = async () => {
    if (window.confirm('确定要清除所有日志吗？此操作不可撤销。')) {
      setIsLoading(true)
      try {
        // 模拟清除日志
        await new Promise(resolve => setTimeout(resolve, 500))
        setLogs([])
        setFilteredLogs([])
        showSuccess('日志已清除', '所有日志已成功清除')
      } catch (error) {
        showError('清除日志失败', '请稍后重试')
      } finally {
        setIsLoading(false)
      }
    }
  }

  const downloadLogs = () => {
    const logText = filteredLogs.map(log => 
      `[${log.timestamp}] ${log.level.toUpperCase()}: ${log.message}${
        log.context ? ' | Context: ' + JSON.stringify(log.context) : ''
      }`
    ).join('\n')
    
    const blob = new Blob([logText], { type: 'text/plain' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `notion-wp-logs-${new Date().toISOString().split('T')[0]}.txt`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
    
    showSuccess('日志已下载', '日志文件已保存到下载目录')
  }

  const getLevelColor = (level: string) => {
    switch (level) {
      case 'error':
        return 'text-red-600 bg-red-50 border-red-200'
      case 'warning':
        return 'text-yellow-600 bg-yellow-50 border-yellow-200'
      case 'info':
        return 'text-blue-600 bg-blue-50 border-blue-200'
      case 'debug':
        return 'text-gray-600 bg-gray-50 border-gray-200'
      default:
        return 'text-gray-600 bg-gray-50 border-gray-200'
    }
  }

  const getLevelIcon = (level: string) => {
    switch (level) {
      case 'error':
        return '❌'
      case 'warning':
        return '⚠️'
      case 'info':
        return 'ℹ️'
      case 'debug':
        return '🔍'
      default:
        return '📝'
    }
  }

  // 过滤日志
  useEffect(() => {
    let filtered = logs

    // 按级别过滤
    if (filter.level !== 'all') {
      filtered = filtered.filter(log => log.level === filter.level)
    }

    // 按搜索词过滤
    if (filter.search) {
      const searchLower = filter.search.toLowerCase()
      filtered = filtered.filter(log => 
        log.message.toLowerCase().includes(searchLower) ||
        (log.context && JSON.stringify(log.context).toLowerCase().includes(searchLower))
      )
    }

    // 按日期过滤
    if (filter.dateFrom) {
      filtered = filtered.filter(log => log.timestamp >= filter.dateFrom)
    }
    if (filter.dateTo) {
      filtered = filtered.filter(log => log.timestamp <= filter.dateTo)
    }

    setFilteredLogs(filtered)
  }, [logs, filter])

  useEffect(() => {
    fetchLogs()
  }, [])

  return (
    <div className="space-y-6">
      <div className="notion-wp-header-section">
        <h2 className="text-xl font-semibold text-gray-800">
          📋 日志查看器
        </h2>
        <p className="text-sm text-gray-600">
          查看和管理系统日志
        </p>
      </div>

      {/* 日志过滤器 */}
      <Card 
        title="日志过滤" 
        subtitle="筛选和搜索日志条目"
        shadow="md"
      >
        <CardContent className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                日志级别
              </label>
              <select
                value={filter.level}
                onChange={(e) => setFilter(prev => ({ ...prev, level: e.target.value }))}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="all">全部</option>
                <option value="error">错误</option>
                <option value="warning">警告</option>
                <option value="info">信息</option>
                <option value="debug">调试</option>
              </select>
            </div>

            <Input
              label="搜索关键词"
              value={filter.search}
              onChange={(e) => setFilter(prev => ({ ...prev, search: e.target.value }))}
              placeholder="搜索日志内容..."
            />

            <Input
              label="开始日期"
              type="date"
              value={filter.dateFrom}
              onChange={(e) => setFilter(prev => ({ ...prev, dateFrom: e.target.value }))}
            />

            <Input
              label="结束日期"
              type="date"
              value={filter.dateTo}
              onChange={(e) => setFilter(prev => ({ ...prev, dateTo: e.target.value }))}
            />
          </div>

          <div className="flex flex-wrap gap-2 pt-4 border-t">
            <Button
              variant="primary"
              onClick={fetchLogs}
              loading={isLoading}
              disabled={isLoading}
            >
              刷新日志
            </Button>
            <Button
              variant="secondary"
              onClick={downloadLogs}
              disabled={filteredLogs.length === 0}
            >
              下载日志
            </Button>
            <Button
              variant="warning"
              onClick={clearLogs}
              loading={isLoading}
              disabled={isLoading || logs.length === 0}
            >
              清除日志
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* 日志列表 */}
      <Card 
        title={`日志条目 (${filteredLogs.length})`}
        subtitle="按时间倒序显示"
        shadow="md"
      >
        <CardContent>
          {isLoading ? (
            <div className="text-center py-8">
              <div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
              <p className="mt-2 text-gray-600">正在加载日志...</p>
            </div>
          ) : filteredLogs.length > 0 ? (
            <div className="space-y-2 max-h-96 overflow-y-auto">
              {filteredLogs.map((log) => (
                <div
                  key={log.id}
                  className={`p-3 rounded-lg border ${getLevelColor(log.level)}`}
                >
                  <div className="flex items-start justify-between">
                    <div className="flex items-start space-x-3 flex-1">
                      <span className="text-lg">{getLevelIcon(log.level)}</span>
                      <div className="flex-1">
                        <div className="flex items-center space-x-2 mb-1">
                          <span className="text-xs font-medium uppercase tracking-wide">
                            {log.level}
                          </span>
                          <span className="text-xs text-gray-500">
                            {log.timestamp}
                          </span>
                        </div>
                        <p className="text-sm font-medium">{log.message}</p>
                        {log.context && (
                          <details className="mt-2">
                            <summary className="text-xs text-gray-600 cursor-pointer hover:text-gray-800">
                              查看详细信息
                            </summary>
                            <pre className="mt-1 text-xs bg-white bg-opacity-50 p-2 rounded border overflow-x-auto">
                              {JSON.stringify(log.context, null, 2)}
                            </pre>
                          </details>
                        )}
                      </div>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <div className="text-center py-8 text-gray-500">
              {logs.length === 0 ? '暂无日志记录' : '没有符合条件的日志'}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
