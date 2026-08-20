<?php
/**
 * Security 单元测试
 *
 * 测试输入验证框架的核心逻辑：API Key、Database ID、Page ID 格式校验。
 * 这些方法为纯逻辑，不依赖外部 API，适合单元测试。
 *
 * @since 2.0.0
 * @package Notion_To_WordPress
 */

declare(strict_types=1);

namespace NTWP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NTWP\Core\Security;

/**
 * Security 类测试。
 */
final class SecurityTest extends TestCase {

    /**
     * 每个测试前重置全局桩状态。
     */
    protected function setUp(): void {
        $GLOBALS['__wp_post_meta'] = [];
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_posts'] = [];
    }

    /**
     * 测试：空 API Key 应校验失败。
     */
    public function test_validate_api_key_rejects_empty(): void {
        $result = Security::validate_notion_api_key('');

        $this->assertFalse($result['is_valid'], '空 API Key 应校验失败');
    }

    /**
     * 测试：过短的 API Key 应校验失败。
     */
    public function test_validate_api_key_rejects_too_short(): void {
        $result = Security::validate_notion_api_key('short_key');

        $this->assertFalse($result['is_valid'], '过短的 API Key 应校验失败');
    }

    /**
     * 测试：包含非法字符的 API Key 应校验失败。
     */
    public function test_validate_api_key_rejects_invalid_chars(): void {
        // 41 个字符但包含非法空格
        $key = str_repeat('a', 40) . ' ';
        $result = Security::validate_notion_api_key($key);

        $this->assertFalse($result['is_valid'], '含非法字符的 API Key 应校验失败');
    }

    /**
     * 测试：合法的 API Key 应校验通过。
     */
    public function test_validate_api_key_accepts_valid(): void {
        // Notion API Key 通常以 secret_ 开头，长度 40+
        $key = 'secret_' . str_repeat('a', 40);
        $result = Security::validate_notion_api_key($key);

        $this->assertTrue($result['is_valid'], '合法格式的 API Key 应校验通过');
    }

    /**
     * 测试：合法 Database ID（32 位十六进制）应校验通过。
     */
    public function test_validate_database_id_accepts_valid(): void {
        $db_id = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
        $result = Security::validate_database_id($db_id);

        $this->assertTrue($result['is_valid'], '32 位十六进制 Database ID 应校验通过');
    }

    /**
     * 测试：带连字符的 Database ID 应校验通过（自动清理连字符）。
     */
    public function test_validate_database_id_accepts_hyphenated(): void {
        $db_id = 'a1b2c3d4-e5f6-a1b2-c3d4-e5f6a1b2c3d4';
        $result = Security::validate_database_id($db_id);

        $this->assertTrue($result['is_valid'], '带连字符的 Database ID 应校验通过');
    }

    /**
     * 测试：非法 Database ID 应校验失败。
     */
    public function test_validate_database_id_rejects_invalid(): void {
        $result = Security::validate_database_id('not-a-valid-id');

        $this->assertFalse($result['is_valid'], '非法 Database ID 应校验失败');
    }

    /**
     * 测试：URL 安全性校验拒绝非 HTTP(S) 协议。
     */
    public function test_is_safe_url_rejects_non_http(): void {
        $this->assertFalse(Security::is_safe_url('javascript:alert(1)'), 'javascript: 协议应被拒绝');
        $this->assertFalse(Security::is_safe_url('file:///etc/passwd'), 'file: 协议应被拒绝');
    }

    /**
     * 测试：URL 安全性校验拒绝本地地址。
     */
    public function test_is_safe_url_rejects_localhost(): void {
        $this->assertFalse(Security::is_safe_url('http://localhost/test'), 'localhost 应被拒绝');
        $this->assertFalse(Security::is_safe_url('http://127.0.0.1/test'), '127.0.0.1 应被拒绝');
    }

    /**
     * 测试：URL 安全性校验接受正常 HTTPS URL。
     */
    public function test_is_safe_url_accepts_https(): void {
        $this->assertTrue(Security::is_safe_url('https://www.notion.so/page'), '正常 HTTPS URL 应被接受');
    }
}
