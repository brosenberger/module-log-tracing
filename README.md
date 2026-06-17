# Brocode_LogTracing

A request-scoped **trace ID** for Magento 2 logs. Stamps one ID onto every log
line produced during a request, echoes it back as an `X-Request-Id` response
header, and gives you a single value to grep on when you need to reconstruct
"what happened to *this* request" out of a busy `var/log`.

Compatible with **Magento 2.4.4–2.4.8+** (Monolog 2 and Monolog 3). Zero
external dependencies.

→ **[Read the full article on brocode.at](https://brocode.at/blog/magento-request-log-tracing)** — why trace IDs, how Monolog 2 and 3 differ, pitfall cheatsheet.
→ **[Module page on brocode.at](https://brocode.at/modules/module-log-tracing)** — install command, feature overview.

---

## Why

Under load, every request writes to the same log files and the lines
interleave. A trace ID labels each line so you can pull back exactly the lines
that belong to one request:

```
[2026-06-06 09:14:22] [9f2c1ad4e0b74f3a8c5d6e7f] main.INFO: Saved order 100000042
[2026-06-06 09:14:22] [9f2c1ad4e0b74f3a8c5d6e7f] main.ERROR: Payment gateway timeout
```

```bash
grep -rh '9f2c1ad4e0b74f3a8c5d6e7f' var/log/
```

---

## What it does

| Piece | File | Job |
|---|---|---|
| Holder | `Service/TraceId.php` | Resolve the ID once per process; reuse an inbound ID or mint one |
| Processor | `Logger/TraceIdProcessor.php` | Stamp `extra.trace_id` on every Monolog record |
| Handlers | `Logger/Handler/Traced*.php` | Print the ID as a leading column in system.log / debug.log |
| Plugin | `Plugin/ResponseHeaderPlugin.php` | Echo the ID back as the `X-Request-Id` response header |
| Plugin | `Plugin/CurlForwardPlugin.php` | Forward the ID (+ inbound trace context) on outbound cURL calls |
| Wiring | `etc/di.xml` | Register all of the above on the core logger and response |

---

## Install

Drop the module in `app/code/Brocode/LogTracing`, then:

```bash
bin/magento module:enable Brocode_LogTracing
bin/magento setup:upgrade
bin/magento setup:di:compile      # if running in production / compiled mode
bin/magento cache:flush
```

Or via Composer once the package is published:

```bash
composer require brocode/module-log-tracing
bin/magento module:enable Brocode_LogTracing
bin/magento setup:upgrade
bin/magento cache:flush
```

---

## Verify

**Logs** — trigger any action that logs, then look at a log file. Every line
should carry an ID in the second column:

```bash
tail var/log/system.log
```

**Response header** — the same ID comes back on the wire (uncached responses):

```bash
curl -sI https://your-store.test/ | grep -i x-request-id
# x-request-id: 9f2c1ad4e0b74f3a8c5d6e7f
```

**End to end** — send your own ID in and watch it flow through logs and back:

```bash
curl -sI -H 'X-Request-Id: my-debug-123' https://your-store.test/ | grep -i x-request-id
# x-request-id: my-debug-123
grep -rh 'my-debug-123' var/log/
```

**From an upstream trace** — send a W3C `traceparent`; the module extracts the
32-hex trace-id and logs (and echoes) that, so Magento lines up with the
distributed trace:

```bash
curl -sI -H 'traceparent: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01' \
  https://your-store.test/ | grep -i x-request-id
# x-request-id: 4bf92f3577b34da6a3ce929d0e0e4736
grep -rh '4bf92f3577b34da6a3ce929d0e0e4736' var/log/
```

---

## Close the loop at the edge

Let the web server supply the ID so the same value appears in its access log
*and* every Magento log line. The app reads it from the inbound request, so the
only job at the edge is to make sure an ID is present.

### Nginx

Nginx has a built-in `$request_id` (32 hex chars). In the `location` block that
proxies to PHP-FPM:

```text
# nginx: pass the built-in request ID to PHP-FPM
fastcgi_param REQUEST_ID $request_id;
```

The holder reads `$_SERVER['REQUEST_ID']`, so no PHP change is needed. Surface
it in Nginx's own log too:

```text
# nginx: trace ID in access log
log_format traced '$remote_addr - $request_time "$request" $status rid=$request_id';
access_log /var/log/nginx/access.log traced;
```

### Apache

Apache's `mod_unique_id` stamps every request with a `UNIQUE_ID`. The robust way
to get that to PHP — under both `mod_php` and PHP-FPM via `mod_proxy_fcgi` — is
to expose it as the `X-Request-Id` **request header**, because headers are always
forwarded to the backend whereas raw env vars are not always passed. Crucially,
do this only when no upstream proxy already set the header, so an ID from a load
balancer survives:

```text
# /etc/apache2/conf-available/zz-request-trace.conf
# Enable once:  a2enmod unique_id headers   then reload Apache.

# Adopt mod_unique_id's value as X-Request-Id, but never overwrite an
# upstream-supplied one (setifempty, Apache 2.4.7+).
RequestHeader setifempty X-Request-Id "%{UNIQUE_ID}e"

# Record the ID in Apache's own access log for correlation with the app log.
LogFormat "%h %l %u %t \"%r\" %>s %b rid=%{X-Request-Id}i" traced
CustomLog ${APACHE_LOG_DIR}/access.log traced
```

The holder reads `$_SERVER['HTTP_X_REQUEST_ID']` first, so it picks this up with
no PHP change. (`UNIQUE_ID` is also in the holder's source list as a belt-and-
braces fallback for setups that skip the `RequestHeader` line.)

Either way, a 502 in the web-server log and the PHP fatal that caused it now
share an ID.

---

## Joining a trace from an upstream service

If a calling service already participates in distributed tracing, the module
adopts *its* trace ID instead of minting a new one — so Magento's logs carry the
same ID your tracing backend (Jaeger, Tempo, Datadog, an APM) shows for that
distributed trace, and line up automatically. Recognised carriers, highest
priority first:

| Header | Standard / source | What's used |
|---|---|---|
| `traceparent` | W3C Trace Context / OpenTelemetry / modern Dynatrace | the 32-hex **trace-id** field (not the per-hop span-id) |
| `X-B3-TraceId` | B3 — Zipkin, Istio/Envoy | full value |
| `b3` | B3 single-header (Envoy) | trace-id (field 1) |
| `SAP-PASSPORT` | SAP end-to-end trace | embedded GUID (best-effort; see note) |
| `X-Correlation-ID` | SAP BTP / CPI / CAP, and general | full value |
| `X-Dynatrace` | Dynatrace request tag | full value (sanitised) |
| `X-Request-Id` | generic correlation convention | full value |
| `X-Amzn-Trace-Id` | AWS ALB / X-Ray | the `Root=` field |
| `X-Cloud-Trace-Context` | Google Cloud LB | trace-id (before the `/`) |
| `REQUEST_ID` / `UNIQUE_ID` | Nginx / Apache, per-node | full value |

**SAP Passport note.** `SAP-PASSPORT` is a hex-encoded binary blob (it starts
with the `*TH*` marker) that embeds the transaction/root-context GUIDs. The field
offsets are version-sensitive, so the module validates the envelope and takes a
best-effort GUID; adjust `TraceId::SAP_GUID_OFFSETS` if it doesn't match your SAP
system. SAP landscapes commonly also emit `traceparent` or `X-Correlation-ID`,
which are parsed cleanly and rank above the generic headers. Whatever is used for
the log ID, the **original passport is forwarded verbatim** on outbound cURL calls
(below), so SAP-side end-to-end correlation continues either way.

**Dynatrace note.** Modern Dynatrace OneAgent propagates W3C `traceparent`
(handled at the top of the list), so in most deployments the Dynatrace trace-id
is captured automatically. `X-Dynatrace` is honoured as a fallback for the
request-tag header.

The key detail for `traceparent`: only the 32-hex trace-id is taken. The
parent-id segment changes at every hop, so using the whole header would yield a
different ID per service and defeat correlation. Invalid or all-zero trace-ids
are skipped and the next source is tried.

The order is a deliberate default — distributed-trace context beats generic
correlation headers, which beat per-node IDs. Reorder `TraceId::SOURCES` if your
infrastructure has a different canonical header.

**Ingestion vs propagation.** This honours *incoming* trace IDs so your **logs**
join the trace. It does not create spans or forward `traceparent` onward — that's
OpenTelemetry's job. For non-OTel downstreams, keep forwarding the ID as
`X-Request-Id` (below); for real span propagation, run the OpenTelemetry PHP
auto-instrumentation alongside this module and let it manage `traceparent`.

---

## Does this work across a cluster?

**Yes for a single request fanning out across services — if the ID is minted at
or before the load balancer and propagated as a header.** The rule is: *one
component mints the ID; everyone downstream honours it rather than minting their
own.*

A single web request lands on exactly one Magento node, so "the same node" isn't
the interesting part. What matters in a multi-node estate is three propagation
boundaries:

**1. Load balancer → web nodes (synchronous).** Configure the LB (or the first
proxy / Varnish) to generate an `X-Request-Id` when the client didn't send one,
and forward it. Every Magento node honours the inbound header first (top of the
holder's source list), so whichever node handles the request logs the LB's ID.
The per-node `$request_id` / `UNIQUE_ID` fallback only fires when nothing
upstream supplied one — which in a properly configured cluster shouldn't happen.

**2. Magento → downstream services (synchronous).** When a node calls OpenSearch,
a payment gateway, or an internal microservice, the ID must travel as a header so
the *other* service's logs join the same trace. Calls through Magento's cURL
client are handled automatically (see *Carry the trace to downstream calls*); for
other HTTP clients, spread `propagationHeaders()` onto the request:

```php
$this->http->request('POST', $endpoint, [
    'headers' => $this->traceId->propagationHeaders(),
    'json'    => $payload,
]);
```

**3. Web node → queue consumer / cron (asynchronous) — the one that does *not*
happen for free.** A request that enqueues a message finishes; some other node
processes that message seconds or minutes later, in a *different* PHP process.
That process mints its own fresh ID by default — which is usually what you want
for an independent job, but means the consumer's logs won't share the original
request's ID unless you carry it across deliberately. To continue the trace:

```php
// Publisher (web node): stash the current ID in the message payload.
$message->setTraceId($this->traceId->get());

// Consumer (worker node): adopt it before doing any work.
$this->traceId->set($message->getTraceId());
```

`set()` makes the consumer log under the originating request's ID, so the whole
chain — web request, downstream calls, async follow-up — reads back as one trace.

**One prerequisite for any of this to be useful across nodes:** you must be
shipping logs to a central store (OpenSearch/ELK, Loki, an APM). Grepping a
single node's `var/log` only ever shows that node's slice. The trace ID is the
join key *across* nodes' logs once they're aggregated in one place.

---

## Carry the trace to downstream calls

**Automatic for Magento's cURL client.** Every request made through
`Magento\Framework\HTTP\Client\Curl` (`get()` / `post()`) is stamped with
`X-Request-Id: <trace-id>`, plus any inbound `traceparent` (+`tracestate`),
`SAP-PASSPORT`, `X-Dynatrace`, `X-Correlation-ID`, or B3 context forwarded
verbatim — so a downstream service's logs join the same trace and SAP/Dynatrace/
OTel continuation keeps working. No call-site changes needed.

```bash
# A downstream endpoint that echoes request headers shows the trace flowing:
#   X-Request-Id: 4bf92f3577b34da6a3ce929d0e0e4736
#   traceparent:  00-4bf92f3577b34da6a3ce929d0e0e4736-...   (if one came in)
```

The plugin attaches to the public `get()`/`post()` methods — the verb wrappers
all funnel through the protected `makeRequest()`, which Magento plugins can't
intercept — and uses `addHeader()`, so it's idempotent and coexists with headers
the call-site already set.

**Manual for other HTTP clients.** Guzzle, `Laminas\Http\Client`, and the
lower-level `HTTP\Adapter\Curl` aren't auto-covered. Inject the service and spread
the same headers onto your request:

```php
public function __construct(
    private readonly \Brocode\LogTracing\Service\TraceId $traceId,
    private readonly \GuzzleHttp\ClientInterface $http,
) {}

public function call(): void
{
    $this->http->request('POST', $endpoint, [
        'headers' => $this->traceId->propagationHeaders(),
        'json'    => $payload,
    ]);
}
```

`propagationHeaders()` returns the same set the cURL plugin uses: `X-Request-Id`
plus any inbound trace context to forward verbatim.

---

## Options

**Trace ID without changing log format.** If you don't want the leading column
and prefer the ID to live inside the default `%extra%` JSON blob (fine for grep
and for shipping structured logs to OpenSearch), delete the `handlers` argument
from `etc/di.xml`. The processor alone is enough.

**Honour a different inbound header, or re-prioritise.** Edit `TraceId::SOURCES`
— entries are checked in order and the first that yields a value wins. See
*Joining a trace from an upstream service* for the recognised carriers and how
structured headers like `traceparent` are parsed.

**Per-message IDs in a queue consumer.** A consumer process otherwise shares one
ID across all messages. Call `TraceId::reset()` at the top of each message for a
fresh ID, or `TraceId::set($idFromMessage)` to continue the originating
request's trace (see *Does this work across a cluster?*).

**New Relic.** If the New Relic PHP extension is present, the ID is attached to
the transaction automatically as the `trace_id` custom parameter — no config.

---

## Monolog version compatibility

| Magento | Monolog | Record type | Processor receives |
|---|---|---|---|
| 2.4.4 – 2.4.7 | 2.x | `array` | `$record['extra']['trace_id']` |
| 2.4.8+ | 3.x | `LogRecord` object | `$record->extra['trace_id']` |

`TraceIdProcessor` uses `is_object($record)` to detect the version at runtime and
takes the correct path — no configuration, no build-time switch. It deliberately
does not implement `ProcessorInterface` (Monolog 3 only) so the class loads
cleanly under both versions.

---

## Notes & limits

- **FPC / Varnish.** Fully cached responses bypass PHP, so they won't carry the
  `X-Request-Id` header. Dynamic responses will.
- **Correlation, not spans.** This is a per-request correlation ID: it answers
  *which request?*, not *why was it slow?* For span-level timing across
  services, graduate to an APM trace view or OpenTelemetry (W3C Trace Context /
  `traceparent`). The `opentelemetry-php/contrib-auto-psr3` package fills
  `extra` the same way this processor does, but with IDs tied to real spans.
- **Virtual types backed by a PHP subclass of Monolog are not auto-covered.**
  The processor is registered on `Magento\Framework\Logger\Monolog` by type
  name. Magento's DI resolves `<type>` arguments by class name, not by PHP
  inheritance. The [Adobe-recommended custom logger pattern](https://experienceleague.adobe.com/en/docs/commerce-operations/configuration-guide/logs/custom-log-files)
  uses virtual types directly of `Monolog` and is covered automatically. The
  gap only appears when a module ships its own logger subclass —

  ```php
  class FooBarLogger extends Magento\Framework\Logger\Monolog { ... }
  ```

  — then virtual types of `FooBarLogger` are resolved against
  `<type name="FooBarLogger">`, not against `<type name="Monolog">`, so the
  processor is absent from those loggers. Fix: add one type entry per such
  base class in your project's `di.xml`:

  ```xml
  <type name="Vendor\Module\Logger\FooBarLogger">
      <arguments>
          <argument name="processors" xsi:type="array">
              <item name="trace_id" xsi:type="object">Brocode\LogTracing\Logger\TraceIdProcessor</item>
          </argument>
      </arguments>
  </type>
  ```

  This covers every virtual type built on `FooBarLogger` in one shot. Verify
  coverage by checking `var/log/<thatmodule>.log` for the trace ID after a
  request.

---

## File tree

```
Brocode/LogTracing/
├── registration.php
├── composer.json
├── README.md
├── etc/
│   ├── module.xml
│   └── di.xml
├── Service/
│   └── TraceId.php
├── Logger/
│   ├── TraceIdProcessor.php
│   └── Handler/
│       ├── TracedFormatterTrait.php
│       ├── TracedSystem.php
│       └── TracedDebug.php
└── Plugin/
    ├── ResponseHeaderPlugin.php
    └── CurlForwardPlugin.php
```