<?php
declare(strict_types=1);

namespace MorganEstes\Terminus\Traits;

use DateTimeImmutable;
use DateTimeInterface;
use \Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Models\Workflow;

trait WorkflowProgressTrait
{
    use WorkflowProcessingTrait;


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
     * @param Workflow $workflow               The workflow to report on.
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
                "Dashboard URL: {$this->site->dashboardUrl()}",
                "Site URL: https://dev-{$this->site->getName()}.pantheonsite.io/",
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
