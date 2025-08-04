module.exports = {
  root: true,
  
  // 解析器配置
  parser: '@typescript-eslint/parser',
  parserOptions: {
    ecmaVersion: 2020,
    sourceType: 'module',
    project: './tsconfig.json'
  },
  
  // 环境配置
  env: {
    browser: true,
    es6: true,
    node: true,
    jquery: true
  },
  
  // 全局变量
  globals: {
    wp: 'readonly',
    jQuery: 'readonly',
    $: 'readonly',
    ajaxurl: 'readonly',
    notionToWp: 'readonly'
  },
  
  // 扩展配置
  extends: [
    'eslint:recommended',
    '@typescript-eslint/recommended',
    '@typescript-eslint/recommended-requiring-type-checking',
    'prettier'
  ],
  
  // 插件
  plugins: [
    '@typescript-eslint',
    'prettier'
  ],
  
  // 规则配置
  rules: {
    // Prettier集成
    'prettier/prettier': 'error',
    
    // TypeScript规则
    '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
    '@typescript-eslint/explicit-function-return-type': 'off',
    '@typescript-eslint/explicit-module-boundary-types': 'off',
    '@typescript-eslint/no-explicit-any': 'warn',
    '@typescript-eslint/no-non-null-assertion': 'warn',
    '@typescript-eslint/prefer-nullish-coalescing': 'error',
    '@typescript-eslint/prefer-optional-chain': 'error',
    
    // 通用规则
    'no-console': process.env.NODE_ENV === 'production' ? 'warn' : 'off',
    'no-debugger': process.env.NODE_ENV === 'production' ? 'error' : 'off',
    'prefer-const': 'error',
    'no-var': 'error',
    'object-shorthand': 'error',
    'prefer-arrow-callback': 'error',
    
    // WordPress特定规则
    'no-undef': 'off', // WordPress全局变量较多
    'camelcase': 'off'  // WordPress使用下划线命名
  },
  
  // 忽略模式
  ignorePatterns: [
    'node_modules/',
    'assets/dist/',
    'build/',
    'vendor/',
    '*.min.js'
  ],
  
  // 覆盖配置
  overrides: [
    {
      files: ['*.js'],
      rules: {
        '@typescript-eslint/no-var-requires': 'off'
      }
    },
    {
      files: ['**/*.test.ts', '**/*.spec.ts'],
      env: {
        jest: true
      },
      rules: {
        '@typescript-eslint/no-explicit-any': 'off'
      }
    }
  ]
};
