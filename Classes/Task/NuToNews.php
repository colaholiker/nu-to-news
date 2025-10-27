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

use SchachvereinBalingenEv\NuToNews\Domain\Repository\CategoryRepository;


final class NuToNews extends AbstractTask
{
    const CATEGORY_PID = 7;
    const CATEGORY_PARENT = 6126;

	/**
	 * MUST be implemented by all tasks
	 */
	public function execute(): bool
	{



		# Dependency injection cannot be used in scheduler tasks
        $CategoryRepository = GeneralUtility::makeInstance(\SchachvereinBalingenEv\NuToNews\Domain\Repository\CategoryRepository::class);


		$url = 'https://svw-schach.liga.nu/cgi-bin/WebObjects/nuLigaSCHACHDE.woa/wa/clubMeetings?club=12004';
		$data = ['searchType' => '1', 'searchTimeRangeFrom' => '01.01.2000', 'searchTimeRangeTo' => '31.12.2099', 'selectedTeamId' => 'WONoSelectionString', 'club' => '12004', 'searchMeetings' => 'Suchen'];

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
                $temp_item[$item_index] = $item_item;
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

            // Categorie erstellen, finden
            if (str_contains($item[7],'Balingen')) {
                $categorie_name .= $item[7];
            }
            if (str_contains($item[8],'Balingen')) {
                $categorie_name .= $item[8];
            }
            $categorie_name .= ' - ' . $item[6];

            var_dump($categorie_name);

            if ($CategoryRepository->count(['title' => $categorie_name])) {
                $category = $CategoryRepository->findOneBy(['title' => $categorie_name]);
                echo "read";
            } else {
                $category = new \SchachvereinBalingenEv\NuToNews\Domain\Model\Category;
                $category->setTitle($categorie_name);
                $category->setPid(self::CATEGORY_PID);
                $category->setParent($CategoryRepository->findByUid(self::CATEGORY_PARENT));

                $CategoryRepository->create($category);
                $CategoryRepository->update();

                echo "write";
            }

            \TYPO3\CMS\Core\Utility\DebugUtility::debug($category, 'blub');

            unset($category);
            unset($category_name);
        }

        \TYPO3\CMS\Core\Utility\DebugUtility::debug($tableData, 'blub');

        echo "</pre>";

        return true;
	}

}
