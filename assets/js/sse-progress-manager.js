/**
 * SSE Progress Manager
 * 
 * 使用Server-Sent Events实现实时进度更新
 * 替代传统的AJAX轮询，提供更高性能的实时通信
 * 
 * @package Notion_To_WordPress
 * @subpackage Assets/JS
 * @since 2.0.0
 */

class SSEProgressManager {
    
    /**
     * 构造函数
     * 
     * @param {string} taskId 任务ID
     * @param {Object} options 配置选项
     */
    constructor(taskId, options = {}) {
        this.taskId = taskId;
        this.options = {
            // SSE端点URL
            sseUrl: options.sseUrl || ajaxurl,
            // 重连间隔（毫秒）
            reconnectInterval: options.reconnectInterval || 3000,
            // 最大重连次数
            maxReconnectAttempts: options.maxReconnectAttempts || 5,
            // 进度回调函数
            onProgress: options.onProgress || null,
            // 完成回调函数
            onComplete: options.onComplete || null,
            // 错误回调函数
            onError: options.onError || null,
            // 连接回调函数
            onConnect: options.onConnect || null,
            // 断开连接回调函数
            onDisconnect: options.onDisconnect || null,
            ...options
        };
        
        // 状态管理
        this.eventSource = null;
        this.isConnected = false;
        this.reconnectAttempts = 0;
        this.reconnectTimer = null;
        this.lastProgressData = null;
        
        // 绑定方法上下文
        this.handleOpen = this.handleOpen.bind(this);
        this.handleMessage = this.handleMessage.bind(this);
        this.handleError = this.handleError.bind(this);
        
        console.log('[SSE] Progress Manager initialized for task:', this.taskId);
    }
    
    /**
     * 开始SSE连接
     */
    start() {
        if (this.isConnected) {
            console.warn('[SSE] Already connected');
            return;
        }
        
        this.connect();
    }
    
    /**
     * 停止SSE连接
     */
    stop() {
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
        
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
        
        this.isConnected = false;
        this.reconnectAttempts = 0;
        
        if (this.options.onDisconnect) {
            this.options.onDisconnect();
        }
        
        console.log('[SSE] Connection stopped');
    }
    
    /**
     * 建立SSE连接
     */
    connect() {
        try {
            // 构建SSE URL
            const url = this.buildSSEUrl();
            
            console.log('[SSE] Connecting to:', url);
            
            // 创建EventSource
            this.eventSource = new EventSource(url);
            
            // 绑定事件处理器
            this.eventSource.addEventListener('open', this.handleOpen);
            this.eventSource.addEventListener('error', this.handleError);
            
            // 绑定自定义事件
            this.eventSource.addEventListener('connected', this.handleMessage);
            this.eventSource.addEventListener('progress', this.handleMessage);
            this.eventSource.addEventListener('completed', this.handleMessage);
            this.eventSource.addEventListener('failed', this.handleMessage);
            this.eventSource.addEventListener('not_found', this.handleMessage);
            this.eventSource.addEventListener('timeout', this.handleMessage);
            
        } catch (error) {
            console.error('[SSE] Connection failed:', error);
            this.handleConnectionError(error);
        }
    }
    
    /**
     * 构建SSE URL
     * 
     * @returns {string} SSE端点URL
     */
    buildSSEUrl() {
        const params = new URLSearchParams({
            action: 'notion_to_wordpress_sse_progress',
            task_id: this.taskId,
            nonce: notion_to_wordpress_ajax.nonce
        });
        
        return `${this.options.sseUrl}?${params.toString()}`;
    }
    
    /**
     * 处理连接打开事件
     * 
     * @param {Event} event 事件对象
     */
    handleOpen(event) {
        console.log('[SSE] Connection opened');
        this.isConnected = true;
        this.reconnectAttempts = 0;
        
        if (this.options.onConnect) {
            this.options.onConnect();
        }
    }
    
    /**
     * 处理消息事件
     * 
     * @param {MessageEvent} event 消息事件
     */
    handleMessage(event) {
        try {
            const data = JSON.parse(event.data);
            
            console.log(`[SSE] Received ${event.type}:`, data);
            
            switch (event.type) {
                case 'connected':
                    this.handleConnected(data);
                    break;
                    
                case 'progress':
                    this.handleProgress(data);
                    break;
                    
                case 'completed':
                    this.handleCompleted(data);
                    break;
                    
                case 'failed':
                    this.handleFailed(data);
                    break;
                    
                case 'not_found':
                    this.handleNotFound(data);
                    break;
                    
                case 'timeout':
                    this.handleTimeout(data);
                    break;
                    
                default:
                    console.warn('[SSE] Unknown event type:', event.type);
            }
            
        } catch (error) {
            console.error('[SSE] Failed to parse message:', error, event.data);
        }
    }
    
    /**
     * 处理连接确认
     * 
     * @param {Object} data 数据
     */
    handleConnected(data) {
        console.log('[SSE] Stream connected for task:', data.task_id);
    }
    
    /**
     * 处理进度更新
     * 
     * @param {Object} data 进度数据
     */
    handleProgress(data) {
        this.lastProgressData = data;
        
        if (this.options.onProgress) {
            this.options.onProgress(data);
        }
    }
    
    /**
     * 处理任务完成
     * 
     * @param {Object} data 完成数据
     */
    handleCompleted(data) {
        console.log('[SSE] Task completed:', data);
        
        if (this.options.onComplete) {
            this.options.onComplete(data.final_progress || this.lastProgressData);
        }
        
        this.stop();
    }
    
    /**
     * 处理任务失败
     * 
     * @param {Object} data 失败数据
     */
    handleFailed(data) {
        console.error('[SSE] Task failed:', data);
        
        if (this.options.onError) {
            this.options.onError(data.error_progress || data);
        }
        
        this.stop();
    }
    
    /**
     * 处理任务未找到
     * 
     * @param {Object} data 数据
     */
    handleNotFound(data) {
        console.warn('[SSE] Task not found:', data);
        
        if (this.options.onError) {
            this.options.onError(data);
        }
        
        this.stop();
    }
    
    /**
     * 处理连接超时
     * 
     * @param {Object} data 数据
     */
    handleTimeout(data) {
        console.warn('[SSE] Connection timeout:', data);
        this.attemptReconnect();
    }
    
    /**
     * 处理连接错误
     * 
     * @param {Event} event 错误事件
     */
    handleError(event) {
        console.error('[SSE] Connection error:', event);
        
        if (this.eventSource && this.eventSource.readyState === EventSource.CLOSED) {
            this.isConnected = false;
            this.attemptReconnect();
        }
    }
    
    /**
     * 处理连接错误
     * 
     * @param {Error} error 错误对象
     */
    handleConnectionError(error) {
        if (this.options.onError) {
            this.options.onError({
                message: '连接失败',
                error: error.message
            });
        }
        
        this.attemptReconnect();
    }
    
    /**
     * 尝试重新连接
     */
    attemptReconnect() {
        if (this.reconnectAttempts >= this.options.maxReconnectAttempts) {
            console.error('[SSE] Max reconnect attempts reached');
            
            if (this.options.onError) {
                this.options.onError({
                    message: '连接失败，已达到最大重试次数',
                    reconnectAttempts: this.reconnectAttempts
                });
            }
            
            this.stop();
            return;
        }
        
        this.reconnectAttempts++;
        
        console.log(`[SSE] Attempting reconnect ${this.reconnectAttempts}/${this.options.maxReconnectAttempts} in ${this.options.reconnectInterval}ms`);
        
        this.reconnectTimer = setTimeout(() => {
            this.connect();
        }, this.options.reconnectInterval);
    }
    
    /**
     * 获取连接状态
     * 
     * @returns {boolean} 是否已连接
     */
    isConnectedState() {
        return this.isConnected && this.eventSource && this.eventSource.readyState === EventSource.OPEN;
    }
    
    /**
     * 获取最后的进度数据
     * 
     * @returns {Object|null} 进度数据
     */
    getLastProgress() {
        return this.lastProgressData;
    }
}

// 导出到全局作用域
window.SSEProgressManager = SSEProgressManager;
