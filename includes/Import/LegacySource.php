<?php

namespace MediaWiki\Extension\GlowThread\Import;

use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Read-only access to FlowThread's tables. Every method runs a SELECT: the importer never
 * writes to FlowThread.
 *
 * FlowThread names its tables FlowThread, FlowThreadAttitude and FlowThreadControl. Where table
 * names are case-sensitive (MySQL with lower_case_table_names=0, and PostgreSQL), they may exist
 * in that spelling or in lower case, for example after a dump from a MySQL server that folds
 * names to lower case. Each table is read under the spelling that exists, FlowThread's own first.
 */
class LegacySource {

	public const POST_TABLE = 'FlowThread';
	public const ATTITUDE_TABLE = 'FlowThreadAttitude';
	public const CONTROL_TABLE = 'FlowThreadControl';

	public const STATUS_NORMAL = 0;
	public const STATUS_DELETED = 1;
	public const STATUS_SPAM = 2;

	public const ATTITUDE_LIKE = 1;
	public const ATTITUDE_REPORT = 2;

	public const CONTROL_OPTED_OUT = 1;
	public const CONTROL_DISABLED = 2;

	private const CHUNK = 500;

	/** @var array<string,string> Table name as spelled above => the spelling that exists */
	private array $tables = [];

	public function __construct( private readonly IReadableDatabase $db ) {
	}

	/**
	 * The table's name as it exists in the database: FlowThread's spelling, or else its lower-case
	 * form when only that exists.
	 */
	private function table( string $name ): string {
		if ( !isset( $this->tables[$name] ) ) {
			$lower = strtolower( $name );
			$this->tables[$name] = !$this->db->tableExists( $name, __METHOD__ )
				&& $this->db->tableExists( $lower, __METHOD__ ) ? $lower : $name;
		}
		return $this->tables[$name];
	}

	/**
	 * @return string[] Names of FlowThread tables that do not exist
	 */
	public function missingTables(): array {
		$missing = [];
		foreach ( [ self::POST_TABLE, self::ATTITUDE_TABLE, self::CONTROL_TABLE ] as $table ) {
			if ( !$this->db->tableExists( $this->table( $table ), __METHOD__ ) ) {
				$missing[] = $table;
			}
		}
		return $missing;
	}

	/**
	 * Comments in ID order (creation order).
	 *
	 * @param int|null $pageId Only this page
	 * @return array[] Each with the keys id (raw bytes), page, userId, userName, text, parent (raw
	 *  bytes or null), status, like and report
	 */
	public function fetchPosts( ?int $pageId = null ): array {
		$query = $this->db->newSelectQueryBuilder()
			->select( [
				'flowthread_id', 'flowthread_pageid', 'flowthread_userid', 'flowthread_username',
				'flowthread_text', 'flowthread_parentid', 'flowthread_status', 'flowthread_like',
				'flowthread_report',
			] )
			->from( $this->table( self::POST_TABLE ) )
			->orderBy( 'flowthread_id' )
			->caller( __METHOD__ );
		if ( $pageId !== null ) {
			$query->where( [ 'flowthread_pageid' => $pageId ] );
		}
		$posts = [];
		foreach ( $query->fetchResultSet() as $row ) {
			$posts[] = [
				'id' => (string)$row->flowthread_id,
				'page' => (int)$row->flowthread_pageid,
				'userId' => (int)$row->flowthread_userid,
				'userName' => (string)$row->flowthread_username,
				'text' => (string)$row->flowthread_text,
				'parent' => $row->flowthread_parentid === null ? null : (string)$row->flowthread_parentid,
				'status' => (int)$row->flowthread_status,
				'like' => (int)$row->flowthread_like,
				'report' => (int)$row->flowthread_report,
			];
		}
		return $posts;
	}

	/**
	 * @param string[] $ids Raw comment IDs
	 * @return array<string,int> Raw ID => page ID, for those that exist
	 */
	public function fetchPagesOf( array $ids ): array {
		$pages = [];
		foreach ( array_chunk( array_values( array_unique( $ids ) ), self::CHUNK ) as $chunk ) {
			$res = $this->db->newSelectQueryBuilder()
				->select( [ 'flowthread_id', 'flowthread_pageid' ] )
				->from( $this->table( self::POST_TABLE ) )
				->where( [ 'flowthread_id' => $chunk ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$pages[(string)$row->flowthread_id] = (int)$row->flowthread_pageid;
			}
		}
		return $pages;
	}

	/**
	 * Likes and reports. The table has no key, so exact duplicates come back as they are stored.
	 *
	 * @param string[]|null $postIds Raw comment IDs, or null for all
	 * @return array<int,array{id:string,type:int,userId:int}>
	 */
	public function fetchAttitudes( ?array $postIds = null ): array {
		$fields = [ 'flowthread_att_id', 'flowthread_att_type', 'flowthread_att_userid' ];
		$results = [];
		if ( $postIds === null ) {
			$results[] = $this->db->newSelectQueryBuilder()
				->select( $fields )
				->from( $this->table( self::ATTITUDE_TABLE ) )
				->caller( __METHOD__ )
				->fetchResultSet();
		} else {
			foreach ( array_chunk( array_values( $postIds ), self::CHUNK ) as $chunk ) {
				$results[] = $this->db->newSelectQueryBuilder()
					->select( $fields )
					->from( $this->table( self::ATTITUDE_TABLE ) )
					->where( [ 'flowthread_att_id' => $chunk ] )
					->caller( __METHOD__ )
					->fetchResultSet();
			}
		}
		$attitudes = [];
		foreach ( $results as $res ) {
			foreach ( $res as $row ) {
				$attitudes[] = [
					'id' => (string)$row->flowthread_att_id,
					'type' => (int)$row->flowthread_att_type,
					'userId' => (int)$row->flowthread_att_userid,
				];
			}
		}
		return $attitudes;
	}

	/**
	 * @param int|null $pageId Only this page
	 * @return array<int,int> Page ID => control status (1 opted out, 2 disabled by an admin)
	 */
	public function fetchControls( ?int $pageId = null ): array {
		$query = $this->db->newSelectQueryBuilder()
			->select( [ 'flowthread_ctrl_pageid', 'flowthread_ctrl_status' ] )
			->from( $this->table( self::CONTROL_TABLE ) )
			->caller( __METHOD__ );
		if ( $pageId !== null ) {
			$query->where( [ 'flowthread_ctrl_pageid' => $pageId ] );
		}
		$controls = [];
		foreach ( $query->fetchResultSet() as $row ) {
			$controls[(int)$row->flowthread_ctrl_pageid] = (int)$row->flowthread_ctrl_status;
		}
		return $controls;
	}
}
