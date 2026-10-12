<?php
namespace phploader;

/**
 * CCache - Eine einfache Cache-Klasse für SQLite
 * Diese Klasse ermöglicht das Speichern und Abrufen von Cache-Daten in einer SQLite-Daten bank. 
 * Sie bietet Funktionen zum Setzen, Abrufen und Löschen von Cache-Einträgen.
 */
class CCache
{
	public $Param = [];
	public $DefaultTtl = 86400; // Standard-TTL von 24 Stunden in Sekunden
	private \SQLite3 $SQL;


	function __construct($P=null)
	{
		if($P['DB'] ?? false) {
			if(file_exists($P['DB']['FILENAME'])) {
				$this->SQL = new \SQLite3($P['DB']['FILENAME'], ($P['DB']['FLAGS']??SQLITE3_OPEN_READWRITE) );
			} else {
				$dirname = dirname($P['DB']['FILENAME']);
				if(!is_dir($dirname)) {
					mkdir($dirname, 0755, true);
				}
				$this->SQL = new \SQLite3($P['DB']['FILENAME']);
				$this->CreateDB();
			}
			if($P['PRAGMA']??false) {
				$this->SQL->exec($P['PRAGMA']);
			} else {
				$this->SQL->exec("
				PRAGMA busy_timeout = 5000;		PRAGMA cache_size = -8192;
				PRAGMA synchronous = OFF;		PRAGMA foreign_keys = OFF;
				PRAGMA temp_store = MEMORY;		PRAGMA journal_mode = wal;
				PRAGMA mmap_size = 52428800;
				");
			}
			$this->Param['DB'] = $P['DB'];
		} else { exit('kein DB-Übergabe Parameter!'); }
	}

	function __destruct() {
		if(isset($this->SQL)) {
			$this->SQL->close();
		}
	}

	private function CreateDB() {
		$this->SQL->exec('
			CREATE TABLE IF NOT EXISTS "wp_cache" (
			"id" text NOT NULL,
			"source" text NULL,
			"ttl" integer NOT NULL,
			"tag" text NULL,
			"data" blob NULL,
			"itimestamp" integer NULL DEFAULT (cast(strftime(\'%s\', \'now\') as int)),
			PRIMARY KEY ("id")
			);
			
			CREATE INDEX IF NOT EXISTS "wp_cache_tag" ON "wp_cache" ("tag");
			CREATE INDEX IF NOT EXISTS "wp_cache_ttl" ON "wp_cache" ("ttl");
			CREATE INDEX IF NOT EXISTS "wp_cache_itimestamp" ON "wp_cache" ("itimestamp");
		');
	}


	/**
     * Diese Funktion ruft den Cache ab.
	 * $P = optional array = [ 'Id', 'Tag' (string|array) (zusätzliche angabe von tag zum gruppieren von Caches) ]
	 */
	function get_cache($id) {
		$_id = (is_array($id))?implode("','",(array)$id):$id;
		$W = " id IN ('{$_id}')";
		#$now = time()-(24*60*60);
		$now = time();
		$qry = $this->SQL->query("SELECT id AS Id, source AS Source, ttl AS Ttl, tag AS Tag, data AS Data FROM  wp_cache WHERE {$W} AND ttl > {$now}" );
		$D = [];
		while ($a = $qry->fetchArray(SQLITE3_ASSOC)) {
			$D[$a['Id']] = $a;
		}
		#Erhöhe TTL um weitere 24h wenn diese in der letzten Stunde einmal abgerufen wurde. Dadurch wird häufig abgefragter Cache am leben gehalten.
		if ($D ?? false) { // UPDATE nur, wenn es Treffer gab
			$this->SQL->exec("UPDATE wp_cache SET ttl = {$now}+$this->DefaultTtl WHERE {$W} AND ttl > {$now} AND ttl < {$now}+60*60 ");
		}
	
		return $D;
	}

	/**
     * Diese Funktion speichert den Cache.
	 * $P[ID] = optional array = [ 'Tag', 'Source', 'Ttl']
	 */
	function set_cache($P) {
		$stmt = $this->SQL->prepare('REPLACE INTO wp_cache (id, ttl, source, tag, data) VALUES (:id, :ttl, :source, :tag, :data)');
		$this->SQL->exec('BEGIN');
		foreach((array)$P AS $kP => $vP) {
			$vP['Ttl'] = (isset($vP['Ttl']))? time() + (int)$vP['Ttl'] : time()+$this->DefaultTtl;
			$stmt->bindValue(':id', $kP);
			$stmt->bindValue(':ttl', $vP['Ttl'], \SQLITE3_INTEGER );
			$stmt->bindValue(':source', $vP['Source'] ?? null);
			$stmt->bindValue(':tag', $vP['Tag'] ?? null);
			$stmt->bindValue(':data', $vP['Data'] ?? null, SQLITE3_BLOB);
			if($stmt->execute() === false) {
				$this->SQL->exec('ROLLBACK'); # Alles oder nichts
				return false;
			}
		}
		$this->SQL->exec('COMMIT');
		return true;
	}

	/**
     * Diese Funktion löscht den Cache.
     * Optional kann ein Tag übergeben werden, um nur bestimmte Cache-Einträge zu löschen.
     * Wenn kein Tag übergeben wird, werden nur abgelaufene Cache-Einträge gelöscht.
	 * ($P['Tag'] = optional string = Tag zum löschen von Cache Einträgen, bei '%' werden alle Einträge gelöscht) und '_' als Platzhalter für einzelne Zeichen.
	 */
	function flush($P=null) {
		if(isset($P['Tag'])) {
			$stmt = $this->SQL->prepare('DELETE FROM wp_cache WHERE tag LIKE :tag');
			$stmt->bindValue(':tag', $P['Tag'] . '%', SQLITE3_TEXT);
			$stmt->execute();
		}
		$stmt = $this->SQL->prepare('DELETE FROM wp_cache WHERE ttl < :time');
		$stmt->bindValue(':time', time(), SQLITE3_INTEGER);
		$stmt->execute();
	}
}