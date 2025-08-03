/**
 * 基础卡片组件
 * 支持标题、内容、操作区域和多种样式
 */

import React from 'react'
import { cn } from '../../utils/cn'
import type { BaseComponentProps } from './types'

export interface CardProps extends BaseComponentProps {
  title?: React.ReactNode
  subtitle?: React.ReactNode
  actions?: React.ReactNode
  footer?: React.ReactNode
  padding?: 'none' | 'sm' | 'md' | 'lg'
  shadow?: 'none' | 'sm' | 'md' | 'lg'
  border?: boolean
  hover?: boolean
}

// 内边距样式映射
const paddingStyles = {
  none: '',
  sm: 'p-3',
  md: 'p-4',
  lg: 'p-6'
}

// 阴影样式映射
const shadowStyles = {
  none: '',
  sm: 'shadow-sm',
  md: 'shadow-md',
  lg: 'shadow-lg'
}

export const Card: React.FC<CardProps> = ({
  title,
  subtitle,
  actions,
  footer,
  padding = 'md',
  shadow = 'sm',
  border = true,
  hover = false,
  className,
  children,
  ...props
}) => {
  return (
    <div
      className={cn(
        // 基础样式
        'bg-white rounded-lg transition-shadow duration-200',
        
        // 边框
        border && 'border border-gray-200',
        
        // 阴影
        shadowStyles[shadow],
        
        // 悬停效果
        hover && 'hover:shadow-md',
        
        className
      )}
      {...props}
    >
      {/* 卡片头部 */}
      {(title || subtitle || actions) && (
        <div className={cn(
          'flex items-start justify-between',
          padding !== 'none' && 'px-4 py-3 border-b border-gray-200'
        )}>
          <div className="flex-1 min-w-0">
            {title && (
              <h3 className="text-lg font-medium text-gray-900 truncate">
                {title}
              </h3>
            )}
            {subtitle && (
              <p className="mt-1 text-sm text-gray-500">
                {subtitle}
              </p>
            )}
          </div>
          {actions && (
            <div className="flex items-center space-x-2 ml-4">
              {actions}
            </div>
          )}
        </div>
      )}
      
      {/* 卡片内容 */}
      {children && (
        <div className={cn(
          paddingStyles[padding],
          (title || subtitle || actions) && padding !== 'none' && 'pt-0'
        )}>
          {children}
        </div>
      )}
      
      {/* 卡片底部 */}
      {footer && (
        <div className={cn(
          'border-t border-gray-200',
          padding !== 'none' && 'px-4 py-3'
        )}>
          {footer}
        </div>
      )}
    </div>
  )
}

// 卡片头部组件
export const CardHeader: React.FC<{
  title?: React.ReactNode
  subtitle?: React.ReactNode
  actions?: React.ReactNode
  className?: string
}> = ({ title, subtitle, actions, className }) => (
  <div className={cn('flex items-start justify-between p-4 border-b border-gray-200', className)}>
    <div className="flex-1 min-w-0">
      {title && (
        <h3 className="text-lg font-medium text-gray-900 truncate">
          {title}
        </h3>
      )}
      {subtitle && (
        <p className="mt-1 text-sm text-gray-500">
          {subtitle}
        </p>
      )}
    </div>
    {actions && (
      <div className="flex items-center space-x-2 ml-4">
        {actions}
      </div>
    )}
  </div>
)

// 卡片内容组件
export const CardContent: React.FC<{
  children: React.ReactNode
  className?: string
  padding?: 'none' | 'sm' | 'md' | 'lg'
}> = ({ children, className, padding = 'md' }) => (
  <div className={cn(paddingStyles[padding], className)}>
    {children}
  </div>
)

// 卡片底部组件
export const CardFooter: React.FC<{
  children: React.ReactNode
  className?: string
  padding?: 'none' | 'sm' | 'md' | 'lg'
}> = ({ children, className, padding = 'md' }) => (
  <div className={cn('border-t border-gray-200', paddingStyles[padding], className)}>
    {children}
  </div>
)
