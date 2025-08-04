/**
 * 数据库视图渲染器 - 现代化TypeScript版本
 * 
 * 从原有Database_Renderer.php的前端渲染功能迁移，包括：
 * - 不同视图类型的渲染
 * - 记录格式化和显示
 * - 响应式布局
 * - 交互功能
 */

import { DatabaseRecord, DatabaseInfo } from '../managers/DatabaseRecordManager';

export type ViewType = 'table' | 'list' | 'gallery' | 'board' | 'calendar' | 'timeline';

export interface RenderOptions {
  viewType?: ViewType;
  showProperties?: string[];
  hideProperties?: string[];
  maxRecords?: number;
  enableInteraction?: boolean;
  responsive?: boolean;
}

export interface PropertyConfig {
  name: string;
  type: string;
  visible: boolean;
  width?: string;
  format?: string;
}

/**
 * 数据库视图渲染器类
 */
export class DatabaseViewRenderer {
  private static instance: DatabaseViewRenderer | null = null;

  constructor() {
    if (DatabaseViewRenderer.instance) {
      return DatabaseViewRenderer.instance;
    }
    
    DatabaseViewRenderer.instance = this;
  }

  /**
   * 获取单例实例
   */
  static getInstance(): DatabaseViewRenderer {
    if (!DatabaseViewRenderer.instance) {
      DatabaseViewRenderer.instance = new DatabaseViewRenderer();
    }
    return DatabaseViewRenderer.instance;
  }

  /**
   * 渲染数据库视图
   */
  renderDatabase(
    container: HTMLElement,
    databaseInfo: DatabaseInfo,
    records: DatabaseRecord[],
    options: RenderOptions = {}
  ): void {
    const finalOptions: Required<RenderOptions> = {
      viewType: 'table',
      showProperties: [],
      hideProperties: [],
      maxRecords: 50,
      enableInteraction: true,
      responsive: true,
      ...options
    };

    // 清空容器
    container.innerHTML = '';

    // 添加数据库标题
    if (databaseInfo.title) {
      const titleElement = this.createTitleElement(databaseInfo.title);
      container.appendChild(titleElement);
    }

    // 限制记录数量
    const limitedRecords = records.slice(0, finalOptions.maxRecords);

    // 根据视图类型渲染
    const viewElement = this.renderView(
      databaseInfo,
      limitedRecords,
      finalOptions
    );

    container.appendChild(viewElement);

    // 添加记录数量信息
    if (records.length > finalOptions.maxRecords) {
      const infoElement = this.createInfoElement(
        `显示 ${finalOptions.maxRecords} / ${records.length} 条记录`
      );
      container.appendChild(infoElement);
    }

    // 添加响应式类
    if (finalOptions.responsive) {
      container.classList.add('notion-database-responsive');
    }
  }

  /**
   * 渲染视图
   */
  private renderView(
    databaseInfo: DatabaseInfo,
    records: DatabaseRecord[],
    options: Required<RenderOptions>
  ): HTMLElement {
    switch (options.viewType) {
      case 'table':
        return this.renderTableView(databaseInfo, records, options);
      case 'list':
        return this.renderListView(databaseInfo, records, options);
      case 'gallery':
        return this.renderGalleryView(databaseInfo, records, options);
      case 'board':
        return this.renderBoardView(databaseInfo, records, options);
      case 'calendar':
        return this.renderCalendarView(databaseInfo, records, options);
      case 'timeline':
        return this.renderTimelineView(databaseInfo, records, options);
      default:
        return this.renderTableView(databaseInfo, records, options);
    }
  }

  /**
   * 渲染表格视图
   */
  private renderTableView(
    databaseInfo: DatabaseInfo,
    records: DatabaseRecord[],
    options: Required<RenderOptions>
  ): HTMLElement {
    const table = document.createElement('div');
    table.className = 'notion-database-table';

    // 获取可见属性
    const visibleProperties = this.getVisibleProperties(databaseInfo, options);

    // 创建表头
    const header = this.createTableHeader(visibleProperties);
    table.appendChild(header);

    // 创建表体
    const body = document.createElement('div');
    body.className = 'notion-table-body';

    records.forEach(record => {
      const row = this.createTableRow(record, visibleProperties, options);
      body.appendChild(row);
    });

    table.appendChild(body);

    return table;
  }

  /**
   * 渲染列表视图
   */
  private renderListView(
    databaseInfo: DatabaseInfo,
    records: DatabaseRecord[],
    options: Required<RenderOptions>
  ): HTMLElement {
    const list = document.createElement('div');
    list.className = 'notion-database-list';

    records.forEach(record => {
      const item = this.createListItem(record, databaseInfo, options);
      list.appendChild(item);
    });

    return list;
  }

  /**
   * 渲染画廊视图
   */
  private renderGalleryView(
    databaseInfo: DatabaseInfo,
    records: DatabaseRecord[],
    options: Required<RenderOptions>
  ): HTMLElement {
    const gallery = document.createElement('div');
    gallery.className = 'notion-database-gallery';

    records.forEach(record => {
      const card = this.createGalleryCard(record, databaseInfo, options);
      gallery.appendChild(card);
    });

    return gallery;
  }

  /**
   * 渲染看板视图
   */
  private renderBoardView(
    databaseInfo: DatabaseInfo,
    records: DatabaseRecord[],
    options: Required<RenderOptions>
  ): HTMLElement {
    const board = document.createElement('div');
    board.className = 'notion-database-board';

    // 按状态分组记录
    const groupedRecords = this.groupRecordsByStatus(records);

    Object.entries(groupedRecords).forEach(([status, statusRecords]) => {
      const column = this.createBoardColumn(status, statusRecords, databaseInfo, options);
      board.appendChild(column);
    });

    return board;
  }

  /**
   * 渲染日历视图
   */
  private renderCalendarView(
    _databaseInfo: DatabaseInfo,
    _records: DatabaseRecord[],
    _options: Required<RenderOptions>
  ): HTMLElement {
    const calendar = document.createElement('div');
    calendar.className = 'notion-database-calendar';
    calendar.innerHTML = '<div class="calendar-placeholder">日历视图开发中...</div>';
    return calendar;
  }

  /**
   * 渲染时间线视图
   */
  private renderTimelineView(
    _databaseInfo: DatabaseInfo,
    _records: DatabaseRecord[],
    _options: Required<RenderOptions>
  ): HTMLElement {
    const timeline = document.createElement('div');
    timeline.className = 'notion-database-timeline';
    timeline.innerHTML = '<div class="timeline-placeholder">时间线视图开发中...</div>';
    return timeline;
  }

  /**
   * 创建标题元素
   */
  private createTitleElement(title: string): HTMLElement {
    const titleElement = document.createElement('h3');
    titleElement.className = 'notion-database-title';
    titleElement.textContent = title;
    return titleElement;
  }

  /**
   * 创建信息元素
   */
  private createInfoElement(text: string): HTMLElement {
    const infoElement = document.createElement('div');
    infoElement.className = 'notion-database-info';
    infoElement.textContent = text;
    return infoElement;
  }

  /**
   * 获取可见属性
   */
  private getVisibleProperties(
    databaseInfo: DatabaseInfo,
    options: Required<RenderOptions>
  ): PropertyConfig[] {
    const properties: PropertyConfig[] = [];

    Object.entries(databaseInfo.properties).forEach(([name, config]) => {
      // 检查是否应该显示此属性
      const shouldShow = options.showProperties.length === 0 || 
                        options.showProperties.includes(name);
      const shouldHide = options.hideProperties.includes(name);

      if (shouldShow && !shouldHide) {
        properties.push({
          name,
          type: config.type || 'text',
          visible: true,
          width: this.getPropertyWidth(config.type),
          format: this.getPropertyFormat(config.type)
        });
      }
    });

    return properties;
  }

  /**
   * 创建表头
   */
  private createTableHeader(properties: PropertyConfig[]): HTMLElement {
    const header = document.createElement('div');
    header.className = 'notion-table-header';

    properties.forEach(property => {
      const cell = document.createElement('div');
      cell.className = 'notion-table-header-cell';
      cell.textContent = property.name;
      
      if (property.width) {
        cell.style.width = property.width;
      }
      
      header.appendChild(cell);
    });

    return header;
  }

  /**
   * 创建表格行
   */
  private createTableRow(
    record: DatabaseRecord,
    properties: PropertyConfig[],
    options: Required<RenderOptions>
  ): HTMLElement {
    const row = document.createElement('div');
    row.className = 'notion-table-row';
    row.dataset.recordId = record.id;

    properties.forEach(property => {
      const cell = this.createTableCell(record, property, options);
      row.appendChild(cell);
    });

    // 添加交互功能
    if (options.enableInteraction) {
      row.addEventListener('click', () => {
        this.handleRecordClick(record);
      });
      row.classList.add('notion-table-row-interactive');
    }

    return row;
  }

  /**
   * 创建表格单元格
   */
  private createTableCell(
    record: DatabaseRecord,
    property: PropertyConfig,
    _options: Required<RenderOptions>
  ): HTMLElement {
    const cell = document.createElement('div');
    cell.className = 'notion-table-cell';
    
    if (property.width) {
      cell.style.width = property.width;
    }

    const value = record.properties[property.name];
    const formattedValue = this.formatPropertyValue(value, property);
    
    if (typeof formattedValue === 'string') {
      cell.textContent = formattedValue;
    } else {
      cell.appendChild(formattedValue);
    }

    return cell;
  }

  /**
   * 创建列表项
   */
  private createListItem(
    record: DatabaseRecord,
    databaseInfo: DatabaseInfo,
    options: Required<RenderOptions>
  ): HTMLElement {
    const item = document.createElement('div');
    item.className = 'notion-list-item';
    item.dataset.recordId = record.id;

    // 添加图标
    if (record.icon) {
      const icon = this.createIcon(record.icon);
      item.appendChild(icon);
    }

    // 添加标题
    const title = this.createRecordTitle(record);
    item.appendChild(title);

    // 添加属性
    const properties = this.createRecordProperties(record, databaseInfo, options);
    item.appendChild(properties);

    // 添加交互功能
    if (options.enableInteraction) {
      item.addEventListener('click', () => {
        this.handleRecordClick(record);
      });
      item.classList.add('notion-list-item-interactive');
    }

    return item;
  }

  /**
   * 创建画廊卡片
   */
  private createGalleryCard(
    record: DatabaseRecord,
    databaseInfo: DatabaseInfo,
    options: Required<RenderOptions>
  ): HTMLElement {
    const card = document.createElement('div');
    card.className = 'notion-gallery-card';
    card.dataset.recordId = record.id;

    // 添加封面图片
    if (record.cover) {
      const cover = this.createCover(record.cover);
      card.appendChild(cover);
    }

    // 添加内容
    const content = document.createElement('div');
    content.className = 'notion-gallery-content';

    const title = this.createRecordTitle(record);
    content.appendChild(title);

    const properties = this.createRecordProperties(record, databaseInfo, options);
    content.appendChild(properties);

    card.appendChild(content);

    // 添加交互功能
    if (options.enableInteraction) {
      card.addEventListener('click', () => {
        this.handleRecordClick(record);
      });
      card.classList.add('notion-gallery-card-interactive');
    }

    return card;
  }

  /**
   * 创建看板列
   */
  private createBoardColumn(
    status: string,
    records: DatabaseRecord[],
    databaseInfo: DatabaseInfo,
    options: Required<RenderOptions>
  ): HTMLElement {
    const column = document.createElement('div');
    column.className = 'notion-board-column';

    // 列标题
    const header = document.createElement('div');
    header.className = 'notion-board-header';
    header.textContent = `${status} (${records.length})`;
    column.appendChild(header);

    // 记录列表
    const list = document.createElement('div');
    list.className = 'notion-board-list';

    records.forEach(record => {
      const card = this.createBoardCard(record, databaseInfo, options);
      list.appendChild(card);
    });

    column.appendChild(list);

    return column;
  }

  /**
   * 创建看板卡片
   */
  private createBoardCard(
    record: DatabaseRecord,
    databaseInfo: DatabaseInfo,
    options: Required<RenderOptions>
  ): HTMLElement {
    const card = document.createElement('div');
    card.className = 'notion-board-card';
    card.dataset.recordId = record.id;

    const title = this.createRecordTitle(record);
    card.appendChild(title);

    const properties = this.createRecordProperties(record, databaseInfo, options);
    card.appendChild(properties);

    // 添加交互功能
    if (options.enableInteraction) {
      card.addEventListener('click', () => {
        this.handleRecordClick(record);
      });
      card.classList.add('notion-board-card-interactive');
    }

    return card;
  }

  /**
   * 按状态分组记录
   */
  private groupRecordsByStatus(records: DatabaseRecord[]): Record<string, DatabaseRecord[]> {
    const grouped: Record<string, DatabaseRecord[]> = {};

    records.forEach(record => {
      // 查找状态属性
      let status = '未分类';
      
      Object.entries(record.properties).forEach(([name, value]) => {
        if (name.toLowerCase().includes('status') || name.toLowerCase().includes('状态')) {
          if (value && typeof value === 'object' && 'name' in value) {
            status = value.name;
          } else if (typeof value === 'string') {
            status = value;
          }
        }
      });

      if (!grouped[status]) {
        grouped[status] = [];
      }
      grouped[status].push(record);
    });

    return grouped;
  }

  /**
   * 创建图标
   */
  private createIcon(icon: any): HTMLElement {
    const iconElement = document.createElement('span');
    iconElement.className = 'notion-record-icon';

    if (icon.type === 'emoji' && icon.emoji) {
      iconElement.textContent = icon.emoji;
    } else if (icon.type === 'file' && icon.file?.url) {
      const img = document.createElement('img');
      img.src = icon.file.url;
      img.alt = 'Icon';
      iconElement.appendChild(img);
    } else if (icon.type === 'external' && icon.external?.url) {
      const img = document.createElement('img');
      img.src = icon.external.url;
      img.alt = 'Icon';
      iconElement.appendChild(img);
    }

    return iconElement;
  }

  /**
   * 创建封面
   */
  private createCover(cover: any): HTMLElement {
    const coverElement = document.createElement('div');
    coverElement.className = 'notion-record-cover';

    if (cover.type === 'file' && cover.file?.url) {
      const img = document.createElement('img');
      img.src = cover.file.url;
      img.alt = 'Cover';
      coverElement.appendChild(img);
    } else if (cover.type === 'external' && cover.external?.url) {
      const img = document.createElement('img');
      img.src = cover.external.url;
      img.alt = 'Cover';
      coverElement.appendChild(img);
    }

    return coverElement;
  }

  /**
   * 创建记录标题
   */
  private createRecordTitle(record: DatabaseRecord): HTMLElement {
    const titleElement = document.createElement('div');
    titleElement.className = 'notion-record-title';

    // 查找标题属性
    let title = '无标题';
    
    Object.entries(record.properties).forEach(([name, value]) => {
      if (name.toLowerCase().includes('title') || name.toLowerCase().includes('名称')) {
        if (value && typeof value === 'object' && 'title' in value && Array.isArray(value.title)) {
          title = value.title.map((t: any) => t.plain_text || '').join('');
        } else if (typeof value === 'string') {
          title = value;
        }
      }
    });

    titleElement.textContent = title;
    return titleElement;
  }

  /**
   * 创建记录属性
   */
  private createRecordProperties(
    record: DatabaseRecord,
    databaseInfo: DatabaseInfo,
    options: Required<RenderOptions>
  ): HTMLElement {
    const propertiesElement = document.createElement('div');
    propertiesElement.className = 'notion-record-properties';

    const visibleProperties = this.getVisibleProperties(databaseInfo, options);
    
    visibleProperties.slice(0, 3).forEach(property => { // 只显示前3个属性
      const value = record.properties[property.name];
      if (value) {
        const propertyElement = document.createElement('div');
        propertyElement.className = 'notion-record-property';
        
        const label = document.createElement('span');
        label.className = 'notion-property-label';
        label.textContent = property.name + ': ';
        
        const valueElement = document.createElement('span');
        valueElement.className = 'notion-property-value';
        
        const formattedValue = this.formatPropertyValue(value, property);
        if (typeof formattedValue === 'string') {
          valueElement.textContent = formattedValue;
        } else {
          valueElement.appendChild(formattedValue);
        }
        
        propertyElement.appendChild(label);
        propertyElement.appendChild(valueElement);
        propertiesElement.appendChild(propertyElement);
      }
    });

    return propertiesElement;
  }

  /**
   * 格式化属性值
   */
  private formatPropertyValue(value: any, property: PropertyConfig): string | HTMLElement {
    if (!value) return '';

    switch (property.type) {
      case 'title':
        if (Array.isArray(value.title)) {
          return value.title.map((t: any) => t.plain_text || '').join('');
        }
        return String(value);

      case 'rich_text':
        if (Array.isArray(value.rich_text)) {
          return value.rich_text.map((t: any) => t.plain_text || '').join('');
        }
        return String(value);

      case 'number':
        return value.number ? String(value.number) : '';

      case 'select':
        return value.select?.name || '';

      case 'multi_select':
        if (Array.isArray(value.multi_select)) {
          return value.multi_select.map((s: any) => s.name).join(', ');
        }
        return '';

      case 'date':
        if (value.date?.start) {
          return new Date(value.date.start).toLocaleDateString();
        }
        return '';

      case 'checkbox':
        return value.checkbox ? '✓' : '✗';

      case 'url':
        if (value.url) {
          const link = document.createElement('a');
          link.href = value.url;
          link.textContent = value.url;
          link.target = '_blank';
          return link;
        }
        return '';

      case 'email':
        if (value.email) {
          const link = document.createElement('a');
          link.href = `mailto:${value.email}`;
          link.textContent = value.email;
          return link;
        }
        return '';

      case 'phone_number':
        return value.phone_number || '';

      default:
        return String(value);
    }
  }

  /**
   * 获取属性宽度
   */
  private getPropertyWidth(type: string): string {
    switch (type) {
      case 'checkbox':
        return '60px';
      case 'date':
        return '120px';
      case 'number':
        return '100px';
      case 'select':
        return '150px';
      default:
        return 'auto';
    }
  }

  /**
   * 获取属性格式
   */
  private getPropertyFormat(type: string): string {
    return type;
  }

  /**
   * 处理记录点击
   */
  private handleRecordClick(record: DatabaseRecord): void {
    if (record.url) {
      window.open(record.url, '_blank');
    }
  }

  /**
   * 检测视图类型
   */
  static detectViewType(databaseInfo: DatabaseInfo): ViewType {
    // 根据数据库属性检测最适合的视图类型
    const properties = Object.keys(databaseInfo.properties);
    
    if (properties.some(p => p.toLowerCase().includes('status'))) {
      return 'board';
    }
    
    if (properties.some(p => p.toLowerCase().includes('date'))) {
      return 'calendar';
    }
    
    if (properties.length > 5) {
      return 'table';
    }
    
    return 'list';
  }
}

// 导出单例实例
export const databaseViewRenderer = DatabaseViewRenderer.getInstance();

export default DatabaseViewRenderer;
