/**
 * 数学公式和图表渲染器 - 完整功能迁移版本
 *
 * 从原有katex-mermaid.js完全迁移所有功能，包括：
 * - KaTeX数学公式渲染（支持mhchem化学公式）
 * - Mermaid图表渲染
 * - 资源加载失败备用方案
 * - 智能兼容性检查
 * - 本地资源回退机制
 */

import { eventBus } from '../../shared/core/EventBus';

// KaTeX配置选项
const KATEX_OPTIONS = {
  displayMode: false,
  throwOnError: false,
  errorColor: '#cc0000',
  strict: 'warn',
  trust: false,
  macros: {
    '\\f': '#1f(#2)',
  },
};

// Mermaid配置选项
const MERMAID_CONFIG = {
  startOnLoad: false,
  theme: 'default',
  securityLevel: 'loose',
  fontFamily: 'Arial, sans-serif',
  fontSize: 14,
  flowchart: {
    useMaxWidth: true,
    htmlLabels: true,
  },
  sequence: {
    useMaxWidth: true,
    wrap: true,
  },
};

/**
 * 资源回退管理器
 */
class ResourceFallbackManager {
  /**
   * 显示主题兼容性检查建议
   */
  static showCompatibilityTips(): void {
    console.group('🔧 [Notion to WordPress] 主题兼容性检查建议');
    console.info('如果数学公式或图表显示异常，请尝试以下解决方案：');
    console.info('1. 确认当前主题正确调用了wp_footer()函数');
    console.info('2. 检查主题是否与其他插件存在JavaScript冲突');
    console.info('3. 尝试切换到WordPress默认主题（如Twenty Twenty-Three）测试');
    console.info('4. 检查浏览器控制台是否有其他错误信息');
    console.info('5. 确认网络连接正常，CDN资源可以正常访问');
    console.groupEnd();
  }

  /**
   * 动态加载CSS文件
   */
  static loadFallbackCSS(localPath: string): Promise<void> {
    return new Promise((resolve, reject) => {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.type = 'text/css';
      link.href = localPath;

      link.onload = () => {
        console.log('✅ 备用CSS加载成功:', localPath);
        resolve();
      };

      link.onerror = () => {
        console.error('❌ 备用CSS加载失败:', localPath);
        reject(new Error('CSS加载失败'));
      };

      document.head.appendChild(link);
    });
  }

  /**
   * 动态加载JS文件
   */
  static loadFallbackJS(localPath: string): Promise<void> {
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.type = 'text/javascript';
      script.src = localPath;

      script.onload = () => {
        console.log('✅ 备用JS加载成功:', localPath);
        resolve();
      };

      script.onerror = () => {
        console.error('❌ 备用JS加载失败:', localPath);
        reject(new Error('JS加载失败'));
      };

      document.head.appendChild(script);
    });
  }

  /**
   * 按顺序加载KaTeX相关文件
   */
  static async loadKatexFallback(): Promise<void> {
    const basePath =
      window.location.origin +
      '/wp-content/plugins/notion-to-wordpress/assets/vendor/katex/';

    console.info('📦 [Notion to WordPress] 开始加载KaTeX本地备用资源...');

    try {
      // 1. 先加载CSS
      await this.loadFallbackCSS(basePath + 'katex.min.css');

      // 2. 加载KaTeX核心JS
      await this.loadFallbackJS(basePath + 'katex.min.js');

      // 3. 加载mhchem扩展
      await this.loadFallbackJS(basePath + 'mhchem.min.js');

      console.log('✅ [Notion to WordPress] KaTeX本地资源加载完成');
    } catch (error) {
      console.error('❌ [Notion to WordPress] KaTeX本地资源加载失败:', error);
      throw error;
    }
  }

  /**
   * 加载Mermaid备用资源
   */
  static async loadMermaidFallback(): Promise<void> {
    const basePath =
      window.location.origin +
      '/wp-content/plugins/notion-to-wordpress/assets/vendor/mermaid/';

    console.info('📦 [Notion to WordPress] 开始加载Mermaid本地备用资源...');

    try {
      await this.loadFallbackJS(basePath + 'mermaid.min.js');
      console.log('✅ [Notion to WordPress] Mermaid本地资源加载完成');
    } catch (error) {
      console.error('❌ [Notion to WordPress] Mermaid本地资源加载失败:', error);
      throw error;
    }
  }
}

export class MathRenderer {
  private static instance: MathRenderer | null = null;
  private katexLoaded = false;
  private mermaidLoaded = false;
  private katexLoadPromise: Promise<void> | null = null;
  private mermaidLoadPromise: Promise<void> | null = null;

  constructor() {
    if (MathRenderer.instance) {
      return MathRenderer.instance;
    }
    MathRenderer.instance = this;
    this.init();
  }

  private init(): void {
    // 监听数学公式渲染事件
    eventBus.on('frontend:math:render', this.renderMath.bind(this));
    eventBus.on('frontend:mermaid:render', this.renderMermaid.bind(this));

    // 页面加载完成后自动检测和渲染
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => {
        this.detectAndRender();
      });
    } else {
      this.detectAndRender();
    }

    console.log('🧮 [数学渲染器] 已初始化');
  }

  /**
   * 检测并渲染页面中的数学公式和图表
   */
  private detectAndRender(): void {
    // 检测KaTeX公式 - 支持多种选择器
    const mathSelectors = [
      '.notion-equation',
      '.katex-math',
      '.math-expression',
      '[data-math]',
      '.wp-block-notion-math',
    ];

    const mathElements = document.querySelectorAll(mathSelectors.join(', '));
    if (mathElements.length > 0) {
      console.log(`🧮 发现 ${mathElements.length} 个数学公式元素`);
      this.loadKaTeX()
        .then(() => {
          mathElements.forEach(element => {
            this.renderMathElement(element as HTMLElement);
          });
        })
        .catch(error => {
          console.error('KaTeX加载失败:', error);
          ResourceFallbackManager.showCompatibilityTips();
        });
    }

    // 检测Mermaid图表 - 支持多种选择器
    const mermaidSelectors = [
      '.notion-mermaid',
      '.mermaid-chart',
      '.diagram',
      '[data-mermaid]',
      '.wp-block-notion-mermaid',
    ];

    const mermaidElements = document.querySelectorAll(
      mermaidSelectors.join(', ')
    );
    if (mermaidElements.length > 0) {
      console.log(`📊 发现 ${mermaidElements.length} 个图表元素`);
      this.loadMermaid()
        .then(() => {
          mermaidElements.forEach(element => {
            this.renderMermaidElement(element as HTMLElement);
          });
        })
        .catch(error => {
          console.error('Mermaid加载失败:', error);
          ResourceFallbackManager.showCompatibilityTips();
        });
    }

    // 检测化学公式
    const chemElements = document.querySelectorAll(
      '.notion-chemistry, .chemistry, [data-chemistry]'
    );
    if (chemElements.length > 0) {
      console.log(`🧪 发现 ${chemElements.length} 个化学公式元素`);
      this.loadKaTeX()
        .then(() => {
          chemElements.forEach(element => {
            this.renderChemistryElement(element as HTMLElement);
          });
        })
        .catch(error => {
          console.error('化学公式渲染失败:', error);
        });
    }
  }

  /**
   * 加载KaTeX库（支持CDN和本地回退）
   */
  private async loadKaTeX(): Promise<void> {
    // 如果已经加载或正在加载，返回现有Promise
    if (this.katexLoaded) return;
    if (this.katexLoadPromise) return this.katexLoadPromise;

    // 检查是否已经存在KaTeX
    if ((window as any).katex) {
      this.katexLoaded = true;
      return;
    }

    console.log('📦 [KaTeX] 开始加载KaTeX资源...');

    this.katexLoadPromise = this.performKatexLoad();
    return this.katexLoadPromise;
  }

  /**
   * 执行KaTeX加载
   */
  private async performKatexLoad(): Promise<void> {
    try {
      // 首先尝试CDN加载
      await this.loadKatexFromCDN();
      console.log('✅ [KaTeX] CDN资源加载成功');
    } catch (cdnError) {
      console.warn('⚠️ [KaTeX] CDN加载失败，尝试本地资源:', cdnError);

      try {
        // CDN失败时使用本地资源
        await ResourceFallbackManager.loadKatexFallback();
        console.log('✅ [KaTeX] 本地资源加载成功');
      } catch (localError) {
        console.error('❌ [KaTeX] 本地资源也加载失败:', localError);
        ResourceFallbackManager.showCompatibilityTips();
        throw new Error('KaTeX加载完全失败');
      }
    }

    // 验证KaTeX是否可用
    if (!(window as any).katex) {
      throw new Error('KaTeX加载后仍不可用');
    }

    this.katexLoaded = true;
    console.log('🎉 [KaTeX] 加载完成并可用');
  }

  /**
   * 从CDN加载KaTeX
   */
  private async loadKatexFromCDN(): Promise<void> {
    const CDN_BASE = 'https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/';

    // 1. 加载CSS
    const cssPromise = new Promise<void>((resolve, reject) => {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = CDN_BASE + 'katex.min.css';
      link.onload = () => resolve();
      link.onerror = () => reject(new Error('KaTeX CSS加载失败'));
      document.head.appendChild(link);
    });

    // 2. 加载主JS
    const jsPromise = new Promise<void>((resolve, reject) => {
      const script = document.createElement('script');
      script.src = CDN_BASE + 'katex.min.js';
      script.onload = () => resolve();
      script.onerror = () => reject(new Error('KaTeX JS加载失败'));
      document.head.appendChild(script);
    });

    // 等待CSS和JS都加载完成
    await Promise.all([cssPromise, jsPromise]);

    // 3. 加载mhchem扩展（化学公式支持）
    const mhchemPromise = new Promise<void>(resolve => {
      const script = document.createElement('script');
      script.src =
        'https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/contrib/mhchem.min.js';
      script.onload = () => resolve();
      script.onerror = () => {
        console.warn('mhchem扩展加载失败，化学公式功能可能不可用');
        resolve(); // 不阻塞主要功能
      };
      document.head.appendChild(script);
    });

    await mhchemPromise;
  }

  /**
   * 加载Mermaid库（支持CDN和本地回退）
   */
  private async loadMermaid(): Promise<void> {
    // 如果已经加载或正在加载，返回现有Promise
    if (this.mermaidLoaded) return;
    if (this.mermaidLoadPromise) return this.mermaidLoadPromise;

    // 检查是否已经存在Mermaid
    if ((window as any).mermaid) {
      this.mermaidLoaded = true;
      return;
    }

    console.log('📊 [Mermaid] 开始加载Mermaid资源...');

    this.mermaidLoadPromise = this.performMermaidLoad();
    return this.mermaidLoadPromise;
  }

  /**
   * 执行Mermaid加载
   */
  private async performMermaidLoad(): Promise<void> {
    try {
      // 首先尝试CDN加载
      await this.loadMermaidFromCDN();
      console.log('✅ [Mermaid] CDN资源加载成功');
    } catch (cdnError) {
      console.warn('⚠️ [Mermaid] CDN加载失败，尝试本地资源:', cdnError);

      try {
        // CDN失败时使用本地资源
        await ResourceFallbackManager.loadMermaidFallback();
        console.log('✅ [Mermaid] 本地资源加载成功');
      } catch (localError) {
        console.error('❌ [Mermaid] 本地资源也加载失败:', localError);
        ResourceFallbackManager.showCompatibilityTips();
        throw new Error('Mermaid加载完全失败');
      }
    }

    // 验证Mermaid是否可用
    if (!(window as any).mermaid) {
      throw new Error('Mermaid加载后仍不可用');
    }

    // 初始化Mermaid配置
    (window as any).mermaid.initialize(MERMAID_CONFIG);

    this.mermaidLoaded = true;
    console.log('🎉 [Mermaid] 加载完成并可用');
  }

  /**
   * 从CDN加载Mermaid
   */
  private async loadMermaidFromCDN(): Promise<void> {
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src =
        'https://cdn.jsdelivr.net/npm/mermaid@10.6.1/dist/mermaid.min.js';
      script.onload = () => resolve();
      script.onerror = () => reject(new Error('Mermaid CDN加载失败'));
      document.head.appendChild(script);
    });
  }

  /**
   * 渲染数学公式（事件处理器）
   */
  private renderMath(_event: any, data: { element: HTMLElement }): void {
    this.renderMathElement(data.element);
  }

  /**
   * 渲染图表（事件处理器）
   */
  private renderMermaid(_event: any, data: { element: HTMLElement }): void {
    this.renderMermaidElement(data.element);
  }

  /**
   * 渲染单个数学公式元素
   */
  private renderMathElement(element: HTMLElement): void {
    if (!this.katexLoaded || !(window as any).katex) {
      console.warn('KaTeX未加载，无法渲染数学公式');
      return;
    }

    // Rendering can be requested by more than one frontend lifecycle event.
    // Re-reading KaTeX's generated MathML/HTML on a later pass corrupts the
    // source expression, especially for posts imported before data-expression
    // was introduced.
    if (
      element.classList.contains('katex-rendered') ||
      element.getAttribute('data-rendered') === 'true'
    ) {
      return;
    }

    // 获取数学表达式
    const expression =
      element.getAttribute('data-expression') ||
      element.getAttribute('data-math') ||
      element.textContent ||
      element.innerHTML;

    if (!expression || expression.trim() === '') {
      console.warn('数学表达式为空，跳过渲染');
      return;
    }

    try {
      const rawExpression = expression.trim();
      const isDisplayDelimited =
        rawExpression.startsWith('$$') && rawExpression.endsWith('$$');
      const isInlineDelimited =
        !isDisplayDelimited &&
        rawExpression.startsWith('$') &&
        rawExpression.endsWith('$');
      const normalizedExpression = isDisplayDelimited
        ? rawExpression.slice(2, -2).trim()
        : isInlineDelimited
          ? rawExpression.slice(1, -1).trim()
          : rawExpression;

      // Notion source output includes TeX delimiters; KaTeX.render expects the
      // expression alone. Keep compatibility with hand-authored markup too.
      const isInline =
        element.classList.contains('inline') ||
        element.classList.contains('notion-equation-inline') ||
        element.classList.contains('katex-inline') ||
        element.hasAttribute('data-inline') ||
        isInlineDelimited;

      // 使用完整的KaTeX配置
      const options = {
        ...KATEX_OPTIONS,
        displayMode: isDisplayDelimited || !isInline,
        throwOnError: false,
        errorColor: '#cc0000',
        strict: 'warn',
      };

      // 渲染数学公式
      (window as any).katex.render(normalizedExpression, element, options);

      // 添加成功渲染的标记
      element.classList.add('katex-rendered');
      element.setAttribute('data-rendered', 'true');

      console.log('✅ 数学公式渲染成功:', expression.substring(0, 50) + '...');
    } catch (error) {
      console.error('❌ KaTeX渲染错误:', error);

      // 显示错误信息
      element.innerHTML = `
        <span style="color: #cc0000; background: #ffe6e6; padding: 2px 4px; border-radius: 3px; font-family: monospace;">
          数学公式错误: ${expression.substring(0, 100)}${expression.length > 100 ? '...' : ''}
        </span>
      `;
      element.classList.add('katex-error');
    }
  }

  /**
   * 渲染化学公式元素
   */
  private renderChemistryElement(element: HTMLElement): void {
    if (!this.katexLoaded || !(window as any).katex) {
      console.warn('KaTeX未加载，无法渲染化学公式');
      return;
    }

    // 获取化学表达式
    const expression =
      element.textContent ||
      element.getAttribute('data-chemistry') ||
      element.getAttribute('data-chem');

    if (!expression || expression.trim() === '') {
      console.warn('化学表达式为空，跳过渲染');
      return;
    }

    try {
      // 化学公式通常使用mhchem语法，需要包装在\ce{}中
      const chemExpression = expression.startsWith('\\ce{')
        ? expression
        : `\\ce{${expression}}`;

      const options = {
        ...KATEX_OPTIONS,
        displayMode: false,
        throwOnError: false,
      };

      (window as any).katex.render(chemExpression, element, options);

      element.classList.add('chemistry-rendered');
      element.setAttribute('data-rendered', 'true');

      console.log('✅ 化学公式渲染成功:', expression);
    } catch (error) {
      console.error('❌ 化学公式渲染错误:', error);

      element.innerHTML = `
        <span style="color: #cc0000; background: #ffe6e6; padding: 2px 4px; border-radius: 3px; font-family: monospace;">
          化学公式错误: ${expression}
        </span>
      `;
      element.classList.add('chemistry-error');
    }
  }

  /**
   * 渲染单个Mermaid图表元素
   */
  private renderMermaidElement(element: HTMLElement): void {
    if (!this.mermaidLoaded || !(window as any).mermaid) {
      console.warn('Mermaid未加载，无法渲染图表');
      return;
    }

    // 获取图表代码
    const diagram =
      element.textContent ||
      element.getAttribute('data-mermaid') ||
      element.getAttribute('data-code') ||
      element.getAttribute('data-diagram') ||
      element.innerHTML;

    if (!diagram || diagram.trim() === '') {
      console.warn('图表代码为空，跳过渲染');
      return;
    }

    try {
      // 生成唯一ID（使用现代方法替代已弃用的substr）
      const id = `mermaid-${Date.now()}-${Math.random().toString(36).substring(2, 11)}`;

      // 清空元素内容，显示加载状态
      element.innerHTML = '<div class="mermaid-loading">正在渲染图表...</div>';
      element.classList.add('mermaid-rendering');

      // 渲染图表
      (window as any).mermaid
        .render(id, diagram)
        .then((result: any) => {
          // 渲染成功
          element.innerHTML = result.svg;
          element.classList.remove('mermaid-rendering');
          element.classList.add('mermaid-rendered');
          element.setAttribute('data-rendered', 'true');

          // 添加响应式支持
          const svg = element.querySelector('svg');
          if (svg) {
            svg.style.maxWidth = '100%';
            svg.style.height = 'auto';
          }

          console.log('✅ Mermaid图表渲染成功');
        })
        .catch((error: any) => {
          // 渲染失败
          console.error('❌ Mermaid渲染错误:', error);

          element.innerHTML = `
          <div style="color: #cc0000; background: #ffe6e6; padding: 10px; border-radius: 5px; border: 1px solid #ffcccc;">
            <strong>图表渲染错误</strong><br>
            <small>${error.message || '未知错误'}</small><br>
            <details style="margin-top: 5px;">
              <summary style="cursor: pointer;">查看原始代码</summary>
              <pre style="background: #f5f5f5; padding: 5px; margin-top: 5px; border-radius: 3px; font-size: 12px;">${diagram}</pre>
            </details>
          </div>
        `;
          element.classList.remove('mermaid-rendering');
          element.classList.add('mermaid-error');
        });
    } catch (error) {
      console.error('❌ Mermaid渲染异常:', error);

      element.innerHTML = `
        <div style="color: #cc0000; background: #ffe6e6; padding: 10px; border-radius: 5px; border: 1px solid #ffcccc;">
          <strong>图表渲染异常</strong><br>
          <small>请检查图表语法是否正确</small>
        </div>
      `;
      element.classList.add('mermaid-error');
    }
  }

  /**
   * 手动渲染指定元素
   */
  public renderElement(element: HTMLElement): void {
    if (
      element.classList.contains('notion-equation') ||
      element.classList.contains('katex-math') ||
      element.hasAttribute('data-math')
    ) {
      this.loadKaTeX()
        .then(() => {
          this.renderMathElement(element);
        })
        .catch(console.error);
    } else if (
      element.classList.contains('notion-mermaid') ||
      element.classList.contains('mermaid-chart') ||
      element.hasAttribute('data-mermaid')
    ) {
      this.loadMermaid()
        .then(() => {
          this.renderMermaidElement(element);
        })
        .catch(console.error);
    } else if (
      element.classList.contains('notion-chemistry') ||
      element.classList.contains('chemistry') ||
      element.hasAttribute('data-chemistry')
    ) {
      this.loadKaTeX()
        .then(() => {
          this.renderChemistryElement(element);
        })
        .catch(console.error);
    }
  }

  /**
   * 重新渲染所有元素
   */
  public reRenderAll(): void {
    console.log('🔄 重新渲染所有数学公式和图表...');
    this.detectAndRender();
  }

  /**
   * 获取渲染状态
   */
  public getStatus(): {
    katexLoaded: boolean;
    mermaidLoaded: boolean;
    mathElements: number;
    mermaidElements: number;
    chemElements: number;
  } {
    return {
      katexLoaded: this.katexLoaded,
      mermaidLoaded: this.mermaidLoaded,
      mathElements: document.querySelectorAll(
        '.notion-equation, .katex-math, [data-math]'
      ).length,
      mermaidElements: document.querySelectorAll(
        '.notion-mermaid, .mermaid-chart, [data-mermaid]'
      ).length,
      chemElements: document.querySelectorAll(
        '.notion-chemistry, .chemistry, [data-chemistry]'
      ).length,
    };
  }

  /**
   * 销毁实例
   */
  public destroy(): void {
    eventBus.off('frontend:math:render', this.renderMath.bind(this));
    eventBus.off('frontend:mermaid:render', this.renderMermaid.bind(this));

    MathRenderer.instance = null;
    console.log('🧮 [数学渲染器] 已销毁');
  }

  /**
   * 获取单例实例
   */
  public static getInstance(): MathRenderer {
    if (!MathRenderer.instance) {
      MathRenderer.instance = new MathRenderer();
    }
    return MathRenderer.instance;
  }
}

// 导出单例实例
export const mathRenderer = new MathRenderer();

// 自动初始化（兼容原有行为）
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    MathRenderer.getInstance();
  });
} else {
  MathRenderer.getInstance();
}

export default MathRenderer;
