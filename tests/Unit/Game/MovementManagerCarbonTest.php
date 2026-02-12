<?php

namespace Tests\Unit\Game;

use App\Models\Movement;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Regression test for "Call to a member function diffInSeconds() on string"
 * at MovementManager.php:663.
 *
 * Ensures that:
 * 1) The Timeable trait's `remaining` accessor handles string dates safely.
 * 2) The Movement model casts `ended_at` to Carbon regardless of input type.
 * 3) The normalizeToCarbon() helper in MovementManager never throws.
 */
class MovementManagerCarbonTest extends TestCase
{
    /**
     * Test that Movement `ended_at` is always a Carbon instance even when
     * set as a raw string (simulates DB returning a string instead of
     * a cast Carbon object).
     */
    public function test_movement_ended_at_cast_from_string(): void
    {
        $movement = new Movement();
        $movement->ended_at = '2026-01-15 10:30:00';

        $this->assertInstanceOf(
            \Carbon\Carbon::class,
            $movement->ended_at,
            'ended_at should be cast to Carbon even when set as a string'
        );
    }

    /**
     * Test that the Timeable `remaining` accessor does not throw when
     * ended_at is a raw string in the attributes array (bypassing setter).
     */
    public function test_timeable_remaining_handles_string_ended_at(): void
    {
        $movement = new Movement();
        // Directly set the raw attribute to simulate DB hydration returning a string
        $movement->setRawAttributes([
            'ended_at' => Carbon::now()->addSeconds(120)->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);

        // This must not throw "Call to a member function diffInSeconds() on string"
        $remaining = $movement->remaining;

        $this->assertIsInt($remaining);
        $this->assertGreaterThanOrEqual(0, $remaining);
        // Should be roughly 120 seconds (allow some tolerance for test execution time)
        $this->assertLessThanOrEqual(125, $remaining);
    }

    /**
     * Test that remaining returns 0 when ended_at is null.
     */
    public function test_timeable_remaining_returns_zero_for_null(): void
    {
        $movement = new Movement();
        $movement->setRawAttributes([
            'ended_at' => null,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $remaining = $movement->remaining;

        $this->assertIsInt($remaining);
        $this->assertEquals(0, $remaining);
    }

    /**
     * Test that remaining returns 0 when ended_at is an empty string.
     */
    public function test_timeable_remaining_returns_zero_for_empty_string(): void
    {
        $movement = new Movement();
        $movement->setRawAttributes([
            'ended_at' => '',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $remaining = $movement->remaining;

        $this->assertIsInt($remaining);
        $this->assertEquals(0, $remaining);
    }

    /**
     * Test that remaining works correctly when ended_at is in the past.
     */
    public function test_timeable_remaining_returns_zero_for_past_date(): void
    {
        $movement = new Movement();
        $movement->setRawAttributes([
            'ended_at' => Carbon::now()->subSeconds(60)->toDateTimeString(),
            'created_at' => Carbon::now()->subSeconds(120)->toDateTimeString(),
        ]);

        $remaining = $movement->remaining;

        $this->assertIsInt($remaining);
        $this->assertEquals(0, $remaining);
    }

    /**
     * Test normalizeToCarbon via reflection — string, Carbon, null, garbage.
     */
    public function test_normalize_to_carbon_helper(): void
    {
        $method = new \ReflectionMethod(\App\Game\MovementManager::class, 'normalizeToCarbon');
        $method->setAccessible(true);

        // Carbon instance → same instance
        $now = Carbon::now();
        $result = $method->invoke(null, $now);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $result);

        // String → parsed Carbon
        $result = $method->invoke(null, '2026-01-15 10:30:00');
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $result);
        $this->assertEquals(2026, $result->year);
        $this->assertEquals(1, $result->month);
        $this->assertEquals(15, $result->day);

        // null → null
        $result = $method->invoke(null, null);
        $this->assertNull($result);

        // empty string → null
        $result = $method->invoke(null, '');
        $this->assertNull($result);

        // Garbage string → null (no exception)
        $result = $method->invoke(null, 'not-a-date');
        $this->assertNull($result);

        // DateTime object → Carbon
        $dt = new \DateTime('2026-06-01 12:00:00');
        $result = $method->invoke(null, $dt);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $result);
        $this->assertEquals(2026, $result->year);
    }

    /**
     * Simulate the exact crash scenario: diffInSeconds on string dates
     * used in MovementManager::returnMovement() logic.
     */
    public function test_diff_in_seconds_with_string_dates_does_not_crash(): void
    {
        $endedAtStr = '2026-01-15 10:30:00';
        $createdAtStr = '2026-01-15 10:00:00';

        $method = new \ReflectionMethod(\App\Game\MovementManager::class, 'normalizeToCarbon');
        $method->setAccessible(true);

        $endedAt = $method->invoke(null, $endedAtStr);
        $createdAt = $method->invoke(null, $createdAtStr);

        $this->assertNotNull($endedAt);
        $this->assertNotNull($createdAt);

        $travelTime = $endedAt->diffInSeconds($createdAt);

        $this->assertEquals(1800, $travelTime, 'Should be 1800 seconds (30 minutes)');
    }
}
