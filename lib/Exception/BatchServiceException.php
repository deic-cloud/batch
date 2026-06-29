<?php

declare(strict_types=1);

namespace OCA\Batch\Exception;

/**
 * Thrown when a call to the batch service fails at the TRANSPORT level —
 * connection refused, timeout, DNS/TLS failure, a non-2xx HTTP status, or a
 * missing/invalid client certificate.
 *
 * ApiController maps this to an HTTP 502 (Bad Gateway) with a {status:error,
 * data:{message}} body, mirroring the shape the frontend already handles.
 */
class BatchServiceException extends \RuntimeException {
}
