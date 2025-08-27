<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'es:benchmark',
    description: 'Benchmark single vs multi index search strategies by running the CLI command multiple times',
)]
final class BenchmarkSearchCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'iterations',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of searches per strategy',
                1000
            );

        $this
            ->addOption(
                'filter',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter on type: event or place',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $testWords = [
            'cultuur',
            'bibliotheek',
            'literatuur',
            'boeken',
            'poëzie',
            'lezing',
            'workshop',
            'cursus',
            'erfgoed',
            'tentoonstelling',
            'archief',
            'kunst',
            'theater',
            'dans',
            'ballet',
            'opera',
            'cabaret',
            'musical',
            'toneel',
            'performance',
            'concert',
            'festival',
            'muziek',
            'koor',
            'jazz',
            'klassiek',
            'pop',
            'rock',
            'dj',
            'party',
            'feest',
            'museum',
            'galerie',
            'expositie',
            'beeldhouwwerk',
            'schilderij',
            'fotografie',
            'film',
            'cinema',
            'kindervoorstelling',
            'jeugdtheater',
            'familieactiviteit',
            'speelplein',
            'circus',
            'kinderboeken',
            'poppenkast',
            'intercultureel',
            'migratie',
            'inclusie',
            'geschiedenis',
            'debat',
            'cultuurcentrum',
            'schouwburg',
            'zaal',
            'kerk',
            'plein',
            'park',
        ];

        $strategies = ['single', 'multi'];
        $iterations = (int)$input->getOption('iterations');

        if ($input->getOption('filter')) {
            if (!in_array($input->getOption('filter'), ['event', 'place'], true)) {
                $output->writeln('<error>Invalid filter. Use "event" or "place".</error>');
                return Command::FAILURE;
            }

            $output->writeln(sprintf("<info>Applying filter: %s</info>", $input->getOption('filter')));
        }

        foreach ($strategies as $strategy) {
            $output->writeln("<info>Benchmarking strategy: {$strategy} ({$iterations} iterations)</info>");

            $totalTime = 0.0;
            $warmup = true;

            for ($i = 0; $i < $iterations; $i++) {
                $word = $testWords[array_rand($testWords)];

                $start = microtime(true);

                if ($input->getOption('filter') !== null) {
                    shell_exec(sprintf('./bin/console es:search %s %s --filter %s> /dev/null 2>&1', $strategy, escapeshellarg($word), escapeshellarg($input->getOption('filter'))));
                } else {
                    shell_exec(sprintf('./bin/console es:search %s %s > /dev/null 2>&1', $strategy, escapeshellarg($word)));
                }

                $elapsed = microtime(true) - $start;

                // Skip first warmup iteration
                if ($warmup) {
                    $warmup = false;
                    continue;
                }

                $totalTime += $elapsed;
            }

            $avg = $totalTime / ($iterations - 1);
            $output->writeln("→ total: " . round($totalTime, 3) . "s, avg: " . round($avg, 5) . "s");
        }

        return Command::SUCCESS;
    }
}
