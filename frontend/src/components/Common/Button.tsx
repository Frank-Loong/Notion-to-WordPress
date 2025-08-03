/**
 * 基础按钮组件
 * 支持多种变体、尺寸和状态
 */

import React, { forwardRef } from 'react'
import { cn } from '../../utils/cn'
import type { BaseComponentProps, ButtonVariant, Size } from './types'

export interface ButtonProps extends BaseComponentProps, Omit<React.ButtonHTMLAttributes<HTMLButtonElement>, 'disabled'> {
  variant?: ButtonVariant
  size?: Size
  fullWidth?: boolean
  icon?: React.ReactNode
  iconPosition?: 'left' | 'right'
}

// 按钮变体样式映射
const variantStyles: Record<ButtonVariant, string> = {
  primary: 'bg-blue-600 text-white hover:bg-blue-700 focus:ring-blue-500',
  secondary: 'bg-gray-200 text-gray-800 hover:bg-gray-300 focus:ring-gray-500',
  success: 'bg-green-600 text-white hover:bg-green-700 focus:ring-green-500',
  warning: 'bg-yellow-600 text-white hover:bg-yellow-700 focus:ring-yellow-500',
  danger: 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
  info: 'bg-blue-500 text-white hover:bg-blue-600 focus:ring-blue-400',
  ghost: 'bg-transparent text-gray-700 hover:bg-gray-100 focus:ring-gray-500',
  outline: 'border border-gray-300 bg-transparent text-gray-700 hover:bg-gray-50 focus:ring-gray-500',
  link: 'bg-transparent text-blue-600 hover:text-blue-700 hover:underline focus:ring-blue-500 p-0'
}

// 按钮尺寸样式映射
const sizeStyles: Record<Size, string> = {
  xs: 'px-2 py-1 text-xs',
  sm: 'px-3 py-1.5 text-sm',
  md: 'px-4 py-2 text-sm',
  lg: 'px-6 py-3 text-base',
  xl: 'px-8 py-4 text-lg'
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(({
  variant = 'primary',
  size = 'md',
  fullWidth = false,
  icon,
  iconPosition = 'left',
  loading = false,
  disabled = false,
  className,
  children,
  ...props
}, ref) => {
  const isDisabled = disabled || loading

  return (
    <button
      ref={ref}
      className={cn(
        // 基础样式
        'inline-flex items-center justify-center font-medium rounded-md transition-colors duration-200',
        'focus:outline-none focus:ring-2 focus:ring-offset-2',
        'disabled:opacity-50 disabled:cursor-not-allowed',
        
        // 变体样式
        variantStyles[variant],
        
        // 尺寸样式（link变体除外）
        variant !== 'link' && sizeStyles[size],
        
        // 全宽样式
        fullWidth && 'w-full',
        
        // 加载状态
        loading && 'cursor-wait',
        
        className
      )}
      disabled={isDisabled}
      {...props}
    >
      {/* 左侧图标或加载状态 */}
      {(icon && iconPosition === 'left') || loading ? (
        <span className={cn('flex items-center', children && 'mr-2')}>
          {loading ? (
            <svg className="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
            </svg>
          ) : icon}
        </span>
      ) : null}
      
      {/* 按钮文本 */}
      {children}
      
      {/* 右侧图标 */}
      {icon && iconPosition === 'right' && !loading ? (
        <span className={cn('flex items-center', children && 'ml-2')}>
          {icon}
        </span>
      ) : null}
    </button>
  )
})

Button.displayName = 'Button'
