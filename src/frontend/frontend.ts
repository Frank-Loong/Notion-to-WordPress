/**
 * 前端入口文件
 */

import { ready } from '../shared/utils/dom';
import { eventBus, emit } from '../shared/core/EventBus';
import { frontendContent } from './FrontendContent';

// 导入样式
import '../styles/frontend/frontend.scss';

/**
 * 前端应用主类
 */
class FrontendApp {
  private initialized = false;

  /**
   * 初始化应用
   */
  init(): void {
    if (this.initialized) {
      return;
    }

    console.log('🚀 Notion to WordPress Frontend App initializing...');

    // 初始化组件
    this.initializeComponents();

    // 绑定事件
    this.bindEvents();

    this.initialized = true;
    emit('frontend:initialized');

    console.log('✅ Notion to WordPress Frontend App initialized');
  }

  /**
   * 初始化组件
   */
  private initializeComponents(): void {
    // 初始化现代化前端内容渲染系统
    this.initializeFrontendContent();

    // 初始化Notion块渲染器
    this.initNotionBlocks();

    // 初始化懒加载
    this.initLazyLoading();

    // 初始化数学公式渲染
    this.initMathRendering();

    emit('frontend:components:init');
  }

  /**
   * 初始化Notion块
   */
  private initNotionBlocks(): void {
    const notionBlocks = document.querySelectorAll('.notion-block');
    console.log(`Found ${notionBlocks.length} Notion blocks`);

    notionBlocks.forEach(block => {
      // 这里将处理各种Notion块类型
      const blockType = block.getAttribute('data-block-type');
      emit('frontend:block:init', { block, blockType });
    });
  }

  /**
   * 初始化懒加载
   */
  private initLazyLoading(): void {
    if ('IntersectionObserver' in window) {
      const lazyImages = document.querySelectorAll('img[data-src]');
      
      if (lazyImages.length > 0) {
        const imageObserver = new IntersectionObserver((entries) => {
          entries.forEach(entry => {
            if (entry.isIntersecting) {
              const img = entry.target as HTMLImageElement;
              const src = img.getAttribute('data-src');
              
              if (src) {
                img.src = src;
                img.removeAttribute('data-src');
                imageObserver.unobserve(img);
                
                emit('frontend:image:loaded', { img, src });
              }
            }
          });
        });

        lazyImages.forEach(img => imageObserver.observe(img));
        console.log(`Initialized lazy loading for ${lazyImages.length} images`);
      }
    }
  }

  /**
   * 初始化数学公式渲染
   */
  private initMathRendering(): void {
    const mathElements = document.querySelectorAll('.notion-equation');
    
    if (mathElements.length > 0) {
      console.log(`Found ${mathElements.length} math equations`);
      
      // 动态加载KaTeX（如果需要）
      this.loadMathRenderer().then(() => {
        mathElements.forEach(element => {
          emit('frontend:math:render', { element });
        });
      });
    }
  }

  /**
   * 动态加载数学渲染器
   */
  private async loadMathRenderer(): Promise<void> {
    // 这里将动态加载KaTeX或其他数学渲染库
    return new Promise((resolve) => {
      // 模拟异步加载
      setTimeout(resolve, 100);
    });
  }

  /**
   * 初始化前端内容渲染系统
   */
  private initializeFrontendContent(): void {
    // 初始化现代化的前端内容渲染系统
    frontendContent.init();

    // 监听前端内容系统事件
    eventBus.on('frontend:content:initialized', () => {
      console.log('✅ 前端内容渲染系统已初始化');
    });

    eventBus.on('frontend:content:destroyed', () => {
      console.log('🔥 前端内容渲染系统已销毁');
    });

    // 监听性能报告事件
    eventBus.on('frontend:performance:report', (_event, metrics) => {
      console.log('📊 前端性能报告:', metrics);
    });

    console.log('✅ 前端内容渲染系统已初始化');
  }

  /**
   * 绑定事件
   */
  private bindEvents(): void {
    // 监听页面滚动
    let scrollTimeout: NodeJS.Timeout;
    window.addEventListener('scroll', () => {
      clearTimeout(scrollTimeout);
      scrollTimeout = setTimeout(() => {
        emit('frontend:scroll', {
          scrollY: window.scrollY,
          scrollX: window.scrollX
        });
      }, 100);
    });

    // 监听窗口大小变化
    window.addEventListener('resize', () => {
      emit('frontend:resize', {
        width: window.innerWidth,
        height: window.innerHeight
      });
    });
  }

  /**
   * 销毁应用
   */
  destroy(): void {
    if (!this.initialized) {
      return;
    }

    // 清理前端内容渲染系统
    frontendContent.destroy();

    emit('frontend:destroy');
    eventBus.removeAllListeners();
    this.initialized = false;

    console.log('🔥 Notion to WordPress Frontend App destroyed');
  }
}

/**
 * 创建全局应用实例
 */
const frontendApp = new FrontendApp();

/**
 * 导出到全局作用域
 */
declare global {
  interface Window {
    NotionWpFrontend: FrontendApp;
  }
}

window.NotionWpFrontend = frontendApp;

/**
 * DOM准备就绪后初始化
 */
ready(() => {
  frontendApp.init();
});

/**
 * 导出主要功能
 */
export { frontendApp };
export default frontendApp;
