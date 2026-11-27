<?php

namespace App\Support\SafeHttp\Contracts;

use App\Support\SafeHttp\TransportRequest;
use App\Support\SafeHttp\TransportResponse;

/**
 * Executes a single non-redirecting, proxy-isolated, IP-pinned HTTP/HTTPS request.
 */
interface HttpTransportInterface
{
    public function send(TransportRequest $request): TransportResponse;
}
