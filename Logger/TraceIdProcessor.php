<?php
declare(strict_types=1);

namespace BroCode\LogTracing\Logger;

use BroCode\LogTracing\Service\TraceId;

/**
 * Stamps the request-scoped trace ID onto the `extra` of every log record.
 *
 * Compatible with both Monolog 2 (Magento 2.4.4–2.4.7) and Monolog 3 (2.4.8+).
 *
 * Monolog 2: the record is a plain array  → mutate $record['extra']['trace_id'].
 * Monolog 3: the record is a LogRecord object → mutate $record->extra['trace_id'].
 *   Array access on a LogRecord returns a copy, so $record['extra'][...] = $x
 *   silently does nothing in Monolog 3 — this is the most common gotcha.
 *
 * The discriminator is is_object(): every processor in Monolog only ever receives
 * an array (v2) or a LogRecord (v3), so this is reliable without needing an
 * instanceof check against a class that may not exist on the installed version.
 *
 * Intentionally does NOT implement ProcessorInterface: that interface was
 * introduced in Monolog 3 and carries a typed LogRecord signature that is
 * incompatible with Monolog 2. Magento's DI only requires a callable here.
 *
 * Runs for every record on every handler; must stay cheap — which it is, because
 * TraceId memoises after the first call.
 */
class TraceIdProcessor
{
    public function __construct(
        private readonly TraceId $traceId
    ) {
    }

    public function __invoke(mixed $record): mixed
    {
        if (is_object($record)) {
            // Monolog 3: LogRecord — extra is the writable property
            $record->extra['trace_id'] = $this->traceId->get();
        } else {
            // Monolog 2: plain array
            $record['extra']['trace_id'] = $this->traceId->get();
        }

        return $record;
    }
}