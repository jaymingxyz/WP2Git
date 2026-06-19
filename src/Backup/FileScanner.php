<?php

declare(strict_types=1);

namespace WP2Git\Backup;

defined( 'ABSPATH' ) || exit;

use WP2Git\Logger;
use WP2Git\Sync\Paths;
use WP2Git\Sync\Scope;

/**
 * Walks in-scope wp-content directories and returns a map of repo-relative path
 * to git blob hash. Symlinks are not followed (containment), oversized files
 * are skipped (GitHub's 100 MB blob limit).
 */
final class FileScanner {

	private const MAX_FILE_BYTES = 95 * 1024 * 1024;

	public function __construct(
		private readonly Paths $paths,
		private readonly Scope $scope,
		private readonly Logger $logger,
	) {
	}

	/**
	 * @return array<string,string> repoPath => git blob sha
	 */
	public function scan(): array {
		$map = array();
		foreach ( $this->scope->dirs() as $dir ) {
			$abs = $this->paths->toLocal( $dir );
			if ( ! is_dir( $abs ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $abs, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $file ) {
				/** @var \SplFileInfo $file */
				if ( ! $file->isFile() || $file->isLink() ) {
					continue;
				}
				$repoPath = $this->paths->toRepo( $file->getPathname() );
				if ( $repoPath === null || $this->scope->isExcluded( $repoPath ) ) {
					continue;
				}
				if ( $file->getSize() > self::MAX_FILE_BYTES ) {
					$this->logger->error(
						'File too large to back up; skipped',
						array(
							'path'  => $repoPath,
							'bytes' => $file->getSize(),
						)
					);
					continue;
				}
				// Local filesystem read; @ tolerates an unreadable file (handled below).
				$content = @file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( $content === false ) {
					continue;
				}
				$map[ $repoPath ] = Paths::gitBlobSha( $content );
			}
		}
		return $map;
	}
}
