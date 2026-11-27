<?php

namespace App\Support\SafeHttp;

/**
 * Canonical failure classifications produced by SafeHttpClient checks.
 */
enum SafeHttpFailureReason: string
{
    case TIMEOUT = 'TIMEOUT';
    case DNS_FAILURE = 'DNS_FAILURE';
    case CONNECTION_REFUSED = 'CONNECTION_REFUSED';
    case HTTP_ERROR = 'HTTP_ERROR';
    case SSRF_BLOCKED = 'SSRF_BLOCKED';
    case TLS_ERROR = 'TLS_ERROR';
    case DEADLINE_EXCEEDED = 'DEADLINE_EXCEEDED';
    case MAX_REDIRECTS_EXCEEDED = 'MAX_REDIRECTS_EXCEEDED';
}
