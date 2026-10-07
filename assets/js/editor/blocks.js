/**
 * SearchPilot Pro — blocks: Breadcrumbs (server rendered) and FAQ.
 *
 * @package SearchPilot
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	const el = wp.element.createElement;
	const Fragment = wp.element.Fragment;
	const { __ } = wp.i18n;
	const be = wp.blockEditor || wp.editor || {};
	const { useBlockProps, InspectorControls, RichText } = be;
	const { PanelBody, SelectControl, ToggleControl, TextControl, Button } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	const breadcrumbIcon = el(
		'svg',
		{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24, 'aria-hidden': 'true', focusable: 'false' },
		el( 'path', { d: 'M3 11h4v2H3zm6.5-3.5 4.5 4.5-4.5 4.5-1.4-1.4 3.1-3.1-3.1-3.1zM15 11h6v2h-6z' } )
	);

	const faqIcon = el(
		'svg',
		{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24, 'aria-hidden': 'true', focusable: 'false' },
		el( 'path', { d: 'M4 4h16v12H7.2L4 19.2zm2 2v8.4l.4-.4H18V6zm5.1 1.5a2.4 2.4 0 0 1 2.6 2.4c0 1.1-.7 1.6-1.2 2-.3.2-.4.4-.4.8v.3h-1.6v-.5c0-.8.4-1.3.9-1.7.4-.3.7-.5.7-.9 0-.5-.4-.8-.9-.8s-1 .4-1 1H8.6a2.5 2.5 0 0 1 2.5-2.6zm-.9 6h1.7V15h-1.7z' } )
	);

	const currentPostId = () => {
		try {
			const editor = wp.data && wp.data.select( 'core/editor' );
			return editor && editor.getCurrentPostId ? editor.getCurrentPostId() : 0;
		} catch ( e ) {
			return 0;
		}
	};

	/* ---------- Breadcrumbs ---------- */

	wp.blocks.registerBlockType( 'searchpilot/breadcrumbs', {
		apiVersion: 3,
		title: __( 'Breadcrumbs (SearchPilot)', 'searchpilot-pro' ),
		description: __( 'Shows the breadcrumb trail of the current page. Breadcrumb structured data is added automatically.', 'searchpilot-pro' ),
		category: 'theme',
		icon: breadcrumbIcon,
		keywords: [ __( 'breadcrumbs', 'searchpilot-pro' ), __( 'navigation', 'searchpilot-pro' ), 'seo' ],
		attributes: {
			showCurrent: { type: 'string', default: 'default' },
		},
		supports: {
			html: false,
			align: [ 'wide', 'full' ],
			typography: { fontSize: true },
			color: { text: true, link: true, background: false },
			spacing: { margin: true },
		},
		edit( props ) {
			const blockProps = useBlockProps ? useBlockProps() : {};
			const postId = currentPostId();
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Breadcrumb settings', 'searchpilot-pro' ) },
						el( SelectControl, {
							label: __( 'Current page', 'searchpilot-pro' ),
							value: props.attributes.showCurrent,
							options: [
								{ value: 'default', label: __( 'As in SearchPilot settings', 'searchpilot-pro' ) },
								{ value: 'show', label: __( 'Show the current page', 'searchpilot-pro' ) },
								{ value: 'hide', label: __( 'Hide the current page', 'searchpilot-pro' ) },
							],
							onChange: ( value ) => props.setAttributes( { showCurrent: value } ),
						} ),
						el( 'p', { className: 'components-base-control__help' }, __( 'Separator, home label and prefix are set in SearchPilot → Schema → Breadcrumbs.', 'searchpilot-pro' ) )
					)
				),
				el(
					'div',
					blockProps,
					ServerSideRender
						? el( ServerSideRender, {
								block: 'searchpilot/breadcrumbs',
								attributes: props.attributes,
								urlQueryArgs: postId ? { post_id: postId } : {},
						  } )
						: el( 'p', null, __( 'Breadcrumbs', 'searchpilot-pro' ) )
				)
			);
		},
		save() {
			return null;
		},
	} );

	/* ---------- FAQ ---------- */

	function FaqEdit( props ) {
		const { attributes, setAttributes } = props;
		const items = Array.isArray( attributes.items ) ? attributes.items : [];
		const blockProps = useBlockProps ? useBlockProps( { className: 'searchpilot-faq' } ) : { className: 'searchpilot-faq' };

		const setItems = ( next ) => setAttributes( { items: next } );
		const update = ( index, key, value ) => setItems( items.map( ( item, i ) => ( i === index ? Object.assign( {}, item, { [ key ]: value } ) : item ) ) );
		const move = ( index, dir ) => {
			const target = index + dir;
			if ( target < 0 || target >= items.length ) {
				return;
			}
			const next = items.slice();
			const tmp = next[ index ];
			next[ index ] = next[ target ];
			next[ target ] = tmp;
			setItems( next );
		};
		const remove = ( index ) => setItems( items.filter( ( item, i ) => i !== index ) );
		const add = () => setItems( items.concat( [ { question: '', answer: '' } ] ) );

		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'FAQ settings', 'searchpilot-pro' ) },
					el( ToggleControl, {
						label: __( 'Show answers expanded', 'searchpilot-pro' ),
						checked: !! attributes.open,
						onChange: ( value ) => setAttributes( { open: value } ),
					} ),
					el(
						'p',
						{ className: 'components-base-control__help' },
						__( 'SearchPilot describes these questions as FAQ structured data. Google shows FAQ rich results only for well-known, authoritative government and health websites; the questions still help visitors and other search engines.', 'searchpilot-pro' )
					)
				)
			),
			el(
				'div',
				blockProps,
				items.length
					? items.map( ( item, index ) =>
							el(
								'div',
								{ className: 'searchpilot-faq-edit-item', key: index },
								el( TextControl, {
									label: __( 'Question', 'searchpilot-pro' ),
									value: item.question || '',
									onChange: ( value ) => update( index, 'question', value ),
								} ),
								RichText
									? el( RichText, {
											tagName: 'div',
											className: 'searchpilot-faq-edit-answer',
											value: item.answer || '',
											placeholder: __( 'Write the answer…', 'searchpilot-pro' ),
											allowedFormats: [ 'core/bold', 'core/italic', 'core/link' ],
											onChange: ( value ) => update( index, 'answer', value ),
											'aria-label': __( 'Answer', 'searchpilot-pro' ),
									  } )
									: el( TextControl, { label: __( 'Answer', 'searchpilot-pro' ), value: item.answer || '', onChange: ( value ) => update( index, 'answer', value ) } ),
								el(
									'div',
									{ className: 'searchpilot-faq-edit-actions' },
									el( Button, { size: 'small', icon: 'arrow-up-alt2', label: __( 'Move up', 'searchpilot-pro' ), disabled: index === 0, onClick: () => move( index, -1 ) } ),
									el( Button, { size: 'small', icon: 'arrow-down-alt2', label: __( 'Move down', 'searchpilot-pro' ), disabled: index === items.length - 1, onClick: () => move( index, 1 ) } ),
									el( Button, { size: 'small', icon: 'trash', isDestructive: true, label: __( 'Remove question', 'searchpilot-pro' ), onClick: () => remove( index ) } )
								)
							)
					  )
					: el( 'p', { className: 'searchpilot-faq-edit-empty' }, __( 'No questions yet. Add the questions your readers ask, with short, complete answers.', 'searchpilot-pro' ) ),
				el( Button, { variant: 'secondary', icon: 'plus', onClick: add }, __( 'Add question', 'searchpilot-pro' ) )
			)
		);
	}

	wp.blocks.registerBlockType( 'searchpilot/faq', {
		apiVersion: 3,
		title: __( 'FAQ (SearchPilot)', 'searchpilot-pro' ),
		description: __( 'Questions and answers shown as an accessible accordion, with FAQ structured data.', 'searchpilot-pro' ),
		category: 'text',
		icon: faqIcon,
		keywords: [ 'faq', __( 'questions', 'searchpilot-pro' ), __( 'accordion', 'searchpilot-pro' ) ],
		attributes: {
			items: { type: 'array', default: [] },
			open: { type: 'boolean', default: false },
		},
		supports: {
			html: false,
			align: [ 'wide', 'full' ],
			typography: { fontSize: true },
			color: { text: true, background: true },
			spacing: { margin: true, padding: true },
		},
		edit: FaqEdit,
		save() {
			return null;
		},
	} );
} )( window.wp );
