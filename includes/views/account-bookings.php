<?php
/**
 * View：會員「我的帳戶 → 我的預約」分頁。
 *
 * 傳入變數：$bookings（該會員的已確認/已完成預約，即將到來排前面）。
 *
 * 「取消預約」按鈕只是體驗層：真正的期限把關在 UAPPT_Account::handle_customer_cancel()。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( empty( $bookings ) ) : ?>
	<p><?php esc_html_e( '目前沒有任何預約紀錄。', 'ultimate-appointments' ); ?></p>
<?php else : ?>
	<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive uappt-account-bookings-table">
		<thead>
			<tr>
				<th><?php esc_html_e( '服務項目', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '時段', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '狀態', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '訂單', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '行事曆', 'ultimate-appointments' ); ?></th>
				<th><?php esc_html_e( '操作', 'ultimate-appointments' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $bookings as $booking ) : ?>
				<?php $order = $booking['order_id'] ? wc_get_order( $booking['order_id'] ) : null; ?>
				<tr>
					<td data-title="<?php esc_attr_e( '服務項目', 'ultimate-appointments' ); ?>">
						<?php
						echo esc_html(
							UAPPT_Booking::get_booking_display_name( $booking )
						);
						?>
					</td>
					<td data-title="<?php esc_attr_e( '時段', 'ultimate-appointments' ); ?>">
						<?php echo esc_html( UAPPT_Admin::format_booking_label( $booking ) ); ?>
					</td>
					<td data-title="<?php esc_attr_e( '狀態', 'ultimate-appointments' ); ?>">
						<?php echo esc_html( UAPPT_Admin::booking_status_label( $booking ) ); ?>
					</td>
					<td data-title="<?php esc_attr_e( '訂單', 'ultimate-appointments' ); ?>">
						<?php if ( $order ) : ?>
							<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
								#<?php echo esc_html( $order->get_order_number() ); ?>
							</a>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td data-title="<?php esc_attr_e( '行事曆', 'ultimate-appointments' ); ?>">
						<?php echo wp_kses_post( UAPPT_Calendar::render_links( $booking, 'stacked' ) ); ?>
					</td>
					<td data-title="<?php esc_attr_e( '操作', 'ultimate-appointments' ); ?>">
						<?php if ( UAPPT_Account::can_customer_cancel( $booking ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-cancel-form">
								<input type="hidden" name="action" value="uappt_customer_cancel" />
								<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>" />
								<?php wp_nonce_field( 'uappt_customer_cancel_' . $booking['id'] ); ?>
								<button type="submit" class="button uappt-cancel-button"
									onclick="return window.confirm( <?php echo esc_attr( wp_json_encode( __( '確定要取消這筆預約嗎？這筆訂單尚未付款，取消後訂單會一併取消、時段立即釋放給其他客人，無法復原。', 'ultimate-appointments' ) ) ); ?> );">
									<?php esc_html_e( '取消預約', 'ultimate-appointments' ); ?>
								</button>
							</form>
						<?php elseif ( UAPPT_Admin::is_awaiting_payment( $booking ) ) : ?>
							<?php // 待付款但已經過了自助取消的期限（見 UAPPT_Account::can_customer_cancel()）。 ?>
							<span class="uappt-cancel-locked description">
								<?php
								$deadline_hours = UAPPT_Product::get_cancel_deadline_hours();
								if ( $deadline_hours > 0 ) {
									printf(
										/* translators: %d: 取消期限小時數 */
										esc_html__( '服務前 %d 小時內請聯繫客服', 'ultimate-appointments' ),
										(int) $deadline_hours
									);
								} else {
									esc_html_e( '請聯繫客服', 'ultimate-appointments' );
								}
								?>
							</span>
						<?php elseif ( UAPPT_Booking::STATUS_CONFIRMED === $booking['status'] ) : ?>
							<?php // 已付款：一律透過客服取消／退款，不開放自助取消，見 can_customer_cancel()。 ?>
							<span class="uappt-cancel-locked description">
								<?php esc_html_e( '如需取消請聯繫客服', 'ultimate-appointments' ); ?>
							</span>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
