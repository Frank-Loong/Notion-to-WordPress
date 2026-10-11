#!/usr/bin/env node

'use strict';

const path = require('path');
const { chromium } = require('playwright');

const targetUrl = process.argv[2];
const screenshotPath = process.argv[3];

if (!targetUrl) {
  process.stderr.write('Usage: node browser-runtime.js <url> [screenshot-path]\n');
  process.exit(2);
}

(async () => {
  const consoleErrors = [];
  const pageErrors = [];
  const browser = await chromium.launch({
    executablePath:
      'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    headless: true,
  });

  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    page.on('console', message => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });
    page.on('pageerror', error => pageErrors.push(error.message));

    const response = await page.goto(targetUrl, {
      waitUntil: 'networkidle',
      timeout: 60000,
    });
    await page.waitForTimeout(3000);

    const checks = await page.evaluate(() => {
      const mathSelector =
        '.notion-equation, .katex-math, .math-expression, [data-math], .wp-block-notion-math';
      const mermaidSelector =
        'pre.mermaid, .notion-mermaid, .mermaid-chart, .diagram, [data-mermaid], .wp-block-notion-mermaid';
      const math = Array.from(document.querySelectorAll(mathSelector));
      const mermaid = Array.from(document.querySelectorAll(mermaidSelector));

      return {
        title: document.title,
        formulas: math.length,
        renderedFormulas: math.filter(
          node =>
            node.classList.contains('katex-rendered') &&
            node.getAttribute('data-rendered') === 'true' &&
            node.querySelector('.katex')
        ).length,
        katexErrors: document.querySelectorAll('.katex-error').length,
        codeBlocks: document.querySelectorAll('pre code, .wp-block-code code').length,
        mermaidDiagrams: mermaid.length,
        renderedMermaidDiagrams: mermaid.filter(node => node.querySelector('svg')).length,
        databaseTables: document.querySelectorAll(
          'table, .notion-database, .notion-collection, .notion-child-database'
        ).length,
        videoEmbeds: Array.from(document.querySelectorAll('iframe')).filter(frame =>
          /youtube|youtu\.be|vimeo|bilibili/i.test(frame.getAttribute('src') || '')
        ).length,
        pdfLinks: Array.from(document.querySelectorAll('a[href]')).filter(link =>
          /\.pdf(?:$|[?#])/i.test(link.getAttribute('href') || '')
        ).length,
        emptyMediaContainers: Array.from(
          document.querySelectorAll(
            '.notion-image, .notion-video, .notion-file, .notion-pdf, figure.wp-block-image'
          )
        ).filter(node => !node.textContent.trim() && !node.querySelector('img, video, iframe, a'))
          .length,
      };
    });

    if (screenshotPath) {
      await page.screenshot({ path: path.resolve(screenshotPath), fullPage: true });
    }

    const relevantConsoleErrors = consoleErrors.filter(message =>
      /katex|parse error|mermaid|uncaught|typeerror|referenceerror/i.test(message)
    );
    const passed =
      Boolean(response && response.ok()) &&
      checks.formulas > 0 &&
      checks.renderedFormulas === checks.formulas &&
      checks.katexErrors === 0 &&
      checks.codeBlocks > 0 &&
      checks.mermaidDiagrams > 0 &&
      checks.renderedMermaidDiagrams === checks.mermaidDiagrams &&
      checks.databaseTables > 0 &&
      checks.videoEmbeds > 0 &&
      checks.pdfLinks > 0 &&
      checks.emptyMediaContainers === 0 &&
      relevantConsoleErrors.length === 0 &&
      pageErrors.length === 0;

    process.stdout.write(
      JSON.stringify(
        {
          status: passed ? 'PASS' : 'FAIL',
          httpStatus: response ? response.status() : null,
          checks,
          consoleErrors: relevantConsoleErrors,
          pageErrors,
        },
        null,
        2
      ) + '\n'
    );
    process.exitCode = passed ? 0 : 1;
  } finally {
    await browser.close();
  }
})().catch(error => {
  process.stderr.write(`${error.stack || error.message}\n`);
  process.exit(1);
});
