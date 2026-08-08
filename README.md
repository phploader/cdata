## Hi everyone,
I have been diving deep into SQLite3 databases lately. During my research, I frequently read that SQLite3 is slower than traditional database systems (like MySQL or PostgreSQL) and should generally only be used for small projects with few users and minimal data.

However, my experience has been completely different. In my tests, I found that SQLite3 – when configured correctly – can actually be up to 10 times faster than MySQL. It handles large amounts of data beautifully and can easily manage multiple concurrent users and requests.

In my opinion, the greatest challenge is exercising self-restraint and not treating SQLite3 exactly like MySQL.

We often catch ourselves writing highly complex SQL queries with countless joins and sub-queries. I have come to view these deeply nested queries critically and no longer consider them a best practice for clean, performant programming. Since shifting away from that approach, I see SQLite3 from a whole new perspective.

To put this philosophy into practice, I developed a PHP class that allows you to interact with the database completely without writing manual SQL queries. It is extremely simple to use and, above all, fast. SQLite3 also brings unique advantages over other SQL databases—for instance, you can easily maintain multiple database files separated by topic within a single project.

## Beschreibung
Die Klasse Cdata wurde entwickelt, um den Umgang mit Daten und deren Speicherung unabhängig von der Datenbank zu ermöglichen. Sie sorgt dafür, dass der Code klar von der Datenbank abgegrenzt ist und vermeidet die Notwendigkeit, datenbankspezifische Befehle im Code zu verwenden.

## Pattern Beispiel: 
```
$D['PATTERN']['PLATFORM']['D']['MANUFACTURER'] = [
	'Active'		=> ['Type' => 'checkbox'],
	'ParentId'		=> ['Type' => 'id', 'ForeignKey' => 1], 
	];
```
**ForeignKey:** Beim Pattern kann = 1 übergeben werden. Dadurch kann man ein Feld als Fremdschlüssel kennzeichnen. Bei der Ausgabe wird PARENT->CHILD ausgabe generiert, so dass auch nach Fremdschlüssel selekitert wird.

### Mögliche Attribute: <!--(ToDo: Attribute umsetzen:)-->
 - Type : id, string, number
 - Min : (optional) bei string, gibt mindest Buchstaben an. bei numbers, bestimmt mindest Wert, bereich z.B: -100 oder -100.0000 dann ergibt ein float mit 4 nachkommastellen. ist Min Angegeben, so wird draus ein Pflichtfeld.
 - Max : (optional) bei string, gibt maximal Buchstaben an. bei numbers, bestimmt maximal Wert, bereich z.B: 1000 oder 100.0000 dann ergibt ein float mit 4 nachkommastellen.

# SET 

Um Daten in die Datenbank zu schreiben, muss die gleiche Array-Struktur verwendet werden. Mit der Set-Funktion können Daten erstellt, aktualisiert oder entfernt werden. Ob Daten neu angelegt oder aktualisiert werden, wird anhand der ID entschieden. Für die Aktualisierung ist es nicht notwendig, den gesamten Array-Baum zu übergeben; es genügt, lediglich die Änderungen zu übermitteln. Zum Löschen eines Datensatzes muss der Wert von "Active" auf -2 gesetzt werden. Dadurch wird der Datensatz zusammen mit seiner Unterstruktur aus der Datenbank entfernt.

Beispiel:
```
$d['WAREHOUSE']['D']['W1'] = [
	'Active'	=> 1, //['Value' => -2 ],
	'Title'		=> 'Warehouse10', //['Value' => 'Warehouse1'],
];

$d['WAREHOUSE']['D']['W1']['STORAGE']['D']['W1S1'] = [
	'Active'	=> 0,
	'Title' => 'StorageA', //Wird '' oder NULL übergeben, so wird das Attribut gelöscht. Nur nicht bei Type=ForeignKey Da werden auch leere Felder übergeben und nur bei NULL gelöscht
];

$d['WAREHOUSE']['D']['W1']['STORAGE']['D']['W1S2'] = [
	'Active'	=> 0,
	'Title' => 'StorageB',
];
$CData->set_object($d);
```


# GET 

Um die gewünschten Daten auszugeben, muss zunächst ein Filter übergeben werden. Dieser ist in einer Struktur ähnlich wie ein Pattern angelegt. Darüber hinaus können zusätzliche Filter zur Datenauswahl übermittelt werden, was im Folgenden erläutert wird.

Beispiel:	
```
$f['WAREHOUSE'] = []; // gib nur WAREHOUSE konnen aus
$f['WAREHOUSE']['STORAGE']['ARTICLE_STOCK'] = []; // Gib bis Ebene 3 die drei Knoten aus.
$CData->get_object($d,$f);
```
- Gibt Bedinungslos alle Daten aus.

### WHERE:

Ein Filter kann sowohl mit AND- als auch mit OR-Anweisungen erstellt werden. Im folgenden Beispiel werden verschiedene Möglichkeiten aufgezeigt, wie ein solcher Filter gestaltet werden kann. Zudem besteht die Möglichkeit, die Oberstruktur (auch als "Vater" bezeichnet) durch die Unterstruktur (oder "Kinder") mithilfe eines Filters zu bestimmen, um festzulegen, ob diese in den Ausgaben enthalten sein sollen.

Beispiel:
```
$f['WAREHOUSE']['W'][0]['ID'] = 'W1'; // Filtere Nach WAREHUSE ID W1
$f['WAREHOUSE']['STORAGE']['W'][0]['Active'] = [1]; // Gib nur Active STORAGE aller Warehouse aus
$f['STORAGE']['W'][0]['Title']['>'] = 'R002'; // Gib alle ab R002 aus. Möglich: [NOTIN|LIKE-%|LIKE%-|LIKE%%|>|>=|<=|<]
$f['STORAGE']['W'][0]['Title'] = ['R001','R002']; // Gib mit Tittle R001,T002 Datensätze aus
$f['WAREHOUSE']['STORAGE']['ARTICLE_STOCK']['A']['Stock'] = ['SUM','COUNT','AVG','MIN','MAX']; #Aggregate COUNT,SUM,AVG,MIN,MAX Ermittelt für das Feld Stock. Ist nur für nummerische Werte möglich
$f['PLATFORM']['SUPPLIER']['W'][0]['ARTICLE']['W'][0]['ID'] = ['A1']; #Kann Filter einer höeren Ebene auf die untere Ebene Anwenden. Z.B: wenn ein Supplier gesucht ist, dieser nur Artiekl von ID A1 beinhalte.
$f['PLATFORM']['SUPPLIER']['W'][0]['ARTICLE']['W'][0]['ATTRIBUTE']['W'][0]['ID'] = ['ATT1','ATT2']; #Gib alle Supplier diese Artikel mit Attribut-ID ATT1 oder ATT2 beinhalten.

$f['ARTICLE']['W'][0]['ATTRIBUTE']['W'][0]['ID'] = 'Title'; #Berücksichtige nur Attribute mit der ID "Title"
$f['ARTICLE']['W'][0]['ATTRIBUTE']['W'][0]['LANGUAGE']['W'][0]['Value']['LIKE%%'] = 'L'; #Gib alle "Artikel" an diese ein Attribute Title haben und den Buchstaben "L" in irgend einer Sprache.
$CData->get_object($d,$f);
```
- OR: [W][0] | [W][1] | ...  Anweisungen
- AND: [W][0] | [W][0] | ... Anweisungen
- Vergleichsoperatoren: ['W'][0]['Title']['>'] Mäglich: NOTIN|LIKE-%|LIKE%-|LIKE%%|>|>=|<=|<


### Limit:

Die Daten können pro Zweig limitiert werden, indem START und STEP festgelegt werden.

Beispiel:
```
$f['STORAGE']['L']['START'] = 1;
$f['STORAGE']['L']['STEP'] = 2;
$CData->get_object($d,$f);
```
- START: (erforderlich) Begine ab 1. Gilt für Storage, kann auch für unteren Knoten angegeben werden
- STEP: (optional) maximal 2 Datensätze ausgeben. Gilt für Storage, kann auch für unteren Knoten angegeben werden

### Sortieren:

Die Sortierung kann für jeden Zweig individuell festgelegt werden.

Beispiel:
```
$f['WHAREGOUSE]['O'][0]['Stock'] = 'DESC';
$CData->get_object($d,$f);
```
Sortieren Speziall Befehle: ID, UTIMESTAMP, ITIMESTAMP