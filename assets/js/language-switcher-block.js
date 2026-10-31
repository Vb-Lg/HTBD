/**
 * Editor registration for the HTBD language switcher block.
 *
 * The block is rendered on the server, so this file only needs to describe
 * the block and its controls. It is plain ES5 so the plugin needs no build
 * step.
 */
(function (blocks, element, blockEditor, components, i18n) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType('htbd/language-switcher', {
		apiVersion: 2,
		title: __('HTBD language switcher', 'hyx-translator-for-baidu-translate'),
		description: __('Links to this page in every configured language.', 'hyx-translator-for-baidu-translate'),
		icon: 'translation',
		category: 'widgets',
		attributes: {
			format: {
				type: 'string',
				default: 'list'
			},
			showCurrent: {
				type: 'boolean',
				default: true
			}
		},
		edit: function (props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el(
				'div',
				{ className: props.className },
				el(
					blockEditor.InspectorControls,
					{},
					el(
						components.PanelBody,
						{ title: __('Settings', 'hyx-translator-for-baidu-translate') },
						el(components.SelectControl, {
							label: __('Format', 'hyx-translator-for-baidu-translate'),
							value: attributes.format,
							options: [
								{ label: __('List', 'hyx-translator-for-baidu-translate'), value: 'list' },
								{ label: __('Inline', 'hyx-translator-for-baidu-translate'), value: 'inline' }
							],
							onChange: function (value) {
								setAttributes({ format: value });
							}
						}),
						el(components.ToggleControl, {
							label: __('Show the current language', 'hyx-translator-for-baidu-translate'),
							checked: !!attributes.showCurrent,
							onChange: function (value) {
								setAttributes({ showCurrent: value });
							}
						})
					)
				),
				el(
					'p',
					{ className: 'htbd-language-switcher__placeholder' },
					__('HTBD language switcher: rendered on the front end.', 'hyx-translator-for-baidu-translate')
				)
			);
		},
		save: function () {
			return null;
		}
	});
}(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
));
