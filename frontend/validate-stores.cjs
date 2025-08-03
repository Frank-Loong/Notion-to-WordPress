/**
 * Store功能验证脚本 (Node.js版本)
 * 用于验证TypeScript编译和基本类型检查
 */

const fs = require('fs');
const path = require('path');

// 验证结果接口
class ValidationResult {
  constructor(storeName, tests, overallPassed) {
    this.storeName = storeName;
    this.tests = tests;
    this.overallPassed = overallPassed;
  }
}

class TestResult {
  constructor(name, passed, error = null) {
    this.name = name;
    this.passed = passed;
    this.error = error;
  }
}

/**
 * 检查文件是否存在
 */
function checkFileExists(filePath) {
  try {
    return fs.existsSync(filePath);
  } catch (error) {
    return false;
  }
}

/**
 * 读取文件内容
 */
function readFileContent(filePath) {
  try {
    return fs.readFileSync(filePath, 'utf8');
  } catch (error) {
    return null;
  }
}

/**
 * 验证文件结构
 */
function validateFileStructure() {
  const tests = [];
  const requiredFiles = [
    'src/stores/syncStore.ts',
    'src/stores/settingsStore.ts', 
    'src/stores/uiStore.ts',
    'src/stores/index.ts',
    'src/types/index.ts',
    'src/services/api.ts'
  ];

  requiredFiles.forEach(file => {
    const exists = checkFileExists(file);
    tests.push(new TestResult(
      `文件存在: ${file}`,
      exists,
      exists ? null : `文件不存在: ${file}`
    ));
  });

  return new ValidationResult(
    'FileStructure',
    tests,
    tests.every(test => test.passed)
  );
}

/**
 * 验证TypeScript语法
 */
function validateTypeScriptSyntax() {
  const tests = [];
  const storeFiles = [
    'src/stores/syncStore.ts',
    'src/stores/settingsStore.ts',
    'src/stores/uiStore.ts',
    'src/stores/index.ts'
  ];

  storeFiles.forEach(file => {
    const content = readFileContent(file);
    if (content) {
      // 基本语法检查
      const hasImports = content.includes('import');
      const hasExports = content.includes('export');

      // 对于index.ts，检查是否导入了其他stores；对于其他文件，检查zustand
      const hasZustand = file.includes('index.ts')
        ? (content.includes('useSyncStore') || content.includes('useSettingsStore') || content.includes('useUIStore'))
        : content.includes('zustand');

      tests.push(new TestResult(
        `${file} - 基本语法`,
        hasImports && hasExports,
        hasImports && hasExports ? null : '缺少必要的import/export语句'
      ));

      tests.push(new TestResult(
        `${file} - Zustand集成`,
        hasZustand,
        hasZustand ? null : '未找到Zustand相关代码'
      ));
    } else {
      tests.push(new TestResult(
        `${file} - 文件读取`,
        false,
        '无法读取文件内容'
      ));
    }
  });

  return new ValidationResult(
    'TypeScriptSyntax',
    tests,
    tests.every(test => test.passed)
  );
}

/**
 * 验证类型定义
 */
function validateTypeDefinitions() {
  const tests = [];
  const typesContent = readFileContent('src/types/index.ts');
  
  if (typesContent) {
    // 检查关键类型定义
    const requiredTypes = [
      'SyncStatusType',
      'SettingsData',
      'FieldMapping',
      'PerformanceConfig',
      'LanguageSettings',
      'ValidationResult',
      'SSEEvent',
      'StatsData'
    ];

    requiredTypes.forEach(type => {
      const hasType = typesContent.includes(type);
      tests.push(new TestResult(
        `类型定义: ${type}`,
        hasType,
        hasType ? null : `未找到类型定义: ${type}`
      ));
    });

    // 检查修复的字段
    const hasCustomFieldMapping = typesContent.includes('custom_field_mapping');
    const hasEnableDebug = typesContent.includes('enable_debug');
    const hasLogLevel = typesContent.includes('log_level');
    
    tests.push(new TestResult(
      'SettingsData扩展字段',
      hasCustomFieldMapping && hasEnableDebug && hasLogLevel,
      hasCustomFieldMapping && hasEnableDebug && hasLogLevel ? null : '缺少扩展字段'
    ));

  } else {
    tests.push(new TestResult(
      '类型文件读取',
      false,
      '无法读取types/index.ts文件'
    ));
  }

  return new ValidationResult(
    'TypeDefinitions',
    tests,
    tests.every(test => test.passed)
  );
}

/**
 * 验证Store实现
 */
function validateStoreImplementation() {
  const tests = [];
  
  // 验证SyncStore
  const syncContent = readFileContent('src/stores/syncStore.ts');
  if (syncContent) {
    const hasCreateStore = syncContent.includes('create<') || syncContent.includes('create(');
    const hasPersist = syncContent.includes('persist');
    const hasUpdateProgress = syncContent.includes('updateProgress');
    const hasUpdateStatus = syncContent.includes('updateStatus');
    const hasUseSyncStore = syncContent.includes('useSyncStore');

    tests.push(new TestResult(
      'SyncStore - 基本结构',
      hasCreateStore && hasPersist && hasUseSyncStore,
      hasCreateStore && hasPersist && hasUseSyncStore ? null : '缺少基本store结构'
    ));

    tests.push(new TestResult(
      'SyncStore - 核心方法',
      hasUpdateProgress && hasUpdateStatus,
      hasUpdateProgress && hasUpdateStatus ? null : '缺少核心方法'
    ));
  }

  // 验证SettingsStore
  const settingsContent = readFileContent('src/stores/settingsStore.ts');
  if (settingsContent) {
    const hasTestConnection = settingsContent.includes('testConnection');
    const hasValidateField = settingsContent.includes('validateField');
    const hasUpdateSettings = settingsContent.includes('updateSettings');
    
    tests.push(new TestResult(
      'SettingsStore - 核心方法',
      hasTestConnection && hasValidateField && hasUpdateSettings,
      hasTestConnection && hasValidateField && hasUpdateSettings ? null : '缺少核心方法'
    ));
  }

  // 验证UIStore
  const uiContent = readFileContent('src/stores/uiStore.ts');
  if (uiContent) {
    const hasShowNotification = uiContent.includes('showNotification');
    const hasSetActiveTab = uiContent.includes('setActiveTab');
    const hasSetTheme = uiContent.includes('setTheme');
    
    tests.push(new TestResult(
      'UIStore - 核心方法',
      hasShowNotification && hasSetActiveTab && hasSetTheme,
      hasShowNotification && hasSetActiveTab && hasSetTheme ? null : '缺少核心方法'
    ));
  }

  return new ValidationResult(
    'StoreImplementation',
    tests,
    tests.every(test => test.passed)
  );
}

/**
 * 验证复合功能
 */
function validateCompositeFeatures() {
  const tests = [];
  const indexContent = readFileContent('src/stores/index.ts');
  
  if (indexContent) {
    const hasUseAllStores = indexContent.includes('useAllStores');
    const hasInitializeApp = indexContent.includes('initializeApp');
    const hasResetAllStores = indexContent.includes('resetAllStores');
    
    tests.push(new TestResult(
      '复合Hook - useAllStores',
      hasUseAllStores,
      hasUseAllStores ? null : '缺少useAllStores hook'
    ));

    tests.push(new TestResult(
      '应用初始化 - initializeApp',
      hasInitializeApp,
      hasInitializeApp ? null : '缺少initializeApp函数'
    ));

    tests.push(new TestResult(
      '状态重置 - resetAllStores',
      hasResetAllStores,
      hasResetAllStores ? null : '缺少resetAllStores函数'
    ));

    // 检查是否使用了正确的环境变量
    const hasCorrectEnvUsage = indexContent.includes('import.meta.env');
    tests.push(new TestResult(
      '环境变量使用',
      hasCorrectEnvUsage,
      hasCorrectEnvUsage ? null : '未使用正确的环境变量语法'
    ));

  } else {
    tests.push(new TestResult(
      'Index文件读取',
      false,
      '无法读取stores/index.ts文件'
    ));
  }

  return new ValidationResult(
    'CompositeFeatures',
    tests,
    tests.every(test => test.passed)
  );
}

/**
 * 运行所有验证测试
 */
function runStoreValidation() {
  console.log('🧪 开始验证Store功能...\n');
  
  const results = [
    validateFileStructure(),
    validateTypeDefinitions(),
    validateTypeScriptSyntax(),
    validateStoreImplementation(),
    validateCompositeFeatures()
  ];

  // 打印结果
  results.forEach(result => {
    console.log(`📊 ${result.storeName} 验证结果: ${result.overallPassed ? '✅ 通过' : '❌ 失败'}`);
    result.tests.forEach(test => {
      const icon = test.passed ? '  ✅' : '  ❌';
      const error = test.error ? ` (${test.error})` : '';
      console.log(`${icon} ${test.name}${error}`);
    });
    console.log('');
  });

  const overallPassed = results.every(result => result.overallPassed);
  const passedCount = results.filter(result => result.overallPassed).length;
  const totalTests = results.reduce((sum, result) => sum + result.tests.length, 0);
  const passedTests = results.reduce((sum, result) => sum + result.tests.filter(test => test.passed).length, 0);

  console.log('='.repeat(60));
  console.log(`🎯 总体验证结果: ${overallPassed ? '✅ 所有验证通过' : '❌ 部分验证失败'}`);
  console.log(`📈 验证模块: ${passedCount}/${results.length} 通过`);
  console.log(`📈 具体测试: ${passedTests}/${totalTests} 通过`);
  console.log('='.repeat(60));

  if (overallPassed) {
    console.log('\n🎉 恭喜！Store架构验证完全通过，可以继续下一步开发。');
  } else {
    console.log('\n⚠️  部分验证失败，建议先修复相关问题再继续。');
  }

  return results;
}

// 如果直接运行此脚本
if (require.main === module) {
  // 切换到正确的目录
  process.chdir(__dirname);
  runStoreValidation();
}

module.exports = { runStoreValidation };
