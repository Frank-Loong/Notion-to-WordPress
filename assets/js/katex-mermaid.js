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

/* ---------------- Mermaid 缩放和平移功能 ---------------- */
function addPanZoomToMermaid() {
    console.log('开始为Mermaid图表添加缩放和平移功能');

    document.querySelectorAll('.mermaid').forEach(function(container, index) {
        const svg = container.querySelector('svg');
        if (!svg || svg.dataset.panZoomEnabled) {
            return; // 跳过已经处理过的SVG
        }

        // 标记为已处理
        svg.dataset.panZoomEnabled = 'true';

        // 创建控制按钮容器
        const controlsContainer = document.createElement('div');
        controlsContainer.className = 'mermaid-controls';
        controlsContainer.innerHTML = `
            <div class="mermaid-zoom-controls">
                <button class="mermaid-btn zoom-in" title="放大">+</button>
                <button class="mermaid-btn zoom-out" title="缩小">−</button>
                <button class="mermaid-btn zoom-reset" title="重置">⌂</button>
                <button class="mermaid-btn zoom-fit" title="适应窗口">⊞</button>
            </div>
        `;

        // 将控制按钮插入到容器中
        container.style.position = 'relative';
        container.appendChild(controlsContainer);

        // 初始化缩放和平移状态
        let scale = 1;
        let translateX = 0;
        let translateY = 0;
        let isDragging = false;
        let lastMouseX = 0;
        let lastMouseY = 0;

        // 获取SVG的原始尺寸
        const originalViewBox = svg.getAttribute('viewBox');
        const svgRect = svg.getBoundingClientRect();
        const originalWidth = svgRect.width;
        const originalHeight = svgRect.height;

        // 应用变换
        function applyTransform() {
            svg.style.transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
            svg.style.transformOrigin = 'center center';
        }

        // 缩放功能
        function zoomIn() {
            scale = Math.min(scale * 1.2, 5); // 最大5倍
            applyTransform();
        }

        function zoomOut() {
            scale = Math.max(scale / 1.2, 0.1); // 最小0.1倍
            applyTransform();
        }

        function zoomReset() {
            scale = 1;
            translateX = 0;
            translateY = 0;
            applyTransform();
        }

        function zoomFit() {
            const containerRect = container.getBoundingClientRect();
            const svgRect = svg.getBoundingClientRect();

            const scaleX = (containerRect.width - 40) / originalWidth;
            const scaleY = (containerRect.height - 40) / originalHeight;
            scale = Math.min(scaleX, scaleY, 1); // 不超过原始大小

            translateX = 0;
            translateY = 0;
            applyTransform();
        }

        // 绑定按钮事件
        controlsContainer.querySelector('.zoom-in').addEventListener('click', zoomIn);
        controlsContainer.querySelector('.zoom-out').addEventListener('click', zoomOut);
        controlsContainer.querySelector('.zoom-reset').addEventListener('click', zoomReset);
        controlsContainer.querySelector('.zoom-fit').addEventListener('click', zoomFit);

        // 鼠标滚轮缩放
        container.addEventListener('wheel', function(e) {
            e.preventDefault();

            if (e.deltaY < 0) {
                zoomIn();
            } else {
                zoomOut();
            }
        });

        // 鼠标拖拽平移
        svg.addEventListener('mousedown', function(e) {
            if (e.button === 0) { // 左键
                isDragging = true;
                lastMouseX = e.clientX;
                lastMouseY = e.clientY;
                svg.style.cursor = 'grabbing';
                e.preventDefault();
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

                applyTransform();
            }
        });

        document.addEventListener('mouseup', function(e) {
            if (isDragging) {
                isDragging = false;
                svg.style.cursor = 'grab';
            }
        });

        // 设置初始样式
        svg.style.cursor = 'grab';
        svg.style.userSelect = 'none';

        console.log(`为第${index + 1}个Mermaid图表添加了缩放和平移功能`);
    });
}

/* ---------------- 初始化 ---------------- */
$(function () {
// KaTeX 已作为依赖加载，直接渲染
renderAllKatex();

// Mermaid 延迟初始化，避免与渲染冲突
setTimeout(initMermaid, 500);
});

})(jQuery);

