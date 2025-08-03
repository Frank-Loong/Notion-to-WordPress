import React from 'react'
import type { TabType } from './AdminLayout'

interface SidebarProps {
  activeTab: string
  onTabChange: (tabId: string) => void
  tabs: TabType[]
}

export const Sidebar: React.FC<SidebarProps> = ({
  activeTab,
  onTabChange,
  tabs
}) => {
  return (
    <div className="notion-wp-sidebar">
      <div className="notion-wp-menu">
        {tabs.map((tab) => (
          <button
            key={tab.id}
            className={`notion-wp-menu-item ${activeTab === tab.id ? 'active' : ''}`}
            onClick={() => onTabChange(tab.id)}
          >
            {tab.label}
          </button>
        ))}
      </div>
    </div>
  )
}