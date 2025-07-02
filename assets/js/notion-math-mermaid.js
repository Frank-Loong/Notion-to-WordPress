/**
 * 处理Notion页面中的LaTeX数学公式和Mermaid图表
 *
 * @since      1.0.8
 * @package    Notion_To_WordPress
 */

(function($) {
    'use strict';
    
    /* ---------------- KaTeX 渲染 ---------------- */
    const katexOptions = { throwOnError: false };

    // 渲染单个元素
    function renderKatexElement(el) {
        const isBlock = el.classList.contains('notion-equation-block');
        let tex = el.textContent.trim();

        // 去除包围符号 $ 或 $$
        if (isBlock) {
            tex = tex.replace(/^\$\$|\$\$$/g, '').replace(/\$\$$/, '');
        } else {
            tex = tex.replace(/^\$/, '').replace(/\$$/, '');
        }

        try {
            katex.render(tex, el, { displayMode: isBlock, ...katexOptions });
        } catch (e) {
            console.error('KaTeX 渲染错误:', e, '公式:', tex);
        }
    }

    // 遍历并渲染页面中所有公式
    function renderAllKatex() {
        // 预处理化学公式 ce{..} => \ce{..}
        $('.notion-equation-inline, .notion-equation-block').each(function () {
            let html = $(this).html();
            if (html.indexOf('ce{') !== -1) {
                html = html.replace(/ce\{([^}]+)\}/g, '\\ce{$1}');
                $(this).html(html);
            }
        });

        document.querySelectorAll('.notion-equation-inline, .notion-equation-block').forEach(renderKatexElement);
    }

    /* ---------------- Mermaid 渲染 ---------------- */
    function initMermaid() {
        if (typeof mermaid === 'undefined') {
            console.warn('Mermaid 库未加载');
            return;
        }

        mermaid.initialize({
            startOnLoad: false,
            theme: 'default',
            securityLevel: 'loose'
        });

        const selector = '.mermaid, pre.mermaid, pre code.language-mermaid';

        // 将 <pre><code> 转换为 div.mermaid
        document.querySelectorAll('pre.mermaid, pre code.language-mermaid').forEach(element => {
            const content = element.tagName === 'CODE' ? element.textContent : element.innerHTML;
            const div = document.createElement('div');
            div.className = 'mermaid';
            div.textContent = content.trim();
            if (element.tagName === 'CODE') {
                element.parentNode.parentNode.replaceChild(div, element.parentNode);
            } else {
                element.parentNode.replaceChild(div, element);
            }
        });

        // 渲染
        try {
            if (typeof mermaid.run === 'function') {
                mermaid.run({ querySelector: '.mermaid' });
            } else if (typeof mermaid.init === 'function') {
                mermaid.init(undefined, document.querySelectorAll('.mermaid'));
            }
        } catch (e) {
            console.error('Mermaid 渲染失败:', e);
        }
    }

    /* ---------------- 初始化 ---------------- */
    $(function () {
        // KaTeX 已作为依赖加载，直接渲染
        renderAllKatex();

        // Mermaid 延迟初始化，避免与渲染冲突
        setTimeout(initMermaid, 500);
    });

})(jQuery); 
