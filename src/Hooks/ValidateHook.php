<?php

namespace MorganEstes\Terminus\Hooks;

use Consolidation\AnnotatedCommand\CommandData;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use MorganEstes\Terminus\Traits\InputValidationTrait;
use MorganEstes\Terminus\Traits\PantheonHelperTrait;
use MorganEstes\Terminus\Traits\UUIDTrait;
use Pantheon\Terminus\Commands\Site\SiteCommand;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Psr\Container\ContainerExceptionInterface;

class ValidateHook extends SiteCommand
{
    use InputValidationTrait;
    use PantheonHelperTrait;
    use UUIDTrait;

    /**
     * Validates and normalizes the input as a site name.
     *
     * @hook validate site:defrost
     *
     * @param CommandData $commandData
     * @throws ContainerExceptionInterface
     * @throws GuzzleException
     * @throws TerminusException
     * @throws InvalidArgumentException
     */
    public function normalizeSiteName(CommandData $commandData): void
    {
        $input = $commandData->input();
        $originalInput = $input->getArgument(self::SITE_NAME_INPUT);

        $siteIdentifier = trim((string)$originalInput);

        // 1. Check if it's a UUID by itself. If so, we're done.
        if (!self::isUUID($siteIdentifier) || str_starts_with($siteIdentifier, 'http')) {
            // 2. Check if it's a URL.
            $urlParts = parse_url($siteIdentifier);
            if (is_array($urlParts) && isset($urlParts['host'])) {
                if (str_ends_with($urlParts['host'], self::PANTHEON_DASHBOARD_DOMAIN)) {
                    $siteIdentifier = $this->parseDashboardUrl($urlParts) ?? $siteIdentifier;
                } elseif (str_ends_with($urlParts['host'], self::PANTHEON_PLATFORM_DOMAIN)) {
                    $siteIdentifier = $this->parsePlatformUrl($urlParts) ?? $siteIdentifier;
                } else {
                    // It's a custom domain, which we don't support for discovery.
                    throw new InvalidArgumentException('Custom domains are not supported for site discovery.');
                }
            } // 3. If not a URL, handle <site>.<env> format.
            elseif (str_contains($siteIdentifier, '.')) {
                $lastDotPosition = strrpos($siteIdentifier, '.');
                $siteIdentifier = substr($siteIdentifier, 0, $lastDotPosition);
            }
        }

        if (empty($siteIdentifier)) {
            $err_msg = sprintf('Could not determine a valid site name from "%s".', $originalInput);
            $this->io()->error($err_msg);
            throw new InvalidArgumentException($err_msg, 1);
        }

        // Now that we have a clean identifier, fetch the site object.
        /** @var Site $site */
        $site = $this->sites()->get($siteIdentifier);

        // Store the serialied site object in the data store for other hooks and the command itself.
        // Retrieve the hydrated site with PantheonHelperTrait::getSite().
        $this->storeSite($site);

        // Update the input argument to the canonical site name for consistency.
        $input->setArgument(self::SITE_NAME_INPUT, $site->getName());
    }
}
