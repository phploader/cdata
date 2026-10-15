<?php
/**
 * Testsuite für phploader\CData (ohne externe Abhängigkeiten).
 *
 * Aufruf:
 *   php tests/CDataTest.php                  alle Tests
 *   php tests/CDataTest.php Filter           nur Tests, deren Name "Filter" enthält
 *   php tests/CDataTest.php --strict         bekannte Probleme (known*) zählen als Fehler
 *
 * Jeder Test läuft in einem eigenen PHP-Prozess mit frischer Temp-Datenbank (CData hält static-Zustand).
 *
 * Tests mit Präfix "test"  : müssen bestehen.
 * Tests mit Präfix "known" : beschreiben gewünschtes Verhalten, das aktuell (noch) nicht
 *                            funktioniert. Sie werden als KNOWN gemeldet und lassen den Lauf
 *                            nur mit --strict scheitern. Besteht ein solcher Test, wird FIXED gemeldet.
 */

use phploader\CData;

require_once __DIR__ . '/../lib/CCache.php';
require_once __DIR__ . '/../lib/CData.php';

error_reporting(E_ALL);

/** Warnungen/Deprecations aus lib/ werden gesammelt (nicht abgebrochen), alle anderen Fehler werfen. */
$GLOBALS['LIB_NOTICES'] = [];
set_error_handler(function (int $no, string $str, string $file, int $line) {
	if (str_contains(str_replace('\\', '/', $file), '/lib/')) {
		$GLOBALS['LIB_NOTICES'][basename($file) . ':' . $line . ' ' . $str] = true;
		return true;
	}
	throw new ErrorException($str, 0, $no, $file, $line);
});

class AssertionFailed extends Exception {}
class TestSkipped extends Exception {}

abstract class TestCase
{
	public int $assertions = 0;

	protected function fail(string $msg): never { throw new AssertionFailed($msg); }
	protected function skip(string $msg): never { throw new TestSkipped($msg); }

	protected function assertTrue($v, string $m = ''): void {
		$this->assertions++;
		if ($v !== true) { $this->fail(($m ?: 'true erwartet') . ' (erhalten: ' . $this->dump($v) . ')'); }
	}
	protected function assertFalse($v, string $m = ''): void {
		$this->assertions++;
		if ($v !== false) { $this->fail(($m ?: 'false erwartet') . ' (erhalten: ' . $this->dump($v) . ')'); }
	}
	protected function assertSame($expected, $actual, string $m = ''): void {
		$this->assertions++;
		if ($expected !== $actual) {
			$this->fail(($m ? $m . ': ' : '') . 'erwartet ' . $this->dump($expected) . ', erhalten ' . $this->dump($actual));
		}
	}
	protected function assertSameValues(array $expected, array $actual, string $m = ''): void {
		sort($expected); sort($actual);
		$this->assertSame($expected, $actual, $m);
	}
	protected function assertArrayHasKey($key, $arr, string $m = ''): void {
		$this->assertions++;
		if (!is_array($arr) || !array_key_exists($key, $arr)) {
			$this->fail(($m ?: "Key '{$key}' fehlt") . ' in ' . $this->dump($arr));
		}
	}
	protected function assertArrayNotHasKey($key, $arr, string $m = ''): void {
		$this->assertions++;
		if (is_array($arr) && array_key_exists($key, $arr)) {
			$this->fail(($m ?: "Key '{$key}' darf nicht existieren") . ' in ' . $this->dump($arr));
		}
	}

	protected function dump($v): string {
		return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: var_export($v, true);
	}

	public function setUp(): void {}
	public function tearDown(): void {}
}

class CDataTest extends TestCase
{
	private string $root = '';
	private int $n = 0;
	/** @var CData[] */
	private array $instances = [];
	private string $file = '';

	// ------------------------------------------------------------------ Infrastruktur

	public function setUp(): void {
		$this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cdata_test_' . uniqid();
		mkdir($this->root, 0777, true);
		$this->n = 0;
		$this->file = '';
	}

	public function tearDown(): void {
		$this->instances = [];
		gc_collect_cycles();
		$this->rmdir($this->root);
	}

	private function rmdir(string $dir): void {
		if (!is_dir($dir)) { return; }
		foreach (scandir($dir) as $f) {
			if ($f === '.' || $f === '..') { continue; }
			$p = $dir . DIRECTORY_SEPARATOR . $f;
			is_dir($p) ? $this->rmdir($p) : @unlink($p);
		}
		@rmdir($dir);
	}

	private function pattern(): array {
		return [
			'LANGUAGE' => [
				'Active' => ['Type' => 'checkbox'],
				'Title'  => ['Type' => 'text'],
			],
			'ARTICLE' => [
				'Active' => ['Type' => 'checkbox'],
				'Name'   => ['Type' => 'text'],
				'Price'  => ['Type' => 'number'],
				'Ref'    => ['Type' => 'id', 'ForeignKey' => 1],
			],
			'PLATFORM' => [
				'Active' => ['Type' => 'checkbox'],
				'Title'  => ['Type' => 'text'],
				'D' => [
					'LANGUAGE' => [
						'Title' => ['Type' => 'text'],
						'D' => [
							'ATTRIBUTE' => [
								'Title'    => ['Type' => 'text'],
								'ParentId' => ['Type' => 'id', 'ForeignKey' => 1],
							],
						],
					],
					'ARTICLE' => [
						'Name'  => ['Type' => 'text'],
						'Price' => ['Type' => 'number'],
						'D' => [
							'LANGUAGE' => [
								'Active' => ['Type' => 'checkbox'],
								'D' => [
									'ATTRIBUTE' => [
										'Value' => ['Type' => 'text'],
									],
								],
							],
						],
					],
				],
			],
		];
	}

	/** Neue CData-Instanz. Ohne $file wird eine neue, leere Datenbank verwendet; mit $file die bestehende. */
	private function db(?string $file = null, array $extra = []): CData {
		$this->file = $file ?? $this->root . DIRECTORY_SEPARATOR . 'db' . (++$this->n) . DIRECTORY_SEPARATOR . 'test.db';
		$db = new CData(array_replace_recursive(['DB' => ['FILENAME' => $this->file], 'PATTERN' => $this->pattern()], $extra));
		return $this->instances[] = $db;
	}

	private function raw(?string $file = null): SQLite3 {
		return new SQLite3($file ?? $this->file);
	}

	private function rawValue(string $sql, ?string $file = null) {
		$r = $this->raw($file);
		$v = $r->querySingle($sql);
		$r->close();
		return $v;
	}

	private function set(CData $db, array $D): void {
		$db->set_object($D);
	}

	private function get(CData $db, array $F, ?array $D = null): array {
		$out = $D;
		$db->get_object($out, $F);
		return (array)$out;
	}

	/** IDs der Datensätze eines Typs in Ausgabereihenfolge. */
	private function ids(array $D, string $type): array {
		return array_keys($D[$type]['D'] ?? []);
	}

	/** Standard-Artikelbestand: A1 Apfel/10, A2 Birne/5, A3 Citrone/100 (Active 1,0,1). */
	private function seedArticles(CData $db): void {
		$D['ARTICLE']['D']['A1'] = ['Name' => 'Apfel',   'Price' => '10',  'Active' => 1];
		$D['ARTICLE']['D']['A2'] = ['Name' => 'Birne',   'Price' => '5',   'Active' => 0];
		$D['ARTICLE']['D']['A3'] = ['Name' => 'Citrone', 'Price' => '100', 'Active' => 1];
		$this->set($db, $D);
	}

	private function seedPlatforms(CData $db): void {
		$D['PLATFORM']['D']['P1']['Title'] = 'Plattform 1';
		$D['PLATFORM']['D']['P1']['LANGUAGE']['D']['DE']['Title'] = 'Deutsch';
		$D['PLATFORM']['D']['P1']['LANGUAGE']['D']['DE']['ATTRIBUTE']['D']['X']['Title'] = 'Attribut X';
		$D['PLATFORM']['D']['P1']['ARTICLE']['D']['x1'] = ['Name' => 'Z', 'Price' => '1'];
		$D['PLATFORM']['D']['P1']['ARTICLE']['D']['x2'] = ['Name' => 'Y', 'Price' => '2'];
		$D['PLATFORM']['D']['P2']['Title'] = 'Plattform 2';
		$D['PLATFORM']['D']['P2']['ARTICLE']['D']['x3'] = ['Name' => 'Q', 'Price' => '3'];
		$this->set($db, $D);
	}

	// ------------------------------------------------------------------ Konstruktor / Datenbank

	public function testConstructorCreatesDatabaseAndDirectories(): void {
		$this->db();
		$this->assertTrue(is_file($this->file));
		$tables = [];
		$r = $this->raw();
		$q = $r->query("SELECT name FROM sqlite_master WHERE type = 'table'");
		while ($a = $q->fetchArray(SQLITE3_ASSOC)) { $tables[] = $a['name']; }
		$r->close();
		foreach (['wp_data', 'wp_data_att', 'wp_data_cache'] as $t) {
			$this->assertTrue(in_array($t, $tables, true), "Tabelle {$t} fehlt");
		}
	}

	public function testConstructorCreatesCacheFile(): void {
		$this->db();
		$this->assertTrue(is_file($this->file . '.cache'));
	}

	public function testConstructorUsesCustomCacheFile(): void {
		$cache = $this->root . DIRECTORY_SEPARATOR . 'other' . DIRECTORY_SEPARATOR . 'my';
		$this->db(null, ['DB' => ['FILENAME_C' => $cache]]);
		$this->assertTrue(is_file($cache . '.cache'));
		$this->assertFalse(is_file($this->file . '.cache'));
	}

	public function testConstructorAcceptsCustomPragma(): void {
		$db = $this->db(null, ['PRAGMA' => 'PRAGMA synchronous = 0;']);
		$this->seedArticles($db);
		$this->assertSame(['A1', 'A2', 'A3'], $this->ids($this->get($db, ['ARTICLE' => []]), 'ARTICLE'));
	}

	public function testReopeningExistingDatabaseKeepsData(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$file = $this->file;
		$this->instances = [];
		gc_collect_cycles();

		$db2 = $this->db($file);
		$D = $this->get($db2, ['ARTICLE' => ['W' => [0 => ['ID' => 'A1']]]]);
		$this->assertSame('Apfel', $D['ARTICLE']['D']['A1']['Name']);
	}

	public function testRebuildsCacheTableWhenEmptyOnConstruct(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$file = $this->file;
		$this->instances = [];
		gc_collect_cycles();

		$r = $this->raw($file); $r->exec('DELETE FROM wp_data_cache'); $r->close();
		$this->assertSame(null, $this->rawValue('SELECT 1 FROM wp_data_cache LIMIT 1', $file));

		$db2 = $this->db($file);
		$this->assertSame(3, (int)$this->rawValue('SELECT count(*) FROM wp_data_cache', $file));
		$D = $this->get($db2, ['ARTICLE' => ['W' => [0 => ['ID' => 'A3']]]]);
		$this->assertSame('Citrone', $D['ARTICLE']['D']['A3']['Name']);
	}

	public function testRepairRebuildsCacheTable(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$r = $this->raw(); $r->exec('DELETE FROM wp_data_cache'); $r->close();

		$db->repair();

		$this->assertSame(3, (int)$this->rawValue('SELECT count(*) FROM wp_data_cache'));
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Name' => 'Birne']]]]);
		$this->assertSame(['A2'], $this->ids($D, 'ARTICLE'));
	}

	// ------------------------------------------------------------------ Pattern

	public function testGetPattern(): void {
		$db = $this->db();
		$db->get_Pattern($X);
		$this->assertSame($this->pattern(), $X['PATTERN']);
	}

	public function testSetPattern(): void {
		$db = $this->db();
		$X = ['PATTERN' => ['T' => ['Title' => ['Type' => 'text']]]];
		$db->set_Pattern($X);
		$db->get_Pattern($Y);
		$this->assertSame(['T'], array_keys($Y['PATTERN']));
	}

	public function testRegisterAndUnregisterPattern(): void {
		$db = $this->db();
		$db->registerPattern(['COLOR' => ['Name' => ['Type' => 'text']], 'SIZE' => ['Name' => ['Type' => 'text']]]);
		$db->get_Pattern($X);
		$this->assertArrayHasKey('COLOR', $X['PATTERN']);
		$this->assertArrayHasKey('SIZE', $X['PATTERN']);
		$this->assertArrayHasKey('ARTICLE', $X['PATTERN'], 'bestehende Pattern bleiben erhalten');

		$db->unregisterPattern('COLOR');
		$db->get_Pattern($X);
		$this->assertArrayNotHasKey('COLOR', $X['PATTERN']);
		$this->assertArrayHasKey('SIZE', $X['PATTERN']);

		$db->unregisterPattern(['SIZE', 'ARTICLE', 'DOES_NOT_EXIST']);
		$db->get_Pattern($X);
		$this->assertArrayNotHasKey('SIZE', $X['PATTERN']);
		$this->assertArrayNotHasKey('ARTICLE', $X['PATTERN']);
	}

	public function testRegisterPatternMergesRecursively(): void {
		$db = $this->db();
		$db->registerPattern(['ARTICLE' => ['Color' => ['Type' => 'text']]]);
		$db->get_Pattern($X);
		$this->assertArrayHasKey('Color', $X['PATTERN']['ARTICLE']);
		$this->assertArrayHasKey('Name', $X['PATTERN']['ARTICLE']);
	}

	public function testRegisteredPatternAllowsSavingNewType(): void {
		$db = $this->db();
		$db->registerPattern(['COLOR' => ['Name' => ['Type' => 'text']]]);
		$this->set($db, ['COLOR' => ['D' => ['red' => ['Name' => 'Rot']]]]);
		$D = $this->get($db, ['COLOR' => []]);
		$this->assertSame('Rot', $D['COLOR']['D']['red']['Name']);
	}

	public function testUnregisteredPatternIsNotSaved(): void {
		$db = $this->db();
		$db->unregisterPattern('ARTICLE');
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Name' => 'Apfel']]]]);
		$this->assertSame(0, (int)$this->rawValue('SELECT count(*) FROM wp_data'));
	}

	// ------------------------------------------------------------------ set_object: Insert / Update

	public function testInsertAndReadSingleRecord(): void {
		$db = $this->db();
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Name' => 'Apfel', 'Price' => '10', 'Active' => 1]]]]);
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSame(['Active' => '1', 'Name' => 'Apfel', 'Price' => '10'], $D['ARTICLE']['D']['A1']);
	}

	public function testInsertMultipleRecordsAndTypesInOneCall(): void {
		$db = $this->db();
		$D['ARTICLE']['D']['A1']['Name'] = 'Apfel';
		$D['ARTICLE']['D']['A2']['Name'] = 'Birne';
		$D['LANGUAGE']['D']['DE']['Title'] = 'Deutsch';
		$this->set($db, $D);

		$R = $this->get($db, ['ARTICLE' => [], 'LANGUAGE' => []]);
		$this->assertSameValues(['A1', 'A2'], $this->ids($R, 'ARTICLE'));
		$this->assertSame(['DE'], $this->ids($R, 'LANGUAGE'));
	}

	public function testUpdateChangesValueAndKeepsOtherAttributes(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Price' => '11']]]]);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => 'A1']]]]);
		$this->assertSame('11', $D['ARTICLE']['D']['A1']['Price']);
		$this->assertSame('Apfel', $D['ARTICLE']['D']['A1']['Name']);
		$this->assertSame('1', $D['ARTICLE']['D']['A1']['Active']);
	}

	public function testSavingTwiceIsIdempotent(): void {
		$db = $this->db();
		$D = ['ARTICLE' => ['D' => ['A1' => ['Name' => 'Apfel']]]];
		$this->set($db, $D);
		$this->set($db, $D);
		$this->assertSame(1, (int)$this->rawValue('SELECT count(*) FROM wp_data'));
		$this->assertSame(1, (int)$this->rawValue('SELECT count(*) FROM wp_data_att'));
		$this->assertSame(1, (int)$this->rawValue('SELECT count(*) FROM wp_data_cache'));
	}

	public function testRecordWithoutAttributesIsStored(): void {
		$db = $this->db();
		$this->set($db, ['ARTICLE' => ['D' => ['A4' => []]]]);
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSame(['A4'], $this->ids($D, 'ARTICLE'));
	}

	public function testUnknownTypeIsIgnored(): void {
		$db = $this->db();
		$this->set($db, ['FOO' => ['D' => ['x' => ['Title' => 'a']]]]);
		$this->assertSame(0, (int)$this->rawValue('SELECT count(*) FROM wp_data'));
		$this->assertSame([], $this->get($db, ['FOO' => []]));
	}

	public function testUnknownAttributeIsIgnored(): void {
		$db = $this->db();
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Name' => 'Apfel', 'Nope' => 'x']]]]);
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSame(['Name' => 'Apfel'], $D['ARTICLE']['D']['A1']);
	}

	// ------------------------------------------------------------------ IDs mit Sonderzeichen

	/** IDs werden in set_object() escaped: Datensatz-ID mit Apostroph wird gespeichert. */
	public function testRecordIdWithQuote(): void {
		$db = $this->db();
		$this->set($db, ['ARTICLE' => ['D' => ["q'id" => ['Name' => 'quote']]]]);
		$this->assertSame(1, (int)$this->rawValue('SELECT count(*) FROM wp_data'));
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSame(["q'id"], $this->ids($D, 'ARTICLE'));
	}

	public function testRecordIdWithSqlInjectionCanBeUpdatedAndDeletedAndHarmsNothingElse(): void {
		$db = $this->db();
		$id = "q'); DELETE FROM wp_data; --";
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Name' => 'bleibt'], $id => ['Name' => 'alt']]]]);
		$this->set($db, ['ARTICLE' => ['D' => [$id => ['Name' => 'neu']]]]);

		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSameValues(['A1', $id], $this->ids($D, 'ARTICLE'));
		$this->assertSame('neu', $D['ARTICLE']['D'][$id]['Name']);

		$this->set($db, ['ARTICLE' => ['D' => [$id => null]]]); // Löschen liest die ID aus der Datenbank und setzt sie wieder ins SQL ein
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSame(['A1'], $this->ids($D, 'ARTICLE'));
		$this->assertSame('bleibt', $D['ARTICLE']['D']['A1']['Name']);
	}

	public function testNestedRecordIdsWithQuoteAndDeleteOfParent(): void {
		$db = $this->db();
		$this->set($db, ['PLATFORM' => ['D' => ["p'1" => ['Title' => 'P', 'ARTICLE' => ['D' => ["a'1" => ['Name' => 'x']]]]]]]);

		$D = $this->get($db, ['PLATFORM' => ['ARTICLE' => []]]);
		$this->assertSame(["a'1"], $this->ids($D['PLATFORM']['D']["p'1"], 'ARTICLE'));

		$this->set($db, ['PLATFORM' => ['D' => ["p'1" => null]]]);
		$this->assertSame(0, (int)$this->rawValue('SELECT count(*) FROM wp_data'));
		$this->assertSame(0, (int)$this->rawValue('SELECT count(*) FROM wp_data_att'));
	}

	public function testSpecialCharactersInValuesAreStoredUnchanged(): void {
		$db = $this->db();
		$values = [
			'quote'   => "O'Brien",
			'dquote'  => 'Say "hi"',
			'percent' => '100% & <b>fett</b>',
			'slash'   => 'C:\\temp\\x',
			'umlaut'  => 'Müller äöü ß €',
			'newline' => "Zeile1\nZeile2",
			'sql'     => "x'; DROP TABLE wp_data; --",
			'number'  => '007',
		];
		foreach ($values as $id => $v) { $D['ARTICLE']['D'][$id]['Name'] = $v; }
		$this->set($db, $D);

		$R = $this->get($db, ['ARTICLE' => []]);
		foreach ($values as $id => $v) {
			$this->assertSame($v, $R['ARTICLE']['D'][$id]['Name'] ?? null, "Wert '{$id}'");
		}
		$this->assertSame(count($values), (int)$this->rawValue('SELECT count(*) FROM wp_data'));
	}

	public function testNumericValuesAreReturnedAsStrings(): void {
		$db = $this->db();
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Price' => 12.5, 'Active' => 1]]]]);
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSame('12.5', $D['ARTICLE']['D']['A1']['Price']);
		$this->assertSame('1', $D['ARTICLE']['D']['A1']['Active']);
	}

	public function testZeroIsStoredAsValue(): void {
		$db = $this->db();
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Active' => 0]]]]);
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSame('0', $D['ARTICLE']['D']['A1']['Active']);
	}

	// ------------------------------------------------------------------ set_object: Attribute löschen

	public function testEmptyStringRemovesAttribute(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Name' => '']]]]);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => 'A1']]]]);
		$this->assertArrayNotHasKey('Name', $D['ARTICLE']['D']['A1']);
		$this->assertSame('10', $D['ARTICLE']['D']['A1']['Price']);
	}

	public function testNullRemovesAttribute(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Name' => null]]]]);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => 'A1']]]]);
		$this->assertArrayNotHasKey('Name', $D['ARTICLE']['D']['A1']);
	}

	public function testDeleteMarkerRemovesAttribute(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Name' => '__DELETE__']]]]);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => 'A1']]]]);
		$this->assertArrayNotHasKey('Name', $D['ARTICLE']['D']['A1']);
		$this->assertSame(2, (int)$this->rawValue("SELECT count(*) FROM wp_data_att WHERE id = 'A1'"));
	}

	public function testForeignKeyAttributeAcceptsEmptyString(): void {
		$db = $this->db();
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Name' => 'Apfel', 'Ref' => '']]]]);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => 'A1']]]]);
		$this->assertSame('', $D['ARTICLE']['D']['A1']['Ref']);
	}

	// ------------------------------------------------------------------ set_object: Datensätze löschen

	public function testDeleteRecordWithNull(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$this->set($db, ['ARTICLE' => ['D' => ['A2' => null]]]);
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSameValues(['A1', 'A3'], $this->ids($D, 'ARTICLE'));
		$this->assertSame(2, $D['ARTICLE']['COUNT']);
		$this->assertSame(0, (int)$this->rawValue("SELECT count(*) FROM wp_data_att WHERE id = 'A2'"));
	}

	public function testDeleteRecordWithDeleteMarker(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => '__DELETE__']]]);
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSameValues(['A2', 'A3'], $this->ids($D, 'ARTICLE'));
	}

	public function testDeleteRecordWithActiveMinusTwo(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Active' => -2]]]]);
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSameValues(['A2', 'A3'], $this->ids($D, 'ARTICLE'));
	}

	public function testDeleteNonExistingRecordDoesNothing(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$this->set($db, ['ARTICLE' => ['D' => ['nope' => null]]]);
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSame(3, count($this->ids($D, 'ARTICLE')));
	}

	public function testDeleteAndInsertInOneCall(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => null, 'A9' => ['Name' => 'Neu']]]]);
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSameValues(['A2', 'A3', 'A9'], $this->ids($D, 'ARTICLE'));
	}

	public function testDeleteParentDeletesAllDescendants(): void {
		$db = $this->db();
		$this->seedPlatforms($db);
		$this->set($db, ['PLATFORM' => ['D' => ['P1' => null]]]);

		$D = $this->get($db, ['PLATFORM' => ['LANGUAGE' => ['ATTRIBUTE' => []], 'ARTICLE' => []]]);
		$this->assertSame(['P2'], $this->ids($D, 'PLATFORM'));
		$this->assertSame(0, (int)$this->rawValue("SELECT count(*) FROM wp_data WHERE type_id IN ('LANGUAGE','ATTRIBUTE')"));
		$this->assertSame(0, (int)$this->rawValue("SELECT count(*) FROM wp_data_att WHERE id IN ('DE','X','x1','x2','P1')"));
		$this->assertSame(0, (int)$this->rawValue("SELECT count(*) FROM wp_data_cache WHERE id IN ('DE','X','x1','x2','P1')"));
		$this->assertSame(['x3'], $this->ids($D['PLATFORM']['D']['P2'], 'ARTICLE'));
	}

	public function testDeleteNestedRecordKeepsParentAndSiblings(): void {
		$db = $this->db();
		$this->seedPlatforms($db);
		$this->set($db, ['PLATFORM' => ['D' => ['P1' => ['ARTICLE' => ['D' => ['x1' => null]]]]]]);

		$D = $this->get($db, ['PLATFORM' => ['ARTICLE' => []]]);
		$this->assertSameValues(['P1', 'P2'], $this->ids($D, 'PLATFORM'));
		$this->assertSame(['x2'], $this->ids($D['PLATFORM']['D']['P1'], 'ARTICLE'));
		$this->assertSame(['x3'], $this->ids($D['PLATFORM']['D']['P2'], 'ARTICLE'));
	}

	// ------------------------------------------------------------------ Hierarchie

	public function testNestedRecordsAcrossThreeLevels(): void {
		$db = $this->db();
		$this->seedPlatforms($db);
		$D = $this->get($db, ['PLATFORM' => ['LANGUAGE' => ['ATTRIBUTE' => []]]]);
		$this->assertSame('Plattform 1', $D['PLATFORM']['D']['P1']['Title']);
		$this->assertSame('Deutsch', $D['PLATFORM']['D']['P1']['LANGUAGE']['D']['DE']['Title']);
		$this->assertSame('Attribut X', $D['PLATFORM']['D']['P1']['LANGUAGE']['D']['DE']['ATTRIBUTE']['D']['X']['Title']);
	}

	public function testSameIdInDifferentParentsIsIndependent(): void {
		$db = $this->db();
		$D['PLATFORM']['D']['P1']['ARTICLE']['D']['dup']['Name'] = 'in1';
		$D['PLATFORM']['D']['P2']['ARTICLE']['D']['dup']['Name'] = 'in2';
		$this->set($db, $D);

		$R = $this->get($db, ['PLATFORM' => ['ARTICLE' => []]]);
		$this->assertSame('in1', $R['PLATFORM']['D']['P1']['ARTICLE']['D']['dup']['Name']);
		$this->assertSame('in2', $R['PLATFORM']['D']['P2']['ARTICLE']['D']['dup']['Name']);

		$this->set($db, ['PLATFORM' => ['D' => ['P1' => ['ARTICLE' => ['D' => ['dup' => null]]]]]]);
		$R = $this->get($db, ['PLATFORM' => ['ARTICLE' => []]]);
		$this->assertSame([], $this->ids($R['PLATFORM']['D']['P1'], 'ARTICLE'));
		$this->assertSame('in2', $R['PLATFORM']['D']['P2']['ARTICLE']['D']['dup']['Name']);
	}

	public function testSameTypeAtRootAndNestedAreIndependent(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$this->seedPlatforms($db);
		$D = $this->get($db, ['ARTICLE' => [], 'PLATFORM' => ['ARTICLE' => []]]);
		$this->assertSameValues(['A1', 'A2', 'A3'], $this->ids($D, 'ARTICLE'));
		$this->assertSameValues(['x1', 'x2'], $this->ids($D['PLATFORM']['D']['P1'], 'ARTICLE'));
	}

	public function testForeignKeyCreatesBackReference(): void {
		$db = $this->db();
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Name' => 'Apfel', 'Ref' => 'R1']]]]);
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSame('R1', $D['ARTICLE']['D']['A1']['Ref']);
		$this->assertSame('Apfel', $D['ARTICLE']['Ref']['D']['R1']['ARTICLE']['D']['A1']['Name']);
	}

	// ------------------------------------------------------------------ get_object: Grundlagen

	public function testGetAllRecordsIncludesCount(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSame(['A1', 'A2', 'A3'], $this->ids($D, 'ARTICLE'));
		$this->assertSame(3, $D['ARTICLE']['COUNT']);
	}

	public function testGetOnEmptyDatabase(): void {
		$db = $this->db();
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSame([], $this->ids($D, 'ARTICLE'));
	}

	public function testGetWithEmptyFilterReturnsNothing(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$this->assertSame([], $this->get($db, []));
	}

	public function testGetMultipleTypesAtOnce(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$this->seedPlatforms($db);
		$D = $this->get($db, ['ARTICLE' => [], 'PLATFORM' => []]);
		$this->assertSame(3, count($this->ids($D, 'ARTICLE')));
		$this->assertSameValues(['P1', 'P2'], $this->ids($D, 'PLATFORM'));
	}

	public function testGetPreservesExistingDataInResultArray(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => 'A1']]]], ['KEEP' => ['D' => ['k' => ['a' => 'b']]]]);
		$this->assertSame('b', $D['KEEP']['D']['k']['a']);
		$this->assertSame(['A1'], $this->ids($D, 'ARTICLE'));
	}

	public function testGetResultsAreNotMixedBetweenCalls(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D1 = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => 'A1']]]]);
		$D2 = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => 'A2']]]]);
		$this->assertSame(['A1'], $this->ids($D1, 'ARTICLE'));
		$this->assertSame(['A2'], $this->ids($D2, 'ARTICLE'));
	}

	// ------------------------------------------------------------------ get_object: WHERE

	public function testWhereIdSingle(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => 'A1']]]]);
		$this->assertSame(['A1'], $this->ids($D, 'ARTICLE'));
		$this->assertSame(3, $D['ARTICLE']['COUNT'], 'COUNT ist unabhängig vom Filter');
	}

	public function testWhereIdList(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => ['A1', 'A2']]]]]);
		$this->assertSameValues(['A1', 'A2'], $this->ids($D, 'ARTICLE'));
	}

	public function testWhereIdNotIn(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => ['NOTIN' => ['A1']]]]]]);
		$this->assertSameValues(['A2', 'A3'], $this->ids($D, 'ARTICLE'));
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => ['NOT IN' => ['A1', 'A2']]]]]]);
		$this->assertSame(['A3'], $this->ids($D, 'ARTICLE'));
	}

	public function testWhereIdLike(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => ['LIKE-%' => 'A']]]]]);
		$this->assertSameValues(['A1', 'A2', 'A3'], $this->ids($D, 'ARTICLE'));
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => ['LIKE%-' => '2']]]]]);
		$this->assertSame(['A2'], $this->ids($D, 'ARTICLE'));
	}

	public function testWhereIdComparison(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => ['>' => 'A1']]]]]);
		$this->assertSameValues(['A2', 'A3'], $this->ids($D, 'ARTICLE'));
	}

	public function testWhereAttributeEquals(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Name' => 'Apfel']]]]);
		$this->assertSame(['A1'], $this->ids($D, 'ARTICLE'));
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Name' => ['=' => 'Birne']]]]]);
		$this->assertSame(['A2'], $this->ids($D, 'ARTICLE'));
	}

	public function testWhereAttributeZeroValue(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Active' => 0]]]]);
		$this->assertSame(['A2'], $this->ids($D, 'ARTICLE'));
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Active' => 1]]]]);
		$this->assertSameValues(['A1', 'A3'], $this->ids($D, 'ARTICLE'));
	}

	public function testWhereAttributeValueList(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Name' => ['Apfel', 'Citrone']]]]]);
		$this->assertSameValues(['A1', 'A3'], $this->ids($D, 'ARTICLE'));
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Name' => ['IN' => ['Apfel', 'Birne']]]]]]);
		$this->assertSameValues(['A1', 'A2'], $this->ids($D, 'ARTICLE'));
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Name' => ['NOT IN' => ['Apfel', 'Birne']]]]]]);
		$this->assertSame(['A3'], $this->ids($D, 'ARTICLE'));
	}

	public function testWhereNotEqual(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Name' => ['!=' => 'Apfel']]]]]);
		$this->assertSameValues(['A2', 'A3'], $this->ids($D, 'ARTICLE'));
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Name' => ['<>' => 'Apfel']]]]]);
		$this->assertSameValues(['A2', 'A3'], $this->ids($D, 'ARTICLE'));
	}

	public function testWhereTextComparison(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$ids = fn(string $op, string $v) => $this->ids($this->get($db, ['ARTICLE' => ['W' => [0 => ['Name' => [$op => $v]]]]]), 'ARTICLE');
		$this->assertSameValues(['A2', 'A3'], $ids('>', 'B'));
		$this->assertSameValues(['A1'], $ids('<', 'B'));
		$this->assertSameValues(['A2', 'A3'], $ids('>=', 'Birne'));
		$this->assertSameValues(['A1', 'A2'], $ids('<=', 'Birne'));
	}

	public function testWhereLikeVariants(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$ids = fn($op, $v) => $this->ids($this->get($db, ['ARTICLE' => ['W' => [0 => ['Name' => [$op => $v]]]]]), 'ARTICLE');
		$this->assertSame(['A1'], $ids('LIKE-%', 'Ap'), 'beginnt mit');
		$this->assertSameValues(['A2', 'A3'], $ids('LIKE%-', 'ne'), 'endet mit');
		$this->assertSameValues(['A2', 'A3'], $ids('LIKE%%', 'r'), 'enthält');
		$this->assertSame(['A2'], $ids('LIKE', '%rne'), 'LIKE ohne Joker-Ergänzung');
		$this->assertSame([], $ids('LIKE', 'rne'));
		$this->assertSameValues(['A1', 'A3'], $ids('LIKE%%', ['pf', 'tro']), 'LIKE mit Liste = OR');
	}

	public function testWhereAndConditionsWithinOneGroup(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Active' => 1, 'Name' => 'Apfel']]]]);
		$this->assertSame(['A1'], $this->ids($D, 'ARTICLE'));
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Active' => 0, 'Name' => 'Apfel']]]]);
		$this->assertSame([], $this->ids($D, 'ARTICLE'));
	}

	public function testWhereOrConditionsBetweenGroups(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Name' => 'Apfel'], 1 => ['Name' => 'Birne']]]]);
		$this->assertSameValues(['A1', 'A2'], $this->ids($D, 'ARTICLE'));
	}

	public function testWhereCombinedAndOr(): void {
		$db = $this->db();
		$this->seedArticles($db);
		// (Active=1 AND Name=Citrone) OR (Name=Birne)
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Active' => 1, 'Name' => 'Citrone'], 1 => ['Name' => 'Birne']]]]);
		$this->assertSameValues(['A2', 'A3'], $this->ids($D, 'ARTICLE'));
	}

	public function testWhereNoMatchReturnsNoRecords(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => 'nope']]]]);
		$this->assertSame([], $this->ids($D, 'ARTICLE'));
		$this->assertSame(3, $D['ARTICLE']['COUNT']);
	}

	public function testWhereOnRemovedAttributeNoLongerMatches(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Name' => '']]]]);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Name' => 'Apfel']]]]);
		$this->assertSame([], $this->ids($D, 'ARTICLE'));
	}

	public function testWhereValuesAreEscapedForOperators(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$evil = "x' OR '1'='1";
		foreach ([['Name', '='], ['Name', '!='], ['Name', 'LIKE'], ['Name', 'LIKE%%'], ['Name', 'IN'], ['ID', '=']] as [$field, $op]) {
			$D = $this->get($db, ['ARTICLE' => ['W' => [0 => [$field => [$op => $evil]]]]]);
			$expected = ($op === '!=') ? 3 : 0;
			$this->assertSame($expected, count($this->ids($D, 'ARTICLE')), "{$field} {$op}");
		}
		$this->assertSame(3, (int)$this->rawValue('SELECT count(*) FROM wp_data'));
	}

	// ------------------------------------------------------------------ get_object: Sortierung / Limit

	public function testOrderByAttributeAscDesc(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['O' => [0 => ['Name' => 'DESC']]]]);
		$this->assertSame(['A3', 'A2', 'A1'], $this->ids($D, 'ARTICLE'));
		$D = $this->get($db, ['ARTICLE' => ['O' => [0 => ['Name' => 'ASC']]]]);
		$this->assertSame(['A1', 'A2', 'A3'], $this->ids($D, 'ARTICLE'));
	}

	public function testOrderByNumberSortsNumerically(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['O' => [0 => ['Price' => 'ASC']]]]);
		$this->assertSame(['A2', 'A1', 'A3'], $this->ids($D, 'ARTICLE'), '5 < 10 < 100');
		$D = $this->get($db, ['ARTICLE' => ['O' => [0 => ['Price' => 'DESC']]]]);
		$this->assertSame(['A3', 'A1', 'A2'], $this->ids($D, 'ARTICLE'));
	}

	public function testOrderById(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['O' => [0 => ['ID' => 'DESC']]]]);
		$this->assertSame(['A3', 'A2', 'A1'], $this->ids($D, 'ARTICLE'));
	}

	public function testOrderByMultipleColumns(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['O' => [0 => ['Active' => 'DESC'], 1 => ['Name' => 'DESC']]]]);
		$this->assertSame(['A3', 'A1', 'A2'], $this->ids($D, 'ARTICLE'));
	}

	public function testOrderByTimestamps(): void {
		$db = $this->db();
		$this->seedArticles($db);
		foreach (['UTIMESTAMP', 'ITIMESTAMP'] as $col) {
			$D = $this->get($db, ['ARTICLE' => ['O' => [0 => [$col => 'ASC']]]]);
			$this->assertSameValues(['A1', 'A2', 'A3'], $this->ids($D, 'ARTICLE'), $col);
		}
	}

	public function testOrderInNestedLevel(): void {
		$db = $this->db();
		$this->seedPlatforms($db);
		$D = $this->get($db, ['PLATFORM' => ['ARTICLE' => ['O' => [0 => ['Name' => 'ASC']]]]]);
		$this->assertSame(['x2', 'x1'], $this->ids($D['PLATFORM']['D']['P1'], 'ARTICLE'), 'Y < Z');
	}

	public function testOrderCombinedWithWhere(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Active' => 1]], 'O' => [0 => ['Name' => 'DESC']]]]);
		$this->assertSame(['A3', 'A1'], $this->ids($D, 'ARTICLE'));
	}

	public function testLimitStepOnly(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['L' => ['STEP' => 2]]]);
		$this->assertSame(['A1', 'A2'], $this->ids($D, 'ARTICLE'));
		$this->assertSame(3, $D['ARTICLE']['COUNT']);
	}

	public function testLimitStartAndStep(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['L' => ['START' => 1, 'STEP' => 1]]]);
		$this->assertSame(['A2'], $this->ids($D, 'ARTICLE'));
		$D = $this->get($db, ['ARTICLE' => ['L' => ['START' => 2, 'STEP' => 5]]]);
		$this->assertSame(['A3'], $this->ids($D, 'ARTICLE'));
		$D = $this->get($db, ['ARTICLE' => ['L' => ['START' => 5, 'STEP' => 5]]]);
		$this->assertSame([], $this->ids($D, 'ARTICLE'));
	}

	public function testPaginationWithOrder(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$page = fn(int $start) => $this->ids($this->get($db, ['ARTICLE' => ['O' => [0 => ['Name' => 'DESC']], 'L' => ['START' => $start, 'STEP' => 2]]]), 'ARTICLE');
		$this->assertSame(['A3', 'A2'], $page(0));
		$this->assertSame(['A1'], $page(2));
	}

	// ------------------------------------------------------------------ get_object: Kind-Ebenen

	public function testLoadChildLevelsOnlyWhenRequested(): void {
		$db = $this->db();
		$this->seedPlatforms($db);
		$D = $this->get($db, ['PLATFORM' => []]);
		$this->assertArrayNotHasKey('LANGUAGE', $D['PLATFORM']['D']['P1']);
		$this->assertArrayNotHasKey('ARTICLE', $D['PLATFORM']['D']['P1']);

		$D = $this->get($db, ['PLATFORM' => ['LANGUAGE' => []]]);
		$this->assertSame(['DE'], $this->ids($D['PLATFORM']['D']['P1'], 'LANGUAGE'));
		$this->assertArrayNotHasKey('ATTRIBUTE', $D['PLATFORM']['D']['P1']['LANGUAGE']['D']['DE']);
	}

	public function testChildFilterDoesNotFilterParents(): void {
		$db = $this->db();
		$this->seedPlatforms($db);
		$D = $this->get($db, ['PLATFORM' => ['ARTICLE' => ['W' => [0 => ['Name' => 'Q']]]]]);
		$this->assertSameValues(['P1', 'P2'], $this->ids($D, 'PLATFORM'));
		$this->assertSame([], $this->ids($D['PLATFORM']['D']['P1'], 'ARTICLE'));
		$this->assertSame(['x3'], $this->ids($D['PLATFORM']['D']['P2'], 'ARTICLE'));
	}

	public function testParentFilterByChildCondition(): void {
		$db = $this->db();
		$this->seedPlatforms($db);
		$D = $this->get($db, ['PLATFORM' => ['W' => [0 => ['ARTICLE' => ['W' => [0 => ['Name' => 'Q']]]]], 'ARTICLE' => []]]);
		$this->assertSame(['P2'], $this->ids($D, 'PLATFORM'));
		$this->assertSame(['x3'], $this->ids($D['PLATFORM']['D']['P2'], 'ARTICLE'));
	}

	public function testFilterOnParentAndChildById(): void {
		$db = $this->db();
		$this->seedPlatforms($db);
		$D = $this->get($db, ['PLATFORM' => ['W' => [0 => ['ID' => 'P1']], 'ARTICLE' => ['W' => [0 => ['ID' => 'x2']]]]]);
		$this->assertSame(['P1'], $this->ids($D, 'PLATFORM'));
		$this->assertSame(['x2'], $this->ids($D['PLATFORM']['D']['P1'], 'ARTICLE'));
		$this->assertSame(2, $D['PLATFORM']['D']['P1']['ARTICLE']['COUNT']);
	}

	public function testChildCountPerParent(): void {
		$db = $this->db();
		$this->seedPlatforms($db);
		$D = $this->get($db, ['PLATFORM' => ['ARTICLE' => []]]);
		$this->assertSame(2, $D['PLATFORM']['COUNT']);
		$this->assertSame(2, $D['PLATFORM']['D']['P1']['ARTICLE']['COUNT']);
		$this->assertSame(1, $D['PLATFORM']['D']['P2']['ARTICLE']['COUNT']);
	}

	public function testDeepChildWithFourLevels(): void {
		$db = $this->db();
		$D['PLATFORM']['D']['P1']['ARTICLE']['D']['a1']['Name'] = 'A';
		$D['PLATFORM']['D']['P1']['ARTICLE']['D']['a1']['LANGUAGE']['D']['DE']['Active'] = 1;
		$D['PLATFORM']['D']['P1']['ARTICLE']['D']['a1']['LANGUAGE']['D']['DE']['ATTRIBUTE']['D']['c']['Value'] = 'Rot';
		$this->set($db, $D);
		$R = $this->get($db, ['PLATFORM' => ['ARTICLE' => ['LANGUAGE' => ['ATTRIBUTE' => []]]]]);
		$this->assertSame('Rot', $R['PLATFORM']['D']['P1']['ARTICLE']['D']['a1']['LANGUAGE']['D']['DE']['ATTRIBUTE']['D']['c']['Value']);
	}

	// ------------------------------------------------------------------ Cache (L2)

	public function testQueryResultIsStoredInCache(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$this->get($db, ['ARTICLE' => []]);
		$this->assertTrue((int)$this->rawValue("SELECT count(*) FROM wp_cache WHERE tag LIKE 'ARTICLE/%'", $this->file . '.cache') >= 1);
	}

	public function testRepeatedQueryReturnsSameResult(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$F = ['ARTICLE' => ['W' => [0 => ['Active' => 1]], 'O' => [0 => ['Name' => 'DESC']]]];
		$first = $this->get($db, $F);
		$second = $this->get($db, $F);
		$this->assertSame($first, $second);
	}

	public function testDifferentFiltersUseDifferentCacheEntries(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$a = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => 'A1']]]]);
		$b = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => 'A2']]]]);
		$a2 = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => 'A1']]]]);
		$this->assertSame(['A2'], $this->ids($b, 'ARTICLE'));
		$this->assertSame($a, $a2);
	}

	public function testCacheIsInvalidatedBySet(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$F = ['ARTICLE' => []];
		$this->get($db, $F); // füllt Cache

		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Name' => 'Apfel2'], 'A4' => ['Name' => 'Dattel']]]]);
		$D = $this->get($db, $F);
		$this->assertSame('Apfel2', $D['ARTICLE']['D']['A1']['Name']);
		$this->assertSame(4, count($this->ids($D, 'ARTICLE')));
		$this->assertSame(4, $D['ARTICLE']['COUNT']);
	}

	public function testCacheIsInvalidatedByDelete(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$F = ['ARTICLE' => []];
		$this->get($db, $F);
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => null]]]);
		$D = $this->get($db, $F);
		$this->assertSameValues(['A2', 'A3'], $this->ids($D, 'ARTICLE'));
	}

	public function testCacheIsInvalidatedForNestedChanges(): void {
		$db = $this->db();
		$this->seedPlatforms($db);
		$F = ['PLATFORM' => ['ARTICLE' => []]];
		$this->get($db, $F);
		$this->set($db, ['PLATFORM' => ['D' => ['P1' => ['ARTICLE' => ['D' => ['x1' => ['Name' => 'Neu']]]]]]]);
		$D = $this->get($db, $F);
		$this->assertSame('Neu', $D['PLATFORM']['D']['P1']['ARTICLE']['D']['x1']['Name']);
	}

	public function testCacheIsSharedBetweenInstancesOnSameDatabase(): void {
		$a = $this->db();
		$this->seedArticles($a);
		$file = $this->file;
		$b = $this->db($file);
		$F = ['ARTICLE' => []];
		$this->assertSame(3, count($this->ids($this->get($b, $F), 'ARTICLE')));

		$this->set($a, ['ARTICLE' => ['D' => ['A9' => ['Name' => 'Neu']]]]);
		$this->assertSame(4, count($this->ids($this->get($b, $F), 'ARTICLE')));
	}

	// ------------------------------------------------------------------ Backup

	public function testBackupCreatesZipArchive(): void {
		if (!class_exists('ZipArchive')) { $this->skip('Extension zip nicht verfügbar'); }
		$dest = $this->root . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR;
		$db = $this->db(null, ['BACKUP' => ['DestinationPath' => $dest]]);
		$this->seedArticles($db);
		$db->backup();

		$zips = glob($dest . '*.zip');
		$this->assertSame(1, count($zips));
		$this->assertSame([], glob($dest . '*.db'), 'unkomprimierte Kopie wird entfernt');

		$zip = new ZipArchive();
		$this->assertTrue($zip->open($zips[0]) === true);
		$this->assertSame(1, $zip->numFiles);
		$this->assertTrue(str_ends_with($zip->getNameIndex(0), 'test.db'));
		$zip->close();
	}

	public function testBackupWithPasswordIsEncrypted(): void {
		if (!class_exists('ZipArchive')) { $this->skip('Extension zip nicht verfügbar'); }
		$dest = $this->root . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR;
		$db = $this->db(null, ['BACKUP' => ['DestinationPath' => $dest, 'BackupPassword' => 'geheim']]);
		$this->seedArticles($db);
		$db->backup();

		$zips = glob($dest . '*.zip');
		$this->assertSame(1, count($zips));
		$zip = new ZipArchive();
		$zip->open($zips[0]);
		$stat = $zip->statIndex(0);
		$this->assertTrue($stat['encryption_method'] !== ZipArchive::EM_NONE, 'Archiv muss verschlüsselt sein');
		$zip->close();
	}

	public function testBackupContainsData(): void {
		if (!class_exists('ZipArchive')) { $this->skip('Extension zip nicht verfügbar'); }
		$dest = $this->root . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR;
		$db = $this->db(null, ['BACKUP' => ['DestinationPath' => $dest]]);
		$this->seedArticles($db);
		$db->backup();

		$zip = new ZipArchive();
		$zip->open(glob($dest . '*.zip')[0]);
		$zip->extractTo($dest . 'x');
		$zip->close();
		$extracted = glob($dest . 'x' . DIRECTORY_SEPARATOR . '*.db')[0];
		$this->assertSame(3, (int)$this->rawValue('SELECT count(*) FROM wp_data', $extracted));
	}

	// ------------------------------------------------------------------ Bekannte Probleme (gewünschtes Verhalten)

	/** Vergleichsoperatoren arbeiten auf Textwerten: '10' > '9' ist false. Doku (3.4) nennt Price['>'] = 10. */
	public function knownNumericComparisonOperators(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Price' => ['>' => '9']]]]]);
		$this->assertSameValues(['A1', 'A3'], $this->ids($D, 'ARTICLE'));
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Price' => ['<' => '50']]]]]);
		$this->assertSameValues(['A1', 'A2'], $this->ids($D, 'ARTICLE'));
	}

	/** Filterwert ohne Operator wird nicht escaped: Wert mit Apostroph bricht das SQL. */
	public function knownWhereValueWithQuoteWithoutOperator(): void {
		$db = $this->db();
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Name' => "O'Brien"], 'A2' => ['Name' => 'Smith']]]]);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Name' => "O'Brien"]]]]);
		$this->assertSame(['A1'], $this->ids($D, 'ARTICLE'));
	}

	/** SQL-Injection über ID-Filter ohne Operator. */
	public function knownWhereIdInjectionWithoutOperator(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['ID' => ["x') OR ('1'='1"]]]]]);
		$this->assertSame([], $this->ids($D, 'ARTICLE'));
	}

	/** SQL-Injection über Attribut-Filter ohne Operator. */
	public function knownWhereAttributeInjectionWithoutOperator(): void {
		$db = $this->db();
		$this->seedArticles($db);
		$D = $this->get($db, ['ARTICLE' => ['W' => [0 => ['Name' => ["x') OR ('1'='1"]]]]]);
		$this->assertSame([], $this->ids($D, 'ARTICLE'));
	}

	/** LIMIT gilt über alle Eltern hinweg statt je Elternelement: P1 kann dadurch leer bleiben (Doku 3.8: STEP = Anzahl). */
	public function knownChildLimitAppliesPerParent(): void {
		$db = $this->db();
		$this->seedPlatforms($db);
		$D = $this->get($db, ['PLATFORM' => ['ARTICLE' => ['L' => ['STEP' => 1]]]]);
		$this->assertSame(1, count($this->ids($D['PLATFORM']['D']['P1'], 'ARTICLE')));
		$this->assertSame(1, count($this->ids($D['PLATFORM']['D']['P2'], 'ARTICLE')));
	}

	/**
	 * _delete_object() hält $D_DATA als static und leert nur die genutzte Ebene. Nach dem Löschen eines tiefen
	 * Baums werden bei einem späteren flachen Löschen veraltete tiefere Ebenen erneut ausgeführt und löschen
	 * neu angelegte Datensätze mit.
	 */
	public function knownDeleteDoesNotReuseStateOfPreviousDelete(): void {
		$db = $this->db();
		$this->seedPlatforms($db);
		$this->set($db, ['PLATFORM' => ['D' => ['P1' => null]]]); // tiefer Baum (3 Ebenen)
		$this->seedPlatforms($db);                                  // P1 samt Kindern neu anlegen
		$this->set($db, ['PLATFORM' => ['D' => ['P1' => ['ARTICLE' => ['D' => ['x1' => null]]]]]]); // flaches Löschen

		$D = $this->get($db, ['PLATFORM' => ['LANGUAGE' => ['ATTRIBUTE' => []], 'ARTICLE' => []]]);
		$this->assertSame(['x2'], $this->ids($D['PLATFORM']['D']['P1'], 'ARTICLE'), 'x2 darf nicht mitgelöscht werden');
		$this->assertSame(['DE'], $this->ids($D['PLATFORM']['D']['P1'], 'LANGUAGE'));
	}

	/** Dokumentiert in @todo: leerer String soll als Attributwert speicherbar sein (aktuell wird das Attribut entfernt). */
	public function knownEmptyStringAttributeCanBeStored(): void {
		$db = $this->db();
		$this->set($db, ['ARTICLE' => ['D' => ['A1' => ['Name' => '']]]]);
		$D = $this->get($db, ['ARTICLE' => []]);
		$this->assertSame('', $D['ARTICLE']['D']['A1']['Name'] ?? null);
	}
}

// ---------------------------------------------------------------------- Runner

/** Führt genau einen Test im aktuellen Prozess aus und gibt das Ergebnis als eine JSON-Zeile aus. */
function run_single(string $m): int {
	$t = new CDataTest();
	$status = 'pass'; $msg = '';
	try {
		$t->setUp();
		$t->$m();
	} catch (TestSkipped $e) {
		$status = 'skip'; $msg = $e->getMessage();
	} catch (Throwable $e) {
		$status = 'fail';
		$msg = ($e instanceof AssertionFailed ? '' : get_class($e) . ': ') . $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']';
	}
	try { $t->tearDown(); } catch (Throwable $e) {}
	echo "\n@@RESULT@@" . json_encode([
		'status' => $status, 'msg' => $msg, 'assertions' => $t->assertions, 'notices' => array_keys($GLOBALS['LIB_NOTICES']),
	], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
	return 0;
}

/** Jeder Test läuft in einem eigenen PHP-Prozess, da CData static-Zustand (set_object/_delete_object) hält. */
function run_isolated(string $m): array {
	$cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg(__FILE__) . ' --single=' . escapeshellarg($m) . ' 2>&1';
	$out = (string)shell_exec($cmd);
	$pos = strrpos($out, '@@RESULT@@');
	if ($pos === false) {
		return ['status' => 'fail', 'msg' => 'Prozess ohne Ergebnis: ' . trim(substr($out, 0, 500)), 'assertions' => 0, 'notices' => []];
	}
	return json_decode(trim(substr($out, $pos + 10)), true);
}

function run_tests(array $argv): int {
	foreach ($argv as $a) {
		if (str_starts_with($a, '--single=')) { return run_single(substr($a, 9)); }
	}
	$strict = in_array('--strict', $argv, true);
	$filter = null;
	foreach (array_slice($argv, 1) as $a) { if ($a[0] !== '-') { $filter = $a; } }

	$methods = array_filter(get_class_methods('CDataTest'), fn($m) => str_starts_with($m, 'test') || str_starts_with($m, 'known'));
	if ($filter !== null) { $methods = array_filter($methods, fn($m) => stripos($m, $filter) !== false); }

	$c = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'known' => 0, 'fixed' => 0];
	$assertions = 0;
	$failures = [];
	$start = microtime(true);

	foreach ($methods as $m) {
		$known = str_starts_with($m, 'known');
		$r = run_isolated($m);
		$status = $r['status']; $msg = $r['msg'];
		$assertions += $r['assertions'];
		foreach ($r['notices'] as $n) { $GLOBALS['LIB_NOTICES'][$n] = true; }

		if ($known) {
			if ($status === 'pass') { $status = 'fixed'; }
			elseif ($status === 'fail') { $status = $strict ? 'fail' : 'known'; }
		}
		$c[$status]++;

		$label = ['pass' => 'PASS ', 'fail' => 'FAIL ', 'skip' => 'SKIP ', 'known' => 'KNOWN', 'fixed' => 'FIXED'][$status];
		echo "[{$label}] {$m}" . ($msg !== '' && $status !== 'pass' ? "\n        {$msg}" : '') . "\n";
		if ($status === 'fail') { $failures[] = $m; }
	}

	printf("\n%d Tests, %d Assertions, %d bestanden, %d fehlgeschlagen, %d übersprungen, %d bekannte Probleme, %d behoben (%.2fs)\n",
		array_sum($c), $assertions, $c['pass'], $c['fail'], $c['skip'], $c['known'], $c['fixed'], microtime(true) - $start);
	if ($c['fixed'] > 0) { echo "Hinweis: FIXED-Tests sollten in 'test...' umbenannt werden.\n"; }

	if ($GLOBALS['LIB_NOTICES']) {
		echo "\nHinweise/Warnungen aus lib/ (" . count($GLOBALS['LIB_NOTICES']) . " verschiedene):\n";
		foreach (array_keys($GLOBALS['LIB_NOTICES']) as $n) { echo "  - {$n}\n"; }
	}
	return $failures ? 1 : 0;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
	exit(run_tests($argv));
}
