/**
 * MathJax配置文件
 *
 * @since      1.0.7
 * @package    Notion_To_WordPress
 */

window.MathJax = {
  tex: {
    inlineMath: [
      ['$', '$'],
      ['\\(', '\\)']
    ],
    displayMath: [
      ['$$', '$$'],
      ['\\[', '\\]']
    ],
    processEscapes: true,
    processEnvironments: true,
    packages: {'[+]': ['mhchem']}  // 启用化学方程式扩展
  },
  options: {
    skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code'],
    processHtmlClass: 'tex2jax_process|latex-block|latex-inline',
    ignoreHtmlClass: 'tex2jax_ignore',
    renderActions: {
      // 增强的错误处理
      find: [10, function (doc) {
        for (const node of document.querySelectorAll('script[type^="math/tex"]')) {
          const display = !!node.type.match(/; *mode=display/);
          const math = new doc.options.MathItem(
            node.textContent,
            doc.inputJax[0],
            display
          );
          const text = document.createTextNode('');
          const span = document.createElement('span');
          span.className = display ? 'latex-block' : 'latex-inline';
          span.appendChild(text);
          node.parentNode.replaceChild(span, node);
          math.start = {node: text, delim: '', n: 0};
          math.end = {node: text, delim: '', n: 0};
          doc.math.push(math);
        }
      }, '']
    }
  },
  svg: {
    fontCache: 'global'
  },
  startup: {
    typeset: true,
    ready: () => {
      console.log('MathJax is loaded and ready!');
      // 处理完成后的回调函数
      MathJax.startup.defaultReady();
    }
  }
}; 