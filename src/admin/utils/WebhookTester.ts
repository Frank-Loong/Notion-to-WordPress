/**
 * Webhook测试器 - 现代化TypeScript版本
 * 
 * 提供Webhook连接测试、验证和诊断功能，包括：
 * - 连接测试
 * - 验证令牌测试
 * - 响应时间测试
 * - 错误诊断
 */

// import { post } from '../../shared/utils/ajax'; // 暂时不使用

export interface WebhookTestResult {
  success: boolean;
  status: 'success' | 'warning' | 'error';
  message: string;
  details?: {
    responseTime?: number;
    statusCode?: number;
    headers?: Record<string, string>;
    body?: any;
    error?: string;
  };
  suggestions?: string[];
}

export interface WebhookTestOptions {
  url: string;
  token: string;
  timeout?: number;
  testType?: 'connection' | 'verification' | 'full';
}

/**
 * Webhook测试器类
 */
export class WebhookTester {
  private static readonly DEFAULT_TIMEOUT = 10000; // 10秒
  private static readonly TEST_ENDPOINTS = {
    connection: '/wp-admin/admin-ajax.php',
    webhook: '/wp-json/notion-to-wordpress/v1/webhook/'
  };

  /**
   * 执行完整的Webhook测试
   */
  static async testWebhook(options: WebhookTestOptions): Promise<WebhookTestResult> {
    const { url, token, timeout = this.DEFAULT_TIMEOUT, testType = 'full' } = options;

    try {
      switch (testType) {
        case 'connection':
          return await this.testConnection(url, timeout);
        case 'verification':
          return await this.testVerification(url, token, timeout);
        case 'full':
        default:
          return await this.testFullWebhook(url, token, timeout);
      }
    } catch (error) {
      return {
        success: false,
        status: 'error',
        message: '测试执行失败',
        details: {
          error: (error as Error).message
        },
        suggestions: [
          '检查网络连接',
          '确认URL和令牌正确',
          '稍后重试'
        ]
      };
    }
  }

  /**
   * 测试基础连接
   */
  private static async testConnection(baseUrl: string, timeout: number): Promise<WebhookTestResult> {
    const startTime = Date.now();
    
    try {
      // 构建测试URL
      const testUrl = this.buildTestUrl(baseUrl, this.TEST_ENDPOINTS.connection);
      
      // 发送测试请求
      const response = await this.sendTestRequest(testUrl, {
        action: 'notion_to_wordpress_webhook_test',
        test_type: 'connection'
      }, timeout);

      const responseTime = Date.now() - startTime;

      if (response.ok) {
        return {
          success: true,
          status: 'success',
          message: '连接测试成功',
          details: {
            responseTime,
            statusCode: response.status
          }
        };
      } else {
        return {
          success: false,
          status: 'error',
          message: `连接测试失败 (${response.status})`,
          details: {
            responseTime,
            statusCode: response.status
          },
          suggestions: [
            '检查服务器是否正常运行',
            '确认URL是否正确',
            '检查防火墙设置'
          ]
        };
      }
    } catch (error) {
      const responseTime = Date.now() - startTime;
      
      return {
        success: false,
        status: 'error',
        message: '连接失败',
        details: {
          responseTime,
          error: (error as Error).message
        },
        suggestions: [
          '检查网络连接',
          '确认服务器地址正确',
          '检查是否存在网络限制'
        ]
      };
    }
  }

  /**
   * 测试验证令牌
   */
  private static async testVerification(baseUrl: string, token: string, timeout: number): Promise<WebhookTestResult> {
    const startTime = Date.now();
    
    try {
      // 构建Webhook URL
      const webhookUrl = this.buildTestUrl(baseUrl, this.TEST_ENDPOINTS.webhook + token);
      
      // 发送验证请求
      const response = await this.sendTestRequest(webhookUrl, {
        verification_token: 'test_verification_' + Date.now()
      }, timeout, 'POST');

      const responseTime = Date.now() - startTime;

      if (response.ok) {
        const data = await response.json();
        
        if (data.verification_token) {
          return {
            success: true,
            status: 'success',
            message: '验证令牌测试成功',
            details: {
              responseTime,
              statusCode: response.status,
              body: data
            }
          };
        } else {
          return {
            success: false,
            status: 'warning',
            message: '验证响应格式异常',
            details: {
              responseTime,
              statusCode: response.status,
              body: data
            },
            suggestions: [
              '检查Webhook处理逻辑',
              '确认返回格式正确'
            ]
          };
        }
      } else {
        return {
          success: false,
          status: 'error',
          message: `验证失败 (${response.status})`,
          details: {
            responseTime,
            statusCode: response.status
          },
          suggestions: [
            '检查令牌是否正确',
            '确认Webhook已启用',
            '检查服务器日志'
          ]
        };
      }
    } catch (error) {
      const responseTime = Date.now() - startTime;
      
      return {
        success: false,
        status: 'error',
        message: '验证测试失败',
        details: {
          responseTime,
          error: (error as Error).message
        },
        suggestions: [
          '检查网络连接',
          '确认URL和令牌正确',
          '检查服务器状态'
        ]
      };
    }
  }

  /**
   * 执行完整的Webhook测试
   */
  private static async testFullWebhook(baseUrl: string, token: string, timeout: number): Promise<WebhookTestResult> {
    const results: WebhookTestResult[] = [];
    
    // 1. 连接测试
    const connectionResult = await this.testConnection(baseUrl, timeout);
    results.push(connectionResult);
    
    if (!connectionResult.success) {
      return {
        success: false,
        status: 'error',
        message: '基础连接测试失败，无法继续',
        details: connectionResult.details,
        suggestions: connectionResult.suggestions
      };
    }
    
    // 2. 验证测试
    const verificationResult = await this.testVerification(baseUrl, token, timeout);
    results.push(verificationResult);
    
    // 3. 模拟事件测试
    const eventResult = await this.testWebhookEvent(baseUrl, token, timeout);
    results.push(eventResult);
    
    // 汇总结果
    const allSuccess = results.every(r => r.success);
    const hasWarnings = results.some(r => r.status === 'warning');
    
    const totalResponseTime = results.reduce((sum, r) => 
      sum + (r.details?.responseTime || 0), 0
    );
    
    const allSuggestions = results.flatMap(r => r.suggestions || []);
    
    return {
      success: allSuccess,
      status: allSuccess ? (hasWarnings ? 'warning' : 'success') : 'error',
      message: allSuccess 
        ? '所有测试通过' 
        : '部分测试失败',
      details: {
        responseTime: totalResponseTime,
        statusCode: 200
      },
      suggestions: [...new Set(allSuggestions)] // 去重
    };
  }

  /**
   * 测试Webhook事件处理
   */
  private static async testWebhookEvent(baseUrl: string, token: string, timeout: number): Promise<WebhookTestResult> {
    const startTime = Date.now();
    
    try {
      // 构建Webhook URL
      const webhookUrl = this.buildTestUrl(baseUrl, this.TEST_ENDPOINTS.webhook + token);
      
      // 模拟页面更新事件
      const testEvent = {
        type: 'page.updated',
        event: {
          type: 'page.updated',
          object: 'page',
          id: 'test-page-id-' + Date.now()
        },
        timestamp: new Date().toISOString()
      };
      
      // 发送事件请求
      const response = await this.sendTestRequest(webhookUrl, testEvent, timeout, 'POST');
      
      const responseTime = Date.now() - startTime;
      
      if (response.ok) {
        const data = await response.json();
        
        return {
          success: true,
          status: 'success',
          message: '事件处理测试成功',
          details: {
            responseTime,
            statusCode: response.status,
            body: data
          }
        };
      } else {
        return {
          success: false,
          status: 'error',
          message: `事件处理失败 (${response.status})`,
          details: {
            responseTime,
            statusCode: response.status
          },
          suggestions: [
            '检查事件处理逻辑',
            '确认同步功能正常',
            '查看服务器错误日志'
          ]
        };
      }
    } catch (error) {
      const responseTime = Date.now() - startTime;
      
      return {
        success: false,
        status: 'error',
        message: '事件测试失败',
        details: {
          responseTime,
          error: (error as Error).message
        },
        suggestions: [
          '检查Webhook处理器',
          '确认事件格式正确',
          '检查服务器配置'
        ]
      };
    }
  }

  /**
   * 发送测试请求
   */
  private static async sendTestRequest(
    url: string, 
    data: any, 
    timeout: number,
    method: 'GET' | 'POST' = 'POST'
  ): Promise<Response> {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeout);
    
    try {
      const response = await fetch(url, {
        method,
        headers: {
          'Content-Type': 'application/json',
          'User-Agent': 'NotionToWordPress-WebhookTester/1.0'
        },
        body: method === 'POST' ? JSON.stringify(data) : undefined,
        signal: controller.signal
      });
      
      clearTimeout(timeoutId);
      return response;
    } catch (error) {
      clearTimeout(timeoutId);
      throw error;
    }
  }

  /**
   * 构建测试URL
   */
  private static buildTestUrl(baseUrl: string, endpoint: string): string {
    const cleanBaseUrl = baseUrl.replace(/\/$/, '');
    const cleanEndpoint = endpoint.startsWith('/') ? endpoint : '/' + endpoint;
    
    return cleanBaseUrl + cleanEndpoint;
  }

  /**
   * 诊断Webhook问题
   */
  static async diagnoseWebhookIssues(url: string, token: string): Promise<{
    issues: string[];
    recommendations: string[];
  }> {
    const issues: string[] = [];
    const recommendations: string[] = [];
    
    try {
      // 基础URL检查
      if (!url || !url.trim()) {
        issues.push('Webhook URL为空');
        recommendations.push('请配置有效的Webhook URL');
        return { issues, recommendations };
      }
      
      // 令牌检查
      if (!token || !token.trim()) {
        issues.push('Webhook令牌为空');
        recommendations.push('请生成并配置Webhook令牌');
        return { issues, recommendations };
      }
      
      // URL格式检查
      try {
        const urlObj = new URL(url);
        
        if (urlObj.protocol === 'http:') {
          issues.push('使用HTTP协议，存在安全风险');
          recommendations.push('建议使用HTTPS协议');
        }
        
        if (urlObj.hostname === 'localhost' || urlObj.hostname.startsWith('127.')) {
          issues.push('使用本地地址，外部无法访问');
          recommendations.push('使用公网可访问的域名');
        }
        
      } catch (error) {
        issues.push('URL格式不正确');
        recommendations.push('请检查URL格式是否正确');
      }
      
      // 执行连接测试
      const testResult = await this.testWebhook({
        url,
        token,
        testType: 'connection'
      });
      
      if (!testResult.success) {
        issues.push('连接测试失败: ' + testResult.message);
        recommendations.push(...(testResult.suggestions || []));
      }
      
    } catch (error) {
      issues.push('诊断过程出错: ' + (error as Error).message);
      recommendations.push('请检查网络连接和配置');
    }
    
    return { issues, recommendations };
  }

  /**
   * 获取Webhook测试报告
   */
  static async generateTestReport(url: string, token: string): Promise<{
    summary: string;
    details: WebhookTestResult[];
    score: number;
    recommendations: string[];
  }> {
    const details: WebhookTestResult[] = [];
    
    // 执行各项测试
    const tests = [
      { name: '连接测试', type: 'connection' as const },
      { name: '验证测试', type: 'verification' as const },
      { name: '完整测试', type: 'full' as const }
    ];
    
    for (const test of tests) {
      try {
        const result = await this.testWebhook({
          url,
          token,
          testType: test.type
        });
        details.push(result);
      } catch (error) {
        details.push({
          success: false,
          status: 'error',
          message: `${test.name}执行失败`,
          details: { error: (error as Error).message }
        });
      }
    }
    
    // 计算得分
    const successCount = details.filter(d => d.success).length;
    const score = Math.round((successCount / details.length) * 100);
    
    // 生成摘要
    const summary = score === 100 
      ? 'Webhook配置完全正常'
      : score >= 70 
        ? 'Webhook配置基本正常，有少量问题'
        : 'Webhook配置存在问题，需要修复';
    
    // 收集建议
    const recommendations = [
      ...new Set(details.flatMap(d => d.suggestions || []))
    ];
    
    return {
      summary,
      details,
      score,
      recommendations
    };
  }
}

export default WebhookTester;
