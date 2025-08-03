/**
 * 加载状态组件
 * 支持多种加载样式和尺寸
 */

import React from 'react'
import { cn } from '../../utils/cn'
import type { BaseComponentProps, Size } from './types'

export interface LoadingProps extends BaseComponentProps {
  size?: Size
  variant?: 'spinner' | 'dots' | 'pulse' | 'bars'
  text?: string
  overlay?: boolean
  fullScreen?: boolean
}

// 尺寸样式映射
const sizeStyles: Record<Size, { spinner: string; text: string }> = {
  xs: { spinner: 'w-3 h-3', text: 'text-xs' },
  sm: { spinner: 'w-4 h-4', text: 'text-sm' },
  md: { spinner: 'w-6 h-6', text: 'text-base' },
  lg: { spinner: 'w-8 h-8', text: 'text-lg' },
  xl: { spinner: 'w-12 h-12', text: 'text-xl' }
}

// 旋转加载器
const Spinner: React.FC<{ size: Size; className?: string }> = ({ size, className }) => (
  <svg
    className={cn('animate-spin', sizeStyles[size].spinner, className)}
    fill="none"
    viewBox="0 0 24 24"
  >
    <circle
      className="opacity-25"
      cx="12"
      cy="12"
      r="10"
      stroke="currentColor"
      strokeWidth="4"
    />
    <path
      className="opacity-75"
      fill="currentColor"
      d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
    />
  </svg>
)

// 点状加载器
const Dots: React.FC<{ size: Size; className?: string }> = ({ size, className }) => {
  const dotSize = size === 'xs' ? 'w-1 h-1' : size === 'sm' ? 'w-1.5 h-1.5' : size === 'md' ? 'w-2 h-2' : size === 'lg' ? 'w-3 h-3' : 'w-4 h-4'
  
  return (
    <div className={cn('flex space-x-1', className)}>
      {[0, 1, 2].map((i) => (
        <div
          key={i}
          className={cn(
            'bg-current rounded-full animate-pulse',
            dotSize
          )}
          style={{
            animationDelay: `${i * 0.2}s`,
            animationDuration: '1s'
          }}
        />
      ))}
    </div>
  )
}

// 脉冲加载器
const Pulse: React.FC<{ size: Size; className?: string }> = ({ size, className }) => (
  <div
    className={cn(
      'bg-current rounded-full animate-pulse',
      sizeStyles[size].spinner,
      className
    )}
  />
)

// 条状加载器
const Bars: React.FC<{ size: Size; className?: string }> = ({ size, className }) => {
  const barHeight = size === 'xs' ? 'h-2' : size === 'sm' ? 'h-3' : size === 'md' ? 'h-4' : size === 'lg' ? 'h-6' : 'h-8'
  const barWidth = size === 'xs' ? 'w-0.5' : size === 'sm' ? 'w-1' : 'w-1.5'
  
  return (
    <div className={cn('flex items-end space-x-1', className)}>
      {[0, 1, 2, 3].map((i) => (
        <div
          key={i}
          className={cn(
            'bg-current animate-pulse',
            barWidth,
            barHeight
          )}
          style={{
            animationDelay: `${i * 0.15}s`,
            animationDuration: '1.2s'
          }}
        />
      ))}
    </div>
  )
}

export const Loading: React.FC<LoadingProps> = ({
  size = 'md',
  variant = 'spinner',
  text,
  overlay = false,
  fullScreen = false,
  className,
  children,
  ...props
}) => {
  const LoadingComponent = {
    spinner: Spinner,
    dots: Dots,
    pulse: Pulse,
    bars: Bars
  }[variant]

  const content = (
    <div
      className={cn(
        'flex flex-col items-center justify-center space-y-2',
        fullScreen && 'min-h-screen',
        className
      )}
      {...props}
    >
      <LoadingComponent size={size} className="text-blue-600" />
      {text && (
        <p className={cn('text-gray-600', sizeStyles[size].text)}>
          {text}
        </p>
      )}
      {children}
    </div>
  )

  if (overlay || fullScreen) {
    return (
      <div
        className={cn(
          'fixed inset-0 bg-white bg-opacity-75 backdrop-blur-sm z-50',
          'flex items-center justify-center'
        )}
      >
        {content}
      </div>
    )
  }

  return content
}

// 内联加载器（用于按钮等小空间）
export const InlineLoading: React.FC<{
  size?: Size
  className?: string
}> = ({ size = 'sm', className }) => (
  <Spinner size={size} className={cn('text-current', className)} />
)
