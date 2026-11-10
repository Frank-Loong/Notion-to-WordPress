<?php
/**
 * Config_Simplifier 后台选项契约测试。
 */

use NTWP\Utils\Config_Simplifier;

class ConfigSimplifierTest extends \PHPUnit\Framework\TestCase {

    public function test_custom_field_template_is_available(): void {
        $templates = Config_Simplifier::get_available_field_templates();

        $this->assertArrayHasKey( 'custom', $templates );
    }

    public function test_custom_template_preserves_manual_mapping(): void {
        $options = [
            'field_template' => 'custom',
            'field_mapping'  => [ 'title' => 'My title' ],
        ];

        $result = Config_Simplifier::apply_simplified_config( $options );

        $this->assertSame( [ 'title' => 'My title' ], $result['field_mapping'] );
    }

    /**
     * 回归：非 custom 模板（partial 默认是 mixed）不得覆盖用户手填的字段映射。
     * 修复前 array_merge( user, template ) 会把模板值后写，每次保存都静默改回默认值。
     */
    public function test_non_custom_template_preserves_user_mapping(): void {
        $options = [
            'field_template' => 'mixed',
            'field_mapping'  => [ 'title' => 'My Custom Title', 'status' => 'My Status' ],
        ];

        $result = Config_Simplifier::apply_simplified_config( $options );

        $this->assertSame( 'My Custom Title', $result['field_mapping']['title'] );
        $this->assertSame( 'My Status', $result['field_mapping']['status'] );
    }

    /**
     * 回归：非 custom 模板只补齐用户未提供的键。
     */
    public function test_non_custom_template_fills_only_missing_keys(): void {
        $options = [
            'field_template' => 'mixed',
            'field_mapping'  => [ 'title' => 'My Custom Title' ],
        ];

        $result = Config_Simplifier::apply_simplified_config( $options );

        $this->assertSame( 'My Custom Title', $result['field_mapping']['title'] );
        // 模板里的其它键应被补齐
        $this->assertSame( 'Status,状态', $result['field_mapping']['status'] );
        $this->assertSame( 'Date,日期', $result['field_mapping']['date'] );
    }

    /**
     * 回归：用户显式清空的键（'' 值）不得被模板回填。
     */
    public function test_non_custom_template_preserves_explicitly_cleared_key(): void {
        $options = [
            'field_template' => 'chinese',
            'field_mapping'  => [ 'title' => '' ],
        ];

        $result = Config_Simplifier::apply_simplified_config( $options );

        $this->assertSame( '', $result['field_mapping']['title'] );
    }

    public function test_custom_performance_level_is_available(): void {
        $levels = Config_Simplifier::get_available_performance_levels();

        $this->assertArrayHasKey( 'custom', $levels );
    }

    public function test_custom_performance_level_preserves_manual_values(): void {
        $options = [
            'performance_level'       => 'custom',
            'api_page_size'           => 150,
            'concurrent_requests'     => 7,
            'batch_size'              => 35,
            'log_buffer_size'         => 80,
            'enable_performance_mode' => 0,
        ];

        $this->assertSame(
            $options,
            Config_Simplifier::apply_simplified_config( $options )
        );
    }
}
