<?php

namespace Elgentos;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use N98\Magento\Command\Database\AbstractDatabaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Magento\Framework\App\Utility\Files;
use Magento\Framework\Setup\Declaration\Schema\Config\Converter;

class ListDbTablesBySizeCommand extends AbstractDatabaseCommand
{
    /**
     * Declarative name for table entity of the declarative schema.
     */
    public const SCHEMA_ENTITY_TABLE = 'table';

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('elgentos:db:show:tables-size')
            ->setDescription('Shows all tables in the database, their sizes, and indicates if they are defined in db_schema.xml')
            ->addOption(
                'only-undefined',
                'u',
                InputOption::VALUE_NONE,
                'Only show tables which are not defined'
            )
            ->addOption(
                'order',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Order by size or name',
                'size'
            )
            ->addOption(
                'direction',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Order direction'
            );
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     * @throws FileSystemException
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->detectMagento($output);
        if (!$this->initMagento()) {
            return 1;
        }

        $this->detectDbSettings($output);
        $dbHelper = $this->getDatabaseHelper();
        $connection =  $dbHelper->getConnection($output, true);

        $order = match (strtolower($input->getOption('order'))) {
            'size' => 'size_mb',
            'name' => 'table_name',
            default => 'size_mb'
        };

        $direction = match(strtolower($input->getOption('direction') ?? '')) {
            'desc' => 'desc',
            'asc' => 'asc',
            default => ($order == 'size_mb' ? 'desc' : 'asc')
        };

        // Retrieve table sizes
        $sql = "
            SELECT table_name as table_name,
                   ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
            FROM information_schema.TABLES
            WHERE table_schema = DATABASE()
            ORDER BY $order $direction;
        ";
        $databaseTables = $connection->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($databaseTables)) {
            $output->writeln('<comment>No tables found.</comment>');
            return 0;
        }

        $onlyUndefined = $input->getOption('only-undefined');

        $table = new Table($output);
        $headers = ['Table Name', 'Size', 'Defined', 'Module Name'];
        $table->setHeaders($headers);

        // Check if tables are defined in db_schema.xml using XmlReader
        [$definedTables, $moduleNames] = $this->getDefinedTables();
        $rows = [];

        foreach ($databaseTables as $databaseTable) {
            $tableName = $databaseTable['table_name'];
            $moduleName = $moduleNames[$tableName] ?? '';
            $size = $databaseTable['size_mb'];
            $isDefinedBool = in_array($tableName, $definedTables, true);
            $isDefined = $isDefinedBool ? '<comment>Yes</comment>' : '<comment>No</comment>';

            $rows[] = [
                $tableName,
                $size,
                $isDefined,
                $moduleName,
                $isDefinedBool
            ];
        }

        if($onlyUndefined) {
            $rows = array_filter($rows, function($row) {
                return !$row[4];
            });
        }

        foreach($rows as &$row) {
            $row = array_slice($row, 0, count($headers));
        }

        $table->setRows($rows);
        $table->render();

        return Command::SUCCESS;
    }

    /**
     * Get list of tables defined in db_schema.xml
     *
     * @return array
     * @throws LocalizedException
     */
    private function getDefinedTables(): array
    {
        $objectManager = $this->getObjectManager();
        $componentRegistrar = $objectManager->get(\Magento\Framework\Component\ComponentRegistrar::class);
        $dirSearch = $objectManager->get(\Magento\Framework\Component\DirSearch::class);
        $themePackageList = $objectManager->get(\Magento\Framework\View\Design\Theme\ThemePackageList::class);
        Files::setInstance(new Files($componentRegistrar, $dirSearch, $themePackageList));

        $definedTables = [];
        $moduleNames = [];
        foreach (Files::init()->getDbSchemaFiles() as $filePath) {
            $filePath = reset($filePath);
            preg_match('#app/code/(\w+/\w+)#', $filePath, $result);
            if (!empty($result)) {
                $moduleName = str_replace('/', '_', $result[1]);
            } elseif (str_contains($filePath, 'vendor')) {
                $moduleXml = simplexml_load_string(file_get_contents(str_replace('etc/db_schema.xml', 'etc/module.xml', $filePath)));
                $moduleName = (string) $moduleXml->module->attributes()->name;
            }

            $moduleDeclaration = $this->getDbSchemaDeclaration($filePath);

            foreach ($moduleDeclaration[self::SCHEMA_ENTITY_TABLE] as $tableName => $tableDeclaration) {
                if (!in_array($tableName, $definedTables, true)) {
                    $definedTables[] = $tableName;
                    $moduleNames[$tableName] = $moduleName;
                }
            }
        }

        return [$definedTables, $moduleNames];
    }

    private function getDbSchemaDeclaration(string $filePath): array
    {
        $objectManager = $this->getObjectManager();
        $converter = $objectManager->get(Converter::class);

        $dom = new \DOMDocument();
        $dom->loadXML(file_get_contents($filePath));
        return $converter->convert($dom);
    }
}
