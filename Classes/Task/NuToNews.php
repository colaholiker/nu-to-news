<?php

declare(strict_types=1);

namespace SchachvereinBalingenEv\NuToNews\Task;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

use Bakame\TabularData\HtmlTable\Parser;

final class NuToNews extends AbstractTask
{
    const CATEGORY_PID = 7;
    const CATEGORY_PARENT = 6126;
    const NEWS_PID = 1707;

	/**
	 * MUST be implemented by all tasks
	 */
	public function execute(): bool
	{


        $categoryRepository = GeneralUtility::makeInstance(\GeorgRinger\News\Domain\Repository\CategoryRepository::class);
        $persistenceManager = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager::class);
        $newsRepository = GeneralUtility::makeInstance(\GeorgRinger\News\Domain\Repository\NewsRepository::class);

        $querySettings = $newsRepository->createQuery()->getQuerySettings();
        $querySettings->setStoragePageIds([self::NEWS_PID]);
        //$querySettings->setRecursive(99);

        $newsRepository->setDefaultQuerySettings($querySettings);

		$url = 'https://svw-schach.liga.nu/cgi-bin/WebObjects/nuLigaSCHACHDE.woa/wa/clubMeetings?club=12004';
		$data = ['searchType' => '1', 'searchTimeRangeFrom' => '01.01.2000', 'searchTimeRangeTo' => date('d.m.Y', time()+2592000), 'selectedTeamId' => 'WONoSelectionString', 'club' => '12004', 'searchMeetings' => 'Suchen'];

        // use key 'http' even if you send the request to https://...
		$options = [
			'http' => [
				'header' => "Content-type: application/x-www-form-urlencoded\r\n",
				'method' => 'POST',
				'content' => http_build_query($data),
				// Ohne Timeout haengt der Scheduler bis zum PHP-Limit, falls nuLiga nicht antwortet.
				'timeout' => 30,
			],
		];

		$context = stream_context_create($options);
		$result = file_get_contents($url, false, $context);

		if ($result === false) {
			// Abruf der NuLiga-Seite fehlgeschlagen -> Task als fehlgeschlagen melden
			return false;
		}

		$formatter = fn (array $record): array => array_map( function($item) {
			// nuLiga liefert &nbsp; (U+00A0) in leeren bzw. aufgefuellten Zellen.
			// trim() entfernt nur ASCII-Whitespace, daher vorher dekodieren und
			// alle Unicode-Leerzeichen an den Raendern abschneiden.
			$item = html_entity_decode((string)$item, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$item = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $item) ?? trim($item);
			return $item;
		}, $record );

		$table = Parser::new()
			->withFormatter($formatter)
			->tablePosition(0)
			->parseHtml($result);

		$tableData = $table->getTabularData();

        $tableData = json_decode(json_encode($tableData));

		foreach ($tableData as $index => &$item) {

            if ($index != 0) {
                $item[2] = substr($item[2], 0, 5);
            }

            //leere Zeilen durch den Termin eine Zeile vorher ersetzen
            foreach ($item as $item_index => &$item_item) {
                if ($item_item == '') {
                    if (isset($temp_item[$item_index])) {
                        $item_item = $temp_item[$item_index];
                    } else {
                        $item_item = '';
                    }
                }

                if (($item_index == 0) || ($item_index == 1) || ($item_index == 2) || ($item_index == 5)) {
                    $temp_item[$item_index] = $item_item;
                }
            }
            unset($item_item);
        }
        unset($item);


        // Kategorien je Lauf zwischenspeichern: sonst wird pro Zeile erneut
        // abgefragt und eine noch nicht geschriebene Kategorie doppelt angelegt.
        $categoryCache = [];

        foreach ($tableData as $item) {
            if (count($item) < 10) {
                continue;
            }

            // Kopf- und Zwischenzeilen brauchen keine gesonderte Textpruefung:
            // nur echte Paarungen haben ein parsebares Datum.
            // Das fuehrende "!" setzt die Sekunden auf 0, sonst wandert der
            // Zeitstempel bei jedem Lauf um die aktuelle Sekundenzahl.
            $meetingDate = \DateTimeImmutable::createFromFormat('!d.m.Y H:i', "$item[1] $item[2]");
            if ($meetingDate === false) {
                continue;
            }

            // Die Saison steht nirgends in der Tabelle, wird aber gebraucht, damit
            // der Schluessel ueber Saisongrenzen hinweg eindeutig bleibt.
            // Spieljahr laeuft Herbst -> Fruehjahr.
            $year = (int)$meetingDate->format('Y');
            $season = (int)$meetingDate->format('n') >= 7
                ? sprintf('%d/%d', $year, $year + 1)
                : sprintf('%d/%d', $year - 1, $year);

            //*********************************
            // Categorie erstellen, finden
            //*********************************
            // Bei Vereinsderbys stehen beide Mannschaften in der Zeile.
            $ownTeams = array_values(array_filter(
                [$item[7], $item[8]],
                static fn (string $team): bool => str_contains($team, 'Balingen')
            ));

            if ($ownTeams === []) {
                // Keine eigene Mannschaft beteiligt -> keine Kategorie, keine News.
                continue;
            }

            $categoryName = 'nu - ' . implode(' / ', $ownTeams) . ' - ' . $item[6];

            if (isset($categoryCache[$categoryName])) {
                $category = $categoryCache[$categoryName];
            } else {
                $category = $categoryRepository->findOneBy(['title' => $categoryName]);

                if ($category === null) {
                    $category = new \GeorgRinger\News\Domain\Model\Category();
                    $category->setTitle($categoryName);
                    $category->setPid(self::CATEGORY_PID);
                    $category->setParentcategory($categoryRepository->findByUid(self::CATEGORY_PARENT));

                    $categoryRepository->add($category);
                    // Sofort schreiben, damit die Kategorie eine UID hat, bevor
                    // sie an eine News gehaengt wird. Passiert nur beim ersten Mal.
                    $persistenceManager->persistAll();
                }

                $categoryCache[$categoryName] = $category;
            }

            //*********************
            //News Erstellen
            //*********************

            // Bewusst ohne Datum: wird eine Partie verlegt, soll die bestehende
            // News aktualisiert und keine zweite angelegt werden.
            $newsHash = md5($season . ' - ' . $item[6] . ' - ' . $item[4] . ' - ' . $item[7] . ' - ' . $item[8]);
            // Alter, datumsabhaengiger Schluessel. Nur noch fuer die einmalige
            // Migration bestehender Datensaetze - kann nach einem vollstaendigen
            // Lauf samt zweitem findOneBy() entfernt werden.
            $legacyHash = md5("$item[1] - $item[4] - $item[5]  - $item[6] - $item[7] - $item[8]");

            $newsTitle = "$item[7] - $item[8] = $item[9]";
            //SF Dornstetten-Pfalzgrafenweiler 4 - SV Balingen 7 = 3,5:2,5
            $newsTimestamp = $meetingDate->getTimestamp();
            $startTimestamp = $meetingDate->modify('-3 days')->getTimestamp();

            $news = $newsRepository->findOneBy(['keywords' => $newsHash])
                ?? $newsRepository->findOneBy(['keywords' => $legacyHash]);

            if ($news !== null) {
                // Migration: Datensatz auf den neuen Schluessel umschreiben.
                // pathSegment bleibt unangetastet, damit bestehende URLs gueltig bleiben.
                $news->setKeywords($newsHash);
                $news->setTitle($newsTitle);
                $news->setHidden(false);
                $news->setDeleted(false);
                $news->setDatetime($newsTimestamp);
                $news->setStarttime($startTimestamp);

                if (!$news->getCategories()->contains($category)) {
                    $news->addCategory($category);
                }

                $newsRepository->update($news);
            } else {
                $news = new \GeorgRinger\News\Domain\Model\NewsDefault();
                $news->setPid(self::NEWS_PID);
                $news->setTstamp(time());
                $news->setCrdate(time());
                $news->setKeywords($newsHash);
                $news->setPathSegment($newsHash);
                $news->setBodytext('Es wurde noch kein Spielbericht hinterlegt.');
                $news->setTitle($newsTitle);
                $news->setHidden(false);
                $news->setDeleted(false);
                $news->setAuthor('svw.info');
                $news->setAuthorEmail('webmaster@svbalingen.de');
                $news->addCategory($category);
                $news->setDatetime($newsTimestamp);
                $news->setStarttime($startTimestamp);

                $newsRepository->add($news);
            }

            unset($news, $category);
        }

        // Einmal am Ende schreiben statt einmal pro Zeile.
        $persistenceManager->persistAll();

        return true;
	}

}
