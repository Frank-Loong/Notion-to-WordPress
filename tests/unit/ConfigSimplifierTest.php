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
