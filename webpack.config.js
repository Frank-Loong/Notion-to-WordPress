const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const TerserPlugin = require('terser-webpack-plugin');
const CssMinimizerPlugin = require('css-minimizer-webpack-plugin');
const { CleanWebpackPlugin } = require('clean-webpack-plugin');
const ForkTsCheckerWebpackPlugin = require('fork-ts-checker-webpack-plugin');

const isDevelopment = process.env.NODE_ENV !== 'production';

module.exports = {
  // 模式
  mode: isDevelopment ? 'development' : 'production',

  // 入口文件
  entry: {
    admin: './src/admin/admin.ts',
    frontend: './src/frontend/frontend.ts',
    'sync-progress': './src/admin/components/SyncProgress.ts',
    'katex-mermaid': './src/frontend/components/MathRenderer.ts',
  },

  // 输出配置
  output: {
    path: path.resolve(__dirname, 'assets/dist'),
    // PHP enqueues these files by name. Keep public entrypoint filenames stable.
    filename: 'js/[name].js',
    chunkFilename: 'js/[name].chunk.js',
    // Resolve lazy chunks relative to the loaded runtime file so WordPress
    // installations remain functional even when the plugin directory is renamed.
    publicPath: 'auto',
    clean: true,
  },

  // 解析配置
  resolve: {
    extensions: ['.ts', '.js', '.json'],
    alias: {
      '@': path.resolve(__dirname, 'src'),
      '@/admin': path.resolve(__dirname, 'src/admin'),
      '@/frontend': path.resolve(__dirname, 'src/frontend'),
      '@/shared': path.resolve(__dirname, 'src/shared'),
      '@/utils': path.resolve(__dirname, 'src/shared/utils'),
      '@/types': path.resolve(__dirname, 'src/shared/types'),
      '@/constants': path.resolve(__dirname, 'src/shared/constants'),
      '@/core': path.resolve(__dirname, 'src/shared/core'),
    },
  },

  // 模块规则
  module: {
    rules: [
      // TypeScript处理
      {
        test: /\.ts$/,
        exclude: /node_modules/,
        use: [
          {
            loader: 'babel-loader',
            options: {
              presets: [
                [
                  '@babel/preset-env',
                  {
                    targets: {
                      browsers: ['> 1%', 'last 2 versions', 'not ie <= 11'],
                    },
                    modules: false,
                    useBuiltIns: 'usage',
                    corejs: 3,
                  },
                ],
                '@babel/preset-typescript',
              ],
              plugins: [
                '@babel/plugin-transform-class-properties',
                '@babel/plugin-transform-object-rest-spread',
              ],
            },
          },
        ],
      },

      // JavaScript处理（向后兼容）
      {
        test: /\.js$/,
        exclude: /node_modules/,
        use: {
          loader: 'babel-loader',
          options: {
            presets: [
              [
                '@babel/preset-env',
                {
                  targets: {
                    browsers: ['> 1%', 'last 2 versions', 'not ie <= 11'],
                  },
                  modules: false,
                  useBuiltIns: 'usage',
                  corejs: 3,
                },
              ],
            ],
          },
        },
      },

      // SCSS/CSS处理
      {
        test: /\.(scss|sass|css)$/,
        use: [
          isDevelopment ? 'style-loader' : MiniCssExtractPlugin.loader,
          {
            loader: 'css-loader',
            options: {
              sourceMap: isDevelopment,
              importLoaders: 2,
            },
          },
          {
            loader: 'postcss-loader',
            options: {
              sourceMap: isDevelopment,
              postcssOptions: {
                plugins: [
                  ['autoprefixer'],
                  ...(isDevelopment
                    ? []
                    : [['cssnano', { preset: 'default' }]]),
                ],
              },
            },
          },
          {
            loader: 'sass-loader',
            options: {
              sourceMap: isDevelopment,
              sassOptions: {
                includePaths: [path.resolve(__dirname, 'src/styles')],
              },
            },
          },
        ],
      },

      // 资源文件处理
      {
        test: /\.(png|jpe?g|gif|svg|woff2?|eot|ttf|otf)$/,
        type: 'asset',
        parser: {
          dataUrlCondition: {
            maxSize: 8 * 1024, // 8KB
          },
        },
        generator: {
          filename: 'assets/[name].[hash:8][ext]',
        },
      },
    ],
  },

  // 插件配置
  plugins: [
    // 清理输出目录
    new CleanWebpackPlugin(),

    // TypeScript类型检查
    new ForkTsCheckerWebpackPlugin({
      typescript: {
        configFile: path.resolve(__dirname, 'tsconfig.json'),
      },
    }),

    // CSS提取
    new MiniCssExtractPlugin({
      filename: 'css/[name].css',
      chunkFilename: 'css/[name].chunk.css',
    }),
  ],

  // 优化配置
  optimization: {
    chunkIds: 'named',
    minimize: !isDevelopment,
    minimizer: [
      new TerserPlugin({
        terserOptions: {
          compress: {
            drop_console: !isDevelopment,
          },
        },
      }),
      new CssMinimizerPlugin(),
    ],

    // 代码分割
    splitChunks: {
      chunks: 'all',
      cacheGroups: {
        // 第三方库
        vendor: {
          test: /[\\/]node_modules[\\/]/,
          name: 'vendors',
          chunks: 'all',
          priority: 10,
        },
        // 共享模块
        common: {
          name: 'common',
          minChunks: 2,
          chunks: 'all',
          priority: 5,
          reuseExistingChunk: true,
        },
      },
    },

    // 运行时代码分离
    runtimeChunk: {
      name: 'runtime',
    },
  },

  // 源码映射
  devtool: isDevelopment ? 'eval-source-map' : false,

  // 性能提示
  performance: {
    hints: isDevelopment ? false : 'warning',
    // The admin UI intentionally includes WordPress management modules in one
    // entrypoint. Keep a measured budget while avoiding a false-positive at 500 KiB.
    maxEntrypointSize: 550 * 1024,
    maxAssetSize: 550 * 1024,
  },

  // 统计信息
  stats: {
    colors: true,
    modules: false,
    children: false,
    chunks: false,
    chunkModules: false,
  },
};
