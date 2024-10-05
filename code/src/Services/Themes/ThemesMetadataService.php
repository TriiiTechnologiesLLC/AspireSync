<?php

declare(strict_types=1);

namespace AssetGrabber\Services\Themes;

use Aura\Sql\ExtendedPdoInterface;
use Exception;
use PDOException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use RuntimeException;

class ThemesMetadataService
{
    private array $existing = [];

    public function __construct(private ExtendedPdoInterface $pdo)
    {
        $this->existing = $this->loadExistingThemes();
    }

    /**
     * @return array|string[]
     */
    public function checkThemeInDatabase(string $slug): array
    {
        if (isset($this->existing[$slug])) {
            return $this->existing[$slug];
        }

        return [];
    }

    private function loadExistingThemes(): array
    {
        $sql    = 'SELECT slug, pulled_at FROM themes';
        $result = [];
        foreach ($this->pdo->fetchAll($sql) as $row) {
            $result[$row['slug']] = ['pulled_at' => $row['pulled_at']];
        }

        return $result;
    }

    /**
     * @param  array<string, string|array<string, string>>  $fileContents
     * @return array|string[]
     */
    public function updateThemeFromWP(array $fileContents, string $pulledAt): array
    {
        return $this->updateTheme($fileContents, $pulledAt);
    }

    /**
     * @param  array<string, string|array<string, string>>  $themeMetadata
     * @return array|string[]
     */
    public function saveThemeFromWP(array $themeMetadata, string $pulledAt): array
    {
        $this->pdo->beginTransaction();

        try {
            $name           = substr($themeMetadata['name'], 0, 255);
            $slug           = $themeMetadata['slug'];
            $currentVersion = $themeMetadata['version'];
            $versions       = $themeMetadata['versions'];
            $updatedAt      = date('c', strtotime($themeMetadata['last_updated']));
            $id             = Uuid::uuid7();

            $themeMetadata['aspirepress_meta'] = [
                'seen'      => date('c'),
                'added'     => date('c'),
                'updated'   => date('c'),
                'processed' => null,
                'finalized' => null,
            ];

            $sql = 'INSERT INTO themes (id, name, slug, current_version, updated, pulled_at, metadata) VALUES (:id, :name, :slug, :current_version, :updated_at, :pulled_at, :metadata)';
            $this->pdo->perform($sql, [
                'id'              => $id->toString(),
                'name'            => $name,
                'slug'            => $slug,
                'current_version' => $currentVersion,
                'updated_at'      => $updatedAt,
                'pulled_at'       => $pulledAt,
                'metadata'        => json_encode($themeMetadata),
            ]);

            if (empty($themeMetadata['versions'])) {
                $versions[$themeMetadata['version']] = $themeMetadata['download_link'];
            } else {
                $versions = $themeMetadata['versions'];
            }

            $versionResult = $this->writeVersionsForTheme($id, $versions, 'wp_cdn');

            if (! empty($versionResult['error'])) {
                throw new Exception('Unable to write versions for theme ' . $slug);
            }
            $this->pdo->commit();
            return ['error' => ''];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['error' => $e->getMessage()];
        }
    }

    private function updateTheme(array $fileContents, string $pulledAt): array
    {
        $this->pdo->beginTransaction();

        try {
            $mdSql      = 'SELECT id, metadata FROM themes WHERE slug = :slug';
            $result     = $this->pdo->fetchOne($mdSql, ['slug' => $fileContents['slug']]);
            $metadata   = json_decode($result['metadata'], true);
            $id         = Uuid::fromString($result['id']);
            $apMetadata = $metadata['aspirepress_meta'];

            $newMetadata                     = $fileContents;
            $newMetadata['aspirepress_meta'] = [
                'seen'      => $apMetadata['seen'],
                'added'     => $apMetadata['added'],
                'updated'   => date('c'),
                'processed' => null,
                'finalized' => null,
            ];

            $name           = substr($fileContents['name'], 0, 255);
            $slug           = $fileContents['slug'];
            $currentVersion = $fileContents['version'];
            $versions       = $fileContents['versions'];
            $updatedAt      = date('c', strtotime($fileContents['last_updated']));

            $sql = 'UPDATE themes SET metadata = :metadata, name = :name, current_version = :current_version, status = :status, updated = :updated, pulled_at = :pulled_at WHERE slug = :slug';
            $this->pdo->perform($sql, [
                'name'            => $name,
                'slug'            => $slug,
                'current_version' => $currentVersion,
                'status'          => 'open',
                'updated'         => $updatedAt,
                'pulled_at'       => $pulledAt,
                'metadata'        => json_encode($newMetadata),
            ]);

            if (! isset($fileContents['versions']) || empty($fileContents['versions'])) {
                $versions = [$fileContents['version'] => $fileContents['download_link']];
            } else {
                $versions = $fileContents['versions'];
            }

            $newVersions = $this->getNewlyDiscoveredVersionsList($id, $versions);

            $versionResult = $this->writeVersionsForTheme($id, $newVersions, 'wp_cdn');

            if (! empty($versionResult['error'])) {
                throw new Exception('Unable to write versions for theme ' . $slug);
            }

            $this->pdo->commit();
            return ['error' => ''];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['error' => $e->getMessage()];
        }
    }

    private function writeVersionsForTheme(UuidInterface $themeId, array $versions, string $cdn = 'wp_cdn'): array
    {
        $sql = 'INSERT INTO theme_files (id, theme_id, file_url, type, version, created) VALUES (:id, :theme_id, :file_url, :type, :version, NOW())';

        if (! $this->pdo->inTransaction()) {
            $ourTransaction = true;
            $this->pdo->beginTransaction();
        }

        try {
            foreach ($versions as $version => $url) {
                $this->pdo->perform($sql, [
                    'id'       => Uuid::uuid7()->toString(),
                    'theme_id' => $themeId->toString(),
                    'file_url' => $url,
                    'type'     => $cdn,
                    'version'  => $version,
                ]);
            }

            if (isset($ourTransaction)) {
                $this->pdo->commit();
            }

            return ['error' => ''];
        } catch (PDOException $e) {
            if (isset($ourTransaction)) {
                $this->pdo->rollBack();
            }
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param  string[]  $versions
     * @return string[]
     */
    private function getNewlyDiscoveredVersionsList(UuidInterface $id, array $versions): array
    {
        $existingVersions = 'SELECT version FROM theme_files WHERE theme_id = :id';
        $existingVersions = $this->pdo->fetchAll($existingVersions, ['id' => $id->toString()]);

        $newVersions = [];
        foreach ($versions as $version => $url) {
            if (! in_array($version, $existingVersions)) {
                $newVersions[$version] = $url;
            }
        }

        return $newVersions;
    }

    /**
     * @param string[] $versions
     * @return string[]
     */
    public function getUnprocessedVersions(string $theme, array $versions, string $type = 'wp_cdn'): array
    {
        $sql     = 'SELECT version FROM theme_files LEFT JOIN themes ON themes.id = theme_files.theme_id WHERE type = :type AND themes.slug = :theme AND processed IS NULL AND theme_files.version IN (:versions)';
        $results = $this->pdo->fetchAll($sql, ['theme' => $theme, 'type' => $type, 'versions' => $versions]);
        $return  = [];
        foreach ($results as $result) {
            $return[] = $result['version'];
        }
        return $return;
    }

    /**
     * @param array<int, string> $versions
     * @return array<string, string>
     */
    public function getDownloadUrlsForVersions(string $theme, array $versions, string $type = 'wp_cdn'): array
    {
        try {
            $sql = 'SELECT version, file_url FROM theme_files LEFT JOIN themes ON themes.id = theme_files.theme_id WHERE themes.slug = :theme AND theme_files.type = :type AND version IN (:versions)';

            $results = $this->pdo->fetchAll($sql, ['theme' => $theme, 'type' => $type, 'versions' => $versions]);
            $return  = [];
            foreach($results as $result) {
                $return[$result['version']] = $result['file_url'];
            }
            return $return;
        } catch (PDOException $e) {
            throw new RuntimeException('Unable to get download URLs for theme ' . $theme . '; reason: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, string[]>
     */
    public function getVersionsForUnfinalizedThemes(string $type = 'wp_cdn'): array
    {
        try {
            $sql         = "SELECT themes.id, slug, version FROM theme_files LEFT JOIN themes ON themes.id = theme_files.theme_id WHERE theme_files.type = :type";
            $result      = $this->pdo->fetchAll($sql, ['type' => $type]);
            $finalResult = [];
            foreach ($result as $row) {
                $theme = $row['slug'];
                $version = $row['version'];
                $finalResult[$theme][] = $version;
            }
            return $finalResult;
        } catch (PDOException $e) {
            throw new RuntimeException('Unable to get versions for themes; reason: ' . $e->getMessage());
        }
    }

    public function setVersionToDownloaded(string $theme, string $version, string $type = 'wp_cdn'): void
    {
        $sql = 'UPDATE theme_files SET processed = NOW() WHERE version = :version AND type = :type AND theme_id = (SELECT id FROM themes WHERE slug = :theme)';
        $this->pdo->perform($sql, ['theme' => $theme, 'type' => $type, 'version' => $version]);
    }}
