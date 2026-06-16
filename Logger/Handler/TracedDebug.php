<?php
declare(strict_types=1);

namespace Brocode\RequestTrace\Logger\Handler;

use Magento\Framework\Logger\Handler\Debug;

/**
 * Drop-in replacement for the core `debug` handler that prints the trace ID as
 * a leading column. Extends Debug, so it keeps Debug's behaviour and writes to
 * the same var/log/debug.log — only the formatter changes.
 */
class TracedDebug extends Debug
{
    use TracedFormatterTrait;
}
