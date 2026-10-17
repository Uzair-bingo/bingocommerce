<?php
/**
 * Package cards for the Session selected through its server link, and the
 * optional purchase step (ADR-045 / BR-084 additions 2026-10-02).
 *
 * Names, images, types, prices and each Package's saved base composition
 * ("What's included") come from the Sessions plugin, which applies BR-080
 * eligibility plus public visibility and WooCommerce purchasability, and
 * prices add-ons at their Package relationship price. When it also supplies
 * usable purchase settings, the cards become one native radio group in a
 * narrow left column; a wider right column holds the selected-Package panel,
 * each Package's own add-on card grid (with a local name search) and the
 * summary with Add to cart. Every purchase control is rendered hidden and
 * disabled, so without the optional script the cards stay informational and
 * nothing can be focused or used. The script sends the selection to
 * WooCommerce's existing cart-extension route, where the Sessions plugin
 * validates it again; this template decides nothing.
 */

use BingoCommerce\BingoThemeV1\SessionCalendar;
use BingoCommerce\BingoThemeV1\Template;
use BingoCommerce\BingoThemeV1\Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bingothemev1_calendar = $args['calendar'] ?? null;
if ( ! is_array( $bingothemev1_calendar ) || ! is_array( $bingothemev1_calendar['session'] ?? null ) || ! is_array( $bingothemev1_calendar['packages'] ?? null ) ) {
	return;
}
$bingothemev1_session  = $bingothemev1_calendar['session'];
$bingothemev1_packages = $bingothemev1_calendar['packages'];
$bingothemev1_time     = SessionCalendar::time_line( $bingothemev1_session );
$bingothemev1_when     = $bingothemev1_session['date_label'] . ( '' !== $bingothemev1_time ? ' · ' . $bingothemev1_time : '' );
$bingothemev1_listed   = 'unavailable' !== $bingothemev1_packages['status'] && [] !== $bingothemev1_packages['items'];
$bingothemev1_purchase = $bingothemev1_listed ? SessionCalendar::purchase( $bingothemev1_calendar ) : null;

/** One Package card's informational content, shared by both presentations. */
$bingothemev1_card = static function ( array $item ): void {
	$image = $item['image_id'] > 0
		? wp_get_attachment_image( $item['image_id'], 'medium_large', false, [ 'class' => 'session-package__image', 'alt' => '', 'loading' => 'lazy', 'sizes' => '(max-width: 640px) calc(100vw - 80px), 400px' ] )
		: '';
	// Without an image the card keeps its shape with a decorative placeholder; the name labels the card.
	echo '' !== $image
		? '<span class="session-package__thumb">' . $image . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- native escaped image markup.
		: '<span class="session-package__thumb is-placeholder">' . Template::icon( 'image' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static decorative SVG.
	echo '<span class="eyebrow session-package__type">' . esc_html( SessionCalendar::type_label( $item['type'] ) ) . '</span>';
	echo '<span class="session-package__name">' . esc_html( $item['name'] ) . '</span>';
	echo '<span class="session-package__price" data-purchase-price>' . wp_kses_post( $item['price_html'] ) . '</span>';
};

/** "What's included": the Package's saved base composition, never its optional add-ons. */
$bingothemev1_contents = static function ( array $item, string $id ): void {
	$contents = SessionCalendar::contents( $item );
	echo "\n" . '<div class="session-package__contents"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ">\n";
	echo '<p class="session-package__contents-title">' . esc_html__( 'What’s included', Theme::TEXT_DOMAIN ) . "</p>\n";
	if ( 'ok' !== $contents['state'] ) {
		echo '<p class="session-package__contents-note">' . esc_html__( 'Package contents can’t be shown right now.', Theme::TEXT_DOMAIN ) . "</p>\n";
	} else {
		echo "<ul class=\"session-package__includes\">\n";
		foreach ( $contents['items'] as $component ) {
			/* translators: 1: Paper or Digital, 2: how many of this item the Package includes. */
			$meta = sprintf( __( '%1$s · Quantity: %2$s', Theme::TEXT_DOMAIN ), SessionCalendar::medium_label( $component['medium'] ), number_format_i18n( $component['quantity'] ) );
			echo '<li class="session-package__include"><span class="session-package__include-name">' . esc_html( $component['name'] ) . '</span> <span class="session-package__include-meta">' . esc_html( $meta ) . "</span></li>\n";
		}
		echo "</ul>\n";
	}
	echo "</div>\n";
};
?>
<div class="bc-session-packages" id="session-packages" data-session-packages="<?php echo esc_attr( $bingothemev1_session['date'] ); ?>" tabindex="-1" aria-labelledby="bc-session-packages-heading">
	<div class="bc-session-packages__header">
		<h3 id="bc-session-packages-heading"><?php /* translators: %s: Session name. */ echo esc_html( sprintf( __( 'Packages for %s', Theme::TEXT_DOMAIN ), $bingothemev1_session['name'] ) ); ?></h3>
		<p class="bc-session-packages__session"><?php echo Template::icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static decorative SVG. ?><span><?php echo esc_html( $bingothemev1_when ); ?></span></p>
	</div>
	<?php if ( 'unavailable' === $bingothemev1_packages['status'] ) : ?>
		<p class="bc-session-packages__message"><?php esc_html_e( 'Packages can’t be shown right now. Please try again.', Theme::TEXT_DOMAIN ); ?></p>
	<?php else : ?>
		<?php if ( 'partial' === $bingothemev1_packages['status'] ) : ?>
			<p class="calendar-notice"><?php esc_html_e( 'Some Packages for this Session couldn’t be checked right now.', Theme::TEXT_DOMAIN ); ?></p>
		<?php endif; ?>
		<?php if ( ! $bingothemev1_listed ) : ?>
			<p class="bc-session-packages__message"><?php esc_html_e( 'No Packages are currently available for this Session.', Theme::TEXT_DOMAIN ); ?></p>
		<?php elseif ( null === $bingothemev1_purchase ) : ?>
			<ul class="bc-session-packages__grid">
				<?php foreach ( $bingothemev1_packages['items'] as $bingothemev1_item ) : ?>
					<li class="session-package"><?php $bingothemev1_card( $bingothemev1_item ); ?><?php $bingothemev1_contents( $bingothemev1_item, '' ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="bc-session-packages__message"><?php esc_html_e( 'Purchasing isn’t available right now.', Theme::TEXT_DOMAIN ); ?></p>
		<?php else : ?>
			<form class="bc-purchase" data-bingo-purchase novalidate
				data-endpoint="<?php echo esc_url( $bingothemev1_purchase['endpoint'] ); ?>"
				data-cart-endpoint="<?php echo esc_url( $bingothemev1_purchase['cart_endpoint'] ); ?>"
				data-namespace="<?php echo esc_attr( $bingothemev1_purchase['namespace'] ); ?>"
				data-nonce="<?php echo esc_attr( $bingothemev1_purchase['nonce'] ); ?>"
				data-cart-url="<?php echo esc_url( $bingothemev1_purchase['cart_url'] ); ?>"
				data-refresh-url="<?php echo esc_url( $bingothemev1_session['packages_url'] ); ?>"
				data-session-id="<?php echo esc_attr( (string) $bingothemev1_session['id'] ); ?>"
				data-session-name="<?php echo esc_attr( $bingothemev1_session['name'] ); ?>"
				data-session-when="<?php echo esc_attr( $bingothemev1_when ); ?>">
				<p class="bc-purchase__intro" data-purchase-reveal hidden><?php esc_html_e( 'Choose one Bingo Package. Add-ons are optional.', Theme::TEXT_DOMAIN ); ?></p>
				<div class="bc-purchase__layout">
					<fieldset class="bc-purchase__packages" data-purchase-packages>
						<legend class="screen-reader-text" data-purchase-reveal hidden><?php esc_html_e( 'Choose a Package', Theme::TEXT_DOMAIN ); ?></legend>
						<p class="bc-purchase__error" id="bc-purchase-package-error" data-purchase-package-error hidden></p>
						<ul class="bc-session-packages__grid">
							<?php foreach ( $bingothemev1_packages['items'] as $bingothemev1_item ) : ?>
								<?php $bingothemev1_radio = 'bc-package-' . (int) $bingothemev1_item['id']; ?>
								<li class="session-package">
									<label class="session-package__choice" for="<?php echo esc_attr( $bingothemev1_radio ); ?>">
										<input type="radio" class="session-package__radio" id="<?php echo esc_attr( $bingothemev1_radio ); ?>" name="bc_package" value="<?php echo esc_attr( (string) $bingothemev1_item['id'] ); ?>"
											data-package-name="<?php echo esc_attr( $bingothemev1_item['name'] ); ?>" aria-describedby="<?php echo esc_attr( 'bc-purchase-package-error ' . $bingothemev1_radio . '-contents' ); ?>" data-purchase-reveal hidden disabled>
										<?php $bingothemev1_card( $bingothemev1_item ); ?>
									</label>
									<?php $bingothemev1_contents( $bingothemev1_item, $bingothemev1_radio . '-contents' ); ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</fieldset>
					<div class="bc-purchase__details" data-purchase-reveal hidden>
						<section class="bc-purchase__selected" aria-labelledby="bc-purchase-selected-title">
							<div class="bc-purchase__selected-head">
								<div class="bc-purchase__selected-text">
									<h4 id="bc-purchase-selected-title"><?php esc_html_e( 'Selected Package', Theme::TEXT_DOMAIN ); ?></h4>
									<p class="bc-purchase__selected-name" data-selected-name><?php esc_html_e( 'No Package chosen yet', Theme::TEXT_DOMAIN ); ?></p>
									<p class="bc-purchase__selected-meta" data-selected-meta hidden></p>
								</div>
								<p class="bc-purchase__badge" data-selected-badge hidden><span class="bc-purchase__badge-count">1</span> <span><?php esc_html_e( 'Package', Theme::TEXT_DOMAIN ); ?></span></p>
							</div>
							<p class="bc-purchase__selected-session"><?php echo Template::icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static decorative SVG. ?><span><?php echo esc_html( $bingothemev1_session['name'] . ' · ' . $bingothemev1_when ); ?></span></p>
						</section>
						<div class="bc-purchase__addons">
							<p class="bc-purchase__hint" data-purchase-hint><?php esc_html_e( 'Choose a Package to see its optional add-ons.', Theme::TEXT_DOMAIN ); ?></p>
							<?php foreach ( $bingothemev1_packages['items'] as $bingothemev1_item ) : ?>
								<?php
								$bingothemev1_package_id = (int) $bingothemev1_item['id'];
								$bingothemev1_addons     = SessionCalendar::addons( $bingothemev1_item );
								$bingothemev1_search     = 'bc-addon-search-' . $bingothemev1_package_id;
								$bingothemev1_searchable = count( $bingothemev1_addons['items'] ) > 1;
								$bingothemev1_choosable  = [] !== array_filter( $bingothemev1_addons['items'], static fn( array $addon ): bool => (int) $addon['max_quantity'] > 1 );
								?>
								<fieldset class="bc-addons" data-addons-for="<?php echo esc_attr( (string) $bingothemev1_package_id ); ?>" data-addons-state="<?php echo esc_attr( $bingothemev1_addons['state'] ); ?>" hidden disabled>
									<legend class="bc-addons__legend"><?php /* translators: %s: Package name. */ echo esc_html( sprintf( __( 'Optional add-ons for %s', Theme::TEXT_DOMAIN ), $bingothemev1_item['name'] ) ); ?></legend>
									<?php if ( 'unavailable' === $bingothemev1_addons['state'] ) : ?>
										<p class="calendar-notice bc-addons__notice"><?php esc_html_e( 'Add-ons can’t be shown right now.', Theme::TEXT_DOMAIN ); ?></p>
									<?php elseif ( 'empty' === $bingothemev1_addons['state'] ) : ?>
										<p class="bc-addons__empty"><?php esc_html_e( 'No add-ons are available for this Package.', Theme::TEXT_DOMAIN ); ?></p>
									<?php else : ?>
										<p class="bc-addons__note"><?php echo esc_html( $bingothemev1_choosable ? __( 'Add-ons are optional. Select an add-on, then choose its quantity.', Theme::TEXT_DOMAIN ) : __( 'Add-ons are optional.', Theme::TEXT_DOMAIN ) ); ?></p>
										<?php if ( 'partial' === $bingothemev1_addons['state'] ) : ?>
											<p class="calendar-notice bc-addons__notice"><?php esc_html_e( 'Some add-ons for this Package couldn’t be checked right now.', Theme::TEXT_DOMAIN ); ?></p>
										<?php endif; ?>
										<?php if ( $bingothemev1_searchable ) : ?>
											<div class="bc-addons__search">
												<label class="screen-reader-text" for="<?php echo esc_attr( $bingothemev1_search ); ?>"><?php /* translators: %s: Package name. */ echo esc_html( sprintf( __( 'Search add-ons for %s by name', Theme::TEXT_DOMAIN ), $bingothemev1_item['name'] ) ); ?></label>
												<?php echo Template::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static decorative SVG. ?>
												<input type="search" class="bc-addons__search-input" id="<?php echo esc_attr( $bingothemev1_search ); ?>" placeholder="<?php esc_attr_e( 'Search add-ons', Theme::TEXT_DOMAIN ); ?>" autocomplete="off" spellcheck="false" data-addon-search
													aria-controls="<?php echo esc_attr( 'bc-addon-grid-' . $bingothemev1_package_id ); ?>" aria-describedby="<?php echo esc_attr( $bingothemev1_search . '-status' ); ?>">
											</div>
										<?php endif; ?>
										<ul class="bc-addons__grid" id="<?php echo esc_attr( 'bc-addon-grid-' . $bingothemev1_package_id ); ?>">
											<?php foreach ( $bingothemev1_addons['items'] as $bingothemev1_addon ) : ?>
												<?php
												$bingothemev1_check = 'bc-addon-' . $bingothemev1_package_id . '-' . (int) $bingothemev1_addon['id'];
												$bingothemev1_qty   = $bingothemev1_check . '-quantity';
												$bingothemev1_max   = (int) $bingothemev1_addon['max_quantity'];
												$bingothemev1_image = $bingothemev1_addon['image_id'] > 0 ? wp_get_attachment_image( $bingothemev1_addon['image_id'], 'medium', false, [ 'alt' => '', 'loading' => 'lazy', 'sizes' => '(max-width: 640px) 45vw, 200px' ] ) : '';
												?>
												<li class="addon-card" data-addon-card>
													<input type="checkbox" class="addon-card__check" id="<?php echo esc_attr( $bingothemev1_check ); ?>"
														data-addon-id="<?php echo esc_attr( (string) $bingothemev1_addon['id'] ); ?>" data-addon-name="<?php echo esc_attr( $bingothemev1_addon['name'] ); ?>"
														data-max="<?php echo esc_attr( (string) $bingothemev1_max ); ?>" data-quantity="<?php echo esc_attr( $bingothemev1_qty ); ?>">
													<label class="addon-card__label" for="<?php echo esc_attr( $bingothemev1_check ); ?>">
														<?php if ( '' !== $bingothemev1_image ) : ?>
															<span class="addon-card__media"><?php echo $bingothemev1_image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- native escaped image markup; the name labels the control. ?></span>
														<?php else : ?>
															<span class="addon-card__media is-placeholder"><?php echo Template::icon( 'image' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static decorative SVG. ?></span>
														<?php endif; ?>
														<span class="addon-card__name"><?php echo esc_html( $bingothemev1_addon['name'] ); ?></span>
														<span class="addon-card__price"><span data-purchase-price><?php echo wp_kses_post( $bingothemev1_addon['price_html'] ); ?></span> <?php esc_html_e( 'each', Theme::TEXT_DOMAIN ); ?></span>
													</label>
													<div class="addon-card__quantity">
														<label class="addon-card__quantity-label" for="<?php echo esc_attr( $bingothemev1_qty ); ?>"><?php esc_html_e( 'Quantity', Theme::TEXT_DOMAIN ); ?><span class="screen-reader-text"> <?php /* translators: %s: add-on name. */ echo esc_html( sprintf( __( 'of %s', Theme::TEXT_DOMAIN ), $bingothemev1_addon['name'] ) ); ?></span></label>
														<?php // The buttons are pointer conveniences; keyboards use the field's own arrow keys, so they stay out of the tab order. ?>
														<span class="addon-card__stepper">
															<button type="button" class="addon-card__step" data-step="-1" tabindex="-1" disabled aria-label="<?php /* translators: %s: add-on name. */ echo esc_attr( sprintf( __( 'Decrease quantity of %s', Theme::TEXT_DOMAIN ), $bingothemev1_addon['name'] ) ); ?>">&minus;</button>
															<input type="number" class="addon-card__qty" id="<?php echo esc_attr( $bingothemev1_qty ); ?>" min="0" max="<?php echo esc_attr( (string) $bingothemev1_max ); ?>" step="1" value="0" inputmode="numeric" disabled
																aria-describedby="<?php echo esc_attr( $bingothemev1_qty . '-range ' . $bingothemev1_qty . '-error' ); ?>">
															<button type="button" class="addon-card__step" data-step="1" tabindex="-1" disabled aria-label="<?php /* translators: %s: add-on name. */ echo esc_attr( sprintf( __( 'Increase quantity of %s', Theme::TEXT_DOMAIN ), $bingothemev1_addon['name'] ) ); ?>">+</button>
														</span>
														<span class="addon-card__range" id="<?php echo esc_attr( $bingothemev1_qty . '-range' ); ?>"><?php /* translators: %d: largest permitted quantity. */ echo esc_html( sprintf( __( 'Maximum quantity: %d', Theme::TEXT_DOMAIN ), $bingothemev1_max ) ); ?></span>
													</div>
													<p class="addon-card__error" id="<?php echo esc_attr( $bingothemev1_qty . '-error' ); ?>" hidden></p>
												</li>
											<?php endforeach; ?>
										</ul>
										<?php if ( $bingothemev1_searchable ) : ?>
											<p class="bc-addons__no-match" id="<?php echo esc_attr( $bingothemev1_search . '-status' ); ?>" data-addons-no-match aria-live="polite"></p>
										<?php endif; ?>
									<?php endif; ?>
								</fieldset>
							<?php endforeach; ?>
						</div>
						<section class="bc-purchase__summary" aria-labelledby="bc-purchase-summary-title">
							<h4 id="bc-purchase-summary-title"><?php esc_html_e( 'Your selection', Theme::TEXT_DOMAIN ); ?></h4>
							<dl class="bc-purchase__lines">
								<div class="bc-purchase__line">
									<dt><?php esc_html_e( 'Session', Theme::TEXT_DOMAIN ); ?></dt>
									<dd><?php echo esc_html( $bingothemev1_session['name'] ); ?><span class="bc-purchase__when"><?php echo esc_html( $bingothemev1_when ); ?></span></dd>
								</div>
								<div class="bc-purchase__line">
									<dt><?php esc_html_e( 'Package', Theme::TEXT_DOMAIN ); ?></dt>
									<dd data-summary-package><?php esc_html_e( 'Not chosen yet', Theme::TEXT_DOMAIN ); ?></dd>
								</div>
								<div class="bc-purchase__line">
									<dt><?php esc_html_e( 'Add-ons', Theme::TEXT_DOMAIN ); ?></dt>
									<dd data-summary-addons><?php esc_html_e( 'None', Theme::TEXT_DOMAIN ); ?></dd>
								</div>
							</dl>
							<p class="bc-purchase__total-note"><?php esc_html_e( 'Your cart shows the total.', Theme::TEXT_DOMAIN ); ?></p>
							<div class="bc-purchase__actions">
								<button type="submit" class="btn btn--primary btn--lg bc-purchase__submit" data-purchase-submit disabled><?php esc_html_e( 'Add to cart', Theme::TEXT_DOMAIN ); ?></button>
								<a class="btn btn--light btn--lg" href="<?php echo esc_url( $bingothemev1_purchase['cart_url'] ); ?>" data-purchase-view-cart hidden><?php esc_html_e( 'View cart', Theme::TEXT_DOMAIN ); ?></a>
							</div>
							<div class="bc-purchase__result" data-purchase-result hidden></div>
						</section>
					</div>
				</div>
				<p class="bc-purchase__note" data-purchase-nojs><?php esc_html_e( 'Turn on JavaScript to choose a Package and add it to your cart.', Theme::TEXT_DOMAIN ); ?></p>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php if ( null !== $bingothemev1_purchase ) : ?>
	<p class="screen-reader-text" data-purchase-status role="status" aria-live="polite" aria-atomic="true"></p>
<?php endif; ?>
