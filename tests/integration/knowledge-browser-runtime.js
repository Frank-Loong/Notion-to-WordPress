#!/usr/bin/env node
'use strict';

const path = require('path');
const { chromium } = require('playwright');
const targetUrl = process.argv[2];
if (!targetUrl) process.exit(2);

(async () => {
  const browser = await chromium.launch({
    executablePath:
      process.env.NTWP_BROWSER_PATH ||
      'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    headless: true,
  });
  const errors = [];
  const reports = [];
  try {
    const page = await browser.newPage();
    page.on('pageerror', error => errors.push(error.message));
    for (const [name, width] of [
      ['desktop', 1440],
      ['mobile', 390],
    ]) {
      await page.setViewportSize({ width, height: 1000 });
      const response = await page.goto(targetUrl, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      });
      await page.waitForFunction(
        () => {
          const math = [...document.querySelectorAll('.notion-equation')];
          return (
            math.length >= 5 &&
            math.every(node => node.dataset.rendered === 'true')
          );
        },
        { timeout: 30000 }
      );
      const webpageCard = page
        .locator('.notion-embed-card')
        .filter({ hasText: '网页卡片标题' })
        .first();
      await webpageCard.locator('img').scrollIntoViewIfNeeded();
      await page.waitForFunction(
        () =>
          [...document.querySelectorAll('.notion-embed-card')].some(
            card =>
              card.textContent.includes('网页卡片标题') &&
              card.querySelector('img')?.naturalWidth > 0
          ),
        null,
        { timeout: 15000 }
      );
      const checks = await page.evaluate(() => {
        const views = ['table', 'gallery', 'list', 'board'];
        const cards = [...document.querySelectorAll('.notion-embed-card')];
        return {
          renderedFormulas:
            document.querySelectorAll(
              '.notion-equation[data-rendered="true"] .katex'
            ).length >= 5,
          noFormulaErrors:
            document.querySelectorAll('.katex-error').length === 0,
          fourViews: views.every(view =>
            document.querySelector(`.notion-database-${view}`)
          ),
          completeTable:
            document.querySelectorAll('.notion-database-table tbody tr')
              .length === 9,
          completeGallery:
            document.querySelectorAll('.notion-gallery-item').length === 9,
          completeList:
            document.querySelectorAll('.notion-database-list-items li')
              .length === 9,
          completeBoard:
            document.querySelectorAll('.notion-board-item').length === 9,
          localRecordLinks: [
            ...document.querySelectorAll('.notion-database a'),
          ].every(link => new URL(link.href).origin === location.origin),
          hiddenPropertiesAbsent:
            !document.body.textContent.includes('HIDDEN_SENTINEL'),
          cardHasTitleAndLoadedCover: cards.some(
            card =>
              card.textContent.includes('网页卡片标题') &&
              card.querySelector('img')?.naturalWidth > 0
          ),
          blockedFrameAbsent: ![...document.querySelectorAll('iframe')].some(
            frame => frame.src.includes('ntwp-fixture.example')
          ),
          noPageOverflow:
            document.documentElement.scrollWidth <= window.innerWidth + 2,
        };
      });
      const screenshot = path.resolve(
        __dirname,
        `../artifacts/knowledge-${name}.png`
      );
      await page.screenshot({ path: screenshot, fullPage: true });
      reports.push({
        viewport: name,
        httpStatus: response.status(),
        checks,
        screenshot,
      });
    }
    const recordLink = page.locator('.notion-database-list a').first();
    await recordLink.click();
    await page.waitForLoadState('domcontentloaded');
    const localLinkNavigation = (
      await page.locator('body').innerText()
    ).includes('Local link target.');
    const passed =
      errors.length === 0 &&
      localLinkNavigation &&
      reports.every(
        report =>
          report.httpStatus === 200 &&
          Object.values(report.checks).every(Boolean)
      );
    process.stdout.write(
      JSON.stringify(
        {
          status: passed ? 'PASS' : 'FAIL',
          reports,
          localLinkNavigation,
          errors,
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
  process.stderr.write(error.stack + '\n');
  process.exitCode = 1;
});
