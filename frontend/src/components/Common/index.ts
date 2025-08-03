/**
 * 基础UI组件库导出
 * 统一导出所有基础组件和类型
 */

// 组件导出
export { Button } from './Button'
export type { ButtonProps } from './Button'

export { Input } from './Input'
export type { InputProps } from './Input'

export { Card, CardHeader, CardContent, CardFooter } from './Card'
export type { CardProps } from './Card'

export { Loading, InlineLoading } from './Loading'
export type { LoadingProps } from './Loading'

export { Modal, ConfirmModal } from './Modal'
export type { ModalProps } from './Modal'

// 类型导出
export type {
  Size,
  Variant,
  Status,
  BaseComponentProps,
  ButtonVariant,
  InputType,
  NotificationType,
  ModalSize,
  Position,
  Alignment
} from './types'

// 工具函数导出
export { cn, notionClass, notionCn } from '../../utils/cn'
