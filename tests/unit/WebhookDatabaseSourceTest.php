<?php
declare(strict_types=1);

use NTWP\Framework\Autoloader;
use NTWP\Handlers\Webhook;

final class WebhookDatabaseSourceTest extends \PHPUnit\Framework\TestCase {
    /** @var \ReflectionMethod */
    private $extract_database_id;

    /** @var Webhook */
    private $webhook;

    protected function setUp(): void {
        Autoloader::register( dirname( __DIR__, 2 ) );
        $reflection = new \ReflectionClass( Webhook::class );
        $this->webhook = $reflection->newInstanceWithoutConstructor();
        $this->extract_database_id = $reflection->getMethod( 'extract_database_id' );
    }

    public function test_reads_database_from_page_parent_payload(): void {
        $database_id = $this->extract_database_id->invoke(
            $this->webhook,
            [ 'event' => [ 'data' => [ 'parent' => [ 'database_id' => 'database-a' ] ] ] ]
        );

        $this->assertSame( 'database-a', $database_id );
    }

    public function test_reads_database_event_entity_id(): void {
        $database_id = $this->extract_database_id->invoke(
            $this->webhook,
            [ 'entity' => [ 'type' => 'database', 'id' => 'database-b' ] ]
        );

        $this->assertSame( 'database-b', $database_id );
    }

    public function test_returns_empty_value_when_source_is_absent(): void {
        $this->assertSame( '', $this->extract_database_id->invoke( $this->webhook, [] ) );
    }
}
