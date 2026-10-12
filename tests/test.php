<?php
#amespace wp;
include_once('lib/CData.php');
/*
function my_autoload($class_name)
{
	require_once $class_name.'.php';
}
spl_autoload_register('my_autoload');
*/
use wp\CData;
use wp\CCache;

#echo hash("crc32b", "PLATFORM").'|<br>';
$D['PATTERN']['LANGUAGE'] = [
	'Active'		=> ['Type' => 'checkbox'],
	'Title'			=> ['Type' => 'text', 'Length' => 200, 'Min' => 20, 'Max' => 200],
];
$D['PATTERN']['PLATFORM'] = [
	'Active'		=> ['Type' => 'checkbox'],
	'Title'			=> ['Type' => 'text'],
];
$D['PATTERN']['PLATFORM']['D']['LANGUAGE'] = [
	'Active'		=> ['Type' => 'checkbox'],
	'Title'			=> ['Type' => 'text', 'Length' => 200, 'Min' => 20, 'Max' => 200],
];
$D['PATTERN']['PLATFORM']['D']['LANGUAGE']['D']['ATTRIBUTE'] = [
	'Active'		=> ['Type' => 'checkbox'],
	'Title'			=> ['Type' => 'text'],
	'Text'			=> ['Type' => 'textarea'],
	'ParentId'		=> ['Type' => 'id', 'ForeignKey' => 1],
];
$D['PATTERN']['PLATFORM']['D']['ARTICLE'] = [
	'Active'		=> ['Type' => 'checkbox'],
	'Price'			=> ['Type' => 'number'],
	'Stock'			=> ['Type' => 'number'],
];
$D['PATTERN']['PLATFORM']['D']['ARTICLE']['D']['LANGUAGE'] = [
	'Active'		=> ['Type' => 'checkbox'],
];
$D['PATTERN']['PLATFORM']['D']['ARTICLE']['D']['LANGUAGE']['D']['ATTRIBUTE'] = [
	#'ATTRIBUTE'		=> ['Type' => 'id'],
	'Active'		=> ['Type' => 'checkbox'],
	'Value'			=> ['Type' => 'text'],
];

$CData = new CData(['DB'=> ['FILENAME' => 'test.db'], 'PATTERN' => $D['PATTERN'], 'BACKUP' => ['DestinationPath' => null, 'BackupPassword' => '1234'] ]);
/*
$D['LANGUAGE']['D']['DE'] = [
	'Title' => 'Englisch',
	'Active' => 0,
];
*/
$D['PLATFORM']['D']['P1']['LANGUAGE']['D']['DE'] = [
	'Title' => 'DE',
];
$D['PLATFORM']['D']['P1']['LANGUAGE']['D']['DE']['ATTRIBUTE']['D']['ATT1'] = [
	'Title' => 'ATT-p1',
];
$D['PLATFORM']['D']['P2']['LANGUAGE']['D']['DE']['ATTRIBUTE']['D']['ATT1'] = [
	'Title' => 'ATT-p2',
];
$D['PLATFORM']['D']['P1']['LANGUAGE']['D']['EN']['ATTRIBUTE']['D']['ATT3'] = [
	'Title' => '',#Attribut löschen
	'ParentId' => 'id',
];

#$[A][D][123][B][0][test][C][0][test] = 'test1'; $[A][D][abc][B][0][test][C][0][test] = 'Wert2';

/*
$D['PLATFORM']['D']['P1']['ARTICLE']['D'][0] = [
	'Price' => '11',
];
$D['PLATFORM']['D']['P1']['ARTICLE']['D'][0]['LANGUAGE']['D'][0] = [
	
	'Active' => '',
];
*/
$D['PLATFORM']['D']['P2']['ARTICLE']['D']['a1'] = [
	'Price' => '22',
];
/*
$D['PLATFORM']['D']['P2']['ARTICLE']['D'][0]['LANGUAGE']['D'][0] = [
	
	'Active' => 4,
];

$D['PLATFORM']['D']['P2']['LANGUAGE']['D']['EN'] = [
	'Title' => 'EN',
	#'Active' => 1,
];*/
#$D['PLATFORM']['D']['P2']['Active'] =
#$D['PLATFORM']['D']['P1']['LANGUAGE']['D']['DE']['Active'] = -2;
#$D['PLATFORM']['D']['P1']['LANGUAGE']['D']['DE'] = null;
#Anlegen Datensatz:
###$CData->set_object($D);
#$CData->backup();
##$CData->repair();
#Auslesen
echo "<br>AUSLESEN:<br>";

$D2['LANGUAGE'] = [];
$D2['PLATFORM'] = [];
$D2['PLATFORM']['W'][0]['ID'] = ['P1','P2'];
#$D2['PLATFORM']['W'][0]['ARTICLE']['W'][0]['ID'] = ['A1'];
#$D2['PLATFORM']['W'][0]['ARTICLE']['W'][0]['Active'] = ['1'];
$D2['PLATFORM']['LANGUAGE']['ATTRIBUTE'] = [];
#$D2['PLATFORM']['W'][0]['ID'] = ['P1'];
#$D2['PLATFORM']['LANGUAGE']['W'][0]['ID'] = ['DE'];
$D2['PLATFORM']['SUPPLIER']['ARTICLE'] = [];
$D2['PLATFORM']['ARTICLE'] = [];
#$D2['PLATFORM']['ARTICLE']['W'][0]['ID'] = ['A1','A2'];
#$D2['PLATFORM']['ARTICLE']['W'][0]['Active'] = ['1'];
#$D2['PLATFORM']['ARTICLE']['W'][1]['Price'] = ['119'];
#$D2['PLATFORM']['ARTICLE']['W'][0]['Price|LIKE%%'] = ['119'];
$D2['PLATFORM']['ARTICLE']['LANGUAGE']['ATTRIBUTE'] = [];
#$D2['PLATFORM']['ARTICLE']['LANGUAGE']['ATTRIBUTE']['L'] = ['START' => 0, 'STEP' => 3];
#$D2['PLATFORM']['ARTICLE']['W'][0]['LANGUAGE']['W'][0]['ID'] = ['DE'];
#print_R($D2);echo "<br>";

##$d['TEST'] = 42;
##$d['PLATFORM']['D'][42]['Title'] = "Test42";
##$d['PLATFORM']['D'][1]['Title'] = "Test421";

#$D2['PLATFORM']['ARTICLE']['W'][0]['ID'] = 'A1'; #ToDo: Das funktioniert nicht!!!


$CData->get_object($d,$D2);
echo '<pre>';
print_r($d);
echo '</pre><br> =================== <br>';

