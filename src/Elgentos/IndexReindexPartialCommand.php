<?php

namespace Elgentos;

use N98\Magento\Command\Indexer\AbstractIndexerCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class IndexReindexPartialCommand extends AbstractIndexerCommand
{
    /**
     * @var InputInterface
     */
    protected $input;
    /**
     * @var OutputInterface
     */
    protected $output;

    protected function configure()
    {
      $this
          ->setName('index:reindex-partial')
          ->setDescription('Reindex partially by inputting ids [elgentos]')
          ->addArgument('indexer', InputArgument::REQUIRED, 'Name of the indexer.')
          ->addArgument('ids', InputArgument::IS_ARRAY | InputArgument::REQUIRED, '(Comma/space) seperated list of entity IDs to be reindexed')
      ;
    }

   /**
    * @param \Symfony\Component\Console\Input\InputInterface $input
    * @param \Symfony\Component\Console\Output\OutputInterface $output
    * @return int|void
    */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->detectMagento($output);
        if ($this->initMagento()) {
            $indexerCollection = $this->getIndexerCollection();
            $indexer = $this->getIndexer($indexerCollection);
            if ($indexer === false) {
                $output->writeln('Error; indexer not found.');
                return;
            }

            $ids = $this->getIdsArray();
            $this->output->writeln(sprintf('Reindexing %s (%s) for entity IDs %s', $indexer->getTitle(), $indexer->getId(), implode(', ', $ids)));
            $indexer->reindexList($ids);
        }
    }

    /**
     * @param $indexerCollection
     * @return bool
     */
    private function getIndexer($indexerCollection)
    {
        foreach ($indexerCollection as $indexer) {
            if ($indexer->getId() == $this->input->getArgument('indexer')) {
                return $indexer;
            }
        }

        return false;
    }

    /**
     * @return array
     */
    private function getIdsArray()
    {
        $ids = $this->input->getArgument('ids');
        $list = [];
        foreach ($ids as &$id) {
            $id = preg_split("/(\s|,)/", $id);

            if (is_array($id)) {
                $list = array_merge($list, $id);
            } else {
                $list[] = $id;
            }
        }

        return array_map(function ($id) {
            return trim($id);
        }, $list);
    }


}
