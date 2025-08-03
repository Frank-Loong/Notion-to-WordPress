import React from 'react'
import { SyncDashboard } from '../Dashboard/SyncDashboard'
import { SettingsPanel } from '../Settings/SettingsPanel'
import { FieldMapping } from '../Settings/FieldMapping'
import { PerformanceConfig } from '../Settings/PerformanceConfig'
import { PerformanceMonitor, LogViewer } from '../Monitor'
import { DebugTools } from '../Debug/DebugTools'
import { HelpContent } from '../Help/HelpContent'
import { AboutAuthor } from '../About/AboutAuthor'
import { ComponentShowcase } from '../Examples/ComponentShowcase'

interface TabContentProps {
  activeTab: string
}

export const TabContent: React.FC<TabContentProps> = ({ activeTab }) => {
  const renderTabContent = () => {
    switch (activeTab) {
      case 'api-settings':
        return <SyncDashboard />
      case 'field-mapping':
        return <FieldMapping />
      case 'performance-config':
        return <PerformanceConfig />
      case 'performance':
        return <PerformanceMonitor />
      case 'logs':
        return <LogViewer />
      case 'other-settings':
        return <SettingsPanel />
      case 'debug':
        return <DebugTools />
      case 'help':
        return <HelpContent />
      case 'about-author':
        return <AboutAuthor />
      case 'components':
        return <ComponentShowcase />
      default:
        return <SyncDashboard />
    }
  }

  return (
    <div className="notion-wp-content">
      {renderTabContent()}
    </div>
  )
}