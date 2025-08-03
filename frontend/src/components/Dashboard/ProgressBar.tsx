import React from 'react'
import { cn } from '../../utils/cn'
import { Loading } from '../Common'

interface ProgressBarProps {
  progress: number
  status: string
  currentStep: string
}

export const ProgressBar: React.FC<ProgressBarProps> = ({
  progress,
  status,
  currentStep
}) => {
  const getStatusColor = (status: string) => {
    switch (status) {
      case 'running':
        return 'bg-blue-500'
      case 'completed':
        return 'bg-green-500'
      case 'error':
        return 'bg-red-500'
      case 'paused':
        return 'bg-yellow-500'
      default:
        return 'bg-gray-500'
    }
  }

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'running':
        return '🔄'
      case 'completed':
        return '✅'
      case 'error':
        return '❌'
      case 'paused':
        return '⏸️'
      default:
        return '⭕'
    }
  }

  return (
    <div className="space-y-4">
      {/* 进度条头部 */}
      <div className="flex items-center justify-between">
        <div className="flex items-center space-x-2">
          <span className="text-lg">
            {getStatusIcon(status)}
          </span>
          <span className="text-sm font-medium text-gray-700">
            {currentStep || '准备中...'}
          </span>
        </div>
        <div className="text-sm font-semibold text-gray-900">
          {Math.round(progress)}%
        </div>
      </div>

      {/* 进度条 */}
      <div className="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
        <div
          className={cn(
            'h-full transition-all duration-300 ease-out rounded-full',
            getStatusColor(status)
          )}
          style={{ width: `${progress}%` }}
        >
          {/* 动画效果 */}
          {status === 'running' && (
            <div className="h-full w-full bg-gradient-to-r from-transparent via-white to-transparent opacity-30 animate-pulse" />
          )}
        </div>
      </div>

      {/* 运行状态详情 */}
      {status === 'running' && (
        <div className="flex items-center space-x-2 text-sm text-gray-600">
          <Loading variant="spinner" size="xs" />
          <span>正在处理...</span>
        </div>
      )}
    </div>
  )
}