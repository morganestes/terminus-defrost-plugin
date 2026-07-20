<?php

declare(strict_types=1);

namespace MorganEstes\Terminus\Traits;

trait InputValidationTrait
{
    use UUIDTrait;
    use PantheonHelperTrait;

    /**
     * @param array $urlParts The parsed URL from {@see parse_url()}
     * @return string|null The site UUID, or null on error.
     */
    protected function parsePlatformUrl(array $urlParts): string|null
    {
        if (!isset($urlParts['host']) || !str_ends_with($urlParts['host'], self::PANTHEON_PLATFORM_DOMAIN)) {
            return null;
        }

        $subdomain = str_replace('.' . self::PANTHEON_PLATFORM_DOMAIN, '', $urlParts['host']);

        /* This regex is intentionally simple. It removes `dev-`, `test-`, or `live-` from the start.
         * It does not handle multidev environments perfectly, as their names are variable.
         * For `multidev-foo-bar-site`, it would need to know the site name `bar-site` to parse correctly,
         * which we don't have at this stage. This is a reasonable limitation given that we shouldn't get a multidev.
         */

        /** @var string|null $maybeSiteName The site name, or null if there's an error from preg_replace(). */
        return preg_replace(pattern: '/^(dev|test|live)-/i', replacement: '', subject: $subdomain, limit: 1);
    }

    /**
     * @param array $urlParts The parsed URL from {@see parse_url()}
     * @return string|null The site UUID, or null on error.
     */
    public function parseDashboardUrl(array $urlParts): string|null
    {
        if (!isset($urlParts['path']) || !str_ends_with($urlParts['host'], self::PANTHEON_DASHBOARD_DOMAIN)) {
            return null;
        }

        $uuidPattern = trim(self::UUID_REGEX, '/i');

        /* Dashboard URLs have (at least) two formats. This checks for the ones we know about:
         * https://dashboard.pantheon.io/workspace/{org-uuid}/cms-site/{site-uuid}/frozen
         * https://dashboard.pantheon.io/sites/{site-uuid}#dev
         */
        $dashboardPathRegex = "#/(?:sites|cms-site)/({$uuidPattern})#i";

        return preg_match($dashboardPathRegex, $urlParts['path'], $matches)
            ? $matches[1]
            : null;
    }
}
