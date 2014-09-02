<?php

namespace AppBundle\Command;

use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use AppBundle\Entity\Issue;

class IssueFinderCommand extends ContainerAwareCommand
{
    private $rootDir;

    protected function configure()
    {
        $this
            ->setName('issues:find')
            ->setDescription('Looks for DX issues in the configured repos')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->rootDir = __DIR__.'/../../../';
        $repositories = $this->getRepositoriesToScan();
        $em = $this->getContainer()->get('doctrine')->getManager();

        $output->writeln("Looking for DX Issues");
        $output->writeln("=====================\n");

        foreach ($repositories as $repository) {
            $output->writeln("> Scanning $repository");

            $results = $this->findIssues($repository);
            $this->saveIssues($em, $results);
        }
    }

    private function getRepositoriesToScan()
    {
        $repositories = Yaml::parse($this->rootDir.'/app/config/repositories.yml');

        return $repositories['repositories'];
    }

    private function getGitHubClient()
    {
        return new \Github\Client(
            new \Github\HttpClient\CachedHttpClient(array(
                'cache_dir' => $this->rootDir.'/app/cache/github'
            ))
        );
    }

    private function findIssues($repositoryUrl)
    {
        list($vendor, $repository) = explode('/', $repositoryUrl);

        $issuesLabelledAsDx = $this->getGitHubClient()
            ->api('issue')
            ->all($vendor, $repository, array(
                'labels' => 'DX',
                'state'  => 'all', // by default it only looks for 'open' issues
            )
        );

        $issuesWithDxInTheTitle = array();
        // $issuesWithDxInTheTitle = $this->getGitHubClient()
        //     ->api('issue')
        //     ->all($vendor, $repository, array(
        //         'q'      => 'DX',
        //         'in'     => 'title',
        //         'state'  => 'all',
        //     )
        // );

        return array_merge($issuesLabelledAsDx, $issuesWithDxInTheTitle);
    }

    private function saveIssues(EntityManager $em, $results)
    {
        foreach ($results as $result) {
            /** @var Issue $issue */
            $issue = $em->getRepository('AppBundle:Issue')->findOneBy(array(
                'githubId' => $result['id']
            ));

            if (null === $issue) {
                $issue = new Issue(array(
                    'githubId'   => $result['id'],
                    'title'      => $this->cleanIssueTitle($result['title']),
                    'body'       => $result['body'],
                    'url'        => $result['html_url'],
                    'repository' => $this->getIssueRepository($result['url']),
                    'author'     => $result['user']['login'],
                    'status'     => $this->getStatusForNewIssue($result),
                    'createdAt'  => new \DateTime($result['created_at']),
                    'comments'   => $result['comments'] ? $result['comments'] : 0,
                ));

            } else {
                $issue
                    ->setTitle($this->cleanIssueTitle($result['title']))
                    ->setBody($result['body'])
                    ->setStatus($this->getStatusForExistingIssue($issue, $result))
                    ->setComments($result['comments'])
                ;
            }

            $em->persist($issue);
        }

        $em->flush();
    }

    private function cleanIssueTitle($title)
    {
        // if the title starts with '[DX]' strip it for being redundant
        return preg_replace('/\[DX\] (.*)/i', '$1', $title);
    }

    private function getIssueRepository($repositoryAbsoluteUrl)
    {
        $matches = array();
        preg_match('~https://api.github.com/repos/(?<repository>.*)/issues/\d+~', $repositoryAbsoluteUrl, $matches);

        return $matches['repository'];
    }

    /**
     * The logic for setting the status of new issues is as follows:
     *
     *   - If GitHub status is closed -> status = finished
     *   - If GitHub status is not closed and the issue has comments -> status = discussing
     *   - Otherwise -> status = new
     */
    private function getStatusForNewIssue(array $issue)
    {
        if ('closed' === $issue['state']) {
            return Issue::STATUS_FINISHED;
        }

        if (count($issue['comments']) > 0) {
            return Issue::STATUS_DISCUSSING;
        }

        return Issue::STATUS_NEW;
    }

    /**
     * Changing the status for existing issues is mostly done by hand by
     * application managers. That's why the logic for setting the status
     * of existing issues is as follows:
     *
     * - If the updated issue status = closed -> change existing issue status to finished
     * - If the updated issue has comments and the existing issue status = new -> change existing issue status to discussing
     * - Otherwise, don't change the existing issue status
     */
    private function getStatusForExistingIssue(array $existingIssueData, array $updatedIssueData)
    {
        if ('closed' === $updatedIssueData['state']) {
            return Issue::STATUS_FINISHED;
        }

        if (Issue::STATUS_NEW == $existingIssueData['status'] && count($updatedIssueData['comments']) > 0) {
            return Issue::STATUS_DISCUSSING;
        }

        return $existingIssueData['status'];
    }
}
