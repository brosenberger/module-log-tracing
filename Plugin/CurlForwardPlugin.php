<?php
declare(strict_types=1);

namespace Brocode\RequestTrace\Plugin;

use Brocode\RequestTrace\Service\TraceId;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Forwards the trace ID on every outbound request made through Magento's cURL
 * client, so a downstream service's logs join the same trace without each
 * call-site remembering to add the header.
 *
 * Attaches to the public get()/post() entry points (the verb wrappers all
 * funnel through the protected makeRequest(), which can't be plugged) and adds
 * the propagation headers just before the request fires. addHeader() keys by
 * name, so this is idempotent and coexists with headers the call-site set.
 *
 * Scope: this covers Magento\Framework\HTTP\Client\Curl only. Code using
 * Guzzle, Laminas\Http\Client, or the lower-level HTTP\Adapter\Curl should add
 * TraceId::propagationHeaders() to its own requests (see README).
 */
class CurlForwardPlugin
{
    public function __construct(
        private readonly TraceId $traceId
    ) {
    }

    /**
     * @param string $uri
     */
    public function beforeGet(Curl $subject, $uri): void
    {
        $this->forward($subject);
    }

    /**
     * @param string $uri
     * @param array|string $params
     */
    public function beforePost(Curl $subject, $uri, $params): void
    {
        $this->forward($subject);
    }

    private function forward(Curl $subject): void
    {
        foreach ($this->traceId->propagationHeaders() as $name => $value) {
            $subject->addHeader($name, $value);
        }
    }
}
