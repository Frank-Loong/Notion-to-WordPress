/**
 * Webhook验证器 - 现代化TypeScript版本
 * 
 * 从原有PHP Security类的webhook验证功能迁移，包括：
 * - Webhook URL验证
 * - Token格式验证
 * - 安全检查
 * - 配置验证
 */

export interface WebhookValidationResult {
  isValid: boolean;
  errors: string[];
  warnings: string[];
  suggestions: string[];
}

export interface WebhookConfig {
  enabled: boolean;
  token: string;
  url: string;
  verificationToken?: string;
  incrementalSync?: boolean;
  checkDeletions?: boolean;
}

/**
 * Webhook验证器类
 */
export class WebhookValidator {
  // 验证规则常量
  private static readonly TOKEN_MIN_LENGTH = 16;
  private static readonly TOKEN_MAX_LENGTH = 64;
  private static readonly TOKEN_PATTERN = /^[a-zA-Z0-9_-]+$/;
  private static readonly URL_PATTERN = /^https?:\/\/[^\s/$.?#].[^\s]*$/i;

  /**
   * 验证Webhook令牌格式
   */
  static validateToken(token: string): WebhookValidationResult {
    const result: WebhookValidationResult = {
      isValid: false,
      errors: [],
      warnings: [],
      suggestions: []
    };

    // 检查是否为空
    if (!token || token.trim() === '') {
      result.errors.push('Webhook令牌不能为空');
      result.suggestions.push('请生成一个新的Webhook令牌');
      return result;
    }

    const trimmedToken = token.trim();

    // 检查长度
    if (trimmedToken.length < this.TOKEN_MIN_LENGTH) {
      result.errors.push(`Webhook令牌长度不能少于${this.TOKEN_MIN_LENGTH}个字符`);
      result.suggestions.push('建议使用32位或更长的随机字符串');
      return result;
    }

    if (trimmedToken.length > this.TOKEN_MAX_LENGTH) {
      result.errors.push(`Webhook令牌长度不能超过${this.TOKEN_MAX_LENGTH}个字符`);
      return result;
    }

    // 检查字符格式
    if (!this.TOKEN_PATTERN.test(trimmedToken)) {
      result.errors.push('Webhook令牌只能包含字母、数字、下划线和连字符');
      result.suggestions.push('请使用字母数字组合，避免特殊字符');
      return result;
    }

    // 检查安全性
    if (this.isWeakToken(trimmedToken)) {
      result.warnings.push('当前令牌可能不够安全');
      result.suggestions.push('建议使用更复杂的随机字符串');
    }

    // 检查是否为常见弱令牌
    if (this.isCommonWeakToken(trimmedToken)) {
      result.errors.push('检测到常见的弱令牌，存在安全风险');
      result.suggestions.push('请生成一个新的随机令牌');
      return result;
    }

    result.isValid = true;
    return result;
  }

  /**
   * 验证Webhook URL
   */
  static validateWebhookUrl(url: string): WebhookValidationResult {
    const result: WebhookValidationResult = {
      isValid: false,
      errors: [],
      warnings: [],
      suggestions: []
    };

    // 检查是否为空
    if (!url || url.trim() === '') {
      result.errors.push('Webhook URL不能为空');
      return result;
    }

    const trimmedUrl = url.trim();

    // 检查URL格式
    if (!this.URL_PATTERN.test(trimmedUrl)) {
      result.errors.push('Webhook URL格式不正确');
      result.suggestions.push('URL应以http://或https://开头');
      return result;
    }

    try {
      const urlObj = new URL(trimmedUrl);

      // 检查协议
      if (urlObj.protocol !== 'https:' && urlObj.protocol !== 'http:') {
        result.errors.push('Webhook URL必须使用HTTP或HTTPS协议');
        return result;
      }

      // 建议使用HTTPS
      if (urlObj.protocol === 'http:') {
        result.warnings.push('建议使用HTTPS协议以确保安全性');
        result.suggestions.push('如果可能，请使用HTTPS版本的URL');
      }

      // 检查主机名
      if (!urlObj.hostname) {
        result.errors.push('Webhook URL缺少有效的主机名');
        return result;
      }

      // 检查是否为本地地址
      if (this.isLocalAddress(urlObj.hostname)) {
        result.warnings.push('检测到本地地址，可能无法从外部访问');
        result.suggestions.push('确保Notion服务能够访问此地址');
      }

      // 检查路径
      if (!urlObj.pathname.includes('/webhook/')) {
        result.warnings.push('URL路径可能不正确');
        result.suggestions.push('确保URL包含正确的webhook路径');
      }

      result.isValid = true;
      return result;

    } catch (error) {
      result.errors.push('URL解析失败：' + (error as Error).message);
      return result;
    }
  }

  /**
   * 验证完整的Webhook配置
   */
  static validateWebhookConfig(config: WebhookConfig): WebhookValidationResult {
    const result: WebhookValidationResult = {
      isValid: true,
      errors: [],
      warnings: [],
      suggestions: []
    };

    // 如果未启用，跳过验证
    if (!config.enabled) {
      return result;
    }

    // 验证令牌
    const tokenResult = this.validateToken(config.token);
    result.errors.push(...tokenResult.errors);
    result.warnings.push(...tokenResult.warnings);
    result.suggestions.push(...tokenResult.suggestions);

    if (!tokenResult.isValid) {
      result.isValid = false;
    }

    // 验证URL
    const urlResult = this.validateWebhookUrl(config.url);
    result.errors.push(...urlResult.errors);
    result.warnings.push(...urlResult.warnings);
    result.suggestions.push(...urlResult.suggestions);

    if (!urlResult.isValid) {
      result.isValid = false;
    }

    // 验证验证令牌（如果存在）
    if (config.verificationToken) {
      if (config.verificationToken.length < 8) {
        result.warnings.push('验证令牌长度较短，可能不够安全');
      }
    } else {
      result.suggestions.push('建议设置验证令牌以增强安全性');
    }

    return result;
  }

  /**
   * 生成安全的Webhook令牌
   */
  static generateSecureToken(length: number = 32): string {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
    let result = '';
    
    // 使用crypto API生成随机数（如果可用）
    if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
      const array = new Uint8Array(length);
      crypto.getRandomValues(array);
      
      for (let i = 0; i < length; i++) {
        result += chars[array[i] % chars.length];
      }
    } else {
      // 降级到Math.random()
      for (let i = 0; i < length; i++) {
        result += chars[Math.floor(Math.random() * chars.length)];
      }
    }
    
    return result;
  }

  /**
   * 构建Webhook URL
   */
  static buildWebhookUrl(baseUrl: string, token: string): string {
    // 移除末尾的斜杠
    const cleanBaseUrl = baseUrl.replace(/\/$/, '');
    
    // 构建完整URL
    return `${cleanBaseUrl}/wp-json/notion-to-wordpress/v1/webhook/${token}`;
  }

  /**
   * 解析Webhook URL获取令牌
   */
  static extractTokenFromUrl(url: string): string | null {
    try {
      const urlObj = new URL(url);
      const pathParts = urlObj.pathname.split('/');
      
      // 查找webhook路径后的令牌
      const webhookIndex = pathParts.indexOf('webhook');
      if (webhookIndex !== -1 && webhookIndex < pathParts.length - 1) {
        return pathParts[webhookIndex + 1];
      }
      
      return null;
    } catch (error) {
      return null;
    }
  }

  /**
   * 检查是否为弱令牌
   */
  private static isWeakToken(token: string): boolean {
    // 检查是否包含连续相同字符
    if (/(.)\1{3,}/.test(token)) {
      return true;
    }

    // 检查是否为简单模式
    if (/^(123|abc|aaa|111)/i.test(token)) {
      return true;
    }

    // 检查是否缺乏复杂性
    const hasLower = /[a-z]/.test(token);
    const hasUpper = /[A-Z]/.test(token);
    const hasNumber = /[0-9]/.test(token);
    const hasSpecial = /[_-]/.test(token);

    const complexity = [hasLower, hasUpper, hasNumber, hasSpecial].filter(Boolean).length;
    
    return complexity < 2;
  }

  /**
   * 检查是否为常见弱令牌
   */
  private static isCommonWeakToken(token: string): boolean {
    const commonWeakTokens = [
      'password',
      'secret',
      'token',
      'webhook',
      'notion',
      'wordpress',
      '123456',
      'abcdef',
      'test',
      'demo'
    ];

    const lowerToken = token.toLowerCase();
    
    return commonWeakTokens.some(weak => 
      lowerToken.includes(weak) || weak.includes(lowerToken)
    );
  }

  /**
   * 检查是否为本地地址
   */
  private static isLocalAddress(hostname: string): boolean {
    const localPatterns = [
      /^localhost$/i,
      /^127\./,
      /^192\.168\./,
      /^10\./,
      /^172\.(1[6-9]|2[0-9]|3[0-1])\./,
      /^::1$/,
      /^fe80:/i
    ];

    return localPatterns.some(pattern => pattern.test(hostname));
  }

  /**
   * 验证Webhook事件类型
   */
  static validateEventType(eventType: string): boolean {
    const validEventTypes = [
      'page.created',
      'page.updated',
      'page.deleted',
      'block.created',
      'block.updated',
      'block.deleted',
      'database.created',
      'database.updated',
      'database.deleted'
    ];

    return validEventTypes.includes(eventType) || 
           /^(page|block|database)\.(created|updated|deleted)$/.test(eventType);
  }

  /**
   * 获取Webhook配置建议
   */
  static getConfigurationSuggestions(): string[] {
    return [
      '使用HTTPS协议确保数据传输安全',
      '定期更换Webhook令牌以提高安全性',
      '启用增量同步以提高性能',
      '根据需要配置删除检测',
      '监控Webhook日志以及时发现问题',
      '设置适当的超时和重试机制',
      '使用强密码生成器创建令牌'
    ];
  }

  /**
   * 获取安全最佳实践
   */
  static getSecurityBestPractices(): string[] {
    return [
      '不要在URL中暴露敏感信息',
      '使用足够长度的随机令牌（建议32位以上）',
      '定期检查和更新Webhook配置',
      '监控异常的Webhook请求',
      '限制Webhook的访问频率',
      '使用HTTPS防止中间人攻击',
      '验证请求来源的合法性'
    ];
  }
}

export default WebhookValidator;
