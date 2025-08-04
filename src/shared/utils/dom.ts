/**
 * DOM操作工具函数
 */

export type EventCallback = (event: Event, ...args: any[]) => void;

/**
 * 查询单个元素
 */
export function querySelector<T extends HTMLElement = HTMLElement>(
  selector: string,
  context: Document | HTMLElement = document
): T | null {
  return context.querySelector<T>(selector);
}

/**
 * 查询多个元素
 */
export function querySelectorAll<T extends HTMLElement = HTMLElement>(
  selector: string,
  context: Document | HTMLElement = document
): NodeListOf<T> {
  return context.querySelectorAll<T>(selector);
}

/**
 * 创建元素
 */
export function createElement<K extends keyof HTMLElementTagNameMap>(
  tagName: K,
  attributes?: Record<string, string>,
  textContent?: string
): HTMLElementTagNameMap[K] {
  const element = document.createElement(tagName);
  
  if (attributes) {
    Object.entries(attributes).forEach(([key, value]) => {
      element.setAttribute(key, value);
    });
  }
  
  if (textContent) {
    element.textContent = textContent;
  }
  
  return element;
}

/**
 * 添加CSS类
 */
export function addClass(element: HTMLElement, ...classNames: string[]): void {
  element.classList.add(...classNames);
}

/**
 * 移除CSS类
 */
export function removeClass(element: HTMLElement, ...classNames: string[]): void {
  element.classList.remove(...classNames);
}

/**
 * 切换CSS类
 */
export function toggleClass(element: HTMLElement, className: string, force?: boolean): boolean {
  return element.classList.toggle(className, force);
}

/**
 * 检查是否包含CSS类
 */
export function hasClass(element: HTMLElement, className: string): boolean {
  return element.classList.contains(className);
}

/**
 * 设置元素属性
 */
export function setAttribute(element: HTMLElement, name: string, value: string): void {
  element.setAttribute(name, value);
}

/**
 * 获取元素属性
 */
export function getAttribute(element: HTMLElement, name: string): string | null {
  return element.getAttribute(name);
}

/**
 * 移除元素属性
 */
export function removeAttribute(element: HTMLElement, name: string): void {
  element.removeAttribute(name);
}

/**
 * 设置元素样式
 */
export function setStyle(element: HTMLElement, styles: Partial<CSSStyleDeclaration>): void {
  Object.assign(element.style, styles);
}

/**
 * 获取计算样式
 */
export function getComputedStyle(element: HTMLElement, property?: string): string | CSSStyleDeclaration {
  const computed = window.getComputedStyle(element);
  return property ? computed.getPropertyValue(property) : computed;
}

/**
 * 显示元素
 */
export function show(element: HTMLElement, display = 'block'): void {
  element.style.display = display;
}

/**
 * 隐藏元素
 */
export function hide(element: HTMLElement): void {
  element.style.display = 'none';
}

/**
 * 切换元素显示状态
 */
export function toggle(element: HTMLElement, display = 'block'): void {
  if (element.style.display === 'none') {
    show(element, display);
  } else {
    hide(element);
  }
}

/**
 * 添加事件监听器
 */
export function addEventListener<K extends keyof HTMLElementEventMap>(
  element: HTMLElement,
  type: K,
  listener: (this: HTMLElement, ev: HTMLElementEventMap[K]) => any,
  options?: boolean | AddEventListenerOptions
): void {
  element.addEventListener(type, listener, options);
}

/**
 * 移除事件监听器
 */
export function removeEventListener<K extends keyof HTMLElementEventMap>(
  element: HTMLElement,
  type: K,
  listener: (this: HTMLElement, ev: HTMLElementEventMap[K]) => any,
  options?: boolean | EventListenerOptions
): void {
  element.removeEventListener(type, listener, options);
}

/**
 * 委托事件监听
 */
export function delegate(
  container: HTMLElement,
  selector: string,
  eventType: string,
  callback: EventCallback
): void {
  addEventListener(container, eventType as keyof HTMLElementEventMap, (event) => {
    const target = event.target as HTMLElement;
    const delegateTarget = target.closest(selector) as HTMLElement;
    
    if (delegateTarget && container.contains(delegateTarget)) {
      callback.call(delegateTarget, event);
    }
  });
}

/**
 * 获取元素位置信息
 */
export function getOffset(element: HTMLElement): { top: number; left: number } {
  const rect = element.getBoundingClientRect();
  return {
    top: rect.top + window.pageYOffset,
    left: rect.left + window.pageXOffset
  };
}

/**
 * 获取元素尺寸信息
 */
export function getSize(element: HTMLElement): { width: number; height: number } {
  const rect = element.getBoundingClientRect();
  return {
    width: rect.width,
    height: rect.height
  };
}

/**
 * 滚动到元素
 */
export function scrollToElement(
  element: HTMLElement,
  options: ScrollIntoViewOptions = { behavior: 'smooth', block: 'start' }
): void {
  element.scrollIntoView(options);
}

/**
 * 检查元素是否在视口中
 */
export function isInViewport(element: HTMLElement): boolean {
  const rect = element.getBoundingClientRect();
  return (
    rect.top >= 0 &&
    rect.left >= 0 &&
    rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
    rect.right <= (window.innerWidth || document.documentElement.clientWidth)
  );
}

/**
 * 等待DOM准备就绪
 */
export function ready(callback: () => void): void {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', callback);
  } else {
    callback();
  }
}

/**
 * 防抖函数
 */
export function debounce<T extends (...args: any[]) => any>(
  func: T,
  wait: number,
  immediate = false
): (...args: Parameters<T>) => void {
  let timeout: NodeJS.Timeout | null = null;
  
  return function executedFunction(...args: Parameters<T>) {
    const later = () => {
      timeout = null;
      if (!immediate) func(...args);
    };
    
    const callNow = immediate && !timeout;
    
    if (timeout) clearTimeout(timeout);
    timeout = setTimeout(later, wait);
    
    if (callNow) func(...args);
  };
}

/**
 * 节流函数
 */
export function throttle<T extends (...args: any[]) => any>(
  func: T,
  limit: number
): (...args: Parameters<T>) => void {
  let inThrottle: boolean;
  
  return function executedFunction(...args: Parameters<T>) {
    if (!inThrottle) {
      func(...args);
      inThrottle = true;
      setTimeout(() => inThrottle = false, limit);
    }
  };
}
