/**
 * 处理Notion页面中的LaTeX数学公式和Mermaid图表
 *
 * @since      1.0.8
 * @package    Notion_To_WordPress
 */

(function($) {
    'use strict';
    
    // 初始化Mermaid
    function initMermaid() {
        if (typeof mermaid !== 'undefined') {
            console.log('初始化Mermaid图表渲染');
            
            // 配置Mermaid
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
        } else {
            console.warn('Mermaid库未加载');
        }
    }
    
    // 回退到老版本的Mermaid渲染方法
    function fallbackMermaidRendering() {
        try {
            console.log('尝试使用回退方法渲染Mermaid图表');
            
            // 查找所有包含Mermaid代码的pre标签
            document.querySelectorAll('pre.mermaid, pre code.language-mermaid').forEach(function(element) {
                var content = element.tagName === 'CODE' ? element.textContent : element.innerHTML;
                var div = document.createElement('div');
                div.className = 'mermaid';
                div.textContent = content.trim();
                
                // 替换pre或code标签
                if (element.tagName === 'CODE') {
                    element.parentNode.parentNode.replaceChild(div, element.parentNode);
                } else {
                    element.parentNode.replaceChild(div, element);
                }
            });
            
            // 尝试旧版本渲染方法
            if (typeof mermaid.init === 'function') {
                mermaid.init(undefined, document.querySelectorAll('.mermaid'));
                console.log('使用mermaid.init()方法渲染完成');
            }
        } catch (fallbackError) {
            console.error('Mermaid回退渲染错误:', fallbackError);
        }
    }
    
    // 处理Notion中的化学方程式（ce{}格式）
    function processChemicalEquations() {
        // 查找包含ce{...}格式的元素
        $('p, li, td, div').each(function() {
            var $element = $(this);
            var html = $element.html();

            if (html && html.indexOf('ce{') !== -1) {
                // 将ce{...}替换为\ce{...}以符合LaTeX语法
                html = html.replace(/ce\{([^}]+)\}/g, '\\ce{$1}');
                $element.html(html);
                console.log('处理了化学方程式');
            }
        });
    }
    
    // 处理Notion中的数学公式脚本标签
    function processMathScriptTags() {
        // 查找所有script类型为math/tex的标签
        $('script[type="math/tex"], script[type="math/tex; mode=display"]').each(function() {
            var $script = $(this);
            var content = $script.text().trim();

            if (content) {
                // 创建新的元素
                var $newElement = $('<span></span>');
                var isDisplay = $script.attr('type') === 'math/tex; mode=display';

                if (isDisplay) {
                    // 块级公式
                    $newElement.addClass('notion-equation-block').html('\\[' + content + '\\]');
                } else {
                    // 行内公式
                    $newElement.addClass('notion-equation-inline').html('\\(' + content + '\\)');
                }

                // 替换原始标签
                $script.replaceWith($newElement);
                console.log('处理了数学公式脚本标签');
            }
        });
    }
    
    // 处理Notion中的方程块
    function processNotionEquations() {
        // 查找Notion方程块
        $('.notion-equation-block').each(function() {
            var $equation = $(this);
            var content = $equation.text().trim();

            if (content && typeof katex !== 'undefined') {
                try {
                    // 提取LaTeX内容，去除包裹符号
                    var latex = content.replace(/^\\\[/, '').replace(/\\\]$/, '');
                    
                    // 不需要额外转义，让KaTeX原样处理
                    // 之前的错误：content中的"\"已被HTML转义，再次转义会导致问题
                    katex.render(latex, $equation[0], {
                        displayMode: true,
                        throwOnError: false,
                        trust: true,
                        strict: false // 使用非严格模式，更宽容地处理语法
                    });
                    console.log('KaTeX渲染了块级公式');
                } catch (e) {
                    console.error('KaTeX块级公式渲染错误:', e);
                    // 保留原始内容，在渲染失败时显示原始LaTeX
                    $equation.html('<div class="katex-error">' + content + '<br>渲染错误: ' + e.message + '</div>');
                }
            }
        });

        // 处理行内公式
        $('.notion-equation-inline').each(function() {
            var $equation = $(this);
            var content = $equation.text().trim();

            if (content && typeof katex !== 'undefined') {
                try {
                    // 提取LaTeX内容，去除包裹符号
                    var latex = content.replace(/^\\\(/, '').replace(/\\\)$/, '');
                    
                    // 不再进行额外的转义
                    katex.render(latex, $equation[0], {
                        displayMode: false,
                        throwOnError: false,
                        trust: true,
                        strict: false // 使用非严格模式
                    });
                    console.log('KaTeX渲染了行内公式');
                } catch (e) {
                    console.error('KaTeX行内公式渲染错误:', e);
                    $equation.html('<span class="katex-error">' + content + '</span>');
                }
            }
        });
    }
    
    // 检查KaTeX是否已加载
    function checkKaTeXLoaded() {
        if (typeof katex === 'undefined') {
            console.warn('KaTeX未加载，等待加载...');
            setTimeout(checkKaTeXLoaded, 500);
            return;
        }

        console.log('KaTeX已加载，版本:', katex.version || 'unknown');

        // KaTeX已加载，可以处理公式
        processChemicalEquations();
        processMathScriptTags();
        processNotionEquations();

        console.log('KaTeX 公式处理完成');
    }
    
    // 文档加载完成后初始化
    $(document).ready(function() {
        console.log('Notion to WordPress: 初始化数学公式和图表渲染');

        // 检查KaTeX是否已加载
        checkKaTeXLoaded();

        // 延迟初始化Mermaid图表，确保KaTeX不会干扰它
        setTimeout(function() {
            initMermaid();
        }, 1000);
    });
})(jQuery); 
