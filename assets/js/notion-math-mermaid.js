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
            console.warn('Mermaid库未加载');
            return;
        }

        console.log('初始化Mermaid图表渲染');

        mermaid.initialize({
            startOnLoad: false, // 手动控制加载
            theme: 'default',
            securityLevel: 'loose',
            flowchart: {
                useMaxWidth: true,
                htmlLabels: true
            },
            er: {
                useMaxWidth: true
            },
            sequence: {
                useMaxWidth: true,
                noteFontWeight: '14px',
                actorFontSize: '14px',
                messageFontSize: '16px'
            }
        });

        // 等待DOM完全加载后再处理
        setTimeout(function() {
            try {
                // 查找所有Mermaid图表容器
                var mermaidElements = document.querySelectorAll('.mermaid, pre.mermaid, pre code.language-mermaid');
                if (mermaidElements.length === 0) {
                    console.log('未找到Mermaid图表');
                    return;
                }

                console.log('找到 ' + mermaidElements.length + ' 个Mermaid图表');

                // 使用mermaid 10.x的新API
                if (typeof mermaid.run === 'function') {
                    mermaid.run({
                        querySelector: '.mermaid, pre.mermaid, pre code.language-mermaid'
                    }).then(function() {
                        console.log('Mermaid图表渲染成功');
                    }).catch(function(error) {
                        console.error('Mermaid渲染错误:', error);
                        fallbackMermaidRendering();
                    });
                } else {
                    // 回退到老版本API
                    fallbackMermaidRendering();
                }
            } catch (e) {
                console.error('Mermaid初始化错误:', e);
                fallbackMermaidRendering();
            }
        }, 500);
    }

    // 回退到老版本的Mermaid渲染方法
    function fallbackMermaidRendering() {
        try {
            console.log('尝试使用回退方法渲染Mermaid图表');

            document.querySelectorAll('pre.mermaid, pre code.language-mermaid').forEach(function(element) {
                var content = element.tagName === 'CODE' ? element.textContent : element.innerHTML;
                var div = document.createElement('div');
                div.className = 'mermaid';
                div.textContent = content.trim();

                if (element.tagName === 'CODE') {
                    element.parentNode.parentNode.replaceChild(div, element.parentNode);
                } else {
                    element.parentNode.replaceChild(div, element);
                }
            });

            if (typeof mermaid.init === 'function') {
                mermaid.init(undefined, document.querySelectorAll('.mermaid'));
                console.log('使用mermaid.init()方法渲染完成');
            }
        } catch (fallbackError) {
            console.error('Mermaid回退渲染错误:', fallbackError);
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
