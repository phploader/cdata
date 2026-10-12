<?php
namespace phploader;

/** DOC
@version 2.16
@license https://opensource.org/license/lgpl-3-0 GNU Public License
 
@todo
BUG: Order in der zweiten Ebene z.B: $F[AAA][BBB][O][Feld] = 'DESC';  funktioniert nicht!! Außerdem soll ein [index] hinzugefügt werden.$F[AAA][BBB][O][>>>0<<<][Feld]
+ Leichen identifizieren und löschen. Alle Elemente die auf ein Vater verweisen dieser bereits gelöscht wurde. Eventuell eine Funktion clear dafür erstellen. Eventull auch get_clear um vorher die Leichen Elemente auszugeben befor diese gelöscht werden, zur sichtung.
+ Azeptieren von Leeren String als Attribut zu speichern. Momentan wird Null und ''-Leer gleich behandelt und Attribut aus der Datenbank entfernt. Stelle Suche: ($ATT !== NULL && $ATT != '')
+ Enum: Hinzufügen des Enum Variable, diese den übergebenen Wert abgleicht. Wird ein Wert dieses nicht in Enum hinterlegt ist übergeben, so wird der Wert durch Null ersetzt als ob nichts übergeben wurde.
+ Allowed: soll beim Pattern kenzeichnen welche Werte erlaubt sind. auch ''-Leerer String soll wenn unerwünscht dadurch verhindert werden.
+ Allowed Expression: regulären Ausdruck diese den Inhalt nach erlaubten Inhalt überprüft.
+ Required: ??? ggf. akzeptans, z.B. null or not null?
+ "IN SELECT", "NOT IN SELECT" : Where Bedinung nach SELECT aus anderen Tabellen. Z.b: ATTRIBUTE.W.0.ID['IN SELECT'].ID.ATTRIBUTE.ATTRIBUTE_GROUP.ATTRIBUTE_SET.W.0.Active = 1 Es sollen Filter je Ebene gesetzt werden können, der aufbau nach SELECT erfolgt Rückwerts
*/

class CData
{
	private $SQL;
	private $CCache;
	private $Param;
	private $BackupDestinationPath;
	private $BackupPassword;



	/**
	 * 'PATTERN'	=> Pattern
	 * 'DB'			=> [FILENAME,FLAGS,FILENAME_C] Datenbank Zugang
	 * 'BACKUP'		=> [DestinationPath, BackupPassword] (optional)
	*/
	function __construct($P=null)
	{
		if($P['DB']) {
			if(file_exists($P['DB']['FILENAME'])) {
				$this->SQL = new \SQLite3($P['DB']['FILENAME'], ($P['DB']['FLAGS']??SQLITE3_OPEN_READWRITE) );
			} else {
				$path= pathinfo($P['DB']['FILENAME']);
				if(!is_dir($path['dirname'])) {
					mkdir($path['dirname'], 0777, true);
				}
				$this->SQL = new \SQLite3($P['DB']['FILENAME']);
				$this->CreateDB();
			}

			$_File_Cache = $P['DB']['FILENAME_C']??$P['DB']['FILENAME'];
			$this->CCache = new \phploader\CCache([ 'DB' => ['FILENAME' => $_File_Cache.'.cache' ] ]);

			if($P['PRAGMA']??false) {
				$this->SQL->exec($P['PRAGMA']);
			} else {
				$this->SQL->exec("
				PRAGMA busy_timeout = 5000;		PRAGMA cache_size = -8192;
				PRAGMA synchronous = 1;			PRAGMA foreign_keys = OFF;
				PRAGMA temp_store = MEMORY;		
				PRAGMA journal_mode = wal;
				PRAGMA wal_autocheckpoint=1000; PRAGMA encoding = 'UTF-8';
				");# PRAGMA journal_mode = normal;
			}
			
			$this->BackupDestinationPath = ($P['BACKUP']['DestinationPath'])??'backup/';
			$this->BackupPassword = $P['BACKUP']['BackupPassword']??null;
			$this->PATTERN = $P['PATTERN']??null;
			$this->Param['DB'] = $P['DB'];

			#Prüfe ob wp_data_cache Tabelle leer ist, fals ja, dann befülle wenn wp_data Tabelle nicht leer ist
			$querySingle = $this->SQL->querySingle("SELECT 1 FROM wp_data_cache LIMIT 1" );
			if(!isset($querySingle)) {
				$querySingle = $this->SQL->querySingle("SELECT 1 FROM wp_data LIMIT 1" );
				if( isset($querySingle) ) {
					$this->CCache->flush(['Tag' => '%']);#Lösche den L2-Cache
					$this->repair(); #Erstelle L1-Cache
				}
			}

		} else { exit('kein DB-Übergabe Parameter!'); }
	}

	function __destruct() {
		if(isset($this->SQL)) {
			$this->SQL->close();
		}
	}
	
	/**
	 * Registriert zusätzliche Pattern-Definitionen.
	 *
	 * @param array $patterns
	 * @return void
	 */
	public function registerPattern(array $patterns): void {
		$this->PATTERN = array_replace_recursive((array)$this->PATTERN,(array)$patterns);
	}

	/**
	 * Entfernt registrierte Pattern-Definitionen.
	 *
	 * @param string|array $keys
	 * @return void
	 */
	public function unregisterPattern(string|array $keys): void {
		if (!is_array($keys)) {
			$keys = [$keys];
		}

		foreach ($keys as $key) {
			if (isset($this->PATTERN[$key])) {
				unset($this->PATTERN[$key]);
			}
		}
	}

	
	private function CreateDB() {
		$this->SQL->exec('
			CREATE TABLE IF NOT EXISTS "wp_data" (
			"id" text NOT NULL,
			"type_id" text NOT NULL,
			"parent_path_hash" text NOT NULL,
			"parent_type_id" text NOT NULL,
			"parent_data_id" text NOT NULL,
			"utimestamp" integer NULL DEFAULT (cast(strftime(\'%s\', \'now\') as int)),
			"itimestamp" integer NULL DEFAULT (cast(strftime(\'%s\', \'now\') as int)),
			PRIMARY KEY ("id", "type_id", "parent_path_hash")
			);

			CREATE INDEX IF NOT EXISTS "wp_data_parent_type_id_parent_data_id" ON "wp_data" ("parent_type_id", "parent_data_id");
			CREATE INDEX IF NOT EXISTS "wp_data_id" ON "wp_data" ("id");
			CREATE INDEX IF NOT EXISTS "wp_data_type_id" ON "wp_data" ("type_id");
			CREATE INDEX IF NOT EXISTS "wp_data_utimestamp" ON "wp_data" ("utimestamp");
			CREATE INDEX IF NOT EXISTS "wp_data_parent_type_id" ON "wp_data" ("parent_type_id");
			CREATE INDEX IF NOT EXISTS "wp_data_id_type_id" ON "wp_data" ("id", "type_id");
			CREATE INDEX IF NOT EXISTS "wp_data_id_parent_path_hash" ON "wp_data" ("id", "parent_path_hash");
			CREATE INDEX IF NOT EXISTS "wp_data_parent_path_hash" ON "wp_data" ( "parent_path_hash");

			CREATE TABLE IF NOT EXISTS "wp_data_att" (
			"id" text COLLATE \'BINARY\' NOT NULL,
			"type_id" text NOT NULL,
			"parent_path_hash" text NOT NULL,
			"attribute_id" text NOT NULL,
			"value" text NULL,
			"sort" numeric NULL,
			"utimestamp" integer NULL DEFAULT (cast(strftime(\'%s\', \'now\') as int)),
			"itimestamp" integer NULL DEFAULT (cast(strftime(\'%s\', \'now\') as int)),
			PRIMARY KEY ("id", "type_id", "parent_path_hash", "attribute_id")
			);

			CREATE INDEX IF NOT EXISTS "wp_data_att_value_hash" ON "wp_data_att" ("sort");
			CREATE INDEX IF NOT EXISTS "wp_data_att_id_type_id_attribute_id" ON "wp_data_att" ("id", "type_id", "attribute_id");
			CREATE INDEX IF NOT EXISTS "wp_data_att_id_type_id" ON "wp_data_att" ("id", "type_id");
			CREATE INDEX IF NOT EXISTS "wp_data_att_attribute_id" ON "wp_data_att" ("attribute_id");
			CREATE INDEX IF NOT EXISTS "wp_data_att_type_id" ON "wp_data_att" ("type_id");
			CREATE INDEX IF NOT EXISTS "wp_data_att_id" ON "wp_data_att" ("id");
			CREATE INDEX IF NOT EXISTS "wp_data_att_utimestamp" ON "wp_data_att" ("utimestamp");
			CREATE INDEX IF NOT EXISTS "wp_data_att_parent_path_hash" ON "wp_data_att" ("parent_path_hash");

			CREATE TABLE IF NOT EXISTS "wp_data_cache" (
			"id" text NOT NULL,
			"type_id" text NOT NULL,
			"parent_path_hash" text NOT NULL,
			"path_hash" text NOT NULL,
			"data" blob NULL,
			"utimestamp" integer NULL DEFAULT (cast(strftime(\'%s\', \'now\') as int)),
			"itimestamp" integer NULL DEFAULT (cast(strftime(\'%s\', \'now\') as int)),
			PRIMARY KEY ("id", "type_id", "parent_path_hash")
			);
			CREATE INDEX IF NOT EXISTS "wp_data_tmp_id" ON "wp_data_cache" ("id");
			CREATE INDEX IF NOT EXISTS "wp_data_tmp_type_id" ON "wp_data_cache" ("type_id");
			CREATE INDEX IF NOT EXISTS "wp_data_tmp_utimestamp" ON "wp_data_cache" ("utimestamp");
			CREATE INDEX IF NOT EXISTS "wp_data_tmp_parent_path_hash" ON "wp_data_cache" ("parent_path_hash");
			CREATE INDEX IF NOT EXISTS "wp_data_tmp_path_hash" ON "wp_data_cache" ("path_hash");
		'); 
	}
	
	/**
	 * Gibt Pattern aus
	 * @param mixed $D
	 * @return void
	 */
	function get_Pattern(&$D) {
		$D['PATTERN'] = $this->PATTERN;
	}

	/**
	 * Pattern setzen
	 * @param mixed $D['PATTERN']
	 * @return void
	 */
	function set_Pattern(&$D) {
		$this->PATTERN = $D['PATTERN'];
	}

	/**
	 * Diese Funktion erstellt ein Backup der Datenbank.
	 * Optional kann ein Zielverzeichnis und ein Passwort für die Sicherung angegeben werden.
	 * BackupDestinationPath und BackupPassword können beim Konstruktor übergeben werden.
	 */
	function backup() {
		if($this->Param['DB']) {
			#Erstelle Unter Verzeichnis
			if(!is_dir($this->BackupDestinationPath)) {
				mkdir($this->BackupDestinationPath,0755,true);
			}

			$datetime = date("YmdHis");
			#Backup der DB
			$path = pathinfo($this->Param['DB']['FILENAME']);
			$backup = new \SQLite3("{$this->BackupDestinationPath}{$datetime}_{$path['basename']}");
			$this->SQL->backup($backup);
			#Todo: Um mehr Speicher zu sparren kann wp_data_cache-Tabelle geleert werden
			$backup->exec("VACUUM");
			$backup->close();

			#Ziped
			$zip = new \ZipArchive();
			if( $zip->open("{$this->BackupDestinationPath}{$datetime}_{$path['basename']}.zip", \ZipArchive::CREATE) ) {
				$zip->addFile("{$this->BackupDestinationPath}{$datetime}_{$path['basename']}","{$datetime}_{$path['basename']}");
				if($this->BackupPassword) {
					$zip->setEncryptionName("{$datetime}_{$path['basename']}", \ZipArchive::EM_AES_256, $this->BackupPassword);
				}
				$zip->close();

				#Lösche Sicherungskopie
				unlink("{$this->BackupDestinationPath}{$datetime}_{$path['basename']}");
			}
		}
	}


	/**
	 * Erzeugt ein float Wert aus einem Text Folge und kann für Sortierung in der Datenbank verwendet werden
	 */
	private function _Value2SortHash($s) {
		$pre = (int)($s);#Wen Zahlen vor stehen, dann wird nach Zahlen Größe sortiert. So dass 1 kleiner ist als 10
		$s = strtolower($s);
		$ar = unpack("C*", $s);
		$stLen = (count($ar) < 6)?count($ar):5;#Maximal die ersten 5 Zeichen
		$hash='';
		for($i = 0; $i < $stLen; $i++){
			$hash .= str_pad($ar[$i+1],3,0,STR_PAD_LEFT);
		}
		return (float)"{$pre}.{$hash}";
	}

	function set_object(&$D = null, $Parent_Hash='', $Parent_Type = '', $Parent_Id = '' ) {
		static $stLevel = 0;
		
		static $IU_DATA = '';
		static $cD_DATA = [];
		static $IU_DATA_ATT  = '';
		static $D_DATA_ATT  = '';
		static $_RefrechCache = [];
		
		$savePatern = $this->PATTERN;
		foreach ((array) $D AS $kType => $Type) { ##[PlATFORM]
			
			if (($this->PATTERN[$kType]??false) && is_array($Type)) { #Prüfe in Pattern ob diese erlaubt sind
				
				$this->CCache->flush(['Tag' => "{$kType}/"]); #Cache bereinigen #ToDo: prüfen ob in der ebene veräderungen vorgenohmen wurden und nicht einfach pauschal leeren.
				
				foreach ((array) $Type['D'] AS $kSup => $Sup) { #PlATFORM.D[x]
					
					$_RefrechCache[$Parent_Hash] = true; #aktuallisiere anhand des parent_path_hash Todo: Genauer !
					
					if( !is_null($Sup) && $Sup != '__DELETE__' && ($Sup['Active']??false) != -2 ) { #Insert/ Update ToDo: depricated: Löschen per Active=-2, muss entfernt werden!
						$Child_Hash = hash("crc32b", $Parent_Hash.$kType.$kSup);

						$IU_DATA .= (($IU_DATA) ? ',' : '') . "('{$kSup}','{$kType}','{$Parent_Hash}','{$Parent_Type}','{$Parent_Id}')";
						
						$d = [];
						foreach ((array) $Sup as $kATT => $ATT) { #PlATFORM.D.x.[ATTRIBUTE]
							
								if (is_array($ATT) && isset($ATT['D'])) { #ist ein ATT eine weitere Ebene? PlATFORM.D.x.ATTRIBUTE.D.[x]
									$d[$kATT]['D'] = &$ATT['D'];
									##$d[$kATT]['D'][$kSubATT][$kType] = $kSup; 
									
									$stLevel++;
									$this->PATTERN = $savePatern[$kType]['D']??=[];
									
									$this->set_object($d,$Child_Hash,$kType,$kSup);
									#$this->set_object($d,$Parent_Hash,$kType,$kSup);
									$this->PATTERN = $savePatern; #Setze Pattern auf Ursprung zurück
									$stLevel--;
								}
								elseif(($this->PATTERN[$kType][$kATT]??false) && !is_array($ATT)) {
									if(($ATT !== NULL && $ATT != '' && $ATT != '__DELETE__') || ($ATT !== NULL && isset($this->PATTERN[$kType][$kATT]['ForeignKey']) && $this->PATTERN[$kType][$kATT]['ForeignKey'] == 1) ) {
										$IU_DATA_ATT .= (($IU_DATA_ATT) ? ',' : '') . "('{$kSup}','{$kType}','{$Parent_Hash}','{$kATT}'";#Setze Attribute
										
										$_sort = $this->_Value2SortHash($ATT); 
										$IU_DATA_ATT .= (isset($ATT)) ? ",".(is_float($_sort) ? $_sort : "'".$_sort."'") : ",NULL";
										$IU_DATA_ATT .= (isset($ATT)) ? ",'".\SQLite3::escapeString($ATT)."'" : ",NULL";
										$IU_DATA_ATT .= ")";
									}
									else {
										$D_DATA_ATT .= (($D_DATA_ATT) ? ' OR ' : '') ." (id = '{$kSup}' AND type_id = '{$kType}' AND attribute_id = '{$kATT}' AND parent_path_hash = '{$Parent_Hash}' )";
									}
									
								}
						}
					}
					else { #Delete
						$cD_DATA[] = [$Parent_Hash, $kType, $kSup];
					}
				}
			}
			
			
		}
		
		if($stLevel == 0) {
			#1. Speichere in der DB
			#echo $IU_DATA;echo "<br>";
			#echo $IU_DATA_ATT;
			if ($IU_DATA??false) {
				$this->SQL->exec("INSERT INTO wp_data (id, type_id, parent_path_hash, parent_type_id, parent_data_id) VALUES {$IU_DATA} 
							ON CONFLICT(id, type_id, parent_path_hash) DO UPDATE SET
								parent_data_id =			CASE WHEN excluded.parent_data_id IS NOT NULL	AND ifnull(parent_data_id,'') <> excluded.parent_data_id		THEN excluded.parent_data_id ELSE parent_data_id END,
								parent_type_id =			CASE WHEN excluded.parent_type_id IS NOT NULL	AND ifnull(parent_type_id,'') <> excluded.parent_type_id		THEN excluded.parent_type_id ELSE parent_type_id END,
								utimestamp =	CASE WHEN 
													excluded.parent_type_id IS NOT NULL	AND ifnull(parent_type_id,'') <> excluded.parent_type_id
												THEN cast(strftime('%s', 'now') as int) ELSE utimestamp END
							");
			}
			if ($IU_DATA_ATT??false) {
				$this->SQL->query("INSERT INTO wp_data_att (id, type_id,parent_path_hash, attribute_id, sort, value ) VALUES {$IU_DATA_ATT} 
							ON CONFLICT(id,  type_id, parent_path_hash, attribute_id) DO UPDATE SET
								value =			CASE WHEN excluded.value IS NOT NULL	AND ifnull(value,'') <> excluded.value		THEN excluded.value ELSE value END,
								sort =			CASE WHEN excluded.sort IS NOT NULL	AND ifnull(sort,'') <> excluded.sort		THEN excluded.sort ELSE sort END,
								utimestamp =	CASE WHEN 
													excluded.value IS NOT NULL	AND ifnull(value,'') <> excluded.value
												THEN cast(strftime('%s', 'now') as int) ELSE utimestamp END
							");
			}
			#2. Lösche in der DB
			if($cD_DATA??false) {
				$this->_delete_object($cD_DATA); #Lösche reqursiv
			}
			if($D_DATA_ATT) { #Lösche Attribut wenn es Null gekenzeichnet wurde
				$this->SQL->query("DELETE FROM wp_data_att WHERE {$D_DATA_ATT}");
			}
			#3. invalidiere Cache
			$this->_set_cache($_RefrechCache);
			# 🔥 HIER: statische Variablen zurücksetzen 
			$IU_DATA = ''; $cD_DATA = []; $IU_DATA_ATT = ''; $D_DATA_ATT = ''; $_RefrechCache = [];
		}
	}
	
	/**
	 * Löscht reqursiv Baum Objekt
	 * @param mixed $C[ 0=> [parent_hash, type_id, id ] ]
	 */
	private function _delete_object($C, $level=0) {
		static $D_DATA = [];
		$D_DATA[$level] = '';
		foreach( $C AS $k => $v) {
			$Child_Hash = hash("crc32b", $v[0].$v[1].$v[2]);
			$qry = $this->SQL->query("SELECT parent_path_hash, type_id, id FROM wp_data WHERE parent_path_hash = '{$Child_Hash}' AND parent_type_id = '{$v[1]}' AND parent_data_id = '{$v[2]}'");
			while ($a = $qry->fetchArray(SQLITE3_ASSOC)) {
				#$parent_Hash = hash("crc32b", $a['parent_path_hash'].$a['type_id'].$a['id']);
				$c[] = [ $a['parent_path_hash'], $a['type_id'], $a['id'] ];
			}
			if($c??false) {
				$this->_delete_object($c, $level+1);
				unset($c);
			}
			$D_DATA[$level] .= (($D_DATA[$level]) ? ' , ' : '') . "('{$v[0]}' || '{$v[1]}' || '{$v[2]}')";
		}
		if($level == 0) {
			for($i=count($D_DATA);$i >= 0; $i--) { #Löscht von oberen Ebene abwärts die Daten
				if(isset($D_DATA[$i])) {
					$this->SQL->query("DELETE FROM wp_data_att WHERE (parent_path_hash || type_id || id) IN ({$D_DATA[$i]})"); #Lösche Attribute
					$this->SQL->query("DELETE FROM wp_data_cache WHERE (parent_path_hash || type_id || id) IN ({$D_DATA[$i]})"); #Lösche Cache
					$this->SQL->query("DELETE FROM wp_data WHERE (parent_path_hash || type_id || id) IN ({$D_DATA[$i]})"); #Lösche Datensatz
				}
			}
		}
	}

	/**
	 * repariert die wp_data_cache bzw. stellt wieder her.
	 */
	function repair() {

			#2. Selektiere Datensätze anhand des PathHash
			$qry = $this->SQL->query("SELECT d.id, d.type_id, d.parent_path_hash, attribute_id, value
						FROM wp_data d LEFT JOIN wp_data_att dat ON d.id = dat.id AND d.type_id = dat.type_id AND d.parent_path_hash = dat.parent_path_hash
						WHERE 1
			");
			while ($a = $qry->fetchArray(SQLITE3_ASSOC)) {
				$set_d[$a['parent_path_hash']][$a['type_id']]['D'][$a['id']][$a['attribute_id']] = $a['value'];
			}
			
			#3. Speichere neue Datensätze im Cache ab
			$IU_DATA_ATT = '';
			foreach ((array) $set_d as $kPath => $Path) {
				foreach ((array) $Path as $kType => $Type) {
					foreach ((array) $Type['D'] as $kSup => $Sup) {
						
						$IU_DATA_ATT .= (($IU_DATA_ATT) ? ',' : '') . "('{$kSup}','{$kType}','{$kPath}'";
						
						$json = $this->SQL->escapeString(json_encode($Sup));
						$ChildHash = hash("crc32b", $kPath.$kType.$kSup);
						$IU_DATA_ATT .= ",'{$ChildHash}'";
						$IU_DATA_ATT .= ",'" . str_replace([',"":""','"":"",','"":""','"":null'],'',$json) . "'"; #replace entfernt leere Key Werte
						
						$IU_DATA_ATT .= ")";

						if( $_i > 10000 ) {
							$this->SQL->query("REPLACE INTO wp_data_cache (id, type_id, parent_path_hash,path_hash,data) VALUES {$IU_DATA_ATT} 
									ON CONFLICT(id, type_id, parent_path_hash) DO UPDATE SET
										data =			CASE WHEN excluded.data IS NOT NULL	AND ifnull(data,'') <> excluded.data		THEN excluded.data ELSE data END,
										utimestamp =	CASE WHEN excluded.data IS NOT NULL	AND ifnull(data,'') <> excluded.data
														THEN cast(strftime('%s', 'now') as int) ELSE utimestamp END
									");
							unset($IU_DATA_ATT);
							$_i=0;
						}
						$_i++;
					}
				}
			}
			
			if ($IU_DATA_ATT) {
				$this->SQL->query("REPLACE INTO wp_data_cache (id, type_id, parent_path_hash,path_hash,data) VALUES {$IU_DATA_ATT} 
									ON CONFLICT(id, type_id, parent_path_hash) DO UPDATE SET
										data =			CASE WHEN excluded.data IS NOT NULL	AND ifnull(data,'') <> excluded.data		THEN excluded.data ELSE data END,
										utimestamp =	CASE WHEN excluded.data IS NOT NULL	AND ifnull(data,'') <> excluded.data
														THEN cast(strftime('%s', 'now') as int) ELSE utimestamp END
									");
									
			}
	}
	private function _set_cache(&$RefrechCache_PathHash=null) {
		
		if($RefrechCache_PathHash) {
			
			#1. Lösche alte Datensätze anhand des PathHash
			$Keys = implode("','",array_keys($RefrechCache_PathHash));
			###$this->SQL->query("DELETE FROM wp_data_cache WHERE parent_path_hash IN ('{$Keys}')");#ToDo: weil mit _delete_object auch Cache geleert wird, muss hier nicht mehr wp_data_cache Tabelle vorher geleert werden. Es reicht nur aktuallisieren oder neu hinzufügen. Muss überarbeitet werden
			
			#2. Selektiere Datensätze anhand des PathHash
			$qry = $this->SQL->query("SELECT d.id, d.type_id, d.parent_path_hash, attribute_id, value
						FROM wp_data d LEFT JOIN wp_data_att dat ON d.id = dat.id AND d.type_id = dat.type_id AND d.parent_path_hash = dat.parent_path_hash
						WHERE d.parent_path_hash IN ('{$Keys}')
			");
			while ($a = $qry->fetchArray(SQLITE3_ASSOC)) {
				$set_d[$a['parent_path_hash']][$a['type_id']]['D'][$a['id']][$a['attribute_id']] = $a['value'];
			}
			
			#3. Speichere neue Datensätze im Cache ab
			$IU_DATA_ATT = '';
			foreach ((array) $set_d as $kPath => $Path) {
				foreach ((array) $Path as $kType => $Type) {
					foreach ((array) $Type['D'] as $kSup => $Sup) {
						
						$IU_DATA_ATT .= (($IU_DATA_ATT) ? ',' : '') . "('{$kSup}','{$kType}','{$kPath}'";
						
						$json = $this->SQL->escapeString(json_encode($Sup));
						$ChildHash = hash("crc32b", $kPath.$kType.$kSup);
						$IU_DATA_ATT .= ",'{$ChildHash}'";
						$IU_DATA_ATT .= ",'" . str_replace([',"":""','"":"",','"":""','"":null'],'',$json) . "'"; #replace entfernt leere Key Werte
						
						$IU_DATA_ATT .= ")";

					}
				}
			}
			
			if ($IU_DATA_ATT) {
				$this->SQL->query("REPLACE INTO wp_data_cache (id, type_id, parent_path_hash,path_hash,data) VALUES {$IU_DATA_ATT} 
									ON CONFLICT(id, type_id, parent_path_hash) DO UPDATE SET
										data =			CASE WHEN excluded.data IS NOT NULL	AND ifnull(data,'') <> excluded.data		THEN excluded.data ELSE data END,
										utimestamp =	CASE WHEN excluded.data IS NOT NULL	AND ifnull(data,'') <> excluded.data
														THEN cast(strftime('%s', 'now') as int) ELSE utimestamp END
									");
									
			}
			
		}
		
	}

	/**
	 * $F = Filer übergabe 
	 * $Pattern = Pattern übergabe
	 * $Level = nur 0 oder garnichts übergeben, wird intern verwendet
	 */
	private function _get_where(&$F,&$Pattern,$Level=0) {
		if($F['W']??false) { #Prüft ob es sich um eine Where anweisung sich handelt
			$W = ' AND ( ';
			$WOR = '';
			foreach ((array) $F['W'] as $kOR => $OR) { #OR Bedinungen durchlaufen
				$WOR .= ($WOR)? ' OR ( ' : '';
				$WAND = '';
				foreach( (array) $OR AS $kAND => $AND ) { #AND Bedinungen durchlaufen
					if($kAND == 'ID') {
						$Value = $this->_get_where_Operations($kAND,$AND,$Level);
						$WAND .= (($WAND)? ' AND ' : ' ')." {$Value} ";
					}
					elseif( in_array($kAND,array_keys( (array)($Pattern??[])  ) ) ) { #Prüfe ob das Attribut auch im Patern enthalten ist. z.B: Active
						$Value = $this->_get_where_Operations($kAND, $AND,$Level);
						$WAND .= (($WAND)? ' AND ' : ' ')." EXISTS (SELECT 1 FROM wp_data_att dt WHERE dtmp{$Level}.parent_path_hash = dt.parent_path_hash AND dtmp{$Level}.id = dt.id AND dtmp{$Level}.type_id = dt.type_id AND dt.attribute_id IN ('{$kAND}') AND ({$Value}) )";
					}
					elseif( in_array($kAND,array_keys((array)($Pattern['D']??[])  ) )) {# Weitere Ebene Prüfen
						$WAND .= (($WAND)? ' AND ' : ' ')." EXISTS (SELECT 2 FROM wp_data_cache dtmp".($Level+1) ." WHERE dtmp".($Level+1).".parent_path_hash = dtmp{$Level}.path_hash ";
						$WAND .= $this->_get_where($AND,$Pattern['D'][$kAND],$Level+1);
						$WAND .= ' ) ';
					}
					else { $WAND .= 1;}
				}
				$WOR .= ($WOR)?"{$WAND} ) " : $WAND;
			}
			$W .= " {$WOR} ) ";
		}
		return $W??'';
	}

	/**
	 * Filds = Feld übergabe mit Oeration oder ohne z:B $Filds['ID'] = ['12'] (wird immer IN gewählt wenn kein Operator) Oder $Filds['ID']['IN'] = ['12'] Oder ...['ID']['IN'] = '12' ODER ...['ID']['LIKE%%'] = 'Test' ODER ...['ID']['LIKE%%'] = ['Test', 'Test2']
	 * Operatoren: IN;NOT IN;LIKE;LIKE%-;LIKE-%;LIKE%%;>;<;<=;<=;=;<>;!=
	 * $Level Muss von _get_where das Level übergeben werden!
	 */
	private function _get_where_Operations ($Fild,$Operation,$Level=0) {
		
		$OV = $aloneOV ='';
		$_Fild = ($Fild == 'ID')?"dtmp{$Level}.id":"dt.value";
		foreach ((array)$Operation AS $kOpe => $Ope) {
			$OV .= ($OV)? ' OR ' : '';
			$_Value = '';
			$esc = " ESCAPE '\\'"; # Escape für LIKE-Operationen, außer bei einfachem LIKE
			if( in_array($kOpe, ['LIKE','LIKE%','LIKE%%','LIKE%-','LIKE-%']) ) {
				$pre = (in_array($kOpe,['LIKE%','LIKE%%','LIKE%-']))?'%':'';
				$suf = (in_array($kOpe,['LIKE%','LIKE%%','LIKE-%']))?'%':'';
				if(is_array($Ope)) { #Wurde Value als Array übergeben?
					foreach((array)$Ope AS $k => $v) {
						$v = addcslashes($v, '%_\\'); 
						$v = \SQLite3::escapeString($v);
						$_Value .= ($_Value?' OR ':'')."{$_Fild} LIKE '{$pre}{$v}{$suf}' {$esc}";
					}
				}
				else {
					$Ope = addcslashes($Ope, '%_\\');
					$Ope = \SQLite3::escapeString($Ope);
					$_Value = " {$_Fild} LIKE '{$pre}{$Ope}{$suf}'  {$esc}";
				}
				$OV .= " {$_Value} ";
			}
			elseif( in_array($kOpe, ['>','<','>=','<=','<>','!=','=']) ) {
				if(is_array($Ope)) { #Wurde Value als Array übergeben?
					$ordered = in_array($kOpe, ['>','<','>=','<='], true);
					foreach((array)$Ope AS $k => $v) {
						if($ordered && is_numeric($v)) {
							$cond = "({$_Fild} GLOB '*[0-9]*' AND CAST({$_Fild} AS REAL) {$kOpe} ".(float)$v.")";
						} else {
							$cond = "{$_Fild} {$kOpe} '".\SQLite3::escapeString($v)."'";
						}
						$_Value .= ($_Value ? ' OR ' : '') . " {$cond} ";
						#$v = \SQLite3::escapeString($v);
						#$_Value .= ($_Value?' OR ':'')." {$_Fild} {$kOpe} '{$v}' ";
					}
				}
				else {
					$Ope = \SQLite3::escapeString($Ope);
					$_Value = "{$_Fild} {$kOpe} '{$Ope}'";
				}
				$OV .= " {$_Value} ";
			}
			elseif( in_array($kOpe, ['IS NULL','NOT NULL']) ) {
				#Todo: Es werden keine Attribute mit NULL angelegt, daher muss bereits NOT EXISTS geprüft werden
				if(is_array($Ope)) { #Wurde Value als Array übergeben?
					foreach((array)$Ope AS $k => $v) {
						$v = \SQLite3::escapeString($v);
						$_Value .= ($_Value?' OR ':'')." {$_Fild} {$kOpe} ";
					}
				}
				else {
					$Ope = \SQLite3::escapeString($Ope);
					$_Value = "{$_Fild} {$kOpe} ";
				}
				$OV .= " {$_Value} ";
			}
			elseif( in_array($kOpe, ['BETWEEN','NOT BETWEEN']) ) {
				if( is_array($Ope) && count($Ope) === 2) {
					[$a, $b] = array_values($Ope);
					$num = is_numeric($a) && is_numeric($b);
					$f = $num ? "CAST({$_Fild} AS REAL)" : $_Fild;
					$OV .= $num ? " {$f} {$kOpe} ".(float)$a." AND ".(float)$b." "
								: " {$f} {$kOpe} '".\SQLite3::escapeString($a)."' AND '".\SQLite3::escapeString($b)."' ";
				}
			}
			elseif( in_array($kOpe, ['IN']) ) {
				if(is_array($Ope)) { #Wurde Value als Array übergeben?
					foreach((array)$Ope AS $k => $v) {
						$v = \SQLite3::escapeString($v);
						$_Value .= ($_Value?',':'')."'{$v}'";
					}
				}
				else {
					$Ope = \SQLite3::escapeString($Ope);
					$_Value = "'{$Ope}'";
				}
				$OV .= " {$_Fild} IN ({$_Value}) ";
			}
			elseif( in_array($kOpe, ['NOTIN', 'NOT IN']) ) {
				if(is_array($Ope)) { #Wurde Value als Array übergeben?
					foreach((array)$Ope AS $k => $v) {
						$v = \SQLite3::escapeString($v);
						$_Value .= ($_Value?',':'')."'{$v}'";
					}
				}
				else {
					$Ope = \SQLite3::escapeString($Ope);
					$_Value = "'{$Ope}'";
				}
				$OV .= " {$_Fild} NOT IN ({$_Value}) ";
			}
			else {
				$Ope = \SQLite3::escapeString($Ope);
				$aloneOV .= ($aloneOV?',':" {$_Fild} IN (")."'{$Ope}'"; #Ohne Operator übergabe
			}
		}
		if(!$Operation) { #Wenn keine Operation und kein Wert übergeben wurde aber nur ein kFild, dann ist der Value als Leer zu betrachten
			$Ope = \SQLite3::escapeString($Ope);
			$aloneOV .= ($aloneOV?',':" {$_Fild} IN (")."'{$Ope}'"; #Ohne Operator übergab 
		}
		$O = ($aloneOV)?$aloneOV.')':'';
		$O .= "{$OV}";
	
		return " {$O} ";
	}

	private function _get_order(&$F, &$Pattern, $Level=0) {
		$O = '';
		if(($F['O']??false)) {
						foreach ((array) $F['O'] as $kR => $R) {
							foreach ((array) $R as $key => $value) {
								if ($key == 'ID') {
									$O .= (($O) ? ',' : '') . " dtmp{$Level}.id {$value} ";
								}
								elseif ($key == 'UTIMESTAMP') {
									$O .= (($O) ? ',' : '') . " (SELECT utimestamp FROM wp_data d WHERE dtmp{$Level}.parent_path_hash = d.parent_path_hash AND dtmp{$Level}.id = d.id AND dtmp{$Level}.type_id = d.type_id ) {$value} ";
								}
								elseif ($key == 'ITIMESTAMP') {
									$O .= (($O) ? ',' : '') . " (SELECT itimestamp FROM wp_data d WHERE dtmp{$Level}.parent_path_hash = d.parent_path_hash AND dtmp{$Level}.id = d.id AND dtmp{$Level}.type_id = d.type_id ) {$value} ";
								}
								elseif( in_array($key,array_keys((array)$Pattern) ) ) { #Prüfe ob das Attribut auch im Patern enthalten ist. z.B: Active
									$O .= (($O) ? ',' : '') . " (SELECT sort FROM wp_data_att dt WHERE dtmp{$Level}.parent_path_hash = dt.parent_path_hash AND dtmp{$Level}.id = dt.id AND dtmp{$Level}.type_id = dt.type_id AND attribute_id = '{$key}' ) {$value}";
								}
								elseif( in_array($key,array_keys((array)$Pattern['D']) )) {# Weitere Ebene Prüfen
									
									#$O .= (($O)? ' AND ' : ' ')." (SELECT 2 FROM wp_data_cache dtmp".($Level+1) ." WHERE dtmp".($Level+1).".parent_path_hash = dtmp{$Level}.path_hash ";
									$O .= (($O)? ',' : ' ')." (SELECT sort FROM wp_data_att dt".($Level+1).", wp_data_cache dtmp".($Level+1)." 
									WHERE dtmp".($Level+1).".parent_path_hash = dtmp{$Level}.path_hash 
									AND dtmp".($Level+1).".parent_path_hash = dt".($Level+1).".parent_path_hash 
									AND dtmp".($Level+1).".id = dt".($Level+1).".id 
									AND dtmp".($Level+1).".type_id = dt".($Level+1).".type_id ";
									
									$O .= $this->_get_order($value,$Pattern['D'][$key],$Level+1);
									#ToDo: funktioniert nur mit der nächsten ebene. Weil "O" oben gefiltert wird: z.B: $f['ARTICLE']['O'][0]['ATTRIBUTE']['ITEM']['PROPERTY']['D']['Number']['Value'] = 'DESC';
									if($value['D']??false) {
										$_id = array_key_first($value['D']);
										$_att_id = array_key_first($value['D'][$_id]);
										$O .= " AND dt".($Level+1).".attribute_id = '{$_att_id}' AND dt".($Level+1).".id = '{$_id}' ";
										$O .= " ) {$value['D'][$_id][$_att_id]}";
									}
									else {
										$O .= ' )';
									}
								}
							}
						}
		
						$O = ($O) ? "ORDER BY {$O}, dtmp{$Level}.id" : '';
		}

		return $O??'';
	}

	function get_object(&$D = null, &$F=null, $Parent_Hash=[], $Parent_Type = '', $Parent_Id = '') {
		static $stLevel = 0;
		
		if($stLevel==0) {
			$saveD = $D;
			$D = null;
		}
		
			$savePatern = $this->PATTERN;

			foreach((array)$F AS $kType => $Type) {
				#0 Cache Abfrage
				if($stLevel==0) {
					$_sqlmd5 = md5(serialize([$kType => $F[$kType] ]));
					$_CacheData = $this->CCache->get_cache($_sqlmd5);
				}
				if($stLevel==0 && isset($_CacheData[$_sqlmd5])) {
					$d = unserialize($_CacheData[$_sqlmd5]['Data']);
					$D[''][$kType] = $d[$kType];
				}
				else {
					$W1 = $W = $L = $W_ID = '';
					#1. Erstelle Bedinung
					$kHash = ($Parent_Hash)?implode("','",(array)$Parent_Hash[$kType]):"";
					
					
					#Durlaufe alle Felder um Informationen dazu zu erhalten, wei z.B: Type, ForeignKey
					foreach((array) ($savePatern[$kType]??[]) AS $kPF => $PF) {
						#Filtere ForeignKey Felder heraus
						if (isset($PF['ForeignKey'])) {#Ist Fremdschlüssel?
							$ForeignKeys[$kPF] = $PF['ForeignKey'];
						}
					}
					

					if($savePatern[$kType]['D']??null) { #Pürft ob weitere Ebene Vorhanden ist
						$f = $F[ $kType ];
					}

					$W = $this->_get_where($Type,$savePatern[$kType],0);
					$W = " (dtmp0.type_id = '{$kType}' AND dtmp0.parent_path_hash IN ('{$kHash}') ) {$W}";

					$W_count = " (dtmp0.type_id = '{$kType}' AND dtmp0.parent_path_hash IN ('{$kHash}') )"; #Für Count Berechnung, weil Count die absolute Maximum ausgibt ohne Filterung nach einzelnen Objekten.
					
					$L = (isset($F[$kType]['L']['STEP'])) ? "LIMIT 0," . (int)$F[$kType]['L']['STEP'] : $L;
					$L = (isset($F[$kType]['L']['START']) && $F[$kType]['L']['STEP']) ? "LIMIT " . (int)$F[$kType]['L']['START'] . "," . (int)$F[$kType]['L']['STEP'] : $L;

					#Order By
					$O = $this->_get_order($Type, $savePatern[$kType],0);

					#2. Holle Daten
					$_Hash = null;
					
					##echo "SELECT id, type_id, parent_path_hash,path_hash, data FROM wp_data_cache dtmp0 WHERE {$W} {$O} {$L}<br>";
					$qry = $this->SQL->query("SELECT id, type_id, parent_path_hash,path_hash, data FROM wp_data_cache dtmp0 WHERE {$W} {$O} {$L}");
					while ($a = $qry->fetchArray(SQLITE3_ASSOC)) {
						$D[ $a['parent_path_hash'] ][ $a['type_id'] ]['D'][ $a['id'] ] = array_replace_recursive(
						(array) ($D[ $a['parent_path_hash'] ][ $a['type_id'] ]['D'][$a['id']]??[]),
						(array) json_decode($a['data'], 1));
						
						$d[ $a['path_hash'] ] = &$D[ $a['parent_path_hash'] ][ $a['type_id'] ]['D'][$a['id']];

						#ForeignKey Anhang
						foreach((array)($ForeignKeys??[]) AS $kFK => $FK) {
							if(isset($savePatern[$kType][$kFK]['ForeignKey']) && isset($D[ $a['parent_path_hash'] ][ $a['type_id'] ]['D'][ $a['id'] ][ $kFK ]) ) {
								$D[ $a['parent_path_hash'] ][ $a['type_id'] ][ $kFK ]['D'][ $D[ $a['parent_path_hash'] ][ $a['type_id'] ]['D'][ $a['id'] ][ $kFK ] ][ $a['type_id'] ]['D'][ $a['id'] ] 
								= &$D[ $a['parent_path_hash'] ][ $a['type_id'] ]['D'][$a['id']];
							}
						}
						#Kind
						foreach((array)$F[ $a['type_id'] ] AS $kChild => $Child) {
							$_Hash[ $kChild ][] = $a['path_hash'];
						}
					}
					

					#Lese Count aus
					$qry = $this->SQL->query("SELECT count(*) num, type_id, parent_path_hash, path_hash FROM wp_data_cache dtmp0 WHERE {$W_count} GROUP BY parent_path_hash,type_id");
					while ($a = $qry->fetchArray(SQLITE3_ASSOC)) {
						$D[ $a['parent_path_hash'] ][ $a['type_id'] ]['COUNT'] = $a['num'];
					}
					
					#3. gehe in die weitere Ebene
					if(($savePatern[$kType]['D']??null) && $_Hash) { #$_Hash=Wenn Parents nicht vorhaden sind, dann gibt es auch keine kinder
						$stLevel++;
						$this->PATTERN = $savePatern[$kType]['D']??=[];
						$this->get_object($d,$f,$_Hash);
						$this->PATTERN = $savePatern; #Setze Pattern auf Ursprung zurück
						$stLevel--;
					}

					if($stLevel == 0) {
						$_cache[ $_sqlmd5 ] = [
							'Source'	=> serialize([$kType => $F[$kType] ]),
							'Tag'		=> $kType.'/'.implode('/',array_keys((array) $F[$kType])),
							'Data'		=> serialize([$kType => $D[''][$kType]?? [] ] ), #ToDo: Hier wird nicht nur die aktuelle ausgabe gespeichert, sondern die beigefügten Daten per $D zur Funktion
						];
						$this->CCache->set_cache($_cache);
					}
				}
					
			}
			

		
		if($stLevel == 0) {
			$D = array_replace_recursive((array)$saveD,(array)($D[''] ?? []) );
		}

	}

}