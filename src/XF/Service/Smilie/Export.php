<?php

namespace XF\Service\Smilie;

use XF\Mvc\Entity\Finder;
use XF\Service\AbstractXmlExport;

class Export extends AbstractXmlExport
{
	public function getRootName()
	{
		return 'smilies_export';
	}

	public function export(Finder $smilies)
	{
		$document = $this->createXml();
		$rootNode = $document->createElement($this->getRootName());
		$document->appendChild($rootNode);

		$smilies = $smilies->fetch();
		if ($smilies->count())
		{
			$smiliesNode = $document->createElement('smilies');
			$smilieCategoryIds = [];
			foreach ($smilies AS $smilie)
			{
				$smilieNode = $document->createElement('smilie');

				if ($smilie['smilie_category_id'])
				{
					$smilieCategoryIds[] = $smilie['smilie_category_id'];
					$smilieNode->setAttribute('smilie_category_id', $smilie['smilie_category_id']);
				}

				$smilieNode->setAttribute('title', $smilie['title']);

				$smilieNode->appendChild($document->createElement('image_url', $smilie['image_url']));
				$smilieNode->appendChild($document->createElement('image_url_2x', $smilie['image_url_2x']));

				if ($smilie['sprite_mode'])
				{
					$spriteParamsNode = $document->createElement('sprite_params');

					foreach ($smilie['sprite_params'] AS $param => $value)
					{
						$spriteParamsNode->setAttribute($param, $value);
					}

					$smilieNode->appendChild($spriteParamsNode);
				}

				foreach (preg_split('/\r?\n/', $smilie['smilie_text'], -1, PREG_SPLIT_NO_EMPTY) AS $smilieText)
				{
					$smilieNode->appendChild($document->createElement('smilie_text', $smilieText));
				}

				$smilieNode->setAttribute('display_order', $smilie['display_order']);
				$smilieNode->setAttribute('display_in_editor', $smilie['display_in_editor']);

				$smiliesNode->appendChild($smilieNode);
			}

			$categoriesNode = $document->createElement('smilie_categories');

			$smilieCategories = $this->finder('XF:SmilieCategory')
				->with('MasterTitle')
				->order(['display_order'])
				->keyedBy('smilie_category_id')
				->where('smilie_category_id', $smilieCategoryIds);

			foreach ($smilieCategories->fetch() AS $smilieCategory)
			{
				$categoryNode = $document->createElement('smilie_category');
				$categoryNode->setAttribute('id', $smilieCategory->smilie_category_id);
				$categoryNode->setAttribute('title', $smilieCategory->MasterTitle->phrase_text);
				$categoryNode->setAttribute('display_order', $smilieCategory->display_order);

				$categoriesNode->appendChild($categoryNode);
			}

			$rootNode->appendChild($categoriesNode);
			$rootNode->appendChild($smiliesNode);

			return $document;
		}
		else
		{
			throw new \XF\PrintableException(\XF::phrase('please_select_at_least_one_smilie_to_export')->render());
		}
	}

	/**
	 * @return \XF\Repository\SmilieCategory
	 */
	protected function getSmilieCategoryRepo()
	{
		return $this->repository('XF:SmilieCategory');
	}
}