<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Regression test: ensures Monolog uses the correct Psr\Log\LoggerInterface
 * (from psr/log Composer package) and NOT PsrExt\Log\LoggerInterface
 * (from the ext-psr PECL extension).
 *
 * Background: Hostinger shared hosting may have ext-psr loaded, which provides
 * PSR interfaces with PSR-3 v1 signatures. Monolog v3 requires PSR-3 v3
 * signatures, causing a fatal "Declaration ... must be compatible" error.
 *
 * @see https://pecl.php.net/package/psr
 */
class PsrLogInterfaceTest extends TestCase
{
    /**
     * Psr\Log\LoggerInterface must exist (from psr/log Composer package).
     */
    public function test_psr_log_interface_exists(): void
    {
        $this->assertTrue(
            interface_exists(\Psr\Log\LoggerInterface::class),
            'Psr\Log\LoggerInterface must exist (provided by psr/log package)'
        );
    }

    /**
     * PsrExt\Log\LoggerInterface must NOT exist.
     * If it does, ext-psr is loaded and will cause a fatal error.
     */
    public function test_psr_ext_log_interface_does_not_exist(): void
    {
        $this->assertFalse(
            interface_exists('PsrExt\Log\LoggerInterface', false),
            'PsrExt\Log\LoggerInterface must NOT exist — ext-psr PECL extension must be disabled'
        );
    }

    /**
     * The ext-psr PHP extension must NOT be loaded.
     */
    public function test_ext_psr_not_loaded(): void
    {
        $this->assertFalse(
            extension_loaded('psr'),
            'The ext-psr PHP extension must not be loaded — it conflicts with psr/log v3 + Monolog v3'
        );
    }

    /**
     * Monolog\Logger must implement Psr\Log\LoggerInterface (not PsrExt).
     */
    public function test_monolog_implements_psr_log_interface(): void
    {
        $this->assertTrue(
            class_exists(\Monolog\Logger::class),
            'Monolog\Logger class must exist'
        );

        $reflection = new \ReflectionClass(\Monolog\Logger::class);

        $this->assertTrue(
            $reflection->implementsInterface(\Psr\Log\LoggerInterface::class),
            'Monolog\Logger must implement Psr\Log\LoggerInterface'
        );
    }

    /**
     * Monolog\Logger::emergency() must accept Stringable|string (PSR-3 v3).
     * This is the exact method signature that breaks when ext-psr is loaded.
     */
    public function test_monolog_emergency_has_correct_signature(): void
    {
        $method = new \ReflectionMethod(\Monolog\Logger::class, 'emergency');
        $params = $method->getParameters();

        $this->assertCount(2, $params, 'emergency() must have exactly 2 parameters');

        $messageParam = $params[0];
        $this->assertEquals('message', $messageParam->getName());

        // PSR-3 v3 signature: Stringable|string $message
        $type = $messageParam->getType();
        $this->assertNotNull($type, 'The $message parameter must have a type hint');

        // It should be a union type (Stringable|string) in PSR-3 v3
        $this->assertInstanceOf(
            \ReflectionUnionType::class,
            $type,
            'The $message parameter should be a union type (Stringable|string) per PSR-3 v3'
        );
    }
}
