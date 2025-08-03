import React from 'react'
import { useSettingsStore } from '../../stores/settingsStore'
import { Card, CardContent, Button, Loading } from '../Common'

export const SettingsPanel: React.FC = () => {
  const { 
    settings, 
    isLoading, 
    isSaving, 
    updateSettings, 
    saveSettings,
    hasUnsavedChanges 
  } = useSettingsStore()

  const handleSave = async () => {
    const success = await saveSettings()
    if (success) {
      // 可以显示成功提示
      console.log('设置保存成功')
    }
  }

  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center py-12 space-y-4">
        <Loading variant="spinner" size="lg" />
        <p className="text-gray-600">正在加载设置...</p>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="notion-wp-header-section">
        <h2 className="text-xl font-semibold text-gray-800">
          ⚙️ 其他设置
        </h2>
        <p className="text-sm text-gray-600">
          配置插件的其他选项和高级功能
        </p>
      </div>

      <Card title="基本设置" shadow="md">
        <CardContent className="space-y-4">
          <div className="notion-wp-form-group">
            <label className="notion-wp-label">
              默认文章类型
            </label>
            <select
              className="notion-wp-select"
              value={settings?.post_type || 'post'}
              onChange={(e) => updateSettings({ post_type: e.target.value })}
            >
              <option value="post">文章 (Post)</option>
              <option value="page">页面 (Page)</option>
            </select>
          </div>

          <div className="notion-wp-form-group">
            <label className="notion-wp-label">
              默认文章状态
            </label>
            <select
              className="notion-wp-select"
              value={settings?.post_status || 'publish'}
              onChange={(e) => updateSettings({ post_status: e.target.value })}
            >
              <option value="publish">已发布</option>
              <option value="draft">草稿</option>
              <option value="private">私密</option>
            </select>
          </div>

          <div className="notion-wp-form-group">
            <label className="notion-wp-label">
              作者ID
            </label>
            <input
              type="number"
              className="notion-wp-input"
              value={settings?.author_id || 1}
              onChange={(e) => updateSettings({ author_id: parseInt(e.target.value) || 1 })}
              min="1"
            />
          </div>
        </CardContent>
      </Card>

      <Card title="自动同步" shadow="md">
        <CardContent className="space-y-4">
          <div className="notion-wp-form-group">
            <label className="notion-wp-checkbox-label">
              <input
                type="checkbox"
                className="notion-wp-checkbox"
                checked={settings?.enable_auto_sync || false}
                onChange={(e) => updateSettings({ enable_auto_sync: e.target.checked })}
              />
              启用自动同步
            </label>
            <p className="notion-wp-help-text">
              启用后将定期自动同步Notion数据库内容
            </p>
          </div>

          {settings?.enable_auto_sync && (
            <div className="notion-wp-form-group">
              <label className="notion-wp-label">
                同步间隔（秒）
              </label>
              <input
                type="number"
                className="notion-wp-input"
                value={settings?.sync_interval || 3600}
                onChange={(e) => updateSettings({ sync_interval: parseInt(e.target.value) || 3600 })}
                min="300"
                max="86400"
              />
              <p className="notion-wp-help-text">
                建议最小间隔为300秒（5分钟）
              </p>
            </div>
          )}
        </CardContent>
      </Card>

      <Card title="调试选项" shadow="md">
        <CardContent className="space-y-4">
          <div className="notion-wp-form-group">
            <label className="notion-wp-checkbox-label">
              <input
                type="checkbox"
                className="notion-wp-checkbox"
                checked={settings?.enable_debug || false}
                onChange={(e) => updateSettings({ enable_debug: e.target.checked })}
              />
              启用调试模式
            </label>
            <p className="notion-wp-help-text">
              启用后将记录详细的调试信息
            </p>
          </div>

          <div className="notion-wp-form-group">
            <label className="notion-wp-label">
              日志级别
            </label>
            <select
              className="notion-wp-select"
              value={settings?.log_level || 'info'}
              onChange={(e) => updateSettings({ log_level: e.target.value })}
            >
              <option value="debug">调试</option>
              <option value="info">信息</option>
              <option value="warning">警告</option>
              <option value="error">错误</option>
            </select>
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
            {isSaving ? '保存中...' : '保存设置'}
          </Button>
        </div>
      )}
    </div>
  )
}