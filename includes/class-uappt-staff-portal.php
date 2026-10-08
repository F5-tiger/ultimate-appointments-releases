<?php
/**
 * 前台員工中心：綁定了人員資料的 WordPress 使用者，可以在會員中心看到自己的
 * 班表、標記自己的預約完成／未到、寫內部備註，以及送出排班申請（送出後要
 * 主管在後台「排班申請」審核通過才會真的生效，見 UAPPT_Shift_Request）。
 *
 * 結構直接照抄 class-uappt-account.php：WooCommerce 帳戶端點 + query var +
 * 選單項目 + admin_post_* handler + redirect_with_notice()。同一個坑也一樣
 * 要注意：redirect_with_notice() 之後的請求是走 admin-post.php 的「後台」
 * 載入分支，這裡不能呼叫 wc_add_notice()（函式還沒被 include，會 fatal），
 * 訊息文字要留給 render_endpoint_content()（貨真價實的前台頁面請求）自己印。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Staff_Portal {

	const ENDPOINT = 'uappt-schedule';

	const ENDPOINT_MONTHLY_DETAIL = 'uappt-monthly-detail';

	/**
	 * @var UAPPT_Staff_Portal|null
	 */
	protected static $instance = null;

	/**
	 * 單例。
	 *
	 * @return UAPPT_Staff_Portal
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
		add_action( 'woocommerce_account_' . self::ENDPOINT_MONTHLY_DETAIL . '_endpoint', array( $this, 'render_monthly_detail_endpoint_content' ) );

		add_action( 'admin_post_uappt_staff_complete_booking', array( $this, 'handle_complete_booking' ) );
		add_action( 'admin_post_uappt_staff_no_show_booking', array( $this, 'handle_no_show_booking' ) );
		add_action( 'admin_post_uappt_staff_revert_booking', array( $this, 'handle_revert_booking' ) );
		add_action( 'admin_post_uappt_staff_update_note', array( $this, 'handle_update_note' ) );
		add_action( 'admin_post_uappt_staff_submit_shift_request', array( $this, 'handle_submit_shift_request' ) );
		add_action( 'admin_post_uappt_staff_withdraw_shift_request', array( $this, 'handle_withdraw_shift_request' ) );
		add_action( 'admin_post_uappt_staff_withdraw_shift_request_day', array( $this, 'handle_withdraw_shift_request_day' ) );
	}

	/**
	 * 註冊「我的班表」與「本月明細」端點。新端點一定要搭配
	 * UAPPT_Install::schedule_rewrite_flush()，否則會 404——「我的班表」不用
	 * 另外呼叫，因為那一版同時把 UAPPT_DB_VERSION 往前推進（新增
	 * bookings.staff_note），maybe_upgrade() 本身就會排定一次 flush（見
	 * ultimate-appointments.php）；「本月明細」是後來從同一頁拆出來的新端點，
	 * 沒有搭配資料庫版本異動，當時另外用一次性旗標排定 flush（v3.1.0 刪掉：
	 * 新安裝啟用時本來就會 flush）。
	 */
	public function register_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( self::ENDPOINT_MONTHLY_DETAIL, EP_ROOT | EP_PAGES );
	}

	/**
	 * 讓 WooCommerce 認得這個端點對應的 query var。
	 *
	 * @param array $vars 現有 query vars。
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::ENDPOINT;
		$vars[] = self::ENDPOINT_MONTHLY_DETAIL;
		return $vars;
	}

	/**
	 * 在「我的帳戶」選單插入「我的班表」跟「本月明細」——只有綁定了人員資料、
	 * 且擁有 `uappt_view_own_schedule` 能力的使用者才看得到。一般客人（customer
	 * 角色）完全不會多這兩個選單項目。
	 *
	 * 「我的班表」（填寫排班申請）跟「本月明細」（查看/處理既有預約）刻意拆成
	 * 兩個獨立頁籤，不是同一頁裡的兩個區塊——前者是輸入密集的任務，後者是
	 * 瀏覽/操作既有紀錄的任務，混在一起兩邊都不好掃視。
	 *
	 * @param array $items 現有選單項目（有序關聯陣列）。
	 * @return array
	 */
	public function add_menu_item( $items ) {
		if ( ! is_user_logged_in() || ! current_user_can( UAPPT_Caps::CAP_VIEW_OWN_SCHEDULE ) ) {
			return $items;
		}
		if ( ! UAPPT_Staff::get_by_user_id( get_current_user_id() ) ) {
			return $items;
		}

		$new_items = array();
		$inserted  = false;

		foreach ( $items as $key => $label ) {
			$new_items[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new_items[ self::ENDPOINT ]                 = __( '我的班表', 'ultimate-appointments' );
				$new_items[ self::ENDPOINT_MONTHLY_DETAIL ]  = __( '本月明細', 'ultimate-appointments' );
				$inserted                                     = true;
			}
		}

		if ( ! $inserted ) {
			$new_items[ self::ENDPOINT ]                = __( '我的班表', 'ultimate-appointments' );
			$new_items[ self::ENDPOINT_MONTHLY_DETAIL ] = __( '本月明細', 'ultimate-appointments' );
		}

		return $new_items;
	}

	/**
	 * 輸出「我的班表」分頁內容。
	 */
	public function render_endpoint_content() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( ! current_user_can( UAPPT_Caps::CAP_VIEW_OWN_SCHEDULE ) ) {
			echo '<p>' . esc_html__( '權限不足。', 'ultimate-appointments' ) . '</p>';
			return;
		}

		$staff = UAPPT_Staff::get_by_user_id( get_current_user_id() );
		if ( ! $staff ) {
			echo '<p>' . esc_html__( '這個帳號尚未綁定人員資料，請聯繫店家。', 'ultimate-appointments' ) . '</p>';
			return;
		}

		$this->render_pending_notice();

		// 人員 status=inactive：這裡只影響能不能送出新的排班申請，跟看不看得到
		// 既有預約無關（那是 render_monthly_detail_endpoint_content() 的
		// $can_manage_bookings，停用只代表「暫時不排新班」，不該連自己過去／
		// 既有的預約都看不到）。
		$can_submit_requests = current_user_can( UAPPT_Caps::CAP_SUBMIT_SHIFT_REQUESTS ) && 'active' === $staff['status'];

		$month = isset( $_GET['uappt_month'] ) ? sanitize_text_field( wp_unslash( $_GET['uappt_month'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$month = current_time( 'Y-m' );
		}

		$calendar = UAPPT_Staff::get_schedule_month( $staff, $month );

		$pending_batches = UAPPT_Shift_Request::query_batches(
			array(
				'staff_id'          => $staff['id'],
				'status'            => UAPPT_Shift_Request::STATUS_PENDING,
				'order_by_date_asc' => true,
				'limit'             => 500,
			)
		);

		require UAPPT_PLUGIN_DIR . 'includes/views/account-schedule.php';
	}

	/**
	 * 輸出「本月明細」分頁內容——跟「我的班表」共用同一套人員/月曆解析邏輯，
	 * 但不需要 $pending_batches（排班申請審核中列表）跟 $can_submit_requests
	 * （送出申請的權限），那些只有「我的班表」的表單會用到。
	 */
	public function render_monthly_detail_endpoint_content() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( ! current_user_can( UAPPT_Caps::CAP_VIEW_OWN_SCHEDULE ) ) {
			echo '<p>' . esc_html__( '權限不足。', 'ultimate-appointments' ) . '</p>';
			return;
		}

		$staff = UAPPT_Staff::get_by_user_id( get_current_user_id() );
		if ( ! $staff ) {
			echo '<p>' . esc_html__( '這個帳號尚未綁定人員資料，請聯繫店家。', 'ultimate-appointments' ) . '</p>';
			return;
		}

		$this->render_pending_notice();

		// 跟人員 status 無關——停用只代表「暫時不排新班」（見
		// render_endpoint_content() 裡 $can_submit_requests 的說明），不該連自己
		// 過去／既有的預約都看不到，所以這裡不檢查 status。
		$can_manage_bookings = current_user_can( UAPPT_Caps::CAP_MANAGE_OWN_BOOKINGS );

		$month = isset( $_GET['uappt_month'] ) ? sanitize_text_field( wp_unslash( $_GET['uappt_month'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$month = current_time( 'Y-m' );
		}

		$calendar = UAPPT_Staff::get_schedule_month( $staff, $month );

		$selected_date = isset( $_GET['uappt_date'] ) ? sanitize_text_field( wp_unslash( $_GET['uappt_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected_date = $this->resolve_selected_date( $calendar, $month, $selected_date );

		// 員工看不看得到自己的業績金額是店家的決定：抽成制的店需要，不想讓
		// 員工知道單價的店要能關掉。預設顯示。
		$show_amount = (bool) get_option( 'uappt_staff_portal_show_amount', 1 );

		require UAPPT_PLUGIN_DIR . 'includes/views/account-monthly-detail.php';
	}

	/**
	 * 決定「本月明細」要展開哪一天。
	 *
	 * 順序：網址帶的日期（必須真的在這個月曆的格線裡）→ 今天（今天落在這個月時）
	 * → 這個月第一個有預約的日子 → 月初。
	 *
	 * 第三順位是刻意的：切到一個過去的月份時，預設落在月初通常是空的，員工還要
	 * 自己找哪天有東西；直接跳到第一個有預約的日子，畫面一打開就有內容可看。
	 *
	 * @param array  $calendar  get_schedule_month() 的回傳值。
	 * @param string $month     月份 (Y-m)。
	 * @param string $requested 網址帶進來的日期，可能是空的或亂填的。
	 * @return string Y-m-d。
	 */
	protected function resolve_selected_date( $calendar, $month, $requested ) {
		// 只接受真的在格線裡的日期。格式對但不在這個月的（例如手改網址）會
		// 讓 view 找不到那一天的資料，畫面上變成一片空白而不是錯誤訊息。
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $requested ) && isset( $calendar['days'][ $requested ] ) ) {
			return $requested;
		}

		$today = current_time( 'Y-m-d' );
		if ( substr( $today, 0, 7 ) === $month ) {
			return $today;
		}

		foreach ( $calendar['days'] as $date => $day ) {
			if ( $day['is_in_month'] && ! empty( $day['bookings'] ) ) {
				return $date;
			}
		}

		return $month . '-01';
	}

	
	/**
	 * 確認目前登入者已綁定人員、且擁有指定能力，回傳對應的人員資料；
	 * 不符合就直接 wp_die()。所有寫入動作的第一行都呼叫這支，避免每個
	 * handler 各自重寫一次身分檢查。
	 *
	 * @param string $cap 需要的 capability。
	 * @return array 人員資料。
	 */
	protected function require_staff_with_cap( $cap ) {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( '請先登入。', 'ultimate-appointments' ) );
		}
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		$staff = UAPPT_Staff::get_by_user_id( get_current_user_id() );
		if ( ! $staff ) {
			wp_die( esc_html__( '這個帳號尚未綁定人員資料。', 'ultimate-appointments' ) );
		}
		return $staff;
	}

	/**
	 * 這筆預約是不是「我自己的客人預約」——時段佔用（block）跟其他人員的
	 * 預約都不算，跟 UAPPT_Account::handle_customer_cancel() 檢查
	 * customer_id 是同一個精神：nonce 只證明表單是我們發出去的，不能證明
	 * 這個人有權動這筆資料，一定要另外核對擁有權。
	 *
	 * @param array|null $booking 預約資料（可能是 null，例如 booking_id 打錯）。
	 * @param array      $staff   目前登入者對應的人員資料。
	 * @return bool
	 */
	protected function is_own_booking( $booking, $staff ) {
		return $booking
			&& UAPPT_Booking::KIND_BOOKING === $booking['kind']
			&& (int) $booking['staff_id'] === (int) $staff['id'];
	}

	/**
	 * 標記自己的預約為已完成。
	 *
	 * ⚠️ 已知限制：UAPPT_Booking::complete() 只接受 confirmed 狀態，「待付款」
	 * 的預約按不了完成。實務上綠界信用卡即時付款會直接變 confirmed，這個
	 * 情況很罕見，view 只在狀態是 confirmed 時才顯示這個按鈕，不需要另外
	 * 放寬 complete() 的守衛。
	 */
	public function handle_complete_booking() {
		$staff      = $this->require_staff_with_cap( UAPPT_Caps::CAP_MANAGE_OWN_BOOKINGS );
		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		check_admin_referer( 'uappt_staff_booking_' . $booking_id );

		$booking = UAPPT_Booking::get( $booking_id );
		if ( ! $this->is_own_booking( $booking, $staff ) ) {
			$this->redirect_with_notice( 'not_found' );
		}

		$this->redirect_with_notice( UAPPT_Booking::complete( $booking_id ) ? 'completed' : 'action_failed' );
	}

	/**
	 * 標記自己的預約為未到。
	 */
	public function handle_no_show_booking() {
		$staff      = $this->require_staff_with_cap( UAPPT_Caps::CAP_MANAGE_OWN_BOOKINGS );
		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		check_admin_referer( 'uappt_staff_booking_' . $booking_id );

		$booking = UAPPT_Booking::get( $booking_id );
		if ( ! $this->is_own_booking( $booking, $staff ) ) {
			$this->redirect_with_notice( 'not_found' );
		}

		$this->redirect_with_notice( UAPPT_Booking::mark_no_show( $booking_id ) ? 'no_show' : 'action_failed' );
	}

	/**
	 * 把自己標錯的「已完成」／「未到」還原成「已確認」。守衛（只收這兩個
	 * 狀態、不動 slot_grid）全部在 UAPPT_Booking::revert_to_confirmed() 裡。
	 */
	public function handle_revert_booking() {
		$staff      = $this->require_staff_with_cap( UAPPT_Caps::CAP_MANAGE_OWN_BOOKINGS );
		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		check_admin_referer( 'uappt_staff_booking_' . $booking_id );

		$booking = UAPPT_Booking::get( $booking_id );
		if ( ! $this->is_own_booking( $booking, $staff ) ) {
			$this->redirect_with_notice( 'not_found' );
		}

		$this->redirect_with_notice( UAPPT_Booking::revert_to_confirmed( $booking_id ) ? 'reverted' : 'action_failed' );
	}

	/**
	 * 寫自己這筆預約的內部備註。刻意寫進 `bookings.staff_note`，不是
	 * `note`——後者是後台管理者在用的內部備註欄位，共用會互相覆蓋。
	 */
	public function handle_update_note() {
		$staff      = $this->require_staff_with_cap( UAPPT_Caps::CAP_MANAGE_OWN_BOOKINGS );
		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		check_admin_referer( 'uappt_staff_booking_' . $booking_id );

		$booking = UAPPT_Booking::get( $booking_id );
		if ( ! $this->is_own_booking( $booking, $staff ) ) {
			$this->redirect_with_notice( 'not_found' );
		}

		$staff_note = isset( $_POST['staff_note'] ) ? wp_unslash( $_POST['staff_note'] ) : '';
		UAPPT_Booking::update_details( $booking_id, array( 'staff_note' => $staff_note ) );

		$this->redirect_with_notice( 'note_saved' );
	}

	/**
	 * 送出排班申請（排班／請假／登記時段佔用）。一律建立成 pending，核准前
	 * 完全不影響任何可預約時段——驗證與寫入都交給
	 * UAPPT_Shift_Request::create_batch()，這裡只負責把 $_POST 轉成它要的形狀。
	 *
	 * `dates[]` 是月曆上勾選的日期（可能只有一天）——**只選一天時走同一條
	 * 路**，不為「單日」另外開一條分岔；create_batch() 本身在只有一天時
	 * 產生的批次跟過去的單筆申請在資料庫裡完全等價（差別只是多了一個
	 * batch_key）。
	 */
	public function handle_submit_shift_request() {
		$staff = $this->require_staff_with_cap( UAPPT_Caps::CAP_SUBMIT_SHIFT_REQUESTS );
		check_admin_referer( 'uappt_staff_submit_shift_request' );

		if ( 'active' !== $staff['status'] ) {
			$this->redirect_with_notice( 'staff_inactive' );
		}

		$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$dates = isset( $_POST['dates'] ) && is_array( $_POST['dates'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['dates'] ) )
			: array();

		$data = array(
			'staff_id'   => $staff['id'],
			'type'       => $type,
			'dates'      => $dates,
			'staff_note' => isset( $_POST['staff_note'] ) ? sanitize_text_field( wp_unslash( $_POST['staff_note'] ) ) : '',
			'created_by' => get_current_user_id(),
		);

		if ( 'hours' === $type ) {
			$posted_hours = array();
			if ( isset( $_POST['hours'] ) && is_array( $_POST['hours'] ) ) {
				foreach ( $_POST['hours'] as $range ) {
					$posted_hours[] = array(
						isset( $range['start'] ) ? sanitize_text_field( wp_unslash( $range['start'] ) ) : '',
						isset( $range['end'] ) ? sanitize_text_field( wp_unslash( $range['end'] ) ) : '',
					);
				}
			}
			$data['hours'] = $posted_hours;
		} elseif ( 'block' === $type ) {
			$data['start_hm'] = isset( $_POST['start_hm'] ) ? sanitize_text_field( wp_unslash( $_POST['start_hm'] ) ) : '';
			$data['end_hm']   = isset( $_POST['end_hm'] ) ? sanitize_text_field( wp_unslash( $_POST['end_hm'] ) ) : '';
			$data['units']    = isset( $_POST['units'] ) ? absint( $_POST['units'] ) : 0;
		}

		$result = UAPPT_Shift_Request::create_batch( $data );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message() );
		}

		if ( ! empty( $result['skipped'] ) ) {
			$this->redirect_with_notice(
				'request_partial',
				sprintf(
					/* translators: 1: 成功送出的天數 2: 略過的天數 */
					__( '已送出 %1$d 天，%2$d 天因為當天已經有同類型的待審申請而略過。', 'ultimate-appointments' ),
					$result['created'],
					count( $result['skipped'] )
				)
			);
		}

		$this->redirect_with_notice( 'request_submitted' );
	}

	/**
	 * 撤回自己還在等待審核的申請——整批一起撤回。`batch_key` 也接受
	 * query_batches() 給的 `single-{id}` 形式（舊資料／單日申請），
	 * withdraw_batch() 會自己認得。
	 */
	public function handle_withdraw_shift_request() {
		$staff     = $this->require_staff_with_cap( UAPPT_Caps::CAP_SUBMIT_SHIFT_REQUESTS );
		$batch_key = isset( $_POST['batch_key'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_key'] ) ) : '';
		check_admin_referer( 'uappt_staff_withdraw_shift_request_' . $batch_key );

		$result = UAPPT_Shift_Request::withdraw_batch( $batch_key, $staff['id'] );

		if ( 0 === $result['ok'] ) {
			$this->redirect_with_notice( 'error', __( '找不到可以撤回的申請。', 'ultimate-appointments' ) );
		}

		$this->redirect_with_notice( 'request_withdrawn' );
	}

	/**
	 * 撤回批次裡的其中一天。
	 *
	 * 一次排一整個月是常態（見 create_batch()），其中一天填錯就要整批撤回重送
	 * 顯然不合理——引擎早就有單筆的 UAPPT_Shift_Request::withdraw()（擁有權與
	 * 「只能撤回 pending」的守衛都在它裡面），這裡不重寫任何驗證，只是多開一個
	 * 進入點，跟 withdraw_batch() 對同一支方法的用法完全一致。
	 *
	 * 撤到一天不剩的批次會自然從待審清單消失（query_batches() 只撈 pending），
	 * 不需要另外處理「空批次」——批次沒有自己的資料列，本來就是從成員列推導的。
	 */
	public function handle_withdraw_shift_request_day() {
		$staff      = $this->require_staff_with_cap( UAPPT_Caps::CAP_SUBMIT_SHIFT_REQUESTS );
		$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		check_admin_referer( 'uappt_staff_withdraw_shift_request_day_' . $request_id );

		$result = UAPPT_Shift_Request::withdraw( $request_id, $staff['id'] );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message() );
		}

		$this->redirect_with_notice( 'request_withdrawn' );
	}

	/**
	 * 帶著提示代碼（跟可選的錯誤訊息）轉回原本送出表單的那一頁。刻意不用
	 * wc_add_notice()——這裡是 admin-post.php 的 handler，走的是 WooCommerce
	 * 的「後台」載入分支，wc-notice-functions.php 根本還沒被 include，
	 * 呼叫下去是 fatal error，不是單純拿不到 session（同樣的坑
	 * UAPPT_Account::redirect_with_notice() 也踩過，說明抄那邊）。
	 *
	 * 「我的班表」跟「本月明細」拆成兩個頁籤之後，兩邊都有表單會呼叫這支，
	 * 不能再寫死轉回 self::ENDPOINT（我的班表）——不然在本月明細按「標記
	 * 完成」之類的按鈕，會被彈去我的班表，使用者會以為按鈕壞了。改用
	 * wp_get_referer()：每個表單的 wp_nonce_field() 預設就會帶出隱藏欄位
	 * `_wp_http_referer`，剛好可以拿來還原「使用者原本在哪個頁籤」；拿不到
	 * referer（理論上不該發生）才 fallback 回我的班表，維持舊行為。
	 *
	 * @param string $code    通知代碼。
	 * @param string $message 只有 code='error' 時才會用到的動態錯誤訊息。
	 */
	protected function redirect_with_notice( $code, $message = '' ) {
		$args = array( 'uappt_staff_notice' => $code );
		if ( '' !== $message ) {
			$args['uappt_staff_msg'] = rawurlencode( $message );
		}
		$referer = wp_get_referer();
		$base    = $referer ? $referer : wc_get_account_endpoint_url( self::ENDPOINT );
		$url     = add_query_arg( $args, $base );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * 把轉址帶回來的通知代碼印成前台看得到的訊息。這裡是貨真價實的前台頁面
	 * 請求（不是 admin-post.php），wc_print_notice() 已經載入，可以正常使用。
	 */
	protected function render_pending_notice() {
		if ( empty( $_GET['uappt_staff_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code = sanitize_key( wp_unslash( $_GET['uappt_staff_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'completed'         => array( __( '已標記為完成。', 'ultimate-appointments' ), 'success' ),
			'no_show'           => array( __( '已標記為未到。', 'ultimate-appointments' ), 'success' ),
			'reverted'          => array( __( '已還原為「已確認」。', 'ultimate-appointments' ), 'success' ),
			'note_saved'        => array( __( '備註已儲存。', 'ultimate-appointments' ), 'success' ),
			'request_submitted' => array( __( '申請已送出，請等待主管審核；核准前不會影響你目前的可預約時段。', 'ultimate-appointments' ), 'success' ),
			'request_partial'   => array(
				// WooCommerce 內建的通知型別只有 error／success／notice 三種
				// （見 wc_print_notice()），'warning' 不是其中之一，這裡用
				// 'notice'（中性樣式）而不是硬塞一個不存在的樣板。
				isset( $_GET['uappt_staff_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['uappt_staff_msg'] ) ) : __( '部分日期已略過。', 'ultimate-appointments' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'notice',
			),
			'request_withdrawn' => array( __( '申請已撤回。', 'ultimate-appointments' ), 'success' ),
			'not_found'         => array( __( '找不到這筆預約，或這筆預約不屬於你。', 'ultimate-appointments' ), 'error' ),
			'action_failed'     => array( __( '這個操作目前無法執行（可能狀態已經改變，請重新整理頁面確認）。', 'ultimate-appointments' ), 'error' ),
			'staff_inactive'    => array( __( '你目前是停用狀態，無法送出新的排班申請，請聯繫店家。', 'ultimate-appointments' ), 'error' ),
			'error'             => array(
				isset( $_GET['uappt_staff_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['uappt_staff_msg'] ) ) : __( '發生錯誤。', 'ultimate-appointments' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'error',
			),
		);

		if ( ! isset( $messages[ $code ] ) || ! function_exists( 'wc_print_notice' ) ) {
			return;
		}

		list( $message, $notice_type ) = $messages[ $code ];
		wc_print_notice( $message, $notice_type );
	}
}
