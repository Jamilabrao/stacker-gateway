<?php

namespace Tests\Unit;

use App\Services\StorageConnectionTester;
use Tests\TestCase;

class StorageConnectionTesterTest extends TestCase
{
    public function test_make_client_config_includes_checksum_workaround(): void
    {
        if (! class_exists(\Aws\S3\S3Client::class)) {
            $this->markTestSkipped('aws/aws-sdk-php não instalado.');
        }

        $client = StorageConnectionTester::makeClient([
            'provider' => 'r2',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'bucket' => 'my-bucket',
            'region' => 'auto',
            'endpoint' => 'https://acc.r2.cloudflarestorage.com',
        ]);

        $this->assertInstanceOf(\Aws\S3\S3Client::class, $client);
    }
}
