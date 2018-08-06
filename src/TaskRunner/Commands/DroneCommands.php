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
    use NuvoleWebTasks\Config\loadTasks;

    /**
     * @command project:generate-drone
     */
    public function generateDrone()
    {
        $php_version = 71;
        $drone = $this->taskWriteToFile('.drone.yml')
          ->textFromFile('config/toolkit.drone.yml')
          ->place('php_version', $php_version);
        $machines = $this->getConfig()->get('project.machines');
        $repositories = $this->getConfig()->get('project.repositories');
        $repos_per_machine =  round(count($repositories) / ($machines -1));
        foreach ($repositories as $number => $repo) {
            $machine_name = 'machine-' . round(($number + $repos_per_machine) / $repos_per_machine);
            $owner = explode('/', $repo)[0];
            $name = explode('/', $repo)[1];
            $drone->textFromFile('config/toolkit.phpcs.yml')
            ->place('machine_name', $machine_name)
            ->place('php_version', $php_version)
            ->place('repo_owner', $owner)
            ->place('repo_name', $name);
        }

        if ($machines > 1) {
            $drone->line('matrix:');
            $drone->line('  MACHINE_NAME:');
            for ($x = 1; $x <= $machines; $x++) {
                $drone->line('    - machine-' . $x);
            }
            $drone->line('');
        }
        $drone->run();
    }

    /**
     * @command project:run-phpcs
     *
     * @option repository   Project repository.
     *
     * @param array $options
     */
    public function runPhpcs(array $options = [
      'repository' => InputOption::VALUE_REQUIRED,
    ])
    {
        // Configuration.
        $repodir = $this->getConfig()->get('project.repodir');
        $projectRepository = $options['repository'];
        $workingDir = $this->getConfig()->get('runner.working_dir');
        $projectBasedir = $repodir . '/' . $projectRepository;

        $phpcs = $this->taskExecStack()->stopOnFail();
        if ($composerJson = file_get_contents($projectBasedir . '/composer.json')) {
            $composer = json_decode($composerJson, TRUE);
            if (isset($composer['require']['ec-europa/toolkit'])) {
                $phpcs->exec('./toolkit/phing test-run-phpcs -logger phing.listener.AnsiColorLogger')->dir($projectBasedir);
            }
            else {
            //if (isset($composer['require']['ec-europa/subsite-starterkit'])) {
                $phpcs->exec('./bin/phing setup-php-codesniffer -logger phing.listener.AnsiColorLogger')->dir($projectBasedir);
                $phpcs->exec('./bin/phpcs')->dir($projectBasedir);
            }
        }
        return $phpcs->run();
    }

    /**
     * @command project:create-project
     *
     * @option repository   Project repository.
     *
     * @param array $options
     */
    public function createProject(array $options = [
      'repository' => InputOption::VALUE_REQUIRED,
    ])
    {
        // Configuration.
        $cacheDir = $this->getConfig()->get('project.cachedir');
        $repodir = $this->getConfig()->get('project.repodir');
        $githubToken = $this->getConfig()->get('github.token');
        $projectRepository = $options['repository'];
        $projectBasedir = $repodir . '/' . $projectRepository;
        
        // To be made configurable.
        $gitBranch = 'master';
        $gitUrl = 'https://' . $githubToken . '@github.com/' . $projectRepository . '.git';
        $gitHash = preg_split ("/\s+/", $this->taskGitStack()->exec('ls-remote ' . $gitUrl . ' ' . $gitBranch)->printOutput(false)->run()->getMessage())[0];
        $gitCacheFile = $cacheDir . '/' . $projectRepository . '/build-dev-' . $gitHash . '.tar.gz';

        // Create project directory and repository.
        if ($this->_mkdir($projectBasedir)) {
            if (file_exists($gitCacheFile)) {
                $this->taskExecStack()->dir(dirname($projectBasedir))->exec("tar -zxf $gitCacheFile")->run();
            }
            else {
                $this->taskGitStack()->cloneShallow($gitUrl, $projectBasedir, 'master')->run();

                if ($composerJson = file_get_contents($projectBasedir . '/composer.json')) {
                    $this->taskComposerInstall()->workingDir($projectBasedir)->ansi()->run();
                    $composer = json_decode($composerJson, TRUE);
                    // if (isset($composer['require']['ec-europa/toolkit'])) {
                    //     $this->taskExec('./toolkit/phing build-platform build-subsite-dev')->dir($projectBasedir)->run();
                    // }
                }
                if ($this->_mkdir(dirname($gitCacheFile))) {
                    $this->taskExecStack()->dir(dirname($projectBasedir))->exec("tar -czf $gitCacheFile ./" . basename($projectBasedir) ."/")->run();
                }
            }
        }
    }
}