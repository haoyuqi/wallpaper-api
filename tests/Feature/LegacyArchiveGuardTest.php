<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class LegacyArchiveGuardTest extends TestCase
{
    public function test_legacy_downloader_and_readme_generator_are_removed(): void
    {
        self::assertFileDoesNotExist(base_path('app/Console/Commands/DownloadBingWallpaperCommand.php'));
        self::assertFileDoesNotExist(base_path('app/Console/Commands/UpdateReadmeCommand.php'));
        self::assertFileDoesNotExist(storage_path('readme-template.dat'));

        $commands = array_keys(Artisan::all());

        self::assertNotContains('download:bing-wallpaper', $commands);
        self::assertNotContains('update:readme', $commands);
        self::assertContains('wallpapers:sync', $commands);
    }

    public function test_runtime_paths_do_not_reference_legacy_image_archive_or_readme_template(): void
    {
        $runtimeFiles = array_merge(
            $this->filesWithExtensions(base_path('app'), ['php']),
            $this->filesWithExtensions(base_path('config'), ['php']),
            $this->filesWithExtensions(base_path('routes'), ['php']),
            [base_path('bootstrap/app.php'), base_path('bootstrap/providers.php')],
        );

        foreach ($runtimeFiles as $filePath) {
            $contents = (string) file_get_contents($filePath);

            self::assertDoesNotMatchRegularExpression(
                '/storage(?:_path\s*\(\s*[\'"]|[\/\\\\])bing-wallpaper/',
                $contents,
                "Unexpected legacy archive reference in {$filePath}."
            );
            self::assertStringNotContainsString('readme-template.dat', $contents, "Unexpected legacy template reference in {$filePath}.");
        }
    }

    public function test_workflows_are_read_only_and_do_not_commit_or_download_wallpapers(): void
    {
        $workflowFiles = $this->filesWithExtensions(base_path('.github/workflows'), ['yml', 'yaml']);

        self::assertNotEmpty($workflowFiles);

        foreach ($workflowFiles as $filePath) {
            $contents = (string) file_get_contents($filePath);

            self::assertMatchesRegularExpression('/permissions:\s+contents:\s*read\b/', $contents, "Workflow {$filePath} must declare read-only contents permission.");
            self::assertDoesNotMatchRegularExpression('/contents:\s*write\b/', $contents, "Workflow {$filePath} must not request contents write permission.");
            self::assertDoesNotMatchRegularExpression('/\bgit\s+(commit|push)\b/', $contents, "Workflow {$filePath} must not commit or push repository changes.");
            self::assertStringNotContainsString('download:bing-wallpaper', $contents);
            self::assertStringNotContainsString('update:readme', $contents);
        }
    }

    public function test_new_wallpaper_images_are_ignored_while_tracked_archive_remains_intact(): void
    {
        $gitignore = (string) file_get_contents(base_path('.gitignore'));

        self::assertStringContainsString('/storage/bing-wallpaper/**', $gitignore);

        $ignoreCheck = new Process(
            ['git', 'check-ignore', 'storage/bing-wallpaper/2026/10/2026-10-20.png'],
            base_path()
        );
        $ignoreCheck->run();

        self::assertTrue($ignoreCheck->isSuccessful(), 'New files under storage/bing-wallpaper/ must be ignored by Git.');

        $trackedArchive = new Process(
            ['git', 'ls-files', 'storage/bing-wallpaper/*.png'],
            base_path()
        );
        $trackedArchive->run();

        self::assertTrue($trackedArchive->isSuccessful());
        $trackedFiles = array_values(array_filter(explode("\n", trim($trackedArchive->getOutput()))));
        // WAPI-10 retains the 321 legacy PNG files; update this assertion and the tracked-precedence check if WAPI-12 removes the archive.
        self::assertCount(321, $trackedFiles);

        $trackedPrecedenceCheck = new Process(
            ['git', 'check-ignore', $trackedFiles[0]],
            base_path()
        );
        $trackedPrecedenceCheck->run();

        self::assertSame(1, $trackedPrecedenceCheck->getExitCode(), 'Tracked archive files must remain tracked despite the ignore rule.');
    }

    private function filesWithExtensions(string $directory, array $extensions): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), $extensions, true)) {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files, SORT_STRING);

        return $files;
    }
}
