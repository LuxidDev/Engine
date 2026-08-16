<?php

declare(strict_types=1);

namespace Luxid\Tests\Foundation;

use Luxid\Foundation\PackageManifest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the compiled package manifest.
 *
 * @package Luxid\Tests\Foundation
 */
final class PackageManifestTest extends TestCase
{
    /**
     * Temporary vendor directory for each test.
     */
    private string $vendor = '';

    protected function setUp(): void
    {
        parent::setUp();

        PackageManifest::flush();

        $this->vendor = sys_get_temp_dir() . '/luxid-manifest-' . bin2hex(random_bytes(6));
        mkdir($this->vendor . '/composer', 0o755, true);
    }

    protected function tearDown(): void
    {
        PackageManifest::flush();

        foreach (glob($this->vendor . '/composer/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->vendor . '/composer');

        foreach (glob($this->vendor . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->vendor);

        parent::tearDown();
    }

    /**
     * Write an installed.json describing the given packages.
     *
     * @param list<array<string, mixed>> $packages Package entries
     */
    private function givenInstalled(array $packages): void
    {
        file_put_contents(
            $this->vendor . '/composer/installed.json',
            json_encode(['packages' => $packages], JSON_THROW_ON_ERROR)
        );
    }

    #[Test]
    public function it_returns_empty_lists_when_nothing_is_installed(): void
    {
        $manifest = new PackageManifest($this->vendor);

        $this->assertSame([], $manifest->providers());
        $this->assertSame([], $manifest->commands());
    }

    #[Test]
    public function it_collects_providers_and_commands(): void
    {
        $this->givenInstalled([
            ['name' => 'a/one', 'extra' => ['luxid' => ['providers' => ['A\\Provider']]]],
            ['name' => 'b/two', 'extra' => ['luxid' => ['commands' => ['b:go' => 'B\\Command']]]],
            ['name' => 'c/none'],
        ]);

        $manifest = new PackageManifest($this->vendor);

        $this->assertSame(['A\\Provider'], $manifest->providers());
        $this->assertSame(['b:go' => 'B\\Command'], $manifest->commands());
    }

    #[Test]
    public function it_compiles_the_manifest_to_a_php_file(): void
    {
        $this->givenInstalled([
            ['name' => 'a/one', 'extra' => ['luxid' => ['providers' => ['A\\Provider']]]],
        ]);

        (new PackageManifest($this->vendor))->providers();

        $this->assertFileExists($this->vendor . '/luxid-manifest.php');
        $this->assertSame(['A\\Provider'], (include $this->vendor . '/luxid-manifest.php')['providers']);
    }

    #[Test]
    public function it_reads_the_compiled_file_without_touching_installed_json(): void
    {
        $this->givenInstalled([
            ['name' => 'a/one', 'extra' => ['luxid' => ['providers' => ['A\\Provider']]]],
        ]);

        (new PackageManifest($this->vendor))->providers();
        PackageManifest::flush();

        // Removing the source proves the compiled copy is what gets read.
        unlink($this->vendor . '/composer/installed.json');

        $this->assertSame(['A\\Provider'], (new PackageManifest($this->vendor))->providers());
    }

    #[Test]
    public function it_rebuilds_when_installed_json_changes(): void
    {
        $this->givenInstalled([
            ['name' => 'a/one', 'extra' => ['luxid' => ['providers' => ['A\\Provider']]]],
        ]);

        (new PackageManifest($this->vendor))->providers();
        PackageManifest::flush();

        $this->givenInstalled([
            ['name' => 'a/one', 'extra' => ['luxid' => ['providers' => ['A\\Provider']]]],
            ['name' => 'b/two', 'extra' => ['luxid' => ['providers' => ['B\\Provider']]]],
        ]);

        // Freshness is decided by mtime, so make the source unambiguously newer.
        touch($this->vendor . '/composer/installed.json', time() + 10);

        $this->assertSame(
            ['A\\Provider', 'B\\Provider'],
            (new PackageManifest($this->vendor))->providers()
        );
    }

    #[Test]
    public function it_deduplicates_providers(): void
    {
        $this->givenInstalled([
            ['name' => 'a/one', 'extra' => ['luxid' => ['providers' => ['Same\\Provider']]]],
            ['name' => 'b/two', 'extra' => ['luxid' => ['providers' => ['Same\\Provider']]]],
        ]);

        $this->assertSame(['Same\\Provider'], (new PackageManifest($this->vendor))->providers());
    }

    #[Test]
    public function it_tolerates_malformed_installed_json(): void
    {
        file_put_contents($this->vendor . '/composer/installed.json', 'not json');

        $this->assertSame([], (new PackageManifest($this->vendor))->providers());
    }

    #[Test]
    public function forget_removes_the_compiled_file(): void
    {
        $this->givenInstalled([['name' => 'a/one', 'extra' => ['luxid' => ['providers' => ['A\\P']]]]]);

        $manifest = new PackageManifest($this->vendor);
        $manifest->providers();
        $manifest->forget();

        $this->assertFileDoesNotExist($this->vendor . '/luxid-manifest.php');
    }

    #[Test]
    public function rebuild_picks_up_changes_without_a_freshness_check(): void
    {
        $this->givenInstalled([['name' => 'a/one', 'extra' => ['luxid' => ['providers' => ['A\\P']]]]]);

        $manifest = new PackageManifest($this->vendor);
        $manifest->providers();

        $this->givenInstalled([['name' => 'b/two', 'extra' => ['luxid' => ['providers' => ['B\\P']]]]]);

        $this->assertSame(['B\\P'], $manifest->rebuild()['providers']);
    }
}
