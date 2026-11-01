<?php
/**
 * Template gallery. Every template is a partial campaign merged over the model defaults
 * and passed through the same sanitiser as admin saves.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Templates.
 */
class Templates {

	/**
	 * All templates (sanitised, with image URLs resolved).
	 *
	 * @return array [ { id, name, description, category, woo, campaign } ]
	 */
	public static function all() {
		$out = array();
		foreach ( self::raw() as $tpl ) {
			if ( ! empty( $tpl['woo'] ) && ! class_exists( 'WooCommerce' ) ) {
				$tpl['requires'] = 'WooCommerce';
			}
			$tpl['campaign'] = self::build( $tpl );
			unset( $tpl['variant'], $tpl['config'] );
			$out[] = $tpl;
		}
		/**
		 * Filters the template gallery.
		 *
		 * @param array $out Templates.
		 */
		return apply_filters( 'dev3pu_templates', $out );
	}

	/**
	 * One template's campaign.
	 *
	 * @param string $id Template id.
	 * @return array|null
	 */
	public static function campaign( $id ) {
		foreach ( self::all() as $tpl ) {
			if ( $tpl['id'] === $id ) {
				return $tpl['campaign'];
			}
		}
		return null;
	}

	/**
	 * Builds a sanitised campaign from a raw template.
	 *
	 * @param array $tpl Raw template.
	 * @return array
	 */
	private static function build( array $tpl ) {
		$config  = Model::defaults();
		$variant = Model::variant( 'a' );
		foreach ( array( 'content', 'design' ) as $part ) {
			if ( isset( $tpl['variant'][ $part ] ) ) {
				$variant[ $part ] = array_merge( $variant[ $part ], $tpl['variant'][ $part ] );
			}
		}
		if ( '' !== $variant['content']['image_url'] && 0 === strpos( $variant['content']['image_url'], 'tpl:' ) ) {
			$variant['content']['image_url'] = DEV3PU_URL . 'assets/img/templates/' . substr( $variant['content']['image_url'], 4 ) . '.svg';
		}
		$config['variants'] = array( $variant );
		foreach ( isset( $tpl['config'] ) ? $tpl['config'] : array() as $key => $value ) {
			$config[ $key ] = is_array( $value ) && isset( $config[ $key ] ) && is_array( $config[ $key ] ) && array_keys( $value ) !== range( 0, count( $value ) - 1 )
				? array_merge( $config[ $key ], $value )
				: $value;
		}
		return Model::sanitize_campaign(
			array(
				'title'  => $tpl['name'],
				'type'   => $tpl['type'],
				'status' => 'draft',
				'config' => $config,
			)
		);
	}

	/**
	 * Raw template definitions (English source copy; the admin edits it).
	 *
	 * @return array
	 */
	private static function raw() {
		$email_name = array(
			array(
				'key'         => 'name',
				'type'        => 'text',
				'label'       => 'First name',
				'placeholder' => 'Alex',
				'required'    => false,
			),
			array(
				'key'         => 'email',
				'type'        => 'email',
				'label'       => 'Email address',
				'placeholder' => 'you@example.com',
				'required'    => true,
			),
		);
		return array(
			array(
				'id'          => 'newsletter',
				'name'        => 'Newsletter signup',
				'description' => 'Classic centered popup after 8 seconds, once a week.',
				'type'        => 'popup',
				'category'    => 'grow',
				'variant'     => array(
					'content' => array(
						'eyebrow'         => 'The weekly letter',
						'headline'        => 'Get our best ideas every Friday',
						'body'            => 'One short email a week with new guides, tools and member-only offers. Join 12,000 readers.',
						'button'          => 'Subscribe',
						'decline'         => 'No thanks',
						'success_title'   => 'You are in!',
						'success_message' => 'Check your inbox to confirm your subscription.',
						'image_url'       => 'tpl:envelope',
						'image_alt'       => '',
					),
					'design'  => array(
						'layout' => 'image-left',
						'accent' => '#4f46e5',
					),
				),
				'config'      => array(
					'form' => array(
						'fields'  => $email_name,
						'consent' => array(
							'enabled'  => true,
							'label'    => 'I agree to receive emails. Unsubscribe any time.',
							'required' => true,
						),
					),
				),
			),
			array(
				'id'          => 'discount',
				'name'        => 'First-order discount',
				'description' => 'Reveal a 10% code after signup. Great for new shoppers.',
				'type'        => 'popup',
				'category'    => 'sell',
				'variant'     => array(
					'content' => array(
						'eyebrow'         => 'Welcome gift',
						'headline'        => 'Take 10% off your first order',
						'body'            => 'Join the list and we will show your code right away.',
						'button'          => 'Get my 10% off',
						'decline'         => 'I prefer to pay full price',
						'success_title'   => 'Here is your code',
						'success_message' => 'Use it at checkout. It is also on its way to your inbox.',
						'image_url'       => 'tpl:gift',
					),
					'design'  => array(
						'layout' => 'image-top',
						'size'   => 'sm',
						'accent' => '#db2777',
					),
				),
				'config'      => array(
					'offer'     => array(
						'type' => 'code',
						'code' => 'WELCOME10',
					),
					'targeting' => array( 'visitor' => 'new' ),
					'frequency' => array(
						'mode' => 'days',
						'days' => 14,
					),
				),
			),
			array(
				'id'          => 'exit-save',
				'name'        => 'Exit intent: wait, before you go',
				'description' => 'Shows when the pointer leaves the window (fast scroll up on phones).',
				'type'        => 'popup',
				'category'    => 'sell',
				'variant'     => array(
					'content' => array(
						'eyebrow'         => 'Before you go',
						'headline'        => 'Leaving already? Here is free shipping',
						'body'            => 'Enter your email and we will send a free shipping code for your next order.',
						'button'          => 'Send my code',
						'decline'         => 'Maybe later',
						'success_title'   => 'Sent!',
						'success_message' => 'Your free shipping code is on its way.',
						'image_url'       => 'tpl:truck',
					),
					'design'  => array(
						'layout' => 'image-left',
						'bg'     => '#fffbeb',
						'accent' => '#b45309',
					),
				),
				'config'      => array(
					'triggers' => array(
						array(
							'type'  => 'exit',
							'value' => 20,
						),
					),
				),
			),
			array(
				'id'          => 'shipping-bar',
				'name'        => 'Free shipping bar',
				'description' => 'Floating top bar with a link. No form, no layout shift.',
				'type'        => 'bar',
				'category'    => 'announce',
				'variant'     => array(
					'content' => array(
						'headline' => 'Free shipping on orders over $50',
						'body'     => 'Ends Sunday.',
						'button'   => 'Shop now',
					),
					'design'  => array(
						'bg'          => '#111827',
						'text'        => '#f9fafb',
						'accent'      => '#facc15',
						'button_text' => '#111827',
						'position'    => 'top',
						'animation'   => 'slide',
						'radius'      => 8,
					),
				),
				'config'      => array(
					'form'      => array(
						'mode'    => 'cta',
						'cta_url' => '/shop/',
					),
					'triggers'  => array(
						array(
							'type'  => 'load',
							'value' => 0,
						),
					),
					'frequency' => array(
						'mode'       => 'always',
						'close_days' => 1,
					),
				),
			),
			array(
				'id'          => 'webinar',
				'name'        => 'Webinar registration',
				'description' => 'Slide-in with name + email after 50% scroll.',
				'type'        => 'slidein',
				'category'    => 'grow',
				'variant'     => array(
					'content' => array(
						'eyebrow'         => 'Live on Thursday',
						'headline'        => 'Save your seat for the free workshop',
						'body'            => '45 minutes, live Q&A, recording for everyone who signs up.',
						'button'          => 'Register free',
						'success_title'   => 'Seat saved',
						'success_message' => 'We sent the calendar invite to your inbox.',
					),
					'design'  => array(
						'size'      => 'sm',
						'align'     => 'left',
						'accent'    => '#0f766e',
						'animation' => 'slide',
					),
				),
				'config'      => array(
					'form'     => array( 'fields' => $email_name ),
					'triggers' => array(
						array(
							'type'  => 'scroll',
							'value' => 50,
						),
					),
				),
			),
			array(
				'id'          => 'lead-magnet',
				'name'        => 'Lead magnet download',
				'description' => 'Offer a free guide; redirect to the download after signup.',
				'type'        => 'popup',
				'category'    => 'grow',
				'variant'     => array(
					'content' => array(
						'eyebrow'         => 'Free guide',
						'headline'        => 'The 20-page starter checklist',
						'body'            => 'Everything we wish we knew on day one, in one printable PDF.',
						'button'          => 'Download free',
						'decline'         => 'No thanks',
						'success_title'   => 'Your guide is ready',
						'success_message' => 'Opening the download…',
						'image_url'       => 'tpl:book',
					),
					'design'  => array(
						'layout' => 'image-left',
						'size'   => 'lg',
						'accent' => '#2563eb',
					),
				),
				'config'      => array(
					'success'  => array(
						'action'       => 'redirect',
						'redirect_url' => home_url( '/' ),
					),
					'triggers' => array(
						array(
							'type'  => 'scroll',
							'value' => 60,
						),
						array(
							'type'  => 'exit',
							'value' => 20,
						),
					),
				),
			),
			array(
				'id'          => 'cart-saver',
				'name'        => 'Cart saver (WooCommerce)',
				'description' => 'Exit intent for shoppers with a cart over $30; unique coupon.',
				'type'        => 'popup',
				'category'    => 'sell',
				'woo'         => true,
				'variant'     => array(
					'content' => array(
						'eyebrow'         => 'Your cart is waiting',
						'headline'        => 'Finish your order and save 5%',
						'body'            => 'Leave your email and we will create a personal one-time code for you.',
						'button'          => 'Create my code',
						'decline'         => 'No thanks',
						'success_title'   => 'Your personal code',
						'success_message' => 'Valid for 3 days, one use.',
						'image_url'       => 'tpl:gift',
					),
					'design'  => array(
						'layout' => 'image-top',
						'size'   => 'sm',
						'accent' => '#7c3aed',
					),
				),
				'config'      => array(
					'offer'     => array(
						'type'          => 'coupon',
						'discount_type' => 'percent',
						'amount'        => 5,
						'expiry_days'   => 3,
						'prefix'        => 'SAVE',
					),
					'triggers'  => array(
						array(
							'type'  => 'exit',
							'value' => 20,
						),
						array(
							'type'  => 'inactivity',
							'value' => 45,
						),
					),
					'targeting' => array(
						'cart' => array(
							'enabled'   => true,
							'min'       => 30,
							'max'       => 0,
							'not_empty' => true,
						),
					),
				),
			),
			array(
				'id'          => 'announcement',
				'name'        => 'Announcement bar',
				'description' => 'Bottom bar for news, sales or cookie-free notices.',
				'type'        => 'bar',
				'category'    => 'announce',
				'variant'     => array(
					'content' => array(
						'headline' => 'New: the autumn collection is here',
						'body'     => '',
						'button'   => 'See what is new',
					),
					'design'  => array(
						'bg'          => '#4f46e5',
						'text'        => '#ffffff',
						'accent'      => '#ffffff',
						'button_text' => '#3730a3',
						'position'    => 'bottom',
						'animation'   => 'slide',
					),
				),
				'config'      => array(
					'form'     => array(
						'mode'    => 'cta',
						'cta_url' => '/',
					),
					'triggers' => array(
						array(
							'type'  => 'time',
							'value' => 3,
						),
					),
				),
			),
			array(
				'id'          => 'welcome-mat',
				'name'        => 'Welcome mat (full screen)',
				'description' => 'Full-screen welcome for desktop visitors; a bottom sheet on phones.',
				'type'        => 'fullscreen',
				'category'    => 'grow',
				'variant'     => array(
					'content' => array(
						'eyebrow'         => 'Members save more',
						'headline'        => 'Join free and unlock member prices',
						'body'            => 'Early access to sales, birthday rewards and free returns.',
						'button'          => 'Join free',
						'decline'         => 'Continue to the site',
						'success_title'   => 'Welcome aboard!',
						'success_message' => 'Your member prices are active.',
						'image_url'       => 'tpl:sparkles',
					),
					'design'  => array(
						'layout'      => 'image-top',
						'bg'          => '#0f172a',
						'text'        => '#f8fafc',
						'accent'      => '#38bdf8',
						'button_text' => '#0f172a',
						'animation'   => 'zoom',
					),
				),
				'config'      => array(
					'triggers'  => array(
						array(
							'type'  => 'time',
							'value' => 5,
						),
					),
					'targeting' => array( 'visitor' => 'new' ),
				),
			),
			array(
				'id'          => 'inline-newsletter',
				'name'        => 'Inline newsletter box',
				'description' => 'After each blog post. Server-rendered: zero layout shift.',
				'type'        => 'inline',
				'category'    => 'grow',
				'variant'     => array(
					'content' => array(
						'eyebrow'         => 'Enjoyed this article?',
						'headline'        => 'Get the next one in your inbox',
						'body'            => 'New posts every week. No spam.',
						'button'          => 'Subscribe',
						'success_title'   => 'Thanks!',
						'success_message' => 'Please confirm your email.',
					),
					'design'  => array(
						'align'  => 'left',
						'bg'     => '#f5f3ff',
						'accent' => '#6d28d9',
					),
				),
				'config'      => array(
					'inline' => array(
						'placement'  => 'after',
						'post_types' => array( 'post' ),
					),
				),
			),
			array(
				'id'          => 'survey',
				'name'        => 'One-question survey',
				'description' => 'Ask how visitors found you, then thank them.',
				'type'        => 'slidein',
				'category'    => 'learn',
				'variant'     => array(
					'content' => array(
						'headline'        => 'Quick question: how did you find us?',
						'body'            => 'It helps us a lot.',
						'button'          => 'Send',
						'success_title'   => 'Thank you!',
						'success_message' => 'Your answer was saved.',
					),
					'design'  => array(
						'size'     => 'sm',
						'align'    => 'left',
						'position' => 'bottom-left',
						'accent'   => '#059669',
					),
				),
				'config'      => array(
					'form'     => array(
						'fields' => array(
							array(
								'key'      => 'source',
								'type'     => 'select',
								'label'    => 'I found you through',
								'required' => true,
								'options'  => array( 'Google search', 'Social media', 'A friend', 'Podcast or video', 'Other' ),
							),
							array(
								'key'         => 'email',
								'type'        => 'email',
								'label'       => 'Email (optional, for a thank-you gift)',
								'placeholder' => 'you@example.com',
								'required'    => false,
							),
						),
					),
					'triggers' => array(
						array(
							'type'  => 'inactivity',
							'value' => 20,
						),
					),
				),
			),
			array(
				'id'          => 'content-upgrade',
				'name'        => 'Content upgrade slide-in',
				'description' => 'Bottom-right card on blog posts after 40% scroll.',
				'type'        => 'slidein',
				'category'    => 'grow',
				'variant'     => array(
					'content' => array(
						'eyebrow'         => 'Bonus',
						'headline'        => 'Get the printable version of this post',
						'body'            => 'Plus a checklist you can use today.',
						'button'          => 'Email it to me',
						'success_title'   => 'On its way',
						'success_message' => 'Check your inbox in a minute.',
						'image_url'       => 'tpl:book',
					),
					'design'  => array(
						'layout' => 'image-left',
						'size'   => 'sm',
						'align'  => 'left',
						'accent' => '#ea580c',
					),
				),
				'config'      => array(
					'triggers'  => array(
						array(
							'type'  => 'scroll',
							'value' => 40,
						),
					),
					'targeting' => array(
						'pages' => array(
							'mode'  => 'include',
							'rules' => array(
								array(
									'type'  => 'post_type',
									'value' => 'post',
								),
							),
						),
					),
				),
			),
		);
	}
}
