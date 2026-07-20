<?php

namespace MorganEstes\Terminus\Hooks;

use Consolidation\AnnotatedCommand\CommandData;
use GuzzleHttp\Exception\GuzzleException;
use MorganEstes\Terminus\Traits\PantheonHelperTrait;
use Pantheon\Terminus\Commands\Site\SiteCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class PostCommandHook extends SiteCommand
{
    use PantheonHelperTrait;

    /**
     * Checks to be sure it worked.
     *
     * @hook post-command site:defrost
     * @param $result
     * @param CommandData $commandData
     * @throws TerminusException
     * @throws GuzzleException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function done($result, CommandData $commandData): void
    {
        if ($this->io()->isDebug() || $this->output()->isDebug()) {
            /** @noinspection DebugFunctionUsageInspection */
            $this->output()->write((string) var_export($result, return: true));
        }
        if ($this->io()->isVerbose()) {
            /** @noinspection DebugFunctionUsageInspection */
            $this->stderr()->write((string) var_export($result, return: true));
        }

        $site = $this->getSite();
        if ($site?->isFrozen()) {
            throw new TerminusException('{site} is still frozen.', ['site' => $site->getName()]);
        }
    }
}
