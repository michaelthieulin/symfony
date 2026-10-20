<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Translation\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Translation\Catalogue\MergeOperation;
use Symfony\Component\Translation\DataCollectorTranslator;
use Symfony\Component\Translation\Extractor\ExtractorInterface;
use Symfony\Component\Translation\LoggingTranslator;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Reader\TranslationReaderInterface;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Helps finding unused or missing translation messages in a given locale
 * and comparing them with the fallback ones.
 *
 * @author Florian Voutzinos <florian@voutzinos.com>
 *
 * @phpstan-type Row array{domain: string, id: string, states: list<int>, translation: ?string, fallbacks: list<?string>}
 */
#[AsCommand(name: 'debug:translation', description: 'Display translation messages information')]
class TranslationDebugCommand extends Command
{
    public const EXIT_CODE_GENERAL_ERROR = 64;
    public const EXIT_CODE_MISSING = 65;
    public const EXIT_CODE_UNUSED = 66;
    public const EXIT_CODE_FALLBACK = 68;
    public const MESSAGE_MISSING = 0;
    public const MESSAGE_UNUSED = 1;
    public const MESSAGE_EQUALS_FALLBACK = 2;

    /**
     * @param \Closure():KernelInterface|KernelInterface $kernel
     */
    public function __construct(
        private \Closure|KernelInterface $kernel,
        private TranslatorInterface $translator,
        private TranslationReaderInterface $reader,
        private ExtractorInterface $extractor,
        private ?string $defaultTransPath = null,
        private ?string $defaultViewsPath = null,
        private array $transPaths = [],
        private array $codePaths = [],
        private array $enabledLocales = [],
    ) {
        $this->enabledLocales = array_filter($enabledLocales);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDefinition([
                new InputArgument('locale', InputArgument::REQUIRED, 'The locale'),
                new InputArgument('bundle', InputArgument::OPTIONAL, 'The bundle name or directory where to load the messages'),
                new InputOption('domain', null, InputOption::VALUE_REQUIRED, 'The messages domain'),
                new InputOption('only-missing', null, InputOption::VALUE_NONE, 'Display only missing messages'),
                new InputOption('only-unused', null, InputOption::VALUE_NONE, 'Display only unused messages'),
                new InputOption('all', null, InputOption::VALUE_NONE, 'Load messages from all registered bundles'),
                new InputOption('format', null, InputOption::VALUE_REQUIRED, \sprintf('The output format ("%s")', implode('", "', $this->getAvailableFormatOptions())), 'txt'),
            ])
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command helps finding unused or missing translation
                messages and comparing them with the fallback ones by inspecting the
                templates and translation files of a given bundle or the default translations directory.

                You can display information about bundle translations in a specific locale:

                  <info>php %command.full_name% en AcmeDemoBundle</info>

                You can also specify a translation domain for the search:

                  <info>php %command.full_name% --domain=messages en AcmeDemoBundle</info>

                You can only display missing messages:

                  <info>php %command.full_name% --only-missing en AcmeDemoBundle</info>

                You can only display unused messages:

                  <info>php %command.full_name% --only-unused en AcmeDemoBundle</info>

                You can display information about application translations in a specific locale:

                  <info>php %command.full_name% en</info>

                You can display information about translations in all registered bundles in a specific locale:

                  <info>php %command.full_name% --all en</info>

                The <info>--format</info> option specifies the format of the command output.
                The <info>json</info> and <info>csv</info> formats contain the full messages and are meant to be parsed by other tools:

                  <info>php %command.full_name% --format=json en</info>
                  <info>php %command.full_name% --format=csv en > translations.csv</info>

                EOF
            )
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        if (!\in_array($input->getOption('format'), $this->getAvailableFormatOptions(), true)) {
            throw new InvalidOptionException(\sprintf('Supported formats are "%s".', implode('", "', $this->getAvailableFormatOptions())));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $locale = $input->getArgument('locale');
        $domain = $input->getOption('domain');

        $exitCode = self::SUCCESS;

        /** @var KernelInterface $kernel */
        $kernel = $this->getKernel();

        // Define Root Paths
        $transPaths = $this->getRootTransPaths();
        $codePaths = $this->getRootCodePaths($kernel);

        // Override with provided Bundle info
        if (null !== $input->getArgument('bundle')) {
            try {
                $bundle = $kernel->getBundle($input->getArgument('bundle'));
                $bundleDir = $bundle->getPath();
                $transPaths = [is_dir($bundleDir.'/Resources/translations') ? $bundleDir.'/Resources/translations' : $bundleDir.'/translations'];
                $codePaths = [is_dir($bundleDir.'/Resources/views') ? $bundleDir.'/Resources/views' : $bundleDir.'/templates'];
                if ($this->defaultTransPath) {
                    $transPaths[] = $this->defaultTransPath;
                }
                if ($this->defaultViewsPath) {
                    $codePaths[] = $this->defaultViewsPath;
                }
            } catch (\InvalidArgumentException) {
                // such a bundle does not exist, so treat the argument as path
                $path = $input->getArgument('bundle');

                $transPaths = [$path.'/translations'];
                $codePaths = [$path.'/templates'];

                if (!is_dir($transPaths[0])) {
                    throw new InvalidArgumentException(\sprintf('"%s" is neither an enabled bundle nor a directory.', $transPaths[0]));
                }
            }
        } elseif ($input->getOption('all')) {
            foreach ($kernel->getBundles() as $bundle) {
                $bundleDir = $bundle->getPath();
                $transPaths[] = is_dir($bundleDir.'/Resources/translations') ? $bundleDir.'/Resources/translations' : $bundle->getPath().'/translations';
                $codePaths[] = is_dir($bundleDir.'/Resources/views') ? $bundleDir.'/Resources/views' : $bundle->getPath().'/templates';
            }
        }

        // Extract used messages
        $extractedCatalogue = $this->extractMessages($locale, $codePaths);

        // Load defined messages
        $currentCatalogue = $this->loadCurrentMessages($locale, $transPaths);

        // Merge defined and extracted messages to get all message ids
        $mergeOperation = new MergeOperation($extractedCatalogue, $currentCatalogue);
        $allMessages = $mergeOperation->getResult()->all($domain);
        if (null !== $domain) {
            $allMessages = [$domain => $allMessages];
        }

        // No defined or extracted messages
        if (!$allMessages || null !== $domain && empty($allMessages[$domain])) {
            $outputMessage = \sprintf('No defined or extracted messages for locale "%s"', $locale);

            if (null !== $domain) {
                $outputMessage .= \sprintf(' and domain "%s"', $domain);
            }

            $io->getErrorStyle()->warning($outputMessage);

            return self::EXIT_CODE_GENERAL_ERROR;
        }

        // Load the fallback catalogues
        $fallbackCatalogues = $this->loadFallbackCatalogues($locale, $transPaths);

        $rows = [];
        // Iterate all message ids and determine their state
        foreach ($allMessages as $domain => $messages) {
            foreach (array_keys($messages) as $messageId) {
                // numeric ids are turned into integers when used as array keys
                $messageId = (string) $messageId;
                $value = $currentCatalogue->get($messageId, $domain);
                $states = [];

                if ($extractedCatalogue->defines($messageId, $domain)) {
                    if (!$currentCatalogue->defines($messageId, $domain)) {
                        $states[] = self::MESSAGE_MISSING;

                        if (!$input->getOption('only-unused')) {
                            $exitCode |= self::EXIT_CODE_MISSING;
                        }
                    }
                } elseif ($currentCatalogue->defines($messageId, $domain)) {
                    $states[] = self::MESSAGE_UNUSED;

                    if (!$input->getOption('only-missing')) {
                        $exitCode |= self::EXIT_CODE_UNUSED;
                    }
                }

                if (!\in_array(self::MESSAGE_UNUSED, $states, true) && $input->getOption('only-unused')
                    || !\in_array(self::MESSAGE_MISSING, $states, true) && $input->getOption('only-missing')
                ) {
                    continue;
                }

                foreach ($fallbackCatalogues as $fallbackCatalogue) {
                    if ($fallbackCatalogue->defines($messageId, $domain) && $value === $fallbackCatalogue->get($messageId, $domain)) {
                        $states[] = self::MESSAGE_EQUALS_FALLBACK;

                        $exitCode |= self::EXIT_CODE_FALLBACK;

                        break;
                    }
                }

                $fallbacks = [];
                foreach ($fallbackCatalogues as $fallbackCatalogue) {
                    $fallbacks[] = $fallbackCatalogue->defines($messageId, $domain) ? $fallbackCatalogue->get($messageId, $domain) : null;
                }

                $rows[] = [
                    'domain' => (string) $domain,
                    'id' => $messageId,
                    'states' => $states,
                    'translation' => $currentCatalogue->defines($messageId, $domain) ? $value : null,
                    'fallbacks' => $fallbacks,
                ];
            }
        }

        $fallbackLocales = array_map(static fn (MessageCatalogue $catalogue) => $catalogue->getLocale(), $fallbackCatalogues);

        match ($input->getOption('format')) {
            'txt' => $this->renderTxt($io, $locale, $fallbackLocales, $rows),
            'json' => $this->renderJson($output, $fallbackLocales, $rows),
            'csv' => $this->renderCsv($output, $locale, $fallbackLocales, $rows),
            default => throw new InvalidOptionException(\sprintf('Supported formats are "%s".', implode('", "', $this->getAvailableFormatOptions()))),
        };

        return $exitCode;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('locale')) {
            $suggestions->suggestValues($this->enabledLocales);

            return;
        }

        if ($input->mustSuggestOptionValuesFor('format')) {
            $suggestions->suggestValues($this->getAvailableFormatOptions());

            return;
        }

        /** @var KernelInterface $kernel */
        $kernel = $this->getKernel();

        if ($input->mustSuggestArgumentValuesFor('bundle')) {
            $availableBundles = [];
            foreach ($kernel->getBundles() as $bundle) {
                $availableBundles[] = $bundle->getName();

                if ($extension = $bundle->getContainerExtension()) {
                    $availableBundles[] = $extension->getAlias();
                }
            }

            $suggestions->suggestValues($availableBundles);

            return;
        }

        if ($input->mustSuggestOptionValuesFor('domain')) {
            $locale = $input->getArgument('locale');

            $mergeOperation = new MergeOperation(
                $this->extractMessages($locale, $this->getRootCodePaths($kernel)),
                $this->loadCurrentMessages($locale, $this->getRootTransPaths())
            );

            $suggestions->suggestValues($mergeOperation->getDomains());
        }
    }

    /**
     * @param string[]  $fallbackLocales
     * @param list<Row> $rows
     */
    private function renderTxt(SymfonyStyle $io, string $locale, array $fallbackLocales, array $rows): void
    {
        $headers = ['State', 'Domain', 'Id', \sprintf('Message Preview (%s)', $locale)];
        foreach ($fallbackLocales as $fallbackLocale) {
            $headers[] = \sprintf('Fallback Message Preview (%s)', $fallbackLocale);
        }

        $tableRows = [];
        foreach ($rows as $row) {
            $tableRow = [$this->formatStates($row['states']), $row['domain'], $this->formatId($row['id']), $this->sanitizeString($row['translation'] ?? $row['id'])];
            foreach ($row['fallbacks'] as $fallback) {
                $tableRow[] = $this->sanitizeString($fallback ?? $row['id']);
            }

            $tableRows[] = $tableRow;
        }

        $io->table($headers, $tableRows);
    }

    /**
     * @param string[]  $fallbackLocales
     * @param list<Row> $rows
     */
    private function renderJson(OutputInterface $output, array $fallbackLocales, array $rows): void
    {
        $getStateName = $this->getStateName(...);

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'domain' => $row['domain'],
                'id' => $row['id'],
                'states' => array_map($getStateName, $row['states']),
                'translation' => $row['translation'],
                // an empty map must be encoded as {} and not as []
                'fallbacks' => (object) array_combine($fallbackLocales, $row['fallbacks']),
            ];
        }

        // the raw output prevents the formatter from altering messages containing tags
        $output->writeln(json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    }

    /**
     * @param string[]  $fallbackLocales
     * @param list<Row> $rows
     */
    private function renderCsv(OutputInterface $output, string $locale, array $fallbackLocales, array $rows): void
    {
        $getStateName = $this->getStateName(...);

        $headers = ['states', 'domain', 'id', 'translation_'.$locale];
        foreach ($fallbackLocales as $fallbackLocale) {
            $headers[] = 'fallback_'.$fallbackLocale;
        }
        $this->writeCsvLine($output, $headers);

        foreach ($rows as $row) {
            $this->writeCsvLine($output, [
                implode(' ', array_map($getStateName, $row['states'])),
                $row['domain'],
                $row['id'],
                $row['translation'] ?? '',
                ...array_map(static fn (?string $fallback) => $fallback ?? '', $row['fallbacks']),
            ]);
        }
    }

    /**
     * @param list<string> $fields
     */
    private function writeCsvLine(OutputInterface $output, array $fields): void
    {
        $handle = fopen('php://memory', 'r+');
        fputcsv($handle, $fields, ';', '"', '');
        rewind($handle);

        // the raw output prevents the formatter from altering messages containing tags
        $output->write(stream_get_contents($handle), false, OutputInterface::OUTPUT_RAW);
        fclose($handle);
    }

    /**
     * @return string[]
     */
    private function getAvailableFormatOptions(): array
    {
        return ['txt', 'json', 'csv'];
    }

    private function getStateName(int $state): string
    {
        return match ($state) {
            self::MESSAGE_MISSING => 'missing',
            self::MESSAGE_UNUSED => 'unused',
            self::MESSAGE_EQUALS_FALLBACK => 'fallback',
            default => (string) $state,
        };
    }

    private function formatState(int $state): string
    {
        if (self::MESSAGE_MISSING === $state) {
            return '<error> missing </error>';
        }

        if (self::MESSAGE_UNUSED === $state) {
            return '<comment> unused </comment>';
        }

        if (self::MESSAGE_EQUALS_FALLBACK === $state) {
            return '<info> fallback </info>';
        }

        return (string) $state;
    }

    private function formatStates(array $states): string
    {
        $result = [];
        foreach ($states as $state) {
            $result[] = $this->formatState($state);
        }

        return implode(' ', $result);
    }

    private function formatId(string $id): string
    {
        return \sprintf('<fg=cyan;options=bold>%s</>', $id);
    }

    private function sanitizeString(string $string, int $length = 40): string
    {
        $string = trim(preg_replace('/\s+/', ' ', $string));

        if (false !== $encoding = mb_detect_encoding($string, null, true)) {
            if (mb_strlen($string, $encoding) > $length) {
                return mb_substr($string, 0, $length - 3, $encoding).'...';
            }
        } elseif (\strlen($string) > $length) {
            return substr($string, 0, $length - 3).'...';
        }

        return $string;
    }

    private function extractMessages(string $locale, array $transPaths): MessageCatalogue
    {
        $extractedCatalogue = new MessageCatalogue($locale);
        foreach ($transPaths as $path) {
            if (is_dir($path) || is_file($path)) {
                $this->extractor->extract($path, $extractedCatalogue);
            }
        }

        return $extractedCatalogue;
    }

    private function loadCurrentMessages(string $locale, array $transPaths): MessageCatalogue
    {
        $currentCatalogue = new MessageCatalogue($locale);
        foreach ($transPaths as $path) {
            if (is_dir($path)) {
                $this->reader->read($path, $currentCatalogue);
            }
        }

        return $currentCatalogue;
    }

    /**
     * @return MessageCatalogue[]
     */
    private function loadFallbackCatalogues(string $locale, array $transPaths): array
    {
        $fallbackCatalogues = [];
        if ($this->translator instanceof Translator || $this->translator instanceof DataCollectorTranslator || $this->translator instanceof LoggingTranslator) {
            foreach ($this->translator->getFallbackLocales() as $fallbackLocale) {
                if ($fallbackLocale === $locale) {
                    continue;
                }

                $fallbackCatalogue = new MessageCatalogue($fallbackLocale);
                foreach ($transPaths as $path) {
                    if (is_dir($path)) {
                        $this->reader->read($path, $fallbackCatalogue);
                    }
                }
                $fallbackCatalogues[] = $fallbackCatalogue;
            }
        }

        return $fallbackCatalogues;
    }

    private function getRootTransPaths(): array
    {
        $transPaths = $this->transPaths;
        if ($this->defaultTransPath) {
            $transPaths[] = $this->defaultTransPath;
        }

        return $transPaths;
    }

    private function getRootCodePaths(KernelInterface $kernel): array
    {
        $codePaths = $this->codePaths;
        $codePaths[] = $kernel->getProjectDir().'/src';
        if ($this->defaultViewsPath) {
            $codePaths[] = $this->defaultViewsPath;
        }

        return $codePaths;
    }

    private function getKernel(): KernelInterface
    {
        return $this->kernel instanceof \Closure ? ($this->kernel)() : $this->kernel;
    }
}
