<?php

declare(strict_types=1);

namespace WP2Git\Backup;

defined( 'ABSPATH' ) || exit;

use WP2Git\Logger;
use WP2Git\Settings;
use WP2Git\Sync\Paths;
use WP_Post;

/**
 * Exports selected, explicitly opted-in database content as deterministic JSON.
 *
 * Database artifacts are deliberately backup-only. This class has no matching
 * importer and never writes to WordPress data. Each artifact is namespaced by
 * the current blog ID so a network-scoped repository cannot conflate sites.
 */
final class DatabaseExporter {

	public const PREFIX = 'wp2git-data/v1/sites/';

	public const GROUPS = array(
		'site_editor',
		'customizer',
		'menus',
		'custom_css',
		'widgets',
		'plugin_templates',
		'wpcode',
	);

	private const SITE_EDITOR_POST_TYPES = array(
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_block',
		'wp_font_family',
		'wp_font_face',
	);

	private const KNOWN_PLUGIN_TEMPLATE_POST_TYPES = array(
		'elementor_library',
		'elementor-hf',
		'e-landing-page',
		'et_pb_layout',
		'fl-builder-template',
		'fl-theme-layout',
		'bricks_template',
		'ct_template',
		'oxygen_template',
		'fusion_template',
		'fusion_tb_section',
		'vc_grid_item',
		'breakdance_template',
		'zion_template',
		'kadence_element',
		'gp_elements',
		'astra-advanced-hook',
		'blocksy_content_blk',
		'ct_content_block',
		'ae_global_templates',
		'templately_library',
		'woolentor-template',
		'seedprod',
	);

	private const WPCODE_LEGACY_OPTIONS = array(
		'ihaf_insert_header',
		'ihaf_insert_body',
		'ihaf_insert_footer',
	);

	private const VOLATILE_META_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_desired_post_slug',
		'_elementor_css',
		'_elementor_screenshot',
		'_elementor_controls_usage',
		'_elementor_page_assets',
	);

	/** @var array<string,string> Repo path to rendered JSON. */
	private array $snapshots = array();

	/** @var array<string,bool> Whether a group completed an authoritative scan. */
	private array $authoritative = array();

	/** @var list<string> Error messages from the most recent scan. */
	private array $scanErrors = array();

	/** @var array<string,list<string>> */
	private array $postTypeCache = array();

	/** @var list<string>|null */
	private ?array $customizerOptionCache = null;

	/** @var list<string>|null */
	private ?array $widgetOptionCache = null;

	private bool $scanned = false;

	private int $cacheBlogId = 0;

	public function __construct(
		private readonly Settings $settings,
		private readonly ?Logger $logger = null,
	) {
	}

	public function isEnabled(): bool {
		return $this->enabledGroups() !== array();
	}

	/** @return list<string> */
	public function enabledGroups(): array {
		$stored = $this->settings->get( 'database_groups', array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$enabled = array();
		foreach ( self::GROUPS as $group ) {
			if ( ! empty( $stored[ $group ] ) || in_array( $group, $stored, true ) ) {
				$enabled[] = $group;
			}
		}
		return $enabled;
	}

	/** Whether a repository path belongs to the versioned database namespace. */
	public function owns( string $repoPath ): bool {
		return $this->parsePath( $repoPath ) !== null;
	}

	/** Manifest content type for an owned path. */
	public function contentType( string $repoPath ): string {
		$parsed = $this->parsePath( $repoPath );
		return $parsed === null ? 'database' : $parsed['group'];
	}

	/**
	 * Render every artifact for the current blog and return Git-compatible hashes.
	 *
	 * A group is authoritative only after its entire scan succeeds. Partial output
	 * from a failed group is discarded so an unavailable plugin or database error
	 * cannot make its existing remote backups look deleted.
	 *
	 * @return array<string,string> Repo path to Git blob SHA.
	 */
	public function scan(): array {
		$this->resetForCurrentBlog();
		$this->snapshots     = array();
		$this->authoritative = array();
		$this->scanErrors    = array();
		$this->scanned       = true;

		$enabled = $this->enabledGroups();
		foreach ( self::GROUPS as $group ) {
			if ( ! in_array( $group, $enabled, true ) ) {
				$this->authoritative[ $group ] = true;
				continue;
			}

			$this->authoritative[ $group ] = false;
			try {
				$groupSnapshots = $this->scanGroup( $group );
				foreach ( $groupSnapshots as $path => $content ) {
					if ( isset( $this->snapshots[ $path ] ) ) {
						throw new \RuntimeException( 'Duplicate database backup path: ' . $path );
					}
					$this->snapshots[ $path ] = $content;
				}
				$this->authoritative[ $group ] = true;
			} catch ( \Throwable $error ) {
				$this->authoritative[ $group ] = false;
				$this->scanErrors[]            = $group . ': ' . $error->getMessage();
				$this->logger?->error(
					'Database snapshot scan failed',
					array(
						'group'  => $group,
						'detail' => $error->getMessage(),
					)
				);
				/**
				 * Fires when an opted-in database group cannot be scanned safely.
				 *
				 * @param string     $group Group key.
				 * @param \Throwable $error Failure detail.
				 */
				do_action( 'wp2git_database_export_error', $group, $error );
			}
		}

		ksort( $this->snapshots, SORT_STRING );
		$hashes = array();
		foreach ( $this->snapshots as $path => $content ) {
			$hashes[ $path ] = Paths::gitBlobSha( $content );
		}
		return $hashes;
	}

	/** Render an owned current-blog path, or null when it is absent/unavailable. */
	public function render( string $repoPath ): ?string {
		$this->resetForCurrentBlog();
		$parsed = $this->parsePath( $repoPath );
		if ( $parsed === null || $parsed['site'] !== get_current_blog_id() ) {
			return null;
		}
		if ( ! $this->scanned ) {
			$this->scan();
		}
		return $this->snapshots[ ltrim( $repoPath, '/' ) ] ?? null;
	}

	/**
	 * Diagnostic summary from the most recent scan() call.
	 *
	 * @return array{enabled:int,succeeded:int,snapshots:int,errors:list<string>}
	 */
	public function lastScanStats(): array {
		$enabled   = $this->enabledGroups();
		$succeeded = 0;
		foreach ( $enabled as $group ) {
			if ( ! empty( $this->authoritative[ $group ] ) ) {
				++$succeeded;
			}
		}
		return array(
			'enabled'   => count( $enabled ),
			'succeeded' => $succeeded,
			'snapshots' => count( $this->snapshots ),
			'errors'    => $this->scanErrors,
		);
	}

	/** Whether a post change should trigger a debounced database backup. */
	public function watchesPostType( string $type ): bool {
		$type = sanitize_key( $type );
		foreach ( $this->enabledGroups() as $group ) {
			if ( in_array( $type, $this->detectedPostTypes( $group ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether an option change should trigger a debounced database backup. */
	public function watchesOption( string $name ): bool {
		$enabled = $this->enabledGroups();

		if ( in_array( 'customizer', $enabled, true )
			&& ( str_starts_with( $name, 'theme_mods_' ) || in_array( $name, $this->customizerOptionNames(), true ) ) ) {
			return true;
		}

		if ( in_array( 'menus', $enabled, true )
			&& ( $name === 'nav_menu_options' || $name === 'theme_mods_' . get_stylesheet() ) ) {
			return true;
		}

		if ( in_array( 'wpcode', $enabled, true ) && in_array( $name, self::WPCODE_LEGACY_OPTIONS, true ) ) {
			return true;
		}

		if ( in_array( 'widgets', $enabled, true ) ) {
			if ( $name === 'sidebars_widgets' ) {
				return true;
			}
			try {
				if ( in_array( $name, $this->widgetOptionNames(), true ) ) {
					return true;
				}
			} catch ( \Throwable $error ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Whether a missing local artifact is safe to delete from the repository.
	 *
	 * Other-blog paths are never authoritative in this instance. Disabled groups
	 * are intentionally authoritative-empty; enabled groups must have completed a
	 * successful scan before absence is meaningful.
	 */
	public function shouldDeleteMissing( string $repoPath ): bool {
		$this->resetForCurrentBlog();
		$parsed = $this->parsePath( $repoPath );
		if ( $parsed === null || $parsed['site'] !== get_current_blog_id() ) {
			return false;
		}

		if ( ! in_array( $parsed['group'], $this->enabledGroups(), true ) ) {
			return true;
		}

		return $this->authoritative[ $parsed['group'] ] ?? false;
	}

	/**
	 * Post types represented by a group, including rows left by inactive plugins.
	 *
	 * @return list<string>
	 */
	public function detectedPostTypes( string $group ): array {
		$this->resetForCurrentBlog();
		if ( isset( $this->postTypeCache[ $group ] ) ) {
			return $this->postTypeCache[ $group ];
		}

		$types = match ( $group ) {
			'site_editor'     => self::SITE_EDITOR_POST_TYPES,
			'menus'           => array( 'nav_menu_item' ),
			'custom_css'      => array( 'custom_css' ),
			'wpcode'          => $this->wpcodePostTypes(),
			'plugin_templates' => $this->detectPluginTemplatePostTypes(),
			default           => array(),
		};

		$types = $this->normalizePostTypes( $types );
		$this->postTypeCache[ $group ] = $types;
		return $types;
	}

	/** @return array<string,string> */
	private function scanGroup( string $group ): array {
		return match ( $group ) {
			'site_editor'      => $this->snapshotPosts( $group, $this->detectedPostTypes( $group ) ),
			'customizer'       => $this->snapshotCustomizer(),
			'menus'            => $this->snapshotMenus(),
			'custom_css'       => $this->snapshotPosts( $group, $this->detectedPostTypes( $group ) ),
			'widgets'          => $this->snapshotWidgets(),
			'plugin_templates' => $this->snapshotPosts( $group, $this->detectedPostTypes( $group ) ),
			'wpcode'           => $this->snapshotWpcode(),
			default            => array(),
		};
	}

	/**
	 * Query IDs directly so post types belonging to inactive plugins are retained.
	 *
	 * @param list<string> $postTypes
	 * @return array<string,string>
	 */
	private function snapshotPosts( string $group, array $postTypes ): array {
		$snapshots = array();
		foreach ( $this->postIds( $postTypes ) as $postId ) {
			$post = get_post( $postId );
			if ( ! $post instanceof WP_Post || $post->post_status === 'trash' || $post->post_type === 'revision' ) {
				continue;
			}

			$relative = $this->pathToken( $post->post_type ) . '/' . $post->ID . '.json';
			$identity = array(
				'post_id'   => (int) $post->ID,
				'post_type' => $post->post_type,
				'slug'      => $post->post_name,
			);
			$this->addSnapshot( $snapshots, $group, $relative, $identity, $this->postData( $post ) );
		}
		return $snapshots;
	}

	/** @return array<string,string> */
	private function snapshotCustomizer(): array {
		$snapshots = array();
		foreach ( $this->customizerOptionNames() as $optionName ) {
			[ $exists, $value ] = $this->readOption( $optionName );
			if ( ! $exists ) {
				continue;
			}

			if ( $this->isSensitiveKey( $optionName ) ) {
				$value = '[REDACTED]';
			}
			$this->addSnapshot(
				$snapshots,
				'customizer',
				'options/' . $this->pathToken( $optionName ) . '.json',
				array( 'option_name' => $optionName ),
				array(
					'option_name' => $optionName,
					'value'       => $value,
				)
			);
		}
		return $snapshots;
	}

	/** @return array<string,string> */
	private function snapshotMenus(): array {
		global $wpdb;

		// Direct reads preserve menus even when a theme/plugin changes registration.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$terms = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.description, tt.parent
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				WHERE tt.taxonomy = %s
				ORDER BY t.term_id ASC",
				'nav_menu'
			),
			ARRAY_A
		);
		$this->throwOnDatabaseError( 'Could not read navigation menus.' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$menus = array();
		foreach ( (array) $terms as $term ) {
			$termId = (int) $term['term_id'];
			$items  = array();
			foreach ( $this->menuItemIds( (int) $term['term_taxonomy_id'] ) as $postId ) {
				$post = get_post( $postId );
				if ( $post instanceof WP_Post && $post->post_status !== 'trash' ) {
					$items[] = $this->postData( $post );
				}
			}

			$menus[] = array(
				'term_id'          => $termId,
				'term_taxonomy_id' => (int) $term['term_taxonomy_id'],
				'name'             => (string) $term['name'],
				'slug'             => (string) $term['slug'],
				'description'      => (string) $term['description'],
				'parent'           => (int) $term['parent'],
				'meta'             => $this->termMeta( $termId ),
				'items'            => $items,
			);
		}

		[ $hasOptions, $navMenuOptions ] = $this->readOption( 'nav_menu_options' );
		$data = array(
			'theme'              => get_stylesheet(),
			'nav_menu_locations' => (array) get_nav_menu_locations(),
			'nav_menu_options'   => $hasOptions ? $navMenuOptions : array(),
			'menus'              => $menus,
		);

		$snapshots = array();
		$this->addSnapshot(
			$snapshots,
			'menus',
			'classic-menus.json',
			array( 'scope' => 'classic-menus' ),
			$data
		);
		return $snapshots;
	}

	/** @return array<string,string> */
	private function snapshotWidgets(): array {
		[ $hasSidebars, $sidebars ] = $this->readOption( 'sidebars_widgets' );
		$sidebars                  = $hasSidebars && is_array( $sidebars ) ? $sidebars : array();
		$options                   = array();
		foreach ( $this->widgetOptionNames( $sidebars ) as $optionName ) {
			[ $exists, $value ] = $this->readOption( $optionName );
			if ( $exists ) {
				$options[ $optionName ] = $this->isSensitiveKey( $optionName ) ? '[REDACTED]' : $value;
			}
		}

		$snapshots = array();
		$this->addSnapshot(
			$snapshots,
			'widgets',
			'widgets.json',
			array( 'scope' => 'widgets' ),
			array(
				'theme'            => get_stylesheet(),
				'sidebars_widgets' => $sidebars,
				'options'          => $options,
			)
		);
		return $snapshots;
	}

	/** @return array<string,string> */
	private function snapshotWpcode(): array {
		$snapshots = $this->snapshotPosts( 'wpcode', $this->detectedPostTypes( 'wpcode' ) );
		$options   = array();
		foreach ( self::WPCODE_LEGACY_OPTIONS as $optionName ) {
			[ $exists, $value ] = $this->readOption( $optionName );
			if ( $exists ) {
				$options[ $optionName ] = $value;
			}
		}

		if ( $options !== array() ) {
			$this->addSnapshot(
				$snapshots,
				'wpcode',
				'legacy-options.json',
				array( 'scope' => 'legacy-header-footer-options' ),
				array( 'options' => $options )
			);
		}
		return $snapshots;
	}

	/**
	 * @param list<string> $postTypes
	 * @return list<int>
	 */
	private function postIds( array $postTypes ): array {
		global $wpdb;

		$postTypes = $this->normalizePostTypes( $postTypes );
		if ( $postTypes === array() ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $postTypes ), '%s' ) );
		$args         = array_merge( $postTypes, array( 'trash', 'auto-draft', 'revision' ) );
		// Table/placeholder strings are internal; values remain parameterized.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type IN ({$placeholders})
			AND post_status NOT IN (%s, %s)
			AND post_type <> %s
			ORDER BY ID ASC",
			...$args
		);
		$ids = $wpdb->get_col( $query );
		$this->throwOnDatabaseError( 'Could not read database-backed posts.' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_values( array_map( 'intval', (array) $ids ) );
	}

	/** @return array<string,mixed> */
	private function postData( WP_Post $post ): array {
		return array(
			'ID'                    => (int) $post->ID,
			'post_author'           => (int) $post->post_author,
			'post_date'             => (string) $post->post_date,
			'post_date_gmt'         => (string) $post->post_date_gmt,
			'post_content'          => (string) $post->post_content,
			'post_title'            => (string) $post->post_title,
			'post_excerpt'          => (string) $post->post_excerpt,
			'post_status'           => (string) $post->post_status,
			'comment_status'        => (string) $post->comment_status,
			'ping_status'           => (string) $post->ping_status,
			'post_name'             => (string) $post->post_name,
			'post_modified'         => (string) $post->post_modified,
			'post_modified_gmt'     => (string) $post->post_modified_gmt,
			'post_content_filtered' => (string) $post->post_content_filtered,
			'post_parent'           => (int) $post->post_parent,
			'menu_order'            => (int) $post->menu_order,
			'post_type'             => (string) $post->post_type,
			'post_mime_type'        => (string) $post->post_mime_type,
			'meta'                  => $this->postMeta( (int) $post->ID ),
			'terms'                 => $this->postTerms( (int) $post->ID ),
		);
	}

	/** @return array<string,list<mixed>> */
	private function postMeta( int $postId ): array {
		$all = get_post_meta( $postId );
		if ( ! is_array( $all ) ) {
			return array();
		}

		$meta = array();
		foreach ( $all as $key => $values ) {
			$key = (string) $key;
			if ( $this->isVolatileMetaKey( $key ) ) {
				continue;
			}
			$meta[ $key ] = array_map( fn ( $value ) => $this->decodeStoredValue( $value ), (array) $values );
		}
		ksort( $meta, SORT_STRING );
		return $meta;
	}

	/** @return list<array<string,mixed>> */
	private function postTerms( int $postId ): array {
		global $wpdb;

		// Direct taxonomy reads work even when the owning plugin is inactive.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				WHERE tr.object_id = %d
				ORDER BY tt.taxonomy ASC, t.term_id ASC",
				$postId
			),
			ARRAY_A
		);
		$this->throwOnDatabaseError( 'Could not read post terms.' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$terms = array();
		foreach ( (array) $rows as $row ) {
			$terms[] = array(
				'term_id'          => (int) $row['term_id'],
				'term_taxonomy_id' => (int) $row['term_taxonomy_id'],
				'taxonomy'         => (string) $row['taxonomy'],
				'name'             => (string) $row['name'],
				'slug'             => (string) $row['slug'],
				'description'      => (string) $row['description'],
				'parent'           => (int) $row['parent'],
			);
		}
		return $terms;
	}

	/** @return array<string,list<mixed>> */
	private function termMeta( int $termId ): array {
		if ( ! function_exists( 'get_term_meta' ) ) {
			return array();
		}
		$all = get_term_meta( $termId );
		if ( ! is_array( $all ) ) {
			return array();
		}

		$meta = array();
		foreach ( $all as $key => $values ) {
			$key = (string) $key;
			if ( $this->isVolatileMetaKey( $key ) ) {
				continue;
			}
			$meta[ $key ] = array_map( fn ( $value ) => $this->decodeStoredValue( $value ), (array) $values );
		}
		ksort( $meta, SORT_STRING );
		return $meta;
	}

	/** @return list<int> */
	private function menuItemIds( int $termTaxonomyId ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				WHERE tr.term_taxonomy_id = %d
				AND p.post_type = %s
				AND p.post_status <> %s
				ORDER BY p.menu_order ASC, p.ID ASC",
				$termTaxonomyId,
				'nav_menu_item',
				'trash'
			)
		);
		$this->throwOnDatabaseError( 'Could not read navigation menu items.' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_values( array_map( 'intval', (array) $ids ) );
	}

	/** @return list<string> */
	private function customizerOptionNames(): array {
		$this->resetForCurrentBlog();
		if ( $this->customizerOptionCache !== null ) {
			return $this->customizerOptionCache;
		}

		global $wpdb;
		$like = $wpdb->esc_like( 'theme_mods_' ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
				$like
			)
		);
		$this->throwOnDatabaseError( 'Could not read Customizer option names.' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		/**
		 * Adds exact (never wildcard) option names to the Customizer backup.
		 *
		 * @param list<string> $names Exact option names.
		 */
		$extra = apply_filters( 'wp2git_customizer_option_names', array() );
		$names = array_merge( (array) $found, is_array( $extra ) ? $extra : array() );
		$this->customizerOptionCache = $this->normalizeOptionNames( $names );
		return $this->customizerOptionCache;
	}

	/**
	 * @param array<string,mixed>|null $sidebars
	 * @return list<string>
	 */
	private function widgetOptionNames( ?array $sidebars = null ): array {
		$this->resetForCurrentBlog();
		if ( $sidebars === null && $this->widgetOptionCache !== null ) {
			return $this->widgetOptionCache;
		}

		if ( $sidebars === null ) {
			[ $hasSidebars, $stored ] = $this->readOption( 'sidebars_widgets' );
			$sidebars                = $hasSidebars && is_array( $stored ) ? $stored : array();
		}

		global $wp_registered_widgets;
		$names = array();
		$ids   = array();
		foreach ( $sidebars as $widgets ) {
			if ( ! is_array( $widgets ) ) {
				continue;
			}
			foreach ( $widgets as $widgetId ) {
				if ( is_string( $widgetId ) && $widgetId !== '' ) {
					$ids[] = $widgetId;
				}
			}
		}

		foreach ( (array) $wp_registered_widgets as $widgetId => $widget ) {
			$ids[]    = (string) $widgetId;
			$callback = is_array( $widget ) ? ( $widget['callback'] ?? null ) : null;
			$object   = is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) ? $callback[0] : null;
			if ( $object !== null && isset( $object->option_name ) && is_string( $object->option_name ) ) {
				$names[] = $object->option_name;
			}
		}

		foreach ( array_unique( $ids ) as $widgetId ) {
			$idBase = preg_replace( '/-\d+$/', '', (string) $widgetId );
			if ( is_string( $idBase ) && $idBase !== '' ) {
				$names[] = 'widget_' . $idBase;
			}
		}

		$names = $this->normalizeOptionNames( $names );
		/**
		 * Filters exact option names included in the widget backup.
		 *
		 * @param list<string>        $names    Exact widget option names.
		 * @param array<string,mixed> $sidebars Current sidebars_widgets value.
		 */
		$filtered = apply_filters( 'wp2git_widget_option_names', $names, $sidebars );
		$names    = $this->normalizeOptionNames( is_array( $filtered ) ? $filtered : $names );
		$this->widgetOptionCache = $names;
		return $names;
	}

	/** @return list<string> */
	private function detectPluginTemplatePostTypes(): array {
		global $wpdb;

		// Query raw post_type values so inactive-plugin records remain discoverable.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_type FROM {$wpdb->posts}
				WHERE post_status <> %s AND post_type <> %s
				ORDER BY post_type ASC",
				'trash',
				'revision'
			)
		);
		$this->throwOnDatabaseError( 'Could not detect plugin template post types.' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$types = self::KNOWN_PLUGIN_TEMPLATE_POST_TYPES;
		foreach ( (array) $found as $postType ) {
			$postType = (string) $postType;
			if ( preg_match( '/(?:template|layout|library)/i', $postType ) ) {
				$types[] = $postType;
			}
		}

		/**
		 * Filters the exact post types treated as plugin-owned templates.
		 *
		 * @param list<string> $types Detected and known template post types.
		 */
		$filtered = apply_filters( 'wp2git_plugin_template_post_types', $types );
		$types    = $this->normalizePostTypes( is_array( $filtered ) ? $filtered : $types );

		$reserved = array_merge(
			self::SITE_EDITOR_POST_TYPES,
			array( 'attachment', 'custom_css', 'nav_menu_item', 'page', 'post', 'revision', 'wpcode' )
		);
		return array_values( array_diff( $types, $reserved ) );
	}

	/** @return list<string> */
	private function wpcodePostTypes(): array {
		/**
		 * Filters the exact post types treated as WPCode snippet records.
		 *
		 * @param list<string> $types WPCode-compatible post types.
		 */
		$types = apply_filters( 'wp2git_wpcode_post_types', array( 'wpcode' ) );
		return is_array( $types ) ? $this->normalizePostTypes( $types ) : array( 'wpcode' );
	}

	/**
	 * Read the exact stored option without invoking pre_option/default filters.
	 *
	 * @return array{0:bool,1:mixed}
	 */
	private function readOption( string $name ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ),
			ARRAY_A
		);
		$this->throwOnDatabaseError( 'Could not read option ' . $name . '.' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
			return array( false, null );
		}
		return array( true, $this->decodeStoredValue( $row['option_value'] ) );
	}

	/**
	 * Decode WordPress's serialized arrays/scalars without instantiating classes.
	 * Snapshot generation must never run an object's unserialize hooks.
	 */
	private function decodeStoredValue( mixed $value ): mixed {
		if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
			return $value;
		}
		$serialized = trim( $value );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed stored data is retained below.
		$decoded = @unserialize( $serialized, array( 'allowed_classes' => false ) );
		if ( $decoded === false && $serialized !== 'b:0;' ) {
			return $value;
		}
		return $decoded;
	}

	/**
	 * @param array<string,string> $snapshots
	 * @param array<string,mixed>  $identity
	 */
	private function addSnapshot(
		array &$snapshots,
		string $group,
		string $relative,
		array $identity,
		mixed $data
	): void {
		$path = $this->currentSitePrefix() . $group . '/' . ltrim( $relative, '/' );
		if ( $this->parsePath( $path ) === null ) {
			throw new \RuntimeException( 'Unsafe database backup path: ' . $path );
		}
		$snapshots[ $path ] = $this->encodeSnapshot( $path, $group, $identity, $data );
	}

	/** @param array<string,mixed> $identity */
	private function encodeSnapshot( string $path, string $group, array $identity, mixed $data ): string {
		$envelope = array(
			'schema'   => 'wp2git-data/v1',
			'kind'     => $group,
			'identity' => $identity,
			'data'     => $data,
		);
		$envelope = $this->normalizeAndRedact( $envelope );

		/**
		 * Filters a complete database snapshot before canonical JSON encoding.
		 * Sensitive keys are redacted again after this filter.
		 *
		 * @param array<string,mixed> $envelope Snapshot envelope.
		 * @param string              $group    Database group.
		 * @param array<string,mixed> $identity Stable artifact identity.
		 * @param string              $path     Repository path.
		 */
		$filtered = apply_filters( 'wp2git_database_snapshot_value', $envelope, $group, $identity, $path );
		if ( is_array( $filtered ) ) {
			$envelope = $filtered;
		}
		$envelope['schema']   = 'wp2git-data/v1';
		$envelope['kind']     = $group;
		$envelope['identity'] = $identity;
		$envelope             = $this->canonicalize( $this->normalizeAndRedact( $envelope ) );

		$json = wp_json_encode(
			$envelope,
			JSON_PRETTY_PRINT
			| JSON_UNESCAPED_SLASHES
			| JSON_UNESCAPED_UNICODE
			| JSON_INVALID_UTF8_SUBSTITUTE
			| JSON_PRESERVE_ZERO_FRACTION
		);
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'Could not encode database backup snapshot: ' . $path );
		}
		return $json . "\n";
	}

	private function normalizeAndRedact( mixed $value, int $depth = 0 ): mixed {
		if ( $depth > 50 ) {
			return '[MAXIMUM DEPTH]';
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = is_string( $key ) && $this->isSensitiveKey( $key )
					? '[REDACTED]'
					: $this->normalizeAndRedact( $item, $depth + 1 );
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			return array(
				'__class'      => get_class( $value ),
				'__properties' => $this->normalizeAndRedact( get_object_vars( $value ), $depth + 1 ),
			);
		}

		if ( is_resource( $value ) ) {
			return '[RESOURCE]';
		}

		if ( is_float( $value ) && ! is_finite( $value ) ) {
			return (string) $value;
		}

		return $value;
	}

	private function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->canonicalize( $item );
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}

	private function isSensitiveKey( string $key ): bool {
		return (bool) preg_match(
			'/(?:secret|pass(?:word|wd)?|token|api[\s_-]*key|private[\s_-]*key|credential|licen[cs]e[\s_-]*key)/i',
			$key
		);
	}

	private function isVolatileMetaKey( string $key ): bool {
		if ( in_array( $key, self::VOLATILE_META_KEYS, true ) ) {
			return true;
		}
		return (bool) preg_match( '/(?:^|_)(?:cache|cached|transient)(?:_|$)/i', $key );
	}

	/** @param list<mixed> $types
	 *  @return list<string>
	 */
	private function normalizePostTypes( array $types ): array {
		$clean = array();
		foreach ( $types as $key => $value ) {
			$type = is_string( $key ) && is_bool( $value ) ? ( $value ? $key : '' ) : (string) $value;
			$type = sanitize_key( $type );
			if ( $type !== '' && $type !== 'revision' ) {
				$clean[ $type ] = true;
			}
		}
		$types = array_keys( $clean );
		sort( $types, SORT_STRING );
		return $types;
	}

	/** @param list<mixed> $names
	 *  @return list<string>
	 */
	private function normalizeOptionNames( array $names ): array {
		$clean = array();
		foreach ( $names as $key => $value ) {
			$name = is_string( $key ) && is_bool( $value ) ? ( $value ? $key : '' ) : trim( (string) $value );
			if ( $name !== ''
				&& strlen( $name ) <= 191
				&& ! str_contains( $name, "\0" )
				&& ! $this->isForbiddenOptionName( $name ) ) {
				$clean[ $name ] = true;
			}
		}
		$names = array_keys( $clean );
		sort( $names, SORT_STRING );
		return $names;
	}

	/** Options that no extension filter may add to a repository snapshot. */
	private function isForbiddenOptionName( string $name ): bool {
		foreach ( array( 'wp2git_', '_transient_', '_site_transient_', 'session_' ) as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}
		return in_array(
			$name,
			array( 'active_plugins', 'auth_key', 'cron', 'recently_activated', 'secret_key', 'user_roles' ),
			true
		);
	}

	private function pathToken( string $value ): string {
		return rawurlencode( $value === '' ? '_' : $value );
	}

	private function currentSitePrefix(): string {
		return self::PREFIX . get_current_blog_id() . '/';
	}

	/**
	 * @return array{site:int,group:string,relative:string}|null
	 */
	private function parsePath( string $repoPath ): ?array {
		$repoPath = ltrim( $repoPath, '/' );
		if ( $repoPath === ''
			|| str_contains( $repoPath, "\0" )
			|| str_contains( $repoPath, '\\' )
			|| str_contains( $repoPath, '//' ) ) {
			return null;
		}

		$groups  = implode( '|', array_map( 'preg_quote', self::GROUPS ) );
		$pattern = '#^' . preg_quote( self::PREFIX, '#' ) . '([1-9][0-9]*)/(' . $groups . ')/(.+\.json)$#';
		if ( ! preg_match( $pattern, $repoPath, $matches ) ) {
			return null;
		}

		$relative = $matches[3];
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( $segment === '' || $segment === '.' || $segment === '..' ) {
				return null;
			}
		}

		return array(
			'site'     => (int) $matches[1],
			'group'    => $matches[2],
			'relative' => $relative,
		);
	}

	private function resetForCurrentBlog(): void {
		$blogId = get_current_blog_id();
		if ( $this->cacheBlogId === $blogId ) {
			return;
		}

		$this->cacheBlogId             = $blogId;
		$this->snapshots               = array();
		$this->authoritative           = array();
		$this->postTypeCache           = array();
		$this->customizerOptionCache   = null;
		$this->widgetOptionCache       = null;
		$this->scanned                 = false;
	}

	private function throwOnDatabaseError( string $message ): void {
		global $wpdb;
		if ( is_string( $wpdb->last_error ) && $wpdb->last_error !== '' ) {
			throw new \RuntimeException( $message . ' ' . $wpdb->last_error );
		}
	}
}
