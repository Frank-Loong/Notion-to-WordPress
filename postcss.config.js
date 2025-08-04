module.exports = {
  plugins: [
    // 自动添加浏览器前缀
    require('autoprefixer')({
      overrideBrowserslist: [
        '> 1%',
        'last 2 versions',
        'not ie <= 11',
        'not dead'
      ],
      grid: true
    }),
    
    // 生产环境CSS优化
    ...(process.env.NODE_ENV === 'production' ? [
      require('cssnano')({
        preset: ['default', {
          // 保留重要注释
          discardComments: {
            removeAll: false
          },
          // 不合并规则（避免WordPress兼容性问题）
          mergeRules: false,
          // 保留z-index值
          zindex: false,
          // 不优化字体权重
          minifyFontValues: false
        }]
      })
    ] : [])
  ]
};
