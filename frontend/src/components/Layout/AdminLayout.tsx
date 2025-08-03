import React, { useState } from 'react'
import { Sidebar } from './Sidebar'
import { TabContent } from './TabContent'

export interface TabType {
  id: string
  label: string
  icon: string
}

export const TABS: TabType[] = [
  { id: 'api-settings', label: '🔄 同步设置', icon: '🔄' },
  { id: 'field-mapping', label: '🔗 字段映射', icon: '🔗' },
  { id: 'performance-config', label: '⚡ 性能配置', icon: '⚡' },
  { id: 'performance', label: '📊 性能监控', icon: '📊' },
  { id: 'logs', label: '📋 日志查看', icon: '📋' },
  { id: 'other-settings', label: '⚙️ 其他设置', icon: '⚙️' },
  { id: 'debug', label: '🐞 调试工具', icon: '🐞' },
  { id: 'components', label: '🎨 组件展示', icon: '🎨' },
  { id: 'help', label: '📖 使用帮助', icon: '📖' },
  { id: 'about-author', label: '👨‍💻 关于作者', icon: '👨‍💻' },
]

export const AdminLayout: React.FC = () => {
  const [activeTab, setActiveTab] = useState<string>('api-settings')

  return (
    <div className="notion-wp-admin">
      <div className="notion-wp-header">
        <div className="notion-wp-header-content">
          <h1 className="wp-heading-inline">
            <span className="notion-wp-logo"></span>
            Notion to WordPress
          </h1>
          <div className="notion-wp-version">
            {window.wpNotionConfig?.version || '2.0.0-beta.1'}
          </div>
        </div>
      </div>

      <div className="notion-wp-layout">
        <Sidebar 
          activeTab={activeTab}
          onTabChange={setActiveTab}
          tabs={TABS}
        />
        <TabContent activeTab={activeTab} />
      </div>
    </div>
  )
}