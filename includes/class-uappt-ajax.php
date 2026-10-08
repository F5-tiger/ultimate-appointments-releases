<?php
/**
 * AJAX 端點：查詢可預約時段（前台商品頁 + 後台手動建立預約共用同一支）。
 *
 * 實際「寫入」動作（建立/取消預約、人力資源與請假覆寫的新增修改刪除）刻意不做成 AJAX，
 * 而是走一般表單 POST + admin-post.php + nonce + capability 檢查，降低複雜度與風險，
 * 只有「查詢可預約時段」這種唯讀、即時性要求高的操作才用 AJAX。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Ajax {

	/**
	 * @var UAPPT_Ajax|null
	 */
	protected static $instance = null;

	/**
	 * 單例。
	 *
	 * @return UAPPT_Ajax
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
		add_action( 'wp_ajax_uappt_get_slots', array( $this, 'get_slots' ) );
		add_action( 'wp_ajax_nopriv_uappt_get_slots', array( $this, 'get_slots' ) );
		add_action( 'wp_ajax_uappt_get_staff_options', array( $this, 'get_staff_options' ) );
		add_action( 'wp_ajax_nopriv_uappt_get_staff_options', array( $this, 'get_staff_options' ) );
		add_action( 'wp_ajax_uappt_get_customer', array( $this, 'get_customer' ) );
	}

	/**
	 * 回傳某商品（或其中一個服務方案）目前的候選服務人員清單，供前台的人員下拉選單使用。
	 *
	 * 刻意獨立於 get_slots() 之外：選人員在選日期「之前」，這時候還沒有日期
	 * 可查，且換方案時只需要重新整理人員清單，不必連時段一起查。
	 */
	public function get_staff_options() {
		check_ajax_referer( 'uappt_slots_nonce', 'nonce' );

		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		$plan_key   = isset( $_GET['plan_key'] ) ? sanitize_key( wp_unslash( $_GET['plan_key'] ) ) : '';

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( '參數不正確。', 'ultimate-appointments' ) ), 400 );
		}

		// 商品「不開放客人指定服務人員」時，前台的候選清單就該是空的——不是隱藏
		// 選單那麼簡單，這支端點本身也要擋，避免客人繞過前台 UI 直接打這支 AJAX
		// 拿到完整名單。後台（手動建立預約／換人）不受這個限制，客服本來就需要
		// 能從候選名單裡指定人員，跟 get_slots() 的邊界政策是同一套判斷。
		//
		// 政策判斷與清單組裝都在 UAPPT_Availability_Query，跟前台預約精靈的 REST
		// 端點共用同一份——兩個邊界的判斷一旦不一致，就會出現「某個入口列得出來、
		// 另一個入口列不出來」。
		if ( ! UAPPT_Availability_Query::staff_list_allowed( $product_id ) ) {
			wp_send_json_success( array( 'staff' => array() ) );
		}

		wp_send_json_success( array( 'staff' => UAPPT_Availability_Query::staff_options( $product_id, $plan_key ) ) );
	}

	/**
	 * 回傳某商品在某日期的可預約時段清單，並附上分類頁籤資訊、是否顯示剩餘名額、
	 * 以及該日額滿時的最近可預約日期建議。
	 */
	public function get_slots() {
		check_ajax_referer( 'uappt_slots_nonce', 'nonce' );

		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		$plan_key   = isset( $_GET['plan_key'] ) ? sanitize_key( wp_unslash( $_GET['plan_key'] ) ) : '';
		$date_ymd   = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		$staff_id   = isset( $_GET['staff_id'] ) ? absint( $_GET['staff_id'] ) : 0;

		// 人員選擇器關閉時一律當作「不指定」查詢，跟加入購物車的把關一致；
		// 暫停接受預約、方案已停用則一個時段都不給。這三個都是**銷售政策**
		// （不是引擎該管的事，見 UAPPT_Availability_Query 的說明），而且後台
		// （手動建立預約／換人）不受限制——客服本來就需要能幫客人排。
		//
		// 判斷邏輯與回應組裝都在 UAPPT_Availability_Query，跟前台預約精靈的
		// REST 端點共用同一份，避免兩個邊界各寫一套之後走鐘。
		$staff_id = UAPPT_Availability_Query::effective_staff_id( $product_id, $staff_id );

		if ( UAPPT_Availability_Query::is_blocked( $product_id, $plan_key ) ) {
			wp_send_json_success( UAPPT_Availability_Query::blocked_slots_payload() );
		}

		if ( ! $product_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_ymd ) ) {
			wp_send_json_error( array( 'message' => __( '參數不正確。', 'ultimate-appointments' ) ), 400 );
		}

		// 回應的組裝（分類頁籤、最近可預約日、剩餘名額、團體預約欄位）都在
		// UAPPT_Availability_Query::slots_payload()，跟 REST 端點共用。
		$payload = UAPPT_Availability_Query::slots_payload( $product_id, $date_ymd, $plan_key, $staff_id );

		if ( is_wp_error( $payload ) ) {
			wp_send_json_error( array( 'message' => $payload->get_error_message() ), 400 );
		}

		wp_send_json_success( $payload );
	}

	/**
	 * 後台手動建立預約：管理員從會員搜尋下拉選好一位會員後，回傳姓名/電話/Email
	 * 供表單自動帶入。刻意不用 nopriv、且在方法內再檢查一次 capability，
	 * 因為這裡會回傳會員的聯絡資訊，不能讓任何登入使用者查得到。
	 */
	public function get_customer() {
		check_ajax_referer( 'uappt_slots_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( '權限不足。', 'ultimate-appointments' ) ), 403 );
		}

		$customer_id = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0;
		if ( ! $customer_id || ! class_exists( 'WC_Customer' ) ) {
			wp_send_json_error( array( 'message' => __( '參數不正確。', 'ultimate-appointments' ) ), 400 );
		}

		$customer = new WC_Customer( $customer_id );
		if ( ! $customer->get_id() ) {
			wp_send_json_error( array( 'message' => __( '找不到此會員。', 'ultimate-appointments' ) ), 404 );
		}

		$name = trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() );
		if ( '' === $name ) {
			$name = $customer->get_display_name();
		}

		wp_send_json_success(
			array(
				'name'  => $name,
				'phone' => $customer->get_billing_phone(),
				'email' => $customer->get_email(),
			)
		);
	}
}
