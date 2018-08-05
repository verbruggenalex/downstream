<?php

namespace VerbruggenAlex\Downstream\TaskRunner\Commands;

use OpenEuropa\TaskRunner\Commands\AbstractCommands;
use Consolidation\AnnotatedCommand\CommandData;
use NuvoleWeb\Robo\Task as NuvoleWebTasks;
use OpenEuropa\TaskRunner\Contract\FilesystemAwareInterface;
use OpenEuropa\TaskRunner\Tasks as TaskRunnerTasks;
use OpenEuropa\TaskRunner\Traits as TaskRunnerTraits;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;

/**
 * Class DroneCommands
 *
 * @package VerbruggenAlex\Downstream\TaskRunner\Commands
 */
class DroneCommands extends AbstractCommands implements FilesystemAwareInterface
{
    use TaskRunnerTraits\ConfigurationTokensTrait;
    use TaskRunnerTraits\FilesystemAwareTrait;
    use TaskRunnerTasks\CollectionFactory\loadTasks;
    use NuvoleWebTasks\Config\Php\loadTasks;

    /**
     * @command drone:generate-yml
     */
    public function generateYml()
    {
        $php_version = 71;
        $drone = $this->taskWriteToFile('.drone.yml')
          ->textFromFile('config/toolkit.drone.yml')
          ->place('php_version', $php_version);
        $repositories = $this->getConfig()->get('repositories');
        foreach ($repositories as $repo) {
            $owner = explode('/', $repo)[0];
            $name = explode('/', $repo)[1];
            $drone->textFromFile('config/toolkit.phpcs.yml')
            ->place('php_version', $php_version)
            ->place('repo_owner', $owner)
            ->place('repo_name', $name);
        }
        $drone->run();
    }

    /**
     * @command project:create-project
     */
    public function createProject()
    {
        // Configuration.
        $projectRepository = $this->getConfig()->get('project.repository');
        $projectBasedir = $this->getConfig()->get('project.basedir');
        $cacheDir = $this->getConfig()->get('project.cachedir');
        
        // To be made configurable.
        $gitBranch = 'master';
        $gitUrl = 'git@github.com:' . $projectRepository . '.git';
        //$gitHash = preg_split ("/\s+/", $this->taskGitStack()->exec('ls-remote ' . $gitUrl . ' ' . $gitBranch)->printOutput(false)->run()->getMessage())[0];

        // Create project directory and repository.
        if (!file_exists($projectBasedir) && $this->_mkdir($projectBasedir)) {
            $gitCacheFile = $cacheDir . '/' . $projectRepository . '/build-dev-' . $gitBranch . '.tar.gz';
            if (file_exists($gitCacheFile)) {
                $this->taskExtract($toolkitCachedVendor)->to($projectBasedir)->preserveTopDirectory(true)->run();
            }
            else {
                $this->taskGitStack()->cloneShallow($gitUrl, $projectBasedir, 'master')->run();
            }
        }
        
        if ($composerJson = file_get_contents($projectBasedir . '/composer.json')) {
            $this->taskComposerInstall()->workingDir($projectBasedir)->run();
            $composer = json_decode($composerJson, TRUE);
            if (isset($composer['require']['ec-europa/toolkit'])) {
                $this->taskExec()->dir($projectBasedir)->rawArg('./toolkit/phing build-platform build-subsite-dev')->run();
            }
        }
    }
}