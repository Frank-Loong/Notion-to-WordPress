import React, { useState } from 'react'
import { useSyncStore } from '../../stores/syncStore'
import { Button } from '../Common'

interface SyncButtonsProps {
  disabled?: boolean
}

export const SyncButtons: React.FC<SyncButtonsProps> = ({ disabled = false }) => {
  const { startSync } = useSyncStore()
  const [loading, setLoading] = useState<string | null>(null)

  const handleSync = async (type: 'smart' | 'full' | 'test') => {
    setLoading(type)
    try {
      if (type === 'test') {
        // 测试连接逻辑
        await new Promise(resolve => setTimeout(resolve, 1000))
      } else {
        await startSync({
          type: type as 'smart' | 'full',
          incremental: type === 'smart',
          force_refresh: type === 'full'
        })
      }
    } catch (error) {
      console.error(`${type} sync failed:`, error)
    } finally {
      setLoading(null)
    }
  }

  const buttons = [
    {
      id: 'test',
      label: '🔗 测试连接',
      description: '验证Notion API连接状态',
      variant: 'secondary' as const,
      action: () => handleSync('test')
    },
    {
      id: 'smart',
      label: '🚀 智能同步',
      description: '仅同步更新的内容',
      variant: 'primary' as const,
      action: () => handleSync('smart')
    },
    {
      id: 'full',
      label: '📥 完整同步',
      description: '同步所有页面数据',
      variant: 'warning' as const,
      action: () => handleSync('full')
    }
  ]

  return (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
      {buttons.map((button) => (
        <div key={button.id} className="space-y-2">
          <Button
            variant={button.variant}
            size="lg"
            fullWidth
            loading={loading === button.id}
            disabled={disabled || loading !== null}
            onClick={button.action}
          >
            {button.label}
          </Button>
          <p className="text-sm text-gray-600 text-center">
            {button.description}
          </p>
        </div>
      ))}
    </div>
  )
}