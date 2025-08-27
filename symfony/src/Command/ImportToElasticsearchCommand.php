<?php

namespace App\Command;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'es:import',
    description: 'Import events and places into Elasticsearch',
)]
final class ImportToElasticsearchCommand extends Command
{
    const EVENT = 'event';
    const PLACE = 'place';
    const LIMIT = 2000;
    private readonly Client $esClient;
    private readonly HttpClientInterface $udbClient;

    public function __construct(private readonly LoggerInterface $logger, string $esHost, private readonly string $udbApiKey)
    {
        $this->esClient = ClientBuilder::create()
            ->setHosts([$esHost])
            ->build();

        $this->udbClient = HttpClient::create([
            'base_uri' => 'https://io.uitdatabank.be/',
            'timeout' => 5.0,
        ]);

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'strategy',
                InputArgument::REQUIRED,
                'Indexing strategy: "single" or "multi"',
            );

        $this
            ->addOption(
                'start',
                's',
                InputOption::VALUE_REQUIRED,
                'Starting point for pagination (default: 0)',
                0
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $strategy = $input->getArgument('strategy');

        if (!in_array($strategy, ['single', 'multi'], true)) {
            $output->writeln('<error>Invalid strategy. Use "single" or "multi".</error>');
            return Command::FAILURE;
        }

        $output->writeln("<info>Using strategy: {$strategy}</info>");

        $start = ((int)$input->getOption('start')) * self::LIMIT;

        // extra filter on date
        $qDate = '&disableDefaultFilters=true&q=created:%5B2023-01-01T00:00:00%2B01:00+TO+2023-12-30T23:59:59%2B01:00%5D&workflowStatus=READY_FOR_VALIDATION,APPROVED';
        $qDate = '';

        $output->writeln('<info>Starting import of events from UiTdatabank...</info>');

        $this->importOffers(
            $output,
            sprintf("events/?apiKey=%s&limit=%d&start=%d", $this->udbApiKey, self::LIMIT, $start) . $qDate,
            self::EVENT,
            $strategy);

        $output->writeln('<info>Starting import of places from UiTdatabank...</info>');
        $this->importOffers(
            $output,
            sprintf("places/?apiKey=%s&limit=%d&start=%d", $this->udbApiKey, self::LIMIT, $start) . $qDate,
            self::PLACE,
            $strategy);

        return Command::SUCCESS;
    }

    private function importOffers(OutputInterface $output, string $url, string $type, string $strategy): void
    {
        $response = $this->udbClient->request('GET', $url);
        $json = $response->getContent();
        $document = json_decode($json, true);

        if (!$document || !isset($document['member'])) {
            $output->writeln('<error>Failed to load resources from ' . $url . '</error>');
            return;
        }

        $progressBar = new ProgressBar($output, count($document['member']));
        $progressBar->start();

        foreach ($document['member'] as $row) {
            $this->importOffer($output, $row['@id'], $type, $strategy);
            $progressBar->advance();
        }
        $progressBar->finish();
        $output->writeln('');
    }

    private function importOffer(OutputInterface $output, string $url, string $type, string $strategy): void
    {
        try {
            $response = $this->udbClient->request('GET', $url);
            $json = $response->getContent();
            $document = json_decode($json, true);
        } catch (\Exception $e) {
            $msg = 'Error fetching document: ' . $url;
            $output->writeln('<error>' . $msg . '</error>');
            $this->logger->error($msg);
            return;
        }

        if ($strategy === 'single') {
            $indexName = 'offers';
            $document['type'] = $type;
        } else {
            $indexName = $type === self::EVENT ? 'events' : 'places';
        }

        $params = [
            'index' => $indexName,
            'id' => basename($document['@id']),
            'body' => $document,
        ];

        try {
            $this->esClient->index($params);
        } catch (ClientResponseException|MissingParameterException|ServerResponseException $e) {
            $this->logger->error(PHP_EOL . '<error>Elasticsearch error: ' . $e->getMessage() . '</error>');
        }
    }
}
