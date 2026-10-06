( function () {
	'use strict';

	const settings = window.wc.wcSettings.getSetting( 'hashpay_data', {} );
	const decodeEntities = window.wp.htmlEntities.decodeEntities;
	const createElement = window.wp.element.createElement;

	const Content = function () {
		return createElement( 'div', null, decodeEntities( settings.description || '' ) );
	};

	const Label = function ( props ) {
		return createElement( props.components.PaymentMethodLabel, {
			text: decodeEntities( settings.title || 'Cryptocurrency' ),
		} );
	};

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'hashpay',
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: decodeEntities( settings.title || 'Cryptocurrency' ),
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
}() );
