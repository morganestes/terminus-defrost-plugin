<?php

declare(strict_types=1);

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
use Pantheon\Terminus\Exceptions\{TerminusException, TerminusNotFoundException, TerminusProcessException};
use Pantheon\Terminus\Models\{Site, Workflow};
use Symfony\Component\Console\Input\InputInterface;
use Psr\Container\{ContainerExceptionInterface, NotFoundExceptionInterface};

/**
 * Creates a Terminus command to defrost sites in Pantheon.
 */
class SiteDefrostCommand extends SiteCommand
{
    use WorkflowProcessingTrait;

    /**
     * A regular expression for matching a UUID.
     */
    private const UUID_REGEX = '/[\da-f]{8}-(?:[\da-f]{4}-){3}[\da-f]{12}/i';

    /**
     * The current site instance.
     */
    private Site $site;

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
     * @hook init site:defrost
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     */
    public function normalizeSiteName(InputInterface $input): void
    {
        $originalInput = trim($input->getArgument('site'));
        $siteIdentifier = $originalInput;

        // 1. Check if it's a UUID. If so, we're done.
        if ($this->isUUID($originalInput)) {
            $input->setArgument('site', $originalInput);
            return;
        }

        // 2. Check if it's a URL and parse it.
        $urlParts = parse_url($originalInput);
        if (is_array($urlParts) && isset($urlParts['host'])) {
            // 2a. Handle Pantheon Dashboard URLs (e.g., https://dashboard.pantheon.io/sites/UUID)
            if (str_ends_with($urlParts['host'], 'dashboard.pantheon.io')) {
                if (isset($urlParts['path'])) {
                    // Match /sites/{uuid} or /.../cms-site/{uuid} while ignoring workspace UUIDs.
                    $uuidPattern = trim(self::UUID_REGEX, '/i');
                    $dashboardPathRegex = '#/(?:sites|cms-site)/(' . $uuidPattern . ')#i';
                    if (preg_match($dashboardPathRegex, $urlParts['path'], $matches)) {
                        $siteIdentifier = $matches[1];
                    }
                }
            } // 2b. Handle Pantheon Platform URLs (e.g., https://dev-my-site.pantheonsite.io)
            elseif (str_ends_with($urlParts['host'], '.pantheonsite.io')) {
                // Remove the .pantheonsite.io suffix.
                $subdomain = str_replace('.pantheonsite.io', '', $urlParts['host']);
                // This regex is intentionally simple. It removes `dev-`, `test-`, or `live-` from the start.
                // It does not handle multidev environments perfectly, as their names are variable.
                // For `multidev-foo-bar-site`, it would need to know the site name `bar-site` to parse correctly,
                // which we don't have at this stage. This is a reasonable limitation.
                $siteIdentifier = preg_replace('/^(dev|test|live)-/i', '', $subdomain);
            }
        } // 3. Handle <site>.<env> format if it's not a URL.
        elseif (str_contains($originalInput, '.')) {
            $lastDotPosition = strrpos($originalInput, '.');
            // Consider the part before the last dot as the site name.
            // This is more robust than explode() for site names containing dots.
            $siteIdentifier = substr($originalInput, 0, $lastDotPosition);
        }

        if (empty($siteIdentifier)) {
            $this->io()->warning(
                sprintf('Could not determine a valid site name from "%s". Using original input.', $originalInput)
            );
            $siteIdentifier = $originalInput;
        }

        $input->setArgument('site', $siteIdentifier);
    }

    /**
     * Checks to see if this is a valid UUID format. It does not check for Site ID validity.
     *
     * @param string $maybeUUID The string to check.
     * @return bool Whether this matches the UUID format.
     */
    public function isUUID(string $maybeUUID): bool
    {
        return (preg_match(self::UUID_REGEX, trim($maybeUUID)) === 1);
    }

    /**
     * Checks to be sure it worked.
     *
     * @hook post-command site:defrost
     * @throws TerminusException
     */
    public function done($result, CommandData $commandData): void
    {
        if ($this->io()->isDebug()) {
            /** @noinspection ForgottenDebugOutputInspection */
            /** @noinspection DebugFunctionUsageInspection */
            var_dump($result);
        }
        if ($this->io()->isVerbose()) {
            /** @noinspection DebugFunctionUsageInspection */
            $this->stderr()->write(var_export($result, return: true));
        }

        // If the $site property is not initialized, it means the command failed
        // before the main logic could run (e.g., site not found).
        // In this case, we should just exit gracefully as the error has already
        // been displayed.
        if (!isset($this->site)) {
            return;
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
     * @aliases site:thaw, site:unfreeze
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
                "No need to thaw, {$this->getSiteName()} isn't frozen.",
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
                $this->io()->info(sprintf('[%s] Thaw started for %s.', $logTime, $this->getSiteName()));
            } else {
                // Fallback if start time isn't available yet.
                $logTime = $startDateTime->format(DateTimeInterface::RFC3339);
                $this->io()->info(
                    sprintf(
                        '[%s] Thaw started for %s. Waiting for workflow to begin...',
                        $logTime,
                        $this->getSiteName()
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
     * @throws TerminusException
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
    private function getElapsedMessage(
        DateTimeImmutable $startDateTime,
        int|null $finishTimestamp,
        string $format
    ): string {
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
