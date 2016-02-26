<?php
namespace MichielRoos\Composer\Downloader;

/**
 *  Copyright notice
 *
 *  ⓒ 2016 ℳichiel ℛoos ⊰ michiel@michielroos.com ⊱
 *  All rights reserved
 *
 *  This is free software; you can redistribute it and/or modify it under the
 *  terms of the GNU General Public License as published by the Free Software
 *  Foundation; either version 2 of the License, or (at your option) any later
 *  version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful, but
 *  WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 *  or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 *  more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 */

use Composer\Config;
use Composer\Downloader\GitDownloader;
use Composer\Package\PackageInterface;
use Composer\Util\Git as GitUtil;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;

/**
 * @author Michiel Roos <michiel@michielroos.com>
 */
class GitShallowDownloader extends GitDownloader
{
    private $hasStashedChanges = false;
    private $hasDiscardedChanges = false;
    private $gitUtil;

    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path, $url)
    {
        GitUtil::cleanEnv();
        $path = $this->normalizePath($path);

        $ref = $package->getSourceReference();
        $flag = Platform::isWindows() ? '/D ' : '';
        $command = 'git clone --no-checkout %s %s && cd ' . $flag . '%2$s && git remote add composer %1$s && git fetch composer';
        $this->io->writeError("    Cloning " . $ref);

        $commandCallable = function ($url) use ($ref, $path, $command) {
            return sprintf($command, ProcessExecutor::escape($url),
                ProcessExecutor::escape($path), ProcessExecutor::escape($ref));
        };

        $this->gitUtil->runCommand($commandCallable, $url, $path, true);
        if ($url !== $package->getSourceUrl()) {
            $url = $package->getSourceUrl();
            $this->process->execute(sprintf('git remote set-url origin %s',
                ProcessExecutor::escape($url)), $output, $path);
        }
        $this->setPushUrl($path, $url);

        if ($newRef = $this->updateToCommit($path, $ref,
            $package->getPrettyVersion(), $package->getReleaseDate())
        ) {
            if ($package->getDistReference() === $package->getSourceReference()) {
                $package->setDistReference($newRef);
            }
            $package->setSourceReference($newRef);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(
        PackageInterface $initial,
        PackageInterface $target,
        $path,
        $url
    ) {
        GitUtil::cleanEnv();
        if (!$this->hasMetadataRepository($path)) {
            throw new \RuntimeException('The .git directory is missing from ' . $path . ', see https://getcomposer.org/commit-deps for more information');
        }

        $ref = $target->getSourceReference();
        $this->io->writeError("    Checking out " . $ref);
        $command = 'git remote set-url composer %s && git fetch composer && git fetch --tags composer';

        $commandCallable = function ($url) use ($command) {
            return sprintf($command, ProcessExecutor::escape($url));
        };

        $this->gitUtil->runCommand($commandCallable, $url, $path);
        if ($newRef = $this->updateToCommit($path, $ref,
            $target->getPrettyVersion(), $target->getReleaseDate())
        ) {
            if ($target->getDistReference() === $target->getSourceReference()) {
                $target->setDistReference($newRef);
            }
            $target->setSourceReference($newRef);
        }
    }

    /**
     * Updates the given path to the given commit ref
     *
     * @param  string $path
     * @param  string $reference
     * @param  string $branch
     * @param  \DateTime $date
     *
     * @throws \RuntimeException
     * @return null|string       if a string is returned, it is the commit
     *     reference that was checked out if the original could not be found
     */
    protected function updateToCommit($path, $reference, $branch, $date)
    {
        $force = $this->hasDiscardedChanges || $this->hasStashedChanges ? '-f ' : '';

        // This uses the "--" sequence to separate branch from file parameters.
        //
        // Otherwise git tries the branch name as well as file name.
        // If the non-existent branch is actually the name of a file, the file
        // is checked out.
        $template = 'git checkout ' . $force . '%s -- && git reset --hard %1$s --';
        $branch = preg_replace('{(?:^dev-|(?:\.x)?-dev$)}i', '', $branch);

        $branches = null;
        if (0 === $this->process->execute('git branch -r', $output, $path)) {
            $branches = $output;
        }

        // check whether non-commitish are branches or tags, and fetch branches with the remote name
        $gitRef = $reference;
        if (!preg_match('{^[a-f0-9]{40}$}', $reference)
            && $branches
            && preg_match('{^\s+composer/' . preg_quote($reference) . '$}m',
                $branches)
        ) {
            $command = sprintf('git checkout ' . $force . '-B %s %s -- && git reset --hard %2$s --',
                ProcessExecutor::escape($branch),
                ProcessExecutor::escape('composer/' . $reference));
            if (0 === $this->process->execute($command, $output, $path)) {
                return;
            }
        }

        // try to checkout branch by name and then reset it so it's on the proper branch name
        if (preg_match('{^[a-f0-9]{40}$}', $reference)) {
            // add 'v' in front of the branch if it was stripped when generating the pretty name
            if (!preg_match('{^\s+composer/' . preg_quote($branch) . '$}m',
                    $branches) && preg_match('{^\s+composer/v' . preg_quote($branch) . '$}m',
                    $branches)
            ) {
                $branch = 'v' . $branch;
            }

            $command = sprintf('git checkout %s --',
                ProcessExecutor::escape($branch));
            $fallbackCommand = sprintf('git checkout ' . $force . '-B %s %s --',
                ProcessExecutor::escape($branch),
                ProcessExecutor::escape('composer/' . $branch));
            if (0 === $this->process->execute($command, $output, $path)
                || 0 === $this->process->execute($fallbackCommand, $output,
                    $path)
            ) {
                $command = sprintf('git reset --hard %s --',
                    ProcessExecutor::escape($reference));
                if (0 === $this->process->execute($command, $output, $path)) {
                    return;
                }
            }
        }

        $command = sprintf($template, ProcessExecutor::escape($gitRef));
        if (0 === $this->process->execute($command, $output, $path)) {
            return;
        }

        // reference was not found (prints "fatal: reference is not a tree: $ref")
        if (false !== strpos($this->process->getErrorOutput(), $reference)) {
            $this->io->writeError('    <warning>' . $reference . ' is gone (history was rewritten?)</warning>');
        }

        throw new \RuntimeException('Failed to execute ' . GitUtil::sanitizeUrl($command) . "\n\n" . $this->process->getErrorOutput());
    }
}
