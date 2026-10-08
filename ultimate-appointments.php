<?php
/**
 * Plugin Name: Ultimate Appointments
 * Plugin URI:  https://nibill-studio.com/
 * Description: 新增「預約商品」商品類型，讓服務業以類似飯店控房的方式管理預約：一個商品可設定多個「服務方案」（名稱／時長／價格），客人選方案、選服務人員、選時段後才能加入購物車。僅在「加入購物車」與訂單狀態變化這幾個標準 WooCommerce 事件上串接，不影響既有結帳流程與電商模組。
 * Version:     3.2.1
 * Author:      快捷鍵Ctrl+A
 * Author URI:  https://nibill-studio.com/
 * Text Domain: ultimate-appointments
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 4.0
 * Update URI:  https://github.com/F5-tiger/ultimate-appointments-releases
 *
 * 名稱：中文「終極預約」、英文「Ultimate Appointments」（v3.0.0 起，對內對外統一）。
 *
 * ⚠️ **外掛列表用英文名、後台選單與畫面用中文名，是刻意的。** 跟同一家族的
 * 終極電商（Ultimate E-commerce）、終極登入（Ultimate Login）同一個作法：店家在
 * 「外掛」頁看到的是一整家族的英文名；後台選單、頁面標題與說明文字面對的是每天
 * 在用的店員，用「終極預約」比較好認。readme 的標題兩個一起寫：
 * `終極預約 (Ultimate Appointments)`。
 *
 * v3.0.0 以前的程式代號是 `wc-service-booking`／`WCSB_`／`wcsb_`，改名時連資料一起
 * 搬過來了（UAPPT_Legacy_Migration，計畫見 docs/rename-plan.md）。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 阻擋直接存取。
}

define( 'UAPPT_VERSION', '3.2.1' );
// UAPPT_VERSION 與 UAPPT_DB_VERSION 刻意分開：前者每次發版都推進（也是靜態資源
// 的快取破除鍵），後者只有真的動到資料表結構時才推。v2.18.0（批次匯入、個別
// 撤回）沿用既有的寫入方法、v2.19.0／v2.19.1／v2.19.2（後台視覺系統、對齊修正、手機版響應式）只動
// CSS 與 view、v2.22.0（現場建單自訂金額）只是讓既有的 bookings.amount 多一個
// 寫入入口、v2.25.0（報表擴充）只是新的聚合查詢、v2.26.0（本月明細月曆改版）
// 只動 view 與 CSS、v2.29.0（客人報表）也只是新的查詢，都沒有異動結構。
define( 'UAPPT_DB_VERSION', '2.93.0' ); // staff 新增 schedule_mode（固定班／彈性班）。
define( 'UAPPT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UAPPT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// 線上更新（從 GitHub 發版倉庫收新版）。排在任何依賴檢查之前，理由見 class 開頭。
require_once UAPPT_PLUGIN_DIR . 'includes/class-uappt-updater.php';
UAPPT_Updater::init( __FILE__ );

/**
 * 檢查 WooCommerce 是否已啟用，未啟用則顯示提示並不載入外掛主體。
 */
function uappt_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * 時區安全的「日期字串 + N 天」運算，回傳 Y-m-d。
 *
 * 全部明確以 wp_timezone() 為基準，不使用裸 strtotime()（會受 PHP 預設時區
 * 影響，該時區不一定等於網站在「設定 → 一般」設定的時區，兩者不一致時
 * 用 strtotime() 做日期加減會算出錯誤的日期）。
 *
 * @param string $date_ymd 起始日期 (Y-m-d)。
 * @param string $modify   要套用的 DateTime::modify() 字串，例如 '+7 days'、'-1 days'。可留空。
 * @return string Y-m-d，若解析失敗則回傳空字串。
 */
function uappt_local_date( $date_ymd, $modify = '' ) {
	$dt = date_create( $date_ymd . ' 00:00:00', wp_timezone() );
	if ( ! $dt ) {
		return '';
	}
	if ( $modify ) {
		$dt->modify( $modify );
	}
	return $dt->format( 'Y-m-d' );
}

/**
 * 時區安全地取得某日期是星期幾（ISO-8601，1=一 ... 7=日）。
 *
 * @param string $date_ymd 日期 (Y-m-d)。
 * @return int
 */
function uappt_local_weekday_iso( $date_ymd ) {
	$dt = date_create( $date_ymd . ' 00:00:00', wp_timezone() );
	return $dt ? (int) $dt->format( 'N' ) : 1;
}

/**
 * 時區安全地取得某日期是星期幾（PHP date('w') 慣例，0=日 ... 6=六）。
 *
 * @param string $date_ymd 日期 (Y-m-d)。
 * @return int
 */
function uappt_local_weekday_w( $date_ymd ) {
	$dt = date_create( $date_ymd . ' 00:00:00', wp_timezone() );
	return $dt ? (int) $dt->format( 'w' ) : 0;
}

/**
 * 載入所有類別檔案。
 */
function uappt_load_includes() {
	$includes = array(
		'includes/class-uappt-install.php',
		'includes/class-uappt-legacy-migration.php',
		'includes/class-uappt-modules.php',
		'includes/class-uappt-caps.php',
		// 店休：UAPPT_Staff::get_business_ranges_for_date() 會用它，要排在前面。
		'includes/class-uappt-shop-closure.php',
		'includes/class-uappt-staff.php',
		// 班別（全店共用的具名上班時段）。清洗時會呼叫
		// UAPPT_Staff::sanitize_ranges()，但那是執行期的呼叫，檔案層級沒有相依
		// ——排在 staff 後面只是歸類上跟班表相關的東西放一起。
		'includes/class-uappt-shift-preset.php',
		'includes/class-uappt-booking.php',
		'includes/class-uappt-shift-request.php',
		// 耗材：UAPPT_Booking::complete()／revert_to_confirmed() 會呼叫它，
		// 但那是執行期的呼叫，檔案層級沒有相依，排在這裡只是歸類上跟
		// bookings 相關的東西放一起。
		'includes/class-uappt-consumable.php',
		// 回訪管理。依賴 UAPPT_Booking::customer_key_sql()，但那是執行期的呼叫，
		// 檔案層級沒有相依。
		'includes/class-uappt-customer.php',
		'includes/class-uappt-import.php',
		// 收款方式與手續費費率。UAPPT_Admin／UAPPT_Order 都會用到，但它自己
		// 只依賴 UAPPT_Install::table()，放哪裡都安全。
		'includes/class-uappt-payment.php',
		'includes/class-uappt-product.php',
		'includes/class-uappt-product-type.php',
		// 一定要排在 class-uappt-product.php 後面：WATCHED_META 常數直接引用
		// UAPPT_Product::PLANS_META。
		'includes/class-uappt-service-index.php',
		// 前台可用性查詢的共用邊界（admin-ajax 與 REST 共用），要排在
		// class-uappt-ajax.php 前面。
		'includes/class-uappt-availability-query.php',
		'includes/class-uappt-rest.php',
		'includes/class-uappt-wizard.php',
		// 軟依賴 Elementor；檔案層級沒有任何 Elementor 類別，可以安全提前載入。
		'includes/class-uappt-elementor.php',
		'includes/class-uappt-cart.php',
		'includes/class-uappt-order.php',
		'includes/class-uappt-checkout.php',
		'includes/class-uappt-ajax.php',
		'includes/class-uappt-cron.php',
		'includes/class-uappt-admin.php',
		'includes/class-uappt-frontend.php',
		'includes/class-uappt-account.php',
		'includes/class-uappt-staff-portal.php',
		'includes/class-uappt-line.php',
		'includes/class-uappt-card.php',
		'includes/class-uappt-calendar.php',
		'includes/class-uappt-reminders.php',
	);

	foreach ( $includes as $file ) {
		$path = UAPPT_PLUGIN_DIR . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}

/**
 * 外掛啟動主流程。
 */
function uappt_bootstrap() {
	if ( ! uappt_is_woocommerce_active() ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' .
					esc_html__( '終極預約需要先安裝並啟用 WooCommerce 才能運作。', 'ultimate-appointments' ) .
					'</p></div>';
			}
		);
		return;
	}

	uappt_load_includes();

	// v3.0.0 改名（wc-service-booking／wcsb_ → ultimate-appointments／uappt_）的資料遷移。
	// ⚠️ 一定要排在 maybe_upgrade() 前面：那支會建資料表，先建了空的 uappt_ 表，舊資料就
	// 搬不進去了。沒有舊資料時只是一次 get_option()。見 docs/rename-plan.md。
	UAPPT_Legacy_Migration::maybe_run();

	// 外掛更新後（使用者未重新啟用）也要能補上新資料表欄位。
	UAPPT_Install::maybe_upgrade();

	// capability／角色授權有自己獨立的版本號（UAPPT_Caps::VERSION_OPTION），
	// 刻意不依附在 UAPPT_DB_VERSION 底下——以後只調整授權內容、沒有動到
	// 資料庫結構的情況下，maybe_upgrade() 根本不會被觸發，掛在那裡面會
	// 讓既有站台永遠補不到新的授權。這裡每次請求都呼叫，跟其他一次性
	// migration 一樣，version 沒變就是一次便宜的 no-op。
	UAPPT_Caps::maybe_install();
	UAPPT_Caps::init();

	UAPPT_Product::instance();
	UAPPT_Product_Type::instance();
	// 只掛失效用的 hook，沒有實例狀態，所以是靜態 init()（跟 UAPPT_Caps 一樣）。
	UAPPT_Service_Index::init();
	// 耗材的低量提醒掛在既有的提醒排程上，同樣沒有實例狀態。
	UAPPT_Consumable::init();
	// 前台預約精靈的唯讀 REST 端點。
	UAPPT_Rest::init();
	// 前台預約精靈的 shortcode [uappt_booking]。
	UAPPT_Wizard::init();
	UAPPT_Elementor::init();
	UAPPT_Cart::instance();
	UAPPT_Order::instance();
	// 預約專用的結帳頁精簡（只在購物車全是預約時生效）。
	UAPPT_Checkout::init();
	UAPPT_Ajax::instance();
	UAPPT_Cron::instance();
	UAPPT_Frontend::instance();
	UAPPT_Account::instance();
	// gating 的第 2 層。整個 class 不實例化，等於前台的兩個 rewrite 端點、
	// 「我的帳戶」選單項目、以及七個 admin_post_uappt_staff_* handler 全部不存在
	// ——一行蓋掉整組，比在七個 handler 裡各檢查一次可靠。
	if ( UAPPT_Modules::enabled( 'staff_portal' ) ) {
		UAPPT_Staff_Portal::instance();
	}
	UAPPT_Calendar::instance();
	UAPPT_Reminders::instance();

	if ( is_admin() ) {
		UAPPT_Admin::instance();
	}
}
add_action( 'plugins_loaded', 'uappt_bootstrap' );

/**
 * 固定班樣板：每天把班表往前鋪滿到開放預約期限。
 *
 * ⚠️ **每天跑，不是每月 1 號。** wp-cron 靠流量觸發，每月一次的排程萬一那天沒人
 * 造訪就整個月沒補上；每天跑的話延遲一天無害。只寫「本來就沒有列」的日子，所以
 * 穩定狀態下每位人員每天只會多一列。
 *
 * 掛在 init 而不是 UAPPT_Cron 的 5 分鐘排程裡：這是一天一次的事，擠進那支會讓它
 * 每 5 分鐘白跑一次 option 比對。用 option 記上次執行的日期來節流，跟 wp-cron
 * 本身是否準時無關。
 */
function uappt_daily_extend_template() {
	if ( ! class_exists( 'UAPPT_Staff' ) ) {
		return;
	}

	$today = current_time( 'Y-m-d' );
	if ( get_option( 'uappt_template_extended_on' ) === $today ) {
		return;
	}

	// 先記日期再做事：萬一中途出錯，下一個請求不會又跑一次（每次請求都重試一個
	// 會失敗的全站寫入，比晚一天補上班表糟糕得多）。
	update_option( 'uappt_template_extended_on', $today, false );
	UAPPT_Staff::extend_all_from_template();
}
add_action( 'init', 'uappt_daily_extend_template', 11 );

/**
 * WordPress 使用者被刪除時，解除他跟人員的綁定（不刪除人員本身，見
 * UAPPT_Staff::unlink_user() 的說明）。
 *
 * 刻意掛在頂層（不是 UAPPT_Admin 的建構子）：`deleted_user` 可能在
 * wp-admin 的「使用者」頁觸發，也可能透過 WP-CLI 或其他非 wp-admin 的
 * 路徑觸發，而 UAPPT_Admin 只在 is_admin() 為真時才會被建立實例
 * （見 uappt_bootstrap()）——掛在那裡面，非後台觸發的刪除就會漏接。
 * 這裡用跟其他頂層 hook 一樣的寫法：直接掛，方法內部再判斷類別是否已載入。
 *
 * @param int $user_id 被刪除的使用者 ID。
 */
function uappt_on_user_deleted( $user_id ) {
	if ( ! class_exists( 'UAPPT_Staff' ) ) {
		return;
	}
	$staff = UAPPT_Staff::get_by_user_id( $user_id );
	if ( $staff ) {
		UAPPT_Staff::unlink_user( $staff['id'] );
	}
}
add_action( 'deleted_user', 'uappt_on_user_deleted' );

/**
 * 如果有排定 flush rewrite rules（外掛啟用或升級後），在這次請求真的執行。
 *
 * 排在 init 的較後段（優先權 20）：UAPPT_Account 掛在 init 預設優先權（10）
 * 註冊「我的預約」端點，要等它註冊完，flush 出來的規則才會包含新端點，
 * 否則客人點進「我的預約」會看到 404。
 */
function uappt_maybe_flush_rewrite_rules() {
	if ( get_option( 'uappt_flush_rewrite_rules' ) ) {
		flush_rewrite_rules();
		delete_option( 'uappt_flush_rewrite_rules' );
	}
}
add_action( 'init', 'uappt_maybe_flush_rewrite_rules', 20 );

/**
 * 啟用外掛：建表 + 排程。
 */
function uappt_activate_plugin() {
	require_once UAPPT_PLUGIN_DIR . 'includes/class-uappt-install.php';
	// 改名遷移排在建表前面，理由同 uappt_bootstrap()。
	require_once UAPPT_PLUGIN_DIR . 'includes/class-uappt-legacy-migration.php';
	UAPPT_Legacy_Migration::maybe_run();
	UAPPT_Install::install();
	UAPPT_Install::schedule_rewrite_flush();

	require_once UAPPT_PLUGIN_DIR . 'includes/class-uappt-caps.php';
	UAPPT_Caps::maybe_install();

	require_once UAPPT_PLUGIN_DIR . 'includes/class-uappt-cron.php';
	UAPPT_Cron::schedule_events();
}
register_activation_hook( __FILE__, 'uappt_activate_plugin' );

/**
 * 停用外掛：清除排程（保留資料表，避免誤刪已產生的預約紀錄）。
 */
function uappt_deactivate_plugin() {
	require_once UAPPT_PLUGIN_DIR . 'includes/class-uappt-cron.php';
	UAPPT_Cron::clear_events();
}
register_deactivation_hook( __FILE__, 'uappt_deactivate_plugin' );

/**
 * 宣告 HPOS（High-Performance Order Storage）相容，避免在新版 WooCommerce 後台出現不相容警告。
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);
