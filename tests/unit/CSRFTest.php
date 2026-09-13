<?php
/**
 * Unit tests for CSRF class
 */
namespace CWP\RcloneCWP\Tests\Unit;

use CWP\RcloneCWP\CSRF;
use PHPUnit\Framework\TestCase;

class CSRFTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure session is active in CLI context
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        // Initialize $_SESSION if not set
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        // Clear any existing CSRF token
        unset($_SESSION[CSRF::SESSION_KEY]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION[CSRF::SESSION_KEY]);
    }

    public function testGenerateTokenReturnsNonEmptyString()
    {
        $token = CSRF::generateToken();

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        $this->assertEquals(64, strlen($token));
    }

    public function testValidateTokenAcceptsValidToken()
    {
        $token = CSRF::generateToken();
        $this->assertTrue(CSRF::validateToken($token));
    }

    public function testValidateTokenRejectsInvalidToken()
    {
        CSRF::generateToken();
        $this->assertFalse(CSRF::validateToken('invalid-token'));
    }

    public function testValidateTokenRejectsEmptyToken()
    {
        CSRF::generateToken();
        $this->assertFalse(CSRF::validateToken(''));
    }

    public function testValidateTokenRejectsNullToken()
    {
        CSRF::generateToken();
        $this->assertFalse(CSRF::validateToken(null));
    }

    public function testValidateTokenRejectsNonStringToken()
    {
        CSRF::generateToken();
        $this->assertFalse(CSRF::validateToken(123));
    }

    public function testTokenIsUniqueEachCall()
    {
        $token1 = CSRF::generateToken();
        $token2 = CSRF::generateToken();
        $this->assertNotEquals($token1, $token2);
    }

    public function testValidateAndConsumeTokenReturnsTrueForValidToken()
    {
        $token = CSRF::generateToken();
        $this->assertTrue(CSRF::validateAndConsumeToken($token));
    }

    public function testValidateAndConsumeTokenInvalidatesTokenAfterUse()
    {
        $token = CSRF::generateToken();
        CSRF::validateAndConsumeToken($token);
        $this->assertFalse(CSRF::validateToken($token));
    }

    public function testValidateAndConsumeTokenReturnsFalseForInvalidToken()
    {
        CSRF::generateToken();
        $this->assertFalse(CSRF::validateAndConsumeToken('invalid-token'));
    }

    public function testValidateTokenReturnsFalseWhenNoTokenInSession()
    {
        unset($_SESSION[CSRF::SESSION_KEY]);
        $this->assertFalse(CSRF::validateToken('any-token'));
    }
}
