<?php
/**
 * 多数据库配置辅助类
 *
 * 统一管理“同步哪些 Notion 数据库”的配置读取与向后兼容迁移。
 * 主数据库（notion_database_id，由 React 前端管理）始终包含在内；
 * 附加数据库来自 notion_databases 选项（每行一个 ID，可选 "名称|ID" 格式）。
 *
 * @since 2.0.0
 */

namespace NTWP\Services;

class Database_Config {

    /**
     * 返回需要同步的数据库列表。
     *
     * 每个元素为 ['id' => string, 'name' => string, 'field_mapping' => array]。
     * 主数据库（notion_database_id）始终置于首位；notion_databases 中的附加库
     * 去重后追加。无任何配置时返回空数组。
     *
     * @since 2.0.0
     * @param array $options notion_to_wordpress_options
     * @return array
     */
    public static function get_configured_databases( array $options ): array {
        $databases = [];
        $seen      = [];

        // 1) 主数据库（React 前端管理，保持向后兼容）
        $primary_id = $options['notion_database_id'] ?? '';
        $primary_id = is_string( $primary_id ) ? trim( $primary_id ) : '';
        if ( $primary_id !== '' ) {
            $databases[] = [
                'id'            => $primary_id,
                'name'          => '',
                'field_mapping' => $options['field_mapping'] ?? [],
            ];
            $seen[ $primary_id ] = true;
        }

        // 2) 附加多数据库
        $extra = $options['notion_databases'] ?? [];
        if ( is_array( $extra ) ) {
            foreach ( $extra as $db ) {
                if ( is_array( $db ) ) {
                    $id = isset( $db['id'] ) ? (string) $db['id'] : '';
                } else {
                    $id = (string) $db;
                }
                $id = trim( $id );
                if ( $id === '' || isset( $seen[ $id ] ) ) {
                    continue;
                }
                $databases[] = [
                    'id'            => $id,
                    'name'          => is_array( $db ) ? ( $db['name'] ?? '' ) : '',
                    'field_mapping' => is_array( $db ) ? ( $db['field_mapping'] ?? [] ) : [],
                ];
                $seen[ $id ] = true;
            }
        }

        return $databases;
    }

    /**
     * 将表单提交的“每行一个数据库”文本解析为 notion_databases 结构。
     *
     * 支持两种格式（每行）：
     *   - 纯 ID：32 位十六进制（可含连字符）
     *   - 名称|ID：以首个 | 分隔，左侧为名称，右侧为 ID
     *
     * @since 2.0.0
     * @param string $raw 文本框原始内容
     * @return array
     */
    public static function parse_database_list( string $raw ): array {
        $databases = [];
        $lines     = preg_split( '/\r\n|\r|\n/', $raw );
        if ( ! is_array( $lines ) ) {
            return $databases;
        }

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            if ( strpos( $line, '|' ) !== false ) {
                list( $name, $id ) = array_map( 'trim', explode( '|', $line, 2 ) );
            } else {
                $name = '';
                $id   = $line;
            }
            $id = trim( $id );
            if ( $id === '' ) {
                continue;
            }
            $databases[] = [
                'id'   => $id,
                'name' => $name,
            ];
        }

        return $databases;
    }
}
