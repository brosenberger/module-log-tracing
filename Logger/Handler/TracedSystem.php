<?php
declare(strict_types=1);

namespace Brocode\LogTracing\Logger\Handler;

use Magento\Framework\Logger\Handler\System;

/**
 * Drop-in replacement for the core `system` handler that prints the trace ID
 * as a leading column. Extends System, so it keeps System's behaviour
 * (including routing exceptions to exception.log) and writes to the same
 * var/log/system.log — only the formatter changes.
 */
class TracedSystem extends System
{
    use TracedFormatterTrait;
}
