<?php
/**
 * s3_probe.php — SigV4 HEAD bucket connectivity check for bootstrap.
 *
 * Exits 0 when the bucket responds to an authenticated HEAD and bucket
 * versioning is enabled. Auth/permission errors (4xx/5xx), transport
 * failures, or un-versioned buckets cause a non-zero exit so init fails
 * fast before Drupal starts.
 *
 * Standalone by design: runs before Drupal is installed and must not
 * depend on the autoloader. Uses only ext-curl and PHP core.
 */

$bucket    = getenv('DRX_S3_BUCKET')              ?: '';
$region    = getenv('DRX_S3_REGION')              ?: 'us-east-1';
$endpoint  = getenv('DRX_S3_ENDPOINT')            ?: '';
$accessKey = getenv('DRX_S3_ACCESS_KEY_ID')       ?: '';
$secretKey = getenv('DRX_S3_SECRET_ACCESS_KEY')   ?: '';
$forcePath = strtolower(getenv('DRX_S3_FORCE_PATH_STYLE') ?: '');

if ($bucket === '' || $accessKey === '' || $secretKey === '') {
    fwrite(STDERR, "s3_probe: missing required env (bucket/access-key/secret)\n");
    exit(2);
}

// Path-style defaults to true when a custom endpoint is supplied (MinIO,
// self-hosted S3) and false against AWS unless explicitly forced.
if ($forcePath === '') {
    $usePath = ($endpoint !== '');
} else {
    $usePath = in_array($forcePath, ['1', 'true', 'yes'], true);
}

if ($endpoint !== '') {
    $parts  = parse_url($endpoint);
    $scheme = $parts['scheme'] ?? 'https';
    $host   = $parts['host']   ?? '';
    if ($host === '') {
        fwrite(STDERR, "s3_probe: could not parse DRX_S3_ENDPOINT='$endpoint'\n");
        exit(2);
    }
    $hostPort = isset($parts['port']) ? "$host:{$parts['port']}" : $host;
} else {
    $scheme   = 'https';
    $host     = "s3.$region.amazonaws.com";
    $hostPort = $host;
}

if ($usePath) {
    $url          = "$scheme://$hostPort/$bucket";
    $signHost     = $hostPort;
    $canonicalUri = '/' . $bucket;
} else {
    $url          = "$scheme://$bucket.$hostPort";
    $signHost     = "$bucket.$hostPort";
    $canonicalUri = '/';
}

/**
 * Execute one SigV4-signed request.
 *
 * @return array{0:int,1:string,2:string} [httpCode, body, curlError]
 */
function s3_signed_request(
    string $method,
    string $url,
    string $canonicalUri,
    string $canonicalQuery,
    string $signHost,
    string $region,
    string $accessKey,
    string $secretKey
): array {
    $now         = gmdate('Ymd\\THis\\Z');
    $today       = substr($now, 0, 8);
    $service     = 's3';
    $payloadHash = hash('sha256', '');

    $canonicalHeaders = "host:$signHost\nx-amz-content-sha256:$payloadHash\nx-amz-date:$now\n";
    $signedHeaders    = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = "$method\n$canonicalUri\n$canonicalQuery\n$canonicalHeaders\n$signedHeaders\n$payloadHash";

    $algo         = 'AWS4-HMAC-SHA256';
    $scope        = "$today/$region/$service/aws4_request";
    $stringToSign = "$algo\n$now\n$scope\n" . hash('sha256', $canonicalRequest);

    $kDate      = hash_hmac('sha256', $today,         'AWS4' . $secretKey, true);
    $kRegion    = hash_hmac('sha256', $region,        $kDate,    true);
    $kService   = hash_hmac('sha256', $service,       $kRegion,  true);
    $kSigning   = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature  = hash_hmac('sha256', $stringToSign,  $kSigning);
    $authHeader = "$algo Credential=$accessKey/$scope, SignedHeaders=$signedHeaders, Signature=$signature";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_NOBODY         => ($method === 'HEAD'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $authHeader,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $now,
            'Host: ' . $signHost,
        ],
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = (string) curl_error($ch);
    curl_close($ch);

    return [$code, $body, $err];
}

[$headCode, $_unusedBody, $headErr] = s3_signed_request(
    'HEAD',
    $url,
    $canonicalUri,
    '',
    $signHost,
    $region,
    $accessKey,
    $secretKey
);

if ($headCode === 0) {
    fwrite(STDERR, "s3_probe: transport error contacting $url: $headErr\n");
    exit(3);
}
if (!($headCode >= 200 && $headCode < 400)) {
    fwrite(STDERR, "s3_probe: HEAD $url returned HTTP $headCode (check bucket name, region, credentials, network reach)\n");
    exit(4);
}

$versioningUrl = $url . '?versioning=';
[$verCode, $verBody, $verErr] = s3_signed_request(
    'GET',
    $versioningUrl,
    $canonicalUri,
    'versioning=',
    $signHost,
    $region,
    $accessKey,
    $secretKey
);

if ($verCode === 0) {
    fwrite(STDERR, "s3_probe: transport error checking versioning at $versioningUrl: $verErr\n");
    exit(3);
}
if (!($verCode >= 200 && $verCode < 400)) {
    fwrite(STDERR, "s3_probe: GET $versioningUrl returned HTTP $verCode (check ListBucket/GetBucketVersioning permissions)\n");
    exit(4);
}

if (!preg_match('/<Status>\\s*Enabled\\s*<\\/Status>/i', $verBody)) {
    fwrite(STDERR, "s3_probe: bucket $bucket is reachable but versioning is not enabled; enable bucket versioning for DR safety\n");
    exit(5);
}

fwrite(STDERR, "s3_probe: ok ($headCode) $url (versioning=enabled)\n");
exit(0);
