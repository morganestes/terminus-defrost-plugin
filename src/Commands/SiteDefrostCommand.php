<?php

declare(strict_types=1);

/**
 * Creates a Terminus command to defrost sites in Pantheon.
 */

namespace MorganEstes\Terminus\Commands;

use AllowDynamicProperties;
use Consolidation\AnnotatedCommand\CommandData;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use MorganEstes\Terminus\Traits\PantheonHelperTrait;
use MorganEstes\Terminus\Traits\WorkflowProgressTrait;
use Pantheon\Terminus\Commands\{Site\SiteCommand};
use Pantheon\Terminus\Exceptions\{TerminusException, TerminusProcessException, TerminusUnsupportedSiteException};
use Pantheon\Terminus\Models\{Site};
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Creates a Terminus command to defrost sites in Pantheon.
 *
 */
#[AllowDynamicProperties]
class SiteDefrostCommand extends SiteCommand
{
    use WorkflowProgressTrait;
    use PantheonHelperTrait;

    private Site $site;

    /**
     * Unfreezes a Pantheon website for a given name, URL, or Site ID.
     *
     * @authorize
     * @interact
     *
     * @command site:defrost
     * @aliases site:thaw, thaw, snowman, site:unfreeze
     * @usage   terminus site:defrost <site_name>
     *
     * @param string $site_name Site name, UUID, or URL to unfreeze
     * @throws ContainerExceptionInterface
     * @throws GuzzleException
     * @throws NotFoundExceptionInterface
     * @throws TerminusException
     * @throws TerminusProcessException
     * @throws TerminusUnsupportedSiteException
     */
    public function siteDefrost(string $site_name): void
    {
        $site = $this->getSite();
        if (!$this->getSite()?->isFrozen()) {
            $this->io()->note([
                "No need to thaw, {$this->site->getName()} isn't frozen.",
                "If you don't see the site loading, try again later. It can take up to 15 minutes to thaw.",
                sprintf('Visit https://dev-%s.pantheonsite.io/ to view the site.', $this->site->getName()),
            ]);
            return;
        }

        $this->io()->info(sprintf('Thawing %s...', $this->site->getName()));

        $workflow = $this->site->getWorkflows()->create('unfreeze_site');
        // Default to now, in case we can't get a start time from the workflow.
        $startDateTime = new DateTimeImmutable();

        try {
            // It can take a moment for the workflow to be created and have a start time.
            sleep(2);
            $workflow->fetch();
            $startTime = $workflow->getStartedAt();

            if ($startTime) {
                $startDateTime = DateTimeImmutable::createFromFormat('U', (string)$startTime);
                $logTime = $startDateTime->format(DateTimeInterface::RFC3339);
                $this->io()->info(sprintf('[%s] Thaw started for %s.', $logTime, $this->site->getName()));
            } else {
                // Fallback if start time isn't available yet.
                $logTime = $startDateTime->format(DateTimeInterface::RFC3339);
                $this->io()->info(
                    sprintf(
                        '[%s] Thaw started for %s. Waiting for workflow to begin...',
                        $logTime,
                        $this->site->getName()
                    )
                );
            }

            $this->pollWorkflow($workflow);
        } catch (Exception $ex) {
            $this->io()->error($ex->getMessage());
            throw new TerminusProcessException(message: $ex->getMessage(), code: $ex->getCode());
        } finally {
            $this->reportWorkflowStatus($workflow, $startDateTime);
        }
    }
}
