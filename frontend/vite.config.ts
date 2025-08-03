import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { resolve } from 'path'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  
  // 开发服务器配置
  server: {
    port: 3000,
    host: true,
    proxy: {
      // 代理WordPress AJAX请求到本地开发环境
      '/wp-admin/admin-ajax.php': {
        target: 'http://frankloong.local',
        changeOrigin: true,
        secure: false
      },
      // 代理WordPress API请求
      '/wp-json': {
        target: 'http://frankloong.local',
        changeOrigin: true,
        secure: false
      }
    }
  },

  // 构建配置
  build: {
    // 输出到WordPress插件的assets/dist目录
    outDir: '../assets/dist',
    emptyOutDir: true,
    
    // 生成manifest.json用于WordPress资源加载
    manifest: true,
    
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'index.html')
      },
      output: {
        // 资源文件命名
        entryFileNames: 'js/[name]-[hash].js',
        chunkFileNames: 'js/[name]-[hash].js',
        assetFileNames: (assetInfo) => {
          const info = assetInfo.name.split('.')
          const ext = info[info.length - 1]
          if (/\.(css)$/.test(assetInfo.name)) {
            return `css/[name]-[hash].${ext}`
          }
          return `assets/[name]-[hash].${ext}`
        }
      }
    },
    
    // 代码分割配置
    chunkSizeWarningLimit: 1000
  },

  // 路径解析
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
      '@components': resolve(__dirname, 'src/components'),
      '@hooks': resolve(__dirname, 'src/hooks'),
      '@services': resolve(__dirname, 'src/services'),
      '@stores': resolve(__dirname, 'src/stores'),
      '@types': resolve(__dirname, 'src/types'),
      '@utils': resolve(__dirname, 'src/utils')
    }
  },

  // 定义全局变量，兼容WordPress环境
  define: {
    'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'development')
  }
})