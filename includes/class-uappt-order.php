<?php
/**
 * 訂單事件串接：付款完成 → 確認預約；取消/失敗/退款 → 釋放預約。
 *
 * 全部掛在 WooCommerce 標準訂單 hook 上（含綠界等任何金流串接都會觸發的
 * woocommerce_payment_complete），不需要修改既有電商模組或結帳流程。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Order {

	/**
	 * @var UAPPT_Order|null
	 */
	protected static $instance = null;

	/**
	 * 單例。
	 *
	 * @return UAPPT_Order
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 建構子：掛載 hooks。
	 */
	protected function __construct() {
		add_action( 'woocommerce_payment_complete', array( $this, 'handle_payment_complete' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_paid_status' ), 10, 3 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_paid_status' ), 10, 3 );

		// 收款方式：依 gateway 對應關係補上。刻意跟 handle_payment_complete()
		// 分開掛而不是塞進那一支——那支在「這張訂單沒有需要確認的暫留預約」時
		// 會提早 return，而線上訂單第二次觸發（processing → completed）正好就是
		// 那個情況，混在一起會變成只有第一次有效的隱性相依。
		add_action( 'woocommerce_payment_complete', array( $this, 'handle_payment_method' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_payment_method' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_payment_method' ) );

		add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_release' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'handle_release' ) );
		add_action( 'woocommerce_order_status_failed', array( $this, 'handle_release' ) );

		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render_booking_link_in_order' ), 10, 3 );
	}

	/**
	 * 付款完成：確認訂單內所有預約；若原暫留已失效，會嘗試重新鎖定同一時段，
	 * 失敗則在訂單留下備註並提醒商家人工處理，避免「已收款但時段消失」的情況被忽略。
	 *
	 * `woocommerce_payment_complete` 不帶「從哪個狀態來」，所以這條路不補鎖 cancelled
	 * （見 UAPPT_Booking::confirm_or_relock()）。訂單真的從取消回到已付款時，
	 * 狀態轉換那一下會先走 handle_paid_status()，那裡知道來源狀態。
	 *
	 * @param int  $order_id         訂單 ID。
	 * @param bool $relock_cancelled 見 UAPPT_Booking::confirm_by_order()。
	 */
	public function handle_payment_complete( $order_id, $relock_cancelled = false ) {
		$results = UAPPT_Booking::confirm_by_order( $order_id, true === $relock_cancelled );
		if ( empty( $results ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $results as $result ) {
			if ( ! $result['ok'] ) {
				$order->add_order_note(
					sprintf(
						/* translators: %d: 預約紀錄 ID */
						__( '⚠️ 預約時段確認失敗：原本鎖定的時段（預約 #%d）已逾時釋放並被其他訂單搶走，客人已完成付款但目前沒有對應時段，請盡快聯繫客人協調改期或退款。', 'ultimate-appointments' ),
						$result['old_booking_id']
					)
				);
				continue;
			}

			if ( $result['relocked'] ) {
				$this->update_order_item_booking_meta( $order, $result['old_booking_id'], $result['booking_id'] );
				$order->add_order_note(
					sprintf(
						/* translators: %d: 新預約紀錄 ID */
						__( '客人付款完成時，原暫留時段已逾時，系統已成功用同一時段重新鎖定為已確認（預約 #%d）。', 'ultimate-appointments' ),
						$result['booking_id']
					)
				);
			}
		}
	}

	/**
	 * 訂單進入已付款狀態（processing／completed）。
	 *
	 * ⚠️ **本來就已經付款的訂單不處理**（v3.0.1）：processing → completed 是「服務做完了」
	 * 不是「剛付款」，那時候訂單裡的預約早在第一次進 processing 就確認過，之後的狀態
	 * （已完成、未到、被單獨取消）都是有人刻意改的，不能再被「確認／補鎖」一次。
	 *
	 * 從 cancelled／failed／refunded 回來的才補鎖 cancelled 的預約：那批是跟著訂單取消
	 * 一起被 handle_release() 釋放的。
	 *
	 * @param int      $order_id   訂單 ID。
	 * @param WC_Order $order      訂單（WooCommerce 傳入，未使用）。
	 * @param array    $transition WooCommerce 的狀態轉換資訊（含 'from'）。
	 */
	public function handle_paid_status( $order_id, $order = null, $transition = array() ) {
		$from = is_array( $transition ) && isset( $transition['from'] ) ? (string) $transition['from'] : '';

		if ( in_array( $from, array( 'processing', 'completed' ), true ) ) {
			return;
		}

		$this->handle_payment_complete( $order_id, in_array( $from, array( 'cancelled', 'failed', 'refunded' ), true ) );
	}

	/**
	 * 訂單付款完成：把 gateway 對應到的收款方式與費率補進預約。
	 *
	 * @param int $order_id 訂單 ID。
	 */
	public function handle_payment_method( $order_id ) {
		UAPPT_Payment::fill_from_gateway( $order_id );
	}

	/**
	 * 訂單取消／付款失敗／退款：釋放對應預約的時段佔用。
	 *
	 * @param int $order_id 訂單 ID。
	 */
	public function handle_release( $order_id ) {
		UAPPT_Booking::release_by_order( $order_id, UAPPT_Booking::STATUS_CANCELLED );
	}

	/**
	 * 重新鎖定產生新的預約 ID 時，把訂單項目 meta 上的 _uappt_booking_id 一併更新，
	 * 避免之後查詢時還指向已失效的舊紀錄。
	 *
	 * @param WC_Order $order 訂單物件。
	 * @param int      $old_booking_id 舊預約 ID。
	 * @param int      $new_booking_id 新預約 ID。
	 */
	protected function update_order_item_booking_meta( $order, $old_booking_id, $new_booking_id ) {
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( (int) $item->get_meta( '_uappt_booking_id', true ) === (int) $old_booking_id ) {
				$item->update_meta_data( '_uappt_booking_id', $new_booking_id );
				$item->save();
			}
		}
	}

	/**
	 * 在後台訂單編輯頁的商品項目下方，顯示這筆預約的時段/狀態，並附「管理此
	 * 預約」連結直接跳到預約編輯頁。客服接到電話通常是先查到訂單，這條路徑
	 * 補上從訂單直接跳去改期/取消/加備註，不用再手動去預約列表找。
	 *
	 * @param int                  $item_id 訂單項目 ID。
	 * @param WC_Order_Item_Product $item 訂單項目物件。
	 * @param WC_Product|false     $product 商品物件（未使用，符合 hook 簽章保留）。
	 */
	public function render_booking_link_in_order( $item_id, $item, $product ) {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$booking_id = $item->get_meta( '_uappt_booking_id', true );
		if ( ! $booking_id ) {
			return;
		}

		$booking = UAPPT_Booking::get( $booking_id );
		if ( ! $booking ) {
			return;
		}

		$edit_url = add_query_arg(
			array(
'page'       => UAPPT_Admin::PAGE_SLUG,
				'section'       => 'bookings',
				'action'     => 'edit',
				'booking_id' => $booking_id,
			),
			admin_url( 'admin.php' )
		);

		// 人員一定要在這裡顯示：客人選「不指定」時，訂單項目 meta 刻意不寫服務人員
		// （見 UAPPT_Cart::save_order_item_meta()），客服從訂單頁就看不到目前排給誰了。
		// 這行是後台專用（上面已擋掉非管理者），補回那個資訊。
		$staff_label = UAPPT_Admin::staff_label( (int) $booking['staff_id'] );
		if ( UAPPT_Booking::ASSIGNMENT_PENDING === $booking['assignment_state'] ) {
			$staff_label .= __( '（待分派）', 'ultimate-appointments' );
		} elseif ( empty( $booking['staff_requested'] ) ) {
			$staff_label .= __( '（系統安排，客人未指定）', 'ultimate-appointments' );
		}

		printf(
			'<p class="uappt-order-booking-link">%1$s（%2$s）｜ %3$s：%4$s ｜ <a href="%5$s">%6$s</a></p>',
			esc_html( UAPPT_Admin::format_booking_label( $booking ) ),
			esc_html( UAPPT_Admin::booking_status_label( $booking ) ),
			esc_html__( '服務人員', 'ultimate-appointments' ),
			esc_html( $staff_label ),
			esc_url( $edit_url ),
			esc_html__( '管理此預約', 'ultimate-appointments' )
		);
	}
}
