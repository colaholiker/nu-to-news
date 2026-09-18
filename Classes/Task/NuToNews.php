<?php

declare(strict_types=1);

namespace SchachvereinBalingenEv\NuToNews\Task;

use Closure;
use DateTimeImmutable;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

use GeorgRinger\News\Domain\Model\Category;
use GeorgRinger\News\Domain\Model\News;
use GeorgRinger\News\Domain\Model\NewsDefault;
use GeorgRinger\News\Domain\Repository\CategoryRepository;
use GeorgRinger\News\Domain\Repository\NewsRepository;

use Bakame\TabularData\HtmlTable\Parser;

final class NuToNews extends AbstractTask
{
    private const CATEGORY_PID = 7;
    private const CATEGORY_PARENT = 6126;
    private const NEWS_PID = 1707;

    private const CLUB_ID = '12004';
    private const MEETINGS_URL = 'https://svw-schach.liga.nu/cgi-bin/WebObjects/nuLigaSCHACHDE.woa/wa/clubMeetings';

    /** Wie weit im Voraus Paarungen abgerufen werden. */
    private const SEARCH_RANGE = '+30 days';

    /** Wie lange vor dem Spieltermin die News sichtbar wird. */
    private const NEWS_LEAD_TIME = '-3 days';

    /** Kennzeichen der eigenen Mannschaften in den Mannschaftsspalten. */
    private const OWN_TEAM_NEEDLE = 'Balingen';

    /**
     * MUST be implemented by all tasks
     */
    public function execute(): bool
    {
        $html = $this->fetchMeetings();

        if ($html === null) {
            // Abruf der NuLiga-Seite fehlgeschlagen -> Task als fehlgeschlagen melden
            return false;
        }

        $categoryRepository = GeneralUtility::makeInstance(CategoryRepository::class);
        $persistenceManager = GeneralUtility::makeInstance(PersistenceManager::class);
        $newsRepository = GeneralUtility::makeInstance(NewsRepository::class);

        $querySettings = $newsRepository->createQuery()->getQuerySettings();
        $querySettings->setStoragePageIds([self::NEWS_PID]);
        //$querySettings->setRecursive(99);

        $newsRepository->setDefaultQuerySettings($querySettings);

        $rows = $this->carryOverEmptyCells($this->parseTable($html));

        // Kategorien je Lauf zwischenspeichern: sonst wird pro Zeile erneut
        // abgefragt und eine noch nicht geschriebene Kategorie doppelt angelegt.
        $categoryCache = [];

        foreach ($rows as $row) {
            if (count($row) < 10) {
                continue;
            }

            // Kopf- und Zwischenzeilen brauchen keine gesonderte Textpruefung:
            // nur echte Paarungen haben ein parsebares Datum.
            $meetingDate = $this->parseMeetingDate($row);
            $categoryTitle = $this->categoryTitle($row);

            if ($meetingDate === null || $categoryTitle === null) {
                continue;
            }

            $category = $this->resolveCategory(
                $categoryTitle,
                $categoryRepository,
                $persistenceManager,
                $categoryCache
            );

            $newsKey = $this->newsKey($row, $meetingDate);

            $news = $newsRepository->findOneBy(['keywords' => $newsKey])
                ?? $newsRepository->findOneBy(['keywords' => $this->legacyNewsKey($row)]);

            $isNew = $news === null;

            if ($isNew) {
                $news = new NewsDefault();
                $news->setPid(self::NEWS_PID);
                $news->setTstamp(time());
                $news->setCrdate(time());
                $news->setPathSegment($newsKey);
                $news->setBodytext('Es wurde noch kein Spielbericht hinterlegt.');
                $news->setAuthor('svw.info');
                $news->setAuthorEmail('webmaster@svbalingen.de');
            }

            //SF Dornstetten-Pfalzgrafenweiler 4 - SV Balingen 7 = 3,5:2,5
            $this->applyMeetingValues(
                $news,
                $newsKey,
                "$row[7] - $row[8] = $row[9]",
                $meetingDate,
                $category
            );

            if ($isNew) {
                $newsRepository->add($news);
            } else {
                $newsRepository->update($news);
            }
        }

        // Einmal am Ende schreiben statt einmal pro Zeile.
        $persistenceManager->persistAll();

        return true;
    }

    /**
     * Laedt die Paarungsliste des Vereins von nuLiga.
     *
     * @return string|null Das HTML der Seite, oder null wenn der Abruf fehlschlug.
     */
    private function fetchMeetings(): ?string
    {
        $query = [
            'searchType' => '1',
            'searchTimeRangeFrom' => '01.01.2000',
            'searchTimeRangeTo' => (new DateTimeImmutable(self::SEARCH_RANGE))->format('d.m.Y'),
            'selectedTeamId' => 'WONoSelectionString',
            'club' => self::CLUB_ID,
            'searchMeetings' => 'Suchen',
        ];

        // use key 'http' even if you send the request to https://...
        $context = stream_context_create([
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($query),
                // Ohne Timeout haengt der Scheduler bis zum PHP-Limit, falls nuLiga nicht antwortet.
                'timeout' => 30,
            ],
        ]);

        $html = file_get_contents(self::MEETINGS_URL . '?club=' . self::CLUB_ID, false, $context);

        return $html === false ? null : $html;
    }

    /**
     * Liest die Paarungstabelle als Liste von Zeilen mit numerischen Spalten.
     */
    private function parseTable(string $html): array
    {
        $table = Parser::new()
            ->withFormatter($this->cellFormatter())
            ->tablePosition(0)
            ->parseHtml($html);

        // Der Umweg ueber JSON materialisiert nur den Iterator.
        // TODO: iterator_to_array() waere direkter, ist aber noch ungetestet.
        return json_decode(json_encode($table->getTabularData()));
    }

    /**
     * nuLiga liefert &nbsp; (U+00A0) in leeren bzw. aufgefuellten Zellen.
     * trim() entfernt nur ASCII-Whitespace, daher vorher dekodieren und alle
     * Unicode-Leerzeichen an den Raendern abschneiden.
     */
    private function cellFormatter(): Closure
    {
        return static fn (array $record): array => array_map(
            static function ($cell): string {
                $cell = html_entity_decode((string)$cell, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $cell) ?? trim($cell);
            },
            $record
        );
    }

    /**
     * nuLiga laesst Tag und Datum in Folgezeilen desselben Spieltags leer
     * (Platzhalterzellen). Diese Werte aus der vorhergehenden Zeile uebernehmen.
     */
    private function carryOverEmptyCells(array $rows): array
    {
        $carried = [];

        foreach ($rows as $index => $row) {
            if ($index !== 0) {
                $row[2] = substr($row[2], 0, 5);
            }

            foreach ($row as $column => $value) {
                if ($value === '') {
                    $value = $carried[$column] ?? '';
                    $row[$column] = $value;
                }

                if ($column === 0 || $column === 1 || $column === 2 || $column === 5) {
                    $carried[$column] = $value;
                }
            }

            $rows[$index] = $row;
        }

        return $rows;
    }

    /**
     * Das fuehrende "!" setzt die Sekunden auf 0, sonst wandert der Zeitstempel
     * bei jedem Lauf um die aktuelle Sekundenzahl.
     */
    private function parseMeetingDate(array $row): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!d.m.Y H:i', "$row[1] $row[2]");

        return $date === false ? null : $date;
    }

    /**
     * Die Saison steht nirgends in der Tabelle, wird aber fuer einen ueber
     * Saisongrenzen hinweg eindeutigen Schluessel gebraucht. Das Spieljahr
     * laeuft Herbst -> Fruehjahr.
     */
    private function season(DateTimeImmutable $date): string
    {
        $year = (int)$date->format('Y');

        return (int)$date->format('n') >= 7
            ? sprintf('%d/%d', $year, $year + 1)
            : sprintf('%d/%d', $year - 1, $year);
    }

    /**
     * @return string|null Der Kategoriename, oder null wenn keine eigene
     *                     Mannschaft an der Paarung beteiligt ist.
     */
    private function categoryTitle(array $row): ?string
    {
        // Bei Vereinsderbys stehen beide Mannschaften in der Zeile.
        $ownTeams = array_values(array_filter(
            [$row[7], $row[8]],
            static fn (string $team): bool => str_contains($team, self::OWN_TEAM_NEEDLE)
        ));

        if ($ownTeams === []) {
            return null;
        }

        return 'nu - ' . implode(' / ', $ownTeams) . ' - ' . $row[6];
    }

    /**
     * @param array $cache Kategorien dieses Laufs, wird fortgeschrieben.
     */
    private function resolveCategory(
        string $title,
        CategoryRepository $categoryRepository,
        PersistenceManager $persistenceManager,
        array &$cache
    ): Category {
        if (isset($cache[$title])) {
            return $cache[$title];
        }

        $category = $categoryRepository->findOneBy(['title' => $title]);

        if ($category === null) {
            $category = new Category();
            $category->setTitle($title);
            $category->setPid(self::CATEGORY_PID);
            $category->setParentcategory($categoryRepository->findByUid(self::CATEGORY_PARENT));

            $categoryRepository->add($category);
            // Sofort schreiben, damit die Kategorie eine UID hat, bevor sie an
            // eine News gehaengt wird. Passiert nur beim ersten Auftreten.
            $persistenceManager->persistAll();
        }

        return $cache[$title] = $category;
    }

    /**
     * Bewusst ohne Datum: wird eine Partie verlegt, soll die bestehende News
     * aktualisiert und keine zweite angelegt werden. Die Saison muss hinein,
     * weil sich Rundennummern jede Saison wiederholen.
     */
    private function newsKey(array $row, DateTimeImmutable $meetingDate): string
    {
        return md5($this->season($meetingDate) . ' - ' . $row[6] . ' - ' . $row[4] . ' - ' . $row[7] . ' - ' . $row[8]);
    }

    /**
     * Alter, datumsabhaengiger Schluessel. Nur noch fuer die einmalige Migration
     * bestehender Datensaetze - kann nach einem vollstaendigen Lauf samt dem
     * zweiten findOneBy() in execute() entfernt werden.
     */
    private function legacyNewsKey(array $row): string
    {
        return md5("$row[1] - $row[4] - $row[5]  - $row[6] - $row[7] - $row[8]");
    }

    /**
     * Setzt die Werte, die bei jedem Lauf aktuell gehalten werden. Wurde die News
     * ueber den Legacy-Schluessel gefunden, schreibt das sie zugleich auf den
     * neuen Schluessel um. pathSegment bleibt unangetastet, damit bestehende
     * URLs gueltig bleiben.
     */
    private function applyMeetingValues(
        News $news,
        string $newsKey,
        string $title,
        DateTimeImmutable $meetingDate,
        Category $category
    ): void {
        $news->setKeywords($newsKey);
        $news->setTitle($title);
        // Gewollt: solange die Paarung in nuLiga steht, soll die News sichtbar
        // sein. Im Backend geloeschte oder versteckte Eintraege werden daher bei
        // jedem Lauf wieder aktiviert.
        $news->setHidden(false);
        $news->setDeleted(false);
        $news->setDatetime($meetingDate->getTimestamp());
        $news->setStarttime($meetingDate->modify(self::NEWS_LEAD_TIME)->getTimestamp());

        if (!$news->getCategories()->contains($category)) {
            $news->addCategory($category);
        }
    }
}
