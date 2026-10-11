import {
  isValidEmail,
  isValidUrl,
  validateField,
  validateForm,
} from './validation';

describe('validation utilities', () => {
  it('rejects whitespace-only required values', () => {
    expect(validateField('   ', { required: true })).toEqual({
      valid: false,
      errors: ['此字段为必填项'],
    });
  });

  it('accepts empty optional values without applying other rules', () => {
    expect(validateField('', { email: true })).toEqual({
      valid: true,
      errors: [],
    });
  });

  it('validates email addresses', () => {
    expect(isValidEmail('editor@example.com')).toBe(true);
    expect(isValidEmail('editor@')).toBe(false);
  });

  it('only accepts HTTP and HTTPS URLs', () => {
    expect(isValidUrl('https://www.notion.so/example')).toBe(true);
    expect(isValidUrl('http://127.0.0.1:8088')).toBe(true);
    expect(isValidUrl('javascript:alert(1)')).toBe(false);
    expect(isValidUrl('data:text/html,test')).toBe(false);
  });

  it('prefixes form errors with their field name', () => {
    expect(
      validateForm(
        { endpoint: 'javascript:alert(1)' },
        { endpoint: { url: true } }
      )
    ).toEqual({
      valid: false,
      errors: ['endpoint: 请输入有效的URL地址'],
    });
  });
});
