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
}
