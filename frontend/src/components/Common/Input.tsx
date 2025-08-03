/**
 * 基础输入框组件
 * 支持多种类型、状态和验证
 */

import React, { forwardRef } from 'react'
import { cn } from '../../utils/cn'
import type { BaseComponentProps, InputType, Size, Status } from './types'

export interface InputProps extends BaseComponentProps, Omit<React.InputHTMLAttributes<HTMLInputElement>, 'size' | 'disabled'> {
  type?: InputType
  size?: Size
  status?: Status
  label?: string
  helperText?: string
  errorText?: string
  leftIcon?: React.ReactNode
  rightIcon?: React.ReactNode
  fullWidth?: boolean
}

// 输入框尺寸样式映射
const sizeStyles: Record<Size, string> = {
  xs: 'px-2 py-1 text-xs',
  sm: 'px-3 py-1.5 text-sm',
  md: 'px-3 py-2 text-sm',
  lg: 'px-4 py-3 text-base',
  xl: 'px-5 py-4 text-lg'
}

// 状态样式映射
const statusStyles: Record<Status, string> = {
  idle: 'border-gray-300 focus:border-blue-500 focus:ring-blue-500',
  loading: 'border-gray-300 focus:border-blue-500 focus:ring-blue-500',
  success: 'border-green-500 focus:border-green-500 focus:ring-green-500',
  error: 'border-red-500 focus:border-red-500 focus:ring-red-500'
}

export const Input = forwardRef<HTMLInputElement, InputProps>(({
  type = 'text',
  size = 'md',
  status = 'idle',
  label,
  helperText,
  errorText,
  leftIcon,
  rightIcon,
  fullWidth = false,
  loading = false,
  disabled = false,
  className,
  ...props
}, ref) => {
  const inputId = props.id || `input-${Math.random().toString(36).substring(2, 9)}`
  const hasError = status === 'error' || !!errorText
  const currentStatus = hasError ? 'error' : status
  const isDisabled = disabled || loading

  return (
    <div className={cn('flex flex-col', fullWidth && 'w-full')}>
      {/* 标签 */}
      {label && (
        <label 
          htmlFor={inputId}
          className="block text-sm font-medium text-gray-700 mb-1"
        >
          {label}
        </label>
      )}
      
      {/* 输入框容器 */}
      <div className="relative">
        {/* 左侧图标 */}
        {leftIcon && (
          <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <span className="text-gray-400">
              {leftIcon}
            </span>
          </div>
        )}
        
        {/* 输入框 */}
        <input
          ref={ref}
          id={inputId}
          type={type}
          className={cn(
            // 基础样式
            'block w-full border rounded-md shadow-sm transition-colors duration-200',
            'placeholder-gray-400 focus:outline-none focus:ring-1',
            'disabled:bg-gray-50 disabled:text-gray-500 disabled:cursor-not-allowed',
            
            // 尺寸样式
            sizeStyles[size],
            
            // 状态样式
            statusStyles[currentStatus],
            
            // 图标间距
            leftIcon && 'pl-10',
            rightIcon && 'pr-10',
            
            className
          )}
          disabled={isDisabled}
          {...props}
        />
        
        {/* 右侧图标或加载状态 */}
        {(rightIcon || loading) && (
          <div className="absolute inset-y-0 right-0 pr-3 flex items-center">
            {loading ? (
              <svg className="animate-spin h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
              </svg>
            ) : (
              <span className="text-gray-400">
                {rightIcon}
              </span>
            )}
          </div>
        )}
      </div>
      
      {/* 帮助文本或错误信息 */}
      {(helperText || errorText) && (
        <p className={cn(
          'mt-1 text-xs',
          hasError ? 'text-red-600' : 'text-gray-500'
        )}>
          {errorText || helperText}
        </p>
      )}
    </div>
  )
})

Input.displayName = 'Input'
