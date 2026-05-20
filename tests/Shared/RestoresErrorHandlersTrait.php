<?php

namespace Hakam\MultiTenancyBundle\Tests\Shared;

/**
 * Pops back error/exception handlers that the test (typically via kernel boot)
 * left on PHP's handler stack. PHPUnit 11 flags both "removed handlers other
 * than its own" and "did not remove its own handlers" as risky — this trait
 * sidesteps both by sentinel-marking the stack height in setUp and popping
 * exactly back to it in tearDown.
 */
trait RestoresErrorHandlersTrait
{
    private static ?\Closure $errorHandlerSentinel = null;
    private static ?\Closure $exceptionHandlerSentinel = null;

    protected function markHandlerStackHeight(): void
    {
        self::$errorHandlerSentinel = static fn (): bool => false;
        self::$exceptionHandlerSentinel = static function (\Throwable $e): void {};
        set_error_handler(self::$errorHandlerSentinel);
        set_exception_handler(self::$exceptionHandlerSentinel);
    }

    protected function restoreHandlerStackHeight(): void
    {
        if (self::$errorHandlerSentinel !== null) {
            while (true) {
                $current = set_error_handler(static fn (): bool => false);
                restore_error_handler();
                if ($current === null || $current === self::$errorHandlerSentinel) {
                    break;
                }
                restore_error_handler();
            }
            restore_error_handler();
            self::$errorHandlerSentinel = null;
        }

        if (self::$exceptionHandlerSentinel !== null) {
            while (true) {
                $current = set_exception_handler(static function (\Throwable $e): void {});
                restore_exception_handler();
                if ($current === null || $current === self::$exceptionHandlerSentinel) {
                    break;
                }
                restore_exception_handler();
            }
            restore_exception_handler();
            self::$exceptionHandlerSentinel = null;
        }
    }
}
