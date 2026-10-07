/**
 * System Logs view.
 *
 * @package SearchPilot
 */
( function ( wp, SPP ) {
	'use strict';

	const { __ } = wp.i18n;
	const { h, ui, fmt, api } = SPP;

	const LEVEL_TONES = {
		debug: 'outline',
		info: 'info',
		notice: 'info',
		warning: 'high',
		error: 'critical',
		critical: 'critical',
	};

	SPP.registerView( 'logs', {
		mount( el ) {
			const query = {
				level: SPP.query.get( 'level' ),
				channel: SPP.query.get( 'channel' ),
				search: SPP.query.get( 'search' ),
			};

			const table = ui.dataTable( {
				path: '/logs',
				query,
				perPage: 50,
				dense: true,
				empty: __( 'No log entries match these filters.', 'searchpilot-pro' ),
				columns: [
					{
						key: 'created_at',
						label: __( 'Time', 'searchpilot-pro' ),
						className: 'is-nowrap',
						render: ( r ) => h( 'span', { title: fmt.datetime( r.created_at ) }, fmt.relative( r.created_at ) ),
					},
					{
						key: 'level',
						label: __( 'Level', 'searchpilot-pro' ),
						render: ( r ) => ui.badge( r.level, LEVEL_TONES[ r.level ] ),
					},
					{
						key: 'channel',
						label: __( 'Channel', 'searchpilot-pro' ),
						render: ( r ) => h( 'code', null, r.channel ),
					},
					{
						key: 'message',
						label: __( 'Message', 'searchpilot-pro' ),
						render: ( r ) =>
							h(
								'div',
								null,
								h( 'div', { class: 'spp-break' }, r.message ),
								r.context
									? ui.button( {
											label: __( 'Details', 'searchpilot-pro' ),
											variant: 'link',
											size: 'small',
											onClick: () => ui.modal( { title: __( 'Log context', 'searchpilot-pro' ), size: 'large', body: ui.code( JSON.stringify( r.context, null, 2 ) ) } ),
									  } )
									: null
							),
					},
				],
			} );

			const channelSelect = h( 'span' );
			api.get( '/logs/channels' ).then( ( res ) => {
				SPP.mount(
					channelSelect,
					ui.select( {
						label: __( 'Channel', 'searchpilot-pro' ),
						value: query.channel,
						options: [ { value: '', label: __( 'All channels', 'searchpilot-pro' ) } ].concat( ( res.channels || [] ).map( ( c ) => ( { value: c, label: c } ) ) ),
						onChange: ( v ) => {
							SPP.query.set( { channel: v } );
							table.setQuery( { channel: v } );
						},
					} )
				);
			} );

			SPP.mount(
				el,
				ui.pageHeader( {
					title: __( 'System Logs', 'searchpilot-pro' ),
					subtitle: __( 'Background jobs, API calls and automatic changes. Credentials and tokens are always redacted before anything is written.', 'searchpilot-pro' ),
					actions: [
						ui.button( { label: __( 'Refresh', 'searchpilot-pro' ), icon: 'refresh', onClick: () => table.reload() } ),
						ui.button( {
							label: __( 'Clear logs', 'searchpilot-pro' ),
							icon: 'trash',
							variant: 'danger',
							onClick: () =>
								ui.confirm( { title: __( 'Clear all logs?', 'searchpilot-pro' ), message: __( 'This permanently deletes every log entry.', 'searchpilot-pro' ), confirmLabel: __( 'Clear logs', 'searchpilot-pro' ), danger: true } ).then( ( ok ) => {
									if ( ! ok ) {
										return null;
									}
									return api.del( '/logs' ).then( () => {
										ui.toast( __( 'Logs cleared', 'searchpilot-pro' ) );
										table.reload( true );
									} );
								} ),
						} ),
					],
				} ),
				ui.card( {
					flush: true,
					body: [
						h(
							'div',
							{ style: { padding: '0 20px' } },
							ui.toolbar(
								ui.search( {
									value: query.search,
									placeholder: __( 'Search messages…', 'searchpilot-pro' ),
									onSearch: ( v ) => {
										SPP.query.set( { search: v } );
										table.setQuery( { search: v } );
									},
								} ),
								ui.select( {
									label: __( 'Minimum level', 'searchpilot-pro' ),
									value: query.level,
									options: [
										{ value: '', label: __( 'All levels', 'searchpilot-pro' ) },
										{ value: 'info', label: __( 'Info and above', 'searchpilot-pro' ) },
										{ value: 'warning', label: __( 'Warnings and errors', 'searchpilot-pro' ) },
										{ value: 'error', label: __( 'Errors only', 'searchpilot-pro' ) },
									],
									onChange: ( v ) => {
										SPP.query.set( { level: v } );
										table.setQuery( { level: v } );
									},
								} ),
								channelSelect
							)
						),
						table.el,
					],
				} )
			);
		},
	} );
} )( window.wp, window.SPP );
