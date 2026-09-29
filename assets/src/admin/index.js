/**
 * VideoOptimizer admin app (WP-Admin → VideoOptimizer). Hash routes:
 *   #/            videos
 *   #/video/:uuid video detail
 *   #/libraries   libraries (admins)
 *   #/settings    settings (admins)
 *   #/send/:ids   transfer media library videos
 */
import { __ } from '@wordpress/i18n';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { SnackbarList } from '@wordpress/components';
import VideosPage from './pages/Videos';
import VideoDetail from './pages/VideoDetail';
import LibrariesPage from './pages/Libraries';
import SettingsPage from './pages/Settings';
import SendPage from './pages/Send';
import { NoticesContext } from './notices';
import '../components/components.scss';
import './admin.scss';

const config = window.videooptimizerAdmin || {};

function parseHash() {
	const hash = window.location.hash.replace( /^#\/?/, '' );
	const [ route, ...rest ] = hash.split( '/' );
	return { route: route || 'videos', param: rest.join( '/' ) };
}

function useRoute() {
	const [ route, setRoute ] = useState( parseHash() );
	useEffect( () => {
		const onChange = () => setRoute( parseHash() );
		window.addEventListener( 'hashchange', onChange );
		return () => window.removeEventListener( 'hashchange', onChange );
	}, [] );
	return route;
}

// Keep the WP admin submenu highlight in sync with the hash route.
function syncSubmenu( route ) {
	const links = document.querySelectorAll(
		'#toplevel_page_videooptimizer .wp-submenu a'
	);
	links.forEach( ( a ) => {
		const target =
			( a.getAttribute( 'href' ) || '' ).split( '#/' )[ 1 ] || 'videos';
		const current = [ 'video', 'send', 'library' ].includes( route )
			? 'videos'
			: route;
		a.parentElement.classList.toggle( 'current', target === current );
		a.classList.toggle( 'current', target === current );
	} );
}

function App() {
	const { route, param } = useRoute();
	const [ notices, setNotices ] = useState( [] );

	useEffect( () => syncSubmenu( route ), [ route ] );

	const notify = ( content, status = 'success' ) => {
		const id = Math.random().toString( 36 ).slice( 2 );
		setNotices( ( list ) => [ ...list, { id, content, status } ] );
		setTimeout(
			() => setNotices( ( list ) => list.filter( ( n ) => n.id !== id ) ),
			6000
		);
	};

	const tabs = [
		{ key: 'videos', label: __( 'Videos', 'videooptimizer' ), show: true },
		{
			key: 'libraries',
			label: __( 'Libraries', 'videooptimizer' ),
			show: config.canManage,
		},
		{
			key: 'settings',
			label: __( 'Settings', 'videooptimizer' ),
			show: config.canManage,
		},
	];
	const active = [ 'video', 'send', 'library' ].includes( route )
		? 'videos'
		: route;

	let page;
	if ( route === 'video' && param ) {
		page = <VideoDetail uuid={ param } />;
	} else if ( route === 'libraries' && config.canManage ) {
		page = <LibrariesPage />;
	} else if ( route === 'settings' && config.canManage ) {
		page = <SettingsPage />;
	} else if ( route === 'send' ) {
		page = (
			<SendPage
				ids={ param.split( ',' ).map( Number ).filter( Boolean ) }
			/>
		);
	} else if ( route === 'library' ) {
		page = <VideosPage library={ decodeURIComponent( param ) } />;
	} else {
		page = <VideosPage />;
	}

	return (
		<NoticesContext.Provider value={ notify }>
			<header className="vo-admin__header">
				<h1 className="vo-admin__title">
					<span
						className="dashicons dashicons-video-alt3"
						aria-hidden="true"
					/>
					VideoOptimizer
				</h1>
				<nav
					className="vo-admin__tabs"
					aria-label={ __(
						'VideoOptimizer sections',
						'videooptimizer'
					) }
				>
					{ tabs
						.filter( ( t ) => t.show )
						.map( ( t ) => (
							<a
								key={ t.key }
								href={
									'#/' + ( t.key === 'videos' ? '' : t.key )
								}
								className={
									'vo-admin__tab' +
									( active === t.key ? ' is-active' : '' )
								}
								aria-current={
									active === t.key ? 'page' : undefined
								}
							>
								{ t.label }
							</a>
						) ) }
				</nav>
				<a
					className="vo-admin__app-link"
					href={ config.appUrl }
					target="_blank"
					rel="noreferrer"
				>
					{ __( 'Open VideoOptimizer app', 'videooptimizer' ) } ↗
				</a>
			</header>
			<main className="vo-admin__main">{ page }</main>
			<SnackbarList
				className="vo-admin__snackbars"
				notices={ notices.map( ( n ) => ( {
					id: n.id,
					content: n.content,
					spokenMessage: n.content,
				} ) ) }
				onRemove={ ( id ) =>
					setNotices( ( list ) =>
						list.filter( ( n ) => n.id !== id )
					)
				}
			/>
		</NoticesContext.Provider>
	);
}

const root = document.getElementById( 'videooptimizer-admin' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
