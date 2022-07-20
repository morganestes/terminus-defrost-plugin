<?php

/**
 * Creates a Terminus command to defrost sites in Pantheon.
 */

namespace MorganEstes\Terminus\Commands;

use Pantheon\Terminus\Commands\Site\SiteCommand;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Exceptions\TerminusException;

class SiteDefrostCommand extends SiteCommand implements SiteAwareInterface
{
  use SiteAwareTrait;

  /**
   * The site identifier used for commands.
   *
   * @var string
   */
  private $siteName;

  /**
   * The current site instance.
   * 
   * @var Site
   */
  private $siteInstance;

  /**
   * Unfreezes a Pantheon website for a given name, URL, or Site ID.
   *
   * @authorize
   *
   * @command site:defrost
   * @aliases site:thaw, site:unfreeze
   * @usage terminus site:defrost <site>
   * 
   * @param string $site Site to unfreeze.
   */
  public function defrost(string $site)
  {
    try {
      $this->setSiteInstance($site)->runner();
    } catch (TerminusException $ex) {
      $this->io()->error($ex->getMessage());
    }
  }

  /**
   * Sets the normalized site name for this instance.
   *
   * @param string $siteName The name, URL, or ID of the site.
   * @return $this
   */
  protected function setSiteName(string $siteName): self
  {
    $this->siteName = $this->normalizeSiteName($siteName);

    return $this;
  }

  /**
   * Gets the site name for this run.
   * 
   * @return string The normalized site name.
   * @throws Exception If the site name isn't set.
   */
  protected function getSiteName(): string
  {
    if (!empty($this->siteName)) {
      return (string) $this->siteName;
    }

    return '';
  }

  /**
   * Sets up an instance of the Pantheon site.
   *
   * @param string $siteName The site name, URL, or ID.
   * @return $this
   */
  protected function setSiteInstance(string $siteName): self
  {
    try {
      $this->setSiteName($siteName);

      if (!$this->siteInstance instanceof Site) {
        $this->siteInstance = $this->getSite($this->getSiteName());
      }

      if (!$this->siteInstance instanceof Site) {
        throw new TerminusException(sprintf('Cannot find site %s', $siteName));
      }

      // Re-set the site name with the one in Pantheon's data.
      // $this->setSiteName($this->siteInstance->get('name'));
    } catch (TerminusException $ex) {
      $this->io()->error($ex->getMessage());
    }

    return $this;
  }

  /**
   * Gets the current instance of the site for this command run.
   * 
   * @return Site The Pantheon site instance.
   * @throws Exception If the site can't be found.
   */
  protected function getSiteInstance(): Site
  {
    if (!$this->siteInstance instanceof Site) {
      throw new TerminusException(sprintf('Could not find the site %s', $this->getSiteName()));
    }

    return $this->siteInstance;
  }

  /**
   * Gets the site name from a string.
   * 
   * @param string $maybeSiteName The URL, UUID, or name-like string.
   * @return string The name for use in site commands.
   * @throws Exception If a slug can't be generated.
   */
  public function normalizeSiteName(string $maybeSiteName): string
  {
    $siteName = trim($maybeSiteName);

    // No need to continue if we already have a site slug that matches what's trying to be set.
    if (!empty($this->getSiteName()) && $this->getSiteName() === $siteName) {
      return $siteName;
    }

    // If given a Pantheon dashboard link, try to extract the UUID.
    // If there's not a UUID in there, it'll just revert back to the passed $maybeSiteName.
    $siteName = $this->maybeGetUUIDFromURL($siteName);

    // Assume UUID format is a Pantheon Site ID and try to get the name directly.
    if ($this->isUUID($siteName)) {
      $site = $this->getSite($siteName);
      if ($site instanceof Site) {
        return $site->get('name');
      }
      return $siteName; // Revert to the passed UUID if we can't get the name. That probably means it's invalid, though.
    }

    try {
      // Try to clean up any URLs or environments passed with the site name.
      $siteName = preg_replace('#https?:\/\/#', '', $siteName );
      $siteName = preg_replace('#\.pantheonsite\.io/(?:.+)$#', '', $siteName);
      /* Sites will only be frozen if they have a live env, so we're going to ignore 'test', 'live', and multidev envs for now.
       * @todo Find a better RegEx to capture all env types while still allowing 'test-site-name', since many of our sites have
       * 'test' or 'testing' as the site name even though 'test' is a reserved env name.
       */
      $siteName = preg_replace('#(^dev-)|(\.dev$)#', '', $siteName);

      if (empty($siteName)) {
        throw new TerminusException(sprintf('Could not validate site name %s.', $siteName));
      }
    } catch (TerminusException $ex) {
      $this->io()->error($ex->getMessage());
    }

    return $siteName;
  }

  /**
   * Checks to see if this is a valid UUID format. It does not check for Site ID validity.
   * 
   * @param string $maybeUUID The string to check.
   * @return bool Whether this matches the UUID format.
   */
  public function isUUID(string $maybeUUID): bool
  {
    // This regex can probably be shortened, but this one only takes 16 steps.
    $regexp = '/[\da-f]{8}-(?:[\da-f]{4}-){3}[\da-f]{12}/i';

    return (1 === preg_match($regexp, trim($maybeUUID)));
  }

  /**
   * Tries to extract a UUID from a URL.
   * 
   * This is most useful when given a link to a dashboard page
   * @param string $url 
   * @return string 
   */
  public function maybeGetUUIDFromURL(string $url): string
  {
    $url = trim($url);
    // 18 steps when given a full dashboard URL.
    $regexp = '/[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}/i';

    preg_match($regexp, $url, $matches);

    if (!empty($matches) && $this->isUUID($matches[0])) {
      return $matches[0];
    }

    return $url;
  }

  /**
   * Runs the command to unfreeze a site.
   * 
   * @return $this
   * @throws Exception If the workflow fails.
   */
  private function thaw(): self
  {
    try {
      $this->getSiteInstance()->getWorkflows()->create('unfreeze_site');
    } catch (TerminusException $ex) {
      $this->io()->error($ex->getMessage());
    }

    return $this;
  }

  /**
   * Shows a friendly message about the frozen status of the site.
   * 
   * @return $this 
   */
  private function showFrozenMessage(): self
  {
    $chilly = $this->getSiteInstance()->isFrozen() ? 'yes' : 'no';
    $this->io()->note(sprintf('Is %1$s frozen? %2$s', $this->siteName, $chilly));

    return $this;
  }

  /**
   * Runs the commands to unfreeze a site.
   *
   * @return $this
   */
  private function runner()
  {
    $link_msg = sprintf('Visit https://dev-%s.pantheonsite.io/ to view the site.', $this->getSiteName());

    try {
      if (!$this->getSiteInstance()->isFrozen()) {
        $messages = [
          "No need to thaw, {$this->getSiteName()} isn't frozen.",
          "If you don't see the site loading, try again later. It can take up to 15 minutes to thaw.",
          $link_msg,
        ];
        $this->io()->block($messages, 'Note', 'comment');
      } else {
        $messages = [
          sprintf('Thawing %s...', $this->getSiteName()),
          $link_msg,
        ];
        $this->io()->block($messages, 'Note', 'comment');

        $this
          ->thaw()
          ->showFrozenMessage();
      }
    } catch (TerminusException $ex) {
      $this->io()->error($ex->getMessage());
    }

    return $this;
  }
}
