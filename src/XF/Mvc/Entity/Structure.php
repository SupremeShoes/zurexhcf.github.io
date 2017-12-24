<?php

namespace XF\Mvc\Entity;

class Structure
{
	public $shortName;
	public $contentType;

	public $table;
	public $primaryKey;
	public $columns = [];
	public $relations = [];
	public $getters = [];
	public $defaultWith = [];
	public $options = [];
	public $behaviors = [];

	// column props: autoIncrement, writeOnce, readOnly, type, nullable, verify, default, required
	// column validations based on type: min, max, forced, maxLength, allowedValues
}