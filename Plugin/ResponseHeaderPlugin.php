<?php
declare(strict_types=1);

namespace Brocode\RequestTrace\Plugin;

use Brocode\RequestTrace\Service\TraceId;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Echoes the trace ID back on the response as `X-Request-Id`, so it is visible
 * on the wire (`curl -I https://store/`) and can be captured by browsers,
 * proxies, and monitoring. If an upstream already set the header, it is left
 * alone so the inbound value wins.
 *
 * Caveat: responses served entirely from full-page cache (Varnish) bypass PHP
 * and will not carry this header. Dynamic / uncached responses will.
 */
class ResponseHeaderPlugin
{
    public function __construct(
        private readonly TraceId $traceId
    ) {
    }

    public function beforeSendResponse(HttpResponse $subject): void
    {
        if (!$subject->getHeader('X-Request-Id')) {
            $subject->setHeader('X-Request-Id', $this->traceId->get(), true);
        }
    }
}
