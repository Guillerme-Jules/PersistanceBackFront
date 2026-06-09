<?php

namespace App\Command;

use App\SolarWind\ClickHouseClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:solarwind:import',
    description: 'Importe les données de vents solaires (CSV du zip) dans ClickHouse.',
)]
final class ImportSolarWindCommand extends Command
{
    private const TABLE = 'solar_wind';

    public function __construct(
        private readonly ClickHouseClient $clickhouse,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(CLICKHOUSE_HOST)%')] private readonly string $host,
        #[Autowire('%env(int:CLICKHOUSE_PORT)%')] private readonly int $port,
        #[Autowire('%env(CLICKHOUSE_DB)%')] private readonly string $database,
        #[Autowire('%env(CLICKHOUSE_USER)%')] private readonly string $user,
        #[Autowire('%env(CLICKHOUSE_PASSWORD)%')] private readonly string $password,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('zip', InputArgument::OPTIONAL, 'Chemin du zip', 'solarwinds-dscovr-compiled.zip')
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'Limiter à une année (ex: 2024)')
            ->addOption('month', null, InputOption::VALUE_REQUIRED, 'Limiter à un mois (ex: 202406)')
            ->addOption('truncate', null, InputOption::VALUE_NONE, 'Vider la table avant import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        ini_set('memory_limit', '1024M');
        $zipPath = (string) $input->getArgument('zip');

        if (!is_file($zipPath)) {
            $io->error(\sprintf('Zip introuvable: %s', $zipPath));

            return Command::FAILURE;
        }

        if (!$this->clickhouse->ping()) {
            $io->error('ClickHouse injoignable. Démarrez le conteneur (docker compose up -d clickhouse).');

            return Command::FAILURE;
        }

        $this->ensureSchema((bool) $input->getOption('truncate'), $io);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $io->error('Impossible d\'ouvrir le zip.');

            return Command::FAILURE;
        }

        $entries = $this->selectEntries($zip, $input->getOption('year'), $input->getOption('month'));
        if ($entries === []) {
            $io->warning('Aucun fichier CSV correspondant.');

            return Command::SUCCESS;
        }

        $io->title(\sprintf('Import de %d fichier(s) vers ClickHouse', \count($entries)));
        $totalBefore = $this->rowCount();

        foreach ($entries as $name) {
            $io->write(\sprintf(' • %-18s ', $name));
            $start = microtime(true);

            $stream = $zip->getStream($name);
            if ($stream === false) {
                $io->writeln('<error>flux illisible, ignoré</error>');
                continue;
            }

            try {
                $this->ingest($stream);
                $io->writeln(\sprintf('<info>OK</info> (%.1fs)', microtime(true) - $start));
            } catch (\Throwable $e) {
                $io->writeln(\sprintf('<error>ÉCHEC: %s</error>', $e->getMessage()));
            } finally {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        $zip->close();

        $totalAfter = $this->rowCount();
        $io->success(\sprintf(
            'Import terminé : %s lignes ajoutées (total: %s).',
            number_format($totalAfter - $totalBefore, 0, '.', ' '),
            number_format($totalAfter, 0, '.', ' '),
        ));

        return Command::SUCCESS;
    }

    private function ensureSchema(bool $truncate, SymfonyStyle $io): void
    {
        $this->clickhouse->execute(\sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                ts DateTime,
                speed Nullable(Float32),
                density Nullable(Float32),
                bt Nullable(Float32),
                bz Nullable(Float32)
            ) ENGINE = MergeTree
            PARTITION BY toYYYYMM(ts)
            ORDER BY ts',
            self::TABLE,
        ));

        if ($truncate) {
            $this->clickhouse->execute(\sprintf('TRUNCATE TABLE %s', self::TABLE));
            $io->note('Table vidée.');
        }
    }

    private function ingest($stream): void
    {

        $query = \sprintf(
            'INSERT INTO %s (ts, speed, density, bt, bz) '
            . 'SELECT parseDateTime(c1, \'%%Y%%m%%d%%H%%i%%S\'), '
            . 'toFloat32OrNull(c2), toFloat32OrNull(c3), toFloat32OrNull(c4), toFloat32OrNull(c5) '
            . 'FROM input(\'c1 String, c2 String, c3 String, c4 String, c5 String\') FORMAT CSV',
            self::TABLE,
        );

        $url = \sprintf('http://%s:%d/?%s', $this->host, $this->port, http_build_query([
            'database' => $this->database,
            'query' => $query,
            'format_csv_delimiter' => ';',
            'input_format_csv_skip_first_lines' => '1',
        ]));

        $body = static function (int $size) use ($stream): string {
            if (feof($stream)) {
                return '';
            }

            return (string) fread($stream, $size);
        };

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'X-ClickHouse-User' => $this->user,
                'X-ClickHouse-Key' => $this->password,
                'Content-Type' => 'text/plain',
            ],
            'body' => $body,
            'max_duration' => 0,
            'timeout' => 600,
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException(\sprintf('HTTP %d: %s', $status, substr($response->getContent(false), 0, 300)));
        }
    }

    private function selectEntries(\ZipArchive $zip, ?string $year, ?string $month): array
    {
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if ($name === false || !preg_match('#(\d{4})/(\d{6})\.csv$#', $name, $m)) {
                continue;
            }
            if ($year !== null && $m[1] !== $year) {
                continue;
            }
            if ($month !== null && $m[2] !== $month) {
                continue;
            }
            $entries[] = $name;
        }
        sort($entries);

        return $entries;
    }

    private function rowCount(): int
    {
        return (int) $this->clickhouse->fetchScalar(\sprintf('SELECT count() FROM %s', self::TABLE));
    }
}
