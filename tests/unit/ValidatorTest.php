<?php
/**
 * Unit tests for Validator class
 */
namespace CWP\RcloneCWP\Tests\Unit;

use CWP\RcloneCWP\Validator;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    // ── string() ──────────────────────────────────────────

    public function testStringValid()
    {
        $this->assertSame('hello', Validator::string('hello'));
    }

    public function testStringTrimsWhitespace()
    {
        $this->assertSame('hello', Validator::string('  hello  '));
    }

    public function testStringEmptyAfterTrimReturnsNull()
    {
        $this->assertNull(Validator::string('   '));
    }

    public function testStringEmptyReturnsNull()
    {
        $this->assertNull(Validator::string(''));
    }

    public function testStringNonStringReturnsNull()
    {
        $this->assertNull(Validator::string(123));
        $this->assertNull(Validator::string(null));
        $this->assertNull(Validator::string([]));
        $this->assertNull(Validator::string(false));
    }

    public function testStringExceedsMaxLengthReturnsNull()
    {
        // 'a' (len 1) ≤ max 3 → returns 'a'
        $this->assertSame('a', Validator::string('a', 3));
        // 'abc' (len 3) = max 3 → returns 'abc'
        $this->assertSame('abc', Validator::string('abc', 3));
        // 'abcd' (len 4) > max 3 → returns null
        $this->assertNull(Validator::string('abcd', 3));
    }

    public function testStringDefaultMaxLength()
    {
        // Default max is 65535 — a string at exactly that length should pass
        $str = str_repeat('x', 65535);
        $this->assertSame($str, Validator::string($str));
        $this->assertNull(Validator::string(str_repeat('x', 65536)));
    }

    // ── int() ─────────────────────────────────────────────

    public function testIntValid()
    {
        $this->assertSame(42, Validator::int(42));
    }

    public function testIntFromStringNumeric()
    {
        $this->assertSame(42, Validator::int('42'));
    }

    public function testIntFloatString()
    {
        $this->assertSame(7, Validator::int('7.9')); // truncated by (int) cast
    }

    public function testIntNonNumericReturnsNull()
    {
        $this->assertNull(Validator::int('abc'));
        $this->assertNull(Validator::int(''));
        $this->assertNull(Validator::int([]));
        $this->assertNull(Validator::int(null));
    }

    public function testIntMinBoundary()
    {
        $this->assertSame(5, Validator::int(5, 5));
        $this->assertNull(Validator::int(4, 5));
    }

    public function testIntMaxBoundary()
    {
        $this->assertSame(10, Validator::int(10, null, 10));
        $this->assertNull(Validator::int(11, null, 10));
    }

    public function testIntRange()
    {
        $this->assertSame(5, Validator::int(5, 1, 10));
        $this->assertNull(Validator::int(0, 1, 10));
        $this->assertNull(Validator::int(11, 1, 10));
    }

    public function testIntNegative()
    {
        $this->assertSame(-3, Validator::int(-3));
        $this->assertSame(-3, Validator::int(-3, -10, 10));
        $this->assertNull(Validator::int(-3, -2, 10));
    }

    // ── enum() ────────────────────────────────────────────

    public function testEnumValid()
    {
        $this->assertSame('a', Validator::enum('a', ['a', 'b', 'c']));
    }

    public function testEnumInvalid()
    {
        $this->assertNull(Validator::enum('d', ['a', 'b', 'c']));
    }

    public function testEnumStrictType()
    {
        // strict comparison: int 1 !== string '1'
        $this->assertNull(Validator::enum(1, ['1']));
        $this->assertSame('1', Validator::enum('1', ['1']));
    }

    public function testEnumEmptyAllowed()
    {
        $this->assertNull(Validator::enum('a', []));
    }

    // ── remoteName() ──────────────────────────────────────

    public function testRemoteNameValid()
    {
        $this->assertSame('my_remote', Validator::remoteName('my_remote'));
    }

    public function testRemoteNameLeadingDashReturnsNull()
    {
        $this->assertNull(Validator::remoteName('-bad'));
    }

    public function testRemoteNameEmptyReturnsNull()
    {
        $this->assertNull(Validator::remoteName(''));
    }

    public function testRemoteNameNonStringReturnsNull()
    {
        $this->assertNull(Validator::remoteName(123));
        $this->assertNull(Validator::remoteName(null));
    }

    public function testRemoteNameOver255ReturnsNull()
    {
        $this->assertNull(Validator::remoteName(str_repeat('a', 256)));
    }

    public function testRemoteNameStripsInvalidChars()
    {
        $this->assertSame('abc123', Validator::remoteName('abc 123!@#'));
    }

    // ── bool() ────────────────────────────────────────────

    public function testBoolTrueValues()
    {
        $this->assertTrue(Validator::bool(true));
        $this->assertTrue(Validator::bool('true'));
        $this->assertTrue(Validator::bool('TRUE'));
        $this->assertTrue(Validator::bool('1'));
        $this->assertTrue(Validator::bool('yes'));
        $this->assertTrue(Validator::bool('YES'));
        $this->assertTrue(Validator::bool(1));
    }

    public function testBoolFalseValues()
    {
        $this->assertFalse(Validator::bool(false));
        $this->assertFalse(Validator::bool('false'));
        $this->assertFalse(Validator::bool('0'));
        $this->assertFalse(Validator::bool('no'));
        $this->assertFalse(Validator::bool(0));
    }

    public function testBoolDefault()
    {
        $this->assertTrue(Validator::bool([], true));
        $this->assertFalse(Validator::bool(null));
        $this->assertTrue(Validator::bool(null, true));
    }

    // ── email() ───────────────────────────────────────────

    public function testEmailValid()
    {
        $this->assertSame('user@example.com', Validator::email('user@example.com'));
    }

    public function testEmailTrimmed()
    {
        $this->assertSame('user@example.com', Validator::email('  user@example.com  '));
    }

    public function testEmailInvalid()
    {
        $this->assertNull(Validator::email('not-an-email'));
        $this->assertNull(Validator::email(''));
        $this->assertNull(Validator::email('@example.com'));
    }

    public function testEmailNonStringReturnsNull()
    {
        $this->assertNull(Validator::email(123));
        $this->assertNull(Validator::email(null));
    }

    public function testEmailOver254ReturnsNull()
    {
        // Exactly 254 chars: 243 x's + @example.com (11) = 254 — should pass strlen check
        $valid = str_repeat('x', 243) . '@example.com'; // 254 chars
        $this->assertNull(Validator::email($valid)); // filter_var rejects over-long

        // 255 chars: pre-trim strlen check rejects
        $this->assertNull(Validator::email(str_repeat('x', 244) . '@example.com'));
    }

    // ── date() ────────────────────────────────────────────

    public function testDateValid()
    {
        $this->assertSame('2024-01-15', Validator::date('2024-01-15'));
    }

    public function testDateInvalidFormat()
    {
        $this->assertNull(Validator::date('01-15-2024'));
        $this->assertNull(Validator::date('2024/01/15'));
        $this->assertNull(Validator::date('not-a-date'));
        $this->assertNull(Validator::date(''));
    }

    public function testDateNonStringReturnsNull()
    {
        $this->assertNull(Validator::date(null));
        $this->assertNull(Validator::date(123));
    }

    public function testDateLeapYear()
    {
        $this->assertSame('2024-02-29', Validator::date('2024-02-29'));
    }

    public function testDateInvalidCalendarDate()
    {
        // PHP strtotime normalizes Feb 30 → Mar 1
        $this->assertSame('2024-03-01', Validator::date('2024-02-30'));
        // Month 13 is genuinely invalid — strtotime rejects it
        $this->assertNull(Validator::date('2024-13-01'));
    }

    // ── cron() ────────────────────────────────────────────

    public function testCronValid()
    {
        $this->assertSame('*/5 * * * *', Validator::cron('*/5 * * * *'));
        $this->assertSame('0 9 * * 1-5', Validator::cron('0 9 * * 1-5'));
        $this->assertSame('30 14 28 2 *', Validator::cron('30 14 28 2 *'));
    }

    public function testCronNotEnoughParts()
    {
        $this->assertNull(Validator::cron('* * *'));
        $this->assertNull(Validator::cron('* * * * * *'));
    }

    public function testCronNonStringReturnsNull()
    {
        $this->assertNull(Validator::cron(''));
        $this->assertNull(Validator::cron(null));
    }

    public function testCronInvalidPart()
    {
        // Non-numeric, non-star, non-range value
        $this->assertNull(Validator::cron('abc * * * *'));
    }

    public function testCronRanges()
    {
        $this->assertSame('1-5 * * * *', Validator::cron('1-5 * * * *'));
    }

    // ── arrayStrings() ────────────────────────────────────

    public function testArrayStringsValid()
    {
        $result = Validator::arrayStrings(['a', 'b', 'c']);
        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testArrayStringsNonArrayReturnsNull()
    {
        $this->assertNull(Validator::arrayStrings('not array'));
        $this->assertNull(Validator::arrayStrings(null));
    }

    public function testArrayStringsNonStringElementReturnsNull()
    {
        $this->assertNull(Validator::arrayStrings([1, 2, 3]));
        $this->assertNull(Validator::arrayStrings(['a', 1, 'c']));
    }

    public function testArrayStringsMinBoundary()
    {
        $this->assertNull(Validator::arrayStrings([], 1));
        $this->assertSame(['a'], Validator::arrayStrings(['a'], 1));
    }

    public function testArrayStringsMaxBoundary()
    {
        $this->assertSame(['a'], Validator::arrayStrings(['a'], 0, 1));
        $this->assertNull(Validator::arrayStrings(['a', 'b'], 0, 1));
    }

    public function testArrayStringsReindexes()
    {
        // Should return re-indexed array (array_values)
        $input = [5 => 'a', 10 => 'b'];
        $this->assertSame(['a', 'b'], Validator::arrayStrings($input));
    }

    // ── isIpBlocked() ─────────────────────────────────────

    public function testIpBlockedLoopback()
    {
        $this->assertTrue(Validator::isIpBlocked('127.0.0.1'));
        $this->assertTrue(Validator::isIpBlocked('127.0.0.255'));
    }

    public function testIpBlockedLinkLocal()
    {
        $this->assertTrue(Validator::isIpBlocked('169.254.169.254'));
        $this->assertTrue(Validator::isIpBlocked('169.254.1.1'));
    }

    public function testIpBlockedZeroNet()
    {
        $this->assertTrue(Validator::isIpBlocked('0.0.0.0'));
    }

    public function testIpBlockedMulticast()
    {
        $this->assertTrue(Validator::isIpBlocked('224.0.0.1'));
        $this->assertTrue(Validator::isIpBlocked('239.255.255.255'));
    }

    public function testIpBlockedReserved()
    {
        $this->assertTrue(Validator::isIpBlocked('240.0.0.1'));
    }

    public function testIpBlockedPrivateRFC1918()
    {
        $this->assertTrue(Validator::isIpBlocked('10.0.0.1'));
        $this->assertTrue(Validator::isIpBlocked('172.16.0.1'));
        $this->assertTrue(Validator::isIpBlocked('192.168.1.1'));
        $this->assertTrue(Validator::isIpBlocked('100.64.0.1')); // CGNAT
        $this->assertTrue(Validator::isIpBlocked('198.18.0.1')); // Benchmarking
    }

    public function testIpAllowedPrivateWhenFlagSet()
    {
        $this->assertFalse(Validator::isIpBlocked('10.0.0.1', true));
        $this->assertFalse(Validator::isIpBlocked('172.16.0.1', true));
        $this->assertFalse(Validator::isIpBlocked('192.168.1.1', true));
        $this->assertFalse(Validator::isIpBlocked('100.64.0.1', true));
        // Loopback still blocked even with allowPrivate
        $this->assertTrue(Validator::isIpBlocked('127.0.0.1', true));
    }

    public function testIpPublicSafe()
    {
        $this->assertFalse(Validator::isIpBlocked('8.8.8.8'));
        $this->assertFalse(Validator::isIpBlocked('1.1.1.1'));
    }

    public function testIpV6Loopback()
    {
        $this->assertTrue(Validator::isIpBlocked('::1'));
        $this->assertTrue(Validator::isIpBlocked('[::1]'));
    }

    public function testIpV6LinkLocal()
    {
        $this->assertTrue(Validator::isIpBlocked('fe80::1'));
    }

    public function testIpV6UniqueLocal()
    {
        $this->assertTrue(Validator::isIpBlocked('fc00::1'));
        $this->assertTrue(Validator::isIpBlocked('fd00::1'));
        // Allowed when allowPrivate is true
        $this->assertFalse(Validator::isIpBlocked('fc00::1', true));
    }

    public function testIpV6PublicSafe()
    {
        $this->assertFalse(Validator::isIpBlocked('2001:4860:4860::8888'));
    }

    public function testIpV4MappedV6Loopback()
    {
        $this->assertTrue(Validator::isIpBlocked('::ffff:127.0.0.1'));
        $this->assertTrue(Validator::isIpBlocked('::ffff:10.0.0.1'));
        $this->assertFalse(Validator::isIpBlocked('::ffff:10.0.0.1', true));
    }

    // ── validateUrlSecurity() — pure checks, no DNS ──────

    public function testUrlInvalid()
    {
        $result = Validator::validateUrlSecurity('not-a-url');
        $this->assertFalse($result['valid']);

        $result = Validator::validateUrlSecurity('ftp://example.com');
        $this->assertFalse($result['valid']);
    }

    public function testUrlLocalhost()
    {
        $result = Validator::validateUrlSecurity('http://localhost/path');
        $this->assertFalse($result['valid']);
    }

    public function testUrlInternalDomain()
    {
        $result = Validator::validateUrlSecurity('http://something.internal/');
        $this->assertFalse($result['valid']);

        $result = Validator::validateUrlSecurity('http://something.local/');
        $this->assertFalse($result['valid']);
    }

    public function testUrlEmbeddedCredentials()
    {
        $result = Validator::validateUrlSecurity('http://user:pass@example.com/');
        $this->assertFalse($result['valid']);
    }

    public function testUrlDirectPrivateIp()
    {
        $result = Validator::validateUrlSecurity('http://10.0.0.1/');
        $this->assertFalse($result['valid']);

        $result = Validator::validateUrlSecurity('http://127.0.0.1/');
        $this->assertFalse($result['valid']);
    }

    // ── validateHostSecurity() — pure checks, no DNS ─────

    public function testHostEmpty()
    {
        $result = Validator::validateHostSecurity('');
        $this->assertFalse($result['valid']);
    }

    public function testHostLocalhost()
    {
        $result = Validator::validateHostSecurity('localhost');
        $this->assertFalse($result['valid']);
    }

    public function testHostMetadataEndpoint()
    {
        $result = Validator::validateHostSecurity('169.254.169.254');
        $this->assertFalse($result['valid']);
    }

    public function testHostDirectPrivateIp()
    {
        $result = Validator::validateHostSecurity('10.0.0.1');
        $this->assertFalse($result['valid']);
    }

    public function testHostInvalidSyntax()
    {
        $result = Validator::validateHostSecurity('not_a_valid_host');
        $this->assertFalse($result['valid']);
    }
}
