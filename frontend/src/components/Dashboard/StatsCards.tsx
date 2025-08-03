import React from 'react'

interface StatsCardsProps {
  stats: {
    imported_count: number
    published_count: number
    last_update: string
    total_posts?: number
    sync_errors?: number
  }
}

export const StatsCards: React.FC<StatsCardsProps> = ({ stats }) => {
  const cards = [
    {
      title: '已导入页面',
      value: stats.imported_count,
      icon: '📥',
      color: 'blue'
    },
    {
      title: '已发布文章',
      value: stats.published_count,
      icon: '✅',
      color: 'green'
    },
    {
      title: '数据库总数',
      value: stats.total_posts || 0,
      icon: '📊',
      color: 'purple'
    },
    {
      title: '同步错误',
      value: stats.sync_errors || 0,
      icon: '⚠️',
      color: stats.sync_errors ? 'red' : 'gray'
    }
  ]

  return (
    <div className="notion-wp-stats-grid">
      {cards.map((card, index) => (
        <div key={index} className={`notion-wp-stat-card notion-wp-stat-card--${card.color}`}>
          <div className="notion-wp-stat-icon">
            {card.icon}
          </div>
          <div className="notion-wp-stat-content">
            <div className="notion-wp-stat-value">
              {card.value.toLocaleString()}
            </div>
            <div className="notion-wp-stat-title">
              {card.title}
            </div>
          </div>
        </div>
      ))}
      
      {stats.last_update && (
        <div className="notion-wp-last-update">
          <span className="notion-wp-last-update-label">最后更新：</span>
          <span className="notion-wp-last-update-time">
            {new Date(stats.last_update).toLocaleString('zh-CN')}
          </span>
        </div>
      )}
    </div>
  )
}