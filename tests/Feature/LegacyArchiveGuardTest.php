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

    public function test_workflow_write_access_is_limited_to_trusted_automation(): void
    {
        $workflowFiles = $this->filesWithExtensions(base_path('.github/workflows'), ['yml', 'yaml']);

        self::assertNotEmpty($workflowFiles);

        foreach ($workflowFiles as $filePath) {
            $contents = (string) file_get_contents($filePath);

            self::assertMatchesRegularExpression('/permissions:\s+contents:\s*read\b/', $contents, "Workflow {$filePath} must declare read-only contents permission.");

            if (basename($filePath) === 'dependabot-auto-merge.yml') {
                // GitHub needs job-level contents: write to enable auto-merge. This is the only
                // exception that handles pull requests; it must never check out their code.
                self::assertStringNotContainsString('actions/checkout', $contents, "Workflow {$filePath} is privileged and must not check out code.");
            } elseif (basename($filePath) === 'coverage-badge.yml') {
                // Badge publishing reads artifacts only from successful CI runs on this repo's
                // default branch and checks out trusted code with credentials disabled.
                self::assertStringContainsString('workflow_run:', $contents);
                self::assertStringContainsString('branches: [master]', $contents);
                self::assertStringContainsString("github.event.workflow_run.conclusion == 'success'", $contents);
                self::assertStringContainsString("github.event.workflow_run.event != 'pull_request'", $contents);
                self::assertStringContainsString('github.event.workflow_run.head_repository.full_name == github.repository', $contents);
                self::assertStringContainsString('ref: master', $contents);
                self::assertStringContainsString('persist-credentials: false', $contents);
            } else {
                self::assertDoesNotMatchRegularExpression('/contents:\s*write\b/', $contents, "Workflow {$filePath} must not request contents write permission.");
            }

            self::assertDoesNotMatchRegularExpression('/\bgit\s+(commit|push)\b/', $contents, "Workflow {$filePath} must not commit or push repository changes.");
            self::assertStringNotContainsString('download:bing-wallpaper', $contents);
            self::assertStringNotContainsString('update:readme', $contents);
        }
    }

    public function test_wallpaper_images_are_ignored_and_no_legacy_pngs_are_tracked(): void
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
        self::assertSame([], $trackedFiles, 'Legacy PNGs must remain outside the current tracked tree.');
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
