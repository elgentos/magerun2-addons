<?php

namespace Elgentos;

use Magento\Framework\App\ObjectManager;
use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Collect phrases from the codebase that have no translation for a given locale.
 *
 * Reimplements the core of experius/module-missing-translations as a standalone
 * magerun2 command so no module install is needed. It collects every translatable
 * phrase with Magento's own i18n dictionary generator, loads the merged frontend
 * translation map (module + theme/pack CSVs + DB translations) for the locale under
 * store emulation, and reports every collected phrase whose key is absent from that
 * map. Output is a ready-to-fill Magento i18n CSV ("phrase","").
 *
 * @see https://github.com/experius/Magento-2-Module-Experius-MissingTranslations
 */
class CollectMissingTranslationsCommand extends AbstractMagentoCommand
{
    protected function configure()
    {
        $this
            ->setName('i18n:collect-missing')
            ->setDescription('Collect phrases that have no translation for the given locale [elgentos]')
            ->addArgument(
                'directory',
                InputArgument::OPTIONAL,
                'Directory (or comma-separated list of directories) to parse. Not needed when --magento is set.'
            )
            ->addOption(
                'magento',
                'm',
                InputOption::VALUE_NONE,
                'Parse the whole Magento codebase (BP) instead of a directory.'
            )
            ->addOption(
                'locale',
                'l',
                InputOption::VALUE_REQUIRED,
                'Locale to check, e.g. nl_NL.'
            )
            ->addOption(
                'store',
                's',
                InputOption::VALUE_REQUIRED,
                'Store id or code to emulate for theme/pack/DB translations (default: default store view).'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output CSV path. Use "-" for stdout. Default: <cwd>/i18n-missing_<locale>.csv'
            )
            ->addOption(
                'translate',
                't',
                InputOption::VALUE_NONE,
                'After collecting, if the "claude" CLI is installed, one-shot translate the CSV via "claude -p" into <output>.translated.csv'
            )
            ->addOption(
                'exclude',
                'x',
                InputOption::VALUE_REQUIRED,
                'Comma-separated path substrings to skip (case-insensitive), e.g. "/sample-data/,/Setup/Patch/".'
            )
            ->addOption(
                'include-tests',
                null,
                InputOption::VALUE_NONE,
                'Do not auto-exclude test/dev directories (by default dev/tests, Test/, tests/ and _files/ are skipped).'
            )
            ->addOption(
                'types',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated phrase sources to collect: php (__() calls), js, html, xml (config/UI labels). Default: php,js,html,xml. Use --types=php for __() only.',
                'php,js,html,xml'
            )
            ->addOption(
                'no-source',
                null,
                InputOption::VALUE_NONE,
                'Omit the source module/package column, producing a plain 2-column language-pack CSV.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Diagnostics go to stderr so stdout stays a clean CSV stream when --output=-.
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $this->detectMagento($output);
        if (!$this->initMagento()) {
            return 1;
        }

        $locale = $input->getOption('locale');
        if (!$locale) {
            $err->writeln('<error>--locale is required, e.g. --locale=nl_NL</error>');
            return 1;
        }

        // Resolve directories to parse (the argument may be comma-separated).
        $useMagento = (bool)$input->getOption('magento');
        $directoryArg = $input->getArgument('directory');
        if ($useMagento) {
            if ($directoryArg) {
                $err->writeln('<error>Pass either a directory or --magento, not both.</error>');
                return 1;
            }
            $directories = [defined('BP') ? BP : $this->getApplication()->getMagentoRootFolder()];
        } elseif (!$directoryArg) {
            $err->writeln('<error>Provide a directory to parse, or use --magento for the whole codebase.</error>');
            return 1;
        } else {
            $directories = array_values(array_filter(array_map('trim', explode(',', $directoryArg)), 'strlen'));
        }
        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                $err->writeln(sprintf('<error>Directory "%s" does not exist.</error>', $directory));
                return 1;
            }
        }

        // Build the (lower-cased) exclude substrings: test/dev dirs by default, plus --exclude.
        $excludes = [];
        if (!$input->getOption('include-tests')) {
            $excludes = ['/dev/tests/', '/test/', '/tests/', '/_files/'];
        }
        $excludeOption = $input->getOption('exclude');
        if ($excludeOption) {
            foreach (explode(',', $excludeOption) as $pattern) {
                $pattern = strtolower(trim($pattern));
                if ($pattern !== '') {
                    $excludes[] = $pattern;
                }
            }
        }

        // Which phrase sources to collect (php/js/html/xml).
        $validTypes = ['php', 'js', 'html', 'xml'];
        $types = array_values(array_filter(
            array_map('trim', explode(',', strtolower((string)$input->getOption('types')))),
            'strlen'
        ));
        $types = array_values(array_intersect($validTypes, $types));
        if (!$types) {
            $err->writeln(sprintf('<error>--types must be a comma-separated subset of: %s</error>', implode(', ', $validTypes)));
            return 1;
        }

        $objectManager = ObjectManager::getInstance();

        // Resolve and emulate the store so theme/pack/DB translations resolve correctly.
        $storeManager = $objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
        $storeOption = $input->getOption('store');
        try {
            $store = $storeOption !== null
                ? $storeManager->getStore($storeOption)
                : $storeManager->getDefaultStoreView();
        } catch (\Exception $e) {
            $err->writeln(sprintf('<error>Could not resolve store "%s": %s</error>', $storeOption, $e->getMessage()));
            return 1;
        }
        if (!$store) {
            $err->writeln('<error>No store view available to emulate.</error>');
            return 1;
        }

        // The i18n phrase collector and translate loader need an area code. magerun
        // does not set one in the global scope, and it can only be set once.
        /** @var \Magento\Framework\App\State $state */
        $state = $objectManager->get(\Magento\Framework\App\State::class);
        try {
            $state->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $state->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
        }

        /** @var \Magento\Store\Model\App\Emulation $emulation */
        $emulation = $objectManager->get(\Magento\Store\Model\App\Emulation::class);

        $err->writeln(sprintf(
            '<info>Collecting phrases from %s and comparing against "%s" translations (store: %s)...</info>',
            implode(', ', $directories),
            $locale,
            $store->getCode()
        ));

        $allPhrases = [];
        $translations = [];
        $emulation->startEnvironmentEmulation($store->getId(), \Magento\Framework\App\Area::AREA_FRONTEND, true);
        try {
            // Load the merged frontend translation map for the requested locale.
            /** @var \Magento\Framework\TranslateInterface $translate */
            $translate = $objectManager->get(\Magento\Framework\TranslateInterface::class);
            $translate->setLocale($locale);
            $translate->loadData(\Magento\Framework\App\Area::AREA_FRONTEND, true);
            $translations = $translate->getData();

            // Collect translatable phrases from each directory, tracking which
            // extension/package each phrase was found in (for the 3rd CSV column).
            $phraseSources = [];
            foreach ($directories as $directory) {
                foreach ($this->collectPhrases($directory, $types, $excludes, $err) as $phrase => $sources) {
                    foreach ($sources as $source => $unused) {
                        $phraseSources[$phrase][$source] = true;
                    }
                }
            }
            $allPhrases = array_keys($phraseSources);
        } catch (\Throwable $e) {
            $emulation->stopEnvironmentEmulation();
            $err->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }
        $emulation->stopEnvironmentEmulation();

        if (!$allPhrases) {
            $err->writeln('<comment>No translatable phrases found in the given directory.</comment>');
            return 0;
        }

        // A phrase is "missing" when its key is absent from the merged translation map.
        $missing = [];
        foreach ($allPhrases as $phrase) {
            if (!array_key_exists($phrase, $translations)) {
                $missing[$phrase] = true;
            }
        }
        $missing = array_keys($missing);
        sort($missing, SORT_STRING);

        if (!$missing) {
            $err->writeln(sprintf(
                '<info>No missing translations: all %d phrase(s) are translated for %s.</info>',
                count($allPhrases),
                $locale
            ));
            return 0;
        }

        // Write output CSV.
        $outputPath = $input->getOption('output');
        if ($outputPath === null) {
            $outputPath = getcwd() . DIRECTORY_SEPARATOR . 'i18n-missing_' . $locale . '.csv';
        }

        // 3rd column: the extension/package(s) the phrase was found in (unless --no-source).
        $includeSource = !$input->getOption('no-source');
        $rowFor = static function (string $phrase) use ($phraseSources, $includeSource): array {
            if (!$includeSource) {
                return [$phrase, ''];
            }
            $sources = array_keys($phraseSources[$phrase] ?? []);
            sort($sources, SORT_STRING);
            return [$phrase, '', implode('; ', $sources)];
        };

        if ($outputPath === '-') {
            $handle = fopen('php://stdout', 'w');
            foreach ($missing as $phrase) {
                fputcsv($handle, $rowFor($phrase));
            }
            // Don't fclose stdout.
        } else {
            $handle = fopen($outputPath, 'w');
            if ($handle === false) {
                $err->writeln(sprintf('<error>Could not open "%s" for writing.</error>', $outputPath));
                return 1;
            }
            foreach ($missing as $phrase) {
                fputcsv($handle, $rowFor($phrase));
            }
            fclose($handle);
        }

        $err->writeln(sprintf(
            '<info>Found %d missing translation(s) out of %d phrase(s) for %s.</info>',
            count($missing),
            count($allPhrases),
            $locale
        ));
        if ($outputPath !== '-') {
            $err->writeln(sprintf('<info>Written to %s</info>', $outputPath));
        }

        // Optionally one-shot translate the CSV with the local "claude" CLI.
        if ($input->getOption('translate')) {
            if ($outputPath === '-') {
                $err->writeln('<comment>--translate is ignored when writing to stdout; pass --output=<file>.</comment>');
            } else {
                $this->translateWithClaude($outputPath, $locale, $err);
            }
        }

        return 0;
    }

    /**
     * If the "claude" CLI is installed, pipe the collected CSV through "claude -p"
     * and write the translated result to <output>.translated.csv. Best-effort: any
     * problem is reported as a warning and leaves the original CSV untouched.
     *
     * @param string $csvPath
     * @param string $locale
     * @param OutputInterface $err
     * @return void
     */
    private function translateWithClaude(string $csvPath, string $locale, OutputInterface $err): void
    {
        $claudeBin = $this->findClaudeBinary();
        if ($claudeBin === null) {
            $err->writeln('<comment>--translate requested but the "claude" CLI was not found in PATH; skipping translation.</comment>');
            $err->writeln('<comment>Install it from https://docs.claude.com/en/docs/claude-code and re-run.</comment>');
            return;
        }

        $csv = file_get_contents($csvPath);
        if ($csv === false || trim($csv) === '') {
            $err->writeln('<comment>Nothing to translate (CSV is empty); skipping.</comment>');
            return;
        }

        $prompt = sprintf(
            'You are a professional e-commerce translator for Magento storefronts. '
            . 'The input on stdin is a Magento i18n dictionary CSV. Each row is "source","","module" '
            . 'where the second column is an empty translation and the optional third column is the '
            . 'source module/package. Translate every source phrase from English into %1$s and put the '
            . 'translation in the second column. '
            . 'Rules: keep the exact same rows in the same order; leave the third column unchanged; '
            . 'preserve Magento placeholders (%%1, %%2, %%s, %%d) and HTML tags exactly; keep the CSV format '
            . '("source","translation","module") with double-quote enclosure; do NOT add a header row, '
            . 'commentary, explanation, or code fences. Output ONLY the resulting CSV.',
            $locale
        );

        $err->writeln(sprintf('<info>Translating %d-line CSV to %s via "%s -p"...</info>', substr_count($csv, "\n"), $locale, $claudeBin));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([$claudeBin, '-p', $prompt], $descriptors, $pipes);
        if (!is_resource($process)) {
            $err->writeln('<error>Could not start the "claude" process; skipping translation.</error>');
            return;
        }

        fwrite($pipes[0], $csv);
        fclose($pipes[0]);
        $translated = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || $translated === false || trim((string)$translated) === '') {
            $err->writeln(sprintf('<error>claude exited with code %d; translation skipped.</error>', $exitCode));
            if (trim((string)$stderr) !== '') {
                $err->writeln('<comment>' . trim((string)$stderr) . '</comment>');
            }
            return;
        }

        // Strip a stray ```csv / ``` fence if the model added one despite instructions.
        $translated = preg_replace('/^```[a-zA-Z]*\R|\R```\s*$/', '', trim($translated));

        $translatedPath = preg_replace('/\.csv$/i', '', $csvPath) . '.translated.csv';
        if (file_put_contents($translatedPath, rtrim($translated) . "\n") === false) {
            $err->writeln(sprintf('<error>Could not write translated CSV to %s.</error>', $translatedPath));
            return;
        }

        $err->writeln(sprintf('<info>Translated CSV written to %s</info>', $translatedPath));
        $err->writeln('<comment>Review it, then import with: magerun2 dev:i18n:... or place it as a language pack CSV.</comment>');
    }

    /**
     * Locate the "claude" CLI in PATH.
     *
     * @return string|null Absolute path / binary name, or null if not found.
     */
    private function findClaudeBinary(): ?string
    {
        $output = [];
        $code = 1;
        // command -v resolves aliases, functions and PATH entries; works on the zsh/bash login shell.
        @exec('command -v claude 2>/dev/null', $output, $code);
        if ($code === 0 && !empty($output[0])) {
            return trim($output[0]);
        }
        return null;
    }

    /**
     * Collect every translatable phrase under $directory using Magento's own i18n
     * parser adapters, but parse one file at a time so a single unparseable file
     * (e.g. a test with __('') or __($var)) is skipped with a warning instead of
     * aborting the whole run — which is what the monolithic core Generator does.
     *
     * @param string $directory
     * @param string[] $types Phrase sources to collect (php/js/html/xml).
     * @param string[] $excludes Lower-cased path substrings; matching files are skipped.
     * @param OutputInterface $err Stream for skip warnings.
     * @return array<string, array<string, bool>> Map of phrase => set of source modules/packages.
     */
    private function collectPhrases(string $directory, array $types, array $excludes, OutputInterface $err): array
    {
        $objectManager = ObjectManager::getInstance();

        /** @var \Magento\Setup\Module\I18n\Dictionary\Options\ResolverFactory $resolverFactory */
        $resolverFactory = $objectManager->get(\Magento\Setup\Module\I18n\Dictionary\Options\ResolverFactory::class);
        $optionResolver = $resolverFactory->create($directory, false);

        /** @var \Magento\Setup\Module\I18n\FilesCollector $filesCollector */
        $filesCollector = $objectManager->get(\Magento\Setup\Module\I18n\FilesCollector::class);

        $adapters = [
            'php'  => $objectManager->get(\Magento\Setup\Module\I18n\Parser\Adapter\Php::class),
            'html' => $objectManager->get(\Magento\Setup\Module\I18n\Parser\Adapter\Html::class),
            'js'   => $objectManager->get(\Magento\Setup\Module\I18n\Parser\Adapter\Js::class),
            'xml'  => $objectManager->get(\Magento\Setup\Module\I18n\Parser\Adapter\Xml::class),
        ];

        $phrases = [];
        $skipped = [];
        $excluded = 0;
        foreach ($optionResolver->getOptions() as $option) {
            $type = $option['type'] ?? null;
            if ($type === null || !isset($adapters[$type]) || !in_array($type, $types, true)) {
                continue;
            }
            $adapter = $adapters[$type];
            $files = $filesCollector->getFiles($option['paths'], $option['fileMask'] ?? false);
            foreach ($files as $file) {
                if ($excludes && $this->isExcluded($file, $excludes)) {
                    $excluded++;
                    continue;
                }
                $source = $this->moduleNameFromPath($file);
                try {
                    $adapter->parse($file);
                    foreach ($adapter->getPhrases() as $phraseData) {
                        $phrase = $phraseData['phrase'] ?? '';
                        if ($phrase !== '') {
                            if (!isset($phrases[$phrase])) {
                                $phrases[$phrase] = [];
                            }
                            if ($source !== '') {
                                $phrases[$phrase][$source] = true;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $skipped[$file] = $e->getMessage();
                    $err->writeln(
                        sprintf('<comment>Skipped %s: %s</comment>', $file, $e->getMessage()),
                        OutputInterface::VERBOSITY_VERBOSE
                    );
                }
            }
        }

        if ($skipped) {
            $err->writeln(sprintf(
                '<comment>Skipped %d unparseable file(s) under %s (run with -v to list them).</comment>',
                count($skipped),
                $directory
            ));
        }
        if ($excluded > 0) {
            $err->writeln(sprintf(
                '<comment>Excluded %d file(s) under %s matching the exclude patterns.</comment>',
                $excluded,
                $directory
            ));
        }

        return $phrases;
    }

    /**
     * Derive the extension/package name a file belongs to:
     *  - app/code/Vendor/Module/...      => "Vendor_Module" (Magento module name)
     *  - vendor/vendor-name/package/...  => "vendor-name/package" (Composer package)
     * Returns '' when the path matches neither layout.
     *
     * @param string $file
     * @return string
     */
    private function moduleNameFromPath(string $file): string
    {
        $path = realpath($file) ?: $file;
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);

        if (preg_match('#/app/code/([^/]+)/([^/]+)/#', $path, $m)) {
            return $m[1] . '_' . $m[2];
        }
        if (preg_match('#/vendor/([^/]+)/([^/]+)/#', $path, $m)) {
            return $m[1] . '/' . $m[2];
        }
        return '';
    }

    /**
     * Whether a file path matches any (lower-cased) exclude substring.
     *
     * @param string $file
     * @param string[] $excludes
     * @return bool
     */
    private function isExcluded(string $file, array $excludes): bool
    {
        $haystack = strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $file));
        foreach ($excludes as $needle) {
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
