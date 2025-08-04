<?php
declare(strict_types=1);

namespace NTWP\Services\Api;

/**
 * API接口定义
 *
 * 定义了Notion API服务的标准接口，确保不同实现的一致性
 *
 * @since      2.0.0-beta.1
 * @version    2.0.0-beta.1
 * @package    Notion_To_WordPress
 * @author     Frank-Loong
 * @license    GPL-3.0-or-later
 * @link       https://github.com/Frank-Loong/Notion-to-WordPress
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

interface ApiInterface {
    
    /**
     * 获取数据库页面
     *
     * @param string $database_id 数据库ID
     * @param array $filter 筛选条件
     * @return array 页面数据
     */
    public function get_database_pages(string $database_id, array $filter = []): array;
    
    /**
     * 获取单个页面
     *
     * @param string $page_id 页面ID
     * @return array 页面数据
     */
    public function get_page(string $page_id): array;
    
    /**
     * 获取页面内容
     *
     * @param string $page_id 页面ID
     * @param int $depth 递归深度
     * @param int $max_depth 最大深度
     * @return array 页面内容
     */
    public function get_page_content(string $page_id, int $depth = 0, int $max_depth = 3): array;
    
    /**
     * 获取块的子内容
     *
     * @param string $block_id 块ID
     * @return array 子内容
     */
    public function get_block_children(string $block_id): array;
    
    /**
     * 批量请求
     *
     * @param array $requests 请求数组
     * @param array $options 选项
     * @return array 批量响应
     */
    public function batch_request(array $requests, array $options = []): array;
    
    /**
     * 发送API请求
     *
     * @param string $endpoint API端点
     * @param string $method HTTP方法
     * @param array $data 请求数据
     * @return array API响应
     */
    public function send_request(string $endpoint, string $method = 'GET', array $data = []): array;
    
    /**
     * 批量发送请求
     *
     * @param array $endpoints 端点数组
     * @param string $method HTTP方法
     * @param array $data_array 数据数组
     * @param int $max_retries 最大重试次数
     * @param int $base_delay 基础延迟
     * @return array 响应数组
     */
    public function batch_send_requests(array $endpoints, string $method = 'GET', array $data_array = [], int $max_retries = 2, int $base_delay = 1000): array;
    
    /**
     * 批量获取块子内容
     *
     * @param array $block_ids 块ID数组
     * @return array 块内容数组
     */
    public function batch_get_block_children(array $block_ids): array;
    
    /**
     * 批量查询数据库
     *
     * @param array $database_ids 数据库ID数组
     * @param array $filters 筛选条件数组
     * @return array 查询结果数组
     */
    public function batch_query_databases(array $database_ids, array $filters = []): array;
    
    /**
     * 获取数据库信息
     *
     * @param string $database_id 数据库ID
     * @return array 数据库信息
     */
    public function get_database(string $database_id): array;
    
    /**
     * 批量获取数据库信息
     *
     * @param array $database_ids 数据库ID数组
     * @return array 数据库信息数组
     */
    public function batch_get_databases(array $database_ids): array;
}
