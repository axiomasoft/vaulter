<?php

declare(strict_types=1);

register_shutdown_function(static function (): void {
    fwrite(STDERR, 'P5_PHP_PEAK_BYTES='.memory_get_peak_usage(true).PHP_EOL);
});
