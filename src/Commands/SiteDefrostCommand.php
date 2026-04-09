<?php

/**
 * Creates a Terminus command to defrost sites in Pantheon.
 */

namespace MorganEstes\Terminus\Commands;

use Consolidation\AnnotatedCommand\CommandData;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Pantheon\Terminus\Commands\{Site\SiteCommand, WorkflowProcessingTrait};
use Symfony\Component\Console\Input\InputInterface;
use Pantheon\Terminus\Exceptions\{TerminusException, TerminusNotFoundException, TerminusProcessException};
use Pantheon\Terminus\Models\{Site, Workflow};
use Psr\Container\{ContainerExceptionInterface, NotFoundExceptionInterface};

/**
 * Creates a Terminus command to defrost sites in Pantheon.
 */
class SiteDefrostCommand extends SiteCommand
{
    use WorkflowProcessingTrait;

    /**
     * The current site instance.
     */
    private Site $site;

    /**
     * Sets up the runner.
     *
     * @hook init site:defrost
     * @throws TerminusNotFoundException
     */
    public function preCommand(InputInterface $input): void
    {
        $this->setSite($input->getArgument('site'));
    }

    /**
     * Gets the site name for this run.
     *
     * @return string The normalized site name.
     */
    protected function getSiteName(): string
    {
        return $this->site->getName();
    }

    /**
     * Sets up an instance of the Pantheon site.
     *
     * @param string $nameOrUUID The site name or UUID.
     * @throws TerminusNotFoundException
     */
    protected function setSite(string $nameOrUUID): void
    {
        $io = $this->io();
        $logger = $this->log();

        try {
            $this->site = $this->sites()->get($nameOrUUID);
        } catch (GuzzleException|TerminusException|NotFoundExceptionInterface|ContainerExceptionInterface $e) {
            $logger->critical('Could not find the site identified by {siteName}.', [
                'siteName' => $nameOrUUID,
            ]);
            $io->error($e->getMessage());

            if ($io->isDebug()) {
                $logger->debug($e->getTraceAsString());
            }

            throw new TerminusNotFoundException(message: $e->getMessage(), code: $e->getCode());
        }
    }

    /**
     * Gets the current instance of the site for this command run.
     *
     * @return Site The Pantheon site instance.
     */
    protected function getSite(): Site
    {
        return $this->site;
    }

    /**
     * Validates and normalizes the input as a site name.
     *
     * @hook validate site:defrost
     *
     * @param CommandData $commandData
     */
    public function normalizeSiteName(CommandData $commandData): void
    {
        $arg1 = $commandData->input()->getFirstArgument();
        $siteName = $arg1;

        // Working our way down from worst to first...
        if (str_starts_with($arg1, 'http')) {
            $maybeUUID = $this->maybeGetUUIDFromURL($arg1);
            if ($this->isUUID($maybeUUID)) {
                $siteName = $maybeUUID;
            }
        }

        // If given a Pantheon dashboard link, try to extract the UUID.
        // If there's not a UUID in there, it'll just revert back to the passed $arg1.

        // Assume UUID format is a Pantheon Site ID and try to get the name directly.

// @todo finish cleaning up the refactoring and find a better function for parsing the site name.
        try {
            // Try to clean up any URLs or environments passed with the site name.
            $siteName = preg_replace('#^https?://#', '', $siteName);
            $siteName = preg_replace('#\.pantheonsite\.io/.*$#', '', $siteName);
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


        $commandData->input()->setArgument('site', $siteName);
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

        return (preg_match($regexp, trim($maybeUUID)) === 1);
    }

    /**
     * Tries to extract a UUID from a URL.
     *
     * This is most useful when given a link to a dashboard page
     *
     * @param string $url The URL string to parse.
     * @return string The extracted UUID or the original URL if no UUID is found.
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
     * Check to be sure it worked
     *
     * @hook post-command site:defrost
     * @throws TerminusException
     */
    public function done($result, CommandData $commandData): void
    {
        if ($this->io()->isDebug()) {
            var_dump($result);
        }
        if ($this->io()->isVerbose()) {
            $this->stderr()->write(var_export($result, return: true));
        }
        if ($this->getSite()->isFrozen()) {
            throw new TerminusException('{site} is still frozen.', ['site' => $this->getSiteName()]);
        }

        $chilly = $this->getSite()->isFrozen() ? 'yes' : 'no';
        $this->io()->note(sprintf('Is %s frozen? %s', $this->getSiteName(), $chilly));
    }

    /**
     * Unfreezes a Pantheon website for a given name, URL, or Site ID.
     *
     * @authorize
     *
     * @command site:defrost
     * @aliases site:thaw, site:unfreeze, thaw
     * @usage   terminus site:defrost <site>
     *
     * @param string $site Site name, URL, or ID to unfreeze.
     * @throws TerminusException
     * @throws TerminusProcessException
     */
    public function siteDefrost(string $site): void
    {
        if (!$this->getSite()->isFrozen()) {
            $this->io()->note([
                "No need to thaw, {$site} isn't frozen.",
                "If you don't see the site loading, try again later. It can take up to 15 minutes to thaw.",
                sprintf('Visit https://dev-%s.pantheonsite.io/ to view the site.', $this->getSiteName()),
            ]);
            return;
        }

        $this->io()->info(sprintf('Thawing %s...', $this->getSiteName()));

        $workflow = $this->getSite()->getWorkflows()->create('unfreeze_site');
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
                $this->io()->info(sprintf('[%s] Thaw started for %s.', $logTime, $site));
            } else {
                // Fallback if start time isn't available yet.
                $logTime = $startDateTime->format(DateTimeInterface::RFC3339);
                $this->io()->info(
                    sprintf('[%s] Thaw started for %s. Waiting for workflow to begin...', $logTime, $site)
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

    /**
     * Polls the workflow until it is finished.
     *
     * @param Workflow $workflow The workflow to poll.
     */
    private function pollWorkflow(Workflow $workflow): void
    {
        do {
            sleep(30);
            $workflow->fetch(); // Refresh workflow data to get the latest status.

            $logTime = (new DateTimeImmutable())->format(DateTimeInterface::RFC3339);
            $this->io()->info(sprintf('[%s] %s', $logTime, $workflow->getStatus()));
        } while (!$workflow->isFinished());
    }

    /**
     * Reports the final status of the workflow.
     *
     * @param Workflow $workflow The workflow to report on.
     * @param DateTimeImmutable $startDateTime The time the process started.
     */
    private function reportWorkflowStatus(Workflow $workflow, DateTimeImmutable $startDateTime): void
    {
        // Ensure we have the final workflow state.
        $workflow->fetch();

        $finalLogTime = (new DateTimeImmutable())->format(DateTimeInterface::RFC3339);

        if ($workflow->isSuccessful()) {
            $elapsedMessage = $this->getElapsedMessage(
                $startDateTime,
                $workflow->getFinishedAt(),
                'completed in %s'
            );
            $this->io()->success([
                sprintf('[%s] Unfreeze successful! (%s)', $finalLogTime, $elapsedMessage),
                $workflow->getMessage(),
                "Dashboard URL: {$this->getSite()->dashboardUrl()}",
                "Site URL: https://dev-{$this->getSiteName()}.pantheonsite.io/",
            ]);
        } else {
            $elapsedMessage = $this->getElapsedMessage(
                $startDateTime,
                null, // The operation failed, so there's no finish time.
                'failed after %s'
            );
            $this->io()->error([
                sprintf('[%s] The unfreeze operation failed. (%s)', $finalLogTime, $elapsedMessage),
                $workflow->getMessage()
            ]);
        }
    }

    /**
     * Calculates and formats the elapsed time message.
     *
     * @param DateTimeImmutable $startDateTime
     * @param int|null $finishTimestamp
     * @param string $format
     * @return string
     */
    private function getElapsedMessage(DateTimeImmutable $startDateTime, ?int $finishTimestamp, string $format): string
    {
        $endDateTime = $finishTimestamp
            ? DateTimeImmutable::createFromFormat('U', (string)$finishTimestamp)
            : new DateTimeImmutable();

        if (!$endDateTime) {
            // Fallback if createFromFormat fails.
            $endDateTime = new DateTimeImmutable();
        }

        $interval = $endDateTime->diff($startDateTime);
        return sprintf($format, $interval->format('%I minutes and %S seconds'));
    }
}
