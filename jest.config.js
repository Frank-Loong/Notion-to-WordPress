module.exports = {
  // 测试环境
  testEnvironment: 'jsdom',
  
  // 根目录
  rootDir: '.',
  
  // 测试文件匹配模式
  testMatch: [
    '<rootDir>/src/**/__tests__/**/*.{ts,js}',
    '<rootDir>/src/**/*.{test,spec}.{ts,js}'
  ],
  
  // 模块文件扩展名
  moduleFileExtensions: ['ts', 'js', 'json'],
  
  // 转换配置
  transform: {
    '^.+\\.ts$': 'babel-jest'
  },
  
  // 模块名映射
  moduleNameMapping: {
    '^@/(.*)$': '<rootDir>/src/$1',
    '^@/admin/(.*)$': '<rootDir>/src/admin/$1',
    '^@/frontend/(.*)$': '<rootDir>/src/frontend/$1',
    '^@/shared/(.*)$': '<rootDir>/src/shared/$1',
    '^@/utils/(.*)$': '<rootDir>/src/shared/utils/$1',
    '^@/types/(.*)$': '<rootDir>/src/shared/types/$1',
    '^@/constants/(.*)$': '<rootDir>/src/shared/constants/$1',
    '^@/core/(.*)$': '<rootDir>/src/shared/core/$1'
  },
  
  // 设置文件
  setupFilesAfterEnv: ['<rootDir>/tests/setup.ts'],
  
  // 覆盖率配置
  collectCoverage: false,
  collectCoverageFrom: [
    'src/**/*.{ts,js}',
    '!src/**/*.d.ts',
    '!src/**/__tests__/**',
    '!src/**/*.test.{ts,js}',
    '!src/**/*.spec.{ts,js}'
  ],
  coverageDirectory: 'coverage',
  coverageReporters: ['text', 'lcov', 'html'],
  
  // 忽略模式
  testPathIgnorePatterns: [
    '/node_modules/',
    '/build/',
    '/assets/dist/'
  ],
  
  // 模块路径忽略模式
  modulePathIgnorePatterns: [
    '<rootDir>/build/',
    '<rootDir>/assets/dist/'
  ],
  
  // 全局变量
  globals: {
    'ts-jest': {
      tsconfig: 'tsconfig.json'
    }
  },
  
  // 清除模拟
  clearMocks: true,
  
  // 恢复模拟
  restoreMocks: true,
  
  // 详细输出
  verbose: true
};
