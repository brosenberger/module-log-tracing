<?php
declare(strict_types=1);

namespace Brocode\RequestTrace\Logger;

use Brocode\RequestTrace\Service\TraceId;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Stamps the request-scoped trace ID onto the `extra` of every log record.
 *
 * Magento 2.4.8 ships Monolog 3, where the record is an immutable LogRecord
 * OBJECT and `extra` is the one writable property. The Monolog 2 array form
 *
 *     $record['extra']['trace_id'] = $id;   // <-- silently no-ops on Monolog 3
 *
 * does nothing here, because array access on the object returns a copy. Mutate
 * the property and return the record.
 *
 * Runs for every record on every handler, so it must stay cheap — which it is,
 * because TraceId memoises after the first call.
 */
class TraceIdProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TraceId $traceId
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['trace_id'] = $this->traceId->get();

        return $record;
    }
}
