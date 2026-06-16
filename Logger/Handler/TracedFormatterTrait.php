<?php
declare(strict_types=1);

namespace Brocode\LogTracing\Logger\Handler;

use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;

/**
 * Supplies a LineFormatter that promotes the trace ID to a leading column:
 *
 *   [2026-06-06 09:14:22] [9f2c1ad4e0b74f3a8c5d6e7f] main.INFO: Saved order ...
 *
 * Overriding getFormatter() (rather than the constructor) keeps these handlers
 * decoupled from the parent constructor signature: they inherit all of the
 * core handler's dependencies and behaviour (System still routes errors to
 * exception.log), and only the output format changes.
 *
 * Monolog calls getFormatter() lazily per write, so the formatter is memoised.
 */
trait TracedFormatterTrait
{
    private ?LineFormatter $tracedFormatter = null;

    public function getFormatter(): FormatterInterface
    {
        return $this->tracedFormatter ??= new LineFormatter(
            "[%datetime%] [%extra.trace_id%] %channel%.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,  // allowInlineLineBreaks
            true   // ignoreEmptyContextAndExtra
        );
    }
}
