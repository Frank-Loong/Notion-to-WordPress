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
        let mermaidCode = element.textContent || element.innerText;
        
        if (!mermaidCode.trim()) {
            console.log(`跳过空的Mermaid图表 #${index}`);
            return;
        }
        
        // 额外的字符清理，确保Mermaid语法正确
        mermaidCode = mermaidCode
            // 处理可能的HTML实体
            .replace(/--&gt;/g, '-->')
            .replace(/-&gt;/g, '->')
            .replace(/&gt;&gt;/g, '>>')
            .replace(/&gt;/g, '>')
            .replace(/&lt;/g, '<')
            .replace(/&quot;/g, '"')
            .replace(/&#39;/g, "'")
            .replace(/&amp;/g, '&')
            // 处理Unicode字符问题
            .replace(/–>/g, '-->')  // em dash
            .replace(/—>/g, '-->')  // em dash
            .replace(/–/g, '-')     // em dash to hyphen
            .replace(/—/g, '-')     // em dash to hyphen
            // 处理其他可能的箭头字符
            .replace(/»/g, '>')     // right guillemet
            .replace(/«/g, '<')     // left guillemet
            .trim();
        
        console.log(`渲染Mermaid图表 #${index}:`, mermaidCode.substring(0, 50) + '...');
        console.log(`完整Mermaid代码 #${index}:`, mermaidCode);
        
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
                    console.error(`失败的代码:`, mermaidCode);
                    element.innerHTML = '<div style="color: red; border: 1px solid red; padding: 10px;">Mermaid渲染失败: ' + err.message + '</div>';
                });
            } else if (typeof mermaid.init === 'function') {
                // 清空元素内容并设置代码
                element.textContent = mermaidCode;
                // 使用旧版API
                mermaid.init(undefined, element);
                console.log(`Mermaid图表 #${index} 渲染完成（旧版API）`);
            }
            
        } catch (error) {
            console.error(`处理Mermaid图表 #${index} 时出错:`, error);
            console.error(`出错的代码:`, mermaidCode);
            element.innerHTML = '<div style="color: red; border: 1px solid red; padding: 10px;">Mermaid处理失败: ' + error.message + '</div>';
        }
    });
});

})(jQuery);