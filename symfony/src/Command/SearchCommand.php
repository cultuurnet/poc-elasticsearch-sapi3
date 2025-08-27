<?php

namespace App\Command;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'es:search',
    description: 'Search events and places in Elasticsearch using single or multi index strategy',
)]
final class SearchCommand extends Command
{
    private readonly Client $client;

    public function __construct(private readonly LoggerInterface $logger, string $esHost)
    {
        $this->client = ClientBuilder::create()
            ->setHosts([$esHost])
            ->build();

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('strategy', InputArgument::REQUIRED, 'Search strategy: single or multi')
            ->addArgument('query', InputArgument::REQUIRED, 'Search query string')
            ->addArgument('type', InputArgument::OPTIONAL, 'Optional type filter (event or place)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $strategy = $input->getArgument('strategy');
        $query = $input->getArgument('query');
        $type = $input->getArgument('type');

        if (!in_array($strategy, ['single', 'multi'], true)) {
            $output->writeln('<error>Invalid strategy. Use "single" or "multi".</error>');
            return Command::FAILURE;
        }

        $output->writeln("<info>Searching with strategy: {$strategy}</info>");
        $output->writeln("<info>Query: {$query}</info>");

        if ($type) {
            $output->writeln("<info>Filtering on type: {$type}</info>");
        }

        try {
            $results = $this->performSearch($query, $strategy, $type);
        } catch (\Throwable $e) {
            $output->writeln('<error>Search failed: ' . $e->getMessage() . '</error>');
            $this->logger->error($e->getMessage());
            return Command::FAILURE;
        }

        if (empty($results['hits']['hits'])) {
            $output->writeln('<comment>No results found.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<comment>%d results found.</comment>', count($results['hits']['hits'])));

        foreach ($results['hits']['hits'] as $hit) {
            $source = $hit['_source'];
            $name = $source['name']['nl'] ?? '[no title]';
            $id = $hit['_id'];
            $index = $hit['_index'];
            $output->writeln("- <info>{$name}</info> ({$id}) [{$index}]");
        }

        return Command::SUCCESS;
    }

    private function performSearch(string $query, string $strategy, ?string $type): array
    {
        if ($strategy === 'single') {
            $params = $this->singleStrategy($query, $type);
        } else {
            $params = $this->multiStrategy($query, $type);
        }

   //     file_put_contents('es-search-params.json', json_encode($params, JSON_PRETTY_PRINT));

        return $this->client->search($params)->asArray();
    }

    private function singleStrategy(string $query, ?string $type): array
    {
        $params = [
            'index' => 'offers',
            'body' => [
                'size' => 10000,
                'query' => [
                    'bool' => [
                        'must' => [
                            ['query_string' => ['query' => $query]],
                        ],
                        'filter' => [],
                    ],
                ],
            ],
        ];

        if ($type) {
            $params['body']['query']['bool']['filter'][] = ['term' => ['type' => $type]];
        }

        return $params;
    }

    private function multiStrategy(string $query, ?string $type = null): array
    {
        if ($type === null) {
            $index = 'events,places';
        } else {
            $index = $type === 'event' ? 'events' : 'places';
        }

        return [
            'index' => $index,
            'body' => [
                'size' => 10000,
                'query' => [
                    'bool' => [
                        'must' => [
                            ['query_string' => ['query' => $query]],
                        ],
                        'filter' => [],
                    ],
                ]
            ],
        ];
    }
}
