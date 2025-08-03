import React from 'react'
import { useSyncStore } from '../../stores/syncStore'
import { Card, CardContent } from '../Common'
import { StatsCards } from './StatsCards'
import { SyncButtons } from './SyncButtons'
import { ProgressBar } from './ProgressBar'

export const SyncDashboard: React.FC = () => {
  const { isRunning, progress, status, currentStep, stats } = useSyncStore()

  return (
    <div className="space-y-6">
      <div className="notion-wp-header-section">
        <h2 className="text-xl font-semibold text-gray-800">
          🔄 同步设置
        </h2>
        <p className="text-sm text-gray-600">
          配置Notion API并管理数据库同步
        </p>
      </div>

      <StatsCards stats={stats || {
        imported_count: 0,
        published_count: 0,
        last_update: '',
        total_posts: 0,
        sync_errors: 0
      }} />

      {isRunning && (
        <Card title="同步进度" shadow="md">
          <CardContent>
            <ProgressBar
              progress={progress}
              status={status}
              currentStep={currentStep}
            />
          </CardContent>
        </Card>
      )}

      <Card title="同步操作" shadow="md">
        <CardContent>
          <SyncButtons disabled={isRunning} />
        </CardContent>
      </Card>
    </div>
  )
}