import React, { useState } from 'react'
import { useSettingsStore } from '../../stores/settingsStore'
import { Card, CardContent, Button, Input } from '../Common'
import type { CustomFieldMapping } from '../../types'

export const FieldMapping: React.FC = () => {
  const {
    settings,
    updateFieldMapping,
    addCustomFieldMapping,
    removeCustomFieldMapping,
    saveSettings,
    hasUnsavedChanges,
    isSaving
  } = useSettingsStore()

  const [newCustomField, setNewCustomField] = useState<CustomFieldMapping>({
    notion_field: '',
    wordpress_field: '',
    field_type: 'text'
  })

  const fieldMapping = settings?.field_mapping || {
    title_field: '',
    content_field: '',
    excerpt_field: '',
    featured_image_field: '',
    category_field: '',
    tag_field: '',
    custom_fields: []
  }
  const customFields = settings?.custom_field_mapping || []

  const handleBasicFieldChange = (field: string, value: string) => {
    updateFieldMapping({ [field]: value })
  }

  const handleAddCustomField = () => {
    if (newCustomField.notion_field && newCustomField.wordpress_field) {
      addCustomFieldMapping(newCustomField)
      setNewCustomField({
        notion_field: '',
        wordpress_field: '',
        field_type: 'text' as const
      })
    }
  }

  const handleSave = async () => {
    const success = await saveSettings()
    if (success) {
      console.log('字段映射保存成功')
    }
  }

  return (
    <div className="space-y-6">
      <div className="notion-wp-header-section">
        <h2 className="text-xl font-semibold text-gray-800">
          🔗 字段映射
        </h2>
        <p className="text-sm text-gray-600">
          配置Notion属性与WordPress字段的映射关系
        </p>
      </div>

      <Card
        title="基础字段映射"
        subtitle="配置Notion属性与WordPress标准字段的映射"
        shadow="md"
      >
        <CardContent className="space-y-4">
          <Input
            label="标题字段"
            value={fieldMapping.title_field || ''}
            onChange={(e) => handleBasicFieldChange('title_field', e.target.value)}
            placeholder="例如：Title, 标题"
            helperText="Notion中用作文章标题的属性名称"
          />

          <Input
            label="内容字段"
            value={fieldMapping.content_field || ''}
            onChange={(e) => handleBasicFieldChange('content_field', e.target.value)}
            placeholder="页面内容（自动获取）"
            disabled
            helperText="页面内容自动从Notion页面获取"
          />

          <Input
            label="摘要字段"
            value={fieldMapping.excerpt_field || ''}
            onChange={(e) => handleBasicFieldChange('excerpt_field', e.target.value)}
            placeholder="例如：Summary, 摘要, Excerpt"
          />

          <Input
            label="特色图片字段"
            value={fieldMapping.featured_image_field || ''}
            onChange={(e) => handleBasicFieldChange('featured_image_field', e.target.value)}
            placeholder="例如：Featured Image, 特色图片"
          />

          <Input
            label="分类字段"
            value={fieldMapping.category_field || ''}
            onChange={(e) => handleBasicFieldChange('category_field', e.target.value)}
            placeholder="例如：Categories, 分类, Category"
          />

          <Input
            label="标签字段"
            value={fieldMapping.tag_field || ''}
            onChange={(e) => handleBasicFieldChange('tag_field', e.target.value)}
            placeholder="例如：Tags, 标签, Tag"
          />
        </CardContent>
      </Card>

      <Card
        title="自定义字段映射"
        subtitle="将Notion属性映射到WordPress自定义字段"
        shadow="md"
      >
        <CardContent className="space-y-4">
          {customFields.length > 0 && (
            <div className="notion-wp-custom-fields-list">
              {customFields.map((field, index) => (
                <div key={index} className="notion-wp-custom-field-item">
                  <div className="notion-wp-custom-field-info">
                    <div className="notion-wp-custom-field-name">
                      {field.notion_field} → {field.wordpress_field}
                    </div>
                    <div className="notion-wp-custom-field-type">
                      类型: {field.field_type}
                    </div>
                  </div>
                  <button
                    className="notion-wp-button notion-wp-button--danger notion-wp-button--small"
                    onClick={() => removeCustomFieldMapping(field.notion_field)}
                  >
                    删除
                  </button>
                </div>
              ))}
            </div>
          )}

          <div className="notion-wp-add-custom-field">
            <div className="notion-wp-form-row">
              <div className="notion-wp-form-group">
                <label className="notion-wp-label">Notion字段名</label>
                <input
                  type="text"
                  className="notion-wp-input"
                  value={newCustomField.notion_field}
                  onChange={(e) => setNewCustomField(prev => ({
                    ...prev,
                    notion_field: e.target.value
                  }))}
                  placeholder="例如：Price, 价格"
                />
              </div>
              <div className="notion-wp-form-group">
                <label className="notion-wp-label">WordPress字段名</label>
                <input
                  type="text"
                  className="notion-wp-input"
                  value={newCustomField.wordpress_field}
                  onChange={(e) => setNewCustomField(prev => ({
                    ...prev,
                    wordpress_field: e.target.value
                  }))}
                  placeholder="例如：product_price"
                />
              </div>
              <div className="notion-wp-form-group">
                <label className="notion-wp-label">字段类型</label>
                <select
                  className="notion-wp-select"
                  value={newCustomField.field_type}
                  onChange={(e) => setNewCustomField(prev => ({
                    ...prev,
                    field_type: e.target.value as 'text' | 'number' | 'date' | 'boolean' | 'select' | 'multi_select'
                  }))}
                >
                  <option value="text">文本</option>
                  <option value="number">数字</option>
                  <option value="date">日期</option>
                  <option value="boolean">布尔值</option>
                  <option value="url">链接</option>
                  <option value="email">邮箱</option>
                </select>
              </div>
              <div className="notion-wp-form-group">
                <button
                  className="notion-wp-button notion-wp-button--primary"
                  onClick={handleAddCustomField}
                  disabled={!newCustomField.notion_field || !newCustomField.wordpress_field}
                >
                  添加
                </button>
              </div>
            </div>
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
            {isSaving ? '保存中...' : '保存映射'}
          </Button>
        </div>
      )}
    </div>
  )
}