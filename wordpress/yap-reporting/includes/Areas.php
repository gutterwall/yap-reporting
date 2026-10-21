<?php
namespace YAPR;

defined( 'ABSPATH' ) || exit;

/** Service bodies (BMLT ids are the identifiers everywhere; names are display only). */
final class Areas {

	/** @var array<int, array>|null */
	private static ?array $cache = null;

	public static function flush(): void {
		self::$cache = null;
	}

	/** @return array<int, array{id: int, parent_id: int, name: string, type: string, managed: bool, present_in_bmlt: bool}> */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		global $wpdb;
		$table = Installer::table( 'areas' );
		$rows  = $wpdb->get_results( "SELECT id, parent_id, name, type, managed, present_in_bmlt FROM {$table} ORDER BY name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$id         = (int) $r['id'];
			$out[ $id ] = array(
				'id'              => $id,
				'parent_id'       => (int) $r['parent_id'],
				'name'            => (string) $r['name'],
				'type'            => (string) $r['type'],
				'managed'         => (bool) $r['managed'],
				'present_in_bmlt' => (bool) $r['present_in_bmlt'],
			);
		}
		return self::$cache = $out;
	}

	public static function exists( int $id ): bool {
		return isset( self::all()[ $id ] );
	}

	/** @return int[] */
	public static function managed_ids(): array {
		return array_values( array_map( static fn( $a ) => $a['id'], array_filter( self::all(), static fn( $a ) => $a['managed'] ) ) );
	}

	public static function is_managed( int $id ): bool {
		return in_array( $id, self::managed_ids(), true );
	}

	public static function name( ?int $id ): string {
		if ( null === $id || $id <= 0 ) {
			return __( 'Unassigned', 'yap-reporting' );
		}
		$labels = (array) Settings::get( 'area_labels' );
		if ( ! empty( $labels[ $id ] ) ) {
			return (string) $labels[ $id ];
		}
		$all = self::all();
		/* translators: %d: BMLT service body id */
		return isset( $all[ $id ] ) && '' !== $all[ $id ]['name'] ? $all[ $id ]['name'] : sprintf( __( 'Service body %d', 'yap-reporting' ), $id );
	}

	/** Every descendant id (any depth). @return int[] */
	public static function descendants( int $id ): array {
		$children = array();
		foreach ( self::all() as $a ) {
			$children[ $a['parent_id'] ][] = $a['id'];
		}
		$out   = array();
		$stack = $children[ $id ] ?? array();
		$guard = 0;
		while ( $stack && $guard++ < 10000 ) {
			$next = array_pop( $stack );
			if ( in_array( $next, $out, true ) ) {
				continue;
			}
			$out[] = $next;
			foreach ( $children[ $next ] ?? array() as $c ) {
				$stack[] = $c;
			}
		}
		return $out;
	}

	/** A region expands to itself plus managed descendants. @return int[] */
	public static function expand_region( int $id ): array {
		$ids = array_values( array_intersect( array_merge( array( $id ), self::descendants( $id ) ), self::managed_ids() ) );
		sort( $ids );
		return $ids;
	}

	/** @param array<int, array{id: int, parent_id: int, name: string, type: string}> $bodies */
	public static function replace_from_bmlt( array $bodies ): void {
		global $wpdb;
		$table   = Installer::table( 'areas' );
		$now     = current_time( 'mysql', true );
		$managed = self::managed_ids();
		$seen    = array();
		foreach ( $bodies as $b ) {
			$seen[] = $b['id'];
			$wpdb->replace(
				$table,
				array(
					'id'              => $b['id'],
					'parent_id'       => $b['parent_id'],
					'name'            => $b['name'],
					'type'            => $b['type'],
					'managed'         => in_array( $b['id'], $managed, true ) ? 1 : 0,
					'present_in_bmlt' => 1,
					'updated_at'      => $now,
				),
				array( '%d', '%d', '%s', '%s', '%d', '%d', '%s' )
			);
		}
		// Keep vanished service bodies (historical data may reference them) but flag them.
		if ( $seen ) {
			$placeholders = implode( ',', array_fill( 0, count( $seen ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET present_in_bmlt = 0 WHERE id NOT IN ({$placeholders})", $seen ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- one %d per id.
		}
		self::flush();
		update_option( 'yapr_bmlt_synced_at', time(), false );
	}

	/** @param int[] $ids */
	public static function set_managed( array $ids ): void {
		global $wpdb;
		$table = Installer::table( 'areas' );
		$ids   = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$wpdb->query( "UPDATE {$table} SET managed = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET managed = 1 WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- one %d per id.
		}
		self::flush();
	}

	/** Nested tree for the selection UI. @return array<int, array> */
	public static function tree(): array {
		$all      = self::all();
		$children = array();
		foreach ( $all as $a ) {
			$parent              = isset( $all[ $a['parent_id'] ] ) ? $a['parent_id'] : 0;
			$children[ $parent ][] = $a;
		}
		$build = static function ( int $parent, int $depth ) use ( &$build, $children ): array {
			$out = array();
			foreach ( $children[ $parent ] ?? array() as $a ) {
				if ( $depth > 20 ) {
					break;
				}
				$a['depth']    = $depth;
				$a['children'] = $build( $a['id'], $depth + 1 );
				$out[]         = $a;
			}
			return $out;
		};
		return $build( 0, 0 );
	}

	/** Display-name map for a list of ids (used in JS). @param int[] $ids */
	public static function names( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$out[ (string) $id ] = self::name( (int) $id );
		}
		return $out;
	}
}
