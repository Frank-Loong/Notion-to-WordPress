/**
 * KaTeX 和 Mermaid 渲染脚本
 * 
 * 负责渲染 Notion 页面中的 LaTeX 数学公式和 Mermaid.js 图表，并提供资源加载失败时的备用方案。
 * 
 * @since 1.0.8
 * @version 2.0.0-beta.1
 * @package Notion_To_WordPress
 * @author Frank-Loong
 * @license GPL-3.0-or-later
 * @link https://github.com/Frank-Loong/Notion-to-WordPress
 */

(function() {
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
        const basePath = window.location.origin + '/wp-content/plugins/notion-to-wordpress/assets/vendor/katex/';

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
        const basePath = window.location.origin + '/wp-content/plugins/notion-to-wordpress/assets/vendor/mermaid/';

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
const katexOptions = {
    throwOnError: false,    // 遇到错误时不抛出异常，而是显示错误信息
    strict: false,          // 🔓 宽松模式：允许Unicode字符和非标准LaTeX语法
    trust: true,            // 🔓 信任模式：允许HTML、CSS和URL等
    fleqn: false,           // 不强制左对齐（保持居中）
    colorIsTextColor: false, // 颜色不影响文本颜色
    macros: {},             // 自定义宏定义（可扩展）
    globalGroup: false,     // 不使用全局组（避免宏污染）
    maxSize: Infinity,      // 🔓 无限制字体大小
    maxExpand: 1000,        // 🔓 宏展开次数限制（宽松设置）
    errorColor: "#cc0000",  // 错误信息颜色
    output: "html"          // 输出HTML格式
};



// 渲染单个元素
function renderKatexElement(el) {
const isBlock = el.classList.contains('notion-equation-block');
// 回退到简单的textContent获取，避免复杂的HTML处理
let tex = el.textContent.trim();

// 去除包围符号 $ 或 $$
if (isBlock) {
tex = tex.replace(/^\$\$|\$\$$/g, '').replace(/\$\$$/, '');
} else {
tex = tex.replace(/^\$/, '').replace(/\$$/, '');
}

// 解码HTML实体，确保LaTeX符号正确（如 &amp; -> &）
tex = tex.replace(/&amp;/g, '&')
         .replace(/&lt;/g, '<')
         .replace(/&gt;/g, '>')
         .replace(/&quot;/g, '"')
         .replace(/&#039;/g, "'");

// 化学公式处理：如果包含ce{但没有\ce{，则添加反斜杠
if (tex.indexOf('ce{') !== -1 && tex.indexOf('\\ce{') === -1) {
tex = tex.replace(/ce\{([^}]+)\}/g, '\\ce{$1}');
// 仅当 ce{ 前面不是反斜杠时才加上 \
tex = tex.replace(/(^|[^\\])ce\{/g, function(match, p1){
return p1 + '\\ce{';
});
}

try {
katex.render(tex, el, { displayMode: isBlock, ...katexOptions });
} catch (e) {
console.error('KaTeX 渲染错误:', e, '公式:', tex);
// 显示错误信息而不是空白
el.innerHTML = '<span style="color: red; font-family: monospace;">公式渲染失败: ' + tex.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>';
}
}



// 遍历并渲染页面中所有公式
function renderAllKatex() {
	// 检测KaTeX是否成功加载，给CDN一些时间
	if (!checkKatexLoaded()) {
		console.warn('🔧 [Notion to WordPress] KaTeX数学公式库未能从CDN加载');
		console.info('💡 可能原因：网络问题、CDN服务异常或主题兼容性问题');
		console.info('🔄 等待2秒后重试，如仍失败将切换到本地备用资源...');

		// 等待2秒后重试，给CDN更多时间
		setTimeout(() => {
			if (!checkKatexLoaded()) {
				console.info('🔄 CDN仍未加载成功，正在切换到本地备用资源...');
				ResourceFallbackManager.showCompatibilityTips();
				ResourceFallbackManager.loadKatexFallback();
			} else {
				console.log('✅ [Notion to WordPress] KaTeX CDN资源延迟加载成功，继续正常渲染');
				renderAllKatex(); // 重新调用渲染
			}
		}, 2000);
		return;
	}

// 化学公式预处理已移至renderKatexElement函数中处理

document.querySelectorAll('.notion-equation-inline, .notion-equation-block').forEach(renderKatexElement);
}

// 暴露函数到全局作用域，供调试和测试使用
window.NotionToWordPressKaTeX = {
    renderAllKatex: renderAllKatex,
    renderKatexElement: renderKatexElement
};

// 暴露Mermaid函数到全局作用域
window.NotionToWordPressMermaid = {
    initMermaid: initMermaid,
    fallbackMermaidRendering: fallbackMermaidRendering,
    addPanZoomToMermaid: addPanZoomToMermaid
};
/* ---------------- Mermaid 渲染 ---------------- */
function initMermaid() {
	// 检测Mermaid是否成功加载，给CDN一些时间
	if (!checkMermaidLoaded()) {
		console.warn('🔧 [Notion to WordPress] Mermaid图表库未能从CDN加载');
		console.info('💡 可能原因：网络问题、CDN服务异常或主题兼容性问题');
		console.info('🔄 等待2秒后重试，如仍失败将切换到本地备用资源...');

		// 等待2秒后重试，给CDN更多时间
		setTimeout(() => {
			if (!checkMermaidLoaded()) {
				console.info('🔄 CDN仍未加载成功，正在切换到本地备用资源...');
				ResourceFallbackManager.showCompatibilityTips();
				ResourceFallbackManager.loadMermaidFallback();
			} else {
				console.log('✅ [Notion to WordPress] Mermaid CDN资源延迟加载成功，继续正常初始化');
				initMermaid(); // 重新调用初始化
			}
		}, 2000);
		return;
	}

console.log('初始化Mermaid图表渲染');

mermaid.initialize({
startOnLoad: false, // 手动控制加载
theme: 'default',
securityLevel: 'loose',
flowchart: {
useMaxWidth: false, // 修复：不强制使用最大宽度，让图表保持合适大小
htmlLabels: true,
curve: 'basis'
},
er: {
useMaxWidth: false // 修复：不强制使用最大宽度
},
sequence: {
useMaxWidth: false, // 修复：不强制使用最大宽度
noteFontWeight: '14px',
actorFontSize: '14px',
messageFontSize: '16px'
},
// 添加全局配置确保图表大小合适
maxTextSize: 90000,
maxEdges: 100
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
// 渲染完成后添加缩放和平移功能
setTimeout(addPanZoomToMermaid, 100);
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

// 增强的选择器，确保捕获所有可能的Mermaid代码块
document.querySelectorAll('pre.mermaid, pre code.language-mermaid, code.language-mermaid, pre.language-mermaid').forEach(function(element) {
var content = element.tagName === 'CODE' ? element.textContent : element.innerHTML;
var div = document.createElement('div');
div.className = 'mermaid';
div.textContent = content.trim();

// 增强的替换逻辑，处理各种嵌套情况
if (element.tagName === 'CODE') {
// 如果是 code 标签，替换其父级 pre 标签
var preParent = element.parentNode;
if (preParent && preParent.tagName === 'PRE') {
preParent.parentNode.replaceChild(div, preParent);
} else {
element.parentNode.replaceChild(div, element);
}
} else if (element.tagName === 'PRE') {
// 如果是 pre 标签，直接替换
element.parentNode.replaceChild(div, element);
}

console.log('转换Mermaid代码块:', content.substring(0, 50) + '...');
});

// 强制重新扫描所有可能遗漏的代码块
setTimeout(function() {
document.querySelectorAll('pre, code').forEach(function(element) {
if (!element.classList.contains('mermaid') && !element.querySelector('.mermaid')) {
var content = element.textContent || element.innerHTML;
// 检查是否包含Mermaid关键词
if (content.includes('graph') || content.includes('flowchart') || content.includes('sequenceDiagram') || content.includes('classDiagram') || content.includes('gantt') || content.includes('pie')) {
// 确保这确实是Mermaid代码而不是普通文本
if (content.trim().match(/^(graph|flowchart|sequenceDiagram|classDiagram|gantt|pie|gitgraph)/)) {
var div = document.createElement('div');
div.className = 'mermaid';
div.textContent = content.trim();
element.parentNode.replaceChild(div, element);
console.log('发现并转换遗漏的Mermaid内容:', content.substring(0, 50) + '...');
}
}
}
});

if (typeof mermaid.init === 'function') {
mermaid.init(undefined, document.querySelectorAll('.mermaid'));
console.log('使用mermaid.init()方法渲染完成');
// 渲染完成后添加缩放和平移功能
setTimeout(addPanZoomToMermaid, 100);
}
}, 100);

} catch (fallbackError) {
console.error('Mermaid回退渲染错误:', fallbackError);
}
}

/* ---------------- Mermaid 现代化缩放和控制功能 ---------------- */
function addPanZoomToMermaid() {
    console.log('🎨 开始为Mermaid图表添加现代化控制功能');

    document.querySelectorAll('.mermaid').forEach(function(container, index) {
        const svg = container.querySelector('svg');
        if (!svg || svg.dataset.panZoomEnabled) {
            return; // 跳过已经处理过的SVG
        }

        // 标记为已处理
        svg.dataset.panZoomEnabled = 'true';

        // 创建现代化控制按钮容器
        const controlsContainer = document.createElement('div');
        controlsContainer.className = 'mermaid-controls';
        controlsContainer.innerHTML = `
            <div class="mermaid-zoom-controls">
                <button class="mermaid-btn zoom-in" title="放大 (Ctrl + +)" aria-label="放大">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/>
                    </svg>
                </button>
                <button class="mermaid-btn zoom-out" title="缩小 (Ctrl + -)" aria-label="缩小">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M4 8a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 0 1h-7A.5.5 0 0 1 4 8z"/>
                    </svg>
                </button>
                <button class="mermaid-btn zoom-reset" title="重置缩放 (Ctrl + 0)" aria-label="重置缩放">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/>
                        <path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/>
                    </svg>
                </button>
                <button class="mermaid-btn zoom-fit" title="适应窗口" aria-label="适应窗口">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/>
                    </svg>
                </button>
                <button class="mermaid-btn copy-code" title="复制源代码 (Ctrl + C)" aria-label="复制源代码">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"/>
                        <path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5h3zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3z"/>
                    </svg>
                </button>
                <button class="mermaid-btn fullscreen" title="全屏查看 (F)" aria-label="全屏查看">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/>
                    </svg>
                </button>
            </div>
        `;

        // 将控制按钮插入到容器中
        container.appendChild(controlsContainer);

        // 初始化缩放和平移状态
        let scale = 1;
        let translateX = 0;
        let translateY = 0;
        let isDragging = false;
        let lastMouseX = 0;
        let lastMouseY = 0;

        // 获取SVG的原始尺寸和容器信息
        const originalViewBox = svg.getAttribute('viewBox');
        const containerRect = container.getBoundingClientRect();
        const svgRect = svg.getBoundingClientRect();
        const originalWidth = svgRect.width;
        const originalHeight = svgRect.height;

        // 应用变换
        function applyTransform() {
            svg.style.transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
            svg.style.transformOrigin = 'center center';
        }

        // 限制平移范围，防止图表移出可视区域太远
        function constrainTranslation() {
            const maxTranslate = Math.max(containerRect.width, containerRect.height) * 0.5;
            translateX = Math.max(-maxTranslate, Math.min(maxTranslate, translateX));
            translateY = Math.max(-maxTranslate, Math.min(maxTranslate, translateY));
        }

        // 改进的缩放功能
        function zoomIn() {
            scale = Math.min(scale * 1.25, 8); // 最大8倍，更平滑的缩放
            constrainTranslation();
            applyTransform();
        }

        function zoomOut() {
            scale = Math.max(scale / 1.25, 0.1); // 最小0.1倍
            constrainTranslation();
            applyTransform();
        }

        function zoomReset() {
            scale = 1;
            translateX = 0;
            translateY = 0;
            applyTransform();
        }

        function zoomFit() {
            const currentContainerRect = container.getBoundingClientRect();
            const scaleX = (currentContainerRect.width - 80) / originalWidth;
            const scaleY = (currentContainerRect.height - 80) / originalHeight;
            scale = Math.min(scaleX, scaleY, 1); // 不超过原始大小

            translateX = 0;
            translateY = 0;
            applyTransform();
        }

        // 复制源代码功能
        function copySourceCode() {
            const originalCode = container.getAttribute('data-original-code');
            console.log('🔧 复制功能调试:', {
                container: container,
                originalCode: originalCode,
                hasAttribute: container.hasAttribute('data-original-code')
            });

            if (originalCode) {
                // 优先使用现代API
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(originalCode).then(() => {
                        showToast('✅ Mermaid 源代码已复制到剪贴板');
                    }).catch((err) => {
                        console.error('复制失败:', err);
                        fallbackCopy(originalCode);
                    });
                } else {
                    fallbackCopy(originalCode);
                }
            } else {
                showToast('❌ 未找到源代码，无法复制');
                console.warn('未找到 data-original-code 属性');
            }
        }

        // 备用复制方法
        function fallbackCopy(text) {
            try {
                const textArea = document.createElement('textarea');
                textArea.value = text;
                textArea.style.position = 'fixed';
                textArea.style.opacity = '0';
                document.body.appendChild(textArea);
                textArea.select();
                textArea.setSelectionRange(0, 99999); // 移动端兼容
                const successful = document.execCommand('copy');
                document.body.removeChild(textArea);

                if (successful) {
                    showToast('✅ Mermaid 源代码已复制到剪贴板');
                } else {
                    showToast('❌ 复制失败，请手动复制');
                }
            } catch (err) {
                console.error('备用复制方法失败:', err);
                showToast('❌ 复制失败，请手动复制');
            }
        }

        // 全屏查看功能
        function toggleFullscreen() {
            if (!document.fullscreenElement) {
                container.requestFullscreen().then(() => {
                    container.classList.add('mermaid-fullscreen');
                    showToast('按 ESC 键退出全屏');
                }).catch(() => {
                    showToast('浏览器不支持全屏功能');
                });
            } else {
                document.exitFullscreen();
                container.classList.remove('mermaid-fullscreen');
            }
        }

        // 显示提示消息
        function showToast(message) {
            const toast = document.createElement('div');
            toast.className = 'mermaid-toast';
            toast.textContent = message;
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: #24292e;
                color: white;
                padding: 8px 16px;
                border-radius: 4px;
                font-size: 14px;
                z-index: 10000;
                opacity: 0;
                transition: opacity 0.3s ease;
            `;
            document.body.appendChild(toast);

            // 显示动画
            setTimeout(() => toast.style.opacity = '1', 10);

            // 3秒后自动消失
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => document.body.removeChild(toast), 300);
            }, 3000);
        }

        // 绑定按钮事件
        controlsContainer.querySelector('.zoom-in').addEventListener('click', zoomIn);
        controlsContainer.querySelector('.zoom-out').addEventListener('click', zoomOut);
        controlsContainer.querySelector('.zoom-reset').addEventListener('click', zoomReset);
        controlsContainer.querySelector('.zoom-fit').addEventListener('click', zoomFit);
        controlsContainer.querySelector('.copy-code').addEventListener('click', copySourceCode);
        controlsContainer.querySelector('.fullscreen').addEventListener('click', toggleFullscreen);

        // 改进的鼠标滚轮缩放
        container.addEventListener('wheel', function(e) {
            e.preventDefault();

            // 计算鼠标位置相对于SVG的坐标
            const rect = svg.getBoundingClientRect();
            const mouseX = e.clientX - rect.left - rect.width / 2;
            const mouseY = e.clientY - rect.top - rect.height / 2;

            const oldScale = scale;

            if (e.deltaY < 0) {
                scale = Math.min(scale * 1.1, 8);
            } else {
                scale = Math.max(scale / 1.1, 0.1);
            }

            // 以鼠标位置为中心进行缩放
            const scaleChange = scale / oldScale;
            translateX = translateX * scaleChange + mouseX * (1 - scaleChange);
            translateY = translateY * scaleChange + mouseY * (1 - scaleChange);

            constrainTranslation();
            applyTransform();
        });

        // 键盘快捷键支持
        container.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case '+':
                    case '=':
                        e.preventDefault();
                        zoomIn();
                        break;
                    case '-':
                        e.preventDefault();
                        zoomOut();
                        break;
                    case '0':
                        e.preventDefault();
                        zoomReset();
                        break;
                    case 'c':
                        e.preventDefault();
                        copySourceCode();
                        break;
                }
            } else if (e.key === 'f' || e.key === 'F') {
                e.preventDefault();
                toggleFullscreen();
            }
        });

        // 确保容器可以接收键盘事件
        container.setAttribute('tabindex', '0');

        // 改进的鼠标拖拽平移
        svg.addEventListener('mousedown', function(e) {
            if (e.button === 0) { // 左键
                isDragging = true;
                lastMouseX = e.clientX;
                lastMouseY = e.clientY;
                svg.style.cursor = 'grabbing';
                e.preventDefault();

                // 防止文本选择
                document.body.style.userSelect = 'none';
            }
        });

        document.addEventListener('mousemove', function(e) {
            if (isDragging) {
                const deltaX = e.clientX - lastMouseX;
                const deltaY = e.clientY - lastMouseY;

                translateX += deltaX;
                translateY += deltaY;

                lastMouseX = e.clientX;
                lastMouseY = e.clientY;

                constrainTranslation();
                applyTransform();
            }
        });

        document.addEventListener('mouseup', function() {
            if (isDragging) {
                isDragging = false;
                svg.style.cursor = 'grab';
                document.body.style.userSelect = '';
            }
        });

        // 处理鼠标离开窗口的情况
        document.addEventListener('mouseleave', function() {
            if (isDragging) {
                isDragging = false;
                svg.style.cursor = 'grab';
                document.body.style.userSelect = '';
            }
        });

        // 设置初始样式
        svg.style.cursor = 'grab';
        svg.style.userSelect = 'none';

        // 添加焦点样式，支持键盘导航
        container.style.outline = 'none';
        container.addEventListener('focus', function() {
            container.style.boxShadow = '0 0 0 2px #0366d6';
        });
        container.addEventListener('blur', function() {
            container.style.boxShadow = '0 1px 3px rgba(0, 0, 0, 0.1)';
        });

        console.log(`✅ 为第${index + 1}个Mermaid图表添加了现代化控制功能`);
    });
}

/* ---------------- 初始化 ---------------- */
// 使用原生JavaScript，不依赖jQuery
document.addEventListener('DOMContentLoaded', function() {
    // KaTeX 已作为依赖加载，直接渲染
    renderAllKatex();

    // Mermaid 延迟初始化，避免与渲染冲突
    setTimeout(initMermaid, 500);
});

})();

