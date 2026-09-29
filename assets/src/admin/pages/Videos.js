import { __ } from '@wordpress/i18n';
import { Button, Card, CardBody } from '@wordpress/components';
import VideoBrowser from '../../components/VideoBrowser';

const config = window.videooptimizerAdmin || {};

function Onboarding() {
	return (
		<Card className="vo-onboarding">
			<CardBody>
				<h2>{ __( 'Welcome to VideoOptimizer', 'videooptimizer' ) }</h2>
				<p>
					{ __(
						'Deliver your videos adaptively from a European CDN — fast on every device, without YouTube branding or tracking.',
						'videooptimizer'
					) }
				</p>
				<ol className="vo-onboarding__steps">
					<li>
						{ __(
							'Create an organization API token in VideoOptimizer (Organization → API tokens).',
							'videooptimizer'
						) }{ ' ' }
						<a
							href={ config.appUrl }
							target="_blank"
							rel="noreferrer"
						>
							videooptimizer.eu ↗
						</a>
					</li>
					<li>
						{ __(
							'Paste it under Settings and click "Save & test".',
							'videooptimizer'
						) }
					</li>
					<li>
						{ __(
							'Upload videos here — or send existing ones from the media library — and add them with the VideoOptimizer blocks, the [videooptimizer] shortcode or the Elementor widget.',
							'videooptimizer'
						) }
					</li>
				</ol>
				{ config.canManage ? (
					<Button variant="primary" href="#/settings">
						{ __( 'Connect VideoOptimizer', 'videooptimizer' ) }
					</Button>
				) : (
					<p>
						{ __(
							'Please ask an administrator to connect VideoOptimizer.',
							'videooptimizer'
						) }
					</p>
				) }
			</CardBody>
		</Card>
	);
}

export default function VideosPage( { library = '' } ) {
	if ( ! config.configured ) {
		return <Onboarding />;
	}
	return (
		<>
			<p className="vo-admin__intro">
				{ __(
					'Upload videos (drag & drop works too), then use them in blocks, the shortcode, Elementor or as WooCommerce product videos. Click a video for poster, options and embed code.',
					'videooptimizer'
				) }{ ' ' }
				<a href={ config.mediaUrl }>
					{ __(
						'Send existing media library videos',
						'videooptimizer'
					) }
				</a>
			</p>
			<VideoBrowser
				key={ library }
				initialLibrary={ library }
				onSelect={ ( video ) => {
					window.location.hash = '#/video/' + video.uuid;
				} }
			/>
		</>
	);
}
