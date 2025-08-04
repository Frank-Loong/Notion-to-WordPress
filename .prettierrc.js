module.exports = {
  // 基础配置
  semi: true,                    // 使用分号
  trailingComma: 'es5',         // 尾随逗号
  singleQuote: true,            // 使用单引号
  doubleQuote: false,           // 不使用双引号
  
  // 缩进配置
  tabWidth: 2,                  // 缩进宽度
  useTabs: false,               // 使用空格而不是制表符
  
  // 换行配置
  printWidth: 80,               // 行宽
  endOfLine: 'lf',              // 换行符类型
  
  // 括号配置
  bracketSpacing: true,         // 对象字面量括号间距
  bracketSameLine: false,       // JSX括号不在同一行
  arrowParens: 'avoid',         // 箭头函数参数括号
  
  // 引号配置
  quoteProps: 'as-needed',      // 对象属性引号
  jsxSingleQuote: true,         // JSX使用单引号
  
  // 其他配置
  insertPragma: false,          // 不插入pragma
  requirePragma: false,         // 不需要pragma
  proseWrap: 'preserve',        // 保持markdown换行
  htmlWhitespaceSensitivity: 'css', // HTML空白敏感度
  
  // 文件特定配置
  overrides: [
    {
      files: '*.json',
      options: {
        printWidth: 120
      }
    },
    {
      files: '*.md',
      options: {
        printWidth: 100,
        proseWrap: 'always'
      }
    },
    {
      files: '*.scss',
      options: {
        singleQuote: false
      }
    }
  ]
};
