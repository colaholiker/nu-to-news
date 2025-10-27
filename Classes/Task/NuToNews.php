<?php

declare(strict_types=1);

namespace SchachvereinBalingenEv\NuToNews\Task;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Object\ObjectManager;

use Bakame\TabularData\HtmlTable\Parser;
use Bakame\TabularData\HtmlTable\Section;
use Bakame\TabularData\HtmlTable\Table;

use TYPO3\CMS\Extbase\Mvc\Controller\CommandController;
use GeorgRinger\News\Domain\Repository\NewsRepository;

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


        $CategoryRepository = GeneralUtility::makeInstance(\GeorgRinger\News\Domain\Repository\CategoryRepository::class);
        $persistenceManager = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager::class);
        $newsRepository = GeneralUtility::makeInstance(\GeorgRinger\News\Domain\Repository\NewsRepository::class);

        $querySettings = $newsRepository->createQuery()->getQuerySettings();
        $querySettings->setStoragePageIds([self::NEWS_PID]);
        //$querySettings->setRecursive(99);

        $newsRepository->setDefaultQuerySettings($querySettings);

		$url = 'https://svw-schach.liga.nu/cgi-bin/WebObjects/nuLigaSCHACHDE.woa/wa/clubMeetings?club=12004';
		$data = ['searchType' => '1', 'searchTimeRangeFrom' => '01.01.2000', 'searchTimeRangeTo' => date('d.m.Y', time()+2592000), 'selectedTeamId' => 'WONoSelectionString', 'club' => '12004', 'searchMeetings' => 'Suchen'];

        echo "<pre>";

        // use key 'http' even if you send the request to https://...
		$options = [
			'http' => [
				'header' => "Content-type: application/x-www-form-urlencoded\r\n",
				'method' => 'POST',
				'content' => http_build_query($data),
			],
		];

		$context = stream_context_create($options);
		$result = file_get_contents($url, false, $context);

		if ($result === false) {
			/* Handle error */
		}

		#var_dump($result);

		$formatter = fn (array $record): array => array_map( function($item) {
			$item = mb_trim($item);
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


        foreach ($tableData as $index => $item) {
            if ($item[0] == 'Tag Datum Zeit') {
                continue;
            }

            //erstellen prüfen Categorien
            $categorie_name = '';
            $categorie_name .= 'nu - ';

            //*********************************
            // Categorie erstellen, finden
            //*********************************
            if (str_contains($item[7],'Balingen')) {
                $categorie_name .= $item[7];
            }
            if (str_contains($item[8],'Balingen')) {
                $categorie_name .= $item[8];
            }
            $categorie_name .= ' - ' . $item[6];


            if ($CategoryRepository->count(['title' => $categorie_name])) {
                $category = $CategoryRepository->findOneBy(['title' => $categorie_name]);
            } else {
                $category = new \GeorgRinger\News\Domain\Model\Category;
                $category->setTitle($categorie_name);
                $category->setPid(self::CATEGORY_PID);
                $category->setParent($CategoryRepository->findByUid(self::CATEGORY_PARENT));

                $CategoryRepository->add($category);
                $persistenceManager->persistAll();
            }

            //*********************
            //News Erstellen
            //*********************

            $news_hash = md5("$item[1] - $item[4] - $item[5]  - $item[6] - $item[7] - $item[8]");
            $news_title = "$item[7] - $item[8] = $item[9]";
            $news_timestamp = strtotime("$item[1] $item[2]");
            //SF Dornstetten-Pfalzgrafenweiler 4 - SV Balingen 7 = 3,5:2,5
            //$news = $newsRepository->findOneBy(['keywords' => $news_hash]);

            if ($newsRepository->count(['keywords' => $news_hash])) {
                $news = $newsRepository->findOneBy(['keywords' => $news_hash]);
                $news->setTitle($news_title);
                $news->setHidden(false);
                $news->setDeleted(false);
                $news->setDatetime($news_timestamp);
                $news->setStarttime($news_timestamp-259200);

                $newsRepository->update($news);
                $persistenceManager->persistAll();
                echo "read";
            } else {
                $news = new \GeorgRinger\News\Domain\Model\NewsDefault;
                $news->setPid(self::NEWS_PID);
                $news->setTstamp(time());
                $news->setCrdate(time());
                $news->setKeywords($news_hash);
                $news->setPathSegment($news_hash);
                $news->setBodytext('Es wurde noch kein Spielberricht hinterlegt.');
                $news->setTitle($news_title);
                $news->setHidden(false);
                $news->setDeleted(false);
                $news->setAuthor('svw.info');
                $news->setAuthorEmail('webmaster@svbalingen.de');
                $news->addCategory($category);
                $news->setDatetime($news_timestamp);
                $news->setStarttime($news_timestamp-259200);

                $newsRepository->add($news);
                $persistenceManager->persistAll();
                echo "write";
            }




            \TYPO3\CMS\Core\Utility\DebugUtility::debug($news, $news_hash);


            unset($news);
            unset($category);
            unset($category_name);
        }

        \TYPO3\CMS\Core\Utility\DebugUtility::debug($tableData, 'blub');

        echo "</pre>";


        return true;
	}

}
