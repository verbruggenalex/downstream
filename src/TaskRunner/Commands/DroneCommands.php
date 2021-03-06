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
     * {@inheritdoc}
     */
    public function getConfigurationFile()
    {
        return __DIR__.'/../../../config/inventory.yml';
    }

    /**
     * @command project:generate-drone
     */
    public function generateDrone(array $options = [
      'projects' => InputOption::VALUE_REQUIRED,
      'machines' => InputOption::VALUE_REQUIRED,
      'pipeline' => InputOption::VALUE_REQUIRED,
    ])
    {
        $inventory = $this->getConfig()->get('inventory');
        $projects = $this->getConfig()->get('projects');
        $machines = $this->getConfig()->get('machines');
        $pipeline = $this->getConfig()->get('pipeline');
        $github = $this->getConfig()->get('github');

        $php_version = 71;
        $drone = $this->taskWriteToFile('.drone.yml')
          ->textFromFile('config/drone.yml')
          ->place('php_version', $php_version);

        $machine_number = 1;
        $repos_per_machine =  round(count($projects) / $machines);

        foreach ($projects as $number => $project) {
            $machine_number = ($number >= $machine_number * $repos_per_machine) ? $machine_number + 1 : $machine_number;
            $owner = explode('/', $inventory[$project]['repository_url'])[3];
            $name = explode('/', $inventory[$project]['repository_url'])[4];
            $drone->textFromFile('config/' . $pipeline . '.yml')
            ->place('machine_name', 'machine-' . $machine_number)
            ->place('php_version', $php_version)
            ->place('repo_owner', $owner)
            ->place('repo_name', $name);
        }

        if ($machines >= 1) {
            $drone->line('matrix:');
            $drone->line('  MACHINE_NAME:');
            for ($x = 1; $x <= $machines; $x++) {
                $drone->line('    - machine-' . $x);
            }
            $drone->line('');
        }
        $this->taskGitStack()->stopOnFail()->checkout($pipeline)->merge('origin/master')->run();

        $drone->run();

        $this->taskGitStack()->stopOnFail()
         ->exec('git remote set-url origin https://' . $github['token'] . '@github.com/verbruggenalex/downstream.git')
         ->exec('git config --global user.email ' . $github['email'])
         ->exec('git config --global user.name ' . $github['name'])
         ->add('.drone.yml')->commit('Start new pipe.')->push('origin', $pipeline)->run();
    }

    /**
     * @command project:generate-backstop
     *
     * @option project   Project id.
     *
     * @param array $options
     */
    public function generateBackstopJsonFromInventory(array $options = [
      'project' => InputOption::VALUE_REQUIRED,
    ])
    {
        // Configuration.
        $inventory = $this->getConfig()->get('inventory');
        $repodir = $this->getConfig()->get('project.repodir');
        $projectId = $options['project'];
        $workingDir = $this->getConfig()->get('runner.working_dir');
        $projectBasedir = $repodir . '/' . $projectRepository;
        $backstopJsonFile = file_get_contents('config/backstop.json');
        $backstopJson = json_decode($backstopJsonFile, true);
        // Merge url into the default scenario.
        $defaultScenario = array_merge($backstopJson['scenarios'][0], array(
            'label' => $inventory[$projectId]['title'],
            'url' => $inventory[$projectId]['production_url'],
        ));
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