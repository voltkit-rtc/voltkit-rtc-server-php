<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Transport;

/**
 * How the client talks HTTP. The built-in CurlTransport is the default; Psr18Transport adapts
 * a PSR-18 client; a test double answers from a script. A transport does one attempt: the
 * client owns retries, redaction and error mapping.
 *
 * Rules a transport must keep (spec 3.4, 3.5):
 *  - honour Request::$timeoutMs as one deadline for the whole exchange, headers and body;
 *  - never follow a redirect when Request::$followRedirects is false (the request carries the
 *    API key): hand the 3xx back as the Response;
 *  - throw TransportException for anything that stops a reply from being read in full (a
 *    refused connection, DNS, the deadline, a body that breaks off), with $timedOut set when
 *    the deadline passed; never throw for an HTTP status;
 *  - should stop reading a body past StreamClient::MAX_REPLY_BYTES (one declared longer
 *    need not be read at all) and return the Response with $tooLarge set: the client fails
 *    such a reply without decoding it, and refuses a longer body handed over whole too.
 */
interface TransportInterface
{
    /**
     * @throws TransportException when no complete reply could be read
     */
    public function send(Request $request): Response;
}
