#!/usr/bin/env node

'use strict';

const { chromium } = require('playwright');

const baseUrl = process.argv[2] || 'http://127.0.0.1:8088';
const cookieName = process.env.NTWP_TEST_AUTH_COOKIE_NAME;
const cookieValue = process.env.NTWP_TEST_AUTH_COOKIE_VALUE;
const username = process.env.NTWP_TEST_WP_USERNAME;
const password = process.env.NTWP_TEST_WP_PASSWORD;

if ((!cookieName || !cookieValue) && (!username || !password)) {
  process.stderr.write(
    'Authenticated WordPress cookie or test username/password is required.\n'
  );
  process.exit(2);
}

(async () => {
  const consoleErrors = [];
  const pageErrors = [];
  const failedRequests = [];
  const errorResponses = [];
  const ajaxActions = [];
  const browser = await chromium.launch({
    executablePath:
      'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    headless: true,
  });

  try {
    const context = await browser.newContext();
    if (cookieName && cookieValue) {
      await context.addCookies([
        {
          name: cookieName,
          value: cookieValue,
          url: baseUrl,
          httpOnly: true,
          sameSite: 'Lax',
        },
      ]);
    }
    const page = await context.newPage();
    page.on('console', message => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });
    page.on('pageerror', error =>
      pageErrors.push(error.stack || error.message)
    );
    page.on('requestfailed', request => {
      failedRequests.push({
        url: request
          .url()
          .replace(/([?&](?:nonce|_wpnonce)=)[^&]+/gi, '$1[redacted]'),
        error: request.failure()?.errorText || 'unknown',
      });
    });
    page.on('request', request => {
      const postData = request.postData() || '';
      const params = new URLSearchParams(postData);
      const multipartAction = postData.match(
        /name="action"\r?\n\r?\n([^\r\n]+)/
      )?.[1];
      const action = params.get('action') || multipartAction;
      if (action) ajaxActions.push(action);
    });
    page.on('response', async response => {
      if (response.status() >= 400) {
        const requestData = response.request().postData() || '';
        const params = new URLSearchParams(requestData);
        errorResponses.push({
          status: response.status(),
          url: response
            .url()
            .replace(/([?&](?:nonce|_wpnonce)=)[^&]+/gi, '$1[redacted]'),
          action: params.get('action'),
          body: (await response.text()).slice(0, 500),
        });
      }
    });

    if (username && password && (!cookieName || !cookieValue)) {
      await page.goto(`${baseUrl}/wp-login.php`, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      });
      await page.locator('#user_login').fill(username);
      await page.locator('#user_pass').fill(password);
      await Promise.all([
        page.waitForURL(url => url.pathname.startsWith('/wp-admin/'), {
          timeout: 60000,
        }),
        page.locator('#wp-submit').click(),
      ]);
    }

    const response = await page.goto(
      `${baseUrl}/wp-admin/admin.php?page=notion-to-wordpress`,
      { waitUntil: 'networkidle', timeout: 60000 }
    );

    const initial = await page.evaluate(() => ({
      title: document.title,
      menuItems: document.querySelectorAll('.notion-wp-menu-item').length,
      tabPanels: document.querySelectorAll('.notion-wp-tab-content').length,
      activeMenu: document
        .querySelector('.notion-wp-menu-item.active')
        ?.getAttribute('data-tab'),
      activePanel: document.querySelector('.notion-wp-tab-content.active')?.id,
      adminBundleLoaded: Array.from(document.scripts).some(script =>
        /assets\/dist\/js\/admin\.js/.test(script.src)
      ),
    }));

    const tabs = await page
      .locator('.notion-wp-menu-item')
      .evaluateAll(buttons =>
        buttons.map(button => button.getAttribute('data-tab')).filter(Boolean)
      );
    const tabResults = [];
    for (const tab of tabs) {
      await page.locator(`.notion-wp-menu-item[data-tab="${tab}"]`).click();
      tabResults.push(
        await page.evaluate(tabId => {
          const button = document.querySelector(
            `.notion-wp-menu-item[data-tab="${tabId}"]`
          );
          const panel = document.getElementById(tabId);
          return {
            tab: tabId,
            selected: button?.getAttribute('aria-selected') === 'true',
            visible: Boolean(
              panel && !panel.hidden && panel.classList.contains('active')
            ),
          };
        }, tab)
      );
    }

    const apiTab = page.locator(
      '.notion-wp-menu-item[data-tab="api-settings"]'
    );
    await apiTab.click();
    await apiTab.press('ArrowRight');
    const keyboardNavigation = await page.evaluate(() => ({
      activeTab: document
        .querySelector('.notion-wp-menu-item.active')
        ?.getAttribute('data-tab'),
      focusedTab: document.activeElement?.getAttribute('data-tab'),
    }));

    await page.locator('.notion-wp-menu-item[data-tab="api-settings"]').click();
    await page.locator('#field_template').selectOption('custom');
    const customTemplate = await page.evaluate(() => ({
      activeTab: document
        .querySelector('.notion-wp-menu-item.active')
        ?.getAttribute('data-tab'),
      mappingFocused: document.activeElement?.id === 'mapping_title',
    }));

    await page.locator('.notion-wp-menu-item[data-tab="api-settings"]').click();
    const recommendationPromise = page
      .waitForResponse(
        response => {
          return (response.request().postData() || '').includes(
            'notion_to_wordpress_get_smart_recommendations'
          );
        },
        { timeout: 5000 }
      )
      .catch(() => null);
    await page.locator('#get-smart-recommendations').click();
    const recommendationHttp = await recommendationPromise;
    const recommendationPayload = recommendationHttp
      ? await recommendationHttp.json()
      : null;
    if ((await page.locator('#apply-recommendations').count()) > 0) {
      await page.locator('#apply-recommendations').click();
    }
    const recommendations = await page.evaluate(() => ({
      httpStatus: 0,
      applied: (
        document.getElementById('apply-recommendations')?.textContent || ''
      ).includes('已应用'),
      performanceValue: document.getElementById('performance_level')?.value,
      templateValue: document.getElementById('field_template')?.value,
    }));
    recommendations.httpStatus = recommendationHttp?.status() || 0;
    recommendations.responseSuccess = recommendationPayload?.success === true;
    recommendations.responseKeys = Object.keys(
      recommendationPayload?.data || {}
    );
    recommendations.buttonDisabled = await page
      .locator('#get-smart-recommendations')
      .isDisabled();

    await page
      .locator('.notion-wp-menu-item[data-tab="field-mapping"]')
      .click();
    const customFieldCountBefore = await page
      .locator('.custom-field-mapping')
      .count();
    await page.locator('#add-custom-field').click();
    const customFieldCountAfterAdd = await page
      .locator('.custom-field-mapping')
      .count();
    await page.locator('.custom-field-mapping .remove-field').last().click();
    const customFieldCountAfterRemove = await page
      .locator('.custom-field-mapping')
      .count();

    await page.locator('.notion-wp-menu-item[data-tab="api-settings"]').click();
    const connectionResponsePromise = page.waitForResponse(
      response => {
        return (response.request().postData() || '').includes(
          'notion_to_wordpress_test_connection'
        );
      },
      { timeout: 45000 }
    );
    await page.locator('#notion-test-connection').click();
    const connectionResponse = await connectionResponsePromise;
    const connectionJson = await connectionResponse.json();
    const connection = {
      httpStatus: connectionResponse.status(),
      success: connectionJson.success === true,
      buttonRestored: await page.locator('#notion-test-connection').isEnabled(),
    };

    const passwordInput = page.locator('#notion_to_wordpress_api_key');
    await page.locator('.show-hide-password').click();
    const passwordRevealed =
      (await passwordInput.getAttribute('type')) === 'text';
    await page.locator('.show-hide-password').click();

    const webhookCheckbox = page.locator('#webhook_enabled');
    const webhookInitiallyEnabled = await webhookCheckbox.isChecked();
    await webhookCheckbox.setChecked(true);
    const webhookPanelMatchesToggle =
      (await page.locator('#webhook-settings').isVisible()) === true;

    const refreshTokenAction = 'notion_to_wordpress_refresh_verification_token';
    const refreshTokenRequestsBefore = ajaxActions.filter(
      action => action === refreshTokenAction
    ).length;
    const refreshTokenResponsePromise = page.waitForResponse(response =>
      (response.request().postData() || '').includes(refreshTokenAction)
    );
    await page.locator('#refresh-verification-token').click();
    const refreshTokenResponse = await refreshTokenResponsePromise;
    const refreshTokenRequests =
      ajaxActions.filter(action => action === refreshTokenAction).length -
      refreshTokenRequestsBefore;
    await webhookCheckbox.setChecked(webhookInitiallyEnabled);

    await page
      .locator('.notion-wp-menu-item[data-tab="performance-config"]')
      .click();
    const originalCdnProvider = await page
      .locator('#cdn_provider')
      .inputValue();
    await page.locator('#cdn_provider').selectOption('custom');
    const customCdnControl = {
      visible: await page.locator('#custom_cdn_url').isVisible(),
      required: await page.locator('#custom_cdn_url').getAttribute('required'),
    };
    await page.locator('#cdn_provider').selectOption(originalCdnProvider);

    await page.locator('.notion-wp-menu-item[data-tab="performance"]').click();
    const refreshResponsePromise = page.waitForResponse(response => {
      return (response.request().postData() || '').includes(
        'notion_to_wordpress_refresh_performance_stats'
      );
    });
    await page.locator('#refresh-performance-stats').click();
    const refreshResponse = await refreshResponsePromise;

    const asyncResponsePromise = page.waitForResponse(response =>
      (response.request().postData() || '').includes(
        'notion_to_wordpress_get_async_status'
      )
    );
    await page.locator('#refresh-async-status').click();
    const asyncResponse = await asyncResponsePromise;

    await page.locator('.notion-wp-menu-item[data-tab="debug"]').click();
    const logFileCount = await page
      .locator('#log-file-selector option')
      .count();
    let logViewStatus = null;
    if (logFileCount > 0) {
      const logResponsePromise = page.waitForResponse(response =>
        (response.request().postData() || '').includes(
          'notion_to_wordpress_view_log'
        )
      );
      await page.locator('#view-log-button').click();
      logViewStatus = (await logResponsePromise).status();
    }

    const safeControls = {
      performanceRefreshStatus: refreshResponse.status(),
      asyncRefreshStatus: asyncResponse.status(),
      indexStatusRendered:
        (await page.locator('#index-status-container li').count()) > 0,
      passwordRevealed,
      webhookPanelMatchesToggle,
      refreshVerificationToken: {
        httpStatus: refreshTokenResponse.status(),
        requestCount: refreshTokenRequests,
        buttonRestored: await page
          .locator('#refresh-verification-token')
          .isEnabled(),
      },
      customCdnControl,
      logFileCount,
      logViewStatus,
      customFields: {
        before: customFieldCountBefore,
        afterAdd: customFieldCountAfterAdd,
        afterRemove: customFieldCountAfterRemove,
      },
    };

    let settingsPersistence = null;
    if (process.env.NTWP_TEST_ADMIN_SAVE === '1') {
      await page
        .locator('.notion-wp-menu-item[data-tab="api-settings"]')
        .click();
      await page.locator('#performance_level').selectOption('custom');
      await page.locator('#api_page_size').fill('160');
      await page.locator('#concurrent_requests').fill('7');
      await page.locator('#batch_size').fill('35');
      await page.locator('#log_buffer_size').fill('80');

      await page
        .locator('.notion-wp-menu-item[data-tab="api-settings"]')
        .click();
      await page
        .locator('input[name="cron_incremental_sync"]')
        .setChecked(false);
      await page.locator('input[name="cron_check_deletions"]').setChecked(true);
      await page.locator('#field_template').selectOption('custom');
      await page.locator('#mapping_title').fill('QA Beta2 Title');

      await page
        .locator('.notion-wp-menu-item[data-tab="debug"]')
        .click();
      await page.locator('#log_retention_days').selectOption('14');

      const saveAction = 'notion_to_wordpress_save_settings';
      const saveRequestsBefore = ajaxActions.filter(
        action => action === saveAction
      ).length;
      const saveResponsePromise = page.waitForResponse(
        response => (response.request().postData() || '').includes(saveAction),
        { timeout: 5000 }
      ).catch(() => null);
      await page.locator('#notion-save-settings').click();
      const saveResponse = await saveResponsePromise;
      const savePayload = saveResponse ? await saveResponse.json() : null;
      const saveRequestCount =
        ajaxActions.filter(action => action === saveAction).length -
        saveRequestsBefore;

      if (saveResponse) {
        await page.reload({ waitUntil: 'networkidle' });
      }
      settingsPersistence = {
        httpStatus: saveResponse?.status() || null,
        success: savePayload?.success === true,
        requestCount: saveRequestCount,
        diagnostics: saveResponse
          ? null
          : await page.evaluate(() => ({
              url: window.location.href,
              formValid: document
                .getElementById('notion-to-wordpress-settings-form')
                ?.checkValidity(),
              buttonDisabled:
                document.getElementById('notion-save-settings')?.disabled,
              interactionsInitialized: Boolean(
                window.notionToWp?.adminInteractions?.isInitialized?.()
              ),
              toast: document
                .getElementById('notion-wp-toast')
                ?.textContent?.trim(),
            })),
        performanceLevel: await page.locator('#performance_level').inputValue(),
        apiPageSize: await page.locator('#api_page_size').inputValue(),
        concurrentRequests: await page
          .locator('#concurrent_requests')
          .inputValue(),
        batchSize: await page.locator('#batch_size').inputValue(),
        logBufferSize: await page.locator('#log_buffer_size').inputValue(),
        cronIncrementalSync: await page
          .locator('input[name="cron_incremental_sync"]')
          .isChecked(),
        cronCheckDeletions: await page
          .locator('input[name="cron_check_deletions"]')
          .isChecked(),
        logRetentionDays: await page
          .locator('#log_retention_days')
          .inputValue(),
        fieldTemplate: await page.locator('#field_template').inputValue(),
        mappingTitle: await page.locator('#mapping_title').inputValue(),
      };
    }

    let sync = null;
    if (process.env.NTWP_TEST_ADMIN_SYNC === '1') {
      await page
        .locator('.notion-wp-menu-item[data-tab="api-settings"]')
        .click();
      const syncResponsePromise = page.waitForResponse(
        response =>
          (response.request().postData() || '').includes(
            'notion_to_wordpress_manual_sync'
          ),
        { timeout: 330000 }
      );
      await page.locator('#notion-manual-import').click();
      const syncResponse = await syncResponsePromise;
      const syncPayload = await syncResponse.json();
      sync = {
        httpStatus: syncResponse.status(),
        success: syncPayload.success === true,
        buttonRestored: await page.locator('#notion-manual-import').isEnabled(),
      };
    }

    process.stdout.write(
      JSON.stringify(
        {
          httpStatus: response?.status() || null,
          initial,
          tabResults,
          keyboardNavigation,
          customTemplate,
          recommendations,
          connection,
          safeControls,
          settingsPersistence,
          sync,
          consoleErrors,
          pageErrors,
          failedRequests,
          errorResponses,
          ajaxActions,
        },
        null,
        2
      ) + '\n'
    );
  } finally {
    await browser.close();
  }
})().catch(error => {
  process.stderr.write(`${error.stack || error.message}\n`);
  process.exit(1);
});
