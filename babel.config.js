module.exports = {
  presets: [
    [
      '@babel/preset-env',
      {
        // 目标浏览器
        targets: {
          browsers: [
            '> 1%',
            'last 2 versions',
            'not ie <= 11',
            'not dead'
          ]
        },
        // 模块系统
        modules: false, // 保留ES模块，让Webpack处理
        // 按需引入polyfill
        useBuiltIns: 'usage',
        corejs: {
          version: 3,
          proposals: true
        },
        // 调试信息
        debug: process.env.NODE_ENV === 'development'
      }
    ],
    [
      '@babel/preset-typescript',
      {
        // 允许命名空间
        allowNamespaces: true,
        // 允许声明合并
        allowDeclareFields: true
      }
    ]
  ],
  
  plugins: [
    // 类属性支持
    '@babel/plugin-transform-class-properties',
    // 对象展开运算符
    '@babel/plugin-transform-object-rest-spread'
  ],
  
  // 环境特定配置
  env: {
    development: {
      plugins: [
        // 开发环境插件
      ]
    },
    production: {
      plugins: [
        // 生产环境插件
      ]
    },
    test: {
      presets: [
        [
          '@babel/preset-env',
          {
            targets: {
              node: 'current'
            }
          }
        ],
        '@babel/preset-typescript'
      ]
    }
  }
};
