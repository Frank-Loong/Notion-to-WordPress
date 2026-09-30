<?php
declare(strict_types=1);

use NTWP\Services\API;

class BatchFallbackApiStub extends API {
    public function __construct() {}

    public function batch_send_requests(array $endpoints, string $method = 'GET', array $data_array = [], int $max_retries = 2, int $base_delay = 1000): array {
        return [new Exception('simulated batch failure')];
    }

    public function get_block_children(string $block_id): array {
        return [
            [
                'id'           => 'child-1',
                'type'         => 'paragraph',
                'has_children' => false,
            ],
        ];
    }
}

final class BatchFallbackFailureApiStub extends BatchFallbackApiStub {
    public function get_block_children(string $block_id): array {
        throw new Exception('simulated fallback failure');
    }
}

final class ApiContentReliabilityTest extends PHPUnit\Framework\TestCase {
    public function test_unconfigured_api_client_can_be_constructed(): void {
        $api = new API('');

        $this->assertFalse($api->is_api_key_set());
    }

    public function test_unconfigured_client_rejects_network_requests_lazily(): void {
        $api = new API('');

        $this->expectException(InvalidArgumentException::class);
        $api->send_request('users/me');
    }

    public function test_batch_block_failure_falls_back_to_single_request(): void {
        $api = new BatchFallbackApiStub();

        $result = $api->batch_get_block_children(['page-1']);

        $this->assertSame('child-1', $result['page-1'][0]['id']);
    }

    public function test_batch_and_single_failure_is_not_converted_to_empty_content(): void {
        $api = new BatchFallbackFailureApiStub();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('批量请求和单页降级请求均失败');
        $api->batch_get_block_children(['page-1']);
    }
}
