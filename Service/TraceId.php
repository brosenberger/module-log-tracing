<?php
declare(strict_types=1);

namespace Brocode\LogTracing\Service;

/**
 * Owns the request-scoped trace ID.
 *
 * Resolved once per PHP process — web request, CLI command, cron run, or queue
 * consumer — and memoised for the rest of that process. If the edge (Nginx, a
 * load balancer, or an upstream service) already supplied an ID, that one is
 * reused so the trace is continuous across hops; otherwise a fresh one is
 * minted.
 *
 * Why $_SERVER and not RequestInterface:
 *   The logger is constructed extremely early in the bootstrap, and the HTTP
 *   request object can itself log during construction. Injecting the request
 *   onto the logging path risks a circular dependency. A superglobal read has
 *   no dependencies and is available the instant PHP starts.
 *
 * This class is a shared (singleton) instance under Magento DI, so the private
 * $id field memoises across the whole process. To get a fresh ID per unit of
 * work inside a long-running consumer, call reset() at the top of each message.
 */
class TraceId
{
    /**
     * Inbound carriers to honour, in priority order. Distributed-tracing
     * context from a calling service wins over generic correlation headers,
     * which win over per-node web-server IDs. Reorder to taste.
     *
     *  - HTTP_TRACEPARENT     W3C Trace Context (OpenTelemetry, modern Dynatrace,
     *                         SAP Cloud); the 32-hex trace-id field is extracted
     *  - HTTP_X_B3_TRACEID    B3 propagation: Zipkin, Istio/Envoy, many meshes
     *  - HTTP_B3              B3 single-header form (Envoy): trace-id is field 1
     *  - HTTP_SAP_PASSPORT    SAP end-to-end trace passport; the embedded GUID
     *                         is extracted (best-effort — see fromSapPassport)
     *  - HTTP_X_CORRELATION_ID  SAP BTP / CPI / CAP and general correlation id
     *  - HTTP_X_DYNATRACE     Dynatrace request tag (when not using traceparent)
     *  - HTTP_X_REQUEST_ID    generic correlation id (LB / upstream / web server)
     *  - HTTP_X_AMZN_TRACE_ID AWS ALB / X-Ray; the Root= field is extracted
     *  - HTTP_X_CLOUD_TRACE_CONTEXT  Google Cloud Load Balancer; trace-id field
     *  - REQUEST_ID           Nginx: fastcgi_param REQUEST_ID $request_id;
     *  - UNIQUE_ID            Apache mod_unique_id (per-node fallback)
     */
    private const SOURCES = [
        'HTTP_TRACEPARENT',
        'HTTP_X_B3_TRACEID',
        'HTTP_B3',
        'HTTP_SAP_PASSPORT',
        'HTTP_X_CORRELATION_ID',
        'HTTP_X_DYNATRACE',
        'HTTP_X_REQUEST_ID',
        'HTTP_X_AMZN_TRACE_ID',
        'HTTP_X_CLOUD_TRACE_CONTEXT',
        'REQUEST_ID',
        'UNIQUE_ID',
    ];

    /**
     * Best-effort byte positions (as hex-char offsets) at which a SAP Passport
     * tends to carry a 16-byte GUID. Probed in order; first valid non-zero
     * 32-hex run wins. Adjust if your SAP system's passport differs.
     */
    private const SAP_GUID_OFFSETS = [16, 48, 80];

    /**
     * Inbound headers forwarded verbatim on outbound calls (see
     * propagationHeaders) so SAP / Dynatrace / OTel continuation works. Maps
     * the $_SERVER key to the wire header name to re-emit.
     */
    private const PROPAGATE = [
        'HTTP_TRACEPARENT'      => 'traceparent',
        'HTTP_TRACESTATE'       => 'tracestate',
        'HTTP_SAP_PASSPORT'     => 'SAP-PASSPORT',
        'HTTP_X_DYNATRACE'      => 'X-Dynatrace',
        'HTTP_B3'               => 'b3',
        'HTTP_X_B3_TRACEID'     => 'X-B3-TraceId',
        'HTTP_X_CORRELATION_ID' => 'X-Correlation-ID',
    ];

    private ?string $id = null;

    public function get(): string
    {
        if ($this->id !== null) {
            return $this->id;
        }

        foreach (self::SOURCES as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            $extracted = $this->extract($key, (string) $_SERVER[$key]);
            if ($extracted !== '') {
                $this->id = $extracted;
                $this->announce($this->id);

                return $this->id;
            }
        }

        // 16 random bytes => 32 hex chars: the same shape as Nginx's $request_id.
        $this->id = bin2hex(random_bytes(16));
        $this->announce($this->id);

        return $this->id;
    }

    /**
     * Adopt a specific ID — e.g. one carried in a queue message so an async
     * consumer continues the originating request's trace across nodes. Call
     * before anything logs.
     */
    public function set(string $id): string
    {
        $this->id = $this->normalise($id);
        $this->announce($this->id);

        return $this->id;
    }

    /**
     * Force a brand-new ID. Useful at the top of each message in a long-running
     * queue consumer so messages don't all share one ID.
     */
    public function reset(): string
    {
        $this->id = bin2hex(random_bytes(16));
        $this->announce($this->id);

        return $this->id;
    }

    /**
     * Headers to attach to an outbound request so the trace continues
     * downstream: always X-Request-Id (our resolved ID), plus any inbound
     * distributed-tracing context forwarded verbatim (traceparent + tracestate,
     * SAP-PASSPORT, X-Dynatrace, B3) so SAP / Dynatrace / OTel systems stay on
     * the same trace.
     *
     * Note: forwarding traceparent verbatim keeps the trace-id continuous but
     * does not open a child span — that is OpenTelemetry's job. For pure log
     * correlation this is the right, dependency-free behaviour.
     */
    public function propagationHeaders(): array
    {
        $headers = ['X-Request-Id' => $this->get()];

        foreach (self::PROPAGATE as $server => $name) {
            if (!empty($_SERVER[$server])) {
                $headers[$name] = (string) $_SERVER[$server];
            }
        }

        return $headers;
    }

    /**
     * Pull the trace-id out of a known carrier. Structured headers
     * (traceparent, X-Amzn-Trace-Id, X-Cloud-Trace-Context) carry more than an
     * ID, so the meaningful field is extracted; everything else is sanitised
     * as-is.
     */
    private function extract(string $key, string $raw): string
    {
        return match ($key) {
            'HTTP_TRACEPARENT'           => $this->fromTraceparent($raw),
            'HTTP_B3'                    => $this->fromB3Single($raw),
            'HTTP_SAP_PASSPORT'          => $this->fromSapPassport($raw),
            'HTTP_X_AMZN_TRACE_ID'       => $this->fromAmznTraceId($raw),
            'HTTP_X_CLOUD_TRACE_CONTEXT' => $this->fromCloudTrace($raw),
            default                      => $this->normalise($raw),
        };
    }

    /**
     * W3C Trace Context: version "-" trace-id "-" parent-id "-" flags, e.g.
     * 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01
     * Returns the 32-hex trace-id only — the shared, cross-service field. The
     * parent-id changes per hop and must not be used as the correlation ID.
     */
    private function fromTraceparent(string $raw): string
    {
        $parts = explode('-', trim($raw));
        $traceId = $parts[1] ?? '';

        if (preg_match('/^[0-9a-f]{32}$/i', $traceId) && $traceId !== str_repeat('0', 32)) {
            return strtolower($traceId);
        }

        return '';
    }

    /**
     * B3 single header (Envoy): trace-id "-" span-id "-" sampled "-" parent.
     * The trace-id is 16 or 32 lowercase hex.
     */
    private function fromB3Single(string $raw): string
    {
        $traceId = explode('-', trim($raw), 2)[0];

        if (preg_match('/^[0-9a-f]{16}([0-9a-f]{16})?$/i', $traceId)) {
            return strtolower($traceId);
        }

        return '';
    }

    /**
     * SAP Passport: a hex-encoded binary blob beginning with the "*TH*" marker
     * (0x2A54482A). It embeds 16-byte GUIDs (transaction ID, root context ID)
     * that SAP logs alongside the request. The exact field offsets are
     * version-sensitive, so this is a best-effort extraction — it validates the
     * envelope, then takes the first valid, non-zero 32-hex GUID at a probed
     * position. If it doesn't line up with your SAP system, adjust
     * SAP_GUID_OFFSETS, or rely on X-Correlation-ID / traceparent, which SAP
     * landscapes also emit and which this module handles directly. Either way,
     * the original passport is forwarded verbatim on outbound calls (see
     * propagationHeaders) so SAP-side correlation continues regardless.
     */
    private function fromSapPassport(string $raw): string
    {
        $hex = strtolower(trim($raw));

        if (!ctype_xdigit($hex) || !str_starts_with($hex, '2a54482a')) {
            return '';
        }

        foreach (self::SAP_GUID_OFFSETS as $offset) {
            $guid = substr($hex, $offset, 32);
            if (strlen($guid) === 32 && $guid !== str_repeat('0', 32)) {
                return $guid;
            }
        }

        return '';
    }

    /**
     * AWS X-Ray: "Root=1-5759e988-bd862e3fe1be46a994272793;Parent=...;Sampled=1"
     */
    private function fromAmznTraceId(string $raw): string
    {
        if (preg_match('/Root=([^;]+)/', $raw, $m)) {
            return $this->normalise($m[1]);
        }

        return $this->normalise($raw);
    }

    /**
     * Google Cloud: "TRACE_ID/SPAN_ID;o=1" — keep the trace-id before the slash.
     */
    private function fromCloudTrace(string $raw): string
    {
        $traceId = explode('/', trim($raw), 2)[0];

        return $this->normalise($traceId);
    }

    private function normalise(string $raw): string
    {
        // Keep it filename- and grep-friendly, and bounded.
        return substr((string) preg_replace('/[^A-Za-z0-9.\-]/', '', $raw), 0, 64);
    }

    /**
     * Surface the ID to external observability tools, once, on first resolve.
     * Guarded so it is a no-op when the New Relic extension is absent.
     */
    private function announce(string $id): void
    {
        if (function_exists('newrelic_add_custom_parameter')) {
            newrelic_add_custom_parameter('trace_id', $id);
        }
    }
}
