<?php

/**
 * This file is part of Gush.
 *
 * (c) Luis Cordova <cordoval@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gush\Adapter;

use Github\Client;
use Github\HttpClient\CachedHttpClient;
use Github\ResultPager;
use Gush\Config;
use Gush\Exception\AdapterException;
use Guzzle\Plugin\Log\LogPlugin;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Aaron Scherer
 * @author Sebastiaan Stok <s.stok@rollerscapes.net>
 */
class GitHubAdapter extends BaseAdapter
{
    /**
     * @var string|null
     */
    protected $url;

    /**
     * @var string|null
     */
    protected $domain;

    /**
     * @var Client|null
     */
    private $client;

    /**
     * @var string
     */
    protected $authenticationType = Client::AUTH_HTTP_PASSWORD;

    /**
     * {@inheritdoc}
     */
    public function __construct(Config $configuration)
    {
        parent::__construct($configuration);

        $this->client = $this->buildGitHubClient();
    }

    /**
     * {@inheritdoc}
     */
    public static function getName()
    {
        return 'github';
    }

    /**
     * @return Client
     */
    protected function buildGitHubClient()
    {
        $config = $this->configuration->get('github');
        $cachedClient = new CachedHttpClient(
            [
                'cache_dir' => $this->configuration->get('cache-dir'),
                'base_url'  => $config['base_url'],
            ]
        );

        $client = new Client($cachedClient);

        if (false !== getenv('GITHUB_DEBUG')) {
            $logPlugin = LogPlugin::getDebugPlugin();
            $httpClient = $client->getHttpClient();
            $httpClient->addSubscriber($logPlugin);
        }

        $client->setOption('base_url', $config['base_url']);
        $this->url = rtrim($config['base_url'], '/');
        $this->domain = rtrim($config['repo_domain_url'], '/');

        return $client;
    }

    /**
     * {@inheritdoc}
     */
    public static function doConfiguration(OutputInterface $output, DialogHelper $dialog)
    {
        $config = [];

        $output->writeln('<comment>Enter your GitHub URL: </comment>');
        $config['base_url'] = $dialog->askAndValidate(
            $output,
            'Api url [https://api.github.com/]: ',
            function ($url) {
                return filter_var($url, FILTER_VALIDATE_URL);
            },
            false,
            'https://api.github.com/'
        );

        $config['repo_domain_url'] = $dialog->askAndValidate(
            $output,
            'Repo domain url [https://github.com]: ',
            function ($field) {
                return $field;
            },
            false,
            'https://github.com'
        );

        return $config;
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate()
    {
        $credentials = $this->configuration->get('authentication');

        if (Client::AUTH_HTTP_PASSWORD === $credentials['http-auth-type']) {
            $this->client->authenticate(
                $credentials['username'],
                $credentials['password-or-token'],
                $credentials['http-auth-type']
            );

            return;
        }

        $this->client->authenticate(
            $credentials['password-or-token'],
            $credentials['http-auth-type']
        );

        $this->authenticationType = Client::AUTH_HTTP_TOKEN;

        return;
    }

    /**
     * {@inheritdoc}
     */
    public function isAuthenticated()
    {
        if (Client::AUTH_HTTP_PASSWORD === $this->authenticationType) {
            return is_array(
                $this->client->api('authorizations')->all()
            );
        }

        return is_array($this->client->api('me')->show());
    }

    /**
     * {@inheritdoc}
     */
    public function getTokenGenerationUrl()
    {
        return sprintf('%s/settings/applications', $this->url);
    }

    /**
     * {@inheritdoc}
     */
    public function createFork($org)
    {
        $api = $this->client->api('repo');

        $result = $api->forks()->create(
            $this->getUsername(),
            $this->getRepository(),
            ['org' => $org]
        );

        return [
            'git_url' => $result['ssh_url'],
            'html_url' => $result['html_url'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function openIssue($subject, $body, array $options = [])
    {
        $api = $this->client->api('issue');

        return $api->create(
            $this->getUsername(),
            $this->getRepository(),
            array_merge($options, ['title' => $subject, 'body' => $body])
        )['number'];
    }

    /**
     * {@inheritdoc}
     */
    public function getIssue($id)
    {
        $api = $this->client->api('issue');

        $issue = $api->show(
            $this->getUsername(),
            $this->getRepository(),
            $id
        );

        return [
            'url'          => $this->getIssueUrl($id),
            'number'       => $id,
            'state'        => $issue['state'],
            'title'        => $issue['title'],
            'body'         => $issue['body'],
            'user'         => $issue['user']['login'],
            'labels'       => $this->getValuesFromNestedArray($issue['labels'], 'name'),
            'assignee'     => $issue['assignee']['login'],
            'milestone'    => $issue['milestone']['title'],
            'created_at'   => !empty($issue['created_at']) ? new \DateTime($issue['created_at']) : null,
            'updated_at'   => !empty($issue['updated_at']) ? new \DateTime($issue['updated_at']) : null,
            'closed_by'    => !empty($issue['closed_by']) ? $issue['closed_by']['login'] : null,
            'pull_request' => isset($issue['pull_request']),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getIssueUrl($id)
    {
        return sprintf('%s/%s/%s/issues/%d', $this->domain, $this->getUsername(), $this->getRepository(), $id);
    }

    /**
     * {@inheritdoc}
     */
    public function getIssues(array $parameters = [], $page = 1, $perPage = 30)
    {
        // FIXME is not respecting the pagination

        $pager = new ResultPager($this->client);
        $fetchedIssues = $pager->fetchAll(
            $this->client->api('issue'),
            'all',
            [
                $this->getUsername(),
                $this->getRepository(),
                $parameters
            ]
        );

        $issues = [];

        foreach ($fetchedIssues as $issue) {
            $issues[] = [
                'url'          => $this->getIssueUrl($issue['number']),
                'number'       => $issue['number'],
                'state'        => $issue['state'],
                'title'        => $issue['title'],
                'body'         => $issue['body'],
                'user'         => $issue['user']['login'],
                'labels'       => $this->getValuesFromNestedArray($issue['labels'], 'name'),
                'assignee'     => $issue['assignee']['login'],
                'milestone'    => $issue['milestone']['title'],
                'created_at'   => !empty($issue['created_at']) ? new \DateTime($issue['created_at']) : null,
                'updated_at'   => !empty($issue['updated_at']) ? new \DateTime($issue['updated_at']) : null,
                'closed_by'    => !empty($issue['closed_by']) ? $issue['closed_by']['login'] : null,
                'pull_request' => isset($issue['pull_request']),
            ];
        }

    }

    /**
     * {@inheritdoc}
     */
    public function updateIssue($id, array $parameters)
    {
        $api = $this->client->api('issue');

        $api->update(
            $this->getUsername(),
            $this->getRepository(),
            $id,
            $parameters
        );
    }

    /**
     * {@inheritdoc}
     */
    public function closeIssue($id)
    {
        $this->updateIssue($id, ['state' => 'closed']);
    }

    /**
     * {@inheritdoc}
     */
    public function createComment($id, $message)
    {
        $api = $this
            ->client
            ->api('issue')
            ->comments()
        ;

        return $api->create(
            $this->getUsername(),
            $this->getRepository(),
            $id,
            ['body' => $message]
        )['html_url'];
    }

    /**
     * {@inheritdoc}
     */
    public function getComments($id)
    {
        $pager = new ResultPager($this->client);

        $fetchedComments = $pager->fetchAll(
            $this->client->api('issue')->comments(),
            'all',
            [
                $this->getUsername(),
                $this->getRepository(),
                $id,
            ]
        );

        $comments = [];

        foreach ($fetchedComments as $comment) {
            $comments[] = [
                'id'         => $comment['number'],
                'url'        => $comment['html_url'],
                'body'       => $comment['body'],
                'user'       => $comment['user']['login'],
                'created_at' => !empty($comment['created_at']) ? new \DateTime($comment['created_at']) : null,
                'updated_at' => !empty($comment['updated_at']) ? new \DateTime($comment['updated_at']) : null,
            ];
        }

        return $comments;
    }

    /**
     * {@inheritdoc}
     */
    public function getLabels()
    {
        $api = $this
            ->client
            ->api('issue')
            ->labels()
        ;

        return $this->getValuesFromNestedArray($api->all(
            $this->getUsername(),
            $this->getRepository()
        ), 'name');
    }

    /**
     * {@inheritdoc}
     */
    public function getMilestones(array $parameters = [])
    {
        $api = $this
            ->client
            ->api('issue')
            ->milestones()
        ;

        return $this->getValuesFromNestedArray($api->all(
            $this->getUsername(),
            $this->getRepository(),
            $parameters
        ), 'title');
    }

    /**
     * {@inheritdoc}
     */
    public function openPullRequest($base, $head, $subject, $body, array $parameters = [])
    {
        $api = $this->client->api('pull_request');

        return $api->create(
            $this->getUsername(),
            $this->getRepository(),
            array_merge(
                $parameters,
                [
                    'base'  => $base,
                    'head'  => $head,
                    'title' => $subject,
                    'body'  => $body,
                ]
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getPullRequest($id)
    {
        $api = $this->client->api('pull_request');

        $pr = $api->show(
            $this->getUsername(),
            $this->getRepository(),
            $id
        );

        return [
            'url'          => $pr['html_url'],
            'number'       => $pr['number'],
            'state'        => $pr['state'],
            'title'        => $pr['title'],
            'body'         => $pr['body'],
            'labels'       => [],
            'milestone'    => null,
            'created_at' => !empty($pr['created_at']) ? new \DateTime($pr['created_at']) : null,
            'updated_at' => !empty($pr['updated_at']) ? new \DateTime($pr['updated_at']) : null,
            'user'         => $pr['user']['login'],
            'assignee'     => null,
            'merge_commit' => null, // empty as GitHub doesn't provide this yet, merge_commit_sha is deprecated and not meant for this
            'merged'       => isset($pr['merged_by']) && isset($pr['merged_by']['login']),
            'merged_by'    => isset($pr['merged_by']) && isset($pr['merged_by']['login']) ? $pr['merged_by']['login'] : '',
            'head' => [
                'ref' =>  $pr['head']['ref'],
                'sha'  => $pr['head']['sha'],
                'user' => $pr['head']['user']['login'],
                'repo' => $pr['head']['repo']['name'],
            ],
            'base' => [
              'ref'   => $pr['base']['ref'],
              'label' => $pr['base']['label'],
              'sha'   => $pr['base']['sha'],
              'repo'  => $pr['base']['repo']['name'],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getPullRequestUrl($id)
    {
        return sprintf('https://%s/%s/%s/pull/%d', $this->domain, $this->getUsername(), $this->getRepository(), $id);
    }

    /**
     * {@inheritdoc}
     */
    public function getPullRequestCommits($id)
    {
        $api = $this->client->api('pull_request');

        $fetchedCommits = $api->commits(
            $this->getUsername(),
            $this->getRepository(),
            $id
        );

        $commits = [];

        foreach ($fetchedCommits as $commit) {
            $commits[] = [
                'sha'     => $commit['sha'],
                'user'    => $commit['author']['login'],
                'message' => $commit['commit']['message'],
            ];
        }

        return $commits;
    }

    /**
     * {@inheritdoc}
     */
    public function mergePullRequest($id, $message)
    {
        $api = $this
            ->client
            ->api('pull_request')
        ;

        $result = $api->merge(
            $this->getUsername(),
            $this->getRepository(),
            $id,
            $message
        );

        if (false === $result['merged']) {
            throw new AdapterException('Merge failed: '.$result['message']);
        }

        return $result['sha'];
    }

    /**
     * {@inheritdoc}
     */
    public function getPullRequests($state = null, $page = 1, $perPage = 30)
    {
        // FIXME is not respecting the pagination

        $api = $this->client->api('pull_request');

        $fetchedPrs = $api->all(
            $this->getUsername(),
            $this->getRepository(),
            $state
        );

        $prs = [];

        foreach ($fetchedPrs as $pr) {
            $prs[] = [
                'url'          => $pr['html_url'],
                'number'       => $pr['number'],
                'state'        => $pr['state'],
                'title'        => $pr['title'],
                'body'         => $pr['body'],
                'labels'       => [],
                'milestone'    => null,
                'created_at'   => !empty($pr['created_at']) ? new \DateTime($pr['created_at']) : null,
                'updated_at'   => !empty($pr['updated_at']) ? new \DateTime($pr['updated_at']) : null,
                'user'         => $pr['user']['login'],
                'assignee'     => null,
                'merge_commit' => null, // empty as GitHub doesn't provide this yet, merge_commit_sha is deprecated and not meant for this
                'merged'       => isset($pr['merged_by']) && isset($pr['merged_by']['login']),
                'merged_by'    => isset($pr['merged_by']) && isset($pr['merged_by']['login']) ? $pr['merged_by']['login'] : '',
                'head' => [
                    'ref' =>  $pr['head']['ref'],
                    'sha'  => $pr['head']['sha'],
                    'user' => $pr['head']['user']['login'],
                    'repo' => $pr['head']['repo']['name'],
                ],
                'base' => [
                  'ref'   => $pr['base']['ref'],
                  'label' => $pr['base']['label'],
                  'sha'   => $pr['base']['sha'],
                  'repo'  => $pr['base']['repo']['name'],
                ],
            ];
        }

        return $prs;
    }

    /**
     * {@inheritdoc}
     */
    public function getPullRequestStates()
    {
        return [
            'open',
            'closed',
            'all',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function createRelease($name, array $parameters = [])
    {
        $api = $this
            ->client
            ->api('repo')
            ->releases()
        ;

        $release = $api->create(
            $this->getUsername(),
            $this->getRepository(),
            array_merge(
                $parameters,
                [
                    'tag_name' => $name
                ]
            )
        );

        return ['url' => $release['html_url'], 'id' => $release['id']];
    }

    /**
     * {@inheritdoc}
     */
    public function getReleases()
    {
        $api = $this
            ->client
            ->api('repo')
            ->releases()
        ;

        $fetchedReleases = $api->all(
            $this->getUsername(),
            $this->getRepository()
        );

        $releases = [];

        foreach ($fetchedReleases as $release) {
            $releases[] = [
                'url'           => $release['html_url'],
                'id'            => $release['id'],
                'name'          => $release['name'],
                'tag_name'      => $release['tag_name'],
                'body'          => $release['body'],
                'draft'         => $release['draft'],
                'prerelease'    => $release['prerelease'],
                'created_at'    => !empty($comment['created_at']) ? new \DateTime($comment['created_at']) : null,
                'updated_at'    => !empty($comment['updated_at']) ? new \DateTime($comment['updated_at']) : null,
                'published_at'  => !empty($comment['published_at']) ? new \DateTime($comment['published_at']) : null,
                'user'          => $release['user']['login'],
            ];
        }

        return $releases;
    }

    /**
     * {@inheritdoc}
     */
    public function removeRelease($id)
    {
        $api =
            $this->client->api('repo')
                ->releases();

        $api->remove(
            $this->getUsername(),
            $this->getRepository(),
            $id
        );
    }

    /**
     * {@inheritdoc}
     */
    public function createReleaseAssets($id, $name, $contentType, $content)
    {
        $api =
            $this->client->api('repo')
                ->releases()
                ->assets();

        return $api->create(
            $this->getUsername(),
            $this->getRepository(),
            $id,
            $name,
            $contentType,
            $content
        )['id'];
    }
}
