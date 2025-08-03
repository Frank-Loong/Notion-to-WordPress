/**
 * 类名合并工具函数
 * 用于合并Tailwind CSS类名和条件类名
 */

import { type ClassValue, clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

/**
 * 合并类名的工具函数
 * 支持条件类名和Tailwind CSS类名冲突解决
 */
export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/**
 * 生成notion-wp前缀的类名
 */
export function notionClass(className: string) {
  return `notion-wp-${className}`
}

/**
 * 合并notion-wp类名和其他类名
 */
export function notionCn(notionClassName: string, ...inputs: ClassValue[]) {
  return cn(notionClass(notionClassName), ...inputs)
}
