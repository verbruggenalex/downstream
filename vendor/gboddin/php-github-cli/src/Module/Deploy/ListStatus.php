<?php
namespace Gbo\PhpGithubCli\Module\Deploy;

use Gbo\PhpGithubCli\GithubCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListStatus extends GithubCommand
{

    /**
     * Symfony cli module config
     */
    protected function githubConfigure()
    {
        $this
            ->setName('deploy:status-list')
            ->setDescription('List statuses for a deployment')
            ->addArgument('org', InputArgument::REQUIRED, 'Repo owner')
            ->addArgument('repo', InputArgument::REQUIRED, 'Repo name')
            ->addArgument('deploy_id', InputArgument::REQUIRED, 'Status ID');
    }

    /**
     * githubExec implementation
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return array
     * @throws \Github\Exception\MissingArgumentException
     */
    protected function githubExec(InputInterface $input, OutputInterface $output)
    {
        return self::$githubClient->api('deployment')->getStatuses(
            $input->getArgument('org'),
            $input->getArgument('repo'),
            $input->getArgument('deploy_id')
        );
    }

    protected function humanOutput(OutputInterface $output, $result)
    {
        $deployment =
        $table = new Table($output);
        $table->setHeaders(['ID', 'Status','Description','User','Date']);
        foreach ($result as $deployment) {
            $table->addRow(
                [$deployment['id'],$deployment['state'],
                    $deployment['description'],$deployment['creator']['login'], $deployment['created_at']]
            );
        }
        $table->render();
    }
}
