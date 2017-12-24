<?php

namespace XF\SubContainer;

use XF\Container;

class Widget extends AbstractSubContainer
{
	public function initialize()
	{
		$container = $this->container;

		$container->factory('position', function($positionId, array $params, Container $c)
		{
			// TODO: Pass some page related context (selected tab, controller, action, page template etc.)

			if (!$positionId)
			{
				return [];
			}

			$widgetCache = $c['widgetCache'];
			if (!isset($widgetCache[$positionId]))
			{
				return [];
			}
			$positionWidgets = $widgetCache[$positionId];

			$widgets = [];
			foreach ($positionWidgets AS $widgetId => $widget)
			{
				$class = \XF::stringToClass($widget['definition_class'], '%s\Widget\%s');
				$class = $this->extendClass($class);

				$widgetConfig = \XF\Widget\WidgetConfig::create($widget);
				$widgets[] = $c->createObject($class, [$this->app, $widgetConfig, $params]);
			}
			return $widgets;
		}, false);

		$container->factory('widget', function($identifier, array $params, Container $c)
		{
			if (strpos($identifier, '\\') !== false)
			{
				$definitionCache = $c['widgetDefinition'];

				$groupedDefinitions = [];
				foreach ($definitionCache AS $definition)
				{
					if (isset($definition['definition_class']))
					{
						$groupedDefinitions[$definition['definition_class']] = $definition;
					}
				}

				if (!isset($groupedDefinitions[$identifier]))
				{
					throw new \InvalidArgumentException(\XF::phrase('no_widget_definition_exists_with_definition_class_of_x', ['identifier' => $identifier]));
				}
				$widget = $groupedDefinitions[$identifier];

				// Special case for an option key named _title - not an option, use it to override the title.
				if (isset($params['_title']))
				{
					$widget['title'] = $params['_title'];
					unset($params['_title']);
				}

				$widget['options'] = [];
			}
			else
			{
				$widgetCache = $c['widgetCache'];

				$groupedWidgets = [];
				foreach ($widgetCache AS $widgets)
				{
					foreach ($widgets AS $widget)
					{
						if (isset($widget['widget_key']))
						{
							$groupedWidgets[$widget['widget_key']] = $widget;
						}
					}
				}

				if (!isset($groupedWidgets[$identifier]))
				{
					throw new \InvalidArgumentException(\XF::phrase('no_widget_defined_with_widget_key_of_x', ['identifier' => $identifier]));
				}

				$widget = $groupedWidgets[$identifier];
			}

			$widget['options'] = array_replace($widget['options'], $params);

			$class = \XF::stringToClass($widget['definition_class'], '%s\Widget\%s');
			$class = $this->extendClass($class);

			// TODO: Pass some page related context (selected tab, controller, action, page template etc.)
			if (isset($params['context']))
			{
				$contextParams = $params['context'];
				unset($params['context']);
			}
			else
			{
				$contextParams = [];
			}

			$widgetConfig = \XF\Widget\WidgetConfig::create($widget);
			return $c->createObject($class, [$this->app, $widgetConfig, $contextParams]);
		}, false);

		$container['widgetCache'] = $this->fromRegistry('widgetCache',
			function(Container $c) { return $this->parent['em']->getRepository('XF:Widget')->rebuildWidgetCache(); }
		);

		$container['widgetDefinition'] = $this->fromRegistry('widgetDefinition',
			function(Container $c) { return $this->parent['em']->getRepository('XF:Widget')->rebuildWidgetDefinitionCache(); }
		);

		$container['widgetPosition'] = $this->fromRegistry('widgetPosition',
			function(Container $c) { return $this->parent['em']->getRepository('XF:Widget')->rebuildWidgetPositionCache(); }
		);
	}

	/**
	 * @param $positionId
	 *
	 * @return array|\XF\Widget\AbstractWidget[]
	 */
	public function position($positionId, array $contextParams = [])
	{
		return $this->container->create('position', $positionId, $contextParams);
	}

	/**
	 * @param $identifier
	 * @param array $options
	 *
	 * @return null|\XF\Widget\AbstractWidget
	 */
	public function widget($identifier, array $options = [])
	{
		return $this->container->create('widget', $identifier, $options);
	}
}