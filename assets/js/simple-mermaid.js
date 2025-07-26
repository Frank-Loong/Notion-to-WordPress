/**
 * 简化的 Mermaid 渲染脚本
 * 
 * 专注于简单可靠的 Mermaid 图表渲染
 * 
 * @since 2.0.0-beta.1
 */

(function($) {
'use strict';

$(document).ready(function() {
    
    // 检查Mermaid是否已加载
    if (typeof window.mermaid === 'undefined') {
        console.log('Mermaid library not loaded');
        return;
    }
    
    console.log('开始初始化Mermaid');
    
    // 简单的Mermaid配置
    mermaid.initialize({
        startOnLoad: false,
        theme: 'default',
        securityLevel: 'loose',
        flowchart: {
            useMaxWidth: true,
            htmlLabels: true
        }
    });
    
    // 查找所有Mermaid元素
    const mermaidElements = document.querySelectorAll('.mermaid');
    
    if (mermaidElements.length === 0) {
        console.log('没有找到Mermaid图表');
        return;
    }
    
    console.log(`找到 ${mermaidElements.length} 个Mermaid图表，开始渲染`);
    
    // 渲染每个Mermaid图表
    mermaidElements.forEach((element, index) => {
        const mermaidCode = element.textContent || element.innerText;
        
        if (!mermaidCode.trim()) {
            console.log(`跳过空的Mermaid图表 #${index}`);
            return;
        }
        
        console.log(`渲染Mermaid图表 #${index}:`, mermaidCode.substring(0, 50) + '...');
        
        try {
            // 为每个元素设置唯一ID
            const elementId = `mermaid-${index}-${Date.now()}`;
            element.id = elementId;
            
            // 使用现代API（如果可用）
            if (typeof mermaid.render === 'function') {
                mermaid.render(elementId + '-svg', mermaidCode).then(result => {
                    element.innerHTML = result.svg;
                    console.log(`Mermaid图表 #${index} 渲染成功`);
                }).catch(err => {
                    console.error(`Mermaid图表 #${index} 渲染失败:`, err);
                    element.innerHTML = '<div style="color: red; border: 1px solid red; padding: 10px;">Mermaid渲染失败: ' + err.message + '</div>';
                });
            } else if (typeof mermaid.init === 'function') {
                // 使用旧版API
                mermaid.init(undefined, element);
                console.log(`Mermaid图表 #${index} 渲染完成（旧版API）`);
            }
            
        } catch (error) {
            console.error(`处理Mermaid图表 #${index} 时出错:`, error);
            element.innerHTML = '<div style="color: red; border: 1px solid red; padding: 10px;">Mermaid处理失败: ' + error.message + '</div>';
        }
    });
});

})(jQuery);