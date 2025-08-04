/**
 * 验证工具函数
 */

export interface ValidationRule {
  required?: boolean;
  minLength?: number;
  maxLength?: number;
  pattern?: RegExp;
  email?: boolean;
  url?: boolean;
  custom?: (value: any) => boolean | string;
}

export interface ValidationResult {
  valid: boolean;
  errors: string[];
}

/**
 * 验证单个字段
 */
export function validateField(value: any, rules: ValidationRule): ValidationResult {
  const errors: string[] = [];
  
  // 必填验证
  if (rules.required && (!value || (typeof value === 'string' && value.trim() === ''))) {
    errors.push('此字段为必填项');
  }
  
  // 如果值为空且不是必填，跳过其他验证
  if (!value && !rules.required) {
    return { valid: true, errors: [] };
  }
  
  const stringValue = String(value);
  
  // 最小长度验证
  if (rules.minLength && stringValue.length < rules.minLength) {
    errors.push(`最少需要 ${rules.minLength} 个字符`);
  }
  
  // 最大长度验证
  if (rules.maxLength && stringValue.length > rules.maxLength) {
    errors.push(`最多允许 ${rules.maxLength} 个字符`);
  }
  
  // 正则表达式验证
  if (rules.pattern && !rules.pattern.test(stringValue)) {
    errors.push('格式不正确');
  }
  
  // 邮箱验证
  if (rules.email && !isValidEmail(stringValue)) {
    errors.push('请输入有效的邮箱地址');
  }
  
  // URL验证
  if (rules.url && !isValidUrl(stringValue)) {
    errors.push('请输入有效的URL地址');
  }
  
  // 自定义验证
  if (rules.custom) {
    const customResult = rules.custom(value);
    if (customResult !== true) {
      errors.push(typeof customResult === 'string' ? customResult : '验证失败');
    }
  }
  
  return {
    valid: errors.length === 0,
    errors
  };
}

/**
 * 验证表单数据
 */
export function validateForm(data: Record<string, any>, rules: Record<string, ValidationRule>): ValidationResult {
  const allErrors: string[] = [];
  
  for (const [field, fieldRules] of Object.entries(rules)) {
    const fieldResult = validateField(data[field], fieldRules);
    if (!fieldResult.valid) {
      allErrors.push(...fieldResult.errors.map(error => `${field}: ${error}`));
    }
  }
  
  return {
    valid: allErrors.length === 0,
    errors: allErrors
  };
}

/**
 * 邮箱格式验证
 */
export function isValidEmail(email: string): boolean {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return emailRegex.test(email);
}

/**
 * URL格式验证
 */
export function isValidUrl(url: string): boolean {
  try {
    new URL(url);
    return true;
  } catch {
    return false;
  }
}

/**
 * 手机号格式验证（中国大陆）
 */
export function isValidPhone(phone: string): boolean {
  const phoneRegex = /^1[3-9]\d{9}$/;
  return phoneRegex.test(phone);
}

/**
 * 身份证号格式验证（中国大陆）
 */
export function isValidIdCard(idCard: string): boolean {
  const idCardRegex = /(^\d{15}$)|(^\d{18}$)|(^\d{17}(\d|X|x)$)/;
  return idCardRegex.test(idCard);
}

/**
 * 密码强度验证
 */
export function validatePasswordStrength(password: string): {
  score: number;
  feedback: string[];
} {
  const feedback: string[] = [];
  let score = 0;
  
  if (password.length < 6) {
    feedback.push('密码长度至少6位');
  } else if (password.length < 8) {
    feedback.push('建议密码长度至少8位');
    score += 1;
  } else {
    score += 2;
  }
  
  if (/[a-z]/.test(password)) {
    score += 1;
  } else {
    feedback.push('建议包含小写字母');
  }
  
  if (/[A-Z]/.test(password)) {
    score += 1;
  } else {
    feedback.push('建议包含大写字母');
  }
  
  if (/\d/.test(password)) {
    score += 1;
  } else {
    feedback.push('建议包含数字');
  }
  
  if (/[^a-zA-Z0-9]/.test(password)) {
    score += 1;
  } else {
    feedback.push('建议包含特殊字符');
  }
  
  return { score, feedback };
}

/**
 * 数字范围验证
 */
export function isInRange(value: number, min: number, max: number): boolean {
  return value >= min && value <= max;
}

/**
 * 字符串长度验证
 */
export function isValidLength(str: string, min: number, max?: number): boolean {
  if (str.length < min) return false;
  if (max !== undefined && str.length > max) return false;
  return true;
}

/**
 * JSON格式验证
 */
export function isValidJson(str: string): boolean {
  try {
    JSON.parse(str);
    return true;
  } catch {
    return false;
  }
}

/**
 * 颜色值验证（支持hex、rgb、rgba）
 */
export function isValidColor(color: string): boolean {
  const hexRegex = /^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/;
  const rgbRegex = /^rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)$/;
  const rgbaRegex = /^rgba\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*(0|1|0?\.\d+)\s*\)$/;
  
  return hexRegex.test(color) || rgbRegex.test(color) || rgbaRegex.test(color);
}

/**
 * 文件类型验证
 */
export function isValidFileType(file: File, allowedTypes: string[]): boolean {
  return allowedTypes.includes(file.type);
}

/**
 * 文件大小验证
 */
export function isValidFileSize(file: File, maxSizeInMB: number): boolean {
  const maxSizeInBytes = maxSizeInMB * 1024 * 1024;
  return file.size <= maxSizeInBytes;
}
