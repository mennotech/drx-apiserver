<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Validates client IP and Bearer token for the drx_litestream HTTP API.
 *
 * The expected token is read from DRX_LITESTREAM_API_TOKEN at request
 * time. If that variable is empty or unset the API is considered
 * unconfigured and every request is denied with 403.
 *
 * Client IP is resolved via $request->getClientIp(), which honours
 * Drupal's reverse_proxy_addresses setting. Configure
 * DRX_REVERSE_PROXY_IPS (comma-separated proxy IPs / CIDR ranges) to
 * teach Drupal which upstream proxies to trust for X-Forwarded-For
 * processing; the setting is written to trusted-hosts.settings.php
 * and takes effect on the next container restart.
 *
 * Allowed client IPs are read from DRX_LITESTREAM_API_ALLOWED_IPS
 * (comma-separated IPs or CIDR ranges). Defaults to 127.0.0.1,::1
 * (localhost only) when the variable is absent or empty.
 * IpUtils::checkIp() handles IPv4, IPv6, and CIDR notation.
 *
 * Bearer token comparison is constant-time (hash_equals) to prevent
 * timing side-channel attacks. The token should be a randomly-generated
 * secret of at least 128 bits injected via environment variable.
 *
 * This checker is gated to routes that declare the
 * `_drx_litestream_api_token` requirement so it does not interfere
 * with unrelated `_custom_access` usage elsewhere in the site.
 */
class ApiTokenAccessCheck implements AccessInterface {

  /**
   * Checks whether the request comes from an allowed IP with a valid token.
   */
  public function access(Request $request): AccessResultInterface {
    $token = (string) (getenv('DRX_LITESTREAM_API_TOKEN') ?: '');
    if ($token === '') {
      // Feature is opt-in: no token configured → API is disabled.
      return AccessResult::forbidden('DRX_LITESTREAM_API_TOKEN is not configured')
        ->setCacheMaxAge(0);
    }

    // Resolve the real client IP. Honours reverse_proxy_addresses when
    // DRX_REVERSE_PROXY_IPS is configured via trusted-hosts.settings.php.
    $clientIp = (string) ($request->getClientIp() ?? '');
    if ($clientIp === '' || !IpUtils::checkIp($clientIp, $this->resolveAllowedRanges())) {
      return AccessResult::forbidden('Client IP not in allowed list')
        ->setCacheMaxAge(0);
    }

    $auth = (string) ($request->headers->get('Authorization') ?? '');
    if (!str_starts_with($auth, 'Bearer ')) {
      return AccessResult::forbidden('Bearer token required')
        ->setCacheMaxAge(0);
    }

    $provided = substr($auth, 7);
    // Constant-time comparison prevents timing side-channels.
    if (!hash_equals($token, $provided)) {
      return AccessResult::forbidden('Invalid token')
        ->setCacheMaxAge(0);
    }

    return AccessResult::allowed()->setCacheMaxAge(0);
  }

  /**
   * Returns the list of allowed client IP ranges from the environment.
   *
   * Reads DRX_LITESTREAM_API_ALLOWED_IPS (comma-separated IPs or CIDR
   * ranges). Falls back to localhost-only (127.0.0.1 and ::1) when the
   * variable is unset or resolves to an empty list.
   *
   * @return string[]
   *   Non-empty list of IP address strings or CIDR ranges accepted by
   *   Symfony\Component\HttpFoundation\IpUtils::checkIp().
   */
  private function resolveAllowedRanges(): array {
    $raw = (string) (getenv('DRX_LITESTREAM_API_ALLOWED_IPS') ?: '127.0.0.1,::1');
    $ranges = array_values(array_filter(array_map('trim', explode(',', $raw))));
    return $ranges ?: ['127.0.0.1', '::1'];
  }

}
