<?php

namespace XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null smilie_id
 * @property string title
 * @property string smilie_text
 * @property string image_url
 * @property string image_url_2x
 * @property bool sprite_mode
 * @property array sprite_params
 * @property int smilie_category_id
 * @property int display_order
 * @property bool display_in_editor
 *
 * GETTERS
 * @property array|bool smilie_text_options
 *
 * RELATIONS
 * @property \XF\Entity\SmilieCategory Category
 */
class Smilie extends Entity
{
	/**
	 * @return array|bool
	 */
	public function getSmilieTextOptions()
	{
		return preg_split('/\r?\n/', $this->smilie_text, -1, PREG_SPLIT_NO_EMPTY);
	}

	public function getSpriteCss()
	{
		if (!$this->sprite_mode || empty($this->sprite_params))
		{
			return '';
		}

		$params = $this->sprite_params;
		$w = isset($params['w']) ? intval($params['w']) : 0;
		$h = isset($params['h']) ? intval($params['h']) : 0;
		$x = isset($params['x']) ? intval($params['x']) : 0;
		$y = isset($params['y']) ? intval($params['y']) : 0;

		$css = sprintf(
			'width: %1$dpx; height: %2$dpx; background: url(\'%3$s\') no-repeat %4$dpx %5$dpx;',
			$w, $h, preg_replace('/["\'\r\n;}]/', '', $this->image_url), $x, $y
		);
		if (!empty($params['bs']))
		{
			$css .= ' background-size: ' . preg_replace('/["\'\r\n;}]/', '', $params['bs']);
		}

		return $css;
	}

	protected function verifySmilieText(&$smilieText)
	{
		$smilies = preg_split('/\r?\n/', $smilieText, -1, PREG_SPLIT_NO_EMPTY);
		foreach ($smilies AS $k => &$v)
		{
			$v = trim($v);
			if (!strlen($v))
			{
				unset($smilies[$k]);
			}
		}
		$smilieText = implode("\n", $smilies);

		if ($this->getOption('check_duplicate'))
		{
			if ($this->isInsert() || $smilieText != $this->getExistingValue('smilie_text'))
			{
				$id = $this->smilie_id;

				$existing = $this->getSmilieRepo()->findSmiliesByText($smilieText);
				foreach ($existing AS $text => $smilie)
				{
					if (!$id || $smilie['smilie_id'] != $id)
					{
						$this->error(\XF::phrase('smilie_replacement_text_must_be_unique_x_in_use', ['text' => $text]), 'smilie_text');
						return false;
					}
				}
			}
		}

		return true;
	}

	protected function verifySmilieCategoryId(&$smilieCategoryId)
	{
		if ($smilieCategoryId > 0)
		{
			$smilieCategory = $this->_em->find('XF:SmilieCategory', $smilieCategoryId);

			if (!$smilieCategory)
			{
				$this->error(\XF::phrase('please_enter_valid_smilie_category_id'), 'smilie_category_id');
				return false;
			}
		}

		return true;
	}

	protected function verifySpriteParams(&$spriteParams)
	{
		array_walk($spriteParams, function($value, $key)
		{
			if ($key != 'bs')
			{
				$value = intval($value);
			}
			return $value;
		});
		return true;
	}

	protected function _preSave()
	{
		if ($this->sprite_mode)
		{
			$this->image_url_2x = '';
		}
	}

	protected function _postSave()
	{
		$this->rebuildSmilieCache();
	}

	protected function _postDelete()
	{
		$this->rebuildSmilieCache();
	}

	protected function rebuildSmilieCache()
	{
		$repo = $this->getSmilieRepo();

		\XF::runOnce('smilieCache', function() use ($repo)
		{
			$repo->rebuildSmilieCache();
			$repo->rebuildSmilieSpriteCache();
		});
	}

	protected function _setupDefaults()
	{
		$this->sprite_params = ['w' => 22, 'h' => 22, 'x' => 0, 'y' => 0, 'bs' => ''];
	}

	public static function getStructure(Structure $structure)
	{
		$structure->table = 'xf_smilie';
		$structure->shortName = 'XF:Smilie';
		$structure->primaryKey = 'smilie_id';
		$structure->columns = [
			'smilie_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
			'title' => ['type' => self::STR, 'maxLength' => 50,
				'required' => 'please_enter_valid_title'
			],
			'smilie_text' => ['type' => self::STR,
				'required' => 'please_enter_valid_smilie_text'
			],
			'image_url' => ['type' => self::STR, 'maxLength' => 200,
				'required' => 'please_enter_valid_url'
			],
			'image_url_2x' => ['type' => self::STR, 'maxLength' => 200, 'default' => ''],
			'sprite_mode' => ['type' => self::BOOL, 'default' => false],
			'sprite_params' => ['type' => self::SERIALIZED_ARRAY, 'default' => []],
			'smilie_category_id' => ['type' => self::UINT],
			'display_order' => ['type' => self::UINT, 'default' => 10],
			'display_in_editor' => ['type' => self::BOOL, 'default' => true]
		];
		$structure->getters = [
			'smilie_text_options' => true
		];
		$structure->relations = [
			'Category' => [
				'type' => self::TO_ONE,
				'entity' => 'XF:SmilieCategory',
				'conditions' => 'smilie_category_id'
			]
		];
		$structure->options = [
			'check_duplicate' => true
		];

		return $structure;
	}

	/**
	 * @return \XF\Repository\Smilie
	 */
	protected function getSmilieRepo()
	{
		return $this->repository('XF:Smilie');
	}
}