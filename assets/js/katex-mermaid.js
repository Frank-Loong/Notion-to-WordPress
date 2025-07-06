/**
 * 处理Notion页面中的LaTeX数学公式和Mermaid图表
 *
 * @since      1.0.8
 * @package    Notion_To_WordPress
 */

(function($) {
'use strict';

/* ---------------- 资源加载检测 ---------------- */
// 检测KaTeX是否成功加载
function checkKatexLoaded() {
    return typeof window.katex !== 'undefined' &&
           typeof window.katex.render === 'function';
}

// 检测Mermaid是否成功加载
function checkMermaidLoaded() {
    return typeof window.mermaid !== 'undefined' &&
           typeof window.mermaid.initialize === 'function';
}

/* ---------------- 智能备用资源加载器 ---------------- */
const ResourceFallbackManager = {
    // 显示主题兼容性检查建议
    showCompatibilityTips: function() {
        console.group('🔧 [Notion to WordPress] 主题兼容性检查建议');
        console.info('如果数学公式或图表显示异常，请尝试以下解决方案：');
        console.info('1. 确认当前主题正确调用了wp_footer()函数');
        console.info('2. 检查主题是否与其他插件存在JavaScript冲突');
        console.info('3. 尝试切换到WordPress默认主题（如Twenty Twenty-Three）测试');
        console.info('4. 检查浏览器控制台是否有其他错误信息');
        console.info('5. 确认网络连接正常，CDN资源可以正常访问');
        console.groupEnd();
    },

    // 动态加载本地CSS文件
    loadFallbackCSS: function(localPath) {
        return new Promise(function(resolve, reject) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.type = 'text/css';
            link.href = localPath;

            link.onload = function() {
                console.log('✅ 备用CSS加载成功:', localPath);
                resolve();
            };

            link.onerror = function() {
                console.error('❌ 备用CSS加载失败:', localPath);
                reject(new Error('CSS加载失败'));
            };

            document.head.appendChild(link);
        });
    },

    // 动态加载本地JS文件
    loadFallbackJS: function(localPath, callback) {
        const script = document.createElement('script');
        script.type = 'text/javascript';
        script.src = localPath;

        script.onload = function() {
            console.log('✅ 备用JS加载成功:', localPath);
            if (callback) callback();
        };

        script.onerror = function() {
            console.error('❌ 备用JS加载失败:', localPath);
            if (callback) callback(new Error('JS加载失败'));
        };

        document.head.appendChild(script);
    },

    // 按顺序加载KaTeX相关文件
    loadKatexFallback: function() {
        const basePath = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '') + '/wp-content/plugins/notion-to-wordpress/assets/vendor/katex/';

        console.info('📦 [Notion to WordPress] 开始加载KaTeX本地备用资源...');

        // 1. 先加载CSS
        this.loadFallbackCSS(basePath + 'katex.min.css').then(() => {
            // 2. 加载KaTeX核心JS
            this.loadFallbackJS(basePath + 'katex.min.js', (error) => {
                if (error) return;

                // 3. 加载mhchem扩展
                this.loadFallbackJS(basePath + 'mhchem.min.js', (error) => {
                    if (error) return;

                    // 4. 加载auto-render扩展
                    this.loadFallbackJS(basePath + 'auto-render.min.js', (error) => {
                        if (error) return;

                        console.log('✅ [Notion to WordPress] KaTeX备用资源加载完成，重新尝试渲染数学公式');
                        // 重新尝试渲染
                        setTimeout(renderAllKatex, 100);
                    });
                });
            });
        }).catch((error) => {
            console.error('❌ [Notion to WordPress] KaTeX备用CSS加载失败:', error);
            console.error('🔍 故障排除建议：');
            console.error('   1. 检查插件文件是否完整：assets/vendor/katex/katex.min.css');
            console.error('   2. 确认WordPress主题正确调用了wp_footer()');
            console.error('   3. 检查是否有其他插件冲突');
            console.error('   4. 尝试切换到默认主题测试');
        });
    },

    // 加载Mermaid备用文件
    loadMermaidFallback: function() {
        const basePath = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '') + '/wp-content/plugins/notion-to-wordpress/assets/vendor/mermaid/';

        console.info('📦 [Notion to WordPress] 开始加载Mermaid本地备用资源...');

        this.loadFallbackJS(basePath + 'mermaid.min.js', (error) => {
            if (error) {
                console.error('❌ [Notion to WordPress] Mermaid备用资源加载失败:', error);
                console.error('🔍 故障排除建议：');
                console.error('   1. 检查插件文件是否完整：assets/vendor/mermaid/mermaid.min.js');
                console.error('   2. 确认WordPress主题正确调用了wp_footer()');
                console.error('   3. 检查是否有其他插件冲突');
                console.error('   4. 尝试切换到默认主题测试');
                return;
            }

            console.log('✅ [Notion to WordPress] Mermaid备用资源加载完成，重新尝试初始化图表渲染');
            // 重新尝试初始化
            setTimeout(initMermaid, 100);
        });
    }
};

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
	// 检测KaTeX是否成功加载
	if (!checkKatexLoaded()) {
		console.warn('🔧 [Notion to WordPress] KaTeX数学公式库未能从CDN加载');
		console.info('💡 可能原因：网络问题、CDN服务异常或主题兼容性问题');
		console.info('🔄 正在自动切换到本地备用资源...');
		ResourceFallbackManager.showCompatibilityTips();
		ResourceFallbackManager.loadKatexFallback();
		return;
	}

// 预处理化学公式 ce{..} => \ce{..}
$('.notion-equation-inline, .notion-equation-block').each(function () {
let html = $(this).html();
            if (html.indexOf('ce{') !== -1) {
                html = html.replace(/ce\{([^}]+)\}/g, '\\ce{$1}');
                $(this).html(html);
            }
            // 仅当 ce{ 前面不是反斜杠时才加上 \
            html = html.replace(/(^|[^\\])ce\{/g, function(match, p1){
                return p1 + '\\ce{';
            });
            $(this).html(html);
});

document.querySelectorAll('.notion-equation-inline, .notion-equation-block').forEach(renderKatexElement);
}
/* ---------------- Mermaid 渲染 ---------------- */
function initMermaid() {
	// 检测Mermaid是否成功加载
	if (!checkMermaidLoaded()) {
		console.warn('🔧 [Notion to WordPress] Mermaid图表库未能从CDN加载');
		console.info('💡 可能原因：网络问题、CDN服务异常或主题兼容性问题');
		console.info('🔄 正在自动切换到本地备用资源...');
		ResourceFallbackManager.showCompatibilityTips();
		ResourceFallbackManager.loadMermaidFallback();
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

