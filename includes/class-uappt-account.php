<?php
/**
 * 會員「我的帳戶 → 我的預約」分頁：讓客人自己看得到已確認/已完成的預約紀錄，
 * 減少「我約幾點？」這類詢問電話，也讓會員綁定在手動建立預約時更有意義。
 *
 * v2.1.0 起可以自行取消（限服務開始前 uappt_cancel_deadline_hours 小時之外）。
 * 取消只釋放時段、不自動退款——退款牽涉金流與店家政策，交給店家在訂單裡處理。
 *
 * 新增自訂端點需要 flush rewrite rules 才會生效，這件事交給
 * ultimate-appointments.php 的 uappt_maybe_flush_rewrite_rules()（外掛啟用/升級
 * 時會排定），這裡只負責註冊端點本身。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Account {

	const ENDPOINT = 'uappt-bookings';

	/**
	 * @var UAPPT_Account|null
	 */
	protected static $instance = null;

	/**
	 * 單例。
	 *
	 * @return UAPPT_Account
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
		add_action( 'init', array( $this, 'register_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_endpoint_content' ) );

		// 寫入動作走一般表單 POST + nonce + 身分檢查，不做成 AJAX（設計紀律：
		// 只有唯讀、即時性高的查詢才用 AJAX）。
		add_action( 'admin_post_uappt_customer_cancel', array( $this, 'handle_customer_cancel' ) );
	}

	/**
	 * 註冊「我的預約」端點。
	 */
	public function register_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * 讓 WooCommerce 認得這個端點對應的 query var。
	 *
	 * @param array $vars 現有 query vars。
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * 在「我的帳戶」選單插入「我的預約」，放在「訂單」之後，分類上比較合理。
	 *
	 * @param array $items 現有選單項目（有序關聯陣列）。
	 * @return array
	 */
	public function add_menu_item( $items ) {
		$new_items = array();
		$inserted  = false;

		foreach ( $items as $key => $label ) {
			$new_items[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new_items[ self::ENDPOINT ] = __( '我的預約', 'ultimate-appointments' );
				$inserted                     = true;
			}
		}

		// 找不到「訂單」項目是理論上不太會發生的情況（例如其他外掛整個換掉選單），
		// 附加在最後總比讓這個分頁完全無法從選單進入好。
		if ( ! $inserted ) {
			$new_items[ self::ENDPOINT ] = __( '我的預約', 'ultimate-appointments' );
		}

		return $new_items;
	}

	/**
	 * 輸出「我的預約」分頁內容。
	 */
	public function render_endpoint_content() {
		$customer_id = get_current_user_id();
		if ( ! $customer_id ) {
			return;
		}

		$this->render_pending_notice();

		$bookings = $this->get_customer_bookings( $customer_id );

		require UAPPT_PLUGIN_DIR . 'includes/views/account-bookings.php';
	}

	/**
	 * 判斷某筆預約現在是否還能由客人自己取消。
	 *
	 * 三個條件都要成立：狀態是「已確認」、服務還沒開始、而且距離開始時間還超過
	 * 設定的期限。期限設 0 代表只要還沒開始就能取消。
	 *
	 * @param array $booking 預約紀錄。
	 * @return bool
	 */
	public static function can_customer_cancel( $booking ) {
		// 已確認（已付款）的預約不開放自助取消：退款一定要透過管理者手動
		// 處理，讓客人自己按一按就把時段放掉，會產生「時段沒了、錢卻還沒退」
		// 的落差，那不是體驗變好，是把問題丟給對帳。要取消請聯繫客服。
		//
		// 只有「待付款」（held 但已經有訂單，例如客人選了 ATM 轉帳／超商代碼
		// 之類需要時間才能完成的付款方式，見「待付款訂單的時段保護」）能自助
		// 取消——這筆訂單客人根本還沒付錢，沒有退款問題，取消就只是單純把
		// 時段還回去、順便把訂單也取消掉（見 handle_customer_cancel()）。
		if ( ! $booking || ! UAPPT_Admin::is_awaiting_payment( $booking ) ) {
			return false;
		}

		// service_start 是以站台本地時間儲存的裸字串（無時區資訊），明確指定
		// wp_timezone() 解析後才能跟 time()（真 UTC）比較。
		$start = date_create( $booking['service_start'], wp_timezone() );
		if ( ! $start ) {
			return false;
		}

		$deadline_seconds = UAPPT_Product::get_cancel_deadline_hours() * HOUR_IN_SECONDS;

		return $start->getTimestamp() - $deadline_seconds > time();
	}

	/**
	 * 處理客人自行取消預約。
	 */
	public function handle_customer_cancel() {
		$customer_id = get_current_user_id();
		if ( ! $customer_id ) {
			wp_die( esc_html__( '請先登入。', 'ultimate-appointments' ) );
		}

		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		check_admin_referer( 'uappt_customer_cancel_' . $booking_id );

		$booking = UAPPT_Booking::get( $booking_id );

		// 一定要確認這筆預約屬於目前登入的人：nonce 只證明「表單是我們發出去的」，
		// 不能證明「這個人有權動這筆資料」。
		if ( ! $booking || (int) $booking['customer_id'] !== $customer_id ) {
			$this->redirect_with_notice( 'not_found' );
		}

		// 期限在這裡才是真正的防線；畫面上不顯示按鈕只是體驗層。
		if ( ! self::can_customer_cancel( $booking ) ) {
			$this->redirect_with_notice( 'too_late' );
		}

		// can_customer_cancel() 現在只放行「待付款」（held + 已有訂單），這種
		// 訂單客人根本還沒付錢，沒有退款問題，取消訂單本身就是正確動作，不該
		// 留一張沒人管的訂單在後台。直接取消訂單（而不是只呼叫
		// UAPPT_Booking::release()）：訂單一變成 cancelled 會觸發
		// UAPPT_Order::handle_release()，跟「待付款訂單逾時安全網」
		// （UAPPT_Booking::release_stale_pending_orders()）走的是同一條既有
		// 路徑，不需要另外重寫一份釋放邏輯，也順便涵蓋「同一張訂單掛了不只
		// 一筆預約」這種理論上的情況。
		$order = $booking['order_id'] ? wc_get_order( (int) $booking['order_id'] ) : null;
		if ( $order ) {
			$order->update_status(
				'cancelled',
				sprintf(
					/* translators: 1: 服務名稱, 2: 原本的時段 */
					__( '客人在完成付款前自行取消了預約：%1$s（%2$s），訂單已自動取消。', 'ultimate-appointments' ),
					UAPPT_Booking::get_booking_display_name( $booking ),
					UAPPT_Admin::format_booking_label( $booking )
				)
			);
		} else {
			// can_customer_cancel() 的定義本身就要求有訂單，理論上不會走到這裡，
			// 純粹是防禦性寫法，避免萬一資料異常時整段沒反應、時段也放不掉。
			UAPPT_Booking::release( (int) $booking['id'], UAPPT_Booking::STATUS_CANCELLED );
		}

		$this->redirect_with_notice( 'success' );
	}

	/**
	 * 帶著提示代碼轉回「我的預約」頁。
	 *
	 * 刻意不用 wc_add_notice()：這支處理常式是 admin-post.php 的 action（前台
	 * 表單送出後也是打到 wp-admin/admin-post.php），這個路徑走的是 WooCommerce
	 * 的「後台」載入分支，只在 !is_admin() 的前台請求才會 include
	 * wc-notice-functions.php（見 WC()->frontend_includes()）——這裡呼叫
	 * wc_add_notice() 會是「呼叫未定義函式」的 fatal error，因為函式根本還沒被
	 * include 進來，不是單純拿不到 session。改成帶一個代碼轉址，訊息文字留給
	 * 真正的前台頁面請求（render_endpoint_content()，那時 is_admin() 是
	 * false，函式都在）自己翻譯輸出。
	 *
	 * @param string $code 通知代碼：success / not_found / too_late。
	 */
	protected function redirect_with_notice( $code ) {
		$url = add_query_arg( 'uappt_cancel', $code, wc_get_account_endpoint_url( self::ENDPOINT ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * 把轉址帶回來的通知代碼印成前台看得到的訊息。
	 *
	 * 這裡是貨真價實的前台頁面請求（不是 admin-post.php），wc_add_notice() /
	 * wc_print_notice() 都已經載入，可以正常使用。
	 */
	protected function render_pending_notice() {
		if ( empty( $_GET['uappt_cancel'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code = sanitize_key( wp_unslash( $_GET['uappt_cancel'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'success'   => array( __( '已為您取消這筆預約。', 'ultimate-appointments' ), 'success' ),
			'not_found' => array( __( '找不到這筆預約。', 'ultimate-appointments' ), 'error' ),
			'too_late'  => array( __( '這筆預約已無法自行取消，請直接聯繫客服。', 'ultimate-appointments' ), 'error' ),
		);

		if ( ! isset( $messages[ $code ] ) || ! function_exists( 'wc_print_notice' ) ) {
			return;
		}

		list( $message, $notice_type ) = $messages[ $code ];
		wc_print_notice( $message, $notice_type );
	}

	/**
	 * 取得某會員的預約：已確認／已完成／未到，加上「已下單、正在等付款」的
	 * 暫留（銀行轉帳這類金流會讓訂單停在保留狀態，見 CLAUDE.md「待付款訂單的
	 * 時段保護」）。即將到來的排前面、已發生過的越新排越前面。
	 *
	 * 未到（no_show）刻意跟已完成放在同一組，不是漏了排除——那是一筆真實
	 * 發生過的預約紀錄，客人自己也知道當時沒去，隱藏起來只會讓「我的預約」
	 * 少一筆說得通的歷史紀錄，看起來像資料憑空消失。
	 *
	 * 純購物車暫留（held 但沒有 order_id）刻意不列進來——那只是客人還沒送出
	 * 的購物車內容，本來就看得到（購物車頁），列進「我的預約」只會讓人以為
	 * 自己已經訂到了一個其實隨時可能被系統逾時釋放的時段。
	 *
	 * @param int $customer_id 會員 ID。
	 * @return array
	 */
	protected function get_customer_bookings( $customer_id ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE customer_id = %d AND ( status IN (%s, %s, %s) OR ( status = %s AND order_id IS NOT NULL ) ) ORDER BY service_start ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$customer_id,
				UAPPT_Booking::STATUS_CONFIRMED,
				UAPPT_Booking::STATUS_COMPLETED,
				UAPPT_Booking::STATUS_NO_SHOW,
				UAPPT_Booking::STATUS_HELD
			),
			ARRAY_A
		);

		$now      = current_time( 'mysql' );
		$upcoming = array();
		$past     = array();

		foreach ( $rows as $row ) {
			if ( $row['service_start'] >= $now ) {
				$upcoming[] = $row;
			} else {
				$past[] = $row;
			}
		}

		// 已經發生過的預約改成新到舊排序（離現在最近的先看到）。
		$past = array_reverse( $past );

		return array_merge( $upcoming, $past );
	}
}
