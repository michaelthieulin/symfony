<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Translation\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandCompletionTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Translation\Command\TranslationDebugCommand;
use Symfony\Component\Translation\Extractor\ExtractorInterface;
use Symfony\Component\Translation\Reader\TranslationReader;
use Symfony\Component\Translation\Translator;

class TranslationDebugCommandTest extends TestCase
{
    private Filesystem $fs;
    private string $translationDir;

    public function testDebugMissingMessages()
    {
        $tester = $this->createCommandTester(['foo' => 'foo']);
        $res = $tester->execute(['locale' => 'en', 'bundle' => 'foo']);

        $this->assertMatchesRegularExpression('/missing/', $tester->getDisplay());
        $this->assertSame(TranslationDebugCommand::EXIT_CODE_MISSING, $res);
    }

    public function testDebugUnusedMessages()
    {
        $tester = $this->createCommandTester([], ['foo' => 'foo']);
        $res = $tester->execute(['locale' => 'en', 'bundle' => 'foo']);

        $this->assertMatchesRegularExpression('/unused/', $tester->getDisplay());
        $this->assertSame(TranslationDebugCommand::EXIT_CODE_UNUSED, $res);
    }

    public function testDebugFallbackMessages()
    {
        $tester = $this->createCommandTester(['foo' => 'foo'], ['foo' => 'foo']);
        $res = $tester->execute(['locale' => 'fr', 'bundle' => 'foo']);

        $this->assertMatchesRegularExpression('/fallback/', $tester->getDisplay());
        $this->assertSame(TranslationDebugCommand::EXIT_CODE_FALLBACK, $res);
    }

    public function testNoDefinedMessages()
    {
        $tester = $this->createCommandTester();
        $res = $tester->execute(['locale' => 'fr', 'bundle' => 'test']);

        $this->assertMatchesRegularExpression('/No defined or extracted messages for locale "fr"/', $tester->getDisplay());
        $this->assertSame(TranslationDebugCommand::EXIT_CODE_GENERAL_ERROR, $res);
    }

    public function testDebugDefaultDirectory()
    {
        $tester = $this->createCommandTester(['foo' => 'foo'], ['bar' => 'bar']);
        $res = $tester->execute(['locale' => 'en']);
        $expectedExitStatus = TranslationDebugCommand::EXIT_CODE_MISSING | TranslationDebugCommand::EXIT_CODE_UNUSED;

        $this->assertMatchesRegularExpression('/missing/', $tester->getDisplay());
        $this->assertMatchesRegularExpression('/unused/', $tester->getDisplay());
        $this->assertSame($expectedExitStatus, $res);
    }

    public function testDebugDefaultRootDirectory()
    {
        $this->fs->remove($this->translationDir);
        $this->fs = new Filesystem();
        $this->translationDir = tempnam(sys_get_temp_dir(), 'sf_translation_');
        $this->fs->remove($this->translationDir);
        $this->fs->mkdir($this->translationDir.'/translations');
        $this->fs->mkdir($this->translationDir.'/templates');

        $expectedExitStatus = TranslationDebugCommand::EXIT_CODE_MISSING | TranslationDebugCommand::EXIT_CODE_UNUSED;

        $tester = $this->createCommandTester(['foo' => 'foo'], ['bar' => 'bar'], null, [$this->translationDir.'/trans'], [$this->translationDir.'/views']);
        $res = $tester->execute(['locale' => 'en']);

        $this->assertMatchesRegularExpression('/missing/', $tester->getDisplay());
        $this->assertMatchesRegularExpression('/unused/', $tester->getDisplay());
        $this->assertSame($expectedExitStatus, $res);
    }

    public function testDebugCustomDirectory()
    {
        $this->fs->mkdir($this->translationDir.'/customDir/translations');
        $this->fs->mkdir($this->translationDir.'/customDir/templates');
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->expects($this->once())
            ->method('getBundle')
            ->with($this->equalTo($this->translationDir.'/customDir'))
            ->willThrowException(new \InvalidArgumentException());

        $expectedExitStatus = TranslationDebugCommand::EXIT_CODE_MISSING | TranslationDebugCommand::EXIT_CODE_UNUSED;

        $tester = $this->createCommandTester(['foo' => 'foo'], ['bar' => 'bar'], $kernel);
        $res = $tester->execute(['locale' => 'en', 'bundle' => $this->translationDir.'/customDir']);

        $this->assertMatchesRegularExpression('/missing/', $tester->getDisplay());
        $this->assertMatchesRegularExpression('/unused/', $tester->getDisplay());
        $this->assertSame($expectedExitStatus, $res);
    }

    public function testDebugInvalidDirectory()
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->expects($this->once())
            ->method('getBundle')
            ->with($this->equalTo('dir'))
            ->willThrowException(new \InvalidArgumentException());

        $tester = $this->createCommandTester([], [], $kernel);

        $this->expectException(\InvalidArgumentException::class);

        $tester->execute(['locale' => 'en', 'bundle' => 'dir']);
    }

    public function testNoErrorWithOnlyMissingOptionAndNoResults()
    {
        $tester = $this->createCommandTester([], ['foo' => 'foo']);
        $res = $tester->execute(['locale' => 'en', '--only-missing' => true]);

        $this->assertSame(Command::SUCCESS, $res);
    }

    public function testNoErrorWithOnlyUnusedOptionAndNoResults()
    {
        $tester = $this->createCommandTester(['foo' => 'foo']);
        $res = $tester->execute(['locale' => 'en', '--only-unused' => true]);

        $this->assertSame(Command::SUCCESS, $res);
    }

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->translationDir = tempnam(sys_get_temp_dir(), 'sf_translation_');
        $this->fs->remove($this->translationDir);
        $this->fs->mkdir($this->translationDir.'/translations');
        $this->fs->mkdir($this->translationDir.'/templates');
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->translationDir);
    }

    /**
     * @param array<string, array<string, string>> $fallbackMessages the messages loaded for a fallback locale, indexed by locale (defaults to $loadedMessages)
     * @param string[]                             $fallbackLocales
     */
    private function createCommandTester(array $extractedMessages = [], array $loadedMessages = [], ?KernelInterface $kernel = null, array $transPaths = [], array $codePaths = [], array $fallbackMessages = [], array $fallbackLocales = ['en']): CommandTester
    {
        return new CommandTester($this->createCommand($extractedMessages, $loadedMessages, $kernel, $transPaths, $codePaths, fallbackMessages: $fallbackMessages, fallbackLocales: $fallbackLocales));
    }

    /**
     * @param array<string, array<string, string>> $fallbackMessages the messages loaded for a fallback locale, indexed by locale (defaults to $loadedMessages)
     * @param string[]                             $fallbackLocales
     */
    private function createCommand(array $extractedMessages = [], array $loadedMessages = [], ?KernelInterface $kernel = null, array $transPaths = [], array $codePaths = [], ?ExtractorInterface $extractor = null, array $bundles = [], array $enabledLocales = [], array $fallbackMessages = [], array $fallbackLocales = ['en']): TranslationDebugCommand
    {
        $translator = new Translator('fr');
        $translator->setFallbackLocales($fallbackLocales);

        if (!$extractor) {
            $extractor = $this->createStub(ExtractorInterface::class);
            $extractor
                ->method('extract')
                ->willReturnCallback(
                    static function ($path, $catalogue) use ($extractedMessages) {
                        $catalogue->add($extractedMessages);
                    }
                );
        }

        $loader = $this->createStub(TranslationReader::class);
        $loader
            ->method('read')
            ->willReturnCallback(
                static function ($path, $catalogue) use ($loadedMessages, $fallbackMessages) {
                    $catalogue->add($fallbackMessages[$catalogue->getLocale()] ?? $loadedMessages);
                }
            );

        if (null === $kernel) {
            $returnValues = [
                ['foo', $this->getBundle($this->translationDir)],
                ['test', $this->getBundle('test')],
            ];
            $kernel = $this->createStub(KernelInterface::class);
            $kernel
                ->method('getBundle')
                ->willReturnMap($returnValues);
        }

        $kernel
            ->method('getBundles')
            ->willReturn($bundles);

        $container = new Container();
        $kernel
            ->method('getContainer')
            ->willReturn($container);

        $command = new TranslationDebugCommand($kernel, $translator, $loader, $extractor, $this->translationDir.'/translations', $this->translationDir.'/templates', $transPaths, $codePaths, $enabledLocales);

        $application = new Application();
        $application->addCommand($command);

        return $application->find('debug:translation');
    }

    private function getBundle($path, string $name = '', ?string $alias = null)
    {
        $bundle = $this->createStub(BundleInterface::class);
        $bundle
            ->method('getPath')
            ->willReturn($path)
        ;
        $bundle
            ->method('getName')
            ->willReturn($name)
        ;

        if (null !== $alias) {
            $extension = $this->createStub(ExtensionInterface::class);
            $extension
                ->method('getAlias')
                ->willReturn($alias)
            ;
            $bundle
                ->method('getContainerExtension')
                ->willReturn($extension)
            ;
        }

        return $bundle;
    }

    #[DataProvider('provideCompletionSuggestions')]
    public function testComplete(array $input, array $expectedSuggestions)
    {
        $extractedMessagesWithDomains = [
            'messages' => [
                'foo' => 'foo',
            ],
            'validators' => [
                'foo' => 'foo',
            ],
            'custom_domain' => [
                'foo' => 'foo',
            ],
        ];
        $extractor = $this->createStub(ExtractorInterface::class);
        $extractor
            ->method('extract')
            ->willReturnCallback(
                static function ($path, $catalogue) use ($extractedMessagesWithDomains) {
                    foreach ($extractedMessagesWithDomains as $domain => $message) {
                        $catalogue->add($message, $domain);
                    }
                }
            );

        $tester = new CommandCompletionTester($this->createCommand([], [], null, [], [], $extractor, [$this->getBundle('foo', 'FooBundle', 'foo')], ['fr', 'nl']));
        $suggestions = $tester->complete($input);
        $this->assertSame($expectedSuggestions, $suggestions);
    }

    public static function provideCompletionSuggestions(): iterable
    {
        yield 'locale' => [
            [''],
            ['fr', 'nl'],
        ];

        yield 'bundle' => [
            ['fr', '--domain', 'messages', ''],
            ['FooBundle', 'foo'],
        ];

        yield 'option --domain' => [
            ['en', '--domain', ''],
            ['messages', 'validators', 'custom_domain'],
        ];

        yield 'option --format' => [
            ['en', '--format', ''],
            ['txt', 'json', 'csv'],
        ];
    }

    #[DataProvider('provideJsonFormat')]
    public function testJsonFormat(string $locale, array $extractedMessages, array $loadedMessages, array $fallbackMessages, array $expectedRows, int $expectedExitCode)
    {
        $tester = $this->createCommandTester($extractedMessages, $loadedMessages, fallbackMessages: $fallbackMessages);
        $res = $tester->execute(['locale' => $locale, '--format' => 'json']);

        $this->assertSame($expectedRows, json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR));
        $this->assertSame($expectedExitCode, $res);
    }

    public static function provideJsonFormat(): iterable
    {
        yield 'missing' => [
            'en', ['foo' => 'foo'], [], [],
            [['domain' => 'messages', 'id' => 'foo', 'states' => ['missing'], 'translation' => null, 'fallbacks' => []]],
            TranslationDebugCommand::EXIT_CODE_MISSING,
        ];

        yield 'unused' => [
            'en', [], ['foo' => 'bar'], [],
            [['domain' => 'messages', 'id' => 'foo', 'states' => ['unused'], 'translation' => 'bar', 'fallbacks' => []]],
            TranslationDebugCommand::EXIT_CODE_UNUSED,
        ];

        yield 'equals fallback' => [
            'fr', ['foo' => 'foo'], ['foo' => 'foo'], [],
            [['domain' => 'messages', 'id' => 'foo', 'states' => ['fallback'], 'translation' => 'foo', 'fallbacks' => ['en' => 'foo']]],
            TranslationDebugCommand::EXIT_CODE_FALLBACK,
        ];

        yield 'unused and equals fallback' => [
            'fr', [], ['foo' => 'foo'], [],
            [['domain' => 'messages', 'id' => 'foo', 'states' => ['unused', 'fallback'], 'translation' => 'foo', 'fallbacks' => ['en' => 'foo']]],
            TranslationDebugCommand::EXIT_CODE_UNUSED | TranslationDebugCommand::EXIT_CODE_FALLBACK,
        ];

        yield 'missing and translated in the fallback locale' => [
            'fr', ['foo' => 'foo'], [], ['en' => ['foo' => 'Hello']],
            [['domain' => 'messages', 'id' => 'foo', 'states' => ['missing'], 'translation' => null, 'fallbacks' => ['en' => 'Hello']]],
            TranslationDebugCommand::EXIT_CODE_MISSING,
        ];

        yield 'missing and equals fallback' => [
            'fr', ['foo' => 'foo'], [], ['en' => ['foo' => 'foo']],
            [['domain' => 'messages', 'id' => 'foo', 'states' => ['missing', 'fallback'], 'translation' => null, 'fallbacks' => ['en' => 'foo']]],
            TranslationDebugCommand::EXIT_CODE_MISSING | TranslationDebugCommand::EXIT_CODE_FALLBACK,
        ];

        yield 'missing in the fallback locale' => [
            'fr', ['foo' => 'foo'], ['foo' => 'Bonjour'], ['en' => ['bar' => 'bar']],
            [['domain' => 'messages', 'id' => 'foo', 'states' => [], 'translation' => 'Bonjour', 'fallbacks' => ['en' => null]]],
            Command::SUCCESS,
        ];
    }

    public function testJsonFormatWithSeveralFallbackLocales()
    {
        $tester = $this->createCommandTester(['foo' => 'foo'], [], fallbackMessages: ['en' => ['foo' => 'Hello'], 'de' => ['bar' => 'Hallo']], fallbackLocales: ['en', 'de']);
        $tester->execute(['locale' => 'fr', '--format' => 'json']);

        $rows = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame(['en' => 'Hello', 'de' => null], $rows[0]['fallbacks']);
    }

    public function testJsonFormatEncodesNoFallbackAsAnObject()
    {
        $tester = $this->createCommandTester(['foo' => 'foo']);
        $tester->execute(['locale' => 'en', '--format' => 'json']);

        $rows = json_decode($tester->getDisplay(), false, 512, \JSON_THROW_ON_ERROR);

        $this->assertEquals(new \stdClass(), $rows[0]->fallbacks);
    }

    public function testJsonFormatKeepsMessagesUntouched()
    {
        $message = "Hello <info>world</info> \\<comment>escaped <b>bold</b>, \"quoted\"\n  with   spaces and a text longer than forty characters";
        $tester = $this->createCommandTester(['foo' => 'foo'], ['foo' => $message]);
        $tester->execute(['locale' => 'en', '--format' => 'json'], ['decorated' => true]);

        $rows = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame($message, $rows[0]['translation']);
        $this->assertStringNotContainsString("\e[", $tester->getDisplay());
    }

    public function testJsonFormatDoesNotEscapeSlashesAndUnicodeCharacters()
    {
        $tester = $this->createCommandTester(['foo' => 'foo'], ['foo' => 'é/x']);
        $tester->execute(['locale' => 'en', '--format' => 'json']);

        $this->assertStringContainsString('é/x', $tester->getDisplay());
    }

    public function testJsonFormatThrowsOnInvalidUtf8()
    {
        $tester = $this->createCommandTester(['foo' => 'foo'], ['foo' => "\xB1\x31"]);

        $this->expectException(\JsonException::class);

        $tester->execute(['locale' => 'en', '--format' => 'json']);
    }

    public function testJsonFormatWithNumericIds()
    {
        $tester = $this->createCommandTester(['0' => '0', '123' => '123']);
        $tester->execute(['locale' => 'en', '--format' => 'json']);

        $ids = array_column(json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR), 'id');
        sort($ids);

        $this->assertSame(['0', '123'], $ids);
    }

    public function testJsonFormatWithOnlyMissingOption()
    {
        $tester = $this->createCommandTester(['foo' => 'foo'], ['bar' => 'bar']);
        $res = $tester->execute(['locale' => 'en', '--format' => 'json', '--only-missing' => true]);

        $rows = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame(['foo'], array_column($rows, 'id'));
        $this->assertSame(TranslationDebugCommand::EXIT_CODE_MISSING, $res);
    }

    public function testJsonFormatWithOnlyUnusedOption()
    {
        $tester = $this->createCommandTester(['foo' => 'foo'], ['bar' => 'bar']);
        $res = $tester->execute(['locale' => 'en', '--format' => 'json', '--only-unused' => true]);

        $rows = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame(['bar'], array_column($rows, 'id'));
        $this->assertSame(TranslationDebugCommand::EXIT_CODE_UNUSED, $res);
    }

    public function testJsonFormatWithoutResults()
    {
        $tester = $this->createCommandTester([], ['foo' => 'foo']);
        $res = $tester->execute(['locale' => 'en', '--format' => 'json', '--only-missing' => true]);

        $this->assertSame("[]\n", $tester->getDisplay());
        $this->assertSame(Command::SUCCESS, $res);
    }

    public function testJsonFormatWithDomainOption()
    {
        $extractor = $this->createStub(ExtractorInterface::class);
        $extractor
            ->method('extract')
            ->willReturnCallback(
                static function ($path, $catalogue) {
                    $catalogue->add(['foo' => 'foo'], 'messages');
                    $catalogue->add(['foo' => 'foo'], 'validators');
                }
            );

        $tester = new CommandTester($this->createCommand([], [], null, [], [], $extractor));
        $tester->execute(['locale' => 'en', '--format' => 'json', '--domain' => 'validators']);

        $rows = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame(['validators'], array_column($rows, 'domain'));
    }

    #[DataProvider('provideMachineReadableFormats')]
    public function testNoDefinedMessagesWithMachineReadableFormat(string $format)
    {
        $tester = $this->createCommandTester();
        $res = $tester->execute(['locale' => 'fr', 'bundle' => 'test', '--format' => $format], ['capture_stderr_separately' => true]);

        $this->assertSame('', $tester->getDisplay());
        $this->assertStringContainsString('No defined or extracted messages for locale "fr"', $tester->getErrorOutput());
        $this->assertSame(TranslationDebugCommand::EXIT_CODE_GENERAL_ERROR, $res);
    }

    public static function provideMachineReadableFormats(): iterable
    {
        yield 'json' => ['json'];
        yield 'csv' => ['csv'];
    }

    public function testCsvFormat()
    {
        $tester = $this->createCommandTester(['foo' => 'foo'], [], fallbackMessages: ['en' => ['foo' => 'Hello']]);
        $res = $tester->execute(['locale' => 'fr', '--format' => 'csv']);

        $this->assertSame("states;domain;id;translation_fr;fallback_en\nmissing;messages;foo;;Hello\n", $tester->getDisplay());
        $this->assertSame(TranslationDebugCommand::EXIT_CODE_MISSING, $res);
    }

    public function testCsvFormatWithSeveralFallbackLocales()
    {
        $tester = $this->createCommandTester(['foo' => 'foo'], [], fallbackMessages: ['en' => ['foo' => 'Hello'], 'de' => ['foo' => 'Hallo']], fallbackLocales: ['en', 'de']);
        $tester->execute(['locale' => 'fr', '--format' => 'csv']);

        $this->assertSame("states;domain;id;translation_fr;fallback_en;fallback_de\nmissing;messages;foo;;Hello;Hallo\n", $tester->getDisplay());
    }

    public function testCsvFormatWithSeveralStates()
    {
        $tester = $this->createCommandTester([], ['foo' => 'foo']);
        $res = $tester->execute(['locale' => 'fr', '--format' => 'csv']);

        $this->assertSame("states;domain;id;translation_fr;fallback_en\n\"unused fallback\";messages;foo;foo;foo\n", $tester->getDisplay());
        $this->assertSame(TranslationDebugCommand::EXIT_CODE_UNUSED | TranslationDebugCommand::EXIT_CODE_FALLBACK, $res);
    }

    public function testCsvFormatKeepsMessagesUntouched()
    {
        $message = "Hello <info>world</info> \\<comment>, \"quoted\" a\\\"b\n  with   spaces and a text longer than forty characters";
        $tester = $this->createCommandTester(['foo' => 'foo'], ['foo' => $message]);
        $tester->execute(['locale' => 'en', '--format' => 'csv'], ['decorated' => true]);

        $expected = "states;domain;id;translation_en\n;messages;foo;\"Hello <info>world</info> \\<comment>, \"\"quoted\"\" a\\\"\"b\n  with   spaces and a text longer than forty characters\"\n";

        $this->assertSame($expected, $tester->getDisplay());
    }

    public function testCsvFormatWithoutResultsOnlyDisplaysTheHeaders()
    {
        $tester = $this->createCommandTester([], ['foo' => 'foo']);
        $res = $tester->execute(['locale' => 'fr', '--format' => 'csv', '--only-missing' => true]);

        $this->assertSame("states;domain;id;translation_fr;fallback_en\n", $tester->getDisplay());
        $this->assertSame(Command::SUCCESS, $res);
    }

    public function testDuplicatedFallbackLocalesKeepTheColumnsAligned()
    {
        $tester = $this->createCommandTester(['foo' => 'foo'], [], fallbackMessages: ['en' => ['foo' => 'Hello']], fallbackLocales: ['en', 'en']);

        $tester->execute(['locale' => 'fr']);
        $this->assertSame(2, substr_count($tester->getDisplay(), 'Fallback Message Preview (en)'));
        $this->assertSame(2, substr_count($tester->getDisplay(), 'Hello'));

        $tester->execute(['locale' => 'fr', '--format' => 'csv']);
        $this->assertSame("states;domain;id;translation_fr;fallback_en;fallback_en\nmissing;messages;foo;;Hello;Hello\n", $tester->getDisplay());

        $tester->execute(['locale' => 'fr', '--format' => 'json']);
        $this->assertSame(['en' => 'Hello'], json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR)[0]['fallbacks']);
    }

    public function testInvalidFormatIsRejectedBeforeScanningMessages()
    {
        $extractor = $this->createMock(ExtractorInterface::class);
        $extractor->expects($this->never())->method('extract');

        $tester = new CommandTester($this->createCommand([], [], null, [], [], $extractor));

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Supported formats are "txt", "json", "csv".');

        $tester->execute(['locale' => 'en', '--format' => 'xml']);
    }

    public function testTxtFormat()
    {
        $tester = $this->createCommandTester(['foo' => 'foo', 'bar' => 'bar'], ['bar' => 'Bonjour'], fallbackMessages: ['en' => ['baz' => 'x']]);
        $res = $tester->execute(['locale' => 'fr', '--format' => 'txt']);

        $expected = <<<'TXT'
             ----------- ---------- ----- ---------------------- -------------------------------
              State       Domain     Id    Message Preview (fr)   Fallback Message Preview (en)
             ----------- ---------- ----- ---------------------- -------------------------------
               missing    messages   foo   foo                    foo
                          messages   bar   Bonjour                bar
             ----------- ---------- ----- ---------------------- -------------------------------
            TXT;

        // the table pads its lines with trailing spaces
        $this->assertSame($expected, implode("\n", array_map('rtrim', explode("\n", rtrim($tester->getDisplay())))));
        $this->assertSame(TranslationDebugCommand::EXIT_CODE_MISSING, $res);
    }

    public function testTxtIsTheDefaultFormat()
    {
        $tester = $this->createCommandTester(['foo' => 'foo'], ['bar' => 'bar']);

        $tester->execute(['locale' => 'fr']);
        $default = $tester->getDisplay();

        $tester->execute(['locale' => 'fr', '--format' => 'txt']);

        $this->assertStringContainsString('Message Preview (fr)', $default);
        $this->assertSame($default, $tester->getDisplay());
    }
}
