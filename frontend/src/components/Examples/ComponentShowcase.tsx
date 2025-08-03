/**
 * 基础组件库展示页面
 * 用于演示和测试所有基础UI组件
 */

import React, { useState } from 'react'
import { 
  Button, 
  Input, 
  Card, 
  CardHeader, 
  CardContent, 
  CardFooter,
  Loading, 
  Modal, 
  ConfirmModal 
} from '../Common'

export const ComponentShowcase: React.FC = () => {
  const [isModalOpen, setIsModalOpen] = useState(false)
  const [isConfirmOpen, setIsConfirmOpen] = useState(false)
  const [inputValue, setInputValue] = useState('')
  const [loading, setLoading] = useState(false)

  const handleLoadingTest = () => {
    setLoading(true)
    setTimeout(() => setLoading(false), 2000)
  }

  return (
    <div className="space-y-8 p-6">
      <div className="notion-wp-header-section">
        <h1 className="text-2xl font-bold text-gray-800">
          🎨 基础组件库展示
        </h1>
        <p className="text-gray-600">
          展示所有可用的基础UI组件和它们的使用方式
        </p>
      </div>

      {/* 按钮组件展示 */}
      <Card title="按钮组件 (Button)" subtitle="支持多种变体、尺寸和状态">
        <CardContent>
          <div className="space-y-4">
            {/* 按钮变体 */}
            <div>
              <h4 className="text-sm font-medium text-gray-700 mb-2">变体样式</h4>
              <div className="flex flex-wrap gap-2">
                <Button variant="primary">主要按钮</Button>
                <Button variant="secondary">次要按钮</Button>
                <Button variant="success">成功按钮</Button>
                <Button variant="warning">警告按钮</Button>
                <Button variant="danger">危险按钮</Button>
                <Button variant="ghost">幽灵按钮</Button>
                <Button variant="outline">轮廓按钮</Button>
                <Button variant="link">链接按钮</Button>
              </div>
            </div>

            {/* 按钮尺寸 */}
            <div>
              <h4 className="text-sm font-medium text-gray-700 mb-2">尺寸大小</h4>
              <div className="flex items-center gap-2">
                <Button size="xs">超小</Button>
                <Button size="sm">小</Button>
                <Button size="md">中等</Button>
                <Button size="lg">大</Button>
                <Button size="xl">超大</Button>
              </div>
            </div>

            {/* 按钮状态 */}
            <div>
              <h4 className="text-sm font-medium text-gray-700 mb-2">状态</h4>
              <div className="flex gap-2">
                <Button loading={loading} onClick={handleLoadingTest}>
                  {loading ? '加载中...' : '点击测试加载'}
                </Button>
                <Button disabled>禁用按钮</Button>
                <Button fullWidth>全宽按钮</Button>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* 输入框组件展示 */}
      <Card title="输入框组件 (Input)" subtitle="支持多种类型、状态和验证">
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Input
              label="基础输入框"
              placeholder="请输入内容"
              value={inputValue}
              onChange={(e) => setInputValue(e.target.value)}
            />
            <Input
              label="带图标的输入框"
              placeholder="搜索..."
              leftIcon={<span>🔍</span>}
            />
            <Input
              label="成功状态"
              status="success"
              helperText="输入正确"
              defaultValue="正确的输入"
            />
            <Input
              label="错误状态"
              status="error"
              errorText="请输入有效的邮箱地址"
              defaultValue="invalid-email"
            />
            <Input
              label="加载状态"
              loading
              placeholder="验证中..."
            />
            <Input
              label="禁用状态"
              disabled
              placeholder="不可编辑"
            />
          </div>
        </CardContent>
      </Card>

      {/* 卡片组件展示 */}
      <Card title="卡片组件 (Card)" subtitle="灵活的内容容器">
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <Card shadow="sm" hover>
              <CardHeader title="简单卡片" subtitle="基础卡片示例" />
              <CardContent>
                <p className="text-gray-600">这是一个简单的卡片内容。</p>
              </CardContent>
            </Card>

            <Card shadow="md">
              <CardHeader 
                title="带操作的卡片" 
                actions={<Button size="sm" variant="outline">编辑</Button>}
              />
              <CardContent>
                <p className="text-gray-600">这个卡片有头部操作按钮。</p>
              </CardContent>
              <CardFooter>
                <div className="flex justify-end space-x-2">
                  <Button size="sm" variant="ghost">取消</Button>
                  <Button size="sm">保存</Button>
                </div>
              </CardFooter>
            </Card>

            <Card shadow="lg" border={false}>
              <CardContent padding="lg">
                <h3 className="text-lg font-semibold mb-2">无边框卡片</h3>
                <p className="text-gray-600">这个卡片没有边框，有大内边距。</p>
              </CardContent>
            </Card>
          </div>
        </CardContent>
      </Card>

      {/* 加载组件展示 */}
      <Card title="加载组件 (Loading)" subtitle="多种加载状态指示器">
        <CardContent>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-6">
            <div className="text-center">
              <Loading variant="spinner" size="md" />
              <p className="mt-2 text-sm text-gray-600">旋转加载器</p>
            </div>
            <div className="text-center">
              <Loading variant="dots" size="md" />
              <p className="mt-2 text-sm text-gray-600">点状加载器</p>
            </div>
            <div className="text-center">
              <Loading variant="pulse" size="md" />
              <p className="mt-2 text-sm text-gray-600">脉冲加载器</p>
            </div>
            <div className="text-center">
              <Loading variant="bars" size="md" />
              <p className="mt-2 text-sm text-gray-600">条状加载器</p>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* 模态框组件展示 */}
      <Card title="模态框组件 (Modal)" subtitle="弹出式对话框">
        <CardContent>
          <div className="flex gap-4">
            <Button onClick={() => setIsModalOpen(true)}>
              打开普通模态框
            </Button>
            <Button 
              variant="danger" 
              onClick={() => setIsConfirmOpen(true)}
            >
              打开确认对话框
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* 模态框实例 */}
      <Modal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        title="示例模态框"
        size="md"
        footer={
          <>
            <Button variant="ghost" onClick={() => setIsModalOpen(false)}>
              取消
            </Button>
            <Button onClick={() => setIsModalOpen(false)}>
              确认
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <p>这是一个示例模态框的内容。</p>
          <Input label="模态框中的输入框" placeholder="可以在模态框中使用其他组件" />
        </div>
      </Modal>

      <ConfirmModal
        isOpen={isConfirmOpen}
        onClose={() => setIsConfirmOpen(false)}
        onConfirm={() => alert('已确认操作！')}
        title="确认删除"
        message="您确定要删除这个项目吗？此操作不可撤销。"
        variant="danger"
        confirmText="删除"
        cancelText="取消"
      />
    </div>
  )
}
