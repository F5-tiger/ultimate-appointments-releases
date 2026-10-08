<?php
/**
 * 後台管理：人力資源、預約列表、手動建立預約、日曆檢視、設定。
 *
 * 「寫入」動作一律走 admin-post.php + nonce + capability 檢查的一般表單流程，
 * 沒有用 AJAX，降低複雜度與風險；唯讀、即時性高的「查可預約時段」與「查候選
 * 人員」則共用 class-uappt-ajax.php 的端點。
 *
 * v2.0.0：「服務資源（帶數量的池子）」全面改為「人力資源（具名個體）」，
 * 本檔案的服務資源 CRUD、預約列表/日曆的 pool_id 篩選都跟著改成 staff_id。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Admin {

	// v2.13.0 起從字面的 'manage_woocommerce' 改成獨立的 capability——
	// 30 個檢查點（6 個頁面 render、17 個 admin-post 寫入動作、選單註冊）
	// 全部共用這個常數，只改這一行值，呼叫端完全不用動。真正的授權（誰
	// 拿到這個 capability）在 UAPPT_Caps::install()，值必須跟那邊的
	// UAPPT_Caps::CAP_MANAGE_BOOKINGS 完全一致。
	const CAP = 'uappt_manage_bookings';

	/**
	 * 全外掛唯一的後台頁面 slug（v2.35.0 選單收攏）。
	 *
	 * 刻意沿用原本「今日營運」的 slug，不另取新名字：它本來就是頂層選單的
	 * 進入點，網址 `admin.php?page=uappt-dashboard` 早就存在，收攏之後
	 * 「不帶 section 就落在今日營運」的行為跟舊網址完全一致，既有書籤不會壞。
	 */
	const PAGE_SLUG = 'uappt-dashboard';

	/**
	 * `add_submenu_page()` 回傳的 hook suffix。
	 *
	 * ⚠️ 不寫死字串——掛在「快捷鍵」底下與自己當頂層選單，算出來的格式完全
	 * 不同（`<父選單>_page_uappt-dashboard` 對 `toplevel_page_uappt-dashboard`）。
	 * 寫死的話選單一搬家就靜默失效：CSS 與 JS 全部不載入、版面整個跑掉，
	 * 卻不會有任何錯誤訊息。
	 *
	 * @var string
	 */
	protected static $page_hook = '';

	/**
	 * @var UAPPT_Admin|null
	 */
	protected static $instance = null;

	/**
	 * 單例。
	 *
	 * @return UAPPT_Admin
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
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );

		add_action( 'admin_post_uappt_save_staff', array( $this, 'handle_save_staff' ) );
		add_action( 'admin_post_uappt_toggle_staff_status', array( $this, 'handle_toggle_staff_status' ) );
		add_action( 'admin_post_uappt_delete_staff', array( $this, 'handle_delete_staff' ) );
		add_action( 'admin_post_uappt_import_upload', array( $this, 'handle_import_upload' ) );
		add_action( 'admin_post_uappt_import_apply', array( $this, 'handle_import_apply' ) );
		add_action( 'admin_post_uappt_import_template', array( $this, 'handle_import_template' ) );
		add_action( 'admin_post_uappt_save_staff_override', array( $this, 'handle_save_staff_override' ) );
		add_action( 'admin_post_uappt_save_staff_month', array( $this, 'handle_save_staff_month' ) );
		add_action( 'admin_post_uappt_save_roster', array( $this, 'handle_save_roster' ) );
		add_action( 'admin_post_uappt_save_shift_presets', array( $this, 'handle_save_shift_presets' ) );
		add_action( 'admin_post_uappt_delete_staff_override', array( $this, 'handle_delete_staff_override' ) );
		add_action( 'admin_post_uappt_save_staff_block', array( $this, 'handle_save_staff_block' ) );
		add_action( 'admin_post_uappt_delete_staff_block', array( $this, 'handle_delete_staff_block' ) );
		add_action( 'admin_post_uappt_create_manual_booking', array( $this, 'handle_create_manual_booking' ) );
		add_action( 'admin_post_uappt_cancel_booking', array( $this, 'handle_cancel_booking' ) );
		add_action( 'admin_post_uappt_bulk_booking_action', array( $this, 'handle_bulk_booking_action' ) );
		add_action( 'admin_post_uappt_reschedule_booking', array( $this, 'handle_reschedule_booking' ) );
		add_action( 'admin_post_uappt_reassign_staff', array( $this, 'handle_reassign_staff' ) );
		add_action( 'admin_post_uappt_complete_booking', array( $this, 'handle_complete_booking' ) );
		add_action( 'admin_post_uappt_mark_no_show', array( $this, 'handle_mark_no_show' ) );
		add_action( 'admin_post_uappt_revert_booking_status', array( $this, 'handle_revert_booking_status' ) );
		add_action( 'admin_post_uappt_export_report', array( $this, 'handle_export_report' ) );
		add_action( 'admin_post_uappt_export_report_all', array( $this, 'handle_export_report_all' ) );
		add_action( 'admin_post_uappt_approve_shift_request', array( $this, 'handle_approve_shift_request' ) );
		add_action( 'admin_post_uappt_reject_shift_request', array( $this, 'handle_reject_shift_request' ) );
		add_action( 'admin_post_uappt_approve_shift_batch', array( $this, 'handle_approve_shift_batch' ) );
		add_action( 'admin_post_uappt_reject_shift_batch', array( $this, 'handle_reject_shift_batch' ) );
		add_action( 'admin_post_uappt_update_booking_details', array( $this, 'handle_update_booking_details' ) );
		add_action( 'admin_post_uappt_update_booking_amount', array( $this, 'handle_update_booking_amount' ) );
		add_action( 'admin_post_uappt_save_consumable', array( $this, 'handle_save_consumable' ) );
		add_action( 'admin_post_uappt_delete_consumable', array( $this, 'handle_delete_consumable' ) );
		add_action( 'admin_post_uappt_consumable_move', array( $this, 'handle_consumable_move' ) );
		add_action( 'admin_post_uappt_consumable_stocktake', array( $this, 'handle_consumable_stocktake' ) );
		add_action( 'admin_post_uappt_save_consumable_recipe', array( $this, 'handle_save_consumable_recipe' ) );
		add_action( 'admin_post_uappt_recalculate_consumable_stock', array( $this, 'handle_recalculate_consumable_stock' ) );
		add_action( 'admin_post_uappt_mark_customer_contacted', array( $this, 'handle_mark_customer_contacted' ) );
		add_action( 'admin_post_uappt_clear_customer_contacted', array( $this, 'handle_clear_customer_contacted' ) );
		add_action( 'admin_post_uappt_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_uappt_save_modules', array( $this, 'handle_save_modules' ) );
		add_action( 'admin_post_uappt_test_line', array( $this, 'handle_test_line' ) );
		add_action( 'admin_post_uappt_resend_reminder', array( $this, 'handle_resend_reminder' ) );

		// 舊的子選單 slug 一律在 admin_init 轉到新網址，見 maybe_redirect_legacy_page()。
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_page' ) );
	}

	/**
	 * 九個功能區塊（section），外加一個不列在導覽列上的手動建單。
	 *
	 * v2.35.0 起後台只有一個選單項目，原本九個子選單改成這一份陣列——
	 * 比照終極電商 v25.8.81 的做法。這樣做的價值不只是選單變乾淨：
	 * **全外掛只有 `render_admin_page()` 一處會 `call_user_func()` 這裡的
	 * `render`**，而 `current_section()` 找不到 key 時一律退回第一個，
	 * 所以「沒有權限（或未來模組關閉）的功能永遠不會被 dispatch 到」是
	 * 結構保證，不是「那個 slug 從沒註冊過、WordPress 核心順便擋下」那種
	 * 副作用式的防線。
	 *
	 * ⚠️ `dashboard` **必須是第一個 key**——退回機制拿的就是 `array_keys()[0]`，
	 * 順序不能亂動。
	 *
	 * `in_nav => false` 的區塊照樣 dispatch 得到，只是不出現在頁籤列上：
	 * 手動建立預約自 v2.32.0 起是一顆按鈕而不是一個地方，不該佔一格頁籤。
	 *
	 * 排序刻意跟未來要合併的對象相鄰（預約｜日曆、人員｜排班申請），合併時
	 * 使用者不會覺得東西跑掉了。
	 *
	 * 記憶化：這支在一次請求裡至少被呼叫兩次（註冊選單算總數、畫頁籤列），
	 * 而 `count` 那三個各自是一次查詢。
	 *
	 * @return array
	 */
	public function sections() {
		static $sections = null;
		if ( null !== $sections ) {
			return $sections;
		}

		$sections = array(
			'dashboard'      => array( 'label' => __( '今日營運', 'ultimate-appointments' ), 'render' => 'render_dashboard_page', 'cap' => self::CAP ),
			// v2.38.0：日曆併進來當「預約」的兩種檢視。同一批資料、三種畫法，
			// 原本卻是兩個地方——Fresha／Mindbody／Vagaro 都是一個行事曆頁上面
			// 切日／週／月／清單，沒有人把清單跟日曆分成兩個導覽項目。
			'bookings'       => array( 'label' => __( '預約列表', 'ultimate-appointments' ), 'render' => 'render_bookings_page', 'cap' => self::CAP ),
			// v2.36.0：排班申請併進來當一個頁籤。⚠️ cap 是「**任一**即可」——
			// 審核排班用的 uappt_approve_shift_requests 跟管理預約用的
			// uappt_manage_bookings 是刻意分開的（資深員工可以幫忙審班表，
			// 不需要因此拿到全部的預約管理權限），只認其中一個會讓另一種人
			// 整個區塊進不去。真正的把關在各頁籤自己身上，見 staff_tabs()。
			// 紅點是「待審排班申請」的數字，所以跟著 staff_portal 走；模組關著
			// 時那個查詢也不必跑。區塊本身永遠在——人員主檔與班表是核心功能。
			'staff'          => array( 'label' => __( '人員管理', 'ultimate-appointments' ), 'render' => 'render_staff_page', 'cap' => array( self::CAP, UAPPT_Caps::CAP_APPROVE_SHIFT_REQUESTS ), 'count' => UAPPT_Modules::enabled( 'staff_portal' ) ? UAPPT_Shift_Request::get_pending_batch_count() : 0 ),
		);

		// v2.43.0：兩個有模組開關的區塊。**條件式塞進陣列**而不是先建好再濾掉
		// ——`count` 那兩個各自是一次查詢（回訪那支還是整張 bookings 的
		// GROUP BY），模組關著的時候不該為了一個永遠不會顯示的紅點去跑它。
		if ( UAPPT_Modules::enabled( 'customer_followup' ) ) {
			$sections['customers'] = array( 'label' => __( '回訪管理', 'ultimate-appointments' ), 'render' => 'render_customers_page', 'cap' => self::CAP, 'count' => UAPPT_Customer::count_lapsed() );
		}

		if ( UAPPT_Modules::enabled( 'consumables' ) ) {
			$sections['consumables'] = array( 'label' => __( '耗材管理', 'ultimate-appointments' ), 'render' => 'render_consumables_page', 'cap' => UAPPT_Caps::CAP_MANAGE_CONSUMABLES, 'count' => count( UAPPT_Consumable::get_low_stock() ) );
		}

		$sections['reports']        = array( 'label' => __( '報表', 'ultimate-appointments' ), 'render' => 'render_reports_page', 'cap' => self::CAP );
		$sections['settings']       = array( 'label' => __( '設定', 'ultimate-appointments' ), 'render' => 'render_settings_page', 'cap' => self::CAP );
		$sections['manual-booking'] = array( 'label' => __( '手動建立預約', 'ultimate-appointments' ), 'render' => 'render_manual_booking_page', 'cap' => self::CAP, 'in_nav' => false );

		// cap 可以是單一字串，也可以是「任一即可」的陣列。
		foreach ( $sections as $key => $info ) {
			$allowed = false;
			foreach ( (array) $info['cap'] as $cap ) {
				if ( current_user_can( $cap ) ) {
					$allowed = true;
					break;
				}
			}
			if ( ! $allowed ) {
				unset( $sections[ $key ] );
			}
		}

		return $sections;
	}

	/**
	 * 依 `?section=` 決定目前顯示哪一個區塊，找不到（含沒有權限、因此根本
	 * 不在 sections() 裡）時退回第一個。
	 *
	 * @return string
	 */
	public function current_section() {
		$sections  = $this->sections();
		$requested = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $requested && isset( $sections[ $requested ] ) ) {
			return $requested;
		}

		$keys = array_keys( $sections );

		return $keys ? $keys[0] : 'dashboard';
	}

	/**
	 * 後台網址組裝的唯一入口。
	 *
	 * 收攏成單一頁面之後，每一條站內連結都要帶 `section`——漏掉不會報錯，
	 * 只會**默默掉回今日營運**，所以不能讓 30 幾處各自手寫。
	 *
	 * @param string $section 區塊 key，留空代表回到最外層（落在今日營運）。
	 * @param array  $args    其餘查詢參數（tab／action／booking_id…）。
	 * @return string
	 */
	public static function url( $section = '', array $args = array() ) {
		$query = array( 'page' => self::PAGE_SLUG );
		if ( '' !== $section ) {
			$query['section'] = $section;
		}

		return add_query_arg( array_merge( $query, $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * 紅點。沿用 WordPress 核心「待審留言」的既有 class（`.awaiting-mod` /
	 * `.pending-count`），但核心樣式只認 `#adminmenu` 這個容器，用在
	 * `.uappt-section-nav`（WP 選單標題以外的地方）時**要靠 admin.css 自己
	 * 補一份同樣視覺的規則**，不是單純沿用就會自動生效。
	 *
	 * @param int $count 數字，0 或負數回傳空字串。
	 * @return string
	 */
	protected static function badge_html( $count ) {
		$count = (int) $count;
		if ( $count < 1 ) {
			return '';
		}

		return sprintf(
			' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>',
			$count
		);
	}

	/**
	 * 最外層的區塊導覽列。
	 *
	 * ⚠️ **這一層固定用 `nav-tab-wrapper`，各區塊內部的頁籤固定用 `subsubsub`。**
	 * 兩層各對應一種 wp-admin 原生元件，形狀天生不同，不需要任何自訂 CSS 去
	 * 區分層級——WooCommerce 設定頁自己就是這樣分工的（最外層「一般／商品／
	 * 運送方式」是 nav-tab-wrapper，分類內「運送區域｜送貨設定｜類別」是
	 * subsubsub）。終極電商 v25.8.83～87 曾經把最外層改成分段藥丸、再改成
	 * 左側垂直選單去「拉開視覺差異」，v25.8.88 全部回退，結論是方向錯了：
	 * 不是這層要長得多獨特，而是這兩層本來就該對應到既有的兩種不同元件。
	 */
	protected function render_section_tabs() {
		$sections = $this->sections();
		$current  = $this->current_section();

		echo '<h2 class="nav-tab-wrapper wp-clearfix uappt-section-nav">';
		foreach ( $sections as $key => $info ) {
			if ( isset( $info['in_nav'] ) && ! $info['in_nav'] ) {
				continue;
			}
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s%5$s</a>',
				esc_url( self::url( $key ) ),
				$key === $current ? ' nav-tab-active' : '',
				$key === $current ? ' aria-current="page"' : '',
				esc_html( $info['label'] ),
				wp_kses_post( self::badge_html( isset( $info['count'] ) ? $info['count'] : 0 ) )
			);
		}
		echo '</h2>';
	}

	/**
	 * 各區塊**內部**的子頁籤導覽（報表的六個頁籤、設定的五個、耗材的三個…）。
	 *
	 * ⚠️ **這一層固定用 `<ul class="subsubsub">`，不是 `nav-tab-wrapper`。**
	 * 最外層的區塊導覽已經是 nav-tab 了，兩層用同一個元件會變成兩排長得
	 * 一模一樣的頁籤，看不出誰包含誰——終極電商 v25.8.81 剛收攏時就是這樣，
	 * 後來花了 v25.8.83～87 試分段藥丸、試左側垂直選單，v25.8.88 才回到
	 * 正解：兩層本來就該對應到 wp-admin 既有的兩種不同元件。WooCommerce
	 * 設定頁自己就是這樣分工的（最外層「一般／商品／運送方式」是 nav-tab，
	 * 分類內「運送區域｜送貨設定｜類別」是 subsubsub）。
	 *
	 * ⚠️ 這推翻了 admin.css 原本記的「subsubsub 是同一份清單的篩選、nav-tab
	 * 是切換到另一組內容」。那條語意分類本身沒錯，但 WooCommerce 自己在第二層
	 * 就違反它（運送區域｜送貨設定｜類別明明是不同內容卻用 subsubsub）——
	 * **巢狀層級的分工贏過語意分類**。
	 *
	 * `$base_url` 由呼叫端給，不是這裡組：報表的頁籤網址要帶著目前的期間與
	 * 人員篩選，切頁籤時那些條件不能掉。
	 *
	 * @param array  $tabs     key => 標籤。少於兩個時不輸出。
	 * @param string $current  目前的 key。
	 * @param string $base_url 頁籤連結的基底網址。
	 * @param string $param    查詢參數名稱（排班申請用的是 status）。
	 */
	public static function render_tabs( array $tabs, $current, $base_url, $param = 'tab' ) {
		if ( count( $tabs ) < 2 ) {
			return;
		}

		// 豎線由 CSS 的 `li:not(:last-child)::after` 產生，不寫進標記——手機版
		// 要把它換成間距（一個字寬的「｜」當不了觸控目標之間的分隔），寫死在
		// 標記裡就只能用 CSS 把文字節點藏起來，那比一開始就交給 CSS 麻煩。
		// 終極電商 twshop_render_admin_tabs() 同樣的理由、同樣的做法。
		echo '<ul class="subsubsub uappt-settings-tabs">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<li><a href="%1$s"%2$s data-uappt-tab-link="%3$s">%4$s</a></li>',
				esc_url( add_query_arg( $param, $key, $base_url ) ),
				$key === $current ? ' class="current" aria-current="page"' : '',
				esc_attr( $key ),
				esc_html( $label )
			);
		}
		echo '</ul><br class="clear" />';
	}

	/**
	 * 唯一的後台頁面入口：依 `?section=` 分派給對應的 `render_*_page()`。
	 *
	 * 全外掛只有這一處 `call_user_func()`，見 sections() 的說明。
	 */
	public function render_admin_page() {
		$sections = $this->sections();
		$current  = $this->current_section();

		if ( ! isset( $sections[ $current ] ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		call_user_func( array( $this, $sections[ $current ]['render'] ) );
	}

	/**
	 * 已經被合併掉的 section → 現在住在哪裡。
	 *
	 * v2.35.0 短暫存在過的 `shift-requests` 從 v2.36.0 起併進 `staff` 的一個
	 * 頁籤。這份對照表讓舊網址（含 v2.35.0 期間產生的書籤）一次轉到正確的
	 * 位置，而不是先轉到一個不存在的 section、再被 current_section() 默默
	 * 退回今日營運——那種「連結沒壞但跑錯地方」最難查。
	 *
	 * @return array section key => array( 新 section, 額外查詢參數 )
	 */
	protected static function retired_sections() {
		return array(
			'shift-requests' => array( 'staff', array( 'tab' => 'requests' ) ),
			// 日曆本來就帶著 view=day／view=month，那個參數會原樣跟過來並蓋掉
			// 這裡的預設值（array_merge 時呼叫端的參數在後）——所以只裝了
			// view=month 當「沒指定就給月檢視」的保底。
			'calendar'       => array( 'bookings', array( 'view' => 'month' ) ),
		);
	}

	/**
	 * 舊的子選單 slug → 新的 section key。
	 *
	 * 這九個 slug 從 v2.35.0 起不再註冊成頁面，改成在 admin_init 直接轉址：
	 * 使用者的書籤、外掛內部十幾處 `redirect_with_error()`、以及任何寫死
	 * 舊網址的地方都不會壞。
	 *
	 * @return array
	 */
	protected static function legacy_slugs() {
		return array(
			'uappt-bookings'       => 'bookings',
			'uappt-calendar'       => 'calendar',
			'uappt-staff'          => 'staff',
			'uappt-shift-requests' => 'shift-requests',
			'uappt-customers'      => 'customers',
			'uappt-consumables'    => 'consumables',
			'uappt-reports'        => 'reports',
			'uappt-settings'       => 'settings',
			'uappt-manual-booking' => 'manual-booking',
		);
	}

	/**
	 * 舊網址轉到新網址。
	 *
	 * ⚠️ **一定要在 `admin_init` 做，不能在頁面的 render callback 裡做。**
	 * `wp-admin/admin.php` 的順序是「`load-{$page_hook}` → `admin-header.php`
	 * →  頁面 callback」，等到 callback 才轉址時側邊欄已經輸出了，
	 * `wp_safe_redirect()` 會因為表頭已送出而失效。
	 *
	 * ⚠️ **`$pagenow` 的判斷不能省。** `admin-post.php` 也會觸發 `admin_init`
	 * （它自己 `do_action( 'admin_init' )`），少了這一行的話，任何帶
	 * `page=uappt-…` 的表單送出都會在寫入之前被轉走。
	 */
	public function maybe_redirect_legacy_page() {
		global $pagenow;

		if ( 'admin.php' !== $pagenow ) {
			return;
		}

		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$legacy  = self::legacy_slugs();
		$retired = self::retired_sections();

		// 兩種舊網址：舊的子選單 slug（v2.34.x 以前），以及被合併掉的 section。
		if ( isset( $legacy[ $page ] ) ) {
			$target = $legacy[ $page ];
		} elseif ( self::PAGE_SLUG === $page && isset( $retired[ $section ] ) ) {
			$target = $section;
		} else {
			return;
		}

		// 原本的查詢參數要原樣帶過去（?action=edit&booking_id=123 之類），
		// 只把 page／section 換掉。值一律轉成字串再交給 add_query_arg() 編碼。
		$args = array();
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'page' === $key || 'section' === $key || ! is_scalar( $value ) ) {
				continue;
			}
			$args[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
		}

		// 併走的 section 要再翻一次，一步轉到最終位置，不要轉兩次。
		if ( isset( $retired[ $target ] ) ) {
			$args   = array_merge( $retired[ $target ][1], $args );
			$target = $retired[ $target ][0];
		}

		wp_safe_redirect( self::url( $target, $args ) );
		exit;
	}

	/**
	 * 「快捷鍵」共用頂層選單的 slug——終極電商（`wc-general-settings`）與
	 * 終極登入（`wclon-settings`）v25.8.80／v1.37.0 起共用的那一個。
	 *
	 * ⚠️ **終極預約刻意不搶當 owner**，找不到就回傳空字串、自己當頂層選單。
	 * 那兩支外掛的 fallback 是**兩兩對寫**的（終極電商只找 `wclon-settings`、
	 * 終極登入只找 `wc-general-settings`），如果這裡也搶著建父選單，會出現
	 * 「只裝終極預約時它建了快捷鍵 → 之後啟用終極電商，對方找不到自己認識的
	 * slug、又建一個」這條路徑，變成兩個快捷鍵選單。要修就得同時改那兩支，
	 * 而它們在本站是唯讀的參考原始碼。
	 *
	 * 單獨安裝時本來就沒有「快捷鍵」可以對齊，維持自有頂層選單完全合理。
	 *
	 * @return string 空字串代表沒有可掛的父選單。
	 */
	protected static function shortcut_parent_slug() {
		global $admin_page_hooks;

		foreach ( array( 'wc-general-settings', 'wclon-settings' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * 後台頁面共用外框的上半：`.wrap` 容器、`<h1>`、標題列按鈕。
	 *
	 * v2.34.0 從 15 個 view 各自的一份收攏過來。收攏的理由不只是重複——
	 * 那 15 份**本來就已經不一致**了：`consumables.php` 與 `reports.php` 用了
	 * `wp-heading-inline` 卻沒有補 `<hr class="wp-header-end" />`，
	 * `customers.php` 則是沒有按鈕卻補了那條線。
	 *
	 * ⚠️ **有 `page-title-action` 就一定要有 `<hr class="wp-header-end" />`**：
	 * WordPress 把 admin notice 插在那條線後面，沒有的話 notice 會被插進
	 * `<h1>` 與按鈕之間，把標題列拆成上下兩段。這條規則現在只寫在這裡一處，
	 * 呼叫端不會再有機會忘記。
	 *
	 * 權限檢查刻意**不**放進來：各 `render_*_page()` 的第一行本來就是
	 * `current_user_can()`，那是「先擋權限，才做事」的正確順序。把檢查搬進
	 * 外框會變成「先算完資料才擋」，無權限者照樣跑完整段準備工作。
	 *
	 * @param string $title   頁面標題。
	 * @param array  $actions 標題列按鈕，每個元素是 array( 'url' => …, 'label' => … )。
	 */
	public static function page_open( $title, array $actions = array() ) {
		echo '<div class="wrap uappt-wrap">' . "\n";

		if ( ! $actions ) {
			printf( "\t" . '<h1>%s</h1>' . "\n", esc_html( $title ) );
		} else {
			printf( "\t" . '<h1 class="wp-heading-inline">%s</h1>' . "\n", esc_html( $title ) );
			foreach ( $actions as $action ) {
				printf(
					"\t" . '<a href="%s" class="page-title-action">%s</a>' . "\n",
					esc_url( $action['url'] ),
					esc_html( $action['label'] )
				);
			}
		}

		self::instance()->render_section_tabs();

		// ⚠️ **全頁只能有一個 `.wp-header-end`。** 它是 WordPress 用來決定
		// 「通知訊息插在哪」的標記，而核心是 `$( '.wp-header-end' ).after( … )`
		// ——jQuery 對多個符合的元素會**各插一份**，重複一個標記就會看到兩則
		// 一模一樣的「已儲存」。所以這一行固定由外框負責，各 view 一律不要自己
		// 再放一個（v2.35.0 收攏時，耗材頁就是這樣一度變成兩個）。
		//
		// 位置在 section 導覽列之後：通知講的是「目前這個區塊」發生了什麼事，
		// 不該插在標題與導覽列中間把一組東西拆開。
		echo "\t" . '<hr class="wp-header-end" />' . "\n";
	}

	/**
	 * 共用外框的下半。跟 page_open() 必須成對——數量對不上的話版面會整個塌掉，
	 * 而且 wp-admin 不會有任何錯誤訊息。
	 */
	public static function page_close() {
		echo "</div>\n";
	}

	/**
	 * 篩選列的外框（上半）。
	 *
	 * 九個篩選區塊本來各自手寫 `<form>` ＋ 一串 hidden 欄位 ＋ 一堆沒有名字的
	 * 控制項。收攏成這一對 open／close 有三個理由：
	 *
	 * 1. `page` 與 `section` 這兩個 hidden 欄位漏掉不會報錯，只會在送出後**默默
	 *    掉回今日營運**（見 url() 的說明）。九份手寫的複本遲早漏一份。
	 * 2. 手機版要把整塊收起來，收合的開關與標題必須每一份都長得一樣。
	 * 3. 每一格要有欄位名稱（見 field_open()），統一在這裡決定怎麼排。
	 *
	 * 收合用的是「checkbox ＋ label」而不是 `<details>`：桌面版要讓內容**永遠**
	 * 顯示，而關著的 `<details>` 沒辦法用 CSS 打開——Chrome 是用
	 * `content-visibility` 藏內容，量得到框但畫不出來（實測確認過，光看
	 * `getBoundingClientRect()` 會得到相反的結論）。checkbox 的顯示與否純粹由
	 * CSS 決定，桌面版整個開關連同標題都 `display: none`，內容照常顯示。
	 *
	 * 「目前有幾個條件生效」由呼叫端算好傳進來：什麼叫「有生效」每一頁不一樣
	 * （預約列表的起始日預設就是今天，那不算使用者篩過），只有各自的 view
	 * 知道。大於 0 時直接 render 成展開狀態，不靠 JS，也就不會先展開再收起。
	 *
	 * @param array $args {
	 *     @type string $section     要送回哪個 section（必填）。
	 *     @type array  $hidden      其餘要帶著走的查詢參數，name => value。
	 *     @type int    $active      目前生效中的條件數，>0 會預設展開。
	 *     @type bool   $collapsible 手機版是否收合。只有一兩格的篩選列不值得
	 *                              收（收起來比攤開還佔一行），預設 true。
	 *     @type string $class       附加在 <form> 上的 class。
	 *     @type string $label       收合標題，預設「篩選條件」。
	 * }
	 */
	public static function filters_open( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'section'     => '',
				'hidden'      => array(),
				'active'      => 0,
				'collapsible' => true,
				'class'       => '',
				'label'       => __( '篩選條件', 'ultimate-appointments' ),
			)
		);

		$active = (int) $args['active'];

		printf(
			'<form method="get" action="%s" class="uappt-filters%s">',
			esc_url( admin_url( 'admin.php' ) ),
			$args['class'] ? ' ' . esc_attr( $args['class'] ) : ''
		);

		$hidden = array_merge(
			array(
				'page'    => self::PAGE_SLUG,
				'section' => $args['section'],
			),
			$args['hidden']
		);
		foreach ( $hidden as $uappt_key => $uappt_value ) {
			if ( '' === (string) $uappt_value ) {
				continue;
			}
			printf(
				'<input type="hidden" name="%s" value="%s" />',
				esc_attr( $uappt_key ),
				esc_attr( $uappt_value )
			);
		}

		if ( $args['collapsible'] ) {
			// id 要唯一：耗材的異動紀錄頁上有兩個篩選表單，共用同一個 id 的話
			// 點其中一個會連動另一個。
			static $uappt_toggle_seq = 0;
			$uappt_toggle_seq++;
			$toggle_id = 'uappt-filters-toggle-' . $uappt_toggle_seq;

			printf(
				'<input type="checkbox" id="%s" class="uappt-filters-toggle"%s />',
				esc_attr( $toggle_id ),
				$active > 0 ? ' checked="checked"' : ''
			);
			printf(
				'<label for="%s" class="uappt-filters-summary">%s%s</label>',
				esc_attr( $toggle_id ),
				esc_html( $args['label'] ),
				$active > 0 ? '<span class="uappt-filters-count">' . esc_html( (string) $active ) . '</span>' : ''
			);
		}

		echo '<div class="uappt-filters-grid">';
	}

	/**
	 * 篩選列的外框（下半）。跟 filters_open() 必須成對。
	 */
	public static function filters_close() {
		echo '</div></form>';
	}

	/**
	 * 篩選列裡的一格：欄位名稱 ＋ 控制項。
	 *
	 * 欄位名稱在桌面版是隱藏的（`.screen-reader-text` 那種藏法，螢幕閱讀器仍然
	 * 讀得到），只有手機版會顯示出來。理由是兩種寬度的問題不一樣：桌面版是
	 * 一整排、彼此有上下文，「所有人員」旁邊就是「所有狀態」，看得出這排在篩
	 * 什麼；手機版每一格獨佔一行之後那個上下文就沒了，只剩一個孤立的下拉選單
	 * 寫著「所有人員」——那是**目前的值**，不是欄位名，讀的人無從判斷這一格
	 * 在篩什麼、也不知道「從」是從哪一種日期算起。
	 *
	 * @param string $label 欄位名稱。
	 * @param string $for   對應控制項的 id，留空則不產生 for（例如一格裡有兩個
	 *                      輸入框的日期區間，那時標題是 <span> 不是 <label>）。
	 * @param string $class 附加的 modifier class（uappt-field-range…）。
	 */
	public static function field_open( $label, $for = '', $class = '' ) {
		printf( '<div class="uappt-field%s">', $class ? ' ' . esc_attr( $class ) : '' );

		if ( '' !== $label ) {
			if ( '' !== $for ) {
				printf(
					'<label class="uappt-field-label" for="%s">%s</label>',
					esc_attr( $for ),
					esc_html( $label )
				);
			} else {
				printf( '<span class="uappt-field-label">%s</span>', esc_html( $label ) );
			}
		}
	}

	/**
	 * 篩選列一格的結尾。跟 field_open() 必須成對。
	 */
	public static function field_close() {
		echo '</div>';
	}

	/**
	 * 「回某某列表」這種返回按鈕的共用組裝，省得 5 個編輯頁各寫一次 add_query_arg()。
	 *
	 * @param string $page  要回去的頁面 slug。
	 * @param string $label 按鈕文字。
	 * @return array 單一元素的 actions 陣列，可直接傳給 page_open()。
	 */
	protected static function back_action( $page, $label ) {
		return array(
			array(
				'url'   => self::url( self::section_key( $page ) ),
				'label' => $label,
			),
		);
	}

	/**
	 * 「手動建立預約」按鈕的共用組裝：今日營運、預約列表、日曆月／日檢視四個
	 * 頁面都有同一顆（v2.32.0 起它不再是選單項目）。日曆與今日營運會多帶一個
	 * 日期，讓建單頁不用再選一次。
	 *
	 * @param string $date_ymd 要帶過去的日期（Y-m-d），空字串代表不帶。
	 * @return array
	 */
	protected static function manual_booking_action( $date_ymd = '' ) {
		$args = array();
		if ( '' !== $date_ymd ) {
			$args['date_ymd'] = $date_ymd;
		}

		return array(
			array(
				'url'   => self::url( 'manual-booking', $args ),
				'label' => __( '手動建立預約', 'ultimate-appointments' ),
			),
		);
	}

	/**
	 * 註冊後台選單。
	 *
	 * v2.35.0 起**只有一個選單項目**（比照終極電商 v25.8.81）：原本的九個
	 * 子選單改成頁面內的 section 頁籤，見 sections()。
	 *
	 * 兩種情境：
	 * - 站上有「快捷鍵」共用頂層選單（終極電商／終極登入其中之一在）→ 掛上去
	 * - 都不在 → 自己建頂層選單，維持 v2.33.0 之前的樣子
	 *
	 * ⚠️ hook suffix 一律**記 add_submenu_page() 的回傳值**，不要寫死字串：
	 * owner 與 attach 兩種情境算出來的格式不同（`toplevel_page_*` 對
	 * `<父選單>_page_*`），寫死的話選單一搬家就**靜默失效**——後台 CSS 完全
	 * 不載入、版面整個跑掉，卻不會有任何錯誤訊息。終極登入的
	 * `WCLON_Settings::$page_hook` 註解記的就是這個教訓。
	 */
	public function register_menu() {
		// 側邊欄只剩一個項目，三種紅點沒地方各自顯示了，改成一個總數；
		// 是哪一種由 section 頁籤上各自的數字回答（render_section_tabs()），
		// 明細則在今日營運那一頁。三層揭露剛好對應導覽的三層。
		$total = 0;
		foreach ( $this->sections() as $info ) {
			$total += isset( $info['count'] ) ? (int) $info['count'] : 0;
		}
		$menu_title = __( '終極預約', 'ultimate-appointments' ) . self::badge_html( $total );

		$parent = self::shortcut_parent_slug();

		if ( '' === $parent ) {
			add_menu_page(
				__( '終極預約', 'ultimate-appointments' ),
				$menu_title,
				self::CAP,
				self::PAGE_SLUG,
				array( $this, 'render_admin_page' ),
				'dashicons-calendar-alt',
				56
			);
			// 拿掉 WordPress 自動插入的重複子項目；下面那一筆才是真正的內容頁。
			// 核心後續會發現「只剩一筆子選單、而且目標跟父選單相同」再把它收掉，
			// 最後呈現的就是單純一個頂層選單項目。
			remove_submenu_page( self::PAGE_SLUG, self::PAGE_SLUG );
			$parent = self::PAGE_SLUG;
		}

		self::$page_hook = add_submenu_page(
			$parent,
			__( '終極預約', 'ultimate-appointments' ),
			$menu_title,
			self::CAP,
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * 載入後台頁面資源。
	 *
	 * @param string $hook 目前頁面 hook。
	 */
	public function enqueue_assets( $hook ) {
		// v2.35.0：改成比對記下來的 hook suffix，取代原本的
		// `strpos( $hook, 'uappt-' )`。收攏成單一頁面之後那種模糊比對已經沒有
		// 意義（只剩一個 slug），而且 hook suffix 會隨著掛在哪個父選單底下
		// 而變，記實際值才不會漏。
		if ( $hook !== self::$page_hook ) {
			return;
		}

		// ⚠️ v2.35.0 起 `$_GET['page']` 永遠是 uappt-dashboard，分不出是哪一頁；
		// 判斷改看 section。這一段是整次收攏最容易漏的地方——漏了不會報錯，
		// 只會某一頁的 JS 悄悄不載入（例如時段選擇器整個不動）。
		$page = $this->current_section();

		wp_enqueue_style( 'uappt-admin', UAPPT_PLUGIN_URL . 'assets/css/admin.css', array(), UAPPT_VERSION );

		// 兩層導覽的橫向捲動定位。**每一頁都要**——導覽本身每一頁都有，而它
		// 在手機寬度下會捲動，少載入這一支就會發生「進到靠右的區塊卻看不到
		// 自己在哪」。不吃 jQuery，也不需要任何 localize。
		wp_enqueue_script( 'uappt-admin-nav', UAPPT_PLUGIN_URL . 'assets/js/admin-nav.js', array(), UAPPT_VERSION, true );

		if ( 'staff' === $page ) {
			wp_enqueue_script( 'uappt-admin-staff', UAPPT_PLUGIN_URL . 'assets/js/admin-staff.js', array( 'jquery' ), UAPPT_VERSION, true );

			// 班別列的「自訂時段…」（v2.100.0）。人員編輯頁與月排班表都有那一列，小檔案，
			// 整個人員區塊一起載入。
			wp_enqueue_script( 'uappt-admin-shift-custom', UAPPT_PLUGIN_URL . 'assets/js/admin-shift-custom.js', array(), UAPPT_VERSION, true );

			// 全店月排班表（v2.94.0）。只在那個頁籤載入：它要掃整張表，其他頁用不到。
			$staff_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'roster' === $staff_tab ) {
				wp_enqueue_script( 'uappt-admin-roster', UAPPT_PLUGIN_URL . 'assets/js/admin-roster.js', array(), UAPPT_VERSION, true );
			}

			// 抽成級距的列增減。原生 DOM API，不依賴 jQuery。
			wp_enqueue_script( 'uappt-admin-commission', UAPPT_PLUGIN_URL . 'assets/js/admin-commission.js', array(), UAPPT_VERSION, true );

			// 人員照片用 WordPress 內建的媒體選擇器。只有新增／編輯頁才載入——
			// wp_enqueue_media() 會帶進一整包 backbone 樣板，列表頁用不到。
			$staff_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'new' === $staff_action || 'edit' === $staff_action ) {
				wp_enqueue_media();
				wp_localize_script(
					'uappt-admin-staff',
					'UAPPT_Admin_Staff',
					array(
						'i18n' => array(
							'photo_title'  => __( '選擇人員照片', 'ultimate-appointments' ),
							'photo_button' => __( '使用這張照片', 'ultimate-appointments' ),
							'photo_select' => __( '選擇照片', 'ultimate-appointments' ),
							'photo_change' => __( '更換照片', 'ultimate-appointments' ),
							// 每週班表的「上班／公休」狀態字（v2.80.0）。
							'working'      => __( '上班', 'ultimate-appointments' ),
							'closed'       => __( '公休', 'ultimate-appointments' ),
						),
					)
				);
			}
		}

		// ⚠️ v2.38.0 日曆併進「預約」之後，這裡的條件一度還停在已經不存在的
		// `calendar` section，於是日／月檢視的 JS 與 select2 全部不載入——
		// 日檢視的人員多選因此退化成一個原生的 <select multiple>（很高、要
		// 捲動、不能搜尋）。**這種失效不會報錯**，只會看起來變醜，所以
		// 合併 section 時一定要回頭檢查這一段。
		$calendar_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'bookings' === $page && in_array( $calendar_view, array( 'day', 'month' ), true ) ) {
			// 只做「載入後捲到當天最早的上班時間」，沒有相依 jQuery。
			wp_enqueue_script( 'uappt-admin-calendar', UAPPT_PLUGIN_URL . 'assets/js/admin-calendar.js', array(), UAPPT_VERSION, true );
			// 日檢視的人員篩選用 WooCommerce 內建的 select2 封裝（可搜尋的下拉
			// 多選）。月檢視用不到，但不分視圖一律載入——檔案小，不值得為了省
			// 這兩行再判斷一次 view。
			//
			// ⚠️ 相依性是安全的，不要再因為「怕註冊順序」把它拿掉：WooCommerce
			// 的 register_scripts() 掛在 **admin_init**（早於 admin_enqueue_scripts），
			// 原始碼註解就寫著這是刻意早註冊、好讓其他外掛用 handle 取用。
			wp_enqueue_style( 'woocommerce_admin_styles' );
			wp_enqueue_script( 'wc-enhanced-select' );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$needs_slot_picker = ( 'manual-booking' === $page )
			|| ( 'bookings' === $page && 'edit' === $action );

		if ( $needs_slot_picker ) {
			wp_enqueue_script( 'uappt-admin-booking', UAPPT_PLUGIN_URL . 'assets/js/admin-booking.js', array( 'jquery' ), UAPPT_VERSION, true );
			wp_localize_script(
				'uappt-admin-booking',
				'UAPPT_Admin_Booking',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'uappt_slots_nonce' ),
					// 從日檢視「點空白處」跳過來時要自動選上的人員 ID；服務項目
					// 沒辦法一起帶（日檢視不知道要建立哪個商品的預約），要等客服
					// 自己選了服務項目、候選人員名單查回來才套用得上，見
					// assets/js/admin-booking.js。非手動建立預約頁一律是 0。
					'prefill_staff_id' => ( 'manual-booking' === $page && isset( $_GET['staff_id'] ) ) ? absint( $_GET['staff_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'i18n'     => array(
						'select_date' => __( '請先選擇日期', 'ultimate-appointments' ),
						'loading'     => __( '查詢中…', 'ultimate-appointments' ),
						'no_slots'    => __( '這天沒有可預約的時段。', 'ultimate-appointments' ),
						'error'       => __( '查詢失敗，請稍後再試。', 'ultimate-appointments' ),
						'staff_any'   => __( '不指定（系統自動安排）', 'ultimate-appointments' ),
					),
				)
			);
		}

		// 「自訂金額」的勾選框出現在同樣這兩頁（手動建單、預約編輯），所以直接
		// 沿用 $needs_slot_picker 的判斷，不另外再寫一份一模一樣的條件。
		if ( $needs_slot_picker ) {
			wp_enqueue_script( 'uappt-admin-amount', UAPPT_PLUGIN_URL . 'assets/js/admin-amount.js', array(), UAPPT_VERSION, true );
			wp_localize_script(
				'uappt-admin-amount',
				'UAPPT_Admin_Amount',
				array(
					'i18n' => array(
						'confirm_zero' => __( '金額是 0 元（招待）。報表上這筆的業績會是 0，確定要這樣送出嗎？', 'ultimate-appointments' ),
					),
				)
			);
		}

		if ( 'manual-booking' === $page ) {
			// 沿用 WooCommerce 內建的會員搜尋（wc-enhanced-select 提供 select2 +
			// woocommerce_json_search_customers AJAX 端點），不用自己另外做一套。
			wp_enqueue_style( 'woocommerce_admin_styles' );
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_script( 'uappt-admin-manual-booking', UAPPT_PLUGIN_URL . 'assets/js/admin-manual-booking.js', array( 'jquery', 'wc-enhanced-select' ), UAPPT_VERSION, true );
			wp_localize_script(
				'uappt-admin-manual-booking',
				'UAPPT_Admin_Customer',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'uappt_slots_nonce' ),
					'i18n'     => array(
						'search_placeholder' => __( '輸入姓名、電話或 Email 搜尋會員…', 'ultimate-appointments' ),
					),
				)
			);
		}

		if ( 'reports' === $page ) {
			// 只做「全部展開／收合」，沒有相依（原生 DOM API），按鈕文字由
			// data 屬性提供，所以不需要 localize。
			wp_enqueue_script( 'uappt-admin-reports', UAPPT_PLUGIN_URL . 'assets/js/admin-reports.js', array(), UAPPT_VERSION, true );
		}

		if ( 'settings' === $page ) {
			// 設定頁的頁籤切換。沒有相依（原生 DOM API），也不需要 localize：
			// 頁籤清單是直接從 DOM 上的 data 屬性讀的，PHP 那邊改了頁籤，這裡
			// 不用跟著改。
			wp_enqueue_script( 'uappt-admin-settings', UAPPT_PLUGIN_URL . 'assets/js/admin-settings.js', array(), UAPPT_VERSION, true );

			// 通知卡片預覽的範例資料。只給「跟外觀無關」的部分（資料列），
			// 標題與顏色由前端直接讀表單——存檔前就要看得到變化。
			wp_localize_script(
				'uappt-admin-settings',
				'UAPPT_CardPreview',
				array(
					'shopName' => get_bloginfo( 'name' ),
					'samples'  => UAPPT_Card::preview_samples(),
				)
			);

			// 「收款設定」頁籤的列增減。整個設定頁是同一張表單、所有頁籤的欄位
			// 都在 DOM 裡（見 settings.php 的 uappt_tab_pane()），所以不分頁籤
			// 一律載入。
			wp_enqueue_script( 'uappt-admin-payment', UAPPT_PLUGIN_URL . 'assets/js/admin-payment.js', array(), UAPPT_VERSION, true );

			// 「功能模組」頁籤的快速套用。只對 manage_options 有意義——其他人
			// 連那個頁籤都看不到（見 settings_tabs()）。
			if ( current_user_can( 'manage_options' ) ) {
				wp_enqueue_script( 'uappt-admin-modules', UAPPT_PLUGIN_URL . 'assets/js/admin-modules.js', array(), UAPPT_VERSION, true );

				// 方案的內容與所有文案只定義在 PHP 一處，JS 不另外寫一份——那種
				// 「兩邊各一份」的清單遲早會歪掉一邊。連「%1$s ＋ 加購%2$s」這種
				// 句型也是帶過去的，翻譯才有作用。
				$presets = array();
				$tiers   = array();
				foreach ( UAPPT_Modules::tier_presets() as $key => $tier ) {
					$presets[ $key ] = $tier['modules'];
					$tiers[ $key ]   = $tier['label'];
				}

				$labels = array();
				foreach ( UAPPT_Modules::definitions() as $key => $def ) {
					$labels[ $key ] = $def['label'];
				}

				wp_localize_script(
					'uappt-admin-modules',
					'UAPPT_Admin_Modules',
					array(
						'presets'     => $presets,
						'tiers'       => $tiers,
						'labels'      => $labels,
						/* translators: %s: 額外加購的模組名稱 */
						'extraFormat' => __( '＋ 加購 %s', 'ultimate-appointments' ),
						'separator'   => __( '、', 'ultimate-appointments' ),
					)
				);
			}
		}
	}

	/**
	 * 顯示操作完成後的提示訊息。
	 */
	public function render_notices() {
		if ( empty( $_GET['uappt_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$notice  = sanitize_key( wp_unslash( $_GET['uappt_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type    = 'success';
		$message = '';

		switch ( $notice ) {
			case 'staff_saved':
				$message = __( '人員已儲存。', 'ultimate-appointments' );
				break;
			case 'staff_created':
				// 剛建立完，下一步一定是排班——把它講出來，不要讓人自己去發現
				// 「建好了但客人還是約不到」。
				$type    = 'info';
				// v2.96.2 起新人員一律從彈性班開始，所以這裡要把兩條路都講出來。
				$message = __( '人員已建立，目前是「彈性班」，還沒有任何班——沒有班表的人員，客人在前台是完全約不到的（而且不會有任何錯誤訊息）。每週班差不多一樣的話，在下面的「排班方式」改成固定班、填好樣板，系統會自動排好；每週都不一樣的話，直接在「這個月的班」排。', 'ultimate-appointments' );
				break;
			case 'staff_deleted':
				$message = __( '人員已刪除。', 'ultimate-appointments' );
				break;
			case 'staff_activated':
				$message = __( '已將這位人員標記為啟用，之後排班/派工會再考慮這位人員。', 'ultimate-appointments' );
				break;
			case 'staff_deactivated':
				$message = __( '已將這位人員標記為停用，不會再被排進新預約；既有的班表與紀錄都還在，需要時可以隨時改回啟用。', 'ultimate-appointments' );
				break;
			case 'staff_override_saved':
				$message = __( '請假設定已儲存。', 'ultimate-appointments' );
				break;
			case 'staff_month_saved':
				$count   = isset( $_GET['uappt_count'] ) ? absint( $_GET['uappt_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$message = sprintf(
					/* translators: %d: 存下來的天數 */
					_n( '已儲存 %d 天的班。', '已儲存 %d 天的班。', $count, 'ultimate-appointments' ),
					$count
				);
				break;
			case 'shift_presets_saved':
				$message = __( '班別已儲存。', 'ultimate-appointments' );
				break;
			case 'shift_presets_partial':
				// 只填一半（有名稱沒時段、或有時段沒名稱）的那幾列被丟掉了——無聲丟掉的話
				// 管理者會以為是存檔壞了。
				$type    = 'warning';
				$message = __( '班別已儲存，但有幾列沒有存進去：班別需要「名稱」與「至少一段時間」兩者都填。只填一半的那幾列請補齊後再存一次。', 'ultimate-appointments' );
				break;
			case 'roster_saved':
				$count    = isset( $_GET['uappt_count'] ) ? absint( $_GET['uappt_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$people   = isset( $_GET['uappt_people'] ) ? absint( $_GET['uappt_people'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$approved = isset( $_GET['uappt_approved'] ) ? absint( $_GET['uappt_approved'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$rejected = isset( $_GET['uappt_rejected'] ) ? absint( $_GET['uappt_rejected'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$held     = isset( $_GET['uappt_held'] ) ? absint( $_GET['uappt_held'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

				$parts = array();
				if ( $count > 0 ) {
					$parts[] = sprintf(
						/* translators: 1: 人數 2: 格數 */
						__( '已儲存 %1$d 位人員、共 %2$d 天的班。', 'ultimate-appointments' ),
						$people,
						$count
					);
				}
				if ( $approved > 0 || $rejected > 0 ) {
					// 只講有發生的那一半：「沒有核准 0 筆」讀起來像是有事沒做完。
					$did = array();
					if ( $approved > 0 ) {
						/* translators: %d: 筆數 */
						$did[] = sprintf( __( '核准 %d 筆', 'ultimate-appointments' ), $approved );
					}
					if ( $rejected > 0 ) {
						/* translators: %d: 筆數 */
						$did[] = sprintf( __( '沒有核准 %d 筆', 'ultimate-appointments' ), $rejected );
					}
					$parts[] = sprintf(
						/* translators: %s: 核准／不核准的筆數 */
						__( '排班申請：%s，已經寄信通知員工。', 'ultimate-appointments' ),
						implode( '、', $did )
					);
				}
				// 硬衝突擋下來的申請：存檔的其他部分是成功的，所以整則維持成功的語氣，
				// 但改成 warning 顏色——沒核准的那幾天客人仍然約在那個人身上。
				if ( $held > 0 ) {
					$type    = 'warning';
					$parts[] = sprintf(
						/* translators: %d: 筆數 */
						__( '⚠️ 有 %d 筆申請會影響已經成立的預約，沒有核准，仍在等待審核——請先改期或換人，再回來核准。', 'ultimate-appointments' ),
						$held
					);
				}
				$message = implode( ' ', $parts );
				break;
			case 'staff_month_unchanged':
				// 不是錯誤：按了儲存卻什麼都沒塗很自然，也可能是 JS 沒載入。講清楚
				// 「沒有變更」比丟一個紅色錯誤誠實，也比靜悄悄什麼都不說好。
				$type    = 'info';
				$message = __( '這個月沒有未儲存的變更，所以沒有寫入任何東西。要排班請先點日子選起來，再點班別。', 'ultimate-appointments' );
				break;
			case 'staff_override_deleted':
				$message = __( '請假設定已刪除。', 'ultimate-appointments' );
				break;
			case 'staff_block_saved':
				$message = __( '時段佔用已建立，該時段的名額已經扣掉，前台不會再賣出去。', 'ultimate-appointments' );
				break;
			case 'staff_block_deleted':
				$message = __( '時段佔用已刪除，名額已歸還。', 'ultimate-appointments' );
				break;
			case 'import_done':
				// 匯入刻意允許部分成功（見 UAPPT_Import::apply()），訊息一定要說得出
				// 實際套用了幾列、略過幾列，否則管理者會以為整個檔案都進去了。
				$done    = isset( $_GET['uappt_count'] ) ? absint( $_GET['uappt_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$skipped = isset( $_GET['uappt_skipped'] ) ? absint( $_GET['uappt_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$failed  = isset( $_GET['uappt_failed'] ) ? absint( $_GET['uappt_failed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( $skipped || $failed ) {
					$type    = 'warning';
					$message = sprintf(
						/* translators: 1: 成功列數 2: 略過列數 3: 失敗列數 */
						__( '匯入完成：套用 %1$d 列，略過 %2$d 列（有錯或被衝突擋下），失敗 %3$d 列。請修正後重新上傳那幾列。', 'ultimate-appointments' ),
						$done,
						$skipped,
						$failed
					);
				} else {
					$message = sprintf(
						/* translators: %d: 成功列數 */
						__( '匯入完成，共套用 %d 列。', 'ultimate-appointments' ),
						$done
					);
				}
				break;
			case 'booking_created':
				$message = __( '預約已建立。', 'ultimate-appointments' );
				break;
			case 'booking_created_no_order':
				$message = __( '預約已建立（未產生訂單）。時段已經鎖住，不會被重複預約；這筆消費的收款與營收請另行處理。', 'ultimate-appointments' );
				break;
			case 'booking_cancelled':
				$message = __( '預約已取消，時段已釋放。', 'ultimate-appointments' );
				break;
			case 'bulk_cancelled':
			case 'bulk_completed':
			case 'bulk_no_show':
				$done       = isset( $_GET['uappt_count'] ) ? absint( $_GET['uappt_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$total      = isset( $_GET['uappt_total'] ) ? absint( $_GET['uappt_total'] ) : $done; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$verb_map   = array(
					'bulk_cancelled' => __( '取消', 'ultimate-appointments' ),
					'bulk_completed' => __( '標記完成', 'ultimate-appointments' ),
					'bulk_no_show'   => __( '標記未到', 'ultimate-appointments' ),
				);
				$verb       = $verb_map[ $notice ];
				if ( $done === $total ) {
					$message = sprintf(
						/* translators: 1: 動作名稱 2: 筆數 */
						__( '已批次%1$s %2$d 筆預約。', 'ultimate-appointments' ),
						$verb,
						$done
					);
				} else {
					// 沒有全部成功：多半是勾選了狀態不適用的預約（例如對已取消的
					// 預約按「取消」），UAPPT_Booking::release()/complete() 會直接
					// 跳過那幾筆，不是操作失敗，但要讓管理者知道跟勾選的筆數不同。
					$type    = 'warning';
					$message = sprintf(
						/* translators: 1: 動作名稱 2: 實際套用的筆數 3: 勾選的總筆數 */
						__( '已批次%1$s %2$d 筆預約（勾選了 %3$d 筆，其餘因狀態不適用而略過）。', 'ultimate-appointments' ),
						$verb,
						$done,
						$total
					);
				}
				break;
			case 'booking_rescheduled':
				$message = __( '預約已改期。', 'ultimate-appointments' );
				break;
			case 'staff_reassigned':
				$message = __( '服務人員已更新。', 'ultimate-appointments' );
				break;
			case 'staff_reassigned_notify_failed':
				$type    = 'warning';
				$message = __( '服務人員已更新，但通知客人失敗（可能未綁定 LINE、已關閉通知、且沒有 email）。訂單備註已留存變更紀錄，請視情況自行聯繫客人。', 'ultimate-appointments' );
				break;
			case 'booking_completed':
				$message = __( '已標記為完成。', 'ultimate-appointments' );
				break;
			case 'booking_no_show':
				$message = __( '已標記為未到。這筆預約的服務時間已經過去，預設的「即將到來」檢視不會列出它，請到「全部」或「已過期」頁籤查看。', 'ultimate-appointments' );
				break;
			case 'booking_reverted':
				$message = __( '已還原為「已確認」。時段本來就沒有被釋放過，所以還原不會影響其他預約。', 'ultimate-appointments' );
				break;
			case 'booking_revert_failed':
				$type    = 'error';
				$message = __( '無法還原這筆預約。只有「已完成」與「未到」可以還原成「已確認」——已取消／已逾時釋放的預約時段早就還給別人了，還原會造成超賣，需要重新建立預約。', 'ultimate-appointments' );
				break;
			case 'booking_updated':
				$message = __( '已儲存變更。', 'ultimate-appointments' );
				break;
			case 'customer_contacted':
				$message = __( '已標記為聯絡過，這位客人會先從名單上收起來；如果一個月後還是沒來，會重新出現提醒你再跟進一次。', 'ultimate-appointments' );
				break;
			case 'customer_contact_cleared':
				$message = __( '已取消聯絡標記，這位客人會回到名單上。', 'ultimate-appointments' );
				break;
			case 'modules_saved':
				$message = __( '功能模組已儲存。', 'ultimate-appointments' );
				break;
			case 'consumable_saved':
				$message = __( '耗材已儲存。', 'ultimate-appointments' );
				break;
			case 'consumable_deleted':
				$message = __( '耗材已刪除。', 'ultimate-appointments' );
				break;
			case 'consumable_move_saved':
				$message = __( '異動已記錄，庫存已更新。', 'ultimate-appointments' );
				break;
			case 'consumable_recipe_saved':
				$message = __( '配方已儲存。之後標記「完成」的預約才會照新的配方扣用，已經扣過的紀錄不會回頭重算。', 'ultimate-appointments' );
				break;
			case 'consumable_stocktake_done':
				$done    = isset( $_GET['uappt_count'] ) ? absint( $_GET['uappt_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$message = $done > 0
					/* translators: %d: 校正筆數 */
					? sprintf( __( '盤點完成，%d 項有差額並已寫入校正紀錄。', 'ultimate-appointments' ), $done )
					: __( '盤點完成，所有品項的帳面跟實際都一致，沒有需要校正的。', 'ultimate-appointments' );
				break;
			case 'consumable_recalculated':
				$done    = isset( $_GET['uappt_count'] ) ? absint( $_GET['uappt_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$message = $done > 0
					/* translators: %d: 修正筆數 */
					? sprintf( __( '已依異動紀錄重算結存，修正了 %d 項。', 'ultimate-appointments' ), $done )
					: __( '已依異動紀錄重算結存，所有品項本來就是對的。', 'ultimate-appointments' );
				break;
			case 'booking_amount_updated':
				$message = __( '金額與收款方式已更新，金額異動已寫進內部備註（有訂單的話訂單備註也有一份）。已付款的訂單不會自動退差額或補收款，金流請另行處理。', 'ultimate-appointments' );
				break;
			case 'settings_saved':
				$message = __( '設定已儲存。', 'ultimate-appointments' );
				break;
			case 'settings_saved_segment_error':
				// 其他設定都存了，只有時段分類那幾格被退回預設。講清楚是哪一件
				// 事沒生效，不然管理者會以為整頁都沒存。
				$type    = 'warning';
				$message = __( '設定已儲存，但「時段分類」的分界時間看不懂或前後顛倒，那一組已經還原成預設值（12:00 / 18:00），請重新填一次。', 'ultimate-appointments' );
				break;
			case 'settings_saved_field_errors':
				$type    = 'warning';
				$message = __( '設定已儲存，但有兩個以上的區塊填錯了，那幾格已經還原成安全值或沒有存。請分別檢查「班別」、「時段分類」的分界時間與收款方式的費率。', 'ultimate-appointments' );
				break;
			case 'settings_saved_payment_error':
				// 同上：其他設定都存了，只有費率那一格被退回 0。
				$type    = 'warning';
				$message = __( '設定已儲存，但有收款方式的費率不在 0～100 的範圍內，那幾格已經改回 0，請重新填一次。', 'ultimate-appointments' );
				break;
			case 'line_test_sent':
				$message = __( '測試卡片已送出，請確認 LINE 是否收到。', 'ultimate-appointments' );
				break;
			case 'reminder_sent':
				$message = __( '提醒已重新發送。', 'ultimate-appointments' );
				break;
			case 'shift_request_approved':
				$message = __( '申請已核准，班表／請假已經生效。', 'ultimate-appointments' );
				break;
			case 'shift_request_rejected':
				$message = __( '申請已駁回。', 'ultimate-appointments' );
				break;
			case 'shift_batch_approved':
			case 'shift_batch_rejected':
				// 批次審核刻意允許「部分成功」：有硬衝突的那幾天維持 pending，
				// 其餘照樣生效（見 UAPPT_Shift_Request::approve_batch()）。訊息一定
				// 要說得出實際筆數，否則主管會以為整批都過了。
				$done   = isset( $_GET['uappt_count'] ) ? absint( $_GET['uappt_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$failed = isset( $_GET['uappt_failed'] ) ? absint( $_GET['uappt_failed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$verb   = ( 'shift_batch_approved' === $notice )
					? __( '核准', 'ultimate-appointments' )
					: __( '駁回', 'ultimate-appointments' );
				if ( $failed > 0 ) {
					$type    = 'warning';
					$message = sprintf(
						/* translators: 1: 動作名稱 2: 成功筆數 3: 保留待審的筆數 */
						__( '已%1$s %2$d 天，另外 %3$d 天因為與既有預約衝突而保留在待審核狀態，請展開該批次個別處理。', 'ultimate-appointments' ),
						$verb,
						$done,
						$failed
					);
				} else {
					$message = sprintf(
						/* translators: 1: 動作名稱 2: 筆數 */
						__( '已%1$s這批申請共 %2$d 天。', 'ultimate-appointments' ),
						$verb,
						$done
					);
				}
				break;
			case 'booking_type_converted':
				$count   = isset( $_GET['uappt_count'] ) ? absint( $_GET['uappt_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$message = sprintf(
					/* translators: %d: 轉換的商品數量 */
					__( '已將 %d 個商品轉換為「預約商品」類型。', 'ultimate-appointments' ),
					$count
				);
				break;
			case 'booking_type_reverted':
				$count   = isset( $_GET['uappt_count'] ) ? absint( $_GET['uappt_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$message = sprintf(
					/* translators: %d: 還原的商品數量 */
					__( '已將 %d 個商品還原為原本的商品類型。', 'ultimate-appointments' ),
					$count
				);
				break;
			case 'plan_rows_dropped':
				// 這個 case 是「已經存檔完成」之後才發現的警告，type 用 warning：跟
				// success（存好了）與 error（沒存成）都不一樣，是「存了，但有東西被丟掉」。
				$type  = 'warning';
				$count   = isset( $_GET['uappt_count'] ) ? absint( $_GET['uappt_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$message = sprintf(
					/* translators: %d: 因未填名稱而未儲存的方案列數 */
					__( '商品已儲存，但有 %d 個服務方案因為沒有填寫名稱而未被儲存，請回到「預約設定」分頁補上名稱後重新儲存。', 'ultimate-appointments' ),
					$count
				);
				break;
			case 'no_bookable_staff':
				$type    = 'warning';
				$message = __( '商品已儲存，但目前沒有任何設定讓這項服務可以被預約（可能是尚未選擇可服務的人員，或所有服務方案都缺少人員）。前台不會顯示任何可預約時段，請回到「預約設定」分頁檢查。', 'ultimate-appointments' );
				break;
			case 'error':
				$type    = 'error';
				$message = isset( $_GET['uappt_message'] ) ? sanitize_text_field( wp_unslash( $_GET['uappt_message'] ) ) : __( '發生錯誤。', 'ultimate-appointments' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				break;
			default:
				return;
		}

		// 排班時順便新增的班別（v2.100.0）。接在原本那則後面，不另開一則——兩件事是
		// 同一次儲存做的。
		$new_presets = isset( $_GET['uappt_new_presets'] ) ? absint( $_GET['uappt_new_presets'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $new_presets > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: 新增的班別數 */
				__( '也新增了 %d 個班別，之後排班可以直接點。', 'ultimate-appointments' ),
				$new_presets
			);
		}

		// 逐日調整／批次排班帶回來的既有預約衝突。
		//
		// 存檔本身是成功的，所以上面那則維持原樣；衝突另外接一段，並把整則
		// 改成 warning——綠色的「已儲存」配上一行小字沒人會看到，而這件事的
		// 後果是「師傅請假了，客人還約在那天」。
		//
		// ⚠️ 刻意**不自動取消任何預約**：那是不可逆的，而且該怎麼處理（改派
		// 他人、改期、致電客人）只有店家知道。這裡的責任是讓他知道。
		$conflict_days = isset( $_GET['uappt_conflict_days'] ) ? absint( $_GET['uappt_conflict_days'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $conflict_days > 0 ) {
			$conflict_total = isset( $_GET['uappt_conflict_total'] ) ? absint( $_GET['uappt_conflict_total'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw_dates      = isset( $_GET['uappt_conflict_dates'] ) ? sanitize_text_field( wp_unslash( $_GET['uappt_conflict_dates'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			// 全店月排班表帶的是「日期@人員 ID」（v2.94.0）——同一天可能好幾個人都有
			// 衝突，只列日期看不出是誰的客人要處理。
			$dates = array();
			foreach ( array_filter( explode( ',', $raw_dates ) ) as $one ) {
				if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})(?:@(\d+))?$/', $one, $m ) ) {
					continue;
				}
				$who     = ! empty( $m[2] ) ? UAPPT_Staff::get( (int) $m[2] ) : null;
				$dates[] = $who ? $who['name'] . ' ' . $m[1] : $m[1];
			}

			$listed = implode( '、', $dates );
			if ( count( $dates ) < $conflict_days ) {
				$listed = trim(
					$listed . sprintf(
						/* translators: %d: 總共有衝突的天數 */
						__( ' 等 %d 天', 'ultimate-appointments' ),
						$conflict_days
					)
				);
			}

			$type     = 'warning';
			$message .= ' ' . sprintf(
				/* translators: 1: 日期清單 2: 預約筆數 */
				__( '⚠️ 但 %1$s 共有 %2$d 筆已確認／暫留中的預約會落在新的上班時間之外。這些預約不會被自動取消，請改派他人、改期或聯繫客人。', 'ultimate-appointments' ),
				$listed,
				$conflict_total
			);
		}

		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	/* ---------------------------------------------------------------------
	 * 人力資源頁面
	 * ------------------------------------------------------------------- */

	/**
	 * 「人員」區塊底下的頁籤，依 capability 決定顯示哪幾個。
	 *
	 * ⚠️ 兩個頁籤各自認不同的 capability，這是 `UAPPT_Caps` 刻意分開的：
	 * `uappt_approve_shift_requests` 給「可以幫忙審班表、但不該碰預約管理」
	 * 的資深員工。只有一個頁籤時 `render_tabs()` 自己不會輸出導覽列。
	 *
	 * 把關靠的是「不在陣列裡就 dispatch 不到」，跟 sections() 同一個模式——
	 * 網址硬打 `?tab=list` 會被 `staff_tab()` 退回第一個有權限的頁籤。
	 *
	 * @return array
	 */
	protected function staff_tabs() {
		$tabs = array();

		if ( current_user_can( self::CAP ) ) {
			$tabs['list'] = __( '人員清單', 'ultimate-appointments' );
			// 全店月排班表（v2.94.0）。⚠️ 只認 self::CAP，不認審班表的權限：這張表
			// 能直接改所有人的班，跟編輯人員是同一件事（使用者決定，見
			// docs/staff-roster-plan.md 的 Q6）。只能審班表的人照舊用「排班申請」。
			$tabs['roster'] = __( '月排班表', 'ultimate-appointments' );
			// 班別設定（v2.98.0，從「設定 ▸ 預約規則」搬過來）：班別是排班排到一半才會
			// 想改的東西，跟排班放在一起。見 docs/shift-ui-plan.md 的 D2。
			$tabs['shifts'] = __( '班別設定', 'ultimate-appointments' );
		}

		// 排班申請是員工中心的一部分——申請本來就是從前台那兩頁送出來的，
		// 模組關著時沒有人送得出新的申請，後台這一頁自然也不該在。
		// ⚠️ 既有的待審申請**原封留在資料庫**，重新啟用就原樣出現（計劃第 5 節
		// 的三個選項裡選的是「什麼都不做」）。
		if ( UAPPT_Modules::enabled( 'staff_portal' ) && current_user_can( UAPPT_Caps::CAP_APPROVE_SHIFT_REQUESTS ) ) {
			$pending = UAPPT_Shift_Request::get_pending_batch_count();
			$tabs['requests'] = __( '排班申請', 'ultimate-appointments' )
				. ( $pending > 0 ? sprintf( '（%d）', $pending ) : '' );
		}

		return $tabs;
	}

	/**
	 * 目前的人員頁籤，不合法或沒有權限時退回第一個。
	 *
	 * @return string
	 */
	protected function staff_tab() {
		$tabs = $this->staff_tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( isset( $tabs[ $tab ] ) ) {
			return $tab;
		}

		$keys = array_keys( $tabs );

		return $keys ? $keys[0] : 'list';
	}

	/**
	 * 人員區塊：人員清單（含新增／編輯／批次匯入）與排班申請兩個頁籤。
	 *
	 * v2.36.0 把原本獨立的「排班申請」併進來——講的就是人員的班表，跟人力
	 * 資源是同一個心智模型，原本卻是兩個選單。
	 */
	public function render_staff_page() {
		// ⚠️ 這裡是「任一即可」，不是 self::CAP。只能審班表的人也要進得來，
		// 進來之後由 staff_tabs() 決定他看得到哪些頁籤。
		if ( ! current_user_can( self::CAP ) && ! current_user_can( UAPPT_Caps::CAP_APPROVE_SHIFT_REQUESTS ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$tabs = $this->staff_tabs();
		$tab  = $this->staff_tab();

		if ( 'requests' === $tab ) {
			$this->render_shift_requests_page( $tabs, $tab );
			return;
		}

		if ( 'roster' === $tab ) {
			$this->render_roster_page( $tabs, $tab );
			return;
		}

		if ( 'shifts' === $tab ) {
			$shift_presets = UAPPT_Shift_Preset::all();
			self::page_open( __( '人員管理', 'ultimate-appointments' ) );
			self::render_tabs( $tabs, $tab, self::url( 'staff' ) );
			require UAPPT_PLUGIN_DIR . 'includes/views/staff-shifts.php';
			self::page_close();
			return;
		}

		$action     = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing_id = isset( $_GET['staff_id'] ) ? absint( $_GET['staff_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing    = $editing_id ? UAPPT_Staff::get( $editing_id ) : null;

		// 指定了 staff_id 但查不到（例如剛被刪掉後按上一頁），退回列表比較不會困惑。
		if ( 'edit' === $action && ! $editing ) {
			$action = '';
		}

		if ( 'new' === $action || 'edit' === $action ) {
			$form           = $this->get_staff_form_values( $editing );
			$linkable_users = $this->get_linkable_users( (int) $form['user_id'] );

			// 批次排班的月曆。只有編輯既有人員才有——還沒建立的人員沒有 id，
			// 也還沒有班表可排。月曆資料跟員工中心「我的班表」是同一支
			// （UAPPT_Staff::get_schedule_month()），不是另外複製一份。
			$batch_calendar = null;
			if ( $editing ) {
				$requested_month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$batch_month     = preg_match( '/^\d{4}-\d{2}$/', $requested_month )
					? $requested_month
					: substr( current_time( 'Y-m-d' ), 0, 7 );
				$batch_calendar  = UAPPT_Staff::get_schedule_month( $editing, $batch_month );
			}
			self::page_open( $editing ? __( '編輯人員', 'ultimate-appointments' ) : __( '新增人員', 'ultimate-appointments' ), self::back_action( 'uappt-staff', __( '← 回人員清單', 'ultimate-appointments' ) ) );
			self::render_tabs( $tabs, $tab, self::url( 'staff' ) );
			require UAPPT_PLUGIN_DIR . 'includes/views/staff-edit.php';
			self::page_close();
			return;
		}

		if ( 'import' === $action ) {
			// 預覽（plan）存在 transient 而不是塞進網址：一次可能有上千列，
			// 網址塞不下，也不該讓「按上一頁就重新套用一次」變成可能。
			$preview = get_transient( UAPPT_Import::preview_key() );
			$preview = is_array( $preview ) ? $preview : null;
			$staff_list = UAPPT_Staff::get_all();
			self::page_open( __( '批次匯入', 'ultimate-appointments' ), self::back_action( 'uappt-staff', __( '← 回人員清單', 'ultimate-appointments' ) ) );
			self::render_tabs( $tabs, $tab, self::url( 'staff' ) );
			require UAPPT_PLUGIN_DIR . 'includes/views/staff-import.php';
			self::page_close();
			return;
		}

		$staff_list = UAPPT_Staff::get_all();
		self::page_open(
			__( '人員管理', 'ultimate-appointments' ),
			array(
				array( 'url' => self::url( 'staff', array( 'action' => 'new' ) ), 'label' => __( '新增人員', 'ultimate-appointments' ) ),
				array( 'url' => self::url( 'staff', array( 'action' => 'import' ) ), 'label' => __( '批次匯入', 'ultimate-appointments' ) ),
			)
		);
		self::render_tabs( $tabs, $tab, self::url( 'staff' ) );
		require UAPPT_PLUGIN_DIR . 'includes/views/staff-list.php';
		self::page_close();
	}

	/**
	 * 儲存班別（人員管理 ▸ 班別設定，v2.98.0）。
	 *
	 * 清洗規則一字不改，還是 UAPPT_Shift_Preset::save()；搬家前它跟著「設定」整頁表單
	 * 一起存（handle_save_settings() 無條件寫入），現在有自己的表單與 action。
	 *
	 * ⚠️ handle_save_settings() 那一行**已經拿掉**：設定頁不再有班別欄位，留著的話存一次
	 * 設定就會把班別清空。
	 */
	public function handle_save_shift_presets() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_save_shift_presets' );

		$errors = array();
		UAPPT_Shift_Preset::save(
			isset( $_POST['shift_presets'] ) && is_array( $_POST['shift_presets'] ) ? wp_unslash( $_POST['shift_presets'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 由 UAPPT_Shift_Preset::sanitize() 逐欄清洗。
			$errors
		);

		$this->redirect(
			'uappt-staff',
			array(
				'tab'         => 'shifts',
				'uappt_notice' => $errors ? 'shift_presets_partial' : 'shift_presets_saved',
			)
		);
	}

	/**
	 * 全店月排班表（v2.94.0）：人員 × 日期一張表。
	 *
	 * 計畫見 docs/staff-roster-plan.md 的 D5。這一頁**不是新的資料來源**：每一列就是
	 * 那個人的 `UAPPT_Staff::get_range_plan()`——跟人員編輯頁的「一次排整個月」產生器
	 * 同一支，所以兩邊看到的永遠是同一份班表。
	 *
	 * @param array  $tabs 人員頁籤。
	 * @param string $tab  目前頁籤。
	 */
	protected function render_roster_page( array $tabs, $tab ) {
		$requested = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$month     = preg_match( '/^\d{4}-\d{2}$/', $requested ) ? $requested : substr( current_time( 'Y-m-d' ), 0, 7 );

		$month_start = $month . '-01';
		$month_end   = uappt_local_date( $month_start, 'last day of this month' );
		if ( '' === $month_end ) {
			$month       = substr( current_time( 'Y-m-d' ), 0, 7 );
			$month_start = $month . '-01';
			$month_end   = uappt_local_date( $month_start, 'last day of this month' );
		}

		$today = current_time( 'Y-m-d' );
		$dates = array();
		// 第幾週（0 起算）：月曆格線是週一起算，1 號落在週幾決定第一週有幾天。
		// 手機的週檢視靠這個數字切欄（v2.95.0），跟 month-grid.php 的列是同一種切法。
		$lead       = uappt_local_weekday_iso( $month_start ) - 1;
		$today_week = null;
		for ( $cursor = $month_start; '' !== $cursor && $cursor <= $month_end; $cursor = uappt_local_date( $cursor, '+1 day' ) ) {
			$day              = (int) substr( $cursor, 8, 2 );
			$dates[ $cursor ] = array(
				'day'     => $day,
				// 週一＝0（跟月曆格線同一個順序，見 month-grid.php 的 ⚠️）。
				'weekday' => uappt_local_weekday_iso( $cursor ) - 1,
				'week'    => intdiv( $day - 1 + $lead, 7 ),
				'is_past' => $cursor < $today,
				'today'   => $cursor === $today,
				'shop'    => UAPPT_Shop_Closure::reason( $cursor ),
			);
			if ( $cursor === $today ) {
				$today_week = $dates[ $cursor ]['week'];
			}
		}
		$weeks = $dates ? end( $dates )['week'] + 1 : 1;

		// 週檢視一進來看哪一週：存檔後帶回來的那一週 → 本月就看今天那一週 → 第一週。
		// 存檔要帶回來，不然在手機上排第三週、按儲存，回來又變第一週。
		$requested_week = isset( $_GET['week'] ) ? sanitize_text_field( wp_unslash( $_GET['week'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $requested_week && ctype_digit( $requested_week ) && (int) $requested_week < $weeks ) {
			$initial_week = (int) $requested_week;
		} else {
			$initial_week = null !== $today_week ? $today_week : 0;
		}

		// 這個月還在等待審核的排班申請（v2.96.0，計畫 D7）。
		$requests = $this->roster_pending_requests( $month_start, $month_end );

		// 只列啟用中的人員：停用的人不會被排進任何預約，列出來只是讓表變長。
		//
		// 每一列另外帶兩份產生器的資料（v2.95.0），跟人員編輯頁那三顆產生器是同一支：
		// - prev：上個月照「第幾週 × 星期幾」對過來。**上個月一天都沒排的人給 null**
		//   ——不給的話「複製上個月」會把新進人員這個月已經排好的班整個清成未排班，
		//   而全店一次套用時根本不會有人注意到是哪一列被清掉。
		// - template：只有固定班、而且樣板有填的人才有。彈性班的樣板不參與（D2），
		//   空樣板套下去是整個月未排班。
		$rows = array();
		foreach ( UAPPT_Staff::get_all( true ) as $staff ) {
			$prev     = null;
			$template = null;
			if ( empty( $staff['is_24h'] ) ) {
				$aligned = UAPPT_Staff::get_prev_month_plan_aligned( $staff, $month );
				foreach ( $aligned as $day ) {
					if ( 'clear' !== $day['t'] ) {
						$prev = $aligned;
						break;
					}
				}
				if ( ! UAPPT_Staff::is_flex( $staff ) && UAPPT_Staff::template_has_ranges( $staff['business_hours'] ) ) {
					$template = UAPPT_Staff::get_range_plan( $staff, $month_start, $month_end, true );
				}
			}

			$rows[] = array(
				'staff'    => $staff,
				'plan'     => UAPPT_Staff::get_range_plan( $staff, $month_start, $month_end ),
				'prev'     => $prev,
				'template' => $template,
				'requests' => isset( $requests[ (int) $staff['id'] ] ) ? $requests[ (int) $staff['id'] ] : array(),
			);
		}

		$roster = array(
			'month'        => $month,
			'prev_month'   => substr( uappt_local_date( $month_start, '-1 month' ), 0, 7 ),
			'next_month'   => substr( uappt_local_date( $month_start, '+1 month' ), 0, 7 ),
			'dates'        => $dates,
			'weeks'        => $weeks,
			'initial_week' => $initial_week,
			'rows'         => $rows,
		);

		self::page_open( __( '人員管理', 'ultimate-appointments' ) );
		self::render_tabs( $tabs, $tab, self::url( 'staff' ) );
		require UAPPT_PLUGIN_DIR . 'includes/views/staff-roster.php';
		self::page_close();
	}

	/**
	 * 全店月排班表要畫出來的待審申請：人員 ID => 日期 => 一筆申請（v2.96.0）。
	 *
	 * - 只有「員工中心」模組開著、而且這個人有審核權限時才有——跟「排班申請」頁籤
	 *   出不出現同一個條件（staff_tabs()）。模組關著時既有的待審申請原封留在資料庫，
	 *   重新開啟就原樣出現。
	 * - 只收 `hours`／`leave`：時段佔用（block）不改變那天的班別，畫在班表格子上
	 *   說不清楚，留在排班申請頁籤處理（計畫 D7 的「明確不做」）。
	 * - 同一天同時有「自訂時段」與「請假」兩筆待審時（不同類型可以並存，見
	 *   create() 的 has_pending()），格子上只放**最新的那一筆**——那是員工現在的
	 *   意思。舊的那筆留在排班申請頁籤。
	 *
	 * @param string $from 起日 (Y-m-d)。
	 * @param string $to   迄日 (Y-m-d)。
	 * @return array
	 */
	protected function roster_pending_requests( $from, $to ) {
		if ( ! UAPPT_Modules::enabled( 'staff_portal' ) || ! current_user_can( UAPPT_Caps::CAP_APPROVE_SHIFT_REQUESTS ) ) {
			return array();
		}

		$rows = UAPPT_Shift_Request::query(
			array(
				'status'            => UAPPT_Shift_Request::STATUS_PENDING,
				'date_from'         => $from,
				'date_to'           => $to,
				'order_by_date_asc' => true,
				'limit'             => 5000,
			)
		);

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! in_array( $row['type'], array( UAPPT_Shift_Request::TYPE_HOURS, UAPPT_Shift_Request::TYPE_LEAVE ), true ) ) {
				continue;
			}
			$sid  = (int) $row['staff_id'];
			$date = $row['request_date'];
			if ( isset( $out[ $sid ][ $date ] ) && (int) $out[ $sid ][ $date ]['id'] > (int) $row['id'] ) {
				continue;
			}

			$is_hours = UAPPT_Shift_Request::TYPE_HOURS === $row['type'];
			$ranges   = $is_hours ? json_decode( (string) $row['hours'], true ) : array();
			$ranges   = is_array( $ranges ) ? array_values( $ranges ) : array();
			$type     = $is_hours ? 'hours' : UAPPT_Staff::CLOSED_LEAVE;

			$out[ $sid ][ $date ] = array(
				'id'     => (int) $row['id'],
				'type'   => $type,
				'ranges' => $ranges,
				'color'  => $is_hours ? UAPPT_Shift_Preset::color_for_ranges( $ranges ) : '',
				'long'   => UAPPT_Staff::plan_label( $type, $ranges ),
				'short'  => UAPPT_Staff::plan_label( $type, $ranges, true ),
				'note'   => (string) $row['staff_note'],
			);
		}

		return $out;
	}

	/**
	 * 儲存全店月排班表（v2.94.0）。
	 *
	 * ⚠️ **改過的格子打包成一個 JSON 欄位（`roster`）送上來，不是每格一個 input。**
	 * PHP 的 `max_input_vars` 預設 1000（這台 Local 是 4000，但很多主機維持預設）。
	 * 每月班表一個人最多 62 個欄位吃不到上限，但全店表 20 人 × 31 天 × 2 個欄位
	 * ＝1,240——**超過的部分 PHP 會靜默丟掉**：存檔成功、畫面沒錯，後半個月的班
	 * 就是沒存進去。一個 JSON 欄位永遠只算 1 個。
	 *
	 * 代價是要把它當完全不可信的輸入：形狀不對的一律略過，每一格照樣走
	 * `save_painted_day()`——跟每月班表同一支驗證，不另寫第二份。
	 *
	 * 寫入仍然是 admin-post ＋ nonce ＋ capability（紀律 #8）。
	 */
	public function handle_save_roster() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_save_roster' );

		$month = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
		$back  = array( 'tab' => 'roster' );
		if ( preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$back['month'] = $month;
		}
		// 週檢視排到哪一週就回到哪一週（v2.95.0）。範圍由頁面那一側再驗一次。
		$week = isset( $_POST['week'] ) ? sanitize_text_field( wp_unslash( $_POST['week'] ) ) : '';
		if ( '' !== $week && ctype_digit( $week ) && (int) $week < 6 ) {
			$back['week'] = (int) $week;
		}

		$raw     = isset( $_POST['roster'] ) ? wp_unslash( $_POST['roster'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON，下面逐欄清洗。
		$decoded = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
		$posted  = is_array( $decoded ) ? $decoded : array();

		// 排班時「存成班別」的那幾個（v2.100.0），理由同 handle_save_staff_month()。
		$new_presets = $this->save_new_presets_from_post();
		if ( $new_presets ) {
			$back['uappt_new_presets'] = $new_presets;
		}

		// 待審申請的決定（v2.96.0）：申請 ID => approve｜reject。跟格子分兩個欄位：
		// 一個是「這天要排成什麼」，一個是「這筆申請要不要准」，混在同一包裡的話
		// 伺服器得從形狀猜是哪一種。
		$decisions = $this->roster_posted_decisions( $month );

		if ( empty( $posted ) && empty( $decisions ) ) {
			$this->redirect( 'uappt-staff', array_merge( $back, array( 'uappt_notice' => 'staff_month_unchanged' ) ) );
		}

		// 上限＝啟用中人數 × 31。擋的是「自己組一包 POST」：衝突檢查是逐格跑的，
		// 沒有上限會被拖垮。正常操作不可能超過——整張表本來就只有這麼多格。
		$active = UAPPT_Staff::get_all( true );
		$limit  = max( 31, count( $active ) * 31 );
		$cells  = 0;
		foreach ( $posted as $days ) {
			$cells += is_array( $days ) ? count( $days ) : 0;
		}
		if ( $cells > $limit ) {
			$this->redirect_with_error(
				'uappt-staff',
				__( '一次送出的格子比整張表還多，沒有儲存任何東西。請重新整理頁面再試一次。', 'ultimate-appointments' ),
				$back
			);
		}

		$active_ids = array();
		foreach ( $active as $staff ) {
			// ⚠️ 24 小時人員不收：他們走 get_business_windows() 的捷徑，寫任何一列
			// 都會讓捷徑失效（從「全天開放」變成「只上那一列的時段」）。畫面上那一列
			// 本來就不能點，這裡是防著自己組的 POST。
			if ( empty( $staff['is_24h'] ) ) {
				$active_ids[ (int) $staff['id'] ] = $staff['name'];
			}
		}

		$done      = 0;
		$people    = array();
		$conflicts = array();

		foreach ( $posted as $raw_staff_id => $days ) {
			$staff_id = absint( $raw_staff_id );
			if ( ! isset( $active_ids[ $staff_id ] ) || ! is_array( $days ) ) {
				continue;
			}

			foreach ( $days as $raw_date => $row ) {
				$result = $this->save_painted_day( $staff_id, $month, $raw_date, $row );

				if ( is_wp_error( $result ) ) {
					// 一格寫失敗就停下來並講清楚是誰的哪一天，理由同每月班表。
					$this->redirect_with_error(
						'uappt-staff',
						sprintf(
							/* translators: 1: 人員姓名 2: 錯誤訊息 3: 已完成格數 */
							__( '%1$s：%2$s 在這之前已經存了 %3$d 格。', 'ultimate-appointments' ),
							$active_ids[ $staff_id ],
							$result->get_error_message(),
							$done
						),
						$back
					);
				}

				if ( $result['hard'] > 0 ) {
					// 鍵帶上人員：全店表上同一天可能好幾個人都有衝突，只列日期的話
					// 看不出是誰的客人要處理（見 render_notice() 的解析）。
					$conflicts[ $result['date'] . '@' . $staff_id ] = $result['hard'];
				}
				if ( $result['written'] ) {
					$done++;
					$people[ $staff_id ] = true;
				}
			}
		}

		// 申請**排在格子後面**處理：同一格如果既排了班、又接受了申請（畫面上不會
		// 送出這種組合，防的是自己組的 POST），以核准的結果為準——那一列會帶著
		// source='shift_request' 的憑證，說得出「這天為什麼是這樣」。
		$review = array(
			'approved' => 0,
			'rejected' => 0,
			'failed'   => array(),
		);
		if ( $decisions ) {
			$review = UAPPT_Shift_Request::review_many( $decisions, get_current_user_id() );
		}

		$changed = $done + $review['approved'] + $review['rejected'];

		$this->redirect(
			'uappt-staff',
			array_merge(
				$back,
				array(
					'uappt_notice'   => $changed > 0 || $review['failed'] ? 'roster_saved' : 'staff_month_unchanged',
					'uappt_count'    => $done,
					'uappt_people'   => count( $people ),
					'uappt_approved' => $review['approved'],
					'uappt_rejected' => $review['rejected'],
					'uappt_held'     => count( $review['failed'] ),
				),
				$this->conflict_redirect_args( $conflicts )
			)
		);
	}

	/**
	 * 全店月排班表送上來的申請決定，清洗過後的「申請 ID => approve｜reject」。
	 *
	 * 只收**這個月、還在等待審核、類型是 hours／leave**的申請，而且要有審核權限、
	 * 員工中心模組開著——跟畫面上會不會出現那個虛線框是同一組條件
	 * （roster_pending_requests()）。畫面上不可能送出的東西一律丟掉，不報錯。
	 *
	 * @param string $month 送出的月份 (Y-m)。
	 * @return array
	 */
	protected function roster_posted_decisions( $month ) {
		$raw = isset( $_POST['roster_requests'] ) ? wp_unslash( $_POST['roster_requests'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- JSON，下面逐筆清洗；呼叫端已驗 nonce。
		$raw = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return array();
		}
		if ( ! UAPPT_Modules::enabled( 'staff_portal' ) || ! current_user_can( UAPPT_Caps::CAP_APPROVE_SHIFT_REQUESTS ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $id => $decision ) {
			$id       = absint( $id );
			$decision = sanitize_key( (string) $decision );
			if ( ! $id || ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
				continue;
			}
			$row = UAPPT_Shift_Request::get( $id );
			if ( ! $row
				|| UAPPT_Shift_Request::STATUS_PENDING !== $row['status']
				|| substr( $row['request_date'], 0, 7 ) !== $month
				|| ! in_array( $row['type'], array( UAPPT_Shift_Request::TYPE_HOURS, UAPPT_Shift_Request::TYPE_LEAVE ), true ) ) {
				continue;
			}
			$out[ $id ] = $decision;
		}

		return $out;
	}

	/**
	 * 取得可以綁定給人員的 WordPress 使用者清單，供「綁定使用者帳號」的下拉
	 * 選單使用。
	 *
	 * 刻意排除 `customer`／`subscriber` 角色——電商網站的會員（customer）
	 * 動輒成千上萬，全部塞進一個 `<select>` 既沒有意義（客人不該被綁成
	 * 服務人員）也會讓下拉選單完全沒辦法用。已經綁定給「別的」人員的使用者
	 * 也會被濾掉，不要讓管理者選到一個一送出就會被 UAPPT_Staff::save() 擋下
	 * 的選項；但如果是「這位人員自己目前的綁定」則要保留，不然編輯畫面上
	 * 會看不到目前已經選好的人。
	 *
	 * @param int $current_user_id 正在編輯的這位人員目前綁定的使用者 ID（0 代表沒有綁定）。
	 * @return WP_User[]
	 */
	protected function get_linkable_users( $current_user_id = 0 ) {
		$users = get_users(
			array(
				'role__not_in' => array( 'customer', 'subscriber' ),
				'orderby'       => 'display_name',
				'order'         => 'ASC',
			)
		);

		global $wpdb;
		$linked_elsewhere = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"SELECT user_id FROM " . UAPPT_Install::table( 'staff' ) . " WHERE user_id IS NOT NULL" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		$linked_elsewhere = array_map( 'intval', $linked_elsewhere );

		return array_values(
			array_filter(
				$users,
				function ( $user ) use ( $linked_elsewhere, $current_user_id ) {
					$uid = (int) $user->ID;
					return $uid === $current_user_id || ! in_array( $uid, $linked_elsewhere, true );
				}
			)
		);
	}

	/**
	 * 取得要填進編輯表單的值。
	 *
	 * 正常情況就是資料庫裡的內容；但如果上一次存檔因為格式錯誤被擋下來，會優先
	 * 用暫存的送出內容，避免管理員填了整週營業時間卻因為一格打錯就全部消失。
	 *
	 * @param array|null $editing 正在編輯的人員。
	 * @return array
	 */
	protected function get_staff_form_values( $editing ) {
		$key     = $this->staff_form_transient_key( $editing ? (int) $editing['id'] : 0 );
		$stashed = get_transient( $key );

		if ( is_array( $stashed ) ) {
			delete_transient( $key );
			return $stashed;
		}

		if ( is_array( $editing ) ) {
			return $editing;
		}

		// schedule_mode 留空：新增頁不問排班方式（v2.96.2），存檔時由 save() 推導。
		return array(
			'name'             => '',
			'status'           => 'active',
			'is_24h'           => false,
			'schedule_mode'    => '',
			'slot_interval'    => '',
			'business_hours'   => array(),
			'price_adjustment' => 0,
			'sort_order'       => 0,
			'capacity'         => 1,
			'user_id'          => 0,
			'photo_id'         => 0,
		);
	}

	/**
	 * 暫存表單內容用的 transient key。
	 *
	 * 除了每位管理員各自獨立，也要綁定是哪一位人員——否則在 A 人員存檔失敗後
	 * 5 分鐘內去編輯 B 人員，B 的表單會被塞進 A 的內容。
	 *
	 * @param int $staff_id 人員 ID（新增時為 0）。
	 * @return string
	 */
	protected function staff_form_transient_key( $staff_id ) {
		return 'uappt_staff_form_' . get_current_user_id() . '_' . (int) $staff_id;
	}

	/**
	 * 儲存人員表單送出。
	 */
	/**
	 * 卡片裡的「說明」摺疊區。
	 *
	 * ⚠️ **規則：一張卡片最多一句常駐說明，其餘一律進這裡。**
	 *
	 * 人員編輯頁曾經有 31 段常駐說明、約 9,000 字介面文字。每一段當初都有理由
	 * （多半是在防一個具體的坑），但加起來就是手機上在控制項之間的一道道文字牆
	 * ——而文字牆的實際效果是**沒有人讀**，連真正重要的那幾句一起被跳過。
	 *
	 * 收進來不是刪掉：要查的時候點一下就看得到。只有「不照做會掉資料或靜默壞掉」
	 * 的 ⚠️ 留在外面。
	 *
	 * @param array $paragraphs 段落（允許 wp_kses_post 的行內標記）。
	 */
	public static function help( array $paragraphs ) {
		$paragraphs = array_filter( $paragraphs );
		if ( empty( $paragraphs ) ) {
			return;
		}

		echo '<details class="uappt-help"><summary>' . esc_html__( '說明', 'ultimate-appointments' ) . '</summary><div class="uappt-help-body">';
		foreach ( $paragraphs as $paragraph ) {
			echo '<p>' . wp_kses_post( $paragraph ) . '</p>';
		}
		echo '</div></details>';
	}

	/**
	 * 設定卡片裡那張表單的開頭（只有編輯既有人員時才印）。
	 *
	 * 新增人員時所有卡片共用**一個**表單、一顆「建立人員」——還不存在的紀錄沒辦法
	 * 分批存，而且第一次填本來就是一口氣填完。
	 *
	 * @param string $card     卡片識別（要在 staff_card_fields() 裡）。
	 * @param int    $staff_id 人員 ID。
	 */
	public static function staff_card_form_open( $card, $staff_id ) {
		printf(
			'<form method="post" action="%s" class="uappt-card-form">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="uappt_save_staff" />';
		printf( '<input type="hidden" name="staff_id" value="%d" />', (int) $staff_id );
		printf( '<input type="hidden" name="uappt_card" value="%s" />', esc_attr( $card ) );
		wp_nonce_field( 'uappt_save_staff' );
	}

	/**
	 * 設定卡片裡那張表單的結尾（鈕 ＋ `</form>`）。
	 *
	 * 文案一律是「儲存」兩個字：鈕就在卡片裡面，範圍由卡片的標題定義，不需要再在
	 * 鈕上重述一次。v2.89.0 以前那四顆鈕各自叫「儲存基本資料與固定班樣板」
	 * 「儲存這個月」「儲存逐日調整」，正是因為它們沒有一個看得出範圍的容器。
	 */
	public static function staff_card_form_close() {
		echo '<p class="submit uappt-card-submit">';
		submit_button( __( '儲存', 'ultimate-appointments' ), 'primary', 'submit', false );
		echo '</p></form>';
	}

	/**
	 * 排班方式三選一的值（畫面用；儲存時拆成 `is_24h` ＋ `schedule_mode`）。
	 *
	 * @return string[]
	 */
	public static function schedule_kinds() {
		return array( UAPPT_Staff::MODE_FIXED, UAPPT_Staff::MODE_FLEX, '24h' );
	}

	/**
	 * 人員編輯頁每一張設定卡片**擁有哪些欄位**。
	 *
	 * ⚠️ **這張表是「部分欄位存檔」的全部安全性所在。** `UAPPT_Staff::save()` 會寫入
	 * 所有欄位，而每張卡片只送自己那幾個——handler 若直接拿 `$_POST` 整包去存，
	 * 沒送出的欄位會被清空（checkbox 更慘：`is_24h` 沒送＝關閉，存一次「基本資料」
	 * 就會把 24 小時營業關掉）。所以存檔流程是**先載入現有資料，只覆蓋這張卡片
	 * 擁有的欄位**。
	 *
	 * 反過來說，漏把某個欄位登記進來，那個欄位就會變成「改了存不進去」——存檔
	 * 成功、畫面沒錯，值卻沒變。**新增欄位時一定要同時改這裡。**
	 *
	 * @return array 卡片識別 => 欄位名陣列。
	 */
	public static function staff_card_fields() {
		// v2.93.0：`is_24h` 從「預約設定」搬到「排班方式」——畫面上它跟固定班／
		// 彈性班合併成一個三選一（理由見 docs/staff-roster-plan.md 的 D1）。三個欄位
		// 一定要在同一張卡片：分在兩張的話，存其中一張就會用另一張的舊值把剛選的
		// 排班方式蓋回去。
		return array(
			'basic'      => array( 'name', 'photo_id', 'status', 'sort_order' ),
			'booking'    => array( 'capacity', 'slot_interval', 'price_adjustment', 'user_id' ),
			'commission' => array( 'commission' ),
			'schedule'   => array( 'is_24h', 'schedule_mode', 'business_hours' ),
		);
	}

	/**
	 * 把 `$_POST` 讀成 `UAPPT_Staff::save()` 要的形狀。
	 *
	 * 注意：這裡刻意不對每個時間欄位的格式做檢查／丟棄——格式驗證與正規化
	 * （包含接受 "930"、"9:5" 這類手動輸入格式）統一交給 `UAPPT_Staff::save()`
	 * 內部處理，格式錯誤時會回傳明確的 `WP_Error`，而不是在這裡就把資料默默丟掉。
	 *
	 * @param int $staff_id 人員 ID（0 代表新增）。
	 * @return array
	 */
	protected function staff_posted_data( $staff_id ) {
		$hours = array();
		if ( ! empty( $_POST['hours'] ) && is_array( $_POST['hours'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( wp_unslash( $_POST['hours'] ) as $day => $ranges ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing
				$day    = sanitize_key( $day );
				$parsed = array();
				if ( is_array( $ranges ) ) {
					foreach ( $ranges as $range ) {
						$parsed[] = array(
							isset( $range['start'] ) ? sanitize_text_field( $range['start'] ) : '',
							isset( $range['end'] ) ? sanitize_text_field( $range['end'] ) : '',
						);
					}
				}
				$hours[ $day ] = $parsed;
			}
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 呼叫端已經 check_admin_referer()。

		// 排班方式三選一（v2.93.0）→ 兩個欄位。選 24 小時時 schedule_mode 送空字串
		// ＝「不動」（UAPPT_Staff::save() 的約定），取消 24 小時會回到原本的方式。
		//
		// ⚠️ 沒有 `schedule_kind` 的時候退回讀舊的 `is_24h` 勾選框：v2.93.0 以前的
		// 頁面還開著就送出，送的是勾選框。只看三選一的話，那種送出會把 24 小時關掉。
		$kind = isset( $_POST['schedule_kind'] ) ? sanitize_key( wp_unslash( $_POST['schedule_kind'] ) ) : '';
		$kind = in_array( $kind, self::schedule_kinds(), true ) ? $kind : '';

		return array(
			'id'               => $staff_id,
			'name'             => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'status'           => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'active',
			'is_24h'           => '' !== $kind ? '24h' === $kind : ! empty( $_POST['is_24h'] ),
			'schedule_mode'    => '24h' === $kind ? '' : $kind,
			// 給 handle_save_staff() 檢查「新增時有沒有選」用，save() 不讀這個鍵。
			'schedule_kind'    => $kind,
			'business_hours'   => $hours,
			'slot_interval'    => isset( $_POST['slot_interval'] ) ? sanitize_text_field( wp_unslash( $_POST['slot_interval'] ) ) : '',
			'price_adjustment' => isset( $_POST['price_adjustment'] ) ? (float) $_POST['price_adjustment'] : 0,
			// 業績抽成。清洗與排序都交給 UAPPT_Staff::sanitize_commission()
			// （級距要排序、第一段要拉到 0、0% 的列要丟掉），這裡只負責把
			// 表單的形狀轉過去。
			'commission'       => array(
				'mode'          => isset( $_POST['commission_mode'] ) ? sanitize_key( wp_unslash( $_POST['commission_mode'] ) ) : 'marginal',
				'tiers'         => isset( $_POST['commission_tiers'] ) ? (array) wp_unslash( $_POST['commission_tiers'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'upcharge_rate' => isset( $_POST['commission_upcharge_rate'] ) ? (float) $_POST['commission_upcharge_rate'] : 0,
			),
			'sort_order'       => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
			'capacity'         => isset( $_POST['capacity'] ) ? (int) $_POST['capacity'] : 1,
			'user_id'          => isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0,
			'photo_id'         => isset( $_POST['photo_id'] ) ? absint( $_POST['photo_id'] ) : 0,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * 把資料庫裡現有的人員資料轉成 `UAPPT_Staff::save()` 要的形狀。
	 *
	 * 部分欄位存檔時的「底」：先拿這一份，再用卡片送上來的欄位覆蓋。
	 * `UAPPT_Staff::get()` 回傳的是 hydrate 過的列（`business_hours` 已解碼成陣列、
	 * `commission` 已解碼、`is_24h` 已轉成 bool），剛好就是 `save()` 吃的形狀。
	 *
	 * @param array $staff 現有人員資料。
	 * @return array
	 */
	protected function staff_data_from_record( array $staff ) {
		return array(
			'id'               => (int) $staff['id'],
			'name'             => (string) $staff['name'],
			'status'           => (string) $staff['status'],
			'is_24h'           => ! empty( $staff['is_24h'] ),
			'schedule_mode'    => (string) $staff['schedule_mode'],
			'business_hours'   => is_array( $staff['business_hours'] ) ? $staff['business_hours'] : array(),
			'slot_interval'    => (string) $staff['slot_interval'],
			'price_adjustment' => (float) $staff['price_adjustment'],
			'commission'       => is_array( $staff['commission'] ) ? $staff['commission'] : array(),
			'sort_order'       => (int) $staff['sort_order'],
			'capacity'         => (int) $staff['capacity'],
			'user_id'          => (int) $staff['user_id'],
			'photo_id'         => (int) $staff['photo_id'],
		);
	}

	public function handle_save_staff() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_save_staff' );

		$staff_id = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;
		$card     = isset( $_POST['uappt_card'] ) ? sanitize_key( wp_unslash( $_POST['uappt_card'] ) ) : '';
		$posted   = $this->staff_posted_data( $staff_id );
		$cards    = self::staff_card_fields();

		if ( $staff_id && isset( $cards[ $card ] ) ) {
			// **部分欄位存檔**：v2.90.0 起每張設定卡片是自己的表單、自己的鈕，
			// 所以送上來的只有那張卡片的欄位。
			//
			// ⚠️ 直接拿 $posted 去存會把沒送出的欄位清空——checkbox 更慘：
			// `is_24h` 沒送＝關閉，存一次「基本資料」就會把 24 小時營業關掉。
			// 所以先載入現有資料當底，只覆蓋這張卡片**登記過**的欄位。
			$existing = UAPPT_Staff::get( $staff_id );
			if ( ! $existing ) {
				$this->redirect_with_error( 'uappt-staff', __( '找不到這位人員。', 'ultimate-appointments' ), array() );
			}

			$data = $this->staff_data_from_record( $existing );
			foreach ( $cards[ $card ] as $field ) {
				$data[ $field ] = $posted[ $field ];
			}
		} elseif ( $staff_id && '' !== $card ) {
			// ⚠️ **帶了卡片識別、但認不得——必須擋下來，不能退回「整包存」。**
			//
			// 退回整包存的後果是靜默的資料遺失：那張表單只送了自己那幾個欄位，
			// 整包存會把其餘的全部清空（實測確認抽成會整個不見）。而且它不會報錯，
			// 存檔「成功」，使用者要過很久才會發現抽成設定沒了。
			//
			// 認不得的來源只有兩種：程式漏把欄位登記進 staff_card_fields()，或是
			// 有人自己組了一包 POST。兩種都該停下來，不是猜著存。
			$this->redirect_with_error(
				'uappt-staff',
				__( '這次送出的資料無法辨識是哪一個區塊，為了避免覆蓋掉其他設定，沒有儲存任何東西。請重新整理頁面再試一次。', 'ultimate-appointments' ),
				array(
					'action'   => 'edit',
					'staff_id' => $staff_id,
				)
			);
		} else {
			// 新增（還沒有紀錄可以合併，四張設定卡片共用一個表單一次送出），或是
			// v2.89.0 以前那種「整頁一個表單」的舊頁面還開著就送出——後者送的是
			// 全部欄位，整包存對它才是對的。
			$data = $posted;
		}

		// v2.96.2 起新增頁不問排班方式（使用者決定，見 docs/staff-roster-plan.md 的 Q3）：
		// 沒送 schedule_kind，UAPPT_Staff::save() 照樣板推導——新增頁也沒有樣板，所以
		// 新人員一律是彈性班、沒有任何班，建立後到編輯頁再決定。
		$result = UAPPT_Staff::save( $data );

		if ( is_wp_error( $result ) ) {
			// 先把使用者剛送出的內容暫存起來，轉址後由編輯頁重新填回表單，
			// 不要因為一個時間格式打錯就讓整份設定重打。
			set_transient( $this->staff_form_transient_key( $staff_id ), $data, 5 * MINUTE_IN_SECONDS );

			$this->redirect_with_error(
				'uappt-staff',
				$result->get_error_message(),
				$staff_id
					? array(
						'action'   => 'edit',
						'staff_id' => $staff_id,
					)
					: array( 'action' => 'new' )
			);
		}

		// 存檔後留在該人員的編輯頁（新增的話就是剛建立的那一位），
		// 讓管理員可以接著排班，而不是被丟回空白的新增表單。
		//
		// ⚠️ **新增跟編輯給不一樣的通知。** 剛建立的人員最常見的下一個問題是
		// 「為什麼客人約不到他」——答案是還沒排班，而這件事在畫面上沒有任何提示
		// （月曆一片空白跟「排好了、那幾天就是不上班」長得一模一樣）。所以新增時
		// 直接把下一步講出來，而不是給一句「人員已儲存」就讓人自己去猜。
		$this->redirect(
			'uappt-staff',
			array(
				'action'      => 'edit',
				'staff_id'    => (int) $result,
				'uappt_notice' => $staff_id ? 'staff_saved' : 'staff_created',
			)
		);
	}

	/**
	 * 快速切換人員的啟用/停用狀態（人力資源列表頁的單一動作連結）。
	 *
	 * 離職／請假的正常處理方式是停用，不是刪除——停用的人員不會再被排進新
	 * 預約，但既有的班表、請假紀錄、指定加價都還在，日後回來上班或需要查
	 * 歷史資料都不用重建。刻意獨立於「編輯」表單之外：只是切換一個欄位，
	 * 不需要管理者為了這件事點進完整的編輯頁、再重新確認一次班表細節。
	 *
	 * 用 UAPPT_Staff::get() 讀回完整資料再整包丟給 save()，是因為 save() 是
	 * 整列覆寫（見該方法的說明），不能只傳 status 一個欄位——那樣會把其他
	 * 欄位存成空值。get() 的 hydrate() 已經把 business_hours/time_segments
	 * 解回 save() 要的陣列格式，兩邊資料形狀本來就是對稱的。
	 */
	public function handle_toggle_staff_status() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_toggle_staff_status' );

		$staff_id = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;
		$staff    = $staff_id ? UAPPT_Staff::get( $staff_id ) : null;

		if ( ! $staff ) {
			$this->redirect_with_error( 'uappt-staff', __( '找不到這位人員。', 'ultimate-appointments' ) );
		}

		$staff['status'] = 'active' === $staff['status'] ? 'inactive' : 'active';
		$result          = UAPPT_Staff::save( $staff );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( 'uappt-staff', $result->get_error_message() );
		}

		$this->redirect(
			'uappt-staff',
			array( 'uappt_notice' => 'active' === $staff['status'] ? 'staff_activated' : 'staff_deactivated' )
		);
	}

	/**
	 * 刪除人員。
	 */
	public function handle_delete_staff() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_delete_staff' );

		$staff_id = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;
		if ( ! $staff_id ) {
			$this->redirect( 'uappt-staff' );
		}

		// 真正的防線在這裡，不是列表頁那句 confirm()：還有進行中或未來的預約／
		// 時段佔用時絕不能刪，否則那些紀錄的 staff_id 會指向不存在的人員——
		// 日曆會直接靜默漏掉它們，slot_grid 裡對應的格子也永遠沒有人釋放，
		// 時段就此永久卡死。要處理離職／請假應該用「停用」，不是刪除。
		$upcoming = UAPPT_Booking::count_upcoming_for_staff( $staff_id );
		if ( $upcoming > 0 ) {
			$this->redirect_with_error(
				'uappt-staff',
				sprintf(
					/* translators: %d: 這位人員名下尚未結束的預約／時段佔用筆數 */
					__( '這位人員名下還有 %d 筆進行中或即將到來的預約／時段佔用，請先改派服務人員或取消這些紀錄後再刪除。若只是暫時請假或離職，建議改用「停用」而不是刪除。', 'ultimate-appointments' ),
					$upcoming
				)
			);
		}

		UAPPT_Staff::delete( $staff_id );

		// 商品／方案的可服務人員名單裡不該留著一個已經刪除的人員 ID——那會讓
		// 候選名單混進查不到資料的幽靈 ID，比商品因此變成「沒有可服務人員」
		// 更難察覺、更難排查。
		UAPPT_Product::remove_staff_from_all_products( $staff_id );

		$this->redirect( 'uappt-staff', array( 'uappt_notice' => 'staff_deleted' ) );
	}

	/* ---------------------------------------------------------------------
	 * UI 元件
	 * ------------------------------------------------------------------- */

	/**
	 * 讀出一個 Lucide 線條圖示的 SVG 原始碼。
	 *
	 * 圖示檔在 `assets/icons/`（ISC 授權，來源與理由見該資料夾的 README.txt）。
	 * 用 Lucide 而不是 Dashicons，是為了跟站上另一個自製外掛「終極電商」的
	 * 後台一致——它已經用這一套，兩個外掛並排時才會看起來是同一個系列。
	 *
	 * slug 先用 `^[a-z0-9-]+$` 過濾再組路徑：這個函式的參數雖然目前全是
	 * 程式碼裡寫死的字串，但它讀的是檔案系統，擋掉 `../` 這類字元是最基本的
	 * 防線，不能因為「現在的呼叫端都是安全的」就省略。
	 *
	 * 回傳的是**未跳脫的 SVG 標記**，呼叫端要用 wp_kses() 或在確定內容是
	 * 自家檔案時直接 echo；不要拿去 esc_html()，那會把標籤印成字面文字。
	 *
	 * @param string $slug 圖示檔名（不含 .svg）。
	 * @return string SVG 標記；找不到或 slug 不合法時回傳空字串。
	 */
	public static function icon( $slug ) {
		static $cache = array();

		if ( isset( $cache[ $slug ] ) ) {
			return $cache[ $slug ];
		}

		if ( ! preg_match( '/^[a-z0-9-]+$/', (string) $slug ) ) {
			return '';
		}

		$path = UAPPT_PLUGIN_DIR . 'assets/icons/' . $slug . '.svg';
		$svg  = file_exists( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions

		$cache[ $slug ] = $svg;

		return $svg;
	}

	/**
	 * 輸出面板的標題列：圖示徽章 ＋ 標題 ＋（可選）靠右的動作按鈕。
	 *
	 * 這是「看起來像終極電商」最關鍵的一個元件（灰底橫條加圖示徽章），抽成
	 * 函式而不是讓每個 view 各抄一遍 markup，順序與跳脫才不會各寫各的。
	 *
	 * `$button` 刻意收成結構化陣列而不是 HTML 字串，由這裡負責 esc_url()／
	 * esc_html()，呼叫端沒有寫錯跳脫的機會——這個寫法是照抄 twshop 的
	 * `twshop_panel_head()`，那支已經驗證過好用。
	 *
	 * @param string $icon   圖示 slug（見 assets/icons/）。空字串＝不顯示圖示。
	 * @param string $title  標題文字。
	 * @param array  $button 可選的動作按鈕：url、label、class（預設 'button'）。
	 */
	public static function panel_head( $icon, $title, array $button = array() ) {
		echo '<div class="uappt-panel-head">';

		if ( $icon ) {
			// SVG 來自外掛自己的檔案、不含使用者輸入，直接輸出；用 esc_html()
			// 會把標籤印成字面文字。
			echo '<span class="uappt-panel-head-icon">' . self::icon( $icon ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<h2>' . esc_html( $title ) . '</h2>';

		if ( ! empty( $button['url'] ) && ! empty( $button['label'] ) ) {
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( $button['url'] ),
				esc_attr( isset( $button['class'] ) ? $button['class'] : 'button' ),
				esc_html( $button['label'] )
			);
		}

		echo '</div>';
	}

	/**
	 * 可收合的卡片（開頭）。`card_close()` 收尾。
	 *
	 * ⚠️ **用原生的 `<details>`／`<summary>`，不自己做。** 原生元素免費拿到四件事：
	 * 沒有 JS 也能開合、鍵盤可達、螢幕閱讀器認得它是可展開的區塊、手機上輕觸區域
	 * 天生夠大。自己用 div ＋ JS 做會把這四件事全部變成要自己維護的東西，而且 JS
	 * 一壞就整頁打不開——那是比「版面不好看」嚴重得多的失效。
	 *
	 * **摘要（`summary`）不是裝飾**：卡片收起來之後它就是這張卡片的全部內容。一眼
	 * 看完這個人的設定，比永遠攤開、要捲三頁才看得完更快。
	 *
	 * @param array $args {
	 *     @type string $icon    圖示 slug（assets/icons/）。
	 *     @type string $title   卡片標題。
	 *     @type string $summary 收合時顯示的現況摘要（純文字）。
	 *     @type bool   $open    預設是否展開。
	 *     @type string $id      卡片的 HTML id（給錨點連結用）。
	 *     @type string $class   額外的 class。
	 * }
	 */
	public static function card_open( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'icon'    => '',
				'title'   => '',
				'summary' => '',
				'open'    => false,
				'id'      => '',
				'class'   => '',
			)
		);

		printf(
			'<details class="uappt-card %1$s"%2$s%3$s>',
			esc_attr( $args['class'] ),
			$args['id'] ? ' id="' . esc_attr( $args['id'] ) . '"' : '',
			$args['open'] ? ' open' : ''
		);

		echo '<summary class="uappt-card-head">';

		if ( $args['icon'] ) {
			// SVG 來自外掛自己的檔案、不含使用者輸入（比照 panel_head()）。
			echo '<span class="uappt-card-icon">' . self::icon( $args['icon'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<span class="uappt-card-title">' . esc_html( $args['title'] ) . '</span>';

		if ( '' !== $args['summary'] ) {
			echo '<span class="uappt-card-summary">' . esc_html( $args['summary'] ) . '</span>';
		}

		// 這張卡片有改到還沒存的東西時由 JS 打開。收起來不會把改到一半的東西藏掉
		// ——那正是「一張卡片一顆鈕」最容易出事的地方。
		echo '<span class="uappt-card-dirty" hidden>' . esc_html__( '未儲存', 'ultimate-appointments' ) . '</span>';

		echo '</summary>';
		echo '<div class="uappt-card-body">';
	}

	/**
	 * 可收合卡片的收尾。
	 */
	public static function card_close() {
		echo '</div></details>';
	}

	/**
	 * 把幾段文字組成卡片標題列的摘要。
	 *
	 * 空的段落自動略過——摘要是給人掃一眼的，印出「・・」只會讓人去找那裡少了什麼。
	 *
	 * @param array $parts 片段。
	 * @return string
	 */
	public static function card_summary( array $parts ) {
		$parts = array_filter(
			array_map( 'trim', $parts ),
			static function ( $part ) {
				return '' !== $part;
			}
		);
		return implode( '　・　', $parts );
	}

	/**
	 * 存檔後要顯示哪一則通知。
	 *
	 * 通知系統一次只認一個字串，但兩組欄位都可能出錯。同時出錯時給一則合併的
	 * 訊息，而不是「先報第一件、修好存檔後才看到第二件」——那會讓人以為已經
	 * 都修好了。
	 *
	 * @param array $segment_errors 時段分類的清洗錯誤。
	 * @param array $payment_errors 收款方式的清洗錯誤。
	 * @return string 通知代碼。
	 */
	protected static function settings_saved_notice( array $segment_errors, array $payment_errors ) {
		// 兩組以上同時出錯就給一則**不點名**的合併訊息。來源從兩個變三個之後，
		// 「時段分類與收款方式都有填錯」這種點名的寫法就會漏講第三個——而通知
		// 系統一次只認一個字串，列舉不完。不點名的版本仍然說得出「有幾格被還原、
		// 要去哪幾個區塊看」，比漏講一個誠實。
		$broken = 0;
		// 班別原本是第三個來源，v2.98.0 搬到人員管理 ▸ 班別設定，有自己的通知了。
		foreach ( array( $segment_errors, $payment_errors ) as $errors ) {
			if ( $errors ) {
				$broken++;
			}
		}

		if ( $broken > 1 ) {
			return 'settings_saved_field_errors';
		}
		if ( $segment_errors ) {
			return 'settings_saved_segment_error';
		}
		if ( $payment_errors ) {
			return 'settings_saved_payment_error';
		}
		return 'settings_saved';
	}

	/**
	 * 設定頁的頁籤定義（識別值 => 顯示標籤）。
	 *
	 * 定義在這裡而不是直接寫在 view 裡，是因為有三個地方要用到同一份清單：
	 * 畫頁籤列、儲存後轉址回原本那一頁、以及把網址上的 ?tab= 比對白名單。
	 * 分散成三份遲早會漏改其中一邊（改了標籤卻沒改白名單，結果切過去被踢回
	 * 第一頁，還很難看出是哪裡的問題）。
	 *
	 * @return array
	 */
	public static function settings_tabs() {
		$tabs = array(
			'rules'   => __( '預約規則', 'ultimate-appointments' ),
			// 收款方式與手續費費率自成一頁：它既不是預約規則也不是前台顯示，
			// 而且往後還會長（分期、各家金流各自的費率），塞進別頁只會讓那一頁
			// 變成雜物間。
			'payment' => __( '收款設定', 'ultimate-appointments' ),
			'display' => __( '前台顯示', 'ultimate-appointments' ),
			'notify'  => __( '通知提醒', 'ultimate-appointments' ),
			'tools'   => __( '進階工具', 'ultimate-appointments' ),
		);

		// 「功能模組」只對 manage_options 顯示——那是**網站管理員**（我們），
		// 不是拿 manage_woocommerce 的客戶。這一列不進清單就等於那一頁不存在：
		// sanitize_settings_tab() 拿這份清單當白名單，`?tab=modules` 會被退回
		// 第一個頁籤，handle_save_modules() 自己再擋一次存檔（見 D2）。
		if ( current_user_can( 'manage_options' ) ) {
			$tabs['modules'] = __( '功能模組', 'ultimate-appointments' );
		}

		return $tabs;
	}

	/**
	 * 儲存功能模組開關，或套用某一個方案層級。
	 *
	 * ⚠️ **這裡擋的是 `manage_options`，不是外掛其餘頁面用的
	 * `uappt_manage_bookings`。** 整套方案分級的執行機制就是這一個 capability
	 * 的落差：維護方是 Administrator，客戶拿 `shop_manager`
	 * （`manage_woocommerce`，沒有 `manage_options`），所以客戶看不到也存不了
	 * 這一頁。前提是**客戶的帳號不能是 Administrator**
	 * ——`UAPPT_Caps::ensure_admin_has_manage_bookings()` 那條保險絲會讓任何有
	 * `manage_options` 的人自動拿回外掛的管理權，那是部署時的規則，程式防不了。
	 *
	 * 這一頁不走 `options.php`，是自己的 `<form>` ＋ `$_POST`，所以沒有
	 * WordPress 核心那層保護，畫面與存檔必須各擋一次。
	 */
	public function handle_save_modules() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_save_modules' );

		// v2.46.0 起「快速套用某個方案」是純前端的（assets/js/admin-modules.js
		// 只把開關撥好、不送出），所以這裡只剩一條路徑：收下勾起來的那些。
		// 少一個分支，也少一種「按了方案就立刻存檔」的意外。
		$before = UAPPT_Modules::enabled( 'staff_portal' );

		$raw     = isset( $_POST['modules'] ) && is_array( $_POST['modules'] ) ? wp_unslash( $_POST['modules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$enabled = array_map( 'sanitize_key', array_keys( $raw ) );
		UAPPT_Modules::save( $enabled );

		// ⚠️ 員工中心一開一關，前台的兩個 rewrite 端點就跟著出現／消失，
		// 規則沒有重寫的話新開的員工中心會 404。
		//
		// 用既有的「排程」機制而不是直接 flush：端點是在 `init` 上註冊的，
		// 而這裡是 admin-post，這個請求裡 register_endpoint() 根本沒跑過
		// （模組剛才還是關的）——當場 flush 只會把「沒有端點」的規則寫死。
		// 排下去，讓下一個請求在 init 註冊完之後（優先權 20）才真的寫。
		if ( $before !== UAPPT_Modules::enabled( 'staff_portal' ) ) {
			update_option( 'uappt_flush_rewrite_rules', 1 );
		}

		$this->redirect(
			'uappt-settings',
			array(
				'tab'         => 'modules',
				'uappt_notice' => 'modules_saved',
			)
		);
	}

	/**
	 * 把外部傳進來的頁籤識別值比對白名單，不在清單裡就退回第一個頁籤。
	 *
	 * 網址參數與表單欄位都會經過這裡，值最後會被印回 HTML 屬性，不能直接信任。
	 *
	 * 先擋非純量：`?tab[]=x` 送進來的是陣列，直接 (string) 轉型會噴
	 * 「Array to string conversion」警告（WP 自己在 admin-post.php 也是先
	 * is_scalar() 才用）。
	 *
	 * @param mixed $tab 未經驗證的頁籤識別值。
	 * @return string
	 */
	public static function sanitize_settings_tab( $tab ) {
		$tabs = self::settings_tabs();
		$tab  = is_scalar( $tab ) ? sanitize_key( (string) $tab ) : '';

		return isset( $tabs[ $tab ] ) ? $tab : 'rules';
	}

	/**
	 * 輸出排班申請的「核准／駁回」一組表單。
	 *
	 * 批次層（一次處理整批）與逐日層（只處理某一天）本來各自把這組 markup 抄
	 * 了一遍——兩邊的 action、nonce、識別欄位名稱都不同，抄過去再逐一改，正是
	 * 最容易改漏一個地方的寫法（改漏 nonce 就會變成永遠審核失敗，而且看不出
	 * 原因）。收成一支之後，兩層只差在傳進來的參數。
	 *
	 * 跟 panel_head() 一樣，識別值與標籤都由這裡負責跳脫，呼叫端沒有寫錯的機會。
	 *
	 * @param array $args {
	 *     @type string $approve_action   核准用的 admin-post action。
	 *     @type string $reject_action    駁回用的 admin-post action。
	 *     @type string $id_field         識別欄位名稱（batch_key 或 request_id）。
	 *     @type string $id_value         識別值。
	 *     @type string $nonce_action     nonce 的 action 字串。
	 *     @type bool   $with_note        是否顯示審核備註輸入框（批次層才有）。
	 *     @type string $approve_label    核准鈕文字。
	 *     @type string $reject_label     駁回鈕文字。
	 *     @type bool   $approve_disabled 核准鈕是否停用（有硬衝突時）。
	 *     @type string $return_to        按完要回哪個 section（留空＝回排班申請頁）。
	 * }
	 */
	public static function shift_review_forms( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'approve_action'   => '',
				'reject_action'    => '',
				'id_field'         => '',
				'id_value'         => '',
				'nonce_action'     => '',
				'with_note'        => false,
				'approve_label'    => __( '核准', 'ultimate-appointments' ),
				'reject_label'     => __( '駁回', 'ultimate-appointments' ),
				'approve_disabled' => false,
				'return_to'        => '',
			)
		);

		$pairs = array(
			array( $args['approve_action'], $args['approve_label'], 'button button-primary', $args['approve_disabled'], __( '審核備註（可留空）', 'ultimate-appointments' ) ),
			array( $args['reject_action'], $args['reject_label'], 'button', false, __( '駁回原因（建議填寫）', 'ultimate-appointments' ) ),
		);

		foreach ( $pairs as $pair ) {
			list( $action, $label, $class, $is_disabled, $placeholder ) = $pair;
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uappt-shift-review-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
				<input type="hidden" name="<?php echo esc_attr( $args['id_field'] ); ?>" value="<?php echo esc_attr( $args['id_value'] ); ?>" />
				<?php wp_nonce_field( $args['nonce_action'] ); ?>
				<?php if ( '' !== $args['return_to'] ) : ?>
					<?php self::return_field( $args['return_to'] ); ?>
				<?php endif; ?>
				<?php if ( $args['with_note'] ) : ?>
					<input type="text" name="review_note" class="regular-text" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
				<?php endif; ?>
				<button type="submit" class="<?php echo esc_attr( $class ); ?>" <?php disabled( $is_disabled ); ?>>
					<?php echo esc_html( $label ); ?>
				</button>
			</form>
			<?php
		}
	}

	/* ---------------------------------------------------------------------
	 * 人力資源批次匯入（人員主檔／逐日班表）
	 * ------------------------------------------------------------------- */

	/**
	 * 上傳 CSV 並產生預覽。**這一步完全不寫入任何資料。**
	 *
	 * 匯入是少數一次動到上百列的操作，沒有預覽的話一個表頭打錯就可能把整批
	 * 人的班表洗掉，而且事後很難看出是哪裡出錯。預覽把「每一列會發生什麼」
	 * 攤開來，確認之後才由 handle_import_apply() 真的寫入。
	 */
	public function handle_import_upload() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_import_upload' );

		$type = isset( $_POST['import_type'] ) ? sanitize_key( wp_unslash( $_POST['import_type'] ) ) : '';
		if ( ! in_array( $type, array( UAPPT_Import::TYPE_STAFF, UAPPT_Import::TYPE_OVERRIDES ), true ) ) {
			$this->redirect_with_error( 'uappt-staff', __( '未知的匯入類型。', 'ultimate-appointments' ), array( 'action' => 'import' ) );
		}

		if ( empty( $_FILES['uappt_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['uappt_csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$this->redirect_with_error( 'uappt-staff', __( '請選擇一個 CSV 檔案。', 'ultimate-appointments' ), array( 'action' => 'import' ) );
		}

		if ( ! empty( $_FILES['uappt_csv']['error'] ) ) {
			$this->redirect_with_error(
				'uappt-staff',
				__( '檔案上傳失敗（可能超過伺服器允許的大小）。', 'ultimate-appointments' ),
				array( 'action' => 'import' )
			);
		}

		// 只認副檔名，不驗 MIME：瀏覽器對 CSV 回報的 MIME 五花八門
		// （text/csv、application/vnd.ms-excel、text/plain 都有），拿它當關卡
		// 只會擋掉正常使用者。真正的安全性不是靠這裡——檔案從頭到尾只被
		// fgetcsv() 當文字讀，而且**不搬進 uploads 目錄**，站台上不會留下任何
		// 使用者上傳的檔案。
		$ext = strtolower( pathinfo( sanitize_file_name( wp_unslash( $_FILES['uappt_csv']['name'] ) ), PATHINFO_EXTENSION ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			$this->redirect_with_error( 'uappt-staff', __( '請上傳 .csv 檔案（Excel 請用「另存新檔 → CSV UTF-8」）。', 'ultimate-appointments' ), array( 'action' => 'import' ) );
		}

		$error  = '';
		$parsed = UAPPT_Import::read_csv( $type, $_FILES['uappt_csv']['tmp_name'], $error ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $parsed ) {
			$this->redirect_with_error( 'uappt-staff', $error, array( 'action' => 'import' ) );
		}

		set_transient(
			UAPPT_Import::preview_key(),
			array(
				'type'  => $type,
				'plans' => UAPPT_Import::plan( $type, $parsed ),
			),
			UAPPT_Import::PREVIEW_TTL
		);

		$this->redirect( 'uappt-staff', array( 'action' => 'import' ) );
	}

	/**
	 * 套用預覽過的計畫。
	 *
	 * 刻意**只吃 transient 裡的計畫**，不重新解析一次表單送過來的內容——
	 * 「畫面上看到的」跟「真正寫進去的」必須是同一份資料，不然預覽就失去意義。
	 * transient 過期（超過 PREVIEW_TTL）就要求重新上傳，不用舊資料硬套。
	 */
	public function handle_import_apply() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_import_apply' );

		$preview = get_transient( UAPPT_Import::preview_key() );
		if ( ! is_array( $preview ) || empty( $preview['plans'] ) ) {
			$this->redirect_with_error(
				'uappt-staff',
				__( '預覽內容已經過期，請重新上傳檔案。', 'ultimate-appointments' ),
				array( 'action' => 'import' )
			);
		}

		$result = UAPPT_Import::apply( $preview['type'], $preview['plans'] );
		delete_transient( UAPPT_Import::preview_key() );

		$this->redirect(
			'uappt-staff',
			array(
				'uappt_notice'  => 'import_done',
				'uappt_count'   => $result['ok'],
				'uappt_skipped' => $result['skipped'],
				'uappt_failed'  => count( $result['failed'] ),
			)
		);
	}

	/**
	 * 匯出目前的資料當成匯入範本。
	 *
	 * 「匯出 → 在 Excel 改 → 匯回來」是這個功能真正的使用方式：沒有人想自己
	 * 手刻表頭，而且人員主檔的更新一定要帶正確的「編號」才不會變成新增一位
	 * 同名人員。欄位定義跟匯入共用 UAPPT_Import::columns()，兩邊不會走鐘。
	 */
	public function handle_import_template() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_import_template' );

		$type = isset( $_REQUEST['import_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['import_type'] ) ) : UAPPT_Import::TYPE_STAFF;
		$type = ( UAPPT_Import::TYPE_OVERRIDES === $type ) ? UAPPT_Import::TYPE_OVERRIDES : UAPPT_Import::TYPE_STAFF;

		$columns = UAPPT_Import::columns( $type );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="uappt-' . $type . '-' . current_time( 'Ymd' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		// UTF-8 BOM：Excel 開啟含中文的 CSV 沒有這個會亂碼，跟報表匯出同一個
		// 處理方式（read_csv() 那邊也記得把它吃掉，檔案才能原封不動匯回來）。
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array_values( $columns ) );

		if ( UAPPT_Import::TYPE_STAFF === $type ) {
			foreach ( UAPPT_Staff::get_all() as $staff ) {
				$linked_user = $staff['user_id'] ? get_user_by( 'id', $staff['user_id'] ) : null;
				$row         = array(
					$staff['id'],
					$staff['name'],
					'inactive' === $staff['status'] ? __( '停用', 'ultimate-appointments' ) : __( '啟用', 'ultimate-appointments' ),
					! empty( $staff['is_24h'] ) ? 1 : 0,
					$staff['capacity'],
					$staff['slot_interval'],
					$staff['price_adjustment'],
					$staff['sort_order'],
					$linked_user ? $linked_user->user_email : '',
				);
				foreach ( array_keys( UAPPT_Import::WEEKDAY_KEYS ) as $day ) {
					$ranges = isset( $staff['business_hours'][ $day ] ) ? $staff['business_hours'][ $day ] : array();
					$row[]  = implode(
						'|',
						array_map(
							static function ( $range ) {
								return $range[0] . '-' . $range[1];
							},
							$ranges
						)
					);
				}
				fputcsv( $out, $row );
			}
		} else {
			// 逐日調整只匯出「今天以後」的：過去的班表改了也沒有意義，全部倒
			// 出來只會讓檔案長到沒辦法在 Excel 裡找到要改的那幾列。
			$today = current_time( 'Y-m-d' );
			foreach ( UAPPT_Staff::get_all() as $staff ) {
				foreach ( UAPPT_Staff::get_overrides_in_range( $staff['id'], $today, uappt_local_date( $today, '+1 year' ) ) as $override ) {
					// override 的 hours 是資料庫原始的 JSON 字串（get_override()
					// 系列刻意不做 hydrate），跟 staff-edit.php 一樣自己解一次。
					$ranges = ! empty( $override['hours'] ) ? json_decode( $override['hours'], true ) : array();
					$ranges = is_array( $ranges ) ? $ranges : array();
					fputcsv(
						$out,
						array(
							$staff['id'],
							$staff['name'],
							$override['override_date'],
							// ⚠️ 這幾個字要跟 UAPPT_Import::parse_override_type() 認得的
							// 關鍵字一致，否則「匯出 → 改一改 → 匯入」這條路會在類型欄
							// 整批報錯。例休與請假都在那支的清單裡。
							! empty( $override['is_closed'] )
								? UAPPT_Staff::closed_reason_label( isset( $override['closed_reason'] ) ? $override['closed_reason'] : '' )
								: __( '自訂時段', 'ultimate-appointments' ),
							implode(
								'|',
								array_map(
									static function ( $range ) {
										return $range[0] . '-' . $range[1];
									},
									$ranges
								)
							),
							isset( $override['note'] ) ? $override['note'] : '',
						)
					);
				}
			}
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * 儲存「這個月的班」——月曆上塗過的日子，一次寫入。
	 *
	 * 取代 v2.84.0 以前的「批次排班」（勾選日期 ＋ 下面填一組設定 ＋ 送出）。
	 * 那張表單一次只能套**一種**設定到一批日子，所以「早班三天、晚班兩天、週日休」
	 * 要送出三次；塗抹是一次送出整個月，每天各自是什麼。
	 *
	 * ⚠️ **只處理送上來的日子，不處理整個月。** 月曆上每一個可塗的日子都印了
	 * hidden input，但**預設是 disabled**（見 month-grid.php），JS 塗到哪一天才
	 * enable 哪一天。所以 `$_POST['days']` 裡本來就只有改過的日子：
	 *
	 * - 沒碰過的日子不會被重寫 → 核准排班申請留下的 `source` / `request_id`
	 *   憑證不會被洗成「主管手動」，班表上「這天為什麼不一樣」仍然追得回去。
	 * - 沒有 JS 時所有欄位都是 disabled，按下儲存就是「沒有任何變更」，而不是
	 *   把整個月原封不動重寫一次。
	 *
	 * ⚠️ **塗抹會沿用那一天原本的備註。** 畫筆講的是時間，不是原因；不帶回去的話
	 * `save_override()` 會把 note 寫成空字串，等於刷一次班表就把所有備註清光——
	 * 而且不會有任何地方報錯。要改備註走下面的「改這一天」。
	 *
	 * 寫入仍然是 admin-post ＋ nonce ＋ capability（設計紀律 #8）。塗抹只動畫面，
	 * 所以那段 JS 壞掉的最差情況是「要用單日表單一天一天改」，不會變成存不進去。
	 */
	public function handle_save_staff_month() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_save_staff_month' );

		$staff_id = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;
		$month    = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
		$back     = array( 'action' => 'edit', 'staff_id' => $staff_id );
		if ( preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$back['month'] = $month;
		}

		$posted = isset( $_POST['days'] ) && is_array( $_POST['days'] ) ? wp_unslash( $_POST['days'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// 排班時「存成班別」的那幾個（v2.100.0）。先存班別再寫格子，通知才講得出兩件事。
		$new_presets = $this->save_new_presets_from_post();
		if ( $new_presets ) {
			$back['uappt_new_presets'] = $new_presets;
		}

		if ( empty( $posted ) ) {
			// 沒有任何改動就不要當成錯誤——按了儲存卻什麼都沒塗是很自然的事
			// （也可能是 JS 沒載入）。講清楚「沒有變更」比丟一個紅色錯誤誠實。
			$this->redirect( 'uappt-staff', array_merge( $back, array( 'uappt_notice' => 'staff_month_unchanged' ) ) );
		}

		// 上限沿用排班申請那一套。一個月最多 31 天本來就吃不到，留著是防著
		// 「有人自己組一包 POST 進來」——衝突檢查是逐日跑的，無上限會被拖垮。
		if ( count( $posted ) > UAPPT_Shift_Request::MAX_BATCH_DAYS ) {
			$this->redirect_with_error(
				'uappt-staff',
				sprintf(
					/* translators: %d: 上限天數 */
					__( '一次最多只能儲存 %d 天，請分批處理。', 'ultimate-appointments' ),
					UAPPT_Shift_Request::MAX_BATCH_DAYS
				),
				$back
			);
		}

		$done      = 0;
		$conflicts = array();

		foreach ( $posted as $raw_date => $row ) {
			$result = $this->save_painted_day( $staff_id, $month, $raw_date, $row );

			if ( is_wp_error( $result ) ) {
				// 一天寫失敗就整批停下來並回報是哪一天——繼續寫下去的話，管理者會
				// 以為整批都成功了，而實際上班表少了一天。
				$this->redirect_with_error(
					'uappt-staff',
					sprintf(
						/* translators: 1: 錯誤訊息 2: 已完成天數 */
						__( '%1$s 在這之前已經存了 %2$d 天。', 'ultimate-appointments' ),
						$result->get_error_message(),
						$done
					),
					$back
				);
			}

			if ( $result['hard'] > 0 ) {
				$conflicts[ $result['date'] ] = $result['hard'];
			}
			if ( $result['written'] ) {
				$done++;
			}
		}

		$this->redirect(
			'uappt-staff',
			array_merge(
				$back,
				array(
					// 一天都沒動到也走「沒有變更」，不要說「已儲存 0 天的班」。
					// 真的會發生：塗了「清除」在本來就沒有調整的日子上，或是送上來
					// 的日期全部被上面那幾道檢查擋掉。
					'uappt_notice' => $done > 0 ? 'staff_month_saved' : 'staff_month_unchanged',
					'uappt_count'  => $done,
				),
				$this->conflict_redirect_args( $conflicts )
			)
		);
	}

	/**
	 * 排班時順便「存成班別」的那幾個（v2.100.0，見 partials/shift-custom.php）。
	 *
	 * 月曆與月排班表的儲存都先呼叫這支，再寫格子。新的班別**接在既有班別後面**、整份
	 * 交給 UAPPT_Shift_Preset::save()——清洗、上限（MAX_PRESETS）、配色都是同一支，
	 * 不另寫一份。
	 *
	 * 同名的班別不收（畫面那一側已經擋過，這裡防的是兩個分頁各自存了同一個名字）：
	 * 不然班別列上會出現兩顆一樣的「中班」，點了不知道是哪一個。
	 *
	 * @return int 實際新增的班別數。
	 */
	protected function save_new_presets_from_post() {
		$raw = isset( $_POST['new_presets'] ) && is_array( $_POST['new_presets'] ) ? wp_unslash( $_POST['new_presets'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- 呼叫端已驗 nonce；欄位交給 UAPPT_Shift_Preset::sanitize()。
		if ( empty( $raw ) ) {
			return 0;
		}

		$existing = UAPPT_Shift_Preset::all();
		$names    = array();
		$rows     = array();
		foreach ( $existing as $preset ) {
			$names[ $preset['name'] ] = true;
			// 存出去的形狀（ranges）sanitize() 本來就看得懂，原樣交回去。
			$rows[] = $preset;
		}

		$added = 0;
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = isset( $row['name'] ) ? trim( sanitize_text_field( (string) $row['name'] ) ) : '';
			if ( '' === $name || isset( $names[ $name ] ) ) {
				continue;
			}
			$rows[] = array(
				'name'  => $name,
				'hours' => array(
					array(
						'start' => isset( $row['start'] ) ? (string) $row['start'] : '',
						'end'   => isset( $row['end'] ) ? (string) $row['end'] : '',
					),
				),
				'color' => isset( $row['color'] ) ? (string) $row['color'] : '',
			);
			$names[ $name ] = true;
			$added++;
		}

		if ( ! $added ) {
			return 0;
		}

		$saved = UAPPT_Shift_Preset::save( $rows );

		// 實際新增幾個以存完的結果為準：時段不合法或超過上限的那幾個會被 sanitize() 丟掉。
		return max( 0, count( $saved ) - count( $existing ) );
	}

	/**
	 * 寫入月曆上排好的**一格**（一位人員的一天）。
	 *
	 * 「這個月的班」（`handle_save_staff_month()`）與全店月排班表
	 * （`handle_save_roster()`）**共用這一支**，v2.94.0 從前者抽出來。日期範圍、類型
	 * 白名單、保留備註、衝突統計都只有這一份——全店表要是另寫一份，兩邊遲早一邊
	 * 擋得住自己組的 POST、另一邊擋不住。
	 *
	 * @param int    $staff_id 人員 ID。
	 * @param string $month    送出的那個月 (Y-m)。
	 * @param mixed  $raw_date 日期 (Y-m-d)，未清洗。
	 * @param mixed  $row      `{type, ranges}`，未清洗。
	 * @return array|WP_Error `{date, written, hard}`；`written` 為假＝這格被略過或
	 *                        本來就是那樣。真的寫不進去才回 WP_Error（呼叫端要停）。
	 */
	protected function save_painted_day( $staff_id, $month, $raw_date, $row ) {
		$date    = sanitize_text_field( (string) $raw_date );
		$skipped = array(
			'date'    => $date,
			'written' => false,
			'hard'    => 0,
		);

		// ⚠️ 日期**必須落在送出的那個月**。只檢查格式的話，自己組一包 POST
		// 就能一次改掉任意日期——而畫面上完全看不出來改了別的月份。
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || substr( $date, 0, 7 ) !== $month ) {
			return $skipped;
		}

		if ( ! is_array( $row ) ) {
			return $skipped;
		}

		$type = isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : '';
		if ( ! in_array( $type, array( 'hours', UAPPT_Staff::CLOSED_OFF, UAPPT_Staff::CLOSED_LEAVE, 'clear' ), true ) ) {
			return $skipped;
		}

		$existing = UAPPT_Staff::get_override( $staff_id, $date );

		if ( 'clear' === $type ) {
			// 清除＝把這天交還給「未排班」。本來就沒有逐日調整的日子不算失敗，
			// 也不算改過一天（排了又排回來不該報「已更新 1 天」）。
			if ( ! $existing ) {
				return $skipped;
			}
			UAPPT_Staff::delete_override( $existing['id'] );
			$skipped['written'] = true;
			return $skipped;
		}

		$is_closed = in_array( $type, array( UAPPT_Staff::CLOSED_OFF, UAPPT_Staff::CLOSED_LEAVE ), true );
		$ranges    = array();

		if ( ! $is_closed ) {
			$ranges = $this->parse_painted_ranges( isset( $row['ranges'] ) ? $row['ranges'] : '' );
			if ( empty( $ranges ) ) {
				return new WP_Error(
					'uappt_painted_empty',
					sprintf(
						/* translators: %s: 日期 */
						__( '%s 這天排的是上班班別，卻沒有任何時段。請重新選一次班別再儲存。', 'ultimate-appointments' ),
						$date
					)
				);
			}
		}

		$result = UAPPT_Staff::save_override(
			array(
				'staff_id'      => $staff_id,
				'override_date' => $date,
				'is_closed'     => $is_closed,
				'closed_reason' => $type,
				'hours'         => $ranges,
				// ⚠️ **原本的備註要帶回去。** 班別講的是時間，不是原因；不帶回去的話
				// save_override() 會把 note 寫成空字串，等於排一次班就把所有備註清光
				// ——而且不會有任何地方報錯。要改備註走「改這一天」。
				'note'          => $existing && isset( $existing['note'] ) ? $existing['note'] : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				sprintf(
					/* translators: 1: 日期 2: 錯誤訊息 */
					__( '%1$s 這天沒有寫入成功：%2$s', 'ultimate-appointments' ),
					$date,
					$result->get_error_message()
				)
			);
		}

		return array(
			'date'    => $date,
			'written' => true,
			'hard'    => $this->override_conflict_count( $staff_id, $date, $is_closed, $ranges ),
		);
	}

	/**
	 * 把塗抹送上來的 ranges 欄位解回 [[開始,結束], ...]。
	 *
	 * 畫筆帶的是一整組時段，用 JSON 傳一個欄位比印成
	 * `days[日期][ranges][0][start]` 這種 186 個欄位的表單乾淨得多；而且 JS 只要
	 * `JSON.stringify(ranges)`，**不用在裡面複製一份欄位名稱的格式**（那種東西
	 * 一改表單結構就會悄悄失效，v2.80.0 為了同一個理由才把三個時段一律印進 DOM）。
	 *
	 * 代價是這一側要把 JSON 當成完全不可信的輸入來處理：解不開、不是陣列、元素
	 * 不成對一律丟掉，剩下的照樣過 `UAPPT_Staff::sanitize_ranges()`——跟每週班表、
	 * 單日調整、班別走同一支驗證，不另外寫第二個時間解析器。
	 *
	 * @param mixed $raw 表單欄位值。
	 * @return array 清洗後的時段；完全無效時回傳空陣列（呼叫端負責報錯）。
	 */
	protected function parse_painted_ranges( $raw ) {
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$pairs = array();
		foreach ( $decoded as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair[0], $pair[1] ) ) {
				continue;
			}
			$pairs[] = array(
				sanitize_text_field( (string) $pair[0] ),
				sanitize_text_field( (string) $pair[1] ),
			);
		}

		$errors = array();
		return UAPPT_Staff::sanitize_ranges( $pairs, __( '塗上去的班別', 'ultimate-appointments' ), $errors );
	}

	/**
	 * 這筆逐日調整存下去之後，有幾筆既有預約會落在新的營業時間外。
	 *
	 * **直接重用排班申請那套衝突偵測**（UAPPT_Shift_Request::find_conflicts()），
	 * 不另外寫一份：它已經把衝突分成 hard（held/confirmed 的客人預約）與 soft
	 * （時段佔用、已完成、已取消）兩級，而且本來就設計成「渲染時跑一次給人看」。
	 *
	 * ⚠️ **為什麼後台這條路只警告、不擋。** 員工從前台送排班申請時，hard 衝突
	 * 會直接擋住不能核准；但管理者在後台直接改班表是另一回事——師傅臨時出事，
	 * 班一定要改得動，擋死只會逼人去繞路。而且標休假**不會取消或釋放任何預約**
	 * （見 find_conflicts() 的說明），改回來就復原，不是破壞性操作。
	 *
	 * 真正要修的是「完全靜默」：v2.78.0 以前後台這兩條路徑對既有預約**毫無
	 * 檢查也毫無提示**，管理者可以把有 3 筆預約的日子標成休假而不知情。
	 *
	 * ⚠️ **`hours` 要傳 JSON 字串，不是陣列。** find_conflicts() 會把它原樣
	 * 塞進 get_business_windows() 的 dry-run override，而那邊是
	 * `json_decode( $override['hours'] )`（class-uappt-staff.php:418）。傳陣列
	 * 會丟 TypeError（PHP 8：json_decode() 的第一個參數必須是字串），在
	 * admin_post handler 裡就是一片白畫面。實測確認過，不是推測。
	 *
	 * @param int    $staff_id  人員 ID。
	 * @param string $date      日期 (Y-m-d)。
	 * @param bool   $is_closed 是否整天休假。
	 * @param array  $ranges    自訂時段（$is_closed 為 false 時才有意義）。
	 * @return int 會落在新時段外的 held/confirmed 客人預約筆數。
	 */
	protected function override_conflict_count( $staff_id, $date, $is_closed, array $ranges = array() ) {
		if ( ! class_exists( 'UAPPT_Shift_Request' ) ) {
			return 0;
		}

		$conflicts = UAPPT_Shift_Request::find_conflicts(
			array(
				'staff_id'     => (int) $staff_id,
				'request_date' => $date,
				'type'         => $is_closed ? UAPPT_Shift_Request::TYPE_LEAVE : UAPPT_Shift_Request::TYPE_HOURS,
				// 見上方說明：一定要 JSON 字串。
				'hours'        => $is_closed ? null : wp_json_encode( $ranges ),
			)
		);

		return isset( $conflicts['hard'] ) ? count( $conflicts['hard'] ) : 0;
	}

	/**
	 * 把衝突日期收進 redirect 參數。
	 *
	 * 日期清單上限 8 筆：批次一次最多 92 天，全部塞進網址會變成一公里長的
	 * query string。總數另外用 uappt_conflict_total 帶，畫面上講「等 N 天」。
	 *
	 * @param array $dates date => 衝突筆數。
	 * @return array
	 */
	protected function conflict_redirect_args( array $dates ) {
		if ( ! $dates ) {
			return array();
		}

		ksort( $dates );

		return array(
			'uappt_conflict_dates' => implode( ',', array_slice( array_keys( $dates ), 0, 8 ) ),
			'uappt_conflict_days'  => count( $dates ),
			'uappt_conflict_total' => array_sum( $dates ),
		);
	}

	/**
	 * 儲存請假設定（單日不開放預約）。
	 */
	public function handle_save_staff_override() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_save_staff_override' );

		$staff_id = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;
		$type     = isset( $_POST['override_type'] ) ? sanitize_key( wp_unslash( $_POST['override_type'] ) ) : '';
		// 既有判斷維持「不是 hours 就是關起來」的形狀——**沒送出 override_type 時
		// 預設是關**，這條退路不要動掉。拆成例休／請假之後再多認一次舊的 `closed`
		// （頁面開著沒重整就送出），理由同批次排班那支。
		$is_closed     = ( 'hours' !== $type );
		$closed_reason = UAPPT_Staff::sanitize_closed_reason( $type );
		$posted_hours  = array();

		if ( ! $is_closed && isset( $_POST['override_hours'] ) && is_array( $_POST['override_hours'] ) ) {
			foreach ( $_POST['override_hours'] as $range ) {
				$posted_hours[] = array(
					isset( $range['start'] ) ? sanitize_text_field( wp_unslash( $range['start'] ) ) : '',
					isset( $range['end'] ) ? sanitize_text_field( wp_unslash( $range['end'] ) ) : '',
				);
			}
		}

		$result = UAPPT_Staff::save_override(
			array(
				'staff_id'      => $staff_id,
				'override_date' => isset( $_POST['override_date'] ) ? sanitize_text_field( wp_unslash( $_POST['override_date'] ) ) : '',
				'is_closed'     => $is_closed,
				'closed_reason' => $closed_reason,
				'hours'         => $posted_hours,
				'note'          => isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error(
				'uappt-staff',
				$result->get_error_message(),
				array(
					'action'   => 'edit',
					'staff_id' => $staff_id,
				)
			);
		}

		// 存完才檢查：這動作不是破壞性的（標休假不會取消任何預約），所以不擋，
		// 只是不能再像 v2.78.0 以前那樣完全靜默。
		$override_date = isset( $_POST['override_date'] ) ? sanitize_text_field( wp_unslash( $_POST['override_date'] ) ) : '';
		$conflicts     = array();
		$hard          = $this->override_conflict_count( $staff_id, $override_date, $is_closed, $posted_hours );
		if ( $hard > 0 ) {
			$conflicts[ $override_date ] = $hard;
		}

		$this->redirect(
			'uappt-staff',
			array_merge(
				array(
					'action'      => 'edit',
					'staff_id'    => $staff_id,
					'uappt_notice' => 'staff_override_saved',
				),
				$this->conflict_redirect_args( $conflicts )
			)
		);
	}

	/**
	 * 刪除請假設定。
	 */
	public function handle_delete_staff_override() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_delete_staff_override' );

		$override_id = isset( $_POST['override_id'] ) ? absint( $_POST['override_id'] ) : 0;
		$staff_id    = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;

		if ( $override_id ) {
			UAPPT_Staff::delete_override( $override_id );
		}

		$this->redirect(
			'uappt-staff',
			array(
				'action'      => 'edit',
				'staff_id'    => $staff_id,
				'uappt_notice' => 'staff_override_deleted',
			)
		);
	}

	/**
	 * 建立一段時段佔用（教育訓練／休息／門市現場客人吃掉的人力）。
	 *
	 * 這是「人力不是只有網站訂單」的入口：現場消耗掉的人力記進同一本帳，
	 * 前台才不會把已經沒人的時段再賣一次。實際的鎖定在
	 * UAPPT_Booking::create_block()，走的是跟一般預約完全相同的交易樣式。
	 */
	public function handle_save_staff_block() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_save_staff_block' );

		$staff_id = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;

		$result = UAPPT_Booking::create_block(
			array(
				'staff_id'   => $staff_id,
				'date_ymd'   => isset( $_POST['block_date'] ) ? sanitize_text_field( wp_unslash( $_POST['block_date'] ) ) : '',
				'start_hm'   => isset( $_POST['block_start'] ) ? sanitize_text_field( wp_unslash( $_POST['block_start'] ) ) : '',
				'end_hm'     => isset( $_POST['block_end'] ) ? sanitize_text_field( wp_unslash( $_POST['block_end'] ) ) : '',
				// 空白＝佔滿（整個人不在），這是訓練/休息最常見的情況。
				'units'      => isset( $_POST['block_units'] ) ? (int) $_POST['block_units'] : 0,
				'note'       => isset( $_POST['block_note'] ) ? sanitize_text_field( wp_unslash( $_POST['block_note'] ) ) : '',
				'created_by' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error(
				'uappt-staff',
				$result->get_error_message(),
				array(
					'action'   => 'edit',
					'staff_id' => $staff_id,
				)
			);
		}

		$this->redirect(
			'uappt-staff',
			array(
				'action'      => 'edit',
				'staff_id'    => $staff_id,
				'uappt_notice' => 'staff_block_saved',
			)
		);
	}

	/**
	 * 刪除一段時段佔用，並把名額還回去。
	 *
	 * 走 UAPPT_Booking::release()，跟取消預約是同一條路徑——它會依紀錄自己存的
	 * occupied_units 歸還正確的名額數，不會還多或還少。
	 */
	public function handle_delete_staff_block() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_delete_staff_block' );

		$block_id = isset( $_POST['block_id'] ) ? absint( $_POST['block_id'] ) : 0;
		$staff_id = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;

		if ( $block_id ) {
			UAPPT_Booking::release( $block_id, UAPPT_Booking::STATUS_CANCELLED );
		}

		$this->redirect(
			'uappt-staff',
			array(
				'action'      => 'edit',
				'staff_id'    => $staff_id,
				'uappt_notice' => 'staff_block_deleted',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * 今日營運（後台首頁）
	 * ------------------------------------------------------------------- */

	/**
	 * 輸出「今日營運」儀表板：把預約列表、待分派、待付款、人力資源四個頁面
	 * 才看得到的資訊集中在一頁，回答管理者早上打開後台最想問的那句話——
	 * 「今天有誰、有誰請假、還有什麼事要處理」，不用自己拼四個頁面的結果。
	 */
	public function render_dashboard_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$today    = current_time( 'Y-m-d' );
		$now_ts   = time();

		// 班表排到哪一天為止。輪班制的店會忘記往下排，而前台不會報錯，只會
		// 安靜地沒有時段可選——這是這一頁唯一「不看就不會知道」的失效。
		// 固定班表的店永遠是 uncovered=0，提示不會出現。
		$coverage = UAPPT_Staff::coverage_tail();

		// 今天的客人預約（已確認／已完成／待付款，不含純購物車暫留與時段佔用），
		// 直接沿用「店家明日清單」用的同一支查詢方法，只是日期換成今天。
		$today_bookings = UAPPT_Booking::get_bookings_for_date( $today );

		$today_count      = count( $today_bookings );
		$next_booking     = null;
		$counts_by_staff  = array();
		foreach ( $today_bookings as $booking ) {
			$start_ts = self::local_ts( $booking['service_start'] );
			if ( null === $next_booking && $start_ts >= $now_ts ) {
				$next_booking = $booking;
			}
			$sid = ! empty( $booking['staff_id'] ) ? (int) $booking['staff_id'] : 0;
			if ( $sid ) {
				$counts_by_staff[ $sid ] = isset( $counts_by_staff[ $sid ] ) ? $counts_by_staff[ $sid ] + 1 : 1;
			}
		}

		// 待分派、待付款：故意不限制日期範圍，用 service_start 升冪排序——
		// 最急迫（最早，甚至可能已經過期還沒處理）的自然排在最前面，管理者
		// 一眼就知道該先處理哪一筆，不會被排序埋掉。清單只取前 5 筆，總數
		// 另外用 total 顯示，多出來的引導去預約列表用篩選條件查完整清單。
		$pending_result = UAPPT_Booking::query(
			array(
				'assignment_state' => UAPPT_Booking::ASSIGNMENT_PENDING,
				'order'            => 'ASC',
				'per_page'         => 5,
			)
		);
		$awaiting_result = UAPPT_Booking::query(
			array(
				'awaiting_payment' => true,
				'order'            => 'ASC',
				'per_page'         => 5,
			)
		);

		// 人力：今天上班／今天請假／今天沒排班（三者互斥），只看啟用中的人員——
		// 停用的人員本來就不會被排進任何預約，不需要出現在今天的班表總覽裡。
		$day_start_ts = self::local_ts( $today . ' 00:00:00' );
		$day_end_ts   = $day_start_ts + DAY_IN_SECONDS;

		$working_today = array();
		$on_leave      = array();
		$scheduled_off = array();
		$not_scheduled = array();

		foreach ( UAPPT_Staff::get_all( true ) as $staff ) {
			$override = UAPPT_Staff::get_override( $staff['id'], $today );
			if ( $override && ! empty( $override['is_closed'] ) ) {
				// 例休與請假分開（v2.84.0）。對店長來說這是兩件事：排休是早就
				// 知道的，請假是今天才少一個人。混成一袋的話「今天怎麼只剩兩個
				// 人」永遠要再點進人員頁才查得出原因。
				//
				// ⚠️ 例休**不算**「沒有排班」：那一列的存在代表這天排過了、結論
				// 是休。「沒有排班」留給真的沒有任何班表的人，它之後是護欄要報的
				// 警訊，混進例休會讓那個警訊天天都在叫。
				if ( UAPPT_Staff::CLOSED_OFF === ( isset( $override['closed_reason'] ) ? $override['closed_reason'] : '' ) ) {
					$scheduled_off[] = $staff;
				} else {
					$on_leave[] = $staff;
				}
				continue;
			}

			$open_ranges = self::day_open_minutes( $staff, $today, $day_start_ts, $day_end_ts );
			if ( empty( $open_ranges ) ) {
				$not_scheduled[] = $staff;
				continue;
			}

			$earliest_start = min( array_column( $open_ranges, 0 ) );
			$working_today[] = array(
				'staff'          => $staff,
				'start_minutes'  => $earliest_start,
				'booking_count'  => isset( $counts_by_staff[ (int) $staff['id'] ] ) ? $counts_by_staff[ (int) $staff['id'] ] : 0,
			);
		}
		usort(
			$working_today,
			function ( $a, $b ) {
				return $a['start_minutes'] <=> $b['start_minutes'];
			}
		);

		// 待審排班申請：只有真的有審核權限的人才看得到這張卡，資深員工被
		// 賦予 uappt_approve_shift_requests 但沒有 self::CAP 時（理論上可能，
		// User Role Editor 調整過的話）也走這頁，所以不能假設「進得了今日
		// 營運」就等於「審得了排班申請」。
		$can_approve_shift_requests = current_user_can( UAPPT_Caps::CAP_APPROVE_SHIFT_REQUESTS );
		$pending_shift_batches      = array();
		$pending_shift_batch_total  = 0;
		if ( $can_approve_shift_requests ) {
			// 徽章跟卡片數字都用「批次」算，不是原始列數——一個人排一整個月
			// 是 30 列但只有一件事要處理，用列數會讓數字失去「有幾件事要
			// 處理」的意義（見 get_pending_batch_count() 的說明）。
			$pending_shift_batch_total = UAPPT_Shift_Request::get_pending_batch_count();
			$pending_shift_batches     = array_slice(
				UAPPT_Shift_Request::query_batches(
					array(
						'status'            => UAPPT_Shift_Request::STATUS_PENDING,
						'order_by_date_asc' => true,
					)
				),
				0,
				5
			);
		}

		self::page_open( __( '今日營運', 'ultimate-appointments' ), self::manual_booking_action( $today ) );
		require UAPPT_PLUGIN_DIR . 'includes/views/dashboard.php';
		self::page_close();
	}

	/* ---------------------------------------------------------------------
	 * 預約列表頁
	 * ------------------------------------------------------------------- */

	/**
	 * 「預約」區塊的檢視切換：清單｜日｜月。
	 *
	 * ⚠️ `view` 一個參數同時表示「哪一種畫法」與「清單的期間」——
	 * `upcoming`／`past`／`all` 是清單，`day`／`month` 是日曆。看起來混用，
	 * 但這是刻意的：兩邊原本各自用 `view`，合併時若改成兩個參數，舊網址
	 * `?view=all` 會變成無效值、被默默退回預設，那種「連結沒壞但跑錯地方」
	 * 最難查。一個參數五個值，所有既有網址的意思完全不變。
	 *
	 * 「清單」那一格會保留目前的期間——已經在看「全部」時按清單不該跳回
	 * 「即將到來」。`staff_id` 是三種檢視唯一共用的篩選，切換時帶著走；
	 * 其餘參數（月份、日期、狀態…）各檢視自己的，切走就丟掉。
	 *
	 * @param string $view 目前的 view 值。
	 */
	protected function render_booking_view_switch( $view ) {
		$in_list  = ! in_array( $view, array( 'day', 'month' ), true );
		$staff_id = isset( $_GET['staff_id'] ) ? absint( $_GET['staff_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$shared   = $staff_id ? array( 'staff_id' => $staff_id ) : array();

		// 從日檢視切到月檢視時帶著正在看的那個月份過去——不然瀏覽到 11 月
		// 按「月」會跳回本月，等於白翻了。反向（月→日）刻意不帶日期：一整個月
		// 沒有哪一天比較「對」，預設今天才是最不會讓人意外的。
		$month_of_day = array();
		if ( 'day' === $view && isset( $_GET['date'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$date_ymd = sanitize_text_field( wp_unslash( $_GET['date'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_ymd ) ) {
				$month_of_day['month'] = substr( $date_ymd, 0, 7 );
			}
		}

		$modes = array(
			'list'  => array( __( '清單', 'ultimate-appointments' ), $in_list ? $view : 'upcoming', $in_list, array() ),
			'day'   => array( __( '日檢視', 'ultimate-appointments' ), 'day', 'day' === $view, array() ),
			'month' => array( __( '月檢視', 'ultimate-appointments' ), 'month', 'month' === $view, $month_of_day ),
		);

		// 豎線交給 CSS（理由見 render_tabs()）——這一排跟那一支是同一種元件，
		// 標記也必須一致，不然手機版只有這一排還留著豎線。
		echo '<ul class="subsubsub uappt-settings-tabs">';
		foreach ( $modes as $mode ) {
			printf(
				'<li><a href="%1$s"%2$s>%3$s</a></li>',
				esc_url( self::url( 'bookings', array_merge( array( 'view' => $mode[1] ), $shared, $mode[3] ) ) ),
				$mode[2] ? ' class="current" aria-current="page"' : '',
				esc_html( $mode[0] )
			);
		}
		echo '</ul><br class="clear" />';
	}

	/**
	 * 輸出預約列表頁。
	 */
	public function render_bookings_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$action     = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$booking_id = isset( $_GET['booking_id'] ) ? absint( $_GET['booking_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'edit' === $action && $booking_id ) {
			$booking = UAPPT_Booking::get( $booking_id );
			if ( $booking ) {
				self::page_open(
					/* translators: %d: 預約 ID */
					sprintf( __( '編輯預約 #%d', 'ultimate-appointments' ), (int) $booking['id'] ),
					self::back_action( 'uappt-bookings', __( '← 回預約列表', 'ultimate-appointments' ) )
				);
				require UAPPT_PLUGIN_DIR . 'includes/views/booking-edit.php';
				self::page_close();
				return;
			}
			// 找不到這筆（例如已被刪除），退回列表比較不會困惑。
		}

		$staff_list = UAPPT_Staff::get_all();

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'upcoming'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// 日／月檢視交給日曆那一套畫，它自己也讀 $_GET['view']（v2.38.0 合併）。
		if ( in_array( $view, array( 'day', 'month' ), true ) ) {
			$this->render_calendar_page();
			return;
		}

		if ( ! in_array( $view, array( 'upcoming', 'past', 'all' ), true ) ) {
			$view = 'upcoming';
		}

		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// 排序直接跟著檢視頁籤走，不受日期欄位是否已有值影響——否則「即將到來」
		// 頁籤第一次點進來是空白日期欄位、套用升冪排序，但欄位被預設值帶出今天
		// 的日期後，使用者只是按了「篩選」按鈕（沒改任何條件），日期欄位就變成
		// 「非空白」，若排序改用同一個空白判斷就會悄悄跳回降冪，體驗不一致。
		$order = ( 'upcoming' === $view ) ? 'ASC' : 'DESC';

		// 日期區間的預設值則只在使用者沒有自己手動指定時才套用；一旦使用者
		// 自己選了日期，以他選的為準（表單會用隱藏欄位保留目前的 view，讓
		// 排序仍照頁籤邏輯，但日期範圍完全交給使用者的輸入）。
		// 使用者自己填的日期要跟「檢視頁籤自動補上的預設值」分開記著：狀態快捷
		// 連結的筆數不能被頁籤的預設日期窗綁住。「未到」的預約必定在過去，若
		// 沿用「即將到來」補上的 date_from = 今天，那排數字會永遠是 0，正好是
		// 這個功能要解決的問題本身。
		$user_date_from = $date_from;
		$user_date_to   = $date_to;

		if ( '' === $date_from && '' === $date_to ) {
			if ( 'upcoming' === $view ) {
				$date_from = current_time( 'Y-m-d' );
			} elseif ( 'past' === $view ) {
				$date_to = uappt_local_date( current_time( 'Y-m-d' ), '-1 day' );
			}
		}

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$assignment_state = isset( $_GET['assignment_state'] ) ? sanitize_key( wp_unslash( $_GET['assignment_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( UAPPT_Booking::ASSIGNMENT_PENDING !== $assignment_state ) {
			$assignment_state = '';
		}

		$filters = array(
			'staff_id'         => isset( $_GET['staff_id'] ) ? absint( $_GET['staff_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'status'           => isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'assignment_state' => $assignment_state,
			'date_from'        => $date_from,
			'date_to'          => $date_to,
			'order'            => $order,
			'search'           => $search,
			'paged'            => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'per_page'         => 20,
		);

		$result = UAPPT_Booking::query( $filters );

		// 狀態快捷連結的筆數：套用人員／搜尋／「使用者自己填的」日期範圍，但
		// 不套用檢視頁籤自動補的日期窗（見上面 $user_date_from 的說明）。快捷
		// 連結一律導向 view=all，數字與點進去看到的清單因此對得起來。
		$status_counts = UAPPT_Booking::count_by_status(
			array_merge(
				$filters,
				array(
					'date_from' => $user_date_from,
					'date_to'   => $user_date_to,
				)
			)
		);

		self::page_open( __( '預約列表', 'ultimate-appointments' ), self::manual_booking_action() );
		$this->render_booking_view_switch( $view );
		require UAPPT_PLUGIN_DIR . 'includes/views/bookings.php';
		self::page_close();
	}

	/**
	 * 取消／釋放預約。
	 */
	public function handle_cancel_booking() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_cancel_booking' );

		// 預約列表現在用單一 <form> 包住整張表格做批次操作（見
		// handle_bulk_booking_action()），單筆「取消」不能再各自包一個
		// `<form>`——HTML 不允許表單巢狀，瀏覽器解析時會把內層表單的欄位直接
		// 併進外層表單，反而讓每一列都變成同一組欄位、按哪一列都送出外層的
		// 批次表單。改成帶 nonce 的連結（wp_nonce_url()），$_REQUEST 兩種
		// 請求方式都讀得到，admin-post.php 本來就不限制一定要 POST。
		$booking_id = isset( $_REQUEST['booking_id'] ) ? absint( $_REQUEST['booking_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $booking_id ) {
			UAPPT_Booking::release( $booking_id, UAPPT_Booking::STATUS_CANCELLED );
		}

		$this->redirect( 'uappt-bookings', array( 'uappt_notice' => 'booking_cancelled' ) );
	}

	/**
	 * 批次處理預約列表勾選的多筆預約：取消、標記完成、或標記未到。
	 *
	 * 刻意不另外驗證每一筆的狀態是否適用——UAPPT_Booking::release()、complete()、
	 * mark_no_show() 本來就是安全的（狀態不符合就直接回傳 false、不動資料），
	 * 這裡只要老實把「處理成功的筆數」跟「送出的筆數」分開統計，讓管理者
	 * 知道有沒有整批都套用成功即可。批次「重新分派人員」沒有做進來：換人
	 * 需要先挑一位新人員，不像這三個是單純的狀態切換，塞進同一個下拉選單
	 * 反而會讓操作介面變複雜，之後真的有需求再獨立做。
	 */
	public function handle_bulk_booking_action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_bulk_booking_action' );

		$bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$booking_ids = isset( $_POST['booking_ids'] ) && is_array( $_POST['booking_ids'] )
			? array_filter( array_map( 'absint', wp_unslash( $_POST['booking_ids'] ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: array();

		// 三個動作各自對應到既有的單筆方法，這裡只是批次呼叫，不重寫任何規則。
		$callbacks = array(
			'cancel'   => function ( $id ) {
				return UAPPT_Booking::release( $id, UAPPT_Booking::STATUS_CANCELLED );
			},
			'complete' => array( 'UAPPT_Booking', 'complete' ),
			'no_show'  => array( 'UAPPT_Booking', 'mark_no_show' ),
		);

		if ( empty( $booking_ids ) || ! isset( $callbacks[ $bulk_action ] ) ) {
			$this->redirect( 'uappt-bookings' );
		}

		$done = 0;
		foreach ( $booking_ids as $booking_id ) {
			if ( call_user_func( $callbacks[ $bulk_action ], $booking_id ) ) {
				++$done;
			}
		}

		$notice_map = array(
			'cancel'   => 'bulk_cancelled',
			'complete' => 'bulk_completed',
			'no_show'  => 'bulk_no_show',
		);

		$this->redirect(
			'uappt-bookings',
			array(
				'uappt_notice' => $notice_map[ $bulk_action ],
				'uappt_count'  => $done,
				'uappt_total'  => count( $booking_ids ),
			)
		);
	}

	/**
	 * 處理預約改期。改期成功後，若這筆預約掛在訂單上，同步更新訂單項目顯示的
	 * 時段 meta 並留下訂單備註，方便日後客訴查核與對帳。
	 *
	 * 改期不需要另外傳入人員：客人當初有指定人員的話只會嘗試同一位（沒空就直接
	 * 失敗），沒指定的話系統會在候選人員中重新挑選，規則見 UAPPT_Booking::reschedule()。
	 */
	public function handle_reschedule_booking() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		check_admin_referer( 'uappt_edit_booking_' . $booking_id );

		$date_ymd = isset( $_POST['date_ymd'] ) ? sanitize_text_field( wp_unslash( $_POST['date_ymd'] ) ) : '';
		$time_hm  = isset( $_POST['time_hm'] ) ? sanitize_text_field( wp_unslash( $_POST['time_hm'] ) ) : '';

		if ( ! $booking_id || '' === $date_ymd || '' === $time_hm ) {
			$this->redirect_with_error(
				'uappt-bookings',
				__( '請選擇新的日期與時段。', 'ultimate-appointments' ),
				array(
					'action'     => 'edit',
					'booking_id' => $booking_id,
				)
			);
		}

		$before = UAPPT_Booking::get( $booking_id );
		$result = UAPPT_Booking::reschedule( $booking_id, $date_ymd, $time_hm );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error(
				'uappt-bookings',
				$result->get_error_message(),
				array(
					'action'     => 'edit',
					'booking_id' => $booking_id,
				)
			);
		}

		$after = UAPPT_Booking::get( $booking_id );
		if ( $before && $after && $after['order_id'] && $after['order_item_id'] ) {
			$order = wc_get_order( $after['order_id'] );
			if ( $order ) {
				$item = $order->get_item( $after['order_item_id'] );
				if ( $item ) {
					$item->update_meta_data( __( '預約時段', 'ultimate-appointments' ), self::format_booking_label( $after ) );
					$item->save();
				}
				$order->add_order_note(
					sprintf(
						/* translators: 1: 原時段 2: 新時段 */
						__( '預約時段已由後台改期：%1$s → %2$s。', 'ultimate-appointments' ),
						self::format_booking_label( $before ),
						self::format_booking_label( $after )
					)
				);
			}
		}

		$this->redirect(
			'uappt-bookings',
			array(
				'action'      => 'edit',
				'booking_id'  => $booking_id,
				'uappt_notice' => 'booking_rescheduled',
			)
		);
	}

	/**
	 * 處理更換服務人員：確認「待分派」的暫定人選，或客服直接改派給別人。
	 *
	 * 實際的鎖定/釋放交易都在 UAPPT_Booking::reassign_staff()，這裡只負責表單
	 * 解析、失敗轉址，以及成功後的連帶動作（訂單備註、視情況通知客人）。
	 * 這兩件連帶動作只在**人員真的換了**（不是單純確認暫定人選）時才做——
	 * 確認不是客人體驗上的變化，不需要訂單留痕，也不需要打擾客人。
	 */
	public function handle_reassign_staff() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		check_admin_referer( 'uappt_edit_booking_' . $booking_id );

		$new_staff_id = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;
		$notify       = ! empty( $_POST['notify_customer'] );

		if ( ! $booking_id || ! $new_staff_id ) {
			$this->redirect_with_error(
				'uappt-bookings',
				__( '請選擇一位服務人員。', 'ultimate-appointments' ),
				array(
					'action'     => 'edit',
					'booking_id' => $booking_id,
				)
			);
		}

		$before = UAPPT_Booking::get( $booking_id );
		$result = UAPPT_Booking::reassign_staff( $booking_id, $new_staff_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error(
				'uappt-bookings',
				$result->get_error_message(),
				array(
					'action'     => 'edit',
					'booking_id' => $booking_id,
				)
			);
		}

		$after            = UAPPT_Booking::get( $booking_id );
		$old_staff_id     = $before ? (int) $before['staff_id'] : 0;
		$actual_staff_id  = $after ? (int) $after['staff_id'] : 0;
		$staff_changed    = $before && $after && $old_staff_id !== $actual_staff_id;
		$notify_notice    = '';

		if ( $staff_changed ) {
			$old_name = self::staff_label( $old_staff_id );
			$new_name = self::staff_label( $actual_staff_id );

			if ( $after['order_id'] ) {
				$order = wc_get_order( $after['order_id'] );
				if ( $order ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: 原服務人員 2: 新服務人員 */
							__( '服務人員已由後台更換：%1$s → %2$s。', 'ultimate-appointments' ),
							$old_name,
							$new_name
						)
					);
				}
			}

			if ( $notify ) {
				$notify_result = UAPPT_Reminders::send_staff_change_notice( $after, $new_name );
				if ( is_wp_error( $notify_result ) ) {
					// 換人本身已經成功，通知失敗不該讓整個操作看起來是失敗的，
					// 只用另一個 notice 提示管理者「換成了，但客人沒收到通知」。
					$notify_notice = 'staff_reassigned_notify_failed';
				}
			}
		}

		$this->redirect_back(
			'uappt-bookings',
			array(
				'action'      => 'edit',
				'booking_id'  => $booking_id,
				'uappt_notice' => $notify_notice ? $notify_notice : 'staff_reassigned',
			),
			array( 'uappt_notice' => $notify_notice ? $notify_notice : 'staff_reassigned' )
		);
	}

	/**
	 * 標記預約為已完成（客人已實際到場完成療程）。
	 */
	public function handle_complete_booking() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		check_admin_referer( 'uappt_edit_booking_' . $booking_id );

		if ( $booking_id ) {
			UAPPT_Booking::complete( $booking_id );
		}

		$this->redirect_back(
			'uappt-bookings',
			array(
				'action'      => 'edit',
				'booking_id'  => $booking_id,
				'uappt_notice' => 'booking_completed',
			),
			array( 'uappt_notice' => 'booking_completed' )
		);
	}

	/**
	 * 標記客人未到。跟 handle_complete_booking() 完全同一套樣式，只是換一支
	 * 引擎方法——真正的狀態轉換規則（只能從 confirmed、服務時間要已過）
	 * 都在 UAPPT_Booking::mark_no_show() 裡把關，這裡不重複判斷。
	 */
	public function handle_mark_no_show() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		check_admin_referer( 'uappt_edit_booking_' . $booking_id );

		if ( $booking_id ) {
			UAPPT_Booking::mark_no_show( $booking_id );
		}

		$this->redirect_back(
			'uappt-bookings',
			array(
				'action'      => 'edit',
				'booking_id'  => $booking_id,
				'uappt_notice' => 'booking_no_show',
			),
			array( 'uappt_notice' => 'booking_no_show' )
		);
	}

	/**
	 * 把標錯的「已完成」／「未到」還原成「已確認」。狀態守衛（只收這兩個、
	 * 不收已經釋放過格子的 cancelled／expired）全部在
	 * UAPPT_Booking::revert_to_confirmed() 裡，這裡跟另外兩支完成狀態 handler
	 * 用完全相同的樣式，不重複判斷。
	 *
	 * 兩個進入點共用這一支：編輯頁是一般的 POST 表單，預約列表則是帶 nonce 的
	 * 連結（列表整張表格被批次操作的 <form> 包住，不能再包內層表單，理由見
	 * handle_cancel_booking()）。`check_admin_referer()` 讀的是 `$_REQUEST`，
	 * 兩種請求方式都成立，所以 booking_id 也一律從 `$_REQUEST` 拿。
	 *
	 * 轉址回哪裡跟著來源走（`uappt_return`）：從編輯頁按就留在編輯頁（還原之後
	 * 通常要接著重新標記或改期），從列表按就回列表，不要把人踢到別的畫面。
	 */
	public function handle_revert_booking_status() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$booking_id = isset( $_REQUEST['booking_id'] ) ? absint( $_REQUEST['booking_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'uappt_edit_booking_' . $booking_id );

		$ok        = $booking_id ? UAPPT_Booking::revert_to_confirmed( $booking_id ) : false;
		$to_list   = isset( $_REQUEST['uappt_return'] ) && 'list' === $_REQUEST['uappt_return']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$args      = array( 'uappt_notice' => $ok ? 'booking_reverted' : 'booking_revert_failed' );

		if ( ! $to_list ) {
			$args['action']     = 'edit';
			$args['booking_id'] = $booking_id;
		}

		$this->redirect( 'uappt-bookings', $args );
	}

	/**
	 * 更新預約的聯絡資訊與內部備註。
	 */
	public function handle_update_booking_details() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		check_admin_referer( 'uappt_edit_booking_' . $booking_id );

		if ( $booking_id ) {
			UAPPT_Booking::update_details(
				$booking_id,
				array(
					'customer_name'  => isset( $_POST['customer_name'] ) ? wp_unslash( $_POST['customer_name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'customer_phone' => isset( $_POST['customer_phone'] ) ? wp_unslash( $_POST['customer_phone'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'note'           => isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				)
			);
		}

		$this->redirect(
			'uappt-bookings',
			array(
				'action'      => 'edit',
				'booking_id'  => $booking_id,
				'uappt_notice' => 'booking_updated',
			)
		);
	}

	/**
	 * 更新一筆既有預約的金額（客人建完單之後才談折扣、或當初就打錯了）。
	 *
	 * **有訂單與沒訂單走兩條不同的路，這是整支方法的重點**：
	 *
	 * - 有訂單：改的是訂單項目，然後讓 `link_to_order()` 把訂單項目的實際金額
	 *   同步回 `bookings.amount`。訂單才是事實來源（折扣碼、稅、退款都在那邊），
	 *   只改快照會讓兩個數字打架，報表跟訂單頁各說各話。
	 * - 沒訂單（手動建單沒勾「建立訂單」）：`bookings.amount` 本來就是唯一的
	 *   營收紀錄，直接寫它。
	 *
	 * ⚠️ **已付款的訂單改金額不會自動退差額或補收款**，只是把帳面數字改對。
	 * 金流的部分要自己在綠界／POS 那邊處理，view 上也寫了這句提醒。
	 */
	public function handle_update_booking_amount() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		check_admin_referer( 'uappt_edit_booking_' . $booking_id );

		$redirect_args = array(
			'action'     => 'edit',
			'booking_id' => $booking_id,
		);

		$booking = $booking_id ? UAPPT_Booking::get( $booking_id ) : null;
		if ( ! $booking ) {
			$this->redirect_with_error( 'uappt-bookings', __( '找不到這筆預約。', 'ultimate-appointments' ) );
		}

		$amount = isset( $_POST['custom_amount'] ) ? wc_format_decimal( wp_unslash( $_POST['custom_amount'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $amount || (float) $amount < 0 ) {
			$this->redirect_with_error( 'uappt-bookings', __( '請填一個不小於 0 的金額。', 'ultimate-appointments' ), $redirect_args );
		}

		$amount   = (float) $amount;
		$previous = null === $booking['amount'] ? 0.0 : (float) $booking['amount'];
		$note     = self::format_amount_change_note( $previous, $amount );

		$order      = ! empty( $booking['order_id'] ) ? wc_get_order( (int) $booking['order_id'] ) : null;
		$order_item = ( $order && ! empty( $booking['order_item_id'] ) ) ? $order->get_item( (int) $booking['order_item_id'] ) : null;

		// 收款方式、未收費原因跟金額在同一張表單：只改了那兩個的時候，金額這段整個跳過
		// （v3.0.2）。以前照樣寫一則「原價 1,000 → 實收 1,000」到預約與訂單備註，還對
		// 已付款訂單重算一次總額——稅率設定改過的話，總額會被這次重算悄悄改掉。
		$amount_changed = round( $amount, 2 ) !== round( $previous, 2 );

		if ( $amount_changed && $order && $order_item ) {
			// subtotal（原價）維持不動，只改 total（實收）——訂單頁就會把差額
			// 顯示成折扣。金額調高時兩者一起推上去，理由跟手動建單那邊一樣：
			// subtotal < total 會被 WooCommerce 當成負折扣印出來。
			$subtotal = (float) $order_item->get_subtotal();
			$order_item->set_total( $amount );
			if ( $amount > $subtotal ) {
				$order_item->set_subtotal( $amount );
			}
			$order_item->save();

			$order->calculate_totals();
			$order->add_order_note( $note );
			$order->save();

			// 這支順便會用訂單項目的實際金額覆蓋 bookings.amount，所以下面
			// 的 update_details() 只要寫備註就好，不用再寫一次金額。
			UAPPT_Booking::link_to_order( $booking_id, (int) $booking['order_id'], (int) $booking['order_item_id'] );
			UAPPT_Booking::update_details( $booking_id, array( 'note' => $this->append_note( $booking['note'], $note ) ) );
		} elseif ( $amount_changed ) {
			UAPPT_Booking::update_details(
				$booking_id,
				array(
					'amount' => $amount,
					'note'   => $this->append_note( $booking['note'], $note ),
				)
			);
		}

		// 收款方式跟金額同一張表單，所以在同一支 handler 裡處理。刻意排在金額
		// 之後：上面的 link_to_order() 會重新寫入 bookings.amount，順序顛倒的話
		// 沒有問題，但把「先金額後收款」寫死比較好推理。
		UAPPT_Payment::apply(
			$booking,
			UAPPT_Payment::sanitize_slug( isset( $_POST['payment_method'] ) ? wp_unslash( $_POST['payment_method'] ) : '' )
		);

		// 未收費原因。跟收款方式一樣是同一張表單送上來的，但**只套用在這一筆**
		// ——招待是針對某一次服務的決定，不像收款方式是整張訂單共用的。
		UAPPT_Booking::set_no_charge(
			$booking_id,
			isset( $_POST['no_charge_reason'] ) ? wp_unslash( $_POST['no_charge_reason'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			isset( $_POST['no_charge_note'] ) ? sanitize_text_field( wp_unslash( $_POST['no_charge_note'] ) ) : ''
		);

		$this->redirect( 'uappt-bookings', array_merge( $redirect_args, array( 'uappt_notice' => 'booking_amount_updated' ) ) );
	}

	/**
	 * 把一行稽核紀錄接在既有的內部備註後面，而不是覆蓋掉。
	 *
	 * 改價的歷史要能累積：同一筆預約先打九折、隔天又改成招待，兩次都要查得到。
	 * 覆蓋的話只剩最後一次，中間發生過什麼完全消失。
	 *
	 * @param string|null $existing 既有備註。
	 * @param string      $line     要追加的一行。
	 * @return string
	 */
	protected function append_note( $existing, $line ) {
		$existing = trim( (string) $existing );
		return '' === $existing ? $line : $existing . "\n" . $line;
	}

	/* ---------------------------------------------------------------------
	 * 手動建立預約（電話訂單）
	 * ------------------------------------------------------------------- */

	/**
	 * 輸出手動建立預約頁。
	 */
	public function render_manual_booking_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$bookable_options = $this->get_bookable_options();

		// 從日檢視「點空白處」跳過來時帶的日期（見 calendar-day.php 的
		// data-quick-book-url），直接當表單的初始值。人員 ID 也是同一個連結
		// 帶過來的，但要等客服選好服務項目、候選人員名單查回來之後才選得到，
		// 那個值改在 enqueue_assets() 交給 JS（UAPPT_Admin_Booking.prefill_staff_id），
		// 由 assets/js/admin-booking.js 在人員清單填好後套用，不需要經過這個變數。
		$prefill_date = isset( $_GET['date_ymd'] ) ? sanitize_text_field( wp_unslash( $_GET['date_ymd'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $prefill_date ) ) {
			$prefill_date = '';
		}

		self::page_open( __( '手動建立預約', 'ultimate-appointments' ) );
		require UAPPT_PLUGIN_DIR . 'includes/views/manual-booking.php';
		self::page_close();
	}

	/**
	 * 取得所有可預約的項目，供手動建立預約的下拉選單使用。
	 *
	 * 有服務方案的商品會展開成各個方案，讓客服可以直接選到「90 分鐘方案」這種
	 * 層級；選項值格式為 "商品ID" 或 "商品ID:方案鍵"。
	 *
	 * 查詢改用 product_type 分類（不再是 _uappt_enable_booking meta）：v2.1.0 起
	 * 商品類型本身就是「是不是預約商品」的唯一判準。
	 *
	 * @return array 每個元素：['value' => string, 'label' => string].
	 */
	protected function get_bookable_options() {
		$post_ids = get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => UAPPT_Product_Type::PRODUCT_TYPE,
					),
				),
			)
		);

		$options = array();

		foreach ( $post_ids as $product_id ) {
			// 暫停中的服務仍然列出來（後台手動建單不受暫停限制），但標註出來，
			// 免得客服不知道這個服務前台其實已經關掉了。
			$suffix = UAPPT_Product::is_paused( $product_id )
				? __( '（已暫停）', 'ultimate-appointments' )
				: '';

			// 這裡刻意不傳 $active_only：已停用的方案仍要能被挑出來給既有客人改期／
			// 加開一筆對應的預約，只是要標「（已停用）」提醒客服這是非常態操作。
			$plans = UAPPT_Product::get_plans( $product_id );

			if ( $plans ) {
				foreach ( $plans as $plan ) {
					if ( ! UAPPT_Product::is_bookable( $product_id, $plan['key'] ) ) {
						continue;
					}
					$plan_suffix = $suffix;
					if ( empty( $plan['active'] ) ) {
						$plan_suffix .= __( '（已停用）', 'ultimate-appointments' );
					}
					$options[] = array(
						'value' => $product_id . ':' . $plan['key'],
						'label' => UAPPT_Product::get_display_name( $product_id, $plan['key'] ) . $plan_suffix,
					);
				}
				continue;
			}

			if ( UAPPT_Product::is_bookable( $product_id ) ) {
				$options[] = array(
					'value' => (string) $product_id,
					'label' => UAPPT_Product::get_display_name( $product_id ) . $suffix,
				);
			}
		}

		return $options;
	}

	/**
	 * 解析下拉選單的值，拆出商品 ID 與方案鍵。
	 *
	 * @param string $raw 例如 "123" 或 "123:plan_6a1f…"。
	 * @return array{product_id:int, plan_key:string}
	 */
	protected function parse_bookable_value( $raw ) {
		$parts = explode( ':', (string) $raw );

		return array(
			'product_id' => isset( $parts[0] ) ? absint( $parts[0] ) : 0,
			'plan_key'   => isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '',
		);
	}

	/**
	 * 處理手動建立預約：先鎖定時段成功後才建立訂單，避免留下孤兒訂單。
	 */
	public function handle_create_manual_booking() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_create_manual_booking' );

		$target         = $this->parse_bookable_value( isset( $_POST['bookable'] ) ? wp_unslash( $_POST['bookable'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$product_id     = $target['product_id'];
		$plan_key       = $target['plan_key'];
		$date_ymd       = isset( $_POST['date_ymd'] ) ? sanitize_text_field( wp_unslash( $_POST['date_ymd'] ) ) : '';
		$time_hm        = isset( $_POST['time_hm'] ) ? sanitize_text_field( wp_unslash( $_POST['time_hm'] ) ) : '';
		$staff_id       = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;
		$customer_id    = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;
		$customer_name  = isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '';
		$customer_phone = isset( $_POST['customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_phone'] ) ) : '';
		$order_status   = isset( $_POST['order_status'] ) && 'completed' === $_POST['order_status'] ? 'completed' : 'processing';
		// 現場客人有時候只要把人力卡住（收款走 POS、或當下還沒結帳），不需要在
		// WooCommerce 開一張訂單。時段照樣鎖住，差別只在有沒有這筆營收紀錄。
		$create_order   = ! empty( $_POST['create_order'] );

		// 收款方式。白名單比對（見 UAPPT_Payment::sanitize_slug()）——表單送什麼上來
		// 都不能直接寫進資料表，那個 slug 之後會被報表拿去分組。
		$payment_method = UAPPT_Payment::sanitize_slug( isset( $_POST['payment_method'] ) ? wp_unslash( $_POST['payment_method'] ) : '' );

		// 未收費原因（招待／員工親友／重做…）。同樣走白名單。
		$no_charge_reason = UAPPT_Booking::sanitize_no_charge_reason( isset( $_POST['no_charge_reason'] ) ? wp_unslash( $_POST['no_charge_reason'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$no_charge_note   = isset( $_POST['no_charge_note'] ) ? sanitize_text_field( wp_unslash( $_POST['no_charge_note'] ) ) : '';
		// 團體預約的報名人數，跟前台同一個 create_hold() 入口、同一套驗證規則
		// （商品沒開放團體預約時，>1 一樣會被 create_hold() 拒絕）。
		$participants   = isset( $_POST['units'] ) ? max( 1, absint( $_POST['units'] ) ) : 1;
		// 現場櫃檯的熟客折扣／湊整數／招待。沒勾就完全走既有的自動計算路徑，
		// 兩條路不互相干擾——這是刻意的：自動計算是前台與後台共用的同一套算法，
		// 不該為了自訂金額在裡面塞條件。
		$custom_amount_enabled = ! empty( $_POST['custom_amount_enabled'] );
		$custom_amount         = ( $custom_amount_enabled && isset( $_POST['custom_amount'] ) )
			? wc_format_decimal( wp_unslash( $_POST['custom_amount'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: '';

		if ( ! $product_id || ! UAPPT_Product::is_bookable( $product_id, $plan_key ) ) {
			$this->redirect_with_error( 'uappt-manual-booking', __( '請選擇一個開放預約的服務項目。', 'ultimate-appointments' ) );
		}

		// 勾了卻沒填就擋下來，不要默默當成 0——0 元在報表上會被讀成「免費服務」，
		// 那是一個完全不同的意思。
		if ( $custom_amount_enabled && ( '' === $custom_amount || (float) $custom_amount < 0 ) ) {
			$this->redirect_with_error( 'uappt-manual-booking', __( '勾了「自訂這筆的金額」就要填一個不小於 0 的金額。', 'ultimate-appointments' ) );
		}

		$booking_id = UAPPT_Booking::create_hold(
			array(
				'product_id'     => $product_id,
				'plan_key'       => $plan_key,
				// 「暫停接受預約」只擋前台的新預約；後台客服要幫客人排是店家自己的
				// 決定，系統不該擋。用明確參數表達，不要靠 created_by 有沒有值暗示。
				'ignore_paused'  => true,
				'date_ymd'       => $date_ymd,
				'time_hm'        => $time_hm,
				'staff_id'       => $staff_id,
				'units'          => $participants,
				'customer_id'    => $customer_id,
				'customer_name'  => $customer_name,
				'customer_phone' => $customer_phone,
				'status'         => UAPPT_Booking::STATUS_CONFIRMED,
				'created_by'     => get_current_user_id(),
			)
		);

		if ( is_wp_error( $booking_id ) ) {
			$this->redirect_with_error( 'uappt-manual-booking', $booking_id->get_error_message() );
		}

		// create_hold() 已經照「方案價 × 人數 + 指定加價」寫好了金額快照，要拿
		// 原價直接回頭問這筆紀錄就好，不要在這裡重算一次同一條算式——兩份算式
		// 遲早會分岔。
		$created_booking = UAPPT_Booking::get( $booking_id );
		$auto_amount     = $created_booking ? (float) $created_booking['amount'] : 0.0;

		if ( $custom_amount_enabled ) {
			// 改價一定要留痕跡，不然月底對帳查不出這筆為什麼收這個數字。
			$amount_fields = array(
				'note' => self::format_amount_change_note( $auto_amount, (float) $custom_amount ),
			);
			// **有訂單時刻意不在這裡寫 amount**：下面會把自訂金額設進訂單項目，
			// 最後 link_to_order() 再用訂單項目的實際金額覆蓋這個快照（那是既有
			// 的設計，訂單才是事實來源）。兩條路各寫一次只會製造不一致。
			if ( ! $create_order ) {
				$amount_fields['amount'] = (float) $custom_amount;
			}
			UAPPT_Booking::update_details( $booking_id, $amount_fields );
		}

		// 未收費原因寫在這裡，**不要等到建立訂單之後**：沒有勾「建立訂單」的單
		// （收款走 POS、或當下還沒結帳）同樣可能是招待，而那種單更需要留下
		// 原因——它連訂單都沒有，事後完全查不到為什麼沒收錢。
		if ( '' !== $no_charge_reason ) {
			UAPPT_Booking::set_no_charge( $booking_id, $no_charge_reason, $no_charge_note );
		}

		// 不建立訂單就到此為止：預約已經成立、時段已經鎖住，剩下的訂單/金額
		// 流程整段跳過。create_hold() 本來就不需要訂單才能運作。
		if ( ! $create_order ) {
			$this->redirect( 'uappt-bookings', array( 'uappt_notice' => 'booking_created_no_order' ) );
		}

		$product = wc_get_product( $product_id );
		$order   = wc_create_order( array( 'created_via' => 'uappt_manual' ) );

		if ( is_wp_error( $order ) || ! $product ) {
			UAPPT_Booking::release( $booking_id, UAPPT_Booking::STATUS_CANCELLED );
			$this->redirect_with_error( 'uappt-manual-booking', __( '建立訂單失敗，時段已釋放，請重新嘗試。', 'ultimate-appointments' ) );
		}

		$item_id = $order->add_product( $product, 1 );

		// 金額要自己算：add_product() 只知道商品本身的售價，不知道客人選的是哪個
		// 方案、也不知道指定人員的加價（前台是靠購物車的 before_calculate_totals
		// 處理的，手動建單走不到那條路）。
		$settings   = UAPPT_Product::get_booking_settings( $product_id, $plan_key );
		$order_item = $order->get_item( $item_id );

		if ( $order_item && $settings ) {
			$manual_booking = $created_booking;
			$adjustment     = $manual_booking ? (float) $manual_booking['staff_price_adjustment'] : 0.0;
			// 跟 booking 紀錄自己存的 occupied_units 為準，不是直接沿用表單送出的
			// $participants——同一個道理：資料庫紀錄才是唯一事實來源。
			$units          = $manual_booking ? max( 1, (int) $manual_booking['occupied_units'] ) : 1;

			$base_price = '' !== (string) $settings['price']
				? (float) $settings['price']
				: (float) $order_item->get_subtotal();
			// 方案價是「每人」的價格，人數要乘進去；人員指定加價固定不乘，跟
			// 前台購物車（UAPPT_Cart::apply_cart_item_price()）用同一套算法。
			$auto_total = $base_price * $units + $adjustment;

			if ( $custom_amount_enabled ) {
				$line_total = (float) $custom_amount;
				// 自訂金額比原價低（折扣）時，subtotal 留原價、total 填實收，
				// WooCommerce 就會在訂單上把差額顯示成折扣，帳面看得出「原價
				// 多少、折了多少」。比原價高（現場臨時加購）時兩者一律填同一
				// 個數字：subtotal < total 在 WooCommerce 眼裡是「負折扣」，
				// 訂單頁會印出一個負數的折扣金額，看起來像壞掉。
				$line_subtotal = $line_total < $auto_total ? $auto_total : $line_total;
			} else {
				$line_total    = $auto_total;
				$line_subtotal = $auto_total;
			}

			$order_item->set_subtotal( $line_subtotal );
			$order_item->set_total( $line_total );

			if ( ! empty( $settings['plan_name'] ) ) {
				$order_item->add_meta_data( __( '服務方案', 'ultimate-appointments' ), $settings['plan_name'], true );
			}

			if ( $units > 1 ) {
				$order_item->add_meta_data(
					__( '報名人數', 'ultimate-appointments' ),
					/* translators: %d: 人數 */
					sprintf( __( '%d 人', 'ultimate-appointments' ), $units ),
					true
				);
			}

			$order_item->save();
		}

		// 綁定會員：訂單才會歸戶到該會員的消費紀錄，會員制度的點數累積等外掛
		// 才抓得到這筆消費。Email 從會員資料帶，因為表單本身沒有另外收 email。
		if ( $customer_id && class_exists( 'WC_Customer' ) ) {
			$wc_customer = new WC_Customer( $customer_id );
			if ( $wc_customer->get_id() ) {
				$order->set_customer_id( $customer_id );
				if ( $wc_customer->get_email() ) {
					$order->set_billing_email( $wc_customer->get_email() );
				}
			}
		}

		if ( $customer_name ) {
			$name_parts = explode( ' ', $customer_name, 2 );
			$order->set_billing_first_name( $name_parts[0] );
			if ( ! empty( $name_parts[1] ) ) {
				$order->set_billing_last_name( $name_parts[1] );
			}
		}
		if ( $customer_phone ) {
			$order->set_billing_phone( $customer_phone );
		}

		$order->calculate_totals();
		$order->add_order_note( __( '此訂單由後台人工建立（電話/現場預約）。', 'ultimate-appointments' ) );

		if ( $custom_amount_enabled ) {
			$order->add_order_note( self::format_amount_change_note( $auto_amount, (float) $custom_amount ) );
		}

		// 電話/現場預約通常口頭上已經確認過，這裡刻意不寄「處理中/已完成」訂單
		// 通知信給客人——尤其綁定會員時，突然收到一封訂單信容易顯得唐突。
		$this->without_customer_order_emails(
			function () use ( $order, $order_status ) {
				$order->set_status( $order_status );
				$order->save();
			}
		);

		UAPPT_Booking::link_to_order( $booking_id, $order->get_id(), $item_id );

		// 收款方式與當下的費率一起記在預約上。要排在 link_to_order() 之後——
		// snapshot_rate() 是用 order_id 找預約的，在綁定之前呼叫會一筆都更新
		// 不到（而且不會報錯，是那種會安靜漏掉的順序相依）。
		UAPPT_Payment::snapshot_rate( $order->get_id(), $payment_method );

		$order_item = $order->get_item( $item_id );
		if ( $order_item ) {
			$booking = UAPPT_Booking::get( $booking_id );
			$order_item->add_meta_data( '_uappt_booking_id', $booking_id, true );
			if ( $booking ) {
				$label = self::format_booking_label( $booking );
				if ( $label ) {
					$order_item->add_meta_data( __( '預約時段', 'ultimate-appointments' ), $label, true );
				}
				if ( ! empty( $booking['staff_id'] ) ) {
					$staff_row = UAPPT_Staff::get( (int) $booking['staff_id'] );
					if ( $staff_row ) {
						$order_item->add_meta_data( __( '服務人員', 'ultimate-appointments' ), $staff_row['name'], true );
					}
				}
			}
			$order_item->save();
		}

		$this->redirect( 'uappt-bookings', array( 'uappt_notice' => 'booking_created' ) );
	}

	/**
	 * 組出「自訂金額：原價 X → 實收 Y（操作者：Z）」這句稽核紀錄，訂單備註與
	 * 預約內部備註共用同一句——兩邊看到的說法不一致的話，對帳的人要自己猜哪
	 * 一句才是對的。
	 *
	 * @param float $auto_amount   自動計算出來的原價。
	 * @param float $custom_amount 實際收取的金額。
	 * @return string
	 */
	protected static function format_amount_change_note( $auto_amount, $custom_amount ) {
		return sprintf(
			/* translators: 1: 原價 2: 實收金額 3: 操作者名稱 */
			__( '自訂金額：原價 %1$s → 實收 %2$s（操作者：%3$s）', 'ultimate-appointments' ),
			self::plain_price( $auto_amount ),
			self::plain_price( $custom_amount ),
			wp_get_current_user()->display_name
		);
	}

	/**
	 * `wc_price()` 的純文字版。訂單備註與 `bookings.note` 都是純文字欄位，
	 * 直接塞 `wc_price()` 的 HTML 進去會在畫面上看到一整串 span 標籤；
	 * 貨幣符號與千分位又是 `wc_price()` 才知道怎麼組的（站台設定），所以
	 * 是「用它產生、再脫掉標籤」，不是自己另外格式化一份。
	 *
	 * `html_entity_decode()` 不能省：`&nbsp;`（貨幣符號與數字之間的不斷行
	 * 空格）在 `wp_strip_all_tags()` 之後仍然是字面上的 `&nbsp;` 六個字元。
	 *
	 * @param float $amount 金額。
	 * @return string
	 */
	protected static function plain_price( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * 在呼叫端的動作執行期間，暫時停用會寄給客人的訂單狀態通知信
	 * （處理中/已完成/保留）。用在後台手動建立預約：電話/現場預約通常口頭上
	 * 已經確認過，不需要再額外寄一封訂單信給客人。
	 *
	 * 用官方文件記載的 `woocommerce_email_enabled_{email_id}` 篩選器暫時關閉，
	 * 而不是移除 action hook——不需要猜測 WC_Emails 內部確切掛載的 callback
	 * 簽章，版本間也比較不會壞掉。
	 *
	 * @param callable $callback 要在停用信件期間執行的動作，不接受參數。
	 */
	protected function without_customer_order_emails( $callback ) {
		$email_ids = array( 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order' );

		foreach ( $email_ids as $email_id ) {
			add_filter( 'woocommerce_email_enabled_' . $email_id, '__return_false' );
		}

		$callback();

		foreach ( $email_ids as $email_id ) {
			remove_filter( 'woocommerce_email_enabled_' . $email_id, '__return_false' );
		}
	}

	/**
	 * 組出「2026-09-10 (四) 14:00–15:00」這樣的顯示字串，供訂單備註/訂單項目 meta 使用。
	 *
	 * @param array $booking 預約紀錄。
	 * @return string
	 */
	public static function format_booking_label( $booking ) {
		$start_dt = date_create( $booking['service_start'], wp_timezone() );
		$end_dt   = date_create( $booking['service_end'], wp_timezone() );

		if ( ! $start_dt || ! $end_dt ) {
			return '';
		}

		return wp_date( 'Y-m-d (D) H:i', $start_dt->getTimestamp() ) . '–' . wp_date( 'H:i', $end_dt->getTimestamp() );
	}

	/**
	 * 取得訂單編輯頁的網址。
	 *
	 * 不能直接拼 `post.php?post=訂單ID&action=edit`——啟用 HPOS（High-Performance
	 * Order Storage）之後訂單不再是 post，那個網址會進不去。一律透過
	 * `$order->get_edit_order_url()`，讓 WooCommerce 自己決定要指到傳統 post 編輯頁
	 * 還是 HPOS 的 `admin.php?page=wc-orders`。
	 *
	 * @param int $order_id 訂單 ID。
	 * @return string 找不到訂單時回傳空字串。
	 */
	public static function get_order_edit_url( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		return $order ? $order->get_edit_order_url() : '';
	}

	/* ---------------------------------------------------------------------
	 * 日曆檢視
	 * ------------------------------------------------------------------- */

	/**
	 * 輸出日曆檢視頁：依 view 參數分流成月檢視／日檢視（按人員分欄）。
	 */
	public function render_calendar_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$staff_list = UAPPT_Staff::get_all();
		if ( empty( $staff_list ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( '日曆檢視', 'ultimate-appointments' ) . '</h1><p>' .
				esc_html__( '請先建立至少一位人員。', 'ultimate-appointments' ) . '</p></div>';
			return;
		}

		$show_all = ! empty( $_GET['show_all'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view     = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'month'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'day' === $view ) {
			// 日檢視是多選（人員一多，橫向捲動看不到全貌，讓管理者自己挑要同時
			// 比較哪幾位）；staff_ids[] 是新的多選參數，staff_id（單數）繼續相容
			// 舊的深連結（例如從預約列表「只看某人」那種只會帶單一 ID 過來的
			// 連結）——兩者只會出現一個，staff_ids[] 有值就以它為準。
			$staff_ids = isset( $_GET['staff_ids'] ) && is_array( $_GET['staff_ids'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_GET['staff_ids'] ) ) ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				: array();
			if ( empty( $staff_ids ) && ! empty( $_GET['staff_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$staff_ids = array( absint( $_GET['staff_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			$hide_idle = ! empty( $_GET['hide_idle'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$this->render_calendar_day_view( $staff_list, $staff_ids, $show_all, $hide_idle );
			return;
		}

		$staff_id = isset( $_GET['staff_id'] ) ? absint( $_GET['staff_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$month_param = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month_param ) ) {
			$month_param = current_time( 'Y-m' );
		}

		$month_start   = $month_param . '-01';
		$month_start_dt = date_create( $month_start . ' 00:00:00', wp_timezone() );
		$days_in_month = $month_start_dt ? (int) $month_start_dt->format( 't' ) : 30;
		$month_end     = $month_param . '-' . str_pad( (string) $days_in_month, 2, '0', STR_PAD_LEFT );

		// 月曆格子：從本月第一天所在那一週的週一開始，補到含滿最後一週的週日為止，
		// 讓格線永遠是完整的 7 欄矩形，前後月份多出來的日期用來補白。
		$first_weekday_iso = uappt_local_weekday_iso( $month_start ); // 1（一）..7（日）
		$grid_start         = uappt_local_date( $month_start, '-' . ( $first_weekday_iso - 1 ) . ' days' );

		$last_weekday_iso = uappt_local_weekday_iso( $month_end );
		$grid_end          = uappt_local_date( $month_end, '+' . ( 7 - $last_weekday_iso ) . ' days' );

		$days = array();
		$cursor = $grid_start;
		while ( $cursor <= $grid_end ) {
			$days[]  = $cursor;
			$cursor = uappt_local_date( $cursor, '+1 day' );
		}

		$result = UAPPT_Booking::query(
			array(
				'staff_id'  => $staff_id,
				'date_from' => $grid_start,
				'date_to'   => $grid_end,
				'order'     => 'ASC',
				'per_page'  => 1000,
				'kind'      => 'any',
			)
		);

		// 預設隱藏已取消/已逾時的預約——月檢視格子小，這些通常只是雜訊；
		// UAPPT_Booking::query() 的 status 篩選只能比對單一狀態，要排除兩種狀態
		// 用查詢條件做不到，改成查全部後在這裡濾掉比較單純。
		$by_day = array_fill_keys( $days, array() );
		foreach ( $result['items'] as $booking ) {
			if ( ! $show_all && in_array( $booking['status'], array( UAPPT_Booking::STATUS_CANCELLED, UAPPT_Booking::STATUS_EXPIRED ), true ) ) {
				continue;
			}
			$start_dt = date_create( $booking['service_start'], wp_timezone() );
			if ( ! $start_dt ) {
				continue;
			}
			$day_key = wp_date( 'Y-m-d', $start_dt->getTimestamp() );
			if ( isset( $by_day[ $day_key ] ) ) {
				$by_day[ $day_key ][] = $booking;
			}
		}
		foreach ( $by_day as &$items ) {
			usort(
				$items,
				function ( $a, $b ) {
					return strcmp( $a['service_start'], $b['service_start'] );
				}
			);
		}
		unset( $items );

		$prev_month_param = substr( uappt_local_date( $month_start, '-1 month' ), 0, 7 );
		$next_month_param = substr( uappt_local_date( $month_start, '+1 month' ), 0, 7 );
		$this_month_param = current_time( 'Y-m' );

		self::page_open( __( '預約列表', 'ultimate-appointments' ), self::manual_booking_action() );
		$this->render_booking_view_switch( 'month' );
		require UAPPT_PLUGIN_DIR . 'includes/views/calendar.php';
		self::page_close();
	}

	/**
	 * 輸出日檢視（按人員分欄）。每位人員一欄，時間軸為列，方塊高度依療程時長
	 * 按比例呈現，方便管理員一眼看出排班有沒有空檔。
	 *
	 * 時間軸固定 00:00–24:00（v2.4.0 改版，見 includes/views/calendar-day.php 開頭
	 * 的座標系說明），不再依當天營業範圍動態縮放——那個做法會讓時間軸容器與
	 * 欄位容器的百分比基準對不齊、23 小時被壓進固定高度導致方塊文字被切掉。
	 * 畫面預設會捲動到當天最早的上班時間（$first_open_minutes，往前留一小時
	 * 緩衝），不會讓管理員一打開就要往下捲一大段才看到今天的班。
	 *
	 * @param array $staff_list 全部人員。
	 * @param array $staff_ids  篩選的人員 ID 陣列；空陣列代表顯示所有啟用中的人員。
	 * @param bool  $show_all   是否連已取消/已逾時的預約都顯示。
	 * @param bool  $hide_idle  是否隱藏當天完全沒有排班的人員欄位。
	 */
	protected function render_calendar_day_view( $staff_list, $staff_ids, $show_all, $hide_idle ) {
		$date_ymd = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_ymd ) ) {
			$date_ymd = current_time( 'Y-m-d' );
		}

		if ( ! empty( $staff_ids ) ) {
			$columns_staff = array_values(
				array_filter(
					$staff_list,
					function ( $s ) use ( $staff_ids ) {
						return in_array( (int) $s['id'], $staff_ids, true );
					}
				)
			);
		} else {
			$columns_staff = array_values(
				array_filter(
					$staff_list,
					function ( $s ) {
						return 'active' === $s['status'];
					}
				)
			);
		}

		// 時間軸固定 00:00–24:00，這裡先算出來，查詢跟畫面裁切都要用同一組時間戳。
		$day_start_ts = self::local_ts( $date_ymd . ' 00:00:00' );
		$day_end_ts   = $day_start_ts + DAY_IN_SECONDS;

		// 「只顯示今天有班的人員」要在決定 $columns_staff 的最終名單這一步就做掉
		// （而不是等 view 渲染時再跳過）：後面查詢預約、算重疊排班、算捲動位置
		// 全部都是照 $columns_staff 來的，早點濾掉才不會浪費一份完全用不到的
		// 資料，也不會讓「今天沒班的人被隱藏了，但畫面還在幫他查詢」這種
		// 不一致的中間狀態出現。人員多的店家（10 位以上）這個篩選尤其重要——
		// 排班本來就常常是輪班制，同時上班的往往只是其中一部分人。
		if ( $hide_idle ) {
			$columns_staff = array_values(
				array_filter(
					$columns_staff,
					function ( $s ) use ( $date_ymd, $day_start_ts, $day_end_ts ) {
						return ! empty( self::day_open_minutes( $s, $date_ymd, $day_start_ts, $day_end_ts ) );
					}
				)
			);
		}

		// 注意：這裡刻意不用「只查某位人員某一天」的寫法（以前的
		// get_bookings_for_staff_on_date()，v3.0.3 刪掉）——那支的 SQL 寫死只回傳
		// held/confirmed/completed，「顯示已取消/已逾時的預約」勾選在那個資料來源下
		// 永遠不會有作用。改用 query()（不指定 status
		// 篩選會回傳所有狀態）一次查出當天所有候選人員的預約，再依人員分組，跟
		// 月檢視排除已取消/已逾時的做法一致。
		//
		// date_from 刻意往前抓一天：query() 的日期篩選只看 service_start，前一天
		// 23:00–01:00 這種跨午夜預約的 service_start 落在前一天，若只查當天會漏掉
		// 延伸到今天凌晨的那段尾巴（人明明還在忙，日檢視卻完全看不到）。抓進來
		// 之後在下面用 service_start/service_end 跟 [day_start_ts, day_end_ts) 是否
		// 有重疊做真正的篩選，view 本來就會把跨日的方塊裁切到當天範圍內顯示。
		$query_filters = array(
			'date_from' => uappt_local_date( $date_ymd, '-1 day' ),
			'date_to'   => $date_ymd,
			'order'     => 'ASC',
			'per_page'  => 1000,
			// 日檢視是排班總覽，時段佔用（訓練／休息／現場客人）也要看得到——
			// 那些正是「人力被什麼吃掉」的答案。預約列表則維持只看客人的預約。
			'kind'      => 'any',
		);
		// query() 的 staff_id 篩選只吃單一 ID，篩到剩一位人員時才用得上；篩了
		// 好幾位或維持顯示全部時就不傳，讓下面依 $columns_staff 分組時自然把
		// 不在畫面上的人員濾掉（跟「全部人員」原本的做法一致，只是現在「全部」
		// 也可能是「hide_idle 篩過後剩下的全部」）。
		if ( 1 === count( $columns_staff ) ) {
			$query_filters['staff_id'] = (int) $columns_staff[0]['id'];
		}
		$query_result = UAPPT_Booking::query( $query_filters );

		$bookings_by_staff = array();
		foreach ( $columns_staff as $staff ) {
			$bookings_by_staff[ $staff['id'] ] = array();
		}

		foreach ( $query_result['items'] as $booking ) {
			if ( ! $show_all && in_array( $booking['status'], array( UAPPT_Booking::STATUS_CANCELLED, UAPPT_Booking::STATUS_EXPIRED ), true ) ) {
				continue;
			}
			$item_staff_id = ! empty( $booking['staff_id'] ) ? (int) $booking['staff_id'] : 0;
			if ( ! isset( $bookings_by_staff[ $item_staff_id ] ) ) {
				continue; // 不在目前顯示的欄位中（例如篩選了單一人員，或該人員未啟用）。
			}
			// 查詢多抓了前一天，這裡才是真正決定「跟今天有沒有交集」的判斷：
			// 服務有結束在今天 00:00 之後、且有開始在明天 00:00 之前，兩者都成立
			// 才算跟今天有交集（純粹當天內的預約自然也會通過）。
			$item_start_ts = self::local_ts( $booking['service_start'] );
			$item_end_ts   = self::local_ts( $booking['service_end'] );
			if ( $item_end_ts <= $day_start_ts || $item_start_ts >= $day_end_ts ) {
				continue;
			}
			$bookings_by_staff[ $item_staff_id ][] = $booking;
		}

		// 同時可服務人數 > 1 時，同一位人員一天內可能有時間重疊的預約，日檢視的方塊
		// 是絕對定位、只靠 top/height，重疊的話會直接疊在一起看不清楚。這裡替每一
		// 位人員的預約各自標出「第幾欄」與「當天最多同時重疊幾筆」，讓 view 用
		// left/width 百分比把重疊的方塊並排畫出來。$query_result 已經是依
		// service_start 升冪排序，是這個演算法的前提。
		foreach ( $bookings_by_staff as $staff_id_key => $staff_bookings ) {
			$bookings_by_staff[ $staff_id_key ] = self::assign_overlap_lanes( $staff_bookings );
		}

		// 時間軸固定 00:00–24:00，位置一律用「距離當天午夜幾分鐘」表示，view 再乘上
		// 每小時的固定高度（CSS 變數）換算成像素。
		//
		// 舊版是依當天實際營業範圍動態縮放、用百分比定位，兩個問題：時間軸容器與
		// 欄位容器的高度基準不一樣（一個含表頭、一個不含），百分比算出來的位置整條
		// 對不齊；而且 23 小時被壓進固定的 480px，每小時只有 21px，半小時的預約
		// 連文字都放不下。改成固定座標系之後，兩邊用同一個單位，結構上就不可能再
		// 對不齊，也不會再被壓扁。（$day_start_ts / $day_end_ts 在上面查詢區塊
		// 就已經算好，這裡沿用同一組，不重算。）

		// 每位人員當天「不在班」的區段（分鐘），view 用來畫灰底：一眼看得出誰有班。
		// 跨午夜的深夜班（例如 20:00–02:00）由 get_business_windows() 一併回傳前一天
		// 延伸過來的區間，固定 24 小時軸之後這段凌晨的班自然就畫得出來了。
		$offhours_by_staff = array();
		foreach ( $columns_staff as $staff ) {
			$offhours_by_staff[ $staff['id'] ] = self::day_offhours_minutes( $staff, $date_ymd, $day_start_ts, $day_end_ts );
		}

		// 檢視當天時才畫「現在時間」紅線。
		$now_minutes = null;
		if ( $date_ymd === current_time( 'Y-m-d' ) ) {
			$now_minutes = max( 0, min( 1440, (int) round( ( time() - $day_start_ts ) / 60 ) ) );
		}

		// 預設捲動位置：當天最早的上班時間（減 1 小時當緩衝），沒有人上班就捲到 8 點。
		$first_open_minutes = 8 * 60;
		$open_candidates    = array();
		foreach ( $columns_staff as $staff ) {
			foreach ( self::day_open_minutes( $staff, $date_ymd, $day_start_ts, $day_end_ts ) as $range ) {
				$open_candidates[] = $range[0];
			}
		}
		if ( $open_candidates ) {
			$first_open_minutes = max( 0, min( $open_candidates ) - 60 );
		}

		$prev_date = uappt_local_date( $date_ymd, '-1 day' );
		$next_date = uappt_local_date( $date_ymd, '+1 day' );
		$today     = current_time( 'Y-m-d' );

		$base_args = array(
			'page' => UAPPT_Admin::PAGE_SLUG,
			'section' => 'bookings',
			'view' => 'day',
		);
		if ( ! empty( $staff_ids ) ) {
			// add_query_arg() 對陣列值會編成 staff_ids[0]=1&staff_ids[1]=2 這種
			// 數字索引的形式，PHP 讀回 $_GET 時一樣會組成陣列，跟 staff_ids[]=1
			// 這種寫法效果相同，不用特別處理。
			$base_args['staff_ids'] = array_values( $staff_ids );
		}
		if ( $show_all ) {
			$base_args['show_all'] = 1;
		}
		if ( $hide_idle ) {
			$base_args['hide_idle'] = 1;
		}

		self::page_open( __( '預約列表', 'ultimate-appointments' ), self::manual_booking_action( $date_ymd ) );
		$this->render_booking_view_switch( 'day' );
		require UAPPT_PLUGIN_DIR . 'includes/views/calendar-day.php';
		self::page_close();
	}

	/**
	 * 一位人員在某一天「有上班」的區段，換算成「距離當天午夜幾分鐘」的 [start, end] 陣列。
	 *
	 * 直接用 UAPPT_Staff::get_business_windows()（營業時間唯一入口，見設計紀律 #7），
	 * 再把時間戳裁切進當天的 00:00–24:00 並換算成分鐘。跨午夜的深夜班會被
	 * get_business_windows() 拆成前一天延伸過來的那一段，裁切後自然落在凌晨。
	 *
	 * @param array  $staff        人員資料。
	 * @param string $date_ymd     日期 (Y-m-d)。
	 * @param int    $day_start_ts 當天 00:00 的時間戳。
	 * @param int    $day_end_ts   隔天 00:00 的時間戳。
	 * @return array 每個元素為 [start_min, end_min]，已排序且不重疊。
	 */
	protected static function day_open_minutes( $staff, $date_ymd, $day_start_ts, $day_end_ts ) {
		$ranges = array();

		foreach ( UAPPT_Staff::get_business_windows( $staff, $date_ymd ) as $window ) {
			$open  = max( $day_start_ts, (int) $window['open_ts'] );
			$close = min( $day_end_ts, (int) $window['close_ts'] );
			if ( $close <= $open ) {
				continue; // 這段完全不落在當天（例如前一天的班在午夜前就結束了）。
			}
			$ranges[] = array(
				(int) round( ( $open - $day_start_ts ) / 60 ),
				(int) round( ( $close - $day_start_ts ) / 60 ),
			);
		}

		if ( ! $ranges ) {
			return array();
		}

		usort(
			$ranges,
			function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);

		// 合併相鄰/重疊的區段，避免畫灰底時出現一條條沒必要的接縫。
		$merged = array( array_shift( $ranges ) );
		foreach ( $ranges as $range ) {
			$last = count( $merged ) - 1;
			if ( $range[0] <= $merged[ $last ][1] ) {
				$merged[ $last ][1] = max( $merged[ $last ][1], $range[1] );
				continue;
			}
			$merged[] = $range;
		}

		return $merged;
	}

	/**
	 * 一位人員在某一天「不在班」的區段（上面那支的補集），供日檢視畫灰底。
	 *
	 * @param array  $staff        人員資料。
	 * @param string $date_ymd     日期 (Y-m-d)。
	 * @param int    $day_start_ts 當天 00:00 的時間戳。
	 * @param int    $day_end_ts   隔天 00:00 的時間戳。
	 * @return array 每個元素為 [start_min, end_min]。
	 */
	protected static function day_offhours_minutes( $staff, $date_ymd, $day_start_ts, $day_end_ts ) {
		$open = self::day_open_minutes( $staff, $date_ymd, $day_start_ts, $day_end_ts );

		// 完全沒班：整天都是灰的。
		if ( ! $open ) {
			return array( array( 0, 1440 ) );
		}

		$off    = array();
		$cursor = 0;
		foreach ( $open as $range ) {
			if ( $range[0] > $cursor ) {
				$off[] = array( $cursor, $range[0] );
			}
			$cursor = max( $cursor, $range[1] );
		}
		if ( $cursor < 1440 ) {
			$off[] = array( $cursor, 1440 );
		}

		return $off;
	}

	/**
	 * 替一位人員當天的預約清單標出「第幾欄」與「當天最多同時重疊幾筆」，供日檢視
	 * 把時間重疊的預約並排畫出來（同時可服務人數 > 1 時，同一位人員可能同時有
	 * 好幾筆重疊的預約）。
	 *
	 * 標準的區間著色貪婪演算法：依開始時間由早到晚處理（呼叫端要保證傳進來的
	 * $bookings 已經照 service_start 升冪排序），每筆預約重用「目前佔用到的結束
	 * 時間最早、且不晚於這筆開始時間」的既有欄位；沒有可重用的欄位才開新的一欄。
	 * 這保證用到的欄位數量是當天實際同時重疊數的最小值，不會多開。
	 *
	 * 容量恆為 1 時，同一位人員不會有任何時間重疊的預約，每筆都會落在第 0 欄、
	 * lane_count 恆為 1，畫面跟這個功能加入前完全一樣。
	 *
	 * @param array $bookings 依 service_start 升冪排序的預約清單。
	 * @return array 同樣的清單，每筆多帶 `_lane`（int，第幾欄，從 0 開始）與
	 *               `_lane_count`（int，這批裡最多同時重疊幾筆）。
	 */
	protected static function assign_overlap_lanes( array $bookings ) {
		$lane_busy_until = array(); // 欄位索引 => 目前佔用到的 end_ts。

		foreach ( $bookings as &$booking ) {
			$start_dt = date_create( $booking['service_start'], wp_timezone() );
			$end_dt   = date_create( $booking['service_end'], wp_timezone() );
			$start_ts = $start_dt ? $start_dt->getTimestamp() : 0;
			$end_ts   = $end_dt ? $end_dt->getTimestamp() : $start_ts;

			$lane          = null;
			$best_busy_until = null;
			foreach ( $lane_busy_until as $idx => $busy_until ) {
				if ( $busy_until <= $start_ts && ( null === $best_busy_until || $busy_until < $best_busy_until ) ) {
					$lane            = $idx;
					$best_busy_until = $busy_until;
				}
			}
			if ( null === $lane ) {
				$lane = count( $lane_busy_until );
			}

			$lane_busy_until[ $lane ] = $end_ts;
			$booking['_lane']         = $lane;
		}
		unset( $booking );

		$lane_count = max( 1, count( $lane_busy_until ) );
		foreach ( $bookings as &$booking ) {
			$booking['_lane_count'] = $lane_count;
		}
		unset( $booking );

		return $bookings;
	}

	/**
	 * 時區安全地把本地時間字串轉為時間戳，供日曆時間軸計算使用。
	 *
	 * @param string $local_datetime 本地時間字串。
	 * @return int
	 */
	protected static function local_ts( $local_datetime ) {
		$dt = date_create( $local_datetime, wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}

	/* ---------------------------------------------------------------------
	 * 報表
	 * ------------------------------------------------------------------- */

	/**
	 * 報表的頁籤（v2.27.0 重組）。
	 *
	 * **依「你想問什麼」分，不是依「資料怎麼切」分。** 舊版的「每日」「每月」
	 * 是同一張表的不同時間顆粒卻各佔一個頁籤，「服務項目」又是第三個；三個
	 * 合併成「營收」（顆粒用下拉切換）之後，騰出來的位置才放得下真正不同主題
	 * 的報表。分群沿用 Fresha／Mindbody／Zenoti／Phorest 共同的骨架：
	 * 營收 · 人員 · 客人 · 預約 · 庫存。
	 *
	 * 跟設定頁的頁籤不一樣（那邊全部欄位都留在 DOM 裡只用 CSS 藏，因為儲存時
	 * 會無條件寫入每一個 option）：這裡每一頁各自查詢、各自匯出，用真正的分頁。
	 *
	 * @return array tab => label
	 */
	public static function report_tabs() {
		// ⚠️ **這一份清單就是報表的 gating。** `sanitize_report_tab()` 拿它當
		// 白名單，而**畫面與 CSV 匯出共用那一支**——所以不在清單裡的頁籤，
		// 網址硬打會退回「總覽」，`admin-post.php` 直接打匯出也一樣拿到總覽的
		// 資料。一處設定同時蓋掉模組 gating 的第 1 層（入口）與第 3 層（寫入）。
		//
		// 「總覽」「營收」「收款」永遠在：一人小店也想知道這個月做了多少、
		// 其中有多少是刷卡（被抽掉的手續費是實打實的成本）。總覽上的
		// 「客人組成」（新客／回頭客／回頭率）刻意留著——那是摘要，跟「客人」
		// 頁籤的回店預約率、回訪週期那種世代分析是不同層次的東西。
		// v2.60.0：七個頁籤收成六個，每一個回答一個明確的問題。
		//
		// 「總覽」與「營收」原本是**同一張表**——build_overview_report() 字面上
		// 就是 build_period_report(unit='day') 再加一個客人組成。使用者點過去
		// 看到幾乎一樣的東西，卻要自己想「差在哪」。合併之後顆粒切換（日／週／
		// 月）直接放在總覽上。
		//
		// 「收款」「服務項目排行」「新舊客業績」三者散在三個地方，但它們回答的
		// 是同一個問題——**這些錢是從哪裡來的**，所以併成「收入結構」。
		$tabs = array(
			'overview' => __( '總覽', 'ultimate-appointments' ),
			'income'   => __( '收入結構', 'ultimate-appointments' ),
		);

		if ( UAPPT_Modules::enabled( 'reports_team' ) ) {
			$tabs['staff'] = __( '人員', 'ultimate-appointments' );
		}

		if ( UAPPT_Modules::enabled( 'reports_insight' ) ) {
			$tabs['clients']      = __( '客人', 'ultimate-appointments' );
			$tabs['appointments'] = __( '預約', 'ultimate-appointments' );
		}

		if ( UAPPT_Modules::enabled( 'consumables' ) ) {
			$tabs['consumables'] = __( '耗材', 'ultimate-appointments' );
		}

		return $tabs;
	}

	/**
	 * 「營收」頁籤的時間顆粒。
	 *
	 * @return array unit => label
	 */
	public static function report_units() {
		return array(
			'day'   => __( '每日', 'ultimate-appointments' ),
			'week'  => __( '每週', 'ultimate-appointments' ),
			'month' => __( '每月', 'ultimate-appointments' ),
		);
	}

	/**
	 * 期間快捷鍵。舊版要自己選兩個日期才看得到東西，而九成的使用都落在這幾個
	 * 區間上。
	 *
	 * @return array preset => label
	 */
	public static function report_presets() {
		return array(
			'today'      => __( '今天', 'ultimate-appointments' ),
			'this_week'  => __( '本週', 'ultimate-appointments' ),
			'this_month' => __( '本月', 'ultimate-appointments' ),
			'last_month' => __( '上個月', 'ultimate-appointments' ),
			'last_30'    => __( '近 30 天', 'ultimate-appointments' ),
			// 「近半年」是為了回答一個兩點比較答不了的問題：**這是一次性波動
			// 還是趨勢**。本月 vs 上月只有兩個點，掉了 10% 看不出是淡季、是
			// 客人流失、還是上個月剛好有檔活動。六個月才看得出形狀。
			'last_6m'    => __( '近半年', 'ultimate-appointments' ),
			'this_year'  => __( '今年', 'ultimate-appointments' ),
		);
	}

	/**
	 * 這個快捷鍵**適合**用哪一種時間顆粒。
	 *
	 * 只是預設值，網址上明確帶了 unit 一律以那個為準。
	 *
	 * 為什麼需要：期間拉到半年、顆粒還停在「每日」的話是 180 列，等於把趨勢
	 * 藏在一片數字裡——而選「近半年」的人要看的就是趨勢。反過來看「今天」
	 * 卻給「每月」也一樣沒意義。
	 *
	 * @param string $preset 快捷鍵。
	 * @return string day／week／month
	 */
	public static function preset_default_unit( $preset ) {
		switch ( $preset ) {
			case 'last_6m':
			case 'this_year':
				return 'month';
			default:
				return 'day';
		}
	}

	/**
	 * 把快捷鍵換算成起訖日期。
	 *
	 * 全部走 `uappt_local_date()`（時區安全，設計紀律 #1），不要用裸的
	 * `strtotime()`——那會用 PHP 預設時區，跟網站設定的時區不一致時會算錯日期。
	 *
	 * @param string $preset 快捷鍵。
	 * @return array|null [$from, $to]；不認得的 preset 回傳 null。
	 */
	protected function report_preset_range( $preset ) {
		$today = current_time( 'Y-m-d' );

		switch ( $preset ) {
			case 'today':
				return array( $today, $today );
			case 'this_week':
				// 週一為一週之始，跟月曆（uappt_local_weekday_iso）同一個慣例。
				$monday = uappt_local_date( $today, '-' . ( uappt_local_weekday_iso( $today ) - 1 ) . ' days' );
				return array( $monday, $today );
			case 'this_month':
				return array( substr( $today, 0, 8 ) . '01', $today );
			case 'last_month':
				$first_of_last = uappt_local_date( substr( $today, 0, 8 ) . '01', '-1 month' );
				return array( $first_of_last, uappt_local_date( $first_of_last, 'last day of this month' ) );
			case 'last_30':
				return array( uappt_local_date( $today, '-29 days' ), $today );
			case 'last_6m':
				// 從「五個月前的 1 號」到今天＝含本月共六個完整的月份格子。
				// 不用「-180 天」：那會切在月中，每一欄的月份都只有半個月，
				// 逐月比較就失去意義了。
				return array( substr( uappt_local_date( $today, '-5 months' ), 0, 8 ) . '01', $today );
			case 'this_year':
				return array( substr( $today, 0, 4 ) . '-01-01', $today );
		}

		return null;
	}

	/**
	 * 上一期的起訖日期。
	 *
	 * 兩種規則，依本期是不是「落在同一個日曆月之內」自動選：
	 *
	 * **同一個月之內 → 上個月的同一段日期**（9/1–9/21 對 8/1–8/21）。
	 * 這一段是 v2.59.0 修的。舊版一律用「同樣天數、緊鄰在前」，看「本月」時
	 * 比的是 8/11–8/31——**月中到月底 vs 月初到月中**，而店家的生意本來就有
	 * 月內節奏（發薪日、月底衝業績）。實測同一個月：對 8/11–8/31 是 +7.1%，
	 * 對 8/1–8/21 是 +1.7%，同一個頭條數字差了四倍。老闆心裡想的是後者。
	 * 天數相同（都是 1 到 N 日），所以沒有下面那個 28／31 天的問題。
	 *
	 * **跨月或自訂區間 → 同樣天數、緊鄰在前**（維持舊行為）。
	 * 這裡刻意**不是**「往前推一個月」：月份天數不同（28～31 天），直接比
	 * 會失真——二月跟一月比永遠輸，而那跟生意好壞無關。
	 *
	 * 兩種情況呼叫端都要把實際比較的區間印在畫面上，不然使用者會以為在跟
	 * 自己心裡想的那一段比，發現數字對不上就不信任整份報表。
	 *
	 * @param string $date_from 本期起。
	 * @param string $date_to   本期迄。
	 * @return array [$from, $to]
	 */
	protected function report_previous_period( $date_from, $date_to ) {
		if ( substr( $date_from, 0, 7 ) === substr( $date_to, 0, 7 ) ) {
			return $this->same_range_previous_month( $date_from, $date_to );
		}

		$days = max( 1, $this->report_period_days( $date_from, $date_to ) );
		$to   = uappt_local_date( $date_from, '-1 day' );
		$from = uappt_local_date( $to, '-' . ( $days - 1 ) . ' days' );

		return array( $from, $to );
	}

	/**
	 * 上個月的同一段日期。
	 *
	 * ⚠️ **不能用 strtotime('-1 month')**：它對月底是「加減月份數字再正規化」，
	 * 3/31 減一個月會變成 3/3（因為 2/31 不存在，往後溢位到三月），而那一天
	 * 既不在上個月、也不是使用者想比的日子。這裡改成先算出上個月是哪個月，
	 * 再把日數夾在那個月的最後一天以內。
	 *
	 * @param string $date_from 本期起（Y-m-d）。
	 * @param string $date_to   本期迄（Y-m-d，與起始同月）。
	 * @return array [$from, $to]
	 */
	protected function same_range_previous_month( $date_from, $date_to ) {
		$year  = (int) substr( $date_from, 0, 4 );
		$month = (int) substr( $date_from, 5, 2 );

		$month--;
		if ( $month < 1 ) {
			$month = 12;
			$year--;
		}

		// 那個月有幾天。cal_days_in_month() 要 calendar 擴充，不保證有，所以
		// 用 't' 格式（PHP 核心，永遠可用）。
		$last = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );

		// 以**結束日**為錨點往回數，不是兩端各自夾。
		// 兩端各自夾的話，3/29–3/31（3 天）對上只有 28 天的二月會變成
		// 2/28–2/28，只剩一天——拿 3 天的業績去比 1 天，三月當然大勝，
		// 而那個「成長」完全是假的。以結束日為錨點則得到 2/26–2/28，
		// 天數一致。錨點選結束日而不是起始日，是因為使用者在意的是
		// 「到目前為止」，那一端才是他心裡對齊的位置。
		$length   = max( 1, $this->report_period_days( $date_from, $date_to ) );
		$to_day   = min( (int) substr( $date_to, 8, 2 ), $last );
		$from_day = max( 1, $to_day - $length + 1 );

		return array(
			sprintf( '%04d-%02d-%02d', $year, $month, $from_day ),
			sprintf( '%04d-%02d-%02d', $year, $month, $to_day ),
		);
	}

	/**
	 * 把網址上的 tab 收斂成合法值。
	 *
	 * @param string $raw 原始值。
	 * @return string
	 */
	protected static function sanitize_report_tab( $raw ) {
		$tab = sanitize_key( (string) $raw );

		// 舊網址對照。v2.60.0 把七個頁籤收成六個，但使用者的書籤、寄出去的
		// 連結、還有自動排程抓的 CSV 匯出網址都還帶著舊值。**不能讓它們默默
		// 退回「總覽」**——那會變成「連結沒壞但跑錯地方」，最難查。
		$legacy = array(
			'revenue'  => 'overview', // 營收 → 併進總覽（顆粒切換跟著過去）
			'payments' => 'income',   // 收款 → 併進收入結構
			'services' => 'income',   // 更早的「服務項目」頁籤，v2.25 就併掉了
		);
		if ( isset( $legacy[ $tab ] ) ) {
			$tab = $legacy[ $tab ];
		}

		$tabs = self::report_tabs();
		return isset( $tabs[ $tab ] ) ? $tab : 'overview';
	}

	/**
	 * 報表期間的上限（天）。
	 *
	 * 時段利用率的分母是逐日呼叫 `get_business_windows()` 算出來的（營業時間
	 * 唯一入口，設計紀律 #7），期間 × 人數就是要跑幾次。一年份 × 10 位人員是
	 * 3650 次，已經是這個查詢合理的上限；再拉長只會讓頁面轉圈圈，而那種跨年
	 * 度的分析本來就該匯出 CSV 自己算。
	 */
	const REPORT_MAX_DAYS = 366;

	/**
	 * 輸出報表頁。
	 */
	public function render_reports_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		list( $date_from, $date_to, $staff_id ) = $this->parse_report_period();

		$tabs        = self::report_tabs();
		$current_tab = self::sanitize_report_tab( isset( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// 顆粒：網址上有就用它；沒有就看是哪個期間快捷鍵。選「近半年」卻給
		// 「每日」會是 180 列，趨勢被藏在一片數字裡。
		$units        = self::report_units();
		$preset_raw   = isset( $_GET['preset'] ) ? sanitize_key( wp_unslash( $_GET['preset'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$default_unit = self::preset_default_unit( $preset_raw );
		$unit         = isset( $_GET['unit'] ) && '' !== $_GET['unit'] ? sanitize_key( wp_unslash( $_GET['unit'] ) ) : $default_unit; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $units[ $unit ] ) ) {
			$unit = $default_unit;
		}

		// 排序。人員總表預設依業績由高到低——人一多的時候，「照人力資源頁的
		// 順序」就不是想看的順序了（跟「服務項目」那張表同一個道理）。
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'revenue'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = ( isset( $_GET['order'] ) && 'asc' === $_GET['order'] ) ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// 「收入結構」一頁兩張表（服務項目／收款方式），跟人員頁同一個模式用
		// $view 分流。白名單比對，認不得的一律回到預設那一張。
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $view, array( '', 'payments', 'service', 'items', 'retail', 'commission', 'no_charge' ), true ) ) {
			$view = '';
		}

		$staff_list  = UAPPT_Staff::get_all();
		$report      = $this->build_report( $current_tab, $date_from, $date_to, $staff_id, $view, $unit, $orderby, $order );
		$period_days = $this->report_period_days( $date_from, $date_to );
		$presets     = self::report_presets();
		$preset      = isset( $_GET['preset'] ) ? sanitize_key( wp_unslash( $_GET['preset'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $presets[ $preset ] ) ) {
			$preset = '';
		}

		// 人員頁的第二份資料（人員 × 項目）。只有那一頁要，不要讓其他頁籤白跑
		// 一次查詢。
		$staff_services = ( 'staff' === $current_tab )
			? $this->build_staff_service_report( $date_from, $date_to, $staff_id )
			: array( 'rows' => array(), 'columns' => array(), 'fields' => array() );

		// 未收費明細：同樣只有人員頁要。
		$staff_no_charge = ( 'staff' === $current_tab )
			? $this->build_no_charge_report( $date_from, $date_to, $staff_id )
			: array( 'rows' => array(), 'columns' => array(), 'fields' => array(), 'totals' => array(), 'extra' => array() );

		// 抽成試算同樣只有人員頁要。
		$staff_commission = ( 'staff' === $current_tab )
			? $this->build_commission_report( $date_from, $date_to, $staff_id )
			: array( 'rows' => array(), 'columns' => array(), 'fields' => array(), 'totals' => array(), 'extra' => array() );

		// 期間比較。預設開——沒有基準的數字看不出好壞，那正是這一版要解決的事。
		// 關掉的話可以省下一整組查詢（含最貴的逐日利用率分母）。
		$compare  = ! isset( $_GET['compare'] ) || '0' !== $_GET['compare']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$previous = array( 'from' => '', 'to' => '', 'totals' => array() );
		if ( $compare ) {
			list( $prev_from, $prev_to ) = $this->report_previous_period( $date_from, $date_to );
			$previous = array(
				'from'   => $prev_from,
				'to'     => $prev_to,
				'totals' => $this->build_overall_totals( $prev_from, $prev_to, $staff_id ),
			);
		}

		$export_args = array(
			'action'    => 'uappt_export_report',
			'tab'       => $current_tab,
			'unit'      => $unit,
			'orderby'   => $orderby,
			'order'     => $order,
			'date_from' => $date_from,
			'date_to'   => $date_to,
			'staff_id'  => $staff_id,
			// 收入結構要帶著「現在看的是哪一張表」，不然畫面在看收款方式、
			// 按匯出卻拿到服務項目那一份。
			'view'      => $view,
		);
		$export_url  = wp_nonce_url( add_query_arg( $export_args, admin_url( 'admin-post.php' ) ), 'uappt_export_report' );

		// 一鍵匯出全部：只需要期間、人員與顆粒，頁籤與 view 由 manifest 決定。
		$export_url_all = wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'uappt_export_report_all',
					'unit'      => $unit,
					'date_from' => $date_from,
					'date_to'   => $date_to,
					'staff_id'  => $staff_id,
				),
				admin_url( 'admin-post.php' )
			),
			'uappt_export_report_all'
		);
		// 人員頁的第二個匯出連結（人員 × 項目）。
		$export_url_service = wp_nonce_url(
			add_query_arg( array_merge( $export_args, array( 'view' => 'service' ) ), admin_url( 'admin-post.php' ) ),
			'uappt_export_report'
		);

		self::page_open( __( '報表', 'ultimate-appointments' ) );
		require UAPPT_PLUGIN_DIR . 'includes/views/reports.php';
		self::page_close();
	}

	/**
	 * 這段期間「全部報表」的清單：檔名 => [頁籤, view, 顆粒]。
	 *
	 * 月結時要一個一個頁籤點過去、下載六到十二個檔案，而且每次還要記得哪些
	 * 子頁籤（拆解方式、人員 × 項目、抽成試算…）也有自己的資料。這支把那件事
	 * 收成一次。
	 *
	 * **順序與編號刻意寫進檔名**：解壓縮之後照字母排序就是報表的閱讀順序，
	 * 不然十二個中文檔名散在資料夾裡，看的人得自己重建脈絡。
	 *
	 * ⚠️ 只列**目前啟用的頁籤**（report_tabs() 已經做了模組 gating）——模組
	 * 關著的頁籤在畫面上看不到，匯出卻多出一個檔案會很莫名。
	 *
	 * @param string $unit 總覽要用的時間顆粒。
	 * @return array<string, array{tab:string, view:string, unit:string}>
	 */
	protected function report_export_manifest( $unit ) {
		$tabs  = self::report_tabs();
		$units = self::report_units();
		$unit_label = isset( $units[ $unit ] ) ? $units[ $unit ] : $units['day'];

		$files = array();
		$n     = 0;

		$add = function ( $label, $tab, $view = '', $u = 'day' ) use ( &$files, &$n ) {
			$n++;
			// 檔名不能有 / \ : * ? " < > |（Windows 與 macOS 的禁用字元），
			// 中文與空白沒問題。編號補零才會照數字排序（10 排在 9 後面）。
			$safe = preg_replace( '#[/\\\\:*?"<>|]+#', '-', $label );
			$files[ sprintf( '%02d-%s.csv', $n, $safe ) ] = array(
				'tab'  => $tab,
				'view' => $view,
				'unit' => $u,
			);
		};

		if ( isset( $tabs['overview'] ) ) {
			/* translators: %s: 時間顆粒（每日／每週／每月） */
			$add( sprintf( __( '總覽-%s', 'ultimate-appointments' ), $unit_label ), 'overview', '', $unit );
		}

		if ( isset( $tabs['income'] ) ) {
			foreach ( self::income_views() as $view => $label ) {
				$add( __( '收入結構', 'ultimate-appointments' ) . '-' . $label, 'income', $view );
			}
		}

		if ( isset( $tabs['staff'] ) ) {
			$add( __( '人員-總表', 'ultimate-appointments' ), 'staff' );
			$add( __( '人員-人員與服務項目', 'ultimate-appointments' ), 'staff', 'service' );
			$add( __( '人員-抽成試算', 'ultimate-appointments' ), 'staff', 'commission' );
			$add( __( '人員-未收費明細', 'ultimate-appointments' ), 'staff', 'no_charge' );
		}

		if ( isset( $tabs['clients'] ) ) {
			$add( __( '客人-消費貢獻排行', 'ultimate-appointments' ), 'clients' );
		}

		if ( isset( $tabs['appointments'] ) ) {
			$add( __( '預約-時段熱度', 'ultimate-appointments' ), 'appointments' );
		}

		if ( isset( $tabs['consumables'] ) ) {
			$add( __( '耗材', 'ultimate-appointments' ), 'consumables' );
		}

		return $files;
	}

	/**
	 * 一次匯出這段期間的所有報表。
	 *
	 * 有 ZipArchive 就打包成 zip（一個檔案一份報表，各自能直接丟進 Excel）；
	 * 沒有就退回「一個 CSV、各段之間空一行並加標題」。**不引入任何函式庫**
	 * ——產 .xlsx 要拉一整包 PhpSpreadsheet（約 10MB），為了一個月用一次的
	 * 功能讓外掛胖一圈不划算。
	 *
	 * 期間、人員篩選、顆粒都沿用畫面上目前的設定（跟單張匯出同一組參數）。
	 */
	public function handle_export_report_all() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_export_report_all' );

		list( $date_from, $date_to, $staff_id ) = $this->parse_report_period();

		$units = self::report_units();
		$unit  = isset( $_GET['unit'] ) ? sanitize_key( wp_unslash( $_GET['unit'] ) ) : 'day'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $units[ $unit ] ) ) {
			$unit = 'day';
		}

		$manifest = $this->report_export_manifest( $unit );

		// 十幾份報表一次跑完，預設的 30 秒對資料量大的站台不夠。只延長這一次
		// 請求，不動全站設定。
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$suffix = $this->export_filename_suffix( $staff_id, $date_from, $date_to );

		if ( class_exists( 'ZipArchive' ) ) {
			$this->stream_report_zip( $manifest, $date_from, $date_to, $staff_id, $suffix );
		}

		$this->stream_report_combined_csv( $manifest, $date_from, $date_to, $staff_id, $suffix );
	}

	/**
	 * 匯出檔名的共同後綴：篩選條件 ＋ 期間。
	 *
	 * @param int    $staff_id  篩選人員。
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @return array{readable:string, ascii:string}
	 */
	protected function export_filename_suffix( $staff_id, $date_from, $date_to ) {
		$readable = '';
		$ascii    = '';

		if ( $staff_id ) {
			$staff = UAPPT_Staff::get( $staff_id );
			$name  = $staff ? trim( $staff['name'] ) : '';
			$name  = '' !== $name ? preg_replace( '#[/\\\\:*?"<>|]+#', '-', $name ) : (string) $staff_id;
			$readable = '-staff-' . $name;
			$ascii    = '-staff-' . $staff_id;
		}

		$tail = '-' . $date_from . '_' . $date_to;

		return array(
			'readable' => $readable . $tail,
			'ascii'    => $ascii . $tail,
		);
	}

	/**
	 * 打包成 zip 並輸出。
	 *
	 * @param array  $manifest  檔名 => 報表參數。
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @param array  $suffix    檔名後綴。
	 */
	protected function stream_report_zip( array $manifest, $date_from, $date_to, $staff_id, array $suffix ) {
		// 用 WordPress 的暫存目錄，不是 sys_get_temp_dir()：有些主機把 PHP 的
		// 暫存目錄設成唯讀，而 WP 的上傳目錄一定可寫（不可寫的話整個媒體庫
		// 也壞了，那是更早就會被發現的問題）。
		$tmp = wp_tempnam( 'uappt-reports' );
		if ( ! $tmp ) {
			$this->stream_report_combined_csv( $manifest, $date_from, $date_to, $staff_id, $suffix );
			return;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->stream_report_combined_csv( $manifest, $date_from, $date_to, $staff_id, $suffix );
			return;
		}

		foreach ( $manifest as $filename => $args ) {
			$report = $this->build_report( $args['tab'], $date_from, $date_to, $staff_id, $args['view'], $args['unit'], 'revenue', 'desc' );
			$zip->addFromString( $filename, $this->report_to_csv_string( $report ) );
		}

		// 一張說明：解壓縮之後只看到十二個 CSV，三個月後沒有人記得那是哪一段
		// 期間、有沒有套人員篩選。
		$zip->addFromString( '00-說明.txt', $this->export_readme( $date_from, $date_to, $staff_id, array_keys( $manifest ) ) );
		$zip->close();

		$body = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Length: ' . strlen( $body ) );
		$this->send_filename_header( 'uappt-reports' . $suffix['readable'] . '.zip', 'uappt-reports' . $suffix['ascii'] . '.zip' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * 沒有 ZipArchive 時的退路：一個 CSV，每一份報表一段，段與段之間空一行。
	 *
	 * 這個格式在 Excel 裡不完美（各段欄數不同會錯位），但它至少讓資料出得來，
	 * 而且不需要任何額外的相依。畫面上會說明目前走的是哪一種。
	 *
	 * @param array  $manifest  檔名 => 報表參數。
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @param array  $suffix    檔名後綴。
	 */
	protected function stream_report_combined_csv( array $manifest, $date_from, $date_to, $staff_id, array $suffix ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		$this->send_filename_header( 'uappt-reports' . $suffix['readable'] . '.csv', 'uappt-reports' . $suffix['ascii'] . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );

		foreach ( $manifest as $filename => $args ) {
			// 段落標題：去掉編號與副檔名，留下看得懂的名字。
			$title  = preg_replace( '/^\d+-|\.csv$/', '', $filename );
			$report = $this->build_report( $args['tab'], $date_from, $date_to, $staff_id, $args['view'], $args['unit'], 'revenue', 'desc' );

			fputcsv( $out, array( '### ' . $title ) );
			fwrite( $out, $this->report_to_csv_string( $report, false ) );
			fputcsv( $out, array() );
		}

		fclose( $out );
		exit;
	}

	/**
	 * 一份報表 → CSV 字串（標題列 ＋ 資料列 ＋ 合計列）。
	 *
	 * 跟單張匯出共用同一套規則：比率欄位的標題補「（比率）」、值維持小數、
	 * 合計列在最後。抽出來才不會兩個入口的格式微妙地不一樣。
	 *
	 * @param array $report 報表。
	 * @param bool  $bom    要不要加 UTF-8 BOM（zip 內的每一個檔案都要，合併
	 *                      CSV 只在整個檔案開頭加一次）。
	 * @return string
	 */
	protected function report_to_csv_string( array $report, $bom = true ) {
		$fh = fopen( 'php://temp', 'r+' );

		if ( $bom ) {
			fwrite( $fh, "\xEF\xBB\xBF" );
		}

		$rate_fields = self::report_rate_fields();
		$headers     = array();
		foreach ( $report['columns'] as $i => $label ) {
			$field     = isset( $report['fields'][ $i ] ) ? $report['fields'][ $i ] : '';
			$headers[] = in_array( $field, $rate_fields, true )
				? $label . __( '（比率）', 'ultimate-appointments' )
				: $label;
		}
		fputcsv( $fh, $headers );

		foreach ( $report['rows'] as $row ) {
			fputcsv( $fh, self::report_csv_line( $row, $report['fields'] ) );
		}

		if ( ! empty( $report['totals'] ) ) {
			$line    = self::report_csv_line( $report['totals'], $report['fields'] );
			$line[0] = __( '合計', 'ultimate-appointments' );
			fputcsv( $fh, $line );
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );

		return $csv;
	}

	/**
	 * zip 裡那張說明檔。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @param array  $filenames 收錄了哪些檔案。
	 * @return string
	 */
	protected function export_readme( $date_from, $date_to, $staff_id, array $filenames ) {
		$staff = $staff_id ? UAPPT_Staff::get( $staff_id ) : null;

		$lines = array(
			__( '終極預約 報表匯出', 'ultimate-appointments' ),
			'',
			sprintf( /* translators: 1: 起始日期 2: 結束日期 */ __( '統計區間：%1$s ～ %2$s', 'ultimate-appointments' ), $date_from, $date_to ),
			sprintf( /* translators: %s: 人員名稱或「所有人員」 */ __( '人員篩選：%s', 'ultimate-appointments' ), $staff ? $staff['name'] : __( '所有人員', 'ultimate-appointments' ) ),
			sprintf( /* translators: %s: 匯出時間 */ __( '匯出時間：%s', 'ultimate-appointments' ), current_time( 'Y-m-d H:i' ) ),
			'',
			__( '檔案：', 'ultimate-appointments' ),
		);

		foreach ( $filenames as $filename ) {
			$lines[] = '  ' . $filename;
		}

		$lines[] = '';
		$lines[] = __( '注意事項：', 'ultimate-appointments' );
		$lines[] = __( '- 除了「零售商品」之外，所有報表都以「服務日期」為準；零售是以訂單建立日期為準，兩者不能直接相加。', 'ultimate-appointments' );
		if ( $staff ) {
			// 篩了人員卻拿到全店的零售數字，是最容易被誤用的一份。
			$lines[] = __( '- ⚠️ 這次匯出有套用人員篩選，但「零售商品」那一份**不受篩選影響**（商品賣給誰算誰的業績目前沒有依據），它永遠是全店的數字。', 'ultimate-appointments' );
		}
		$lines[] = __( '- 「抽成試算」以整個日曆月計算級距，所以它的業績是整月的數字，跟上面的統計區間不一定相同。', 'ultimate-appointments' );
		$lines[] = __( '- 標「（比率）」的欄位是 0～1 的小數，不是百分比。', 'ultimate-appointments' );
		$lines[] = __( '- 「不重複客人」的合計不等於各列相加（同一位客人來三天，逐列會算三次，合計是去重後的真正人數）。', 'ultimate-appointments' );

		return implode( "\r\n", $lines );
	}

	/**
	 * 送出 Content-Disposition。
	 *
	 * 檔名有中文時只靠 filename= 在部分瀏覽器會亂碼或被截掉；filename*=UTF-8''
	 * 是 RFC 6266 的作法，兩個都給，舊的當退路。
	 *
	 * @param string $readable 給人看的檔名（可含中文）。
	 * @param string $fallback 純 ASCII 的退路檔名。
	 */
	protected function send_filename_header( $readable, $fallback ) {
		header( 'Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode( $readable ) );
	}

	/**
	 * 匯出報表 CSV。跟畫面共用同一份期間解析與同一支 `build_report()`——
	 * **畫面上看到的數字跟匯出檔案裡的數字必須是同一套邏輯算出來的**，
	 * 不能各自維護一份。六個頁籤各自匯出自己那一份。
	 */
	public function handle_export_report() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_export_report' );

		list( $date_from, $date_to, $staff_id ) = $this->parse_report_period();

		$tab   = self::sanitize_report_tab( isset( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : '' );
		$view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$units = self::report_units();
		$unit  = isset( $_GET['unit'] ) ? sanitize_key( wp_unslash( $_GET['unit'] ) ) : 'day';
		if ( ! isset( $units[ $unit ] ) ) {
			$unit = 'day';
		}

		// 排序也要帶過來：畫面上排好序再匯出，結果卻是另一個順序，那會讓人
		// 以為匯出的是別份資料。
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'revenue';
		$order   = ( isset( $_GET['order'] ) && 'asc' === $_GET['order'] ) ? 'asc' : 'desc';

		$report = $this->build_report( $tab, $date_from, $date_to, $staff_id, $view, $unit, $orderby, $order );

		// 檔名要看得出是哪一份：同一個頁籤可能有兩個匯出（人員總表 vs 人員×項目），
		// 顆粒不同的營收表也該分得開，不然下載資料夾裡三個檔名一模一樣。
		$slug = $tab . ( $view ? '-' . $view : '' ) . ( 'overview' === $tab ? '-' . $unit : '' );

		// ⚠️ **篩選條件也要進檔名。** 人員篩選確實有套用在匯出上（staff_id 一路
		// 從網址帶進 parse_report_period()），但檔名跟內容都看不出來——「全部
		// 人員」與「只看某一位」匯出的兩個檔案，檔名一模一樣、長得也一樣，
		// 放在下載資料夾裡一個禮拜之後沒有人分得出哪個是哪個。
		// 後綴的組法跟「一鍵匯出全部」共用（見 export_filename_suffix()）。
		$suffix = $this->export_filename_suffix( $staff_id, $date_from, $date_to );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		$this->send_filename_header(
			'uappt-report-' . $slug . $suffix['readable'] . '.csv',
			'uappt-report-' . $slug . $suffix['ascii'] . '.csv'
		);

		// 標題列、資料列、合計列的組法跟「一鍵匯出全部」共用同一支，不然兩個
		// 入口的格式遲早會微妙地不一樣（比率欄位補不補單位、有沒有合計列…）。
		// UTF-8 BOM 也在那支裡：Excel 開啟含中文的 CSV 沒有它會亂碼。
		echo $this->report_to_csv_string( $report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * 把一列報表資料轉成 CSV 的一行。
	 *
	 * 資料列與合計列共用，免得兩邊的取值與四捨五入方式微妙地不一樣。
	 *
	 * @param array $row    資料列。
	 * @param array $fields 欄位順序。
	 * @return array
	 */
	protected static function report_csv_line( array $row, array $fields ) {
		$line = array();
		foreach ( $fields as $field ) {
			$value = isset( $row[ $field ] ) ? $row[ $field ] : '';
			// CSV 要的是可以直接算的原始數字，不是畫面上那種 "68.4%"。
			// 比率欄位在畫面上會被格式化，這裡維持小數。
			$line[] = is_float( $value ) ? round( $value, 4 ) : $value;
		}
		return $line;
	}

	/**
	 * 期間天數（含頭尾）。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @return int
	 */
	protected function report_period_days( $date_from, $date_to ) {
		$from = date_create( $date_from, wp_timezone() );
		$to   = date_create( $date_to, wp_timezone() );
		if ( ! $from || ! $to ) {
			return 0;
		}
		return (int) $from->diff( $to )->days + 1;
	}

	/**
	 * 產生某一個頁籤要的資料。**畫面與 CSV 的唯一入口。**
	 *
	 * 回傳裡的 `columns`／`fields` 是給 CSV 用的（欄位標題與對應的 key），
	 * 畫面則直接讀 `rows` 自己排版——兩邊的「數字」來自同一份 `rows`，只是
	 * 呈現方式不同，這是刻意的：欄位標題與資料 key 放在一起，日後加欄位
	 * 不會漏掉 CSV。
	 *
	 * @param string $tab       頁籤。
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @param string $view      同一個頁籤裡的第二份資料（目前只有人員頁的 'service'）。
	 * @param string $unit      「營收」頁籤的時間顆粒：day／week／month。
	 * @param string $orderby   排序欄位（目前只有人員總表吃這個）。
	 * @param string $order     asc／desc。
	 * @return array ['rows','columns','fields','totals','extra']
	 */
	protected function build_report( $tab, $date_from, $date_to, $staff_id = 0, $view = '', $unit = 'day', $orderby = '', $order = 'desc' ) {
		switch ( $tab ) {
			case 'income':
				$report = $this->build_income_report( $date_from, $date_to, $staff_id, $view );
				break;
			case 'clients':
				$report = $this->build_clients_report( $date_from, $date_to, $staff_id );
				break;
			case 'appointments':
				$report = $this->build_appointments_report( $date_from, $date_to, $staff_id );
				break;
			case 'consumables':
				$report = $this->build_consumable_report( $date_from, $date_to );
				break;
			case 'staff':
				// 人員頁有兩份資料：績效總表與人員 × 項目。兩者各自匯出，用
				// $view 分流——同一個頁籤兩個 CSV，不要硬塞成一份。
				if ( 'service' === $view ) {
					$report = $this->build_staff_service_report( $date_from, $date_to, $staff_id );
				} elseif ( 'commission' === $view ) {
					$report = $this->build_commission_report( $date_from, $date_to, $staff_id );
				} elseif ( 'no_charge' === $view ) {
					$report = $this->build_no_charge_report( $date_from, $date_to, $staff_id );
				} else {
					$report = $this->build_staff_report( $date_from, $date_to, $staff_id, $orderby, $order );
				}
				break;
			default:
				$report = $this->build_overview_report( $date_from, $date_to, $staff_id, $unit );
				break;
		}

		if ( ! empty( $report['totals'] ) ) {
			$report['totals'] = $this->normalize_period_totals( $report['totals'], $date_from, $date_to, $staff_id );
		}

		return $report;
	}

	/**
	 * 把「期間層級」的總計修正成不隨分組方式改變的值。
	 *
	 * ⚠️ 這一步是 v2.59.0 補的，修掉一個從很早就存在、而且**每一個頁籤答案
	 * 都不一樣**的錯誤。同一段期間、同一批資料實測：
	 *
	 * | 頁籤 | 不重複客人 | 材料成本 | 淨毛利 |
	 * |------|-----------|---------|--------|
	 * | 總覽 | 51        | 17,249  | 299,292 |
	 * | 營收 | **186**   | 17,249  | 299,292 |
	 * | 人員 | **100**   | 17,249  | 299,292 |
	 * | 收款 | **120**   | **0**   | **316,540** |
	 *
	 * 原因是各 builder 的 totals 都是「把每一列加起來」——那對筆數與金額
	 * 成立，對**去重過的數量**不成立（同一位客人來三天會被算三次），對
	 * 「這個 builder 根本沒查的東西」（收款頁不算材料成本）也不成立。
	 *
	 * 總計是**期間的屬性，不是分組方式的屬性**，所以統一在這裡重算一次，
	 * 而不是叫七個 builder 各自記得處理。代價是每次產表多兩個查詢。
	 *
	 * 比率欄位（平均客單價、未到率）不用動：它們的分子分母都是可加總的。
	 *
	 * @param array  $totals    builder 算出來的總計。
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @return array
	 */
	protected function normalize_period_totals( array $totals, $date_from, $date_to, $staff_id ) {
		if ( isset( $totals['customer_count'] ) ) {
			$totals['customer_count'] = UAPPT_Booking::count_unique_customers( $date_from, $date_to, $staff_id );
		}

		// ⚠️ 用 op_count 而不是 revenue 當判斷依據：「依零售商品」那份 totals
		// 也有 revenue，但那是**商品**的銷售額、時間軸還不一樣，替它算服務的
		// 材料成本與毛利只會生出一組沒有意義的數字。op_count 只有預約導出的
		// 統計才有。
		if ( isset( $totals['revenue'], $totals['op_count'] ) ) {
			$costs = UAPPT_Consumable::get_cost_by_period( $date_from, $date_to, $staff_id, 'all' );
			$cost  = isset( $costs['0'] ) ? (float) $costs['0'] : 0.0;
			$fee   = isset( $totals['fee'] ) ? (float) $totals['fee'] : 0.0;

			$totals['material_cost'] = $cost;
			$totals['gross_profit']  = $totals['revenue'] - $cost;
			$totals['net_revenue']   = $totals['revenue'] - $fee;
			$totals['net_profit']    = $totals['revenue'] - $cost - $fee;
		}

		return $totals;
	}

	/**
	 * 整段期間的一組總計（不分組）。
	 *
	 * 抽出來是因為**期間比較要對上一期再算一次**，而「總計」跟「逐列」是兩回事：
	 * 比較只需要總計，逐列的部分（以及最貴的逐日利用率分母）不必為了比較跑第二遍
	 * ——只有這一支會為了上一期再跑一次。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @return array
	 */
	protected function build_overall_totals( $date_from, $date_to, $staff_id = 0 ) {
		$overall = UAPPT_Booking::get_report_stats(
			array(
				'date_from' => $date_from,
				'date_to'   => $date_to,
				'staff_id'  => $staff_id,
				'group_by'  => 'all',
			)
		);
		$stats = isset( $overall['0'] ) ? $overall['0'] : UAPPT_Booking::empty_report_stats();

		$costs = UAPPT_Consumable::get_cost_by_period( $date_from, $date_to, $staff_id, 'all' );
		$cost  = isset( $costs['0'] ) ? $costs['0'] : 0.0;

		$capacity  = $this->report_capacity_by_period( $date_from, $date_to, $staff_id, 'all' );
		$available = isset( $capacity['0'] ) ? $capacity['0'] : 0.0;

		$totals                = $this->decorate_report_row( $stats, $cost );
		$totals['utilization'] = $available > 0 ? min( 1, $stats['busy_minutes'] / $available ) : 0.0;

		return $totals;
	}

	/**
	 * 把一筆聚合統計補上那些「要除一下才有」的衍生欄位。
	 *
	 * 六個頁籤都要算同樣的平均客單價、未到率、毛利，抽出來才不會六份各自
	 * 微妙地不一樣。
	 *
	 * @param array $stats         `UAPPT_Booking::get_report_stats()` 的一列。
	 * @param float $material_cost 這一格的材料成本。
	 * @return array
	 */
	protected function decorate_report_row( $stats, $material_cost = 0.0 ) {
		// 平均客單價與未到率的分母都用「已經有最終結果的筆數」（服務筆數 +
		// 未到筆數），不是單純的服務筆數——未到的那幾筆通常也已經收到錢，
		// 把它們排除在分母外，平均客單價會被墊高到不合理的數字。
		$finalized = $stats['service_count'] + $stats['no_show_count'];

		return array_merge(
			$stats,
			array(
				'op_count'      => $finalized,
				'avg_price'     => $finalized > 0 ? $stats['revenue'] / $finalized : 0.0,
				'no_show_rate'  => $finalized > 0 ? $stats['no_show_count'] / $finalized : 0.0,
				'request_rate'  => $finalized > 0 ? $stats['requested_count'] / $finalized : 0.0,
				'material_cost' => (float) $material_cost,
				'gross_profit'  => $stats['revenue'] - (float) $material_cost,
				// 金流手續費與扣掉它之後的兩個數字（v2.58.0）。
				//
				// ⚠️ **「毛利」的定義刻意不動**，另外並列「淨毛利」。毛利是既有
				// 欄位，已經出現在過去每一份匯出的 CSV 裡；偷偷改掉它的算法會讓
				// 這個月的報表跟上個月印出來的那份對不上，而且沒有人看得出是為
				// 什麼。要看扣掉手續費之後的數字就看 net_profit。
				'fee'           => isset( $stats['fee'] ) ? (float) $stats['fee'] : 0.0,
				'net_revenue'   => $stats['revenue'] - ( isset( $stats['fee'] ) ? (float) $stats['fee'] : 0.0 ),
				'net_profit'    => $stats['revenue'] - (float) $material_cost - ( isset( $stats['fee'] ) ? (float) $stats['fee'] : 0.0 ),
			)
		);
	}

	/**
	 * 「每日」與「每月」：一天／一個月一列。
	 *
	 * **沒有資料的日子也要印出來**（補零），不然畫面上會變成「9/1、9/3、9/8」
	 * 這種跳號清單，看不出中間那幾天是公休還是真的沒客人——而「哪幾天是空的」
	 * 正是這張表最有價值的資訊之一。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @param string $unit      'day'／'week'／'month'。
	 * @return array
	 */
	protected function build_period_report( $date_from, $date_to, $staff_id, $unit ) {
		$stats = UAPPT_Booking::get_report_stats(
			array(
				'date_from' => $date_from,
				'date_to'   => $date_to,
				'staff_id'  => $staff_id,
				'group_by'  => $unit,
			)
		);
		$costs = UAPPT_Consumable::get_cost_by_period( $date_from, $date_to, $staff_id, $unit );

		// 利用率的分母：先把「這段期間每一位人員的營業分鐘數」算出來，讓每一格
		// 共用同一份計算結果，不要每一列各自重跑一次逐日迴圈。
		$capacity = $this->report_capacity_by_period( $date_from, $date_to, $staff_id, $unit );

		$rows   = array();
		$keys   = $this->report_period_keys( $date_from, $date_to, $unit );
		$totals = UAPPT_Booking::empty_report_stats();
		$total_cost = 0.0;

		foreach ( $keys as $key ) {
			$row = isset( $stats[ $key ] ) ? $stats[ $key ] : UAPPT_Booking::empty_report_stats();
			$cost = isset( $costs[ $key ] ) ? $costs[ $key ] : 0.0;

			$available = isset( $capacity[ $key ] ) ? $capacity[ $key ] : 0.0;
			$decorated = $this->decorate_report_row( $row, $cost );
			$decorated['period']      = $key;
			$decorated['utilization'] = $available > 0 ? min( 1, $row['busy_minutes'] / $available ) : 0.0;

			$rows[] = $decorated;

			foreach ( UAPPT_Booking::summable_report_fields() as $field ) {
				$totals[ $field ] += $row[ $field ];
			}
			$total_cost += $cost;
		}

		// 合計列的利用率要用「總已服務分鐘 ÷ 總可用人力-分鐘」重算，不能把
		// 每一列的百分比加起來或平均——那是兩個不同分母的比率，加起來沒有意義。
		$total_available       = array_sum( $capacity );
		$decorated_totals      = $this->decorate_report_row( $totals, $total_cost );
		$decorated_totals['utilization'] = $total_available > 0 ? min( 1, $totals['busy_minutes'] / $total_available ) : 0.0;

		$columns = $this->period_report_columns( $unit );

		return array(
			'rows'    => $rows,
			'totals'  => $decorated_totals,
			'columns' => $columns['labels'],
			'fields'  => $columns['fields'],
			'extra'   => array(),
		);
	}

	/**
	 * 哪些欄位是金額。
	 *
	 * ⚠️ 這份清單原本寫在 views/partials/report-table.php 裡，而 CSV 匯出那邊
	 * 又各自判斷了一次——同一件事兩份定義。新增欄位時只改一邊，畫面會印成
	 * 「15289」而 CSV 印成「NT$15,289」（或反過來），而且不會有任何錯誤。
	 * 收攏在這裡，兩邊都來拿。
	 *
	 * @return string[]
	 */
	public static function report_money_fields() {
		return array(
			'revenue', 'avg_price', 'material_cost', 'gross_profit',
			'service_spend', 'product_spend', 'total_spend', 'avg_spend',
			'fee', 'net_revenue', 'net_profit', 'upcharge',
			'base', 'tier_amount', 'upcharge_amount', 'commission',
		);
	}

	/**
	 * 哪些欄位是比率（0～1 的小數）。
	 *
	 * 畫面上印成「68.4%」，CSV 則維持原始小數——試算表要的是可以再計算的
	 * 數字，不是字串。代價是 CSV 裡看到 `0.1314` 不知道單位，所以匯出時會
	 * 在**標題**補上「（比率）」；畫面上不補，那邊的值自己就帶著 % 了。
	 *
	 * @return string[]
	 */
	public static function report_rate_fields() {
		return array( 'no_show_rate', 'request_rate', 'utilization', 'revenue_share', 'staff_share', 'fee_rate', 'tier_rate' );
	}

	/**
	 * 「總覽」與「營收」那張表的欄位。
	 *
	 * **順序照損益表走**：業績 → 扣材料成本 → 毛利 → 扣手續費 → 淨毛利，
	 * 錢的部分一路推導下來擺在左邊（也就是手機版凍結欄的正右邊，不滑動就
	 * 看得到），量的部分（筆數、客人數、利用率）往右擺。舊版是把材料成本與
	 * 毛利夾在平均客單價與利用率中間，讀起來沒有一條線。
	 *
	 * **成本相關的欄位會依實際狀況增減**，理由跟卡片一樣（見 views/reports.php）：
	 * 耗材模組關著時「材料成本」永遠是 0，那個 0 會被讀成「這期間沒有耗用
	 * 任何材料」，而實情是「這個站根本沒在記耗材」——照著它算出來的毛利
	 * 等於業績，是一個看起來精確、實際上假的數字。手續費同理。
	 *
	 * ⚠️ 欄位會變動代表 **CSV 的欄數也會跟著變**。那是刻意的：匯出一整排
	 * 沒有意義的 0 比少一欄更糟。
	 *
	 * @param string $unit 時間顆粒。
	 * @return array{labels:string[], fields:string[]}
	 */
	protected function period_report_columns( $unit ) {
		$has_cost = UAPPT_Modules::enabled( 'consumables' );
		$has_fee  = UAPPT_Payment::has_fees();

		$cols = array( array( $this->report_unit_column_label( $unit ), 'period' ) );
		$cols[] = array( __( '業績', 'ultimate-appointments' ), 'revenue' );

		if ( $has_cost ) {
			$cols[] = array( __( '材料成本', 'ultimate-appointments' ), 'material_cost' );
			$cols[] = array( __( '毛利', 'ultimate-appointments' ), 'gross_profit' );
		}
		if ( $has_fee ) {
			$cols[] = array( __( '金流手續費', 'ultimate-appointments' ), 'fee' );
		}
		// 「淨毛利」只有在真的扣了什麼的時候才有意義——兩項都沒有的話，它
		// 會跟「業績」一模一樣，多一欄重複的數字。
		if ( $has_cost || $has_fee ) {
			$cols[] = array( __( '淨毛利', 'ultimate-appointments' ), 'net_profit' );
		}

		$cols[] = array( __( '操作筆數', 'ultimate-appointments' ), 'op_count' );
		$cols[] = array( __( '不重複客人', 'ultimate-appointments' ), 'customer_count' );
		$cols[] = array( __( '服務人次', 'ultimate-appointments' ), 'person_count' );
		$cols[] = array( __( '未到筆數', 'ultimate-appointments' ), 'no_show_count' );
		$cols[] = array( __( '平均客單價', 'ultimate-appointments' ), 'avg_price' );
		$cols[] = array( __( '時段利用率', 'ultimate-appointments' ), 'utilization' );

		return array(
			'labels' => array_column( $cols, 0 ),
			'fields' => array_column( $cols, 1 ),
		);
	}

	/**
	 * 期間內所有的分組鍵（含沒有資料的），照時間順序。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param string $unit      'day'／'week'／'month'。
	 * @return string[]
	 */
	protected function report_period_keys( $date_from, $date_to, $unit ) {
		$keys = array();

		if ( 'month' === $unit ) {
			$cursor = substr( $date_from, 0, 7 ) . '-01';
			$last   = substr( $date_to, 0, 7 ) . '-01';
			while ( '' !== $cursor && $cursor <= $last ) {
				$keys[] = substr( $cursor, 0, 7 );
				$cursor = uappt_local_date( $cursor, '+1 month' );
			}
			return $keys;
		}

		if ( 'week' === $unit ) {
			// 鍵是「那一週的星期一」，跟 SQL 端 WEEKDAY() 的算法對齊
			// （見 UAPPT_Booking::get_report_stats() 的 $group_sql）。期間的第一天
			// 多半不是星期一，所以要先退回它所屬那一週的星期一當起點，不然
			// 第一週會查不到對應的鍵、變成一列空白。
			$cursor = uappt_local_date( $date_from, '-' . ( uappt_local_weekday_iso( $date_from ) - 1 ) . ' days' );
			$last   = uappt_local_date( $date_to, '-' . ( uappt_local_weekday_iso( $date_to ) - 1 ) . ' days' );
			while ( '' !== $cursor && $cursor <= $last ) {
				$keys[] = $cursor;
				$cursor = uappt_local_date( $cursor, '+7 days' );
			}
			return $keys;
		}

		$cursor = $date_from;
		while ( '' !== $cursor && $cursor <= $date_to ) {
			$keys[] = $cursor;
			$cursor = uappt_local_date( $cursor, '+1 day' );
		}
		return $keys;
	}

	/**
	 * 時段利用率的分母（人力-分鐘），依分組方式攤到每一格。
	 *
	 * 逐日呼叫 `UAPPT_Staff::get_business_minutes_in_range()`（營業時間唯一
	 * 入口，設計紀律 #7）並乘上該人員的同時可服務人數。這是整張報表最貴的
	 * 計算，所以**一次算完存成陣列給所有列共用**，不要每一列各自重跑。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @param string $unit      'day'／'week'／'month'／'all'。
	 * @return array gkey => 可用人力-分鐘
	 */
	protected function report_capacity_by_period( $date_from, $date_to, $staff_id, $unit ) {
		$out    = array();
		$staffs = array();

		foreach ( UAPPT_Staff::get_all() as $staff ) {
			if ( $staff_id && (int) $staff['id'] !== $staff_id ) {
				continue;
			}
			$staffs[] = $staff;

			// ⚠️ **一定要先 prime**。底下是逐日呼叫 get_business_minutes_in_range()，
			// 而它每天都會去問一次「這一天有沒有排班例外」——沒有預熱的話就是
			// 「天數 × 人員數」次單筆查詢。實測 83 天 × 3 位人員、而且一次產表
			// 會呼叫這支四次（本期逐日、本期總計、上期各一），總共 **1,992 次**
			// 查詢只為了算利用率的分母。prime_overrides() 本來就是為了這件事寫的
			// （一位人員一次區間查詢），只是這裡一直沒有用它。
			UAPPT_Staff::prime_overrides( (int) $staff['id'], $date_from, $date_to );
		}

		$cursor = $date_from;
		while ( '' !== $cursor && $cursor <= $date_to ) {
			if ( 'month' === $unit ) {
				$key = substr( $cursor, 0, 7 );
			} elseif ( 'week' === $unit ) {
				$key = uappt_local_date( $cursor, '-' . ( uappt_local_weekday_iso( $cursor ) - 1 ) . ' days' );
			} elseif ( 'all' === $unit ) {
				$key = '0';
			} else {
				$key = $cursor;
			}
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = 0.0;
			}
			foreach ( $staffs as $staff ) {
				$out[ $key ] += UAPPT_Staff::get_business_minutes_in_range( $staff, $cursor, $cursor ) * max( 1, (int) $staff['capacity'] );
			}
			$cursor = uappt_local_date( $cursor, '+1 day' );
		}

		return $out;
	}

	/**
	 * 「人員」頁籤：一位人員一列。
	 *
	 * 停用的人員只有在這段期間**真的有資料**時才出現——不然報表會被離職很久、
	 * 這段期間根本沒服務過任何人的舊人員佔位；篩了特定人員時則無條件顯示那
	 * 一位，不管有沒有資料，篩選的意圖本來就是要看那個人，空白結果也是一種答案。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @return array
	 */
	protected function build_staff_report( $date_from, $date_to, $staff_id = 0, $orderby = 'revenue', $order = 'desc' ) {
		$stats = UAPPT_Booking::get_report_stats(
			array(
				'date_from' => $date_from,
				'date_to'   => $date_to,
				'staff_id'  => $staff_id,
				'group_by'  => 'staff',
			)
		);
		$costs = UAPPT_Consumable::get_cost_by_period( $date_from, $date_to, $staff_id, 'staff' );

		$rows            = array();
		$totals          = UAPPT_Booking::empty_report_stats();
		$total_cost      = 0.0;
		$total_available = 0.0;

		foreach ( UAPPT_Staff::get_all() as $staff ) {
			$sid = (int) $staff['id'];
			if ( $staff_id && $sid !== $staff_id ) {
				continue;
			}

			$has_data = isset( $stats[ (string) $sid ] );
			if ( 'active' !== $staff['status'] && ! $has_data && ! $staff_id ) {
				continue;
			}

			$s    = $has_data ? $stats[ (string) $sid ] : UAPPT_Booking::empty_report_stats();
			$cost = isset( $costs[ (string) $sid ] ) ? $costs[ (string) $sid ] : 0.0;

			$available        = UAPPT_Staff::get_business_minutes_in_range( $staff, $date_from, $date_to ) * max( 1, (int) $staff['capacity'] );
			$total_available += $available;

			$row = $this->decorate_report_row( $s, $cost );
			$row['staff_id']    = $sid;
			$row['staff_name']  = $staff['name'] . ( 'active' !== $staff['status'] ? __( '（已停用）', 'ultimate-appointments' ) : '' );
			$row['utilization'] = $available > 0 ? min( 1, $s['busy_minutes'] / $available ) : 0.0;

			// 異常判定要知道這位人員「有沒有班可排」——沒排班的人利用率當然是 0，
			// 那不是異常。
			$row['available_minutes'] = $available;

			$rows[] = $row;

			foreach ( UAPPT_Booking::summable_report_fields() as $field ) {
				$totals[ $field ] += $s[ $field ];
			}
			$total_cost += $cost;
		}

		// 合計列的利用率同樣要重算（見 build_period_report() 的說明）。
		$decorated_totals                = $this->decorate_report_row( $totals, $total_cost );
		$decorated_totals['utilization'] = $total_available > 0 ? min( 1, $totals['busy_minutes'] / $total_available ) : 0.0;

		$rows = $this->flag_staff_outliers( $rows, $decorated_totals );
		$rows = $this->sort_report_rows( $rows, $orderby, $order, 'staff_name' );

		$staff_columns = $this->staff_report_columns( $decorated_totals['upcharge'] > 0, $decorated_totals['no_charge_count'] > 0 );

		return array(
			'rows'    => $rows,
			'totals'  => $decorated_totals,
			'columns' => $staff_columns['labels'],
			'fields'  => $staff_columns['fields'],
			'extra'   => array(),
		);
	}

	/**
	 * 判定「利用率偏低」之前，全店利用率至少要到這個水準。
	 *
	 * 低於這個數字時整個不標——那代表全店都很閒（剛上線、淡季、或排班遠多於
	 * 需求），不是某個人特別閒。
	 */
	const OUTLIER_MIN_UTILIZATION = 0.05;

	/**
	 * 判定異常之前，該人員至少要有這麼多筆才算數。
	 *
	 * 一位人員這段期間只做了 1 筆、剛好未到，未到率就是 100%——標成異常只是
	 * 雜訊，而且會讓真正的異常被淹掉。
	 */
	const OUTLIER_MIN_SAMPLE = 5;

	/**
	 * 報表列排序（點欄位標題用）。
	 *
	 * 人員一多的時候，「照人力資源頁的順序」就不是想看的順序了——想問的是
	 * 「誰業績最高」「誰未到率異常」。用連結帶參數實作，**不引入表格套件**：
	 * 排序只要換一個 ORDER BY 等級的動作，為此多載一包 JS 不划算，而且連結
	 * 版本可以直接複製網址分享給別人。
	 *
	 * @param array  $rows     資料列。
	 * @param string $orderby  排序欄位；不在白名單裡就用 $fallback。
	 * @param string $order    'asc' 或 'desc'。
	 * @param string $fallback 找不到欄位時的預設欄位。
	 * @return array
	 */
	protected function sort_report_rows( $rows, $orderby, $order, $fallback = '' ) {
		if ( ! $rows ) {
			return $rows;
		}

		// 白名單就是「第一列真的有的欄位」——不用另外維護一份清單，加欄位時
		// 排序自動跟著支援。
		$sample = reset( $rows );
		if ( ! isset( $sample[ $orderby ] ) ) {
			$orderby = ( $fallback && isset( $sample[ $fallback ] ) ) ? $fallback : '';
		}
		if ( ! $orderby ) {
			return $rows;
		}

		$direction = ( 'asc' === strtolower( (string) $order ) ) ? 1 : -1;

		usort(
			$rows,
			function ( $a, $b ) use ( $orderby, $direction ) {
				$x = $a[ $orderby ];
				$y = $b[ $orderby ];

				// 文字欄位（人員姓名、服務項目）要照中文排序規則比，直接用
				// <=> 比字串在中文底下順序會很怪。
				if ( is_string( $x ) && ! is_numeric( $x ) ) {
					return strcoll( $x, $y ) * $direction;
				}

				return ( $x <=> $y ) * $direction;
			}
		);

		return $rows;
	}

	/**
	 * 標出「值得看一眼」的人員。
	 *
	 * 人員一多，總表就是一片數字——20 位 × 11 欄等於 220 個數字，靠人眼找異常
	 * 不切實際。這支把注意力**引過去**，而不是讓人自己找。
	 *
	 * ⚠️ **三個守衛是這支最重要的部分**，少了任何一個，標示就會變成雜訊而不是
	 * 提示——而雜訊會讓真正的異常被淹掉：
	 *
	 * 1. **樣本數**（`OUTLIER_MIN_SAMPLE`）：只做了 1 筆、剛好未到，未到率就是
	 *    100%，那不是異常
	 * 2. **有沒有班可排**：沒排班的人利用率當然是 0，那是排班的結果不是表現問題
	 * 3. **全店基準本身要有意義**（`OUTLIER_MIN_UTILIZATION`）：全店只有 3% 的
	 *    時候「某人 1%」在數學上成立，但那是全店都很閒，不是他特別閒
	 *
	 * @param array $rows   資料列。
	 * @param array $totals 全店合計（拿來當比較基準）。
	 * @return array 每一列多出 'flags'（欄位 => 樣式）與 'flag_notes'（欄位 => 說明）。
	 */
	protected function flag_staff_outliers( $rows, $totals ) {
		$avg_no_show     = isset( $totals['no_show_rate'] ) ? (float) $totals['no_show_rate'] : 0.0;
		$avg_utilization = isset( $totals['utilization'] ) ? (float) $totals['utilization'] : 0.0;

		foreach ( $rows as $index => $row ) {
			$flags = array();
			$notes = array();

			// 未到率明顯高於全店：1.5 倍以上，而且至少要有 5 筆才算數。
			if ( $row['op_count'] >= self::OUTLIER_MIN_SAMPLE && $avg_no_show > 0 && $row['no_show_rate'] > $avg_no_show * 1.5 ) {
				$flags['no_show_rate'] = 'is-flag-warn';
				$notes['no_show_rate'] = sprintf(
					/* translators: %s: 全店平均未到率 */
					__( '明顯高於全店平均（%s）', 'ultimate-appointments' ),
					round( $avg_no_show * 100, 1 ) . '%'
				);
			}

			// 利用率明顯低於全店：0.6 倍以下，而且這段期間真的有排班。
			//
			// ⚠️ **全店利用率本身太低時整個不標。** 全店只有 3% 的時候，「某人
			// 1%」在數學上是「低於平均 0.6 倍」，但那個比較沒有意義——那是全店
			// 都很閒，不是他特別閒。少了這個守衛，剛上線或淡季的店會整欄被標成
			// 異常，真正的異常反而被淹掉。
			if (
				$row['available_minutes'] > 0
				&& $avg_utilization >= self::OUTLIER_MIN_UTILIZATION
				&& $row['utilization'] < $avg_utilization * 0.6
			) {
				$flags['utilization'] = 'is-flag-warn';
				$notes['utilization'] = sprintf(
					/* translators: %s: 全店平均利用率 */
					__( '明顯低於全店平均（%s）', 'ultimate-appointments' ),
					round( $avg_utilization * 100, 1 ) . '%'
				);
			}

			$rows[ $index ]['flags']      = $flags;
			$rows[ $index ]['flag_notes'] = $notes;
		}

		return $rows;
	}

	/**
	 * 一項服務商品歸在哪個分類底下。
	 *
	 * ⚠️ **一個商品可能掛在好幾個分類**（WooCommerce 允許），但彙總表必須
	 * 「每一筆只算一次」，不然合計會超過業績本身。所以固定取**第一個**，
	 * 並且用 term_id 排序而不是靠 get_the_terms() 回傳的順序——後者會隨
	 * 站台設定與快取而變，同一份報表在不同時候可能歸到不同分類。
	 *
	 * 這個取捨要讓使用者知道：真的需要一個商品同時算進兩類，那是商品分類
	 * 設計的問題，不該由報表去猜。
	 *
	 * @param int $product_id 商品 ID（0 代表這筆沒有商品）。
	 * @return string 分類名稱；沒有分類回傳「未分類」。
	 */
	protected function service_category_name( $product_id ) {
		static $cache = array();

		$product_id = (int) $product_id;
		if ( isset( $cache[ $product_id ] ) ) {
			return $cache[ $product_id ];
		}

		$name = __( '未分類', 'ultimate-appointments' );

		if ( $product_id > 0 ) {
			$terms = get_the_terms( $product_id, 'product_cat' );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$ids = wp_list_pluck( $terms, 'term_id' );
				sort( $ids );
				$first = get_term( $ids[0], 'product_cat' );
				if ( $first && ! is_wp_error( $first ) ) {
					$name = $first->name;
				}
			}
		}

		$cache[ $product_id ] = $name;
		return $name;
	}

	/**
	 * 「收入結構 ▸ 依服務分類」：把服務項目往上收一層。
	 *
	 * 為什麼需要：項目清單長度是方案數（這個站 15 個，實際營運的店通常更多），
	 * 一整頁數字回答不了「這個月偏哪一塊」。分類這一層是 WooCommerce 現成的
	 * 資料，不用多建任何東西。
	 *
	 * 直接在 PHP 端把「依服務項目」的結果往上疊，不另外寫一句 SQL：分類是
	 * WordPress 的 taxonomy、不在預約資料表裡，硬要 JOIN 得把 term 的三張表
	 * 都接進來，而項目數本來就是幾十筆的量級，在 PHP 疊反而簡單又不會跟
	 * 「依服務項目」那張表對不起來。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @return array
	 */
	protected function build_service_category_report( $date_from, $date_to, $staff_id = 0 ) {
		$services = $this->build_service_report( $date_from, $date_to, $staff_id );

		$groups        = array();
		$total_revenue = 0.0;

		foreach ( $services['rows'] as $row ) {
			$cat = $this->service_category_name( isset( $row['product_id'] ) ? $row['product_id'] : 0 );

			if ( ! isset( $groups[ $cat ] ) ) {
				$groups[ $cat ] = UAPPT_Booking::empty_report_stats();
				$groups[ $cat ]['material_cost'] = 0.0;
			}

			foreach ( UAPPT_Booking::summable_report_fields() as $field ) {
				$groups[ $cat ][ $field ] += isset( $row[ $field ] ) ? $row[ $field ] : 0;
			}
			$groups[ $cat ]['material_cost'] += isset( $row['material_cost'] ) ? $row['material_cost'] : 0.0;

			$total_revenue += $row['revenue'];
		}

		$rows   = array();
		$totals = UAPPT_Booking::empty_report_stats();
		$cost   = 0.0;

		foreach ( $groups as $cat => $g ) {
			$decorated = $this->decorate_report_row( $g, $g['material_cost'] );
			$decorated['category_name'] = $cat;
			$decorated['revenue_share'] = $total_revenue > 0 ? $g['revenue'] / $total_revenue : 0.0;
			$rows[] = $decorated;

			foreach ( UAPPT_Booking::summable_report_fields() as $field ) {
				$totals[ $field ] += $g[ $field ];
			}
			$cost += $g['material_cost'];
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);

		$decorated_totals                  = $this->decorate_report_row( $totals, $cost );
		$decorated_totals['category_name'] = __( '合計', 'ultimate-appointments' );
		$decorated_totals['revenue_share'] = $total_revenue > 0 ? 1.0 : 0.0;

		return array(
			'rows'    => $rows,
			'totals'  => $decorated_totals,
			'columns' => array(
				__( '服務分類', 'ultimate-appointments' ),
				__( '操作筆數', 'ultimate-appointments' ),
				__( '服務人次', 'ultimate-appointments' ),
				__( '業績', 'ultimate-appointments' ),
				__( '業績佔比', 'ultimate-appointments' ),
				__( '平均客單價', 'ultimate-appointments' ),
				__( '材料成本', 'ultimate-appointments' ),
				__( '毛利', 'ultimate-appointments' ),
			),
			'fields'  => array( 'category_name', 'op_count', 'person_count', 'revenue', 'revenue_share', 'avg_price', 'material_cost', 'gross_profit' ),
			'extra'   => array(),
		);
	}

	/**
	 * 「收入結構 ▸ 依零售商品」：非預約的一般商品賣了哪些。
	 *
	 * ⚠️ 這張表跟其他所有報表**不在同一條時間軸上**：它以訂單的建立日期為準，
	 * 其餘都是以服務日期為準；而且**不吃人員篩選**。理由見
	 * UAPPT_Customer::get_retail_breakdown()。畫面上必須講清楚，不然使用者會
	 * 拿「服務業績 + 零售業績」當成當期總收入，而那兩個數字的期間定義不同。
	 *
	 * 因此這份 totals 刻意**只有數量與金額**，沒有 op_count／customer_count
	 * 那些欄位——build_report() 出口的 normalize_period_totals() 是靠 op_count
	 * 判斷「這是不是一份預約導出的統計」，沒有它就不會去動這份合計。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @return array
	 */
	protected function build_retail_report( $date_from, $date_to ) {
		$items = UAPPT_Customer::get_retail_breakdown( $date_from, $date_to );

		$total_revenue = 0.0;
		foreach ( $items as $item ) {
			$total_revenue += $item['revenue'];
		}

		$rows     = array();
		$total_qty = 0.0;
		foreach ( $items as $item ) {
			$rows[] = array(
				'retail_name'   => '' !== $item['name'] ? $item['name'] : sprintf( '#%d', $item['product_id'] ),
				'qty'           => $item['qty'],
				'revenue'       => $item['revenue'],
				'revenue_share' => $total_revenue > 0 ? $item['revenue'] / $total_revenue : 0.0,
				'avg_price'     => $item['qty'] > 0 ? $item['revenue'] / $item['qty'] : 0.0,
			);
			$total_qty += $item['qty'];
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);

		return array(
			'rows'    => $rows,
			'totals'  => array(
				'retail_name'   => __( '合計', 'ultimate-appointments' ),
				'qty'           => $total_qty,
				'revenue'       => $total_revenue,
				'revenue_share' => $total_revenue > 0 ? 1.0 : 0.0,
				'avg_price'     => $total_qty > 0 ? $total_revenue / $total_qty : 0.0,
			),
			'columns' => array(
				__( '商品', 'ultimate-appointments' ),
				__( '數量', 'ultimate-appointments' ),
				__( '銷售額', 'ultimate-appointments' ),
				__( '佔零售比', 'ultimate-appointments' ),
				__( '平均單價', 'ultimate-appointments' ),
			),
			'fields'  => array( 'retail_name', 'qty', 'revenue', 'revenue_share', 'avg_price' ),
			'extra'   => array(),
		);
	}

	/**
	 * 「收款」頁籤：依收款方式拆解業績、手續費與實收淨額。
	 *
	 * **手續費不是估算。** 特約商店與金流公司的費率是談定的固定值，而
	 * WooCommerce 核心不保存金流實際抽走的金額（綠界外掛雖然有那一欄，實測
	 * 回傳的是 0），所以一律用「金額 × 費率」自己算。準確度取決於收款方式的
	 * 顆粒度對不對得上合約——分期跟一般刷卡的費率差很多，要分開建立兩列。
	 *
	 * 費率讀的是**每筆預約自己的快照**（`bookings.fee_rate`），不是現行設定，
	 * 所以同一個「刷卡」底下可能混著幾種費率。表格因此印的是「平均費率」
	 * （手續費 ÷ 業績）而不是設定值，不然會出現「費率 2%、業績 10 萬、手續費
	 * 2,300」這種自己對不起來的一列。
	 *
	 * 這一頁刻意不算材料成本與毛利：收款方式跟成本結構沒有關係，那是「營收」
	 * 與「人員」頁在回答的問題。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @return array
	 */
	protected function build_payments_report( $date_from, $date_to, $staff_id = 0 ) {
		$stats = UAPPT_Booking::get_report_stats(
			array(
				'date_from' => $date_from,
				'date_to'   => $date_to,
				'staff_id'  => $staff_id,
				'group_by'  => 'payment',
			)
		);

		$total_revenue = 0.0;
		foreach ( $stats as $s ) {
			$total_revenue += $s['revenue'];
		}

		$rows   = array();
		$totals = UAPPT_Booking::empty_report_stats();

		foreach ( $stats as $key => $s ) {
			$row = $this->decorate_report_row( $s );
			$row['payment_name']  = UAPPT_Payment::label( (string) $key );
			$row['payment_slug']  = (string) $key;
			$row['revenue_share'] = $total_revenue > 0 ? $s['revenue'] / $total_revenue : 0.0;
			// 有效費率：實際算出來的手續費佔業績多少。業績是 0 時不能除，
			// 直接給 0——這一列本來就沒有手續費可言。
			$row['fee_rate'] = $s['revenue'] > 0 ? $s['fee'] / $s['revenue'] : 0.0;

			$rows[] = $row;

			foreach ( UAPPT_Booking::summable_report_fields() as $field ) {
				$totals[ $field ] += $s[ $field ];
			}
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);

		$decorated_totals                  = $this->decorate_report_row( $totals );
		$decorated_totals['payment_name']  = __( '合計', 'ultimate-appointments' );
		$decorated_totals['revenue_share'] = $total_revenue > 0 ? 1.0 : 0.0;
		$decorated_totals['fee_rate']      = $totals['revenue'] > 0 ? $totals['fee'] / $totals['revenue'] : 0.0;

		return array(
			'rows'    => $rows,
			'totals'  => $decorated_totals,
			'columns' => array(
				__( '收款方式', 'ultimate-appointments' ),
				__( '操作筆數', 'ultimate-appointments' ),
				__( '業績', 'ultimate-appointments' ),
				__( '業績佔比', 'ultimate-appointments' ),
				__( '平均費率', 'ultimate-appointments' ),
				__( '手續費', 'ultimate-appointments' ),
				__( '實收淨額', 'ultimate-appointments' ),
			),
			'fields'  => array( 'payment_name', 'op_count', 'revenue', 'revenue_share', 'fee_rate', 'fee', 'net_revenue' ),
			'extra'   => array(),
		);
	}

	/**
	 * 「服務項目」頁籤：一個商品／方案一列，依業績由高到低。
	 *
	 * 顯示名稱優先用預約自己存的 `plan_name` 快照（方案被改名或刪掉之後，
	 * 報表仍要說得出客人當初買的是什麼），快照是空的才回頭問商品。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @return array
	 */
	protected function build_service_report( $date_from, $date_to, $staff_id = 0 ) {
		$stats = UAPPT_Booking::get_report_stats(
			array(
				'date_from' => $date_from,
				'date_to'   => $date_to,
				'staff_id'  => $staff_id,
				'group_by'  => 'service',
			)
		);
		$costs = UAPPT_Consumable::get_cost_by_period( $date_from, $date_to, $staff_id, 'service' );

		$total_revenue = 0.0;
		foreach ( $stats as $s ) {
			$total_revenue += $s['revenue'];
		}

		$rows       = array();
		$totals     = UAPPT_Booking::empty_report_stats();
		$total_cost = 0.0;

		foreach ( $stats as $key => $s ) {
			$cost = isset( $costs[ $key ] ) ? $costs[ $key ] : 0.0;

			$row = $this->decorate_report_row( $s, $cost );
			$row['service_name']  = $this->format_service_name( $s );
			$row['revenue_share'] = $total_revenue > 0 ? $s['revenue'] / $total_revenue : 0.0;

			$rows[] = $row;

			foreach ( UAPPT_Booking::summable_report_fields() as $field ) {
				$totals[ $field ] += $s[ $field ];
			}
			$total_cost += $cost;
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);

		// 合計列的「業績佔比」補成 1（＝100%）。畫面上這一格印「—」（佔比的
		// 合計必然是 100%，印出來沒有資訊量），但 CSV 留空會變成一個空欄位，
		// 而隔壁「收款方式」那份匯出的同一欄是 1——同一個位置兩種寫法，拿去
		// 試算表串接的人得各寫一次判斷。
		$service_totals                  = $this->decorate_report_row( $totals, $total_cost );
		$service_totals['revenue_share'] = $total_revenue > 0 ? 1.0 : 0.0;

		return array(
			'rows'    => $rows,
			'totals'  => $service_totals,
			'columns' => array(
				__( '服務項目', 'ultimate-appointments' ),
				__( '操作筆數', 'ultimate-appointments' ),
				__( '服務人次', 'ultimate-appointments' ),
				__( '業績', 'ultimate-appointments' ),
				__( '業績佔比', 'ultimate-appointments' ),
				__( '平均客單價', 'ultimate-appointments' ),
				__( '材料成本', 'ultimate-appointments' ),
				__( '毛利', 'ultimate-appointments' ),
			),
			'fields'  => array( 'service_name', 'op_count', 'person_count', 'revenue', 'revenue_share', 'avg_price', 'material_cost', 'gross_profit' ),
			'extra'   => array(),
		);
	}

	/**
	 * 「營收」頁籤的第一欄標題（隨顆粒變）。
	 *
	 * @param string $unit day／week／month。
	 * @return string
	 */
	protected function report_unit_column_label( $unit ) {
		if ( 'month' === $unit ) {
			return __( '月份', 'ultimate-appointments' );
		}
		if ( 'week' === $unit ) {
			return __( '週（起始日）', 'ultimate-appointments' );
		}
		return __( '日期', 'ultimate-appointments' );
	}

	/**
	 * 「未收費／招待」明細。
	 *
	 * 這張表存在的理由很具體：**招待的單會無聲拉低那位人員的毛利**。做了服務、
	 * 材料照扣，業績卻是 0，於是那一筆的毛利是負的——而在報表上它跟其他單
	 * 混在一起，看到的人只會覺得「這位人員成本怎麼特別高」。標記原因之後，
	 * 被拉低的那一塊有了歸屬，也看得出是不是有人在濫用招待。
	 *
	 * 欄位對齊實體店家原本就在手寫的那張表（日期／姓名／療程／耗材／因素／
	 * 關係），這樣他們可以直接停用那張 Excel。
	 *
	 * ⚠️ **列出來的是「有標記原因」的預約，不是「金額為 0」的預約。** 兩者
	 * 不一樣：金額 0 也可能只是還沒填價格。沒有標記就沒有原因可言，硬把所有
	 * 0 元的單抓進來只會讓這張表變成待辦清單。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @return array
	 */
	protected function build_no_charge_report( $date_from, $date_to, $staff_id = 0 ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		$where  = array( "kind = %s", "status IN (%s, %s, %s)", "no_charge_reason <> ''", 'service_start >= %s', 'service_start <= %s', 'service_start <= %s' );
		$params = array(
			UAPPT_Booking::KIND_BOOKING,
			UAPPT_Booking::STATUS_CONFIRMED,
			UAPPT_Booking::STATUS_COMPLETED,
			UAPPT_Booking::STATUS_NO_SHOW,
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59',
			current_time( 'mysql' ),
		);
		if ( $staff_id ) {
			$where[]  = 'staff_id = %d';
			$params[] = (int) $staff_id;
		}

		$bookings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, service_start, staff_id, product_id, plan_name, customer_name,
					amount, no_charge_reason, no_charge_note
				FROM {$table}
				WHERE " . implode( ' AND ', $where ) . "
				ORDER BY service_start DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			),
			ARRAY_A
		);

		// 這幾筆各自用掉多少材料。逐筆問會是 N+1，一次撈完再對。
		$costs = array();
		if ( $bookings ) {
			$ids   = array_map( 'absint', wp_list_pluck( $bookings, 'id' ) );
			$moves = UAPPT_Install::table( 'consumable_moves' );
			$in    = implode( ',', $ids );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT booking_id, SUM( CASE WHEN type = %s THEN cost_total WHEN type = %s THEN -cost_total ELSE 0 END ) AS cost
					FROM {$moves} WHERE booking_id IN ({$in}) GROUP BY booking_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					UAPPT_Consumable::MOVE_CONSUME,
					UAPPT_Consumable::MOVE_REVERT
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$costs[ (int) $row['booking_id'] ] = (float) $row['cost'];
			}
		}

		$staff_names = array();
		foreach ( UAPPT_Staff::get_all( array( 'include_inactive' => true ) ) as $row ) {
			$staff_names[ (int) $row['id'] ] = $row['name'];
		}

		$rows       = array();
		$total_cost = 0.0;
		$total_amt  = 0.0;
		$by_reason  = array();

		foreach ( (array) $bookings as $b ) {
			$cost   = isset( $costs[ (int) $b['id'] ] ) ? $costs[ (int) $b['id'] ] : 0.0;
			$reason = UAPPT_Booking::no_charge_label( $b['no_charge_reason'] );

			$rows[] = array(
				'date'          => substr( (string) $b['service_start'], 0, 10 ),
				'customer_name' => '' !== $b['customer_name'] ? $b['customer_name'] : __( '（未留姓名）', 'ultimate-appointments' ),
				'service_name'  => $this->format_service_name( $b ),
				'staff_name'    => isset( $staff_names[ (int) $b['staff_id'] ] ) ? $staff_names[ (int) $b['staff_id'] ] : '—',
				'reason'        => $reason,
				'no_charge_note' => (string) $b['no_charge_note'],
				'amount'        => (float) $b['amount'],
				'material_cost' => $cost,
			);

			$total_cost += $cost;
			$total_amt  += (float) $b['amount'];

			if ( ! isset( $by_reason[ $reason ] ) ) {
				$by_reason[ $reason ] = array( 'count' => 0, 'cost' => 0.0 );
			}
			$by_reason[ $reason ]['count']++;
			$by_reason[ $reason ]['cost'] += $cost;
		}

		arsort( $by_reason );

		return array(
			'rows'    => $rows,
			'totals'  => $rows ? array(
				'date'          => __( '合計', 'ultimate-appointments' ),
				'amount'        => $total_amt,
				'material_cost' => $total_cost,
			) : array(),
			'columns' => array(
				__( '日期', 'ultimate-appointments' ),
				__( '客人', 'ultimate-appointments' ),
				__( '服務項目', 'ultimate-appointments' ),
				__( '人員', 'ultimate-appointments' ),
				__( '原因', 'ultimate-appointments' ),
				__( '對象／說明', 'ultimate-appointments' ),
				__( '實收', 'ultimate-appointments' ),
				__( '材料成本', 'ultimate-appointments' ),
			),
			'fields'  => array( 'date', 'customer_name', 'service_name', 'staff_name', 'reason', 'no_charge_note', 'amount', 'material_cost' ),
			'extra'   => array(
				'by_reason'  => $by_reason,
				'total_cost' => $total_cost,
			),
		);
	}

	/**
	 * 「人員抽成試算」：逐月、逐人算出抽成。
	 *
	 * ⚠️ **這是試算，不是薪資單。** 面板上會標，這裡再寫一次：它只算「業績
	 * 抽成」這一塊，沒有底薪保障、沒有全勤獎金、沒有跨月結算、沒有預扣。
	 *
	 * ⚠️ **級距以整個日曆月的業績判定**，跟報表選了哪一段期間無關（見
	 * UAPPT_Booking::get_monthly_staff_revenue()）。所以這張表的「業績」那一欄
	 * 是**整月**的數字，不會等於上方表格裡同一位人員的業績——上面那個是
	 * 「你選的期間」，這裡是「整個月」。兩個數字並排卻不相等很容易被當成
	 * bug，所以畫面上必須把月份標得很清楚。
	 *
	 * 沒有設定任何級距的人員不列——不然報表上會是一排 0，看起來像壞掉，
	 * 而實情是「這個人不抽成」或「還沒設定」。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @return array
	 */
	protected function build_commission_report( $date_from, $date_to, $staff_id = 0 ) {
		$monthly = UAPPT_Booking::get_monthly_staff_revenue( $date_from, $date_to, $staff_id );

		$staff = array();
		foreach ( UAPPT_Staff::get_all( array( 'include_inactive' => true ) ) as $row ) {
			$staff[ (int) $row['id'] ] = $row;
		}

		$this_month = substr( current_time( 'Y-m-d' ), 0, 7 );

		$rows      = array();
		$total     = 0.0;
		$has_setup = false;

		foreach ( $monthly as $ym => $per_staff ) {
			foreach ( $per_staff as $sid => $data ) {
				if ( ! isset( $staff[ $sid ] ) ) {
					continue;
				}

				$config = $staff[ $sid ]['commission'];
				if ( empty( $config['tiers'] ) && $config['upcharge_rate'] <= 0 ) {
					continue; // 沒設定就不列
				}
				$has_setup = true;

				$calc = UAPPT_Staff::calculate_commission( $config, $data['revenue'], $data['upcharge'] );

				$rows[] = array(
					'month'           => $ym . ( $ym === $this_month ? __( '（暫估）', 'ultimate-appointments' ) : '' ),
					'staff_name'      => $staff[ $sid ]['name'] . ( 'active' !== $staff[ $sid ]['status'] ? __( '（已停用）', 'ultimate-appointments' ) : '' ),
					'revenue'         => $data['revenue'],
					'upcharge'        => $data['upcharge'],
					'base'            => $calc['base'],
					// 累進時顯示的是「觸及的最高一級」，不是單一適用比例——
					// 欄名因此是「最高級距」而不是「抽成率」。
					'tier_rate'       => $calc['tier_rate'] / 100,
					'tier_amount'     => $calc['tier_amount'],
					'upcharge_amount' => $calc['upcharge_amount'],
					'commission'      => $calc['total'],
				);
				$total += $calc['total'];
			}
		}

		return array(
			'rows'    => $rows,
			'totals'  => $rows ? array(
				'month'      => __( '合計', 'ultimate-appointments' ),
				'commission' => round( $total, 2 ),
			) : array(),
			'columns' => array(
				__( '月份', 'ultimate-appointments' ),
				__( '人員', 'ultimate-appointments' ),
				__( '當月業績', 'ultimate-appointments' ),
				__( '其中指定加價', 'ultimate-appointments' ),
				__( '一般業績', 'ultimate-appointments' ),
				__( '最高級距', 'ultimate-appointments' ),
				__( '級距抽成', 'ultimate-appointments' ),
				__( '指定加價分成', 'ultimate-appointments' ),
				__( '抽成合計', 'ultimate-appointments' ),
			),
			'fields'  => array( 'month', 'staff_name', 'revenue', 'upcharge', 'base', 'tier_rate', 'tier_amount', 'upcharge_amount', 'commission' ),
			'extra'   => array( 'has_setup' => $has_setup ),
		);
	}

	/**
	 * 人員總表的欄位。
	 *
	 * 「指定加價」只有在這段期間**真的收到過**才會出現。沒開指定加價的店
	 * （多數）看到一整欄 0 只是雜訊，而且會讓人以為「指定率 50% 卻一毛加價
	 * 都沒收」是哪裡壞了——實情是這家店本來就沒設定加價。
	 *
	 * ⚠️ 指定加價是 `amount` 的**一部分**，不是額外收入。欄位名稱因此是
	 * 「其中指定加價」而不是「指定加價收入」，不然會有人把它加到業績上。
	 *
	 * 「未收費筆數」同理：沒有招待紀錄的店不需要那一欄。
	 *
	 * @param bool $has_upcharge  這段期間有沒有指定加價。
	 * @param bool $has_no_charge 這段期間有沒有未收費的預約。
	 * @return array{labels:string[], fields:string[]}
	 */
	protected function staff_report_columns( $has_upcharge, $has_no_charge = false ) {
		$cols = array(
			array( __( '人員', 'ultimate-appointments' ), 'staff_name' ),
			array( __( '操作筆數', 'ultimate-appointments' ), 'op_count' ),
			array( __( '不重複客人', 'ultimate-appointments' ), 'customer_count' ),
			array( __( '未到筆數', 'ultimate-appointments' ), 'no_show_count' ),
			array( __( '未到率', 'ultimate-appointments' ), 'no_show_rate' ),
			array( __( '指定率', 'ultimate-appointments' ), 'request_rate' ),
			array( __( '業績', 'ultimate-appointments' ), 'revenue' ),
		);

		if ( $has_upcharge ) {
			$cols[] = array( __( '其中指定加價', 'ultimate-appointments' ), 'upcharge' );
		}

		if ( $has_no_charge ) {
			$cols[] = array( __( '未收費筆數', 'ultimate-appointments' ), 'no_charge_count' );
		}

		$cols[] = array( __( '平均客單價', 'ultimate-appointments' ), 'avg_price' );
		$cols[] = array( __( '材料成本', 'ultimate-appointments' ), 'material_cost' );
		$cols[] = array( __( '毛利', 'ultimate-appointments' ), 'gross_profit' );
		$cols[] = array( __( '時段利用率', 'ultimate-appointments' ), 'utilization' );

		return array(
			'labels' => array_column( $cols, 0 ),
			'fields' => array_column( $cols, 1 ),
		);
	}

	/**
	 * 「人員 × 項目」：每位人員做了哪些項目、各做幾次、各多少業績。
	 *
	 * **畫面用分組明細、CSV 用逐列**（見 docs/reports-v2-plan.md 的 D4）：
	 * 不做矩陣（人員為列、項目為欄），因為美業的項目數通常 10–30 個，矩陣一定
	 * 要橫向捲動、手機幾乎不能看；真要跨人員比較，CSV 丟進 Excel 樞紐分析比在
	 * 網頁上硬做矩陣好用得多。
	 *
	 * 回傳的 `rows` 是**逐列**（一列一個人員 × 項目組合，給 CSV 用），view 自己
	 * 依 `staff_id` 分組印成可折疊區塊——兩邊同一份資料，不各自查一次。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @return array
	 */
	protected function build_staff_service_report( $date_from, $date_to, $staff_id = 0 ) {
		$stats = UAPPT_Booking::get_report_stats(
			array(
				'date_from' => $date_from,
				'date_to'   => $date_to,
				'staff_id'  => $staff_id,
				'group_by'  => 'staff_service',
			)
		);
		$costs = UAPPT_Consumable::get_cost_by_period( $date_from, $date_to, $staff_id, 'staff_service' );

		// 每位人員自己的業績小計，算「佔這位人員的幾 %」要用——分母是他自己的
		// 業績，不是全店的。這一欄才回答得了「他主要在做什麼」。
		$staff_revenue = array();
		foreach ( $stats as $row ) {
			$sid = (int) $row['staff_id'];
			if ( ! isset( $staff_revenue[ $sid ] ) ) {
				$staff_revenue[ $sid ] = 0.0;
			}
			$staff_revenue[ $sid ] += $row['revenue'];
		}

		$names      = array();
		$sort_order = array();
		foreach ( UAPPT_Staff::get_all() as $staff ) {
			$sid                = (int) $staff['id'];
			$names[ $sid ]      = $staff['name'] . ( 'active' !== $staff['status'] ? __( '（已停用）', 'ultimate-appointments' ) : '' );
			$sort_order[ $sid ] = (int) $staff['sort_order'];
		}

		$rows       = array();
		$totals     = UAPPT_Booking::empty_report_stats();
		$total_cost = 0.0;

		foreach ( $stats as $key => $row ) {
			$sid  = (int) $row['staff_id'];
			$cost = isset( $costs[ $key ] ) ? $costs[ $key ] : 0.0;

			$decorated = $this->decorate_report_row( $row, $cost );
			$decorated['staff_id']     = $sid;
			$decorated['staff_name']   = isset( $names[ $sid ] ) ? $names[ $sid ] : sprintf( '#%d', $sid );
			$decorated['service_name'] = $this->format_service_name( $row );
			$decorated['staff_share']  = ! empty( $staff_revenue[ $sid ] ) ? $row['revenue'] / $staff_revenue[ $sid ] : 0.0;
			$decorated['sort_order']   = isset( $sort_order[ $sid ] ) ? $sort_order[ $sid ] : PHP_INT_MAX;

			$rows[] = $decorated;

			foreach ( UAPPT_Booking::summable_report_fields() as $field ) {
				$totals[ $field ] += $row[ $field ];
			}
			$total_cost += $cost;
		}

		// 先照人員的排序（跟人力資源頁一致），同一位人員底下再照業績由高到低——
		// 「他主要在做什麼」應該排在最上面。
		usort(
			$rows,
			function ( $a, $b ) {
				if ( $a['sort_order'] !== $b['sort_order'] ) {
					return $a['sort_order'] <=> $b['sort_order'];
				}
				if ( $a['staff_id'] !== $b['staff_id'] ) {
					return $a['staff_id'] <=> $b['staff_id'];
				}
				return $b['revenue'] <=> $a['revenue'];
			}
		);

		return array(
			'rows'    => $rows,
			'totals'  => $this->decorate_report_row( $totals, $total_cost ),
			'columns' => array(
				__( '人員', 'ultimate-appointments' ),
				__( '服務項目', 'ultimate-appointments' ),
				__( '操作筆數', 'ultimate-appointments' ),
				__( '服務人次', 'ultimate-appointments' ),
				__( '業績', 'ultimate-appointments' ),
				__( '佔該人員業績', 'ultimate-appointments' ),
				__( '平均客單價', 'ultimate-appointments' ),
				__( '材料成本', 'ultimate-appointments' ),
				__( '毛利', 'ultimate-appointments' ),
			),
			'fields'  => array( 'staff_name', 'service_name', 'op_count', 'person_count', 'revenue', 'staff_share', 'avg_price', 'material_cost', 'gross_profit' ),
			'extra'   => array(),
		);
	}

	/**
	 * 服務項目的顯示名稱。
	 *
	 * 優先用預約自己存的 `plan_name` 快照——方案被改名、停用或刪除之後，報表仍要
	 * 說得出客人當初買的是什麼（設計紀律 #4）。快照是空的才回頭問商品。
	 *
	 * @param array $row `get_report_stats()` 的一列，需含 product_id 與 plan_name。
	 * @return string
	 */
	protected function format_service_name( $row ) {
		$product = wc_get_product( $row['product_id'] );
		$name    = (string) $row['plan_name'];

		if ( '' === $name ) {
			return $product ? $product->get_name() : sprintf( '#%d', $row['product_id'] );
		}

		return $product ? $product->get_name() . ' › ' . $name : $name;
	}

	/**
	 * 「客人」頁籤：客人從哪來、會不會回來、誰貢獻最多。
	 *
	 * `rows` 是 Top 客人排行（CSV 匯出的就是它），其餘指標掛在 `extra`——
	 * 卡片上那些數字每一個都是獨立的計算，形狀跟 `columns`／`fields` 對不上，
	 * 硬塞成表格只會讓兩邊都彆扭。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @return array
	 */
	protected function build_clients_report( $date_from, $date_to, $staff_id = 0 ) {
		// ⚠️ **一定要給 `totals`**：頁面最上方那排 KPI 卡片是所有頁籤共用的，
		// 回傳空陣列會讓那一整排變成一堆 undefined index 警告。這一版第一次寫的
		// 時候就是漏了這個——只顧著填自己頁籤要的 extra。
		$totals = $this->build_overall_totals( $date_from, $date_to, $staff_id );

		$top = UAPPT_Booking::get_top_customers( $date_from, $date_to, $staff_id, 20 );

		// Top 客人的產品消費一次查完整頁（`customer_id` 吃陣列），不要每一列各
		// 查一次——見 UAPPT_Customer::get_product_spend() 的說明。
		$customer_ids = array();
		foreach ( $top as $row ) {
			if ( (int) $row['customer_id'] > 0 ) {
				$customer_ids[] = (int) $row['customer_id'];
			}
		}
		$product_spend = UAPPT_Customer::get_product_spend( $customer_ids );

		$rows = array();
		foreach ( $top as $row ) {
			$cid     = (int) $row['customer_id'];
			$visits  = (int) $row['visits'];
			$service = (float) $row['service_spend'];
			$product = isset( $product_spend[ $cid ] ) ? $product_spend[ $cid ] : 0.0;

			$rows[] = array(
				'customer_name'  => '' !== $row['customer_name'] ? $row['customer_name'] : __( '（未留姓名）', 'ultimate-appointments' ),
				'customer_phone' => (string) $row['customer_phone'],
				'visits'         => $visits,
				'service_spend'  => $service,
				'product_spend'  => $product,
				'total_spend'    => $service + $product,
				'avg_spend'      => $visits > 0 ? $service / $visits : 0.0,
				'last_visit'     => substr( (string) $row['last_visit'], 0, 10 ),
			);
		}

		// 零售對服務比。⚠️ 產品業績是全店的、以**下單日期**為準，所以篩選了
		// 特定人員時這個數字不會跟著變——產品賣給誰記在誰頭上目前沒有依據
		// （那是階段 4 的 D9，要靠同一張訂單的服務人員分攤）。view 上會說明。
		$service_revenue = $totals['revenue'];
		$product_revenue = UAPPT_Customer::get_product_revenue( $date_from, $date_to );

		// 新客的首購 → 回購。跟上面的「回訪週期中位數」分開算，因為那一個是
		// 熟客撐出來的，回答不了「第一次上門的人有沒有第二次」。
		$new_repeat = UAPPT_Booking::get_new_customer_repeat( $date_from, $date_to, $staff_id );

		return array(
			'rows'    => $rows,
			'totals'  => $totals,
			'columns' => array(
				__( '客人', 'ultimate-appointments' ),
				__( '電話', 'ultimate-appointments' ),
				__( '到訪次數', 'ultimate-appointments' ),
				__( '服務消費', 'ultimate-appointments' ),
				__( '產品消費', 'ultimate-appointments' ),
				__( '合計', 'ultimate-appointments' ),
				__( '平均每次', 'ultimate-appointments' ),
				__( '最後到訪', 'ultimate-appointments' ),
			),
			'fields'  => array( 'customer_name', 'customer_phone', 'visits', 'service_spend', 'product_spend', 'total_spend', 'avg_spend', 'last_visit' ),
			'extra'   => array(
				'customers'       => UAPPT_Booking::get_customer_breakdown( $date_from, $date_to, $staff_id ),
				'rebooking'       => UAPPT_Booking::get_rebooking_stats( $date_from, $date_to, $staff_id ),
				'gaps'            => UAPPT_Booking::get_visit_gaps( $date_from, $date_to, $staff_id ),
				'lapsed'          => UAPPT_Customer::count_lapsed(),
				'customer_count'  => $totals['customer_count'],
				'service_revenue' => $service_revenue,
				'product_revenue' => $product_revenue,
				'new_repeat'      => $new_repeat,
			),
		);
	}

	/**
	 * 「預約」頁籤：位子滿不滿、什麼時候滿、漏掉了多少。
	 *
	 * `rows` 是星期 × 時段的熱度矩陣（CSV 匯出的就是它，一列一格），其餘指標
	 * 掛在 `extra`。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @return array
	 */
	protected function build_appointments_report( $date_from, $date_to, $staff_id = 0 ) {
		$totals = $this->build_overall_totals( $date_from, $date_to, $staff_id );

		$heat = UAPPT_Booking::get_report_stats(
			array(
				'date_from' => $date_from,
				'date_to'   => $date_to,
				'staff_id'  => $staff_id,
				'group_by'  => 'weekday_hour',
			)
		);

		// 攤成 [星期][小時] => 統計，同時記下實際出現過的最早／最晚時段——
		// 一律印 0–23 點會有一半的欄位永遠是空的，表格寬度也吃不消。
		$matrix    = array();
		$min_hour  = null;
		$max_hour  = null;
		$max_count = 0;
		$rows      = array();

		foreach ( $heat as $key => $stats ) {
			$parts   = explode( '|', (string) $key, 2 );
			$weekday = isset( $parts[0] ) ? (int) $parts[0] : 0;
			$hour    = isset( $parts[1] ) ? (int) $parts[1] : 0;
			$count   = $stats['service_count'] + $stats['no_show_count'];

			$matrix[ $weekday ][ $hour ] = array(
				'count'   => $count,
				'revenue' => $stats['revenue'],
			);

			$min_hour  = ( null === $min_hour ) ? $hour : min( $min_hour, $hour );
			$max_hour  = ( null === $max_hour ) ? $hour : max( $max_hour, $hour );
			$max_count = max( $max_count, $count );

			$rows[] = array(
				'weekday'  => self::weekday_label( $weekday ),
				'hour'     => sprintf( '%02d:00', $hour ),
				'op_count' => $count,
				'revenue'  => $stats['revenue'],
			);
		}

		// 照星期、時段排序，CSV 才看得懂。
		usort(
			$rows,
			function ( $a, $b ) {
				return array( $a['weekday'], $a['hour'] ) <=> array( $b['weekday'], $b['hour'] );
			}
		);

		// 產能缺口。**這是推估不是事實**，畫面上要標明：空閒的人力-分鐘除以
		// 這段期間的平均服務時長，等於「還能多做幾個」，再乘平均客單價。
		$capacity  = $this->report_capacity_by_period( $date_from, $date_to, $staff_id, 'all' );
		$available = isset( $capacity['0'] ) ? $capacity['0'] : 0.0;
		$busy      = (float) $totals['busy_minutes'];
		$idle      = max( 0, $available - $busy );
		$avg_len   = $totals['service_count'] > 0 ? $busy / $totals['service_count'] : 0.0;
		$slots     = $avg_len > 0 ? floor( $idle / $avg_len ) : 0;

		return array(
			'rows'    => $rows,
			'totals'  => $totals,
			'columns' => array(
				__( '星期', 'ultimate-appointments' ),
				__( '時段', 'ultimate-appointments' ),
				__( '操作筆數', 'ultimate-appointments' ),
				__( '業績', 'ultimate-appointments' ),
			),
			'fields'  => array( 'weekday', 'hour', 'op_count', 'revenue' ),
			'extra'   => array(
				'matrix'       => $matrix,
				'min_hour'     => null === $min_hour ? 9 : $min_hour,
				'max_hour'     => null === $max_hour ? 18 : $max_hour,
				'max_count'    => $max_count,
				'cancellation' => UAPPT_Booking::get_cancellation_stats( $date_from, $date_to, $staff_id ),
				'lead_time'    => UAPPT_Booking::get_lead_time_buckets( $date_from, $date_to, $staff_id ),
				'source'       => UAPPT_Booking::get_source_breakdown( $date_from, $date_to, $staff_id ),
				'capacity'     => array(
					'available_minutes' => $available,
					'busy_minutes'      => $busy,
					'idle_minutes'      => $idle,
					'avg_service_len'   => $avg_len,
					'idle_slots'        => $slots,
					'potential_revenue' => $slots * $totals['avg_price'],
				),
			),
		);
	}

	/**
	 * MySQL `DAYOFWEEK()` 的數字換成中文（1＝星期日 … 7＝星期六）。
	 *
	 * @param int $weekday DAYOFWEEK() 的值。
	 * @return string
	 */
	public static function weekday_label( $weekday ) {
		$labels = array( 1 => '日', 2 => '一', 3 => '二', 4 => '三', 5 => '四', 6 => '五', 7 => '六' );

		return isset( $labels[ $weekday ] ) ? $labels[ $weekday ] : (string) $weekday;
	}

	/**
	 * 「耗材」頁籤：期間用量、成本、進出與預估可用天數。
	 *
	 * 「預估可用天數」用這段期間的日均用量往前推。用量是 0 的不給估——除以 0
	 * 是一回事，「這段期間沒用過」本來就推不出任何有意義的天數是另一回事。
	 *
	 * 這一頁**不套人員篩選**：庫存是整間店共用的，「王小美用掉多少毛巾」在
	 * 「人員」頁籤的材料成本欄看得到，這裡問的是「店裡的東西夠不夠用」。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @return array
	 */
	protected function build_consumable_report( $date_from, $date_to ) {
		$usage = UAPPT_Consumable::get_usage_stats( $date_from, $date_to );
		$days  = max( 1, $this->report_period_days( $date_from, $date_to ) );

		$rows = array();
		foreach ( UAPPT_Consumable::get_all() as $item ) {
			$id = (int) $item['id'];
			$u  = isset( $usage[ $id ] ) ? $usage[ $id ] : array(
				'used'      => 0.0,
				'cost'      => 0.0,
				'restocked' => 0.0,
				'adjusted'  => 0.0,
				'wasted'    => 0.0,
			);

			// 這段期間完全沒動過、而且已經停用的品項不列——跟人員報表「離職
			// 很久又沒資料的不佔位」同一個道理。
			if ( UAPPT_Consumable::STATUS_ACTIVE !== $item['status']
				&& 0.0 === $u['used'] && 0.0 === $u['restocked'] && 0.0 === $u['adjusted'] && 0.0 === $u['wasted']
			) {
				continue;
			}

			$daily = $u['used'] / $days;

			$rows[] = array(
				'consumable_name' => $item['name'],
				'unit'            => $item['unit'],
				'used'            => $u['used'],
				'cost'            => $u['cost'],
				'restocked'       => $u['restocked'],
				'adjusted'        => $u['adjusted'],
				'wasted'          => $u['wasted'],
				'stock'           => (float) $item['stock'],
				'days_left'       => $daily > 0 ? (float) $item['stock'] / $daily : -1.0,
			);
		}

		return array(
			'rows'    => $rows,
			'totals'  => array(),
			'columns' => array(
				__( '耗材', 'ultimate-appointments' ),
				__( '單位', 'ultimate-appointments' ),
				__( '服務用量', 'ultimate-appointments' ),
				__( '材料成本', 'ultimate-appointments' ),
				__( '進貨', 'ultimate-appointments' ),
				__( '報廢', 'ultimate-appointments' ),
				__( '盤點差異', 'ultimate-appointments' ),
				__( '目前結存', 'ultimate-appointments' ),
				__( '預估可用天數', 'ultimate-appointments' ),
			),
			'fields'  => array( 'consumable_name', 'unit', 'used', 'cost', 'restocked', 'wasted', 'adjusted', 'stock', 'days_left' ),
			'extra'   => array(),
		);
	}

	/**
	 * 「總覽」頁籤：整段期間一個總數，加上每日趨勢。
	 *
	 * 趨勢直接沿用「每日」那一份資料，不另外查一次——同一個畫面上的兩個東西
	 * 出自不同查詢遲早會對不起來。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @return array
	 */
	protected function build_overview_report( $date_from, $date_to, $staff_id = 0, $unit = 'day' ) {
		$units = self::report_units();
		if ( ! isset( $units[ $unit ] ) ) {
			$unit = 'day';
		}

		$period = $this->build_period_report( $date_from, $date_to, $staff_id, $unit );

		// ⚠️ 總計的「不重複客人」**不能把每一列的客數加起來**——同一位客人這個
		// 月來了三天，逐列相加會變成三位。整段期間要重新問一次資料庫，那正是
		// build_overall_totals() 在做的事（build_report() 出口的
		// normalize_period_totals() 會再保證一次，兩邊算出來的值相同）。
		return array(
			'rows'    => $period['rows'],
			'totals'  => $this->build_overall_totals( $date_from, $date_to, $staff_id ),
			'columns' => $period['columns'],
			'fields'  => $period['fields'],
			'extra'   => array(
				'customers' => UAPPT_Booking::get_customer_breakdown( $date_from, $date_to, $staff_id ),
				// 每日走勢圖固定用**每日**，不跟著顆粒切換：切到「每月」時
				// 三根長條沒有任何形狀可言，而那張圖存在的意義就是看形狀。
				'daily'     => 'day' === $unit ? $period['rows'] : $this->build_period_report( $date_from, $date_to, $staff_id, 'day' )['rows'],
				'unit'      => $unit,
			),
		);
	}

	/**
	 * 「收入結構」頁籤：這些錢是從哪裡來的。
	 *
	 * 三種切法放在同一頁，因為它們回答的是同一個問題，只是分類軸不同：
	 * **賣什麼**（服務項目）、**誰付的**（新客／回頭客）、**怎麼收的**（收款方式）。
	 * 舊版散在「營收」頁尾、「總覽」的客人組成、以及獨立的「收款」頁籤裡，
	 * 要回答「這個月多出來的錢是新客帶來的、還是熟客加購？」得在三個地方之間
	 * 來回切換再自己心算。
	 *
	 * 主表格（也就是 CSV 匯出的那一份）用 $view 分流，跟人員頁同一個模式：
	 * 一頁三張表，但一次匯出一張——硬塞成一份 CSV 只會讓三張表的欄位互相打架。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員。
	 * @param string $view      ''＝服務項目（預設）／'payments'＝收款方式。
	 * @return array
	 */
	protected function build_income_report( $date_from, $date_to, $staff_id = 0, $view = '' ) {
		$view = self::sanitize_income_view( $view );

		// 只算被選到的那一張，不要四張全跑：切換是連結，每一次都會重新產表，
		// 一次算四份等於三份白費（零售那一份還要撈整段期間的訂單）。
		switch ( $view ) {
			case 'items':
				$report = $this->build_service_report( $date_from, $date_to, $staff_id );
				break;
			case 'retail':
				$report = $this->build_retail_report( $date_from, $date_to );
				break;
			case 'payments':
				$report = $this->build_payments_report( $date_from, $date_to, $staff_id );
				break;
			default:
				$report = $this->build_service_category_report( $date_from, $date_to, $staff_id );
				break;
		}

		$report['extra'] = array(
			'customers' => UAPPT_Booking::get_revenue_by_customer_type( $date_from, $date_to, $staff_id ),
			'view'      => $view,
			// ⚠️ 頁面上方那排卡片讀的是 $report['totals']，而「依零售商品」那一
			// 份的 totals 是**商品銷售額**（時間軸還不一樣）。不另外給一份的話，
			// 切到零售時上面的「業績」會從 319,870 變成 54,910，看起來像資料
			// 壞掉。period_totals 永遠是這段期間的服務統計，view 端優先用它。
			'period_totals' => $this->build_overall_totals( $date_from, $date_to, $staff_id ),
		);

		return $report;
	}

	/**
	 * 「收入結構」的拆解方式。
	 *
	 * 由粗到細：先看大塊（分類），再看單品，再看零售，最後才是怎麼收的。
	 *
	 * ⚠️ 服務項目那一個用 `items` 而不是 `service`：`service` 在「人員」頁籤
	 * 已經有另一個意思（人員 × 項目），兩個頁籤共用同一個 `view` 參數，同名
	 * 不同義遲早會有人接錯。
	 *
	 * @return array view => 標籤
	 */
	public static function income_views() {
		return array(
			''         => __( '依服務分類', 'ultimate-appointments' ),
			'items'    => __( '依服務項目', 'ultimate-appointments' ),
			'retail'   => __( '依零售商品', 'ultimate-appointments' ),
			'payments' => __( '依收款方式', 'ultimate-appointments' ),
		);
	}

	/**
	 * 把網址上的 view 收斂成合法值。
	 *
	 * @param string $raw 原始值。
	 * @return string
	 */
	public static function sanitize_income_view( $raw ) {
		$view = sanitize_key( (string) $raw );
		return array_key_exists( $view, self::income_views() ) ? $view : '';
	}

	/**
	 * 解析報表的期間與人員篩選（畫面與 CSV 匯出共用）。日期格式不對或完全
	 * 沒帶時，退回「本月」——不是空白區間，報表一開啟就要看得到東西，不用
	 * 先選日期才有畫面。
	 *
	 * @return array [$date_from, $date_to, $staff_id]
	 */
	protected function parse_report_period() {
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$staff_id  = isset( $_GET['staff_id'] ) ? absint( $_GET['staff_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// 快捷鍵優先於手打的日期：按下「本月」時網址上還留著舊的 date_from／
		// date_to，先看 preset 才不會按了沒反應。
		$preset = isset( $_GET['preset'] ) ? sanitize_key( wp_unslash( $_GET['preset'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$range  = $preset ? $this->report_preset_range( $preset ) : null;
		if ( $range ) {
			list( $date_from, $date_to ) = $range;
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$today     = current_time( 'Y-m-d' );
			$date_from = substr( $today, 0, 8 ) . '01';
			$date_to   = $today;
		}
		if ( $date_from > $date_to ) {
			list( $date_from, $date_to ) = array( $date_to, $date_from );
		}

		// 期間上限：利用率的分母是逐日 × 逐人算出來的，區間拉太長頁面會一直
		// 轉圈圈。超過就把**起始日**往後收（保留結束日），因為使用者多半是
		// 想看「到最近為止」的資料，砍掉最舊的那一段比砍掉最新的合理。
		$max_from = uappt_local_date( $date_to, '-' . ( self::REPORT_MAX_DAYS - 1 ) . ' days' );
		if ( '' !== $max_from && $date_from < $max_from ) {
			$date_from = $max_from;
		}

		return array( $date_from, $date_to, $staff_id );
	}



	/* ---------------------------------------------------------------------
	 * 耗材管理（v2.24.0）
	 * ------------------------------------------------------------------- */

	/**
	 * 耗材頁的三個頁籤。
	 *
	 * 跟設定頁的頁籤**不一樣**：那邊是同一張表單、全部欄位都留在 DOM 裡只用
	 * CSS 藏（因為儲存時會無條件寫入每一個 option）；這裡每個頁籤各自查詢、
	 * 各自是獨立的表單，用真正的分頁（每頁只渲染自己那一份）才對。
	 *
	 * @return array tab => label
	 */
	public static function consumable_tabs() {
		return array(
			// v2.32.0：「盤點」不再是獨立頁籤，併進「庫存」——盤點不是一個
			// 地方，是在庫存表上直接填實際數量。切到另一個頁籤、對著另一份
			// 長得幾乎一樣的清單填，等於把同一張表畫了兩次。
			// 舊網址 ?tab=stocktake 會被 sanitize_consumable_tab() 退回 'stock'，
			// 剛好就是它現在住的地方，書籤不會壞。
			'stock'   => __( '庫存', 'ultimate-appointments' ),
			'recipes' => __( '配方', 'ultimate-appointments' ),
			'moves'   => __( '異動紀錄', 'ultimate-appointments' ),
		);
	}

	/**
	 * 把網址上的 tab 收斂成合法值。
	 *
	 * @param string $raw 原始值。
	 * @return string
	 */
	protected static function sanitize_consumable_tab( $raw ) {
		$tab  = sanitize_key( (string) $raw );
		$tabs = self::consumable_tabs();
		return isset( $tabs[ $tab ] ) ? $tab : 'stock';
	}

	/**
	 * 配方可以掛在哪些對象上。
	 *
	 * 刻意不重用 `get_bookable_options()`：那支給手動建單用，有方案的商品只會
	 * 列出各個方案、不會列出商品本身。配方需要「整個商品的預設」這一層
	 * （方案沒設定時往上沿用），所以兩者都要列。
	 *
	 * @return array 每個元素：['value','label']
	 */
	protected function get_recipe_targets() {
		$targets = array(
			array(
				'value' => 'global',
				'label' => __( '全部服務（每次服務都會用到）', 'ultimate-appointments' ),
			),
		);

		$post_ids = get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => UAPPT_Product_Type::PRODUCT_TYPE,
					),
				),
			)
		);

		foreach ( $post_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$targets[] = array(
				'value' => 'p:' . $product_id,
				'label' => $product->get_name() . __( '（整個商品的預設）', 'ultimate-appointments' ),
			);

			// 已停用的方案也列出來：既有預約仍然指得到那個 plan_key，它的配方
			// 還是會被 resolve_recipe() 讀到，看不到就沒辦法維護。
			foreach ( UAPPT_Product::get_plans( $product_id ) as $plan ) {
				$targets[] = array(
					'value' => 'k:' . $product_id . ':' . $plan['key'],
					'label' => '— ' . $product->get_name() . ' › ' . $plan['name'] . ( empty( $plan['active'] ) ? __( '（已停用）', 'ultimate-appointments' ) : '' ),
				);
			}
		}

		return $targets;
	}

	/**
	 * 解析配方對象的字串值。
	 *
	 * @param string $raw 'global'／'p:123'／'k:123:abcdef'。
	 * @return array ['scope','product_id','plan_key']
	 */
	protected function parse_recipe_target( $raw ) {
		$parts = explode( ':', (string) $raw );
		$kind  = isset( $parts[0] ) ? $parts[0] : '';

		if ( 'p' === $kind ) {
			return array(
				'scope'      => UAPPT_Consumable::SCOPE_PRODUCT,
				'product_id' => isset( $parts[1] ) ? absint( $parts[1] ) : 0,
				'plan_key'   => '',
			);
		}

		if ( 'k' === $kind ) {
			return array(
				'scope'      => UAPPT_Consumable::SCOPE_PLAN,
				'product_id' => isset( $parts[1] ) ? absint( $parts[1] ) : 0,
				'plan_key'   => isset( $parts[2] ) ? sanitize_key( $parts[2] ) : '',
			);
		}

		return array(
			'scope'      => UAPPT_Consumable::SCOPE_GLOBAL,
			'product_id' => 0,
			'plan_key'   => '',
		);
	}

	/**
	 * 輸出耗材管理頁。新增／編輯單筆耗材是獨立的畫面，其餘走頁籤。
	 */
	public function render_consumables_page() {
		if ( ! current_user_can( UAPPT_Caps::CAP_MANAGE_CONSUMABLES ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'new' === $action || 'edit' === $action ) {
			$consumable = null;
			if ( 'edit' === $action ) {
				$id         = isset( $_GET['consumable_id'] ) ? absint( $_GET['consumable_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$consumable = UAPPT_Consumable::get( $id );
				if ( ! $consumable ) {
					$this->redirect_with_error( 'uappt-consumables', __( '找不到這筆耗材。', 'ultimate-appointments' ) );
				}
			}
			self::page_open( $consumable ? __( '編輯耗材', 'ultimate-appointments' ) : __( '新增耗材', 'ultimate-appointments' ), self::back_action( 'uappt-consumables', __( '← 回耗材清單', 'ultimate-appointments' ) ) );
			require UAPPT_PLUGIN_DIR . 'includes/views/consumable-edit.php';
			self::page_close();
			return;
		}

		$tabs        = self::consumable_tabs();
		$current_tab = self::sanitize_consumable_tab( isset( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// 每個頁籤各自準備自己要的資料，不是一次全查——「異動紀錄」要分頁、
		// 「配方」要載入商品清單，都是只有那一頁才需要的成本。
		$consumables   = array();
		$low_stock     = array();
		$moves         = array( 'items' => array(), 'total' => 0 );
		$move_filters  = array();
		$recipe_target = array( 'scope' => UAPPT_Consumable::SCOPE_GLOBAL, 'product_id' => 0, 'plan_key' => '' );
		$recipe_rows   = array();
		$recipe_value  = 'global';
		$recipe_targets   = array();
		$recipe_inherited = array();
		$move_names    = array();

		switch ( $current_tab ) {
			case 'recipes':
				$consumables    = UAPPT_Consumable::get_all( array( 'status' => UAPPT_Consumable::STATUS_ACTIVE ) );
				$recipe_targets = $this->get_recipe_targets();
				$recipe_value   = isset( $_GET['target'] ) ? sanitize_text_field( wp_unslash( $_GET['target'] ) ) : 'global'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$recipe_target  = $this->parse_recipe_target( $recipe_value );
				$recipe_rows    = UAPPT_Consumable::get_recipe_rows( $recipe_target['scope'], $recipe_target['product_id'], $recipe_target['plan_key'] );

				// 「這一頁沒設定、但實際上還是會扣」的東西要講出來，不然管理者
				// 會問「我明明沒設定毛巾，為什麼毛巾一直在少」。兩個來源：
				// 全域的每次必用，以及方案沒設定時沿用的商品層級。
				$recipe_inherited = array();
				if ( UAPPT_Consumable::SCOPE_GLOBAL !== $recipe_target['scope'] ) {
					foreach ( UAPPT_Consumable::get_recipe_rows( UAPPT_Consumable::SCOPE_GLOBAL ) as $uappt_g ) {
						$recipe_inherited['global'][] = $uappt_g;
					}
				}
				if ( UAPPT_Consumable::SCOPE_PLAN === $recipe_target['scope'] && ! $recipe_rows ) {
					$recipe_inherited['product'] = UAPPT_Consumable::get_recipe_rows( UAPPT_Consumable::SCOPE_PRODUCT, $recipe_target['product_id'] );
				}
				break;

			case 'moves':
				$move_filters = array(
					'consumable_id' => isset( $_GET['consumable_id'] ) ? absint( $_GET['consumable_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'type'          => isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'date_from'     => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'date_to'       => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'paged'         => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'per_page'      => 50,
				);
				$moves       = UAPPT_Consumable::query_moves( $move_filters );
				$consumables = UAPPT_Consumable::get_all();
				// 一次撈回這一頁用到的耗材名稱，不要每一列各查一次。
				$move_names  = UAPPT_Consumable::get_many( wp_list_pluck( $moves['items'], 'consumable_id' ) );
				break;

			default:
				$consumables = UAPPT_Consumable::get_all();
				$low_stock   = UAPPT_Consumable::get_low_stock();
				break;
		}

		self::page_open(
			__( '耗材管理', 'ultimate-appointments' ),
			array( array( 'url' => self::url( 'consumables', array( 'action' => 'new' ) ), 'label' => __( '新增耗材', 'ultimate-appointments' ) ) )
		);
		require UAPPT_PLUGIN_DIR . 'includes/views/consumables.php';
		self::page_close();
	}

	/**
	 * 新增／更新耗材主檔。
	 *
	 * 新增時可以順便填「期初庫存」，但那是**寫成一筆 `restock` 異動**，不是
	 * 直接把數字塞進 `stock` 欄位——期初盤點本身就是一次真實的入庫，走同一條
	 * 路流水帳才完整（見 UAPPT_Consumable 開頭的規則 1）。
	 */
	public function handle_save_consumable() {
		if ( ! current_user_can( UAPPT_Caps::CAP_MANAGE_CONSUMABLES ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		$this->require_module( 'consumables' );
		check_admin_referer( 'uappt_save_consumable' );

		$id = isset( $_POST['consumable_id'] ) ? absint( $_POST['consumable_id'] ) : 0;

		$result = UAPPT_Consumable::save(
			array(
				'id'                  => $id,
				'name'                => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'sku'                 => isset( $_POST['sku'] ) ? wp_unslash( $_POST['sku'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'unit'                => isset( $_POST['unit'] ) ? wp_unslash( $_POST['unit'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'low_stock_threshold' => isset( $_POST['low_stock_threshold'] ) ? wc_format_decimal( wp_unslash( $_POST['low_stock_threshold'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'unit_cost'           => isset( $_POST['unit_cost'] ) ? wc_format_decimal( wp_unslash( $_POST['unit_cost'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'status'              => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : UAPPT_Consumable::STATUS_ACTIVE,
				'note'                => isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'sort_order'          => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( 'uappt-consumables', $result->get_error_message() );
		}

		if ( ! $id ) {
			$opening = isset( $_POST['opening_stock'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['opening_stock'] ) ) : 0.0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( $opening > 0 ) {
				UAPPT_Consumable::record_move(
					$result,
					UAPPT_Consumable::MOVE_RESTOCK,
					$opening,
					array( 'note' => __( '期初庫存', 'ultimate-appointments' ) )
				);
			}
		}

		$this->redirect( 'uappt-consumables', array( 'uappt_notice' => 'consumable_saved' ) );
	}

	/**
	 * 刪除耗材。有異動紀錄的會被 UAPPT_Consumable::delete() 擋下來（見那支的說明）。
	 */
	public function handle_delete_consumable() {
		if ( ! current_user_can( UAPPT_Caps::CAP_MANAGE_CONSUMABLES ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		$this->require_module( 'consumables' );
		check_admin_referer( 'uappt_delete_consumable' );

		$id     = isset( $_POST['consumable_id'] ) ? absint( $_POST['consumable_id'] ) : 0;
		$result = UAPPT_Consumable::delete( $id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( 'uappt-consumables', $result->get_error_message() );
		}

		$this->redirect( 'uappt-consumables', array( 'uappt_notice' => 'consumable_deleted' ) );
	}

	/**
	 * 手動異動：進貨（正數）或報廢（負數）。
	 *
	 * 表單上收的一律是**正數**，方向由送出的按鈕決定——讓使用者自己記得報廢
	 * 要打負號，遲早會有人打成正的、把報廢記成進貨。
	 */
	public function handle_consumable_move() {
		if ( ! current_user_can( UAPPT_Caps::CAP_MANAGE_CONSUMABLES ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		$this->require_module( 'consumables' );
		check_admin_referer( 'uappt_consumable_move' );

		$id   = isset( $_POST['consumable_id'] ) ? absint( $_POST['consumable_id'] ) : 0;
		$qty  = isset( $_POST['qty'] ) ? abs( (float) wc_format_decimal( wp_unslash( $_POST['qty'] ) ) ) : 0.0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$type = ( isset( $_POST['move_type'] ) && UAPPT_Consumable::MOVE_WASTE === $_POST['move_type'] )
			? UAPPT_Consumable::MOVE_WASTE
			: UAPPT_Consumable::MOVE_RESTOCK;
		$note = isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$result = UAPPT_Consumable::record_move(
			$id,
			$type,
			UAPPT_Consumable::MOVE_WASTE === $type ? -$qty : $qty,
			array( 'note' => $note )
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( 'uappt-consumables', $result->get_error_message() );
		}

		$this->redirect( 'uappt-consumables', array( 'uappt_notice' => 'consumable_move_saved' ) );
	}

	/**
	 * 盤點：整批送出「實際數量」，只有跟帳面不同的才會寫一筆 `adjust` 差額。
	 *
	 * 留空的欄位代表「這次沒盤到這一項」，不是「盤到 0」——兩者差很多，所以
	 * 空字串一定要跳過，不能用 `(float)` 一律轉成 0。
	 */
	public function handle_consumable_stocktake() {
		if ( ! current_user_can( UAPPT_Caps::CAP_MANAGE_CONSUMABLES ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		$this->require_module( 'consumables' );
		check_admin_referer( 'uappt_consumable_stocktake' );

		$actual = isset( $_POST['actual'] ) && is_array( $_POST['actual'] ) ? wp_unslash( $_POST['actual'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

		$adjusted = 0;
		foreach ( $actual as $consumable_id => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$result = UAPPT_Consumable::adjust_to( absint( $consumable_id ), (float) wc_format_decimal( $value ), $note );
			if ( ! is_wp_error( $result ) ) {
				$adjusted++;
			}
		}

		$this->redirect(
			'uappt-consumables',
			array(
				'tab'         => 'stock',
				'uappt_notice' => 'consumable_stocktake_done',
				'uappt_count'  => $adjusted,
			)
		);
	}

	/**
	 * 儲存某一個層級的配方（整組覆寫）。
	 */
	public function handle_save_consumable_recipe() {
		if ( ! current_user_can( UAPPT_Caps::CAP_MANAGE_CONSUMABLES ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		$this->require_module( 'consumables' );
		check_admin_referer( 'uappt_save_consumable_recipe' );

		$raw_target = isset( $_POST['target'] ) ? wp_unslash( $_POST['target'] ) : 'global'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$target     = $this->parse_recipe_target( $raw_target );

		// 表單一律把「全部啟用中的耗材」列出來、每一列一個數量欄位，沒填或填 0
		// 的由 save_recipe_rows() 自己丟掉。這比「按鈕新增一列」的重複欄位簡單
		// 得多，而耗材的數量級（一間店幾十種）也撐得住。
		$qty      = isset( $_POST['qty'] ) && is_array( $_POST['qty'] ) ? wp_unslash( $_POST['qty'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$per_unit = isset( $_POST['per_unit'] ) && is_array( $_POST['per_unit'] ) ? wp_unslash( $_POST['per_unit'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$rows = array();
		foreach ( $qty as $consumable_id => $value ) {
			$consumable_id = absint( $consumable_id );
			$rows[]        = array(
				'consumable_id'   => $consumable_id,
				'qty_per_service' => (float) wc_format_decimal( $value ),
				'per_unit'        => ! empty( $per_unit[ $consumable_id ] ),
			);
		}

		UAPPT_Consumable::save_recipe_rows( $target['scope'], $target['product_id'], $target['plan_key'], $rows );

		$this->redirect(
			'uappt-consumables',
			array(
				'tab'         => 'recipes',
				'target'      => $raw_target,
				'uappt_notice' => 'consumable_recipe_saved',
			)
		);
	}

	/**
	 * 依流水帳重算所有結存（帳面跟明細對不上時的善後工具）。
	 */
	public function handle_recalculate_consumable_stock() {
		if ( ! current_user_can( UAPPT_Caps::CAP_MANAGE_CONSUMABLES ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		$this->require_module( 'consumables' );
		check_admin_referer( 'uappt_recalculate_consumable_stock' );

		$fixed = UAPPT_Consumable::recalculate_stock();

		$this->redirect(
			'uappt-consumables',
			array(
				'uappt_notice' => 'consumable_recalculated',
				'uappt_count'  => $fixed,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * 回訪管理（v2.28.0）
	 * ------------------------------------------------------------------- */

	/**
	 * 一頁列幾位。刻意不多：這是一份「今天要打的電話」清單，不是資料匯出——
	 * 一次列 200 位只會讓人不知道從哪裡開始打。
	 */
	const LAPSED_PER_PAGE = 30;

	/**
	 * 輸出回訪管理頁。
	 */
	public function render_customers_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : UAPPT_Customer::DEFAULT_LAPSED_DAYS; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $days < 1 ) {
			$days = UAPPT_Customer::DEFAULT_LAPSED_DAYS;
		}

		$filters = array(
			'days'              => $days,
			'staff_id'          => isset( $_GET['staff_id'] ) ? absint( $_GET['staff_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'include_contacted' => ! empty( $_GET['include_contacted'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'paged'             => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'per_page'          => self::LAPSED_PER_PAGE,
		);

		$result     = UAPPT_Customer::get_lapsed( $filters );
		$staff_list = UAPPT_Staff::get_all();

		self::page_open( __( '回訪管理', 'ultimate-appointments' ) );
		require UAPPT_PLUGIN_DIR . 'includes/views/customers.php';
		self::page_close();
	}

	/**
	 * 標記某位客人「已聯絡」。
	 */
	public function handle_mark_customer_contacted() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		$this->require_module( 'customer_followup' );
		check_admin_referer( 'uappt_mark_customer_contacted' );

		$ckey = isset( $_POST['ckey'] ) ? sanitize_text_field( wp_unslash( $_POST['ckey'] ) ) : '';
		$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

		if ( '' === $ckey ) {
			$this->redirect_with_error( 'uappt-customers', __( '找不到這位客人。', 'ultimate-appointments' ) );
		}

		UAPPT_Customer::mark_contacted( $ckey, $note );

		// 篩選條件要帶回去，不然按一下就跳回預設的 60 天、第一頁。
		$this->redirect( 'uappt-customers', array_merge( $this->customer_redirect_args(), array( 'uappt_notice' => 'customer_contacted' ) ) );
	}

	/**
	 * 取消「已聯絡」的標記。
	 */
	public function handle_clear_customer_contacted() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		$this->require_module( 'customer_followup' );
		check_admin_referer( 'uappt_clear_customer_contacted' );

		$ckey = isset( $_POST['ckey'] ) ? sanitize_text_field( wp_unslash( $_POST['ckey'] ) ) : '';
		if ( '' !== $ckey ) {
			UAPPT_Customer::clear_contacted( $ckey );
		}

		$this->redirect( 'uappt-customers', array_merge( $this->customer_redirect_args(), array( 'uappt_notice' => 'customer_contact_cleared' ) ) );
	}

	/**
	 * 回訪管理的篩選條件（寫入動作轉址時要帶回去）。
	 *
	 * 從 `$_POST` 讀而不是 `$_GET`：轉址發生在 admin-post.php，那裡的 `$_GET`
	 * 是空的，篩選條件是表單自己用 hidden 欄位帶過來的。
	 *
	 * @return array
	 */
	protected function customer_redirect_args() {
		$args = array();

		foreach ( array( 'days', 'staff_id', 'paged' ) as $key ) {
			if ( ! empty( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$args[ $key ] = absint( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}
		if ( ! empty( $_POST['include_contacted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$args['include_contacted'] = 1;
		}

		return $args;
	}

	/* ---------------------------------------------------------------------
	 * 排班申請審核頁面
	 * ------------------------------------------------------------------- */

	/**
	 * 一頁最多顯示幾個「批次」。跟 UAPPT_Shift_Request::MAX_BATCH_DAYS 一起看：
	 * 一批最多 92 天，撈 2000 列原始資料綽綽有餘地涵蓋好幾頁批次，分頁只在
	 * 「批次」這個顯示單位上做，不是在原始列上做。
	 */
	const SHIFT_BATCHES_PER_PAGE = 20;

	/**
	 * 輸出排班申請審核頁：依狀態分頁籤（預設待審），依批次分組顯示——一位
	 * 員工排一整個月只佔一列，展開才看得到逐日明細。每筆待審申請都先跑一次
	 * `UAPPT_Shift_Request::find_conflicts()` 讓審核者看得到「核准後會不會
	 * 影響到既有預約」，在批次層彙總成「N 天可核准、M 天有硬衝突」——這裡
	 * 看到的只是參考，真正的防線在 approve()／approve_batch() 核准當下會
	 * 重跑一次。
	 */
	public function render_shift_requests_page( array $tabs = array(), $tab = 'requests' ) {
		if ( ! current_user_can( UAPPT_Caps::CAP_APPROVE_SHIFT_REQUESTS ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : UAPPT_Shift_Request::STATUS_PENDING; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$valid_statuses = array(
			UAPPT_Shift_Request::STATUS_PENDING,
			UAPPT_Shift_Request::STATUS_APPROVED,
			UAPPT_Shift_Request::STATUS_REJECTED,
			UAPPT_Shift_Request::STATUS_WITHDRAWN,
			'all',
		);
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			$status = UAPPT_Shift_Request::STATUS_PENDING;
		}

		$filters = array(
			'order_by_date_asc' => true,
			'limit'             => 2000,
		);
		if ( 'all' !== $status ) {
			$filters['status'] = $status;
		}
		$all_batches = UAPPT_Shift_Request::query_batches( $filters );

		// 分頁在「批次」這個顯示單位上做，不是在原始列上做——待審申請的
		// 衝突檢查成本隨批次天數線性增加，先切頁再算，才不會一次跑完幾百天
		// 的 dry-run。
		$paged       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total_pages = max( 1, (int) ceil( count( $all_batches ) / self::SHIFT_BATCHES_PER_PAGE ) );
		$batches     = array_slice( $all_batches, ( $paged - 1 ) * self::SHIFT_BATCHES_PER_PAGE, self::SHIFT_BATCHES_PER_PAGE );

		// 待審才需要跑衝突檢查——已經處理過的申請看歷史就好，不用每次開頁面
		// 都重算一次「假設核准後」的營業區間。彙總成「這一批有幾天硬衝突／
		// 軟提醒」，逐日明細仍然保留在 $conflicts_by_id 給展開區用。
		$conflicts_by_id = array();
		foreach ( $batches as &$batch ) {
			$batch['hard_dates'] = array();
			foreach ( $batch['rows'] as $row ) {
				if ( UAPPT_Shift_Request::STATUS_PENDING !== $row['status'] ) {
					continue;
				}
				$conflicts                   = UAPPT_Shift_Request::find_conflicts( $row );
				$conflicts_by_id[ $row['id'] ] = $conflicts;
				if ( ! empty( $conflicts['hard'] ) ) {
					$batch['hard_dates'][] = $row['request_date'];
				}
			}
		}
		unset( $batch );

		self::page_open( __( '人員管理', 'ultimate-appointments' ) );
		self::render_tabs( $tabs, $tab, self::url( 'staff' ) );
		require UAPPT_PLUGIN_DIR . 'includes/views/shift-requests.php';
		self::page_close();
	}

	/**
	 * 核准單一天的排班申請（展開區的逐日操作）。
	 */
	public function handle_approve_shift_request() {
		if ( ! current_user_can( UAPPT_Caps::CAP_APPROVE_SHIFT_REQUESTS ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		$this->require_module( 'staff_portal' );

		$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		check_admin_referer( 'uappt_review_shift_request_' . $request_id );

		$review_note = isset( $_POST['review_note'] ) ? sanitize_text_field( wp_unslash( $_POST['review_note'] ) ) : '';
		$result      = UAPPT_Shift_Request::approve( $request_id, get_current_user_id(), $review_note );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( 'uappt-shift-requests', $result->get_error_message() );
		}

		$this->redirect(
			'uappt-shift-requests',
			array( 'uappt_notice' => 'shift_request_approved' )
		);
	}

	/**
	 * 駁回單一天的排班申請（展開區的逐日操作）。
	 */
	public function handle_reject_shift_request() {
		if ( ! current_user_can( UAPPT_Caps::CAP_APPROVE_SHIFT_REQUESTS ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		$this->require_module( 'staff_portal' );

		$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		check_admin_referer( 'uappt_review_shift_request_' . $request_id );

		$review_note = isset( $_POST['review_note'] ) ? sanitize_text_field( wp_unslash( $_POST['review_note'] ) ) : '';
		$result      = UAPPT_Shift_Request::reject( $request_id, get_current_user_id(), $review_note );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( 'uappt-shift-requests', $result->get_error_message() );
		}

		$this->redirect(
			'uappt-shift-requests',
			array( 'uappt_notice' => 'shift_request_rejected' )
		);
	}

	/**
	 * 核准一整批（一列＝一批的主要動作）。允許部分成功——有硬衝突的那幾天
	 * 維持 pending，其餘照樣生效，通知訊息把兩個數字都講清楚（見
	 * render_notices() 的 shift_batch_approved 分支）。
	 */
	public function handle_approve_shift_batch() {
		if ( ! current_user_can( UAPPT_Caps::CAP_APPROVE_SHIFT_REQUESTS ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		$this->require_module( 'staff_portal' );

		$batch_key = isset( $_POST['batch_key'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_key'] ) ) : '';
		check_admin_referer( 'uappt_review_shift_batch_' . $batch_key );

		$review_note = isset( $_POST['review_note'] ) ? sanitize_text_field( wp_unslash( $_POST['review_note'] ) ) : '';
		$result      = UAPPT_Shift_Request::approve_batch( $batch_key, get_current_user_id(), $review_note );

		$this->redirect_back(
			'uappt-shift-requests',
			array(
				'uappt_notice' => 'shift_batch_approved',
				'uappt_count'  => $result['ok'],
				'uappt_failed' => count( $result['failed'] ),
			),
			array(
				'uappt_notice' => 'shift_batch_approved',
				'uappt_count'  => $result['ok'],
				'uappt_failed' => count( $result['failed'] ),
			)
		);
	}

	/**
	 * 駁回一整批。
	 */
	public function handle_reject_shift_batch() {
		if ( ! current_user_can( UAPPT_Caps::CAP_APPROVE_SHIFT_REQUESTS ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		$this->require_module( 'staff_portal' );

		$batch_key = isset( $_POST['batch_key'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_key'] ) ) : '';
		check_admin_referer( 'uappt_review_shift_batch_' . $batch_key );

		$review_note = isset( $_POST['review_note'] ) ? sanitize_text_field( wp_unslash( $_POST['review_note'] ) ) : '';
		$result      = UAPPT_Shift_Request::reject_batch( $batch_key, get_current_user_id(), $review_note );

		$this->redirect_back(
			'uappt-shift-requests',
			array(
				'uappt_notice' => 'shift_batch_rejected',
				'uappt_count'  => $result['ok'],
				'uappt_failed' => count( $result['failed'] ),
			),
			array(
				'uappt_notice' => 'shift_batch_rejected',
				'uappt_count'  => $result['ok'],
				'uappt_failed' => count( $result['failed'] ),
			)
		);
	}

	/**
	 * 排班申請的類型／狀態顯示文字，供審核頁與（之後的）前台員工中心共用。
	 *
	 * @param string $type 申請類型代碼。
	 * @return string
	 */
	public static function shift_request_type_label( $type ) {
		$labels = array(
			UAPPT_Shift_Request::TYPE_HOURS => __( '自訂時段', 'ultimate-appointments' ),
			UAPPT_Shift_Request::TYPE_LEAVE => __( '請假', 'ultimate-appointments' ),
			UAPPT_Shift_Request::TYPE_BLOCK => __( '時段佔用', 'ultimate-appointments' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * @param string $status 申請狀態代碼。
	 * @return string
	 */
	public static function shift_request_status_label( $status ) {
		$labels = array(
			UAPPT_Shift_Request::STATUS_PENDING   => __( '待審核', 'ultimate-appointments' ),
			UAPPT_Shift_Request::STATUS_APPROVED  => __( '已核准', 'ultimate-appointments' ),
			UAPPT_Shift_Request::STATUS_REJECTED  => __( '已駁回', 'ultimate-appointments' ),
			UAPPT_Shift_Request::STATUS_WITHDRAWN => __( '已撤回', 'ultimate-appointments' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * @param string $status 申請狀態代碼。
	 * @return string CSS class 後綴。
	 */
	public static function shift_request_status_class( $status ) {
		$map = array(
			UAPPT_Shift_Request::STATUS_PENDING   => 'warning',
			UAPPT_Shift_Request::STATUS_APPROVED  => 'success',
			UAPPT_Shift_Request::STATUS_REJECTED  => 'error',
			UAPPT_Shift_Request::STATUS_WITHDRAWN => 'muted',
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : 'muted';
	}

	/**
	 * 把一筆申請的內容（時段／時間）轉成一行文字描述，審核頁與（之後的）
	 * 前台員工中心共用，不要各自重寫一份格式化邏輯。
	 *
	 * @param array $request 申請資料列。
	 * @return string
	 */
	public static function shift_request_detail_label( $request ) {
		switch ( $request['type'] ) {
			case UAPPT_Shift_Request::TYPE_HOURS:
				$ranges = json_decode( $request['hours'], true );
				$labels = array();
				if ( is_array( $ranges ) ) {
					foreach ( $ranges as $range ) {
						if ( isset( $range[0], $range[1] ) ) {
							$labels[] = $range[0] . '–' . $range[1];
						}
					}
				}
				return implode( '、', $labels );
			case UAPPT_Shift_Request::TYPE_LEAVE:
				return __( '整天休假', 'ultimate-appointments' );
			case UAPPT_Shift_Request::TYPE_BLOCK:
				return $request['start_hm'] . '–' . $request['end_hm'];
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * 設定頁
	 * ------------------------------------------------------------------- */

	/**
	 * 輸出設定頁。
	 */
	public function render_settings_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$hold_minutes     = (int) get_option( 'uappt_hold_minutes', 15 );
		$slot_interval    = (int) get_option( 'uappt_slot_interval_minutes', 15 );
		$horizon_days     = (int) get_option( 'uappt_booking_horizon_days', 30 );
		$shop_closure     = UAPPT_Shop_Closure::get();
		$min_lead_minutes = (int) get_option( 'uappt_min_lead_minutes', 0 );
		$cancel_deadline  = (int) get_option( 'uappt_cancel_deadline_hours', 24 );
		$pending_timeout  = (int) get_option( 'uappt_pending_order_timeout_hours', 72 );
		$show_remaining   = UAPPT_Product::show_remaining_mode();
		$show_staff       = UAPPT_Product::staff_selector_enabled();
		$product_page_mode = UAPPT_Product::product_page_mode();
		$wizard_after_add  = UAPPT_Cart::after_add_destination();
		$card_style        = UAPPT_Card::style();
		$booking_page_id   = (int) get_option( 'uappt_booking_page_id', 0 );
		$staff_portal_show_amount = (bool) get_option( 'uappt_staff_portal_show_amount', 1 );

		// 時段分類（全站一份，v2.55.0 從人員身上搬過來）。
		$segment_defaults   = UAPPT_Product::default_segment_config();
		$segment_current    = UAPPT_Product::segment_config();
		$segment_labels     = $segment_current['labels'];
		$segment_boundaries = $segment_current['boundaries'];

		// 收款方式與費率（全站一份，v2.58.0）。設定頁永遠多渲染一列空白供新增，
		// 所以這裡只給已存在的列，空白列由 view 自己補。
		$payment_methods  = UAPPT_Payment::methods();
		$payment_gateways = UAPPT_Payment::available_gateways();
		$payment_unmapped = UAPPT_Payment::unmapped_gateways();

		$reminder = array(
			'customer_enabled'  => (int) get_option( 'uappt_reminder_customer_enabled', 1 ),
			'shop_enabled'      => (int) get_option( 'uappt_reminder_shop_enabled', 1 ),
			'send_time'         => UAPPT_Reminders::get_send_time(),
			// 服務前提醒是獨立的一組設定（見 UAPPT_Reminders::send_hour_reminders()）。
			'hour_enabled'      => (int) get_option( 'uappt_reminder2_enabled', 0 ),
			'hour_before_hours' => (int) get_option( 'uappt_reminder2_hours', 3 ),
		);

		$convertible_ids = UAPPT_Product_Type::get_convertible_product_ids();
		$converted_ids   = UAPPT_Product_Type::get_converted_product_ids();

		// 設定頁分成幾個頁籤（內容太長，全部攤在一頁找不到東西）。頁籤列是
		// 真的 <a> 連結、目前是哪一頁由 PHP 決定，JS 只是接手做「不重新整理
		// 就切換」；JS 掛掉時整頁重新載入一樣能用。
		$tabs        = self::settings_tabs();
		$current_tab = self::sanitize_settings_tab( isset( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		self::page_open( __( '設定', 'ultimate-appointments' ) );
		require UAPPT_PLUGIN_DIR . 'includes/views/settings.php';
		self::page_close();
	}

	/**
	 * 儲存設定。
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_save_settings' );

		update_option( 'uappt_hold_minutes', isset( $_POST['hold_minutes'] ) ? max( 1, absint( $_POST['hold_minutes'] ) ) : 15 );
		update_option( 'uappt_slot_interval_minutes', isset( $_POST['slot_interval_minutes'] ) ? max( 5, absint( $_POST['slot_interval_minutes'] ) ) : 15 );
		update_option( 'uappt_booking_horizon_days', isset( $_POST['booking_horizon_days'] ) ? max( 1, absint( $_POST['booking_horizon_days'] ) ) : 30 );

		// 公休日。⚠️ 這裡跟其他 option 一樣是**無條件寫入**——欄位沒送上來就等於
		// 「全部清空」，這正是設定頁所有頁籤的欄位都必須留在 DOM 裡的原因
		// （見 settings.php 的 uappt_tab_pane() 說明）。
		UAPPT_Shop_Closure::save(
			array(
				'weekdays' => isset( $_POST['shop_closure_weekdays'] ) && is_array( $_POST['shop_closure_weekdays'] ) ? wp_unslash( $_POST['shop_closure_weekdays'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'dates'    => isset( $_POST['shop_closure_dates'] ) && is_array( $_POST['shop_closure_dates'] ) ? wp_unslash( $_POST['shop_closure_dates'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			)
		);
		// ⚠️ 班別**不在這裡存了**（v2.98.0 搬到人員管理 ▸ 班別設定，見
		// handle_save_shift_presets()）。設定頁已經沒有班別欄位——這裡要是還照舊
		// 「無條件寫入」，存一次設定就會把所有班別清空。

		update_option( 'uappt_min_lead_minutes', isset( $_POST['min_lead_minutes'] ) ? max( 0, absint( $_POST['min_lead_minutes'] ) ) : 0 );
		update_option( 'uappt_cancel_deadline_hours', isset( $_POST['cancel_deadline_hours'] ) ? max( 0, absint( $_POST['cancel_deadline_hours'] ) ) : 24 );
		update_option( 'uappt_pending_order_timeout_hours', isset( $_POST['pending_order_timeout_hours'] ) ? max( 1, absint( $_POST['pending_order_timeout_hours'] ) ) : 72 );

		$show_remaining = isset( $_POST['show_remaining'] ) ? sanitize_key( wp_unslash( $_POST['show_remaining'] ) ) : 'auto';
		update_option( 'uappt_show_remaining', in_array( $show_remaining, array( 'auto', 'always', 'never' ), true ) ? $show_remaining : 'auto' );

		update_option( 'uappt_show_staff_selector', isset( $_POST['show_staff_selector'] ) ? 1 : 0 );

		// 精靈送出後去哪一頁。白名單比對，不是 'checkout' 一律當 'cart'——
		// 預設值本身就是 'cart'，未知值退回預設不會有意外。
		$wizard_after_add = isset( $_POST['wizard_after_add'] ) ? sanitize_key( wp_unslash( $_POST['wizard_after_add'] ) ) : 'cart';
		update_option( UAPPT_Cart::AFTER_ADD_OPTION, 'checkout' === $wizard_after_add ? 'checkout' : 'cart' );

		// 通知卡片的外觀。清洗在 UAPPT_Card 裡做——那支同時是渲染端，格式該由它
		// 自己定義，不要讓設定頁另外理解一次。
		update_option(
			UAPPT_Card::OPTION,
			UAPPT_Card::sanitize_style( isset( $_POST['card_style'] ) ? wp_unslash( $_POST['card_style'] ) : array() ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);

		// 時段分類。sanitize_segment_config() 會把格式不對的分界時間退回預設，
		// 並把錯誤訊息收進 $segment_errors——存檔不會因此失敗（其他設定照存），
		// 但要讓管理者看到哪一格填錯了，否則他會以為自己填的值生效了。
		$segment_errors = array();
		update_option(
			UAPPT_Product::SEGMENTS_OPTION,
			UAPPT_Product::sanitize_segment_config(
				array(
					'labels'     => isset( $_POST['segment_labels'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['segment_labels'] ) ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'boundaries' => isset( $_POST['segment_boundaries'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['segment_boundaries'] ) ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				),
				$segment_errors
			)
		);

		// 收款方式與費率。跟時段分類一樣，清洗錯誤不會讓整次存檔失敗，只會
		// 把那一格退回安全值並回報。
		$payment_errors = array();
		update_option(
			UAPPT_Payment::METHODS_OPTION,
			UAPPT_Payment::sanitize_methods(
				isset( $_POST['payment_methods'] ) ? wp_unslash( (array) $_POST['payment_methods'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$payment_errors
			)
		);

		// 前台員工中心要不要顯示業績金額。預設 1（顯示），所以第一次存檔時
		// 勾選框本來就是勾著的，不會因為「沒動它」而被關掉。
		update_option( 'uappt_staff_portal_show_amount', isset( $_POST['staff_portal_show_amount'] ) ? 1 : 0 );

		update_option( 'uappt_reminder_customer_enabled', isset( $_POST['reminder_customer_enabled'] ) ? 1 : 0 );
		update_option( 'uappt_reminder_shop_enabled', isset( $_POST['reminder_shop_enabled'] ) ? 1 : 0 );

		// 時間沿用人力資源那邊同一套寬鬆解析（接受 "930"、"9:5" 等寫法），
		// 解析不出來就退回預設值，不要存進格式錯誤的字串害排程判斷失準。
		$raw_time = isset( $_POST['reminder_send_time'] ) ? sanitize_text_field( wp_unslash( $_POST['reminder_send_time'] ) ) : '';
		update_option( 'uappt_reminder_send_time', self::normalize_time( $raw_time, '20:00' ) );

		update_option( 'uappt_reminder2_enabled', isset( $_POST['reminder2_enabled'] ) ? 1 : 0 );
		update_option( 'uappt_reminder2_hours', isset( $_POST['reminder2_hours'] ) ? max( 0, absint( $_POST['reminder2_hours'] ) ) : 3 );

		// 商品頁的預約介面。白名單比對，不信任表單送上來的值。
		$page_mode = isset( $_POST['product_page_mode'] ) ? sanitize_key( wp_unslash( $_POST['product_page_mode'] ) ) : 'classic';
		update_option( 'uappt_product_page_mode', in_array( $page_mode, array( 'classic', 'wizard', 'intro' ), true ) ? $page_mode : 'classic' );
		update_option( 'uappt_booking_page_id', isset( $_POST['booking_page_id'] ) ? absint( $_POST['booking_page_id'] ) : 0 );

		// 帶著頁籤一起轉址回去：不然在「通知提醒」改完按儲存，會被丟回第一個
		// 頁籤，還要自己再點一次才看得到剛剛改的東西。
		$this->redirect(
			'uappt-settings',
			array(
				'uappt_notice' => self::settings_saved_notice( $segment_errors, $payment_errors ),
				'tab'         => self::sanitize_settings_tab( isset( $_POST['uappt_tab'] ) ? wp_unslash( $_POST['uappt_tab'] ) : '' ),
			)
		);
	}

	/**
	 * 把使用者輸入的時間正規化為 HH:mm。
	 *
	 * @param string $raw     輸入值。
	 * @param string $default 解析失敗時的預設值。
	 * @return string
	 */
	protected static function normalize_time( $raw, $default ) {
		$raw = trim( (string) $raw );

		if ( preg_match( '/^(\d{1,2}):(\d{1,2})$/', $raw, $m ) ) {
			$hour   = (int) $m[1];
			$minute = (int) $m[2];
			return ( $hour <= 23 && $minute <= 59 ) ? sprintf( '%02d:%02d', $hour, $minute ) : $default;
		}

		if ( preg_match( '/^\d{1,4}$/', $raw ) ) {
			$len = strlen( $raw );
			if ( $len <= 2 ) {
				$hour   = (int) $raw;
				$minute = 0;
			} elseif ( 3 === $len ) {
				$hour   = (int) substr( $raw, 0, 1 );
				$minute = (int) substr( $raw, 1, 2 );
			} else {
				$hour   = (int) substr( $raw, 0, 2 );
				$minute = (int) substr( $raw, 2, 2 );
			}
			return ( $hour <= 23 && $minute <= 59 ) ? sprintf( '%02d:%02d', $hour, $minute ) : $default;
		}

		return $default;
	}

	/**
	 * 測試發送 LINE 訊息，把 LINE 回傳的結果原樣顯示出來。
	 *
	 * 送的是**跟真提醒同一支 render_flex() 畫出來的卡片**（v2.74.0 起，原本是一行
	 * 純文字）。純文字只驗得到「憑證對不對、ID 有沒有填錯」，但店家真正要確認的是
	 * 自己調的顏色、標題、問候語、按鈕在手機上長怎樣——設定頁右側的預覽是我們自己
	 * 畫的 HTML 模擬，不是 LINE 的排版引擎，只有這裡送得出真貨。
	 *
	 * 這也表示失敗訊息現在有兩種可能：憑證／ID 錯（LINE 回 Authentication failed
	 * 之類），或卡片設定本身讓 Flex JSON 不合法。兩種都直接把 LINE 的原文顯示出來，
	 * 分得出來；而且後者本來就會讓真的提醒一起失敗，店家該在這裡先知道。
	 */
	public function handle_test_line() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_test_line' );

		$target = isset( $_POST['test_target'] ) ? sanitize_text_field( wp_unslash( $_POST['test_target'] ) ) : '';

		if ( '' === $target ) {
			$this->redirect_with_error( 'uappt-settings', __( '請填入要測試的收件 ID。', 'ultimate-appointments' ), array( 'tab' => 'notify' ) );
		}

		$kind = isset( $_POST['test_kind'] ) ? sanitize_key( wp_unslash( $_POST['test_kind'] ) ) : UAPPT_Card::KIND_DAY;
		if ( ! array_key_exists( $kind, UAPPT_Card::sample_kinds() ) ) {
			$kind = UAPPT_Card::KIND_DAY;
		}

		$result = UAPPT_Line::push_flex( $target, UAPPT_Card::render_flex( UAPPT_Card::build_sample( $kind ) ) );

		if ( true !== $result ) {
			$this->redirect_with_error(
				'uappt-settings',
				sprintf(
					/* translators: %s: LINE 回傳的錯誤 */
					__( '測試發送失敗：%s', 'ultimate-appointments' ),
					$result
				),
				array( 'tab' => 'notify' )
			);
		}

		$this->redirect( 'uappt-settings', array( 'uappt_notice' => 'line_test_sent', 'tab' => 'notify' ) );
	}

	/**
	 * 後台手動重送某筆預約的提醒（客人說沒收到時用）。
	 */
	public function handle_resend_reminder() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}

		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		check_admin_referer( 'uappt_edit_booking_' . $booking_id );

		$booking = $booking_id ? UAPPT_Booking::get( $booking_id ) : null;
		if ( ! $booking ) {
			$this->redirect_with_error( 'uappt-bookings', __( '找不到這筆預約。', 'ultimate-appointments' ) );
		}

		$result = UAPPT_Reminders::send_one( $booking );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error(
				'uappt-bookings',
				$result->get_error_message(),
				array(
					'action'     => 'edit',
					'booking_id' => $booking_id,
				)
			);
		}

		$this->redirect(
			'uappt-bookings',
			array(
				'action'      => 'edit',
				'booking_id'  => $booking_id,
				'uappt_notice' => 'reminder_sent',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * 共用工具
	 * ------------------------------------------------------------------- */

	/**
	 * 這筆預約是不是「已下單、等待付款」。
	 *
	 * `held` 有兩種完全不同的處境，資料庫是同一個狀態，但對店家的意義差很多：
	 * 客人還在購物車裡（15 分鐘後會自動釋放），或訂單已經成立、只是還沒收到款
	 * （例如銀行轉帳，訂單停在「保留」）。後者不會逾時（見
	 * UAPPT_Booking::link_to_order() 會清掉 expires_at），店家要追的是款項，
	 * 不是等它自己消失，所以顯示上要分得出來。
	 *
	 * 刻意不新增資料庫狀態：這個區別完全能從「有沒有訂單」推導出來，多一個狀態
	 * 就多一組要維護的轉換規則。
	 *
	 * @param array $booking 預約紀錄。
	 * @return bool
	 */
	public static function is_awaiting_payment( $booking ) {
		return isset( $booking['status'] )
			&& UAPPT_Booking::STATUS_HELD === $booking['status']
			&& ! empty( $booking['order_id'] );
	}

	/**
	 * 取得一筆預約的顯示狀態標籤（會把「待付款」跟「暫留中」分開）。
	 *
	 * 呼叫端只要手上有完整的預約列，就一律用這支，不要直接用 status_label()——
	 * 那支只認得狀態代碼，看不出「有沒有訂單」這件事。
	 *
	 * @param array $booking 預約紀錄。
	 * @return string
	 */
	public static function booking_status_label( $booking ) {
		if ( self::is_awaiting_payment( $booking ) ) {
			return __( '待付款', 'ultimate-appointments' );
		}
		return self::status_label( $booking['status'] );
	}

	/**
	 * 取得一筆預約的顯示狀態 CSS class 後綴。
	 *
	 * @param array $booking 預約紀錄。
	 * @return string
	 */
	public static function booking_status_class( $booking ) {
		if ( self::is_awaiting_payment( $booking ) ) {
			return 'warning';
		}
		return self::status_class( $booking['status'] );
	}

	/**
	 * 依狀態代碼取得中文標籤。
	 *
	 * 只認得狀態代碼本身（篩選下拉這種手上沒有預約列的場合用）。手上有完整
	 * 預約列時請改用 booking_status_label()。
	 *
	 * @param string $status 狀態代碼。
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			UAPPT_Booking::STATUS_HELD      => __( '暫留中', 'ultimate-appointments' ),
			UAPPT_Booking::STATUS_CONFIRMED => __( '已確認', 'ultimate-appointments' ),
			UAPPT_Booking::STATUS_CANCELLED => __( '已取消', 'ultimate-appointments' ),
			UAPPT_Booking::STATUS_EXPIRED   => __( '已逾時釋放', 'ultimate-appointments' ),
			UAPPT_Booking::STATUS_COMPLETED => __( '已完成', 'ultimate-appointments' ),
			UAPPT_Booking::STATUS_NO_SHOW   => __( '未到', 'ultimate-appointments' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * 依狀態代碼取得 CSS class 後綴。
	 *
	 * @param string $status 狀態代碼。
	 * @return string
	 */
	public static function status_class( $status ) {
		$map = array(
			UAPPT_Booking::STATUS_HELD      => 'warning',
			UAPPT_Booking::STATUS_CONFIRMED => 'success',
			UAPPT_Booking::STATUS_CANCELLED => 'muted',
			UAPPT_Booking::STATUS_EXPIRED   => 'muted',
			UAPPT_Booking::STATUS_COMPLETED => 'info',
			UAPPT_Booking::STATUS_NO_SHOW   => 'error',
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : 'muted';
	}

	/**
	 * 依人員 ID 取得顯示名稱；沒有人員（0 或找不到）回傳「尚未分配」。
	 *
	 * 統一入口：booking-edit.php／bookings.php／handle_reassign_staff() 都要顯示
	 * 同一句「尚未分配」，不要各自寫一份字面字串，未來要改用詞只需要改這裡。
	 *
	 * @param int $staff_id 人員 ID。
	 * @return string
	 */
	public static function staff_label( $staff_id ) {
		$staff_id = (int) $staff_id;
		if ( ! $staff_id ) {
			return __( '—（尚未分配）', 'ultimate-appointments' );
		}
		$staff = UAPPT_Staff::get( $staff_id );
		return $staff ? $staff['name'] : __( '—（尚未分配）', 'ultimate-appointments' );
	}

	/**
	 * 轉址回某個後台頁面並附上查詢參數。
	 *
	 * @param string $page 頁面 slug。
	 * @param array  $args 額外查詢參數。
	 */
	protected function redirect( $page, $args = array() ) {
		$section = self::section_key( $page );
		$retired = self::retired_sections();

		// 併走的 section 在這裡就翻好，不要讓轉址落在舊位置、再被
		// maybe_redirect_legacy_page() 轉第二次——兩段轉址對使用者沒有差別，
		// 但除錯時會多一層霧。呼叫端自己帶的參數優先（例如已經指定 status）。
		if ( isset( $retired[ $section ] ) ) {
			$args    = array_merge( $retired[ $section ][1], $args );
			$section = $retired[ $section ][0];
		}

		wp_safe_redirect( self::url( $section, $args ) );
		exit;
	}

	/**
	 * 一筆預約「可以換給誰做」的候選人員清單。
	 *
	 * 從 booking-edit.php 抽出來共用（v2.37.0）——今日營運的「待分派」現在
	 * 也要就地指派，兩邊的名單必須一模一樣，不然會出現「在首頁選得到、
	 * 送出卻被打回」的狀況。
	 *
	 * 名單是「這項服務目前的候選人員、且在職」，**不是「現在有空的人」**：
	 * 有沒有空由 `UAPPT_Booking::reassign_staff()` 在送出當下判斷，跟編輯頁
	 * 一直以來的行為相同。
	 *
	 * @param array $booking 預約資料列。
	 * @return array
	 */
	public static function reassign_candidates( array $booking ) {
		$settings = UAPPT_Product::get_booking_settings(
			$booking['product_id'],
			isset( $booking['plan_key'] ) ? (string) $booking['plan_key'] : ''
		);

		if ( ! $settings ) {
			return array();
		}

		$candidates = array();
		foreach ( UAPPT_Staff::get_many( $settings['staff_ids'] ) as $staff ) {
			if ( 'active' === $staff['status'] ) {
				$candidates[] = $staff;
			}
		}

		usort(
			$candidates,
			function ( $a, $b ) {
				return $a['sort_order'] <=> $b['sort_order'];
			}
		);

		return $candidates;
	}

	/**
	 * 寫入動作的模組檢查——gating 的第 3 層。
	 *
	 * ⚠️ **第 1 層（區塊不進陣列）擋不住這個。** 後台的寫入全部走
	 * `admin-post.php`，跟 section dispatch 完全無關：知道 action 名稱就能直接
	 * 打，UI 上什麼都沒有、功能卻是通的。而且漏掉完全看不出來——這是整套
	 * 模組工程裡最容易漏、也最難發現的一層。
	 *
	 * 訊息刻意跟「權限不足」分開：這不是權限問題，是這個站台沒有買這項功能，
	 * 講清楚才不會讓人以為是自己的帳號有問題。
	 *
	 * @param string $module 模組 key。
	 */
	protected function require_module( $module ) {
		if ( ! UAPPT_Modules::enabled( $module ) ) {
			wp_die( esc_html__( '這項功能未啟用。', 'ultimate-appointments' ) );
		}
	}

	/**
	 * 寫入完成之後要轉回哪裡。
	 *
	 * v2.37.0 新增，讓「今日營運」上的就地操作按完之後留在原地。在這之前
	 * 全外掛沒有任何 handler 會回到來源頁（`redirect()` 一律轉到固定 slug），
	 * 所以快捷按鈕按下去會被彈到預約列表——比不做還糟。
	 *
	 * ⚠️ **傳的是 section key，不是網址，而且一定要比對白名單。**
	 * 直接讓表單帶完整網址、或直接信 `wp_get_referer()`，都是開放轉址
	 * （open redirect）。這裡的值只能是 `sections()` 裡的 key，而那份清單
	 * 本身已經依 capability 過濾過——使用者連自己沒權限的區塊都轉不過去，
	 * 攻擊面是零。
	 *
	 * @param string $fallback_page 沒有指定時要去的頁面（舊 slug 或 section key）。
	 * @param array  $fallback_args 沒有指定時要帶的參數。
	 * @param array  $return_args   回到來源頁時要帶的參數（通常只有通知訊息）。
	 */
	protected function redirect_back( $fallback_page, array $fallback_args, array $return_args = array() ) {
		$target   = isset( $_REQUEST['uappt_return'] ) ? sanitize_key( wp_unslash( $_REQUEST['uappt_return'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sections = $this->sections();

		if ( '' !== $target && isset( $sections[ $target ] ) ) {
			$this->redirect( $target, $return_args );
		}

		$this->redirect( $fallback_page, $fallback_args );
	}

	/**
	 * 表單裡的「按完回哪裡」欄位。放在有就地操作的清單上，值是目前所在的
	 * section key。
	 *
	 * @param string $section section key。
	 */
	public static function return_field( $section ) {
		printf( '<input type="hidden" name="uappt_return" value="%s" />', esc_attr( $section ) );
	}

	/**
	 * 把呼叫端傳進來的目標轉成 section key。
	 *
	 * 全外掛有 70 幾處 `redirect( 'uappt-bookings', … )` 這種寫法，v2.35.0 收攏
	 * 成單一頁面之後那些字串仍然讀得懂「要回哪一頁」，所以**刻意不逐一改寫**，
	 * 改在這裡翻譯——舊 slug 與 section key 都收。少改 70 個地方就少 70 個
	 * 改錯的機會，而且轉址網址的組裝仍然只有 url() 一個出口。
	 *
	 * @param string $page 舊的頁面 slug 或 section key。
	 * @return string
	 */
	protected static function section_key( $page ) {
		$legacy = self::legacy_slugs();

		if ( isset( $legacy[ $page ] ) ) {
			return $legacy[ $page ];
		}

		return self::PAGE_SLUG === $page ? '' : $page;
	}

	/**
	 * 轉址並帶上錯誤訊息。
	 *
	 * @param string $page 頁面 slug。
	 * @param string $message 錯誤訊息。
	 * @param array  $extra 額外查詢參數。
	 */
	protected function redirect_with_error( $page, $message, $extra = array() ) {
		// 錯誤也要回到來源頁。少了這一段，今日營運上的就地操作一失敗就會把人
		// 丟到預約列表看一則沒頭沒尾的紅字——比操作失敗本身更讓人困惑。
		$target   = isset( $_REQUEST['uappt_return'] ) ? sanitize_key( wp_unslash( $_REQUEST['uappt_return'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sections = $this->sections();
		if ( '' !== $target && isset( $sections[ $target ] ) ) {
			$page  = $target;
			$extra = array();
		}

		$this->redirect(
			$page,
			array_merge(
				array(
					'uappt_notice'  => 'error',
					'uappt_message' => rawurlencode( $message ),
				),
				$extra
			)
		);
	}
}
