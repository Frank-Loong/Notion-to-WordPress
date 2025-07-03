<?php
declare(strict_types=1);

/**
 * 将 Notion 块数组转换为 HTML 的专用类
 *
 * @since 1.1.0
 * @package Notion_To_WordPress
 */
class Notion_Block_Converter {

    private Notion_Pages $page_service;
    private Notion_API   $api;

    /**
     * 用于防止重复处理块
     * @var string[]
     */
    private array $processed_blocks = [];

    /**
     * 当前列表包装器状态（ul / ol / todo）
     */
    private ?string $list_wrapper = null;

    public function __construct( Notion_Pages $page_service, Notion_API $api ) {
        $this->page_service = $page_service;
        $this->api          = $api;
    }

    /**
     * 入口：递归遍历并输出 HTML
     */
    public function convert_blocks( array $blocks ): string {
        $html = '';

        foreach ( $blocks as $block ) {
            if ( in_array( $block['id'], $this->processed_blocks, true ) ) {
                continue;
            }
            $this->processed_blocks[] = $block['id'];

            $block_type = $block['type'] ?? '';
            $is_standard_list_item = in_array( $block_type, [ 'bulleted_list_item', 'numbered_list_item' ], true );
            $is_todo_item         = ( 'to_do' === $block_type );
            $is_list_item         = $is_standard_list_item || $is_todo_item;

            // ---- 列表包装器处理 ----
            if ( $is_standard_list_item ) {
                $current = ( 'bulleted_list_item' === $block_type ) ? 'ul' : 'ol';
                if ( $this->list_wrapper !== $current ) {
                    $html .= $this->close_list_wrapper();
                    $html .= '<' . $current . '>';
                    $this->list_wrapper = $current;
                }
            } elseif ( $is_todo_item ) {
                if ( 'todo' !== $this->list_wrapper ) {
                    $html .= $this->close_list_wrapper();
                    $html .= '<ul class="notion-to-do-list">';
                    $this->list_wrapper = 'todo';
                }
            } else {
                $html .= $this->close_list_wrapper();
            }

            // ---- 块转换 ----
            $html .= $this->convert_single_block( $block );
        }

        // 结束时关闭任何打开的列表
        $html .= $this->close_list_wrapper();

        return $html;
    }

    /**
     * 根据当前 wrapper 类型输出闭合标签并重置状态
     */
    private function close_list_wrapper(): string {
        if ( null === $this->list_wrapper ) {
            return '';
        }
        $tag = ( 'todo' === $this->list_wrapper ) ? 'ul' : $this->list_wrapper;
        $this->list_wrapper = null;
        return '</' . $tag . '>';
    }

    /**
     * 提供静态提取富文本方法，供 Notion_Pages 及本类内部共用。
     */
    public static function extract_rich_text_static( array $rich_text ): string {
        if ( empty( $rich_text ) ) {
            return '';
        }

        // 为保持不修改核心逻辑，暂复用 Notion_Pages 的实现
        // 注意：此处使用匿名临时对象以访问原实现
        static $extractor = null;
        if ( null === $extractor ) {
            $extractor = new class {
                // 复制原 Notion_Pages::extract_rich_text 内容（简化无依赖版）
                public function run( array $rich_text ): string {
                    $result = '';
                    foreach ( $rich_text as $text ) {
                        if ( isset( $text['type'] ) && $text['type'] === 'equation' ) {
                            $expr_raw = $text['equation']['expression'] ?? '';
                            $expr = str_replace( '\\', '\\\\', $expr_raw );
                            $content = '<span class="notion-equation notion-equation-inline">$' . $expr . '$</span>';
                        } else {
                            $content = isset( $text['plain_text'] ) ? esc_html( $text['plain_text'] ) : '';
                        }

                        if ( empty( $content ) ) {
                            continue;
                        }

                        $annotations = $text['annotations'] ?? [];
                        $href        = $text['href'] ?? '';

                        // 粗略移植格式化，仅处理常见样式
                        if ( ! empty( $annotations ) ) {
                            if ( ! empty( $annotations['bold'] ) ) {
                                $content = '<strong>' . $content . '</strong>';
                            }
                            if ( ! empty( $annotations['italic'] ) ) {
                                $content = '<em>' . $content . '</em>';
                            }
                            if ( ! empty( $annotations['strikethrough'] ) ) {
                                $content = '<del>' . $content . '</del>';
                            }
                            if ( ! empty( $annotations['underline'] ) ) {
                                $content = '<u>' . $content . '</u>';
                            }
                            if ( ! empty( $annotations['code'] ) ) {
                                $content = '<code>' . $content . '</code>';
                            }
                        }

                        if ( $href ) {
                            $content = '<a href="' . esc_url( $href ) . '">' . $content . '</a>';
                        }

                        $result .= $content;
                    }
                    return $result;
                }
            };
        }

        return $extractor->run( $rich_text );
    }

    /**
     * 将单个块转换为 HTML。
     * 当前阶段仍委托给 Notion_Pages 中的原 _convert_block_* 方法，后续将迁移。
     */
    public function convert_single_block( array $block ): string {
        $block_type = $block['type'] ?? '';
        $method     = '_convert_block_' . $block_type;

        if ( method_exists( $this, $method ) ) {
            return $this->{$method}( $block );
        }

        // 回退到旧实现
        return $this->page_service->convert_single_block( $block, $this->api );
    }

    /**
     * 递归转换子块（早期兼容版）
     */
    private function _convert_child_blocks_local( array $block ): string {
        if ( ! ( $block['has_children'] ?? false ) ) {
            return '';
        }

        $child_blocks = $block['children'] ?? $this->api->get_page_content( $block['id'] );
        return $child_blocks ? $this->convert_blocks( $child_blocks ) : '';
    }

    /* -------------------- 基础文本 / 标题 / 列表 -------------------- */

    private function _convert_block_paragraph( array $block ): string {
        $text = self::extract_rich_text_static( $block['paragraph']['rich_text'] ?? [] );
        if ( empty( trim( $text ) ) && ! ( $block['has_children'] ?? false ) ) {
            return '';
        }
        $html  = '<p>' . ( $text ?: '&nbsp;' ) . '</p>';
        $html .= $this->_convert_child_blocks_local( $block );
        return $html;
    }

    private function _convert_block_heading_1( array $block ): string {
        $text   = self::extract_rich_text_static( $block['heading_1']['rich_text'] ?? [] );
        $anchor = str_replace( '-', '', $block['id'] );
        return '<h1 id="' . esc_attr( $anchor ) . '">' . $text . '</h1>' . $this->_convert_child_blocks_local( $block );
    }

    private function _convert_block_heading_2( array $block ): string {
        $text   = self::extract_rich_text_static( $block['heading_2']['rich_text'] ?? [] );
        $anchor = str_replace( '-', '', $block['id'] );
        return '<h2 id="' . esc_attr( $anchor ) . '">' . $text . '</h2>' . $this->_convert_child_blocks_local( $block );
    }

    private function _convert_block_heading_3( array $block ): string {
        $text   = self::extract_rich_text_static( $block['heading_3']['rich_text'] ?? [] );
        $anchor = str_replace( '-', '', $block['id'] );
        return '<h3 id="' . esc_attr( $anchor ) . '">' . $text . '</h3>' . $this->_convert_child_blocks_local( $block );
    }

    private function _convert_block_bulleted_list_item( array $block ): string {
        $text = self::extract_rich_text_static( $block['bulleted_list_item']['rich_text'] ?? [] );
        return '<li>' . $text . $this->_convert_child_blocks_local( $block ) . '</li>';
    }

    private function _convert_block_numbered_list_item( array $block ): string {
        $text = self::extract_rich_text_static( $block['numbered_list_item']['rich_text'] ?? [] );
        return '<li>' . $text . $this->_convert_child_blocks_local( $block ) . '</li>';
    }

    private function _convert_block_to_do( array $block ): string {
        $text    = self::extract_rich_text_static( $block['to_do']['rich_text'] ?? [] );
        $checked = ( isset( $block['to_do']['checked'] ) && $block['to_do']['checked'] ) ? ' checked' : '';
        $html  = '<li class="notion-to-do">';
        $html .= '<input type="checkbox"' . $checked . ' disabled>'; // 只展示
        $html .= '<span class="notion-to-do-text">' . $text . '</span>';
        $html .= $this->_convert_child_blocks_local( $block );
        $html .= '</li>';
        return $html;
    }

    private function _convert_block_toggle( array $block ): string {
        $text = self::extract_rich_text_static( $block['toggle']['rich_text'] ?? [] );
        return '<details class="notion-toggle"><summary>' . $text . '</summary>' . $this->_convert_child_blocks_local( $block ) . '</details>';
    }

    private function _convert_block_child_page( array $block ): string {
        $title = $block['child_page']['title'] ?? '';
        return '<div class="notion-child-page"><span>' . esc_html( $title ) . '</span></div>';
    }

    /* -------------------- 引用 / 分割线 / 公式 -------------------- */

    private function _convert_block_quote( array $block ): string {
        $text = self::extract_rich_text_static( $block['quote']['rich_text'] ?? [] );
        return '<blockquote>' . $text . '</blockquote>';
    }

    private function _convert_block_divider( array $block ): string {
        return '<hr>';
    }

    private function _convert_block_equation( array $block ): string {
        $expression = str_replace( '\\', '\\\\', $block['equation']['expression'] ?? '' );
        return '<div class="notion-equation notion-equation-block">$$' . $expression . '$$</div>';
    }

    /* -------------------- 布局相关 -------------------- */

    private function _convert_block_column_list( array $block ): string {
        $html  = '<div class="notion-column-list">';
        $html .= $this->_convert_child_blocks_local( $block );
        $html .= '</div>';
        return $html;
    }

    private function _convert_block_column( array $block ): string {
        $ratio          = $block['column']['ratio'] ?? ( $block['column']['width_ratio'] ?? 1 );
        $width_percent  = max( 5, round( (float) $ratio * 100, 2 ) );
        $html  = '<div class="notion-column" style="flex:0 0 ' . esc_attr( $width_percent ) . '%;">';
        $html .= $this->_convert_child_blocks_local( $block );
        $html .= '</div>';
        return $html;
    }

    /* -------------------- 其他简单块 -------------------- */

    private function _convert_block_synced_block( array $block ): string {
        return $this->_convert_child_blocks_local( $block );
    }

    private function _convert_block_link_to_page( array $block ): string {
        $data = $block['link_to_page'] ?? [];
        $url   = '';
        $label = '';
        try {
            switch ( $data['type'] ?? '' ) {
                case 'page_id':
                    $page_id = $data['page_id'];
                    $page    = $this->api->get_page( $page_id );
                    $url     = $page['url'] ?? 'https://www.notion.so/' . str_replace( '-', '', $page_id );
                    if ( isset( $page['properties']['title']['title'][0]['plain_text'] ) ) {
                        $label = $page['properties']['title']['title'][0]['plain_text'];
                    }
                    break;
                case 'database_id':
                    $db_id = $data['database_id'];
                    $db    = $this->api->get_database( $db_id );
                    $url   = $db['url'] ?? 'https://www.notion.so/' . str_replace( '-', '', $db_id );
                    if ( isset( $db['title'][0]['plain_text'] ) ) {
                        $label = $db['title'][0]['plain_text'];
                    }
                    break;
                case 'url':
                    $url = $data['url'] ?? '';
                    break;
            }
        } catch ( Exception $e ) {
            // 忽略异常，回退使用默认链接
        }
        if ( empty( $url ) ) {
            return '<!-- Empty link_to_page -->';
        }
        if ( empty( $label ) ) {
            $label = parse_url( $url, PHP_URL_HOST ) ?: 'Notion Page';
        }
        return '<p class="notion-link-to-page"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a></p>';
    }

    /* -------------------- 代码 / 表格 / 卡片 -------------------- */

    private function _convert_block_code( array $block ): string {
        $language = strtolower( $block['code']['language'] ?? 'text' );

        // 特殊处理 Mermaid
        if ( 'mermaid' === $language ) {
            $raw_code = Notion_To_WordPress_Helper::get_text_from_rich_text( $block['code']['rich_text'] ?? [] );
            return '<pre class="mermaid">' . $raw_code . '</pre>';
        }

        $escaped_code = self::extract_rich_text_static( $block['code']['rich_text'] ?? [] );
        // 使用 pre/code 包裹，并附加语言类名，便于前端高亮库处理
        return '<pre><code class="language-' . esc_attr( $language ) . '">' . $escaped_code . '</code></pre>';
    }

    private function _convert_block_table( array $block ): string {
        // 优先使用 children，避免额外请求
        $rows = $block['children'] ?? $this->api->get_page_content( $block['id'] );
        if ( empty( $rows ) ) {
            return '<!-- Empty table -->';
        }
        $has_col_header = $block['table']['has_column_header'] ?? false;
        $has_row_header = $block['table']['has_row_header'] ?? false;

        $thead_html = '';
        $tbody_html = '';
        $is_first_row = true;

        foreach ( $rows as $row ) {
            $this->processed_blocks[] = $row['id']; // 避免重复
            $cells = $row['table_row']['cells'] ?? [];
            $row_html = '';
            foreach ( $cells as $idx => $cell_rich ) {
                $cell_text = self::extract_rich_text_static( $cell_rich );
                $use_th = false;
                if ( $has_col_header && $is_first_row ) {
                    $use_th = true;
                } elseif ( $has_row_header && 0 === $idx ) {
                    $use_th = true;
                }
                $tag = $use_th ? 'th' : 'td';
                $row_html .= '<' . $tag . '>' . $cell_text . '</' . $tag . '>';
            }
            $row_html = '<tr>' . $row_html . '</tr>';
            if ( $has_col_header && $is_first_row ) {
                $thead_html .= $row_html;
            } else {
                $tbody_html .= $row_html;
            }
            $is_first_row = false;
        }
        $thead = $thead_html ? '<thead>' . $thead_html . '</thead>' : '';
        $tbody = '<tbody>' . $tbody_html . '</tbody>';
        return '<table>' . $thead . $tbody . '</table>';
    }

    private function _convert_block_table_row( array $block ): string {
        $cells = $block['children'] ?? $this->api->get_page_content( $block['id'] );
        if ( empty( $cells ) ) {
            return '';
        }
        $html = '<tr>';
        foreach ( $cells as $cell ) {
            $cell_text = '';
            if ( isset( $cell['table_cell']['rich_text'] ) ) {
                $cell_text = self::extract_rich_text_static( $cell['table_cell']['rich_text'] );
            } else {
                $cell_text = self::extract_rich_text_static( $cell );
            }
            $html .= '<td>' . $cell_text . '</td>';
        }
        $html .= '</tr>';
        return $html;
    }

    private function _convert_block_callout( array $block ): string {
        $text = self::extract_rich_text_static( $block['callout']['rich_text'] ?? [] );
        $icon_html = '';
        if ( isset( $block['callout']['icon'] ) ) {
            $icon = $block['callout']['icon'];
            if ( isset( $icon['emoji'] ) ) {
                $icon_html = '<span class="notion-callout-icon">' . esc_html( $icon['emoji'] ) . '</span>';
            } elseif ( isset( $icon['external']['url'] ) ) {
                $icon_html = '<img src="' . esc_url( $icon['external']['url'] ) . '" class="notion-callout-icon" alt="icon">';
            }
        }
        return '<div class="notion-callout">' . $icon_html . '<div class="notion-callout-content">' . $text . '</div></div>';
    }

    private function _convert_block_bookmark( array $block ): string {
        $url = esc_url( $block['bookmark']['url'] ?? '' );
        $caption = self::extract_rich_text_static( $block['bookmark']['caption'] ?? [] );
        $caption_html = $caption ? '<div class="notion-bookmark-caption">' . esc_html( $caption ) . '</div>' : '';
        return '<div class="notion-bookmark"><a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' . $caption_html . '</div>';
    }

    /* -------------------- 媒体相关块 -------------------- */

    private function _convert_block_image( array $block ): string {
        $image_data = $block['image'] ?? [];
        $type       = $image_data['type'] ?? 'external';
        $url        = ( 'file' === $type ) ? ( $image_data['file']['url'] ?? '' ) : ( $image_data['external']['url'] ?? '' );
        $caption    = self::extract_rich_text_static( $image_data['caption'] ?? [] );

        if ( empty( $url ) ) {
            return '<!-- Empty image URL -->';
        }

        // 非 Notion 临时链接直接外链
        if ( ! $this->is_notion_temp_url( $url ) ) {
            return '<figure class="wp-block-image size-large"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $caption ) . '"/><figcaption>' . esc_html( $caption ) . '</figcaption></figure>';
        }

        // Notion 临时链接——尝试下载到媒体库
        $attachment_id = $this->download_and_insert_image( $url, $caption );
        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            $placeholder_id = 'ntw-img-' . substr( md5( $url ), 0, 10 );
            $notice         = __( '图片正在后台下载中...', 'notion-to-wordpress' );
            $figcaption     = $caption ? esc_html( $caption ) . ' - ' . $notice : $notice;
            return '<figure class="wp-block-image size-large notion-temp-image" data-ntw-url="' . esc_attr( $url ) . '" id="' . esc_attr( $placeholder_id ) . '"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $caption ) . '"><figcaption>' . $figcaption . '</figcaption></figure>';
        }

        $local_url = wp_get_attachment_url( $attachment_id );
        return '<figure class="wp-block-image size-large"><img src="' . esc_url( $local_url ) . '" alt="' . esc_attr( $caption ) . '"><figcaption>' . esc_html( $caption ) . '</figcaption></figure>';
    }

    private function _convert_block_file( array $block ): string {
        $file_data = $block['file'] ?? [];
        $type      = $file_data['type'] ?? 'external';
        $url       = ( 'file' === $type ) ? ( $file_data['file']['url'] ?? '' ) : ( $file_data['external']['url'] ?? '' );
        if ( empty( $url ) ) {
            return '<!-- Empty file block -->';
        }
        $caption   = self::extract_rich_text_static( $file_data['caption'] ?? [] );
        $file_name = basename( parse_url( $url, PHP_URL_PATH ) );
        $display   = $caption ?: $file_name;

        if ( ! $this->is_notion_temp_url( $url ) ) {
            return '<div class="file-download-box"><span class="file-download-name">' . esc_html( $display ) . '</span> <a class="file-download-btn" href="' . esc_url( $url ) . '" download target="_blank" rel="noopener">' . __( '下载附件', 'notion-to-wordpress' ) . '</a></div>';
        }

        $attachment_id = $this->download_and_insert_file( $url, $caption, $file_name );
        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            $placeholder_id = 'ntw-file-' . substr( md5( $url ), 0, 10 );
            return '<div class="file-download-box notion-temp-file" data-ntw-url="' . esc_attr( $url ) . '" id="' . esc_attr( $placeholder_id ) . '"><span class="file-download-name">' . esc_html( $display ) . '</span> <a class="file-download-btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . __( '下载附件（后台处理中...）', 'notion-to-wordpress' ) . '</a></div>';
        }
        $local_url = wp_get_attachment_url( $attachment_id );
        return '<div class="file-download-box"><span class="file-download-name">' . esc_html( $display ) . '</span> <a class="file-download-btn" href="' . esc_url( $local_url ) . '" download target="_blank" rel="noopener">' . __( '下载附件', 'notion-to-wordpress' ) . '</a></div>';
    }

    private function _convert_block_pdf( array $block ): string {
        $pdf_data = $block['pdf'] ?? [];
        $type     = $pdf_data['type'] ?? 'external';
        $url      = ( 'file' === $type ) ? ( $pdf_data['file']['url'] ?? '' ) : ( $pdf_data['external']['url'] ?? '' );
        if ( empty( $url ) ) {
            return '<!-- 无效的 PDF URL -->';
        }
        $caption = isset( $pdf_data['caption'] ) ? self::extract_rich_text_static( $pdf_data['caption'] ) : '';
        if ( ! $this->is_notion_temp_url( $url ) ) {
            return '<div class="notion-pdf"><embed src="' . esc_url( $url ) . '" type="application/pdf" width="100%" height="600px" /><p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . __( '下载 PDF', 'notion-to-wordpress' ) . '</a></p></div>';
        }
        $file_name     = basename( parse_url( $url, PHP_URL_PATH ) );
        $attachment_id = $this->download_and_insert_file( $url, $caption, $file_name );
        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return '<div class="notion-pdf notion-temp-pdf"><embed src="' . esc_url( $url ) . '" type="application/pdf" width="100%" height="600px" /><p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . __( '下载 PDF（外链，可能过期）', 'notion-to-wordpress' ) . '</a></p></div>';
        }
        $local_url = wp_get_attachment_url( $attachment_id );
        return '<div class="notion-pdf"><embed src="' . esc_url( $local_url ) . '" type="application/pdf" width="100%" height="600px" /><p><a href="' . esc_url( $local_url ) . '" target="_blank" rel="noopener" download>' . __( '下载 PDF', 'notion-to-wordpress' ) . '</a></p></div>';
    }

    private function _convert_block_video( array $block ): string {
        $video_data = $block['video'] ?? [];
        $type       = $video_data['type'] ?? '';
        $url        = ( 'external' === $type ) ? ( $video_data['external']['url'] ?? '' ) : ( $video_data['file']['url'] ?? '' );
        if ( empty( $url ) ) {
            return '<!-- 无效的视频URL -->';
        }
        // 处理常见平台
        if ( preg_match( '/(?:youtube\.com\/(?:[^\/]+\/.*\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $m ) ) {
            $vid = $m[1];
            return '<div class="notion-video notion-video-youtube"><iframe width="560" height="315" src="https://www.youtube.com/embed/' . esc_attr( $vid ) . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>';
        }
        if ( preg_match( '/vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/([^\/]*)\/videos\/|)(\d+)(?:|\/?)/', $url, $m ) ) {
            $vid = $m[2];
            return '<div class="notion-video notion-video-vimeo"><iframe src="https://player.vimeo.com/video/' . esc_attr( $vid ) . '" width="560" height="315" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe></div>';
        }
        if ( preg_match( '/bilibili\.com\/video\/([^\/\?&]+)/', $url, $m ) ) {
            $vid = $m[1];
            return '<div class="notion-video notion-video-bilibili"><iframe src="//player.bilibili.com/player.html?bvid=' . esc_attr( $vid ) . '&page=1" scrolling="no" border="0" frameborder="no" framespacing="0" allowfullscreen="true" width="560" height="315"></iframe></div>';
        }
        // 直接播放
        $ext = strtolower( pathinfo( $url, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, [ 'mp4', 'webm', 'ogg' ], true ) ) {
            return '<div class="notion-video"><video controls width="100%"><source src="' . esc_url( $url ) . '" type="video/' . esc_attr( $ext ) . '">' . __( '您的浏览器不支持视频标签。', 'notion-to-wordpress' ) . '</video></div>';
        }
        return '<div class="notion-video-link"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . __( '查看视频', 'notion-to-wordpress' ) . '</a></div>';
    }

    private function _convert_block_embed( array $block ): string {
        $url = $block['embed']['url'] ?? '';
        if ( empty( $url ) ) {
            return '<!-- 无效的嵌入URL -->';
        }
        // YouTube
        if ( preg_match( '/(?:youtube\.com\/(?:[^\/]+\/.*\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $m ) ) {
            $vid = $m[1];
            return '<div class="notion-embed notion-embed-youtube"><iframe width="560" height="315" src="https://www.youtube.com/embed/' . esc_attr( $vid ) . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>';
        }
        // Vimeo
        if ( preg_match( '/vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/([^\/]*)\/videos\/|)(\d+)(?:|\/?)/', $url, $m ) ) {
            $vid = $m[2];
            return '<div class="notion-embed notion-embed-vimeo"><iframe src="https://player.vimeo.com/video/' . esc_attr( $vid ) . '" width="560" height="315" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe></div>';
        }
        // Bilibili
        if ( preg_match( '/bilibili\.com\/video\/([^\/\?&]+)/', $url, $m ) ) {
            $vid = $m[1];
            return '<div class="notion-embed notion-embed-bilibili"><iframe src="//player.bilibili.com/player.html?bvid=' . esc_attr( $vid ) . '&page=1" scrolling="no" border="0" frameborder="no" framespacing="0" allowfullscreen="true" width="560" height="315"></iframe></div>';
        }
        // PDF
        if ( preg_match( '/\.pdf(\?|$)/i', $url ) ) {
            return '<div class="notion-embed notion-embed-pdf"><embed src="' . esc_url( $url ) . '" type="application/pdf" width="100%" height="600px" /><p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . __( '下载 PDF', 'notion-to-wordpress' ) . '</a></p></div>';
        }
        // 通用 iframe
        return '<div class="notion-embed"><iframe src="' . esc_url( $url ) . '" width="100%" height="500" frameborder="0" loading="lazy" referrerpolicy="no-referrer"></iframe></div>';
    }

    /* -------------------- 内部工具方法 -------------------- */

    private function is_notion_temp_url( string $url ): bool {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $host ) {
            return false;
        }
        $notion_hosts = [
            'secure.notion-static.com',
            'www.notion.so',
            'prod-files-secure.s3.us-west-2.amazonaws.com',
            'prod-files-secure.s3.amazonaws.com',
        ];
        foreach ( $notion_hosts as $nh ) {
            if ( str_contains( $host, $nh ) ) {
                return true;
            }
        }
        return false;
    }

    private function download_and_insert_image( string $url, string $caption = '' ) {
        $existing_id = $this->get_attachment_by_url( $url );
        if ( $existing_id > 0 ) {
            Notion_To_WordPress_Helper::debug_log( '找到已存在的图片附件: ' . $existing_id . ' 对应URL: ' . $url, 'Image', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );
            return $existing_id;
        }
        Notion_Download_Queue::push([
            'type'        => 'image',
            'url'         => $url,
            'post_id'     => 0,
            'is_featured' => false,
            'caption'     => $caption,
        ]);
        Notion_To_WordPress_Helper::debug_log( '图片入队下载: ' . $url, 'Image', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );
        return 0;
    }

    private function download_and_insert_file( string $url, string $caption = '', string $override_name = '' ) {
        $existing_id = $this->get_attachment_by_url( $url );
        if ( $existing_id > 0 ) {
            Notion_To_WordPress_Helper::debug_log( '找到已存在的文件附件: ' . $existing_id . ' 对应URL: ' . $url, 'File', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );
            return $existing_id;
        }
        Notion_Download_Queue::push([
            'type'    => 'file',
            'url'     => $url,
            'post_id' => 0,
            'caption' => $caption,
            'name'    => $override_name,
        ]);
        Notion_To_WordPress_Helper::debug_log( '文件入队下载: ' . $url . ( $override_name ? ' 指定文件名: ' . $override_name : '' ), 'File', Notion_To_WordPress_Helper::DEBUG_LEVEL_INFO );
        return 0;
    }

    private function get_attachment_by_url( string $search_url ) {
        $posts = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'     => '_notion_base_url',
                    'value'   => esc_url( $search_url ),
                    'compare' => '=',
                ],
            ],
            'fields' => 'ids',
        ]);
        if ( ! empty( $posts ) ) {
            return $posts[0];
        }
        $posts = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'     => '_notion_original_url',
                    'value'   => esc_url( $search_url ),
                    'compare' => '=',
                ],
            ],
            'fields' => 'ids',
        ]);
        if ( ! empty( $posts ) ) {
            return $posts[0];
        }
        global $wpdb;
        $attachment = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid=%s LIMIT 1", $search_url ) );
        if ( isset( $attachment[0] ) ) {
            return (int) $attachment[0];
        }
        return 0;
    }
} 