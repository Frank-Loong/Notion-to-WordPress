/**
 * 基础UI组件的通用类型定义
 */

import { ReactNode } from 'react'

// 基础尺寸类型
export type Size = 'xs' | 'sm' | 'md' | 'lg' | 'xl'

// 基础变体类型
export type Variant = 'primary' | 'secondary' | 'success' | 'warning' | 'danger' | 'info'

// 基础状态类型
export type Status = 'idle' | 'loading' | 'success' | 'error'

// 通用组件Props
export interface BaseComponentProps {
  className?: string
  children?: ReactNode
  disabled?: boolean
  loading?: boolean
}

// 按钮变体类型
export type ButtonVariant = Variant | 'ghost' | 'outline' | 'link'

// 输入框类型
export type InputType = 'text' | 'email' | 'password' | 'number' | 'url' | 'tel' | 'search' | 'date' | 'datetime-local' | 'time'

// 通知类型
export type NotificationType = 'success' | 'error' | 'warning' | 'info'

// 模态框尺寸
export type ModalSize = 'sm' | 'md' | 'lg' | 'xl' | 'full'

// 位置类型
export type Position = 'top' | 'bottom' | 'left' | 'right' | 'top-left' | 'top-right' | 'bottom-left' | 'bottom-right'

// 对齐类型
export type Alignment = 'start' | 'center' | 'end'
