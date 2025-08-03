import React from 'react'
import { useSettingsStore } from '../../stores/settingsStore'
import { Card, CardContent, Input, Button } from '../Common'

export const PerformanceConfig: React.FC = () => {
  const {
    settings,
    updateSettings,
    saveSettings,
    hasUnsavedChanges,
    isSaving
  } = useSettingsStore()

  const performanceConfig = settings?.performance_config || {
    enable_cache: true,
    cache_duration: 3600,
    batch_size: 20,
    max_execution_time: 300,
    memory_limit: '256M',
    enable_async_processing: true,
    enable_image_optimization: true,
    max_retries: 3,
    timeout: 30000,
    request_delay: 100
  }

  const handleConfigChange = (field: string, value: string | number | boolean) => {
    updateSettings({
      performance_config: {
        ...performanceConfig,
        [field]: value
      }
    })
  }

  const handleSave = async () => {
    const success = await saveSettings()
    if (success) {
      console.log('性能配置保存成功')
    }
  }

  return (
    <div className="space-y-6">
      <div className="notion-wp-header-section">
        <h2 className="text-xl font-semibold text-gray-800">
          ⚡ 性能配置
        </h2>
        <p className="text-sm text-gray-600">
          优化同步性能和资源使用
        </p>
      </div>

      <Card
        title="API 性能配置"
        subtitle="调整API请求的性能参数"
        shadow="md"
      >
        <CardContent className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Input
              label="缓存持续时间 (秒)"
              type="number"
              value={performanceConfig.cache_duration.toString()}
              onChange={(e) => handleConfigChange('cache_duration', parseInt(e.target.value) || 3600)}
              helperText="缓存数据的有效时间"
              min="300"
              max="86400"
            />

            <Input
              label="批处理大小"
              type="number"
              value={performanceConfig.batch_size.toString()}
              onChange={(e) => handleConfigChange('batch_size', parseInt(e.target.value) || 20)}
              helperText="批量处理的文章数量"
              min="5"
              max="100"
            />

            <Input
              label="最大执行时间 (秒)"
              type="number"
              value={performanceConfig.max_execution_time.toString()}
              onChange={(e) => handleConfigChange('max_execution_time', parseInt(e.target.value) || 300)}
              helperText="脚本最大执行时间"
              min="60"
              max="3600"
            />

            <Input
              label="内存限制"
              type="text"
              value={performanceConfig.memory_limit}
              onChange={(e) => handleConfigChange('memory_limit', e.target.value)}
              helperText="PHP内存限制 (如: 256M, 512M)"
              placeholder="256M"
            />

            <Input
              label="最大重试次数"
              type="number"
              value={performanceConfig.max_retries.toString()}
              onChange={(e) => handleConfigChange('max_retries', parseInt(e.target.value) || 3)}
              helperText="API请求失败时的重试次数"
              min="0"
              max="10"
            />

            <Input
              label="请求超时时间 (毫秒)"
              type="number"
              value={performanceConfig.timeout.toString()}
              onChange={(e) => handleConfigChange('timeout', parseInt(e.target.value) || 30000)}
              helperText="API请求的超时时间"
              min="5000"
              max="120000"
            />
          </div>

          <div className="pt-4 border-t space-y-4">
            <label className="flex items-center space-x-2">
              <input
                type="checkbox"
                checked={performanceConfig.enable_cache}
                onChange={(e) => handleConfigChange('enable_cache', e.target.checked)}
                className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <span className="text-sm font-medium text-gray-700">
                启用缓存
              </span>
            </label>
            <p className="text-sm text-gray-500 mt-1">
              启用缓存可以显著提高同步性能
            </p>

            <label className="flex items-center space-x-2">
              <input
                type="checkbox"
                checked={performanceConfig.enable_async_processing}
                onChange={(e) => handleConfigChange('enable_async_processing', e.target.checked)}
                className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <span className="text-sm font-medium text-gray-700">
                启用异步处理
              </span>
            </label>
            <p className="text-sm text-gray-500 mt-1">
              异步处理可以提高大批量同步的效率
            </p>

            <label className="flex items-center space-x-2">
              <input
                type="checkbox"
                checked={performanceConfig.enable_image_optimization}
                onChange={(e) => handleConfigChange('enable_image_optimization', e.target.checked)}
                className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <span className="text-sm font-medium text-gray-700">
                启用图片优化
              </span>
            </label>
            <p className="text-sm text-gray-500 mt-1">
              自动优化和压缩同步的图片文件
            </p>
          </div>
        </CardContent>
      </Card>

      <Card
        title="缓存配置"
        subtitle="配置缓存策略以提高性能"
        shadow="md"
      >
        <CardContent className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Input
              label="请求延迟 (毫秒)"
              type="number"
              value={performanceConfig.request_delay.toString()}
              onChange={(e) => handleConfigChange('request_delay', parseInt(e.target.value) || 100)}
              helperText="请求之间的延迟时间"
              min="0"
              max="5000"
            />
          </div>

          <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h4 className="text-sm font-medium text-blue-800 mb-2">
              💡 性能优化建议
            </h4>
            <ul className="text-sm text-blue-700 space-y-1">
              <li>• 增加API页面大小可以减少请求次数，但会增加内存使用</li>
              <li>• 适当的并发请求数可以提高同步速度，但过多可能导致API限制</li>
              <li>• 启用性能模式适合服务器性能较好的环境</li>
              <li>• 增加请求延迟可以避免触发API速率限制</li>
            </ul>
          </div>
        </CardContent>
      </Card>

      {hasUnsavedChanges && (
        <div className="flex items-center justify-between p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
          <p className="text-sm text-yellow-800">您有未保存的更改</p>
          <Button
            variant="primary"
            onClick={handleSave}
            loading={isSaving}
            disabled={isSaving}
          >
            {isSaving ? '保存中...' : '保存配置'}
          </Button>
        </div>
      )}
    </div>
  )
}