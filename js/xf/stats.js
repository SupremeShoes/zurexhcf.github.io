!function($, window, document, _undefined)
{
	"use strict";

	XF.Stats = XF.Element.newHandler({
		options: {
			data: '| .js-statsData',
			seriesLabels: '| .js-statsSeriesLabels',
			legend: '| .js-statsLegend',
			chart: '| .js-statsChart',
			maxTicks: 9
		},

		$chart: null,
		chart: null,
		seriesLabels: {},
		labelMap: {},
		tooltipEl: null,

		init: function()
		{
			this.$chart = XF.findRelativeIf(this.options.chart, this.$target);

			var data = {},
				$data = XF.findRelativeIf(this.options.data, this.$target),
				seriesLabels = {},
				$seriesLabels = XF.findRelativeIf(this.options.seriesLabels, this.$target);

			try
			{
				data = $.parseJSON($data.first().html()) || {};
			}
			catch (e)
			{
				console.error("Stats data not valid: ", e);
				return;
			}

			try
			{
				seriesLabels = $.parseJSON($seriesLabels.first().html()) || {};
			}
			catch (e)
			{
				console.error("Series labels not valid: ", e);
			}

			this.seriesLabels = seriesLabels;

			var chartData = this.setupChartData(data),
				chartOptions = this.setupChartOptions(chartData),
				chartResponsive = this.setupChartResponsive(chartData, chartOptions);

			this.createChart(chartData, chartOptions, chartResponsive);
		},

		setupChartData: function(data)
		{
			var labels = [],
				labelMap = {},
				series = null,
				point = 0,
				self = this;

			$.each(data, function(k, v)
			{
				var i = 0,
					averages = v.averages;

				labels.push(point);
				labelMap[point] = v.label;

				if (series == null)
				{
					series = [];
					for (var seriesType in averages)
					{
						series.push({
							name: self.seriesLabels[seriesType],
							data: []
						});
					}
				}

				for (var type in averages)
				{
					if (averages.hasOwnProperty(type))
					{
						series[i].data.push({
							x: point,
							y: averages[type]
						});
						i++;
					}
				}

				point++;
			});

			this.labelMap = labelMap;

			return {
				labels: labels,
				series: series
			};
		},

		setupChartOptions: function(chartData)
		{
			var labels = chartData.labels,
				self = this;

			return {
				fullWidth: true,
				lineSmooth: false,
				axisY: {
					onlyInteger: true,
					labelOffset: { x: 0, y: 6}
				},
				axisX: {
					type: Chartist.FixedScaleAxis,
					ticks: this.getTicks(labels, this.options.maxTicks),
					low: labels[0],
					high: labels.length ? labels[labels.length - 1] : 0,
					labelOffset: { x: 0, y: 4 },
					labelInterpolationFnc: function(value, index)
					{
						if (value >= labels[labels.length - 1])
						{
							// there isn't enough space to plot the last point
							return '\u00A0';
						}

						return self.labelMap[value];
					}
				}
			};
		},

		getTicks: function(labels, maxTicks)
		{
			var ticks = [],
				tickEvery = Math.ceil(labels.length / maxTicks);

			for (var i = 0; i < labels.length; i++)
			{
				if (i % tickEvery == 0)
				{
					ticks.push(labels[i]);
				}
			}

			return ticks;
		},

		setupChartResponsive: function(data, options)
		{
			return [
				['screen and (max-width: 800px)', {
					axisX: {
						ticks: this.getTicks(data.labels, Math.min(6, this.options.maxTicks))
					}
				}],
				['screen and (max-width: 500px)', {
					axisX: {
						ticks: this.getTicks(data.labels, Math.min(3, this.options.maxTicks))
					}
				}]
			];
		},

		createChart: function(data, options, responsive)
		{
			this.chart = new Chartist.Line(this.$chart[0], data, options, responsive);

			var $tooltipContent = $('<span />');

			this.tooltipEl = new XF.TooltipElement($tooltipContent, {
				html: true
			});

			var self = this;
			this.$chart.on('mouseenter focusin', '.ct-point', function(e)
			{
				var $point = $(e.target),
					$series = $point.closest('.ct-series'),
					seriesLabel = $series.attr('ct:series-name') || '',
					ctValue = $point.attr('ct:value').split(','),
					axisLabel = ctValue[0],
					value = ctValue[1] || 0;

				$tooltipContent.text(seriesLabel + ' - ' + self.labelMap[axisLabel] + ': ' + value);

				self.tooltipEl.setPositioner($point);
				self.tooltipEl.show();
			});
			this.$chart.on('mouseleave focusout', '.ct-point', function(e)
			{
				self.tooltipEl.hide();
			});

			var $legend = XF.findRelativeIf(this.options.legend, this.$target),
				$chart = this.$chart,
				chart = this.chart;

			if ($legend.length)
			{
				setTimeout(function()
				{
					$.each(chart.data.series, function(k, series)
					{
						var className = series.className || chart.options.classNames.series + '-' + Chartist.alphaNumerate(k),
							$el = $chart.find('.' + className).find('.ct-line, .ct-point').first(),
							$li = $('<li />'),
							stroke;

						if ($el.length)
						{
							$li.text(series.name);
							stroke = window.getComputedStyle($el[0]).getPropertyValue('stroke');
							$li.prepend($('<i />').css('background', stroke));
							$legend.append($li);
						}
					});
				}, 0);
			}
		}
	});

	XF.Element.register('stats', 'XF.Stats');
}
(jQuery, window, document);