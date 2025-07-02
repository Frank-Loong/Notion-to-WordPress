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

        // 先初始化全局配置，保持与 NotionNext 一致
        mermaid.initialize({
            startOnLoad: false,
            theme: 'default',
            securityLevel: 'loose',
            flowchart: { useMaxWidth: true, htmlLabels: true },
            er: { useMaxWidth: true },
            sequence: { useMaxWidth: true, noteFontWeight: '14px', actorFontSize: '14px', messageFontSize: '16px' }
        });

        // 等待 DOM 稳定后再查找 mermaid 代码块
        setTimeout(() => {
            try {
                // 将 <pre><code> 形式的 mermaid 图表替换为 div.mermaid
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

                const mermaidElements = document.querySelectorAll('.mermaid');
                if (mermaidElements.length === 0) {
                    console.log('未找到 Mermaid 图表');
                    return;
                }

                // 使用 mermaid 10 的 run API；若不存在则回退
                if (typeof mermaid.run === 'function') {
                    mermaid.run({ querySelector: '.mermaid' }).then(() => {
                        console.log('Mermaid 图表渲染成功');
                    }).catch(err => {
                        console.error('Mermaid run 错误:', err);
                        fallbackMermaidRendering();
                    });
                } else {
                    fallbackMermaidRendering();
                }
            } catch (e) {
                console.error('Mermaid 初始化错误:', e);
                fallbackMermaidRendering();
            }
        }, 800); // 延时稍长，保证 mermaid 脚本完全加载
    }

    // 回退方案：兼容旧版 mermaid.init()
    function fallbackMermaidRendering() {
        try {
            console.log('尝试 mermaid.init() 回退渲染');
            if (typeof mermaid.init === 'function') {
                mermaid.init(undefined, document.querySelectorAll('.mermaid'));
            }
        } catch (e) {
            console.error('Mermaid 回退渲染失败:', e);
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
