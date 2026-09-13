<?php
/**
 * Unit tests for Encryption class
 *
 * @package CWP\RcloneCWP\Tests\Unit
 */

namespace CWP\RcloneCWP\Tests\Unit;

use CWP\RcloneCWP\Encryption;
use PHPUnit\Framework\TestCase;

if (!defined('RCLONE_KEYFILE')) {
    define('RCLONE_KEYFILE', '/tmp/rclone_test_key.bin');
}

class EncryptionTest extends TestCase
{
    private $keyFile = '/tmp/encryption_test_key.bin';

    protected function setUp(): void
    {
        // Ensure clean state before each test
        if (file_exists($this->keyFile)) {
            unlink($this->keyFile);
        }
    }

    public function testEncryptDecryptRoundtrip()
    {
        $data = 'Test data for encryption';
        $key = random_bytes(32);
        $encryption = new Encryption($key);

        $encrypted = $encryption->encrypt($data);
        $decrypted = $encryption->decrypt($encrypted);

        $this->assertEquals($data, $decrypted);
    }

    public function testEncryptEmptyString()
    {
        $key = random_bytes(32);
        $encryption = new Encryption($key);
        $encrypted = $encryption->encrypt('');
        $decrypted = $encryption->decrypt($encrypted);

        $this->assertEquals('', $decrypted);
    }

    public function testEncryptSpecialCharacters()
    {
        $key = random_bytes(32);
        $encryption = new Encryption($key);
        $data = 'Special chars!@#$%^&*()_+-=[]{}|;:\'",.<>/?`~';
        $encrypted = $encryption->encrypt($data);
        $decrypted = $encryption->decrypt($encrypted);

        $this->assertEquals($data, $decrypted);
    }

    public function testKeyFileGenerationAndDetection()
    {
        // Test key generation
        $key = Encryption::generateKey($this->keyFile);
        $this->assertIsString($key);
        $this->assertEquals(32, strlen($key));

        // Test key file detection
        $this->assertTrue(Encryption::hasKeyFile($this->keyFile));

        // Test with different key file
        $keyFile2 = '/tmp/encryption_test_key2.bin';
        $key2 = Encryption::generateKey($keyFile2);
        $this->assertTrue(Encryption::hasKeyFile($keyFile2));
        $this->assertEquals(32, strlen($key2));
    }

    public function testErrorHandlingForInvalidKeys()
    {
        // Test with no key file
        $caught = null;
        try {
            $encryption = new Encryption(null, '/nonexistent/key.bin');
            $encryption->encrypt('test');
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught);
        $this->assertStringContainsString('not found', $caught->getMessage());

        // Test with invalid key length
        $invalidKeyFile = '/tmp/invalid_key.bin';
        file_put_contents($invalidKeyFile, 'invalidkey' . str_repeat('a', 25)); // 30 bytes
        $caught = null;
        try {
            $encryption = new Encryption(null, $invalidKeyFile);
            $encryption->encrypt('test');
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught);
        $this->assertStringContainsString('Invalid encryption key length', $caught->getMessage());
    }
}