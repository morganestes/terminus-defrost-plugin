<?php

namespace MorganEstes\Terminus\Traits;

use GuzzleHttp\Exception\GuzzleException;
use Pantheon\Terminus\DataStore\DataStoreAwareTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

trait PantheonHelperTrait
{
    use SiteAwareTrait;
    use DataStoreAwareTrait;

    public const string SITE_NAME_INPUT = 'site_name';
    public const string DATA_STORE_NAME = 'frozen_site';
    public const string PANTHEON_DASHBOARD_DOMAIN = 'dashboard.pantheon.io';
    public const string PANTHEON_PLATFORM_DOMAIN = 'pantheonsite.io';

    /**
     * Stores the site data as a array in the datastore.
     *
     * @param Site $site
     * @return void
     */
    public function storeSite(Site $site): void
    {
        if($site->valid()) {
            $this->getDataStore()->set(self::DATA_STORE_NAME, $site->serialize());
        }
    }

    /**
     * Gets the site's data out of storage.
     *
     * @return iterable|null
     */
    public function getStoredSite(): iterable|null
    {
        return $this->getDataStore()->has(self::DATA_STORE_NAME)
            ? $this->getDataStore()->get(self::DATA_STORE_NAME)
            : null;
    }

    /**
     */
    public function getSite(): Site|null
    {
        $stored_site_data = $this->getStoredSite();

        if (!$stored_site_data || !isset($stored_site_data['id'])) {
            return null;
        }

        try {
            return $this->sites()->get($stored_site_data['id']);
        } catch (TerminusException|GuzzleException|NotFoundExceptionInterface|ContainerExceptionInterface $e) {
            $this->io()->error($e->getMessage());
        }

        return null;
    }
}
