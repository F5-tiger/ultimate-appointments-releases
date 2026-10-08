<?php
/**
 * Capability／角色註冊：第二梯隊「排班申請＋審核」跟「前台員工中心」的地基。
 *
 * 全外掛在這之前只有一個 capability（`UAPPT_Admin::CAP`，值是
 * `manage_woocommerce`），後台 6 個頁面、17 個 admin-post 寫入動作全部
 * 共用同一個檢查。這裡新增 6 個**真正的、有名字**的 WordPress capability，
 * 而不是寫死角色名稱（例如 `current_user_can('administrator')`）——店家會
 * 用「User Role Editor」這類外掛調整權限，那類外掛操作的對象是
 * capability，寫死角色名稱的話，那邊完全沒有東西可以勾選調整。
 *
 * 六個 capability：
 * - `uappt_manage_bookings`：`UAPPT_Admin::CAP` 的新值，涵蓋原本
 *   `manage_woocommerce` 在管的全部 6 個頁面／17 個寫入動作，行為完全
 *   不變，只是換一個名字。
 * - `uappt_approve_shift_requests`：審核排班申請。刻意跟上面那個分開——
 *   資深員工可以幫忙審班表，但不需要因此拿到全部的預約管理權限。
 * - `uappt_submit_shift_requests`／`uappt_view_own_schedule`／
 *   `uappt_manage_own_bookings`：前台員工中心要用的三個，新角色
 *   `uappt_staff`（服務人員）預設就有這三個。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Caps {

	/**
	 * 記錄「目前站台的 capability 授權跑到哪一版」的 option 名稱，跟資料庫
	 * migration 是同一種概念，只是對象是角色的 capability，不是資料表結構。
	 */
	const VERSION_OPTION = 'uappt_caps_version';

	/**
	 * 目前的授權版本。改了下面 install() 授權的內容（新增 capability、
	 * 調整哪個角色該有哪個）就要遞增這個數字，讓既有站台在下次載入時
	 * 重新跑一次授權。
	 */
	const CURRENT_VERSION = 2;

	// 跟 UAPPT_Admin::CAP 的值必須完全一致（那邊是原本就存在的字面字串，
	// 為了不讓兩個類別互相耦合載入順序，這裡沒有直接引用它，而是各自寫死
	// 同一個字串——只有這一個地方需要手動保持同步）。
	const CAP_MANAGE_BOOKINGS         = 'uappt_manage_bookings';
	const CAP_APPROVE_SHIFT_REQUESTS  = 'uappt_approve_shift_requests';
	// v2.24.0。刻意跟 CAP_MANAGE_BOOKINGS 分開：管庫存跟管預約是兩種工作，
	// 店長可能想讓助理管耗材進出，但不給他動客人的預約。
	const CAP_MANAGE_CONSUMABLES      = 'uappt_manage_consumables';
	const CAP_SUBMIT_SHIFT_REQUESTS   = 'uappt_submit_shift_requests';
	const CAP_VIEW_OWN_SCHEDULE       = 'uappt_view_own_schedule';
	const CAP_MANAGE_OWN_BOOKINGS     = 'uappt_manage_own_bookings';

	/**
	 * 新角色的 slug。刻意不給 `read` capability——服務人員只透過前台的
	 * WooCommerce 會員中心操作（那是任何登入使用者都看得到的前台頁面，不
	 * 需要 `read` 這種「能不能進 wp-admin」的權限），完全不該、也不需要
	 * 進到 wp-admin，這是刻意的安全邊界，不是漏掉。
	 */
	const STAFF_ROLE = 'uappt_staff';

	/**
	 * 掛載執行期的防呆保險絲。跟 install() 分開：install() 只在版本號變動
	 * 時跑一次（寫進資料庫），這支每次請求都要掛，是執行期規則。
	 */
	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'ensure_admin_has_manage_bookings' ), 10, 4 );
	}

	/**
	 * 版本號不符才真的執行 install()。跟其他一次性 migration 不同，這裡
	 * 不需要等 WooCommerce 或任何 taxonomy 就緒——`get_role()`／`add_role()`
	 * 是 WordPress 核心角色系統的一部分，`UAPPT_Install::install()` 與
	 * `maybe_upgrade()` 呼叫的當下就已經可以安全使用，不用延後掛在 init。
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::VERSION_OPTION ) === self::CURRENT_VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * 實際的授權：administrator 拿全部 6 個、shop_manager 拿管理＋審核＋耗材
	 * 三個、新角色 `uappt_staff` 拿前台員工中心要用的 3 個。
	 *
	 * `add_role()` 對已經存在的角色是 no-op（不會補上新的 capability），
	 * 所以角色已存在時要額外用 `add_cap()` 補——這是下次調整 capability
	 * 清單、遞增 CURRENT_VERSION 讓既有站台重跑這支時，真正會用到的路徑。
	 */
	public static function install() {
		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			foreach ( self::all_caps() as $cap ) {
				$administrator->add_cap( $cap );
			}
		}

		$shop_manager = get_role( 'shop_manager' );
		if ( $shop_manager ) {
			$shop_manager->add_cap( self::CAP_MANAGE_BOOKINGS );
			$shop_manager->add_cap( self::CAP_APPROVE_SHIFT_REQUESTS );
			$shop_manager->add_cap( self::CAP_MANAGE_CONSUMABLES );
		}

		if ( ! get_role( self::STAFF_ROLE ) ) {
			add_role(
				self::STAFF_ROLE,
				__( '服務人員', 'ultimate-appointments' ),
				array(
					self::CAP_SUBMIT_SHIFT_REQUESTS => true,
					self::CAP_VIEW_OWN_SCHEDULE      => true,
					self::CAP_MANAGE_OWN_BOOKINGS    => true,
				)
			);
		} else {
			$staff_role = get_role( self::STAFF_ROLE );
			$staff_role->add_cap( self::CAP_SUBMIT_SHIFT_REQUESTS );
			$staff_role->add_cap( self::CAP_VIEW_OWN_SCHEDULE );
			$staff_role->add_cap( self::CAP_MANAGE_OWN_BOOKINGS );
		}

		update_option( self::VERSION_OPTION, self::CURRENT_VERSION );
	}

	/**
	 * 全部 6 個 capability，供 install() 跟未來要列出完整清單的地方共用
	 * （例如卸載外掛時要把 capability 從所有角色清乾淨）。
	 *
	 * @return string[]
	 */
	public static function all_caps() {
		return array(
			self::CAP_MANAGE_BOOKINGS,
			self::CAP_APPROVE_SHIFT_REQUESTS,
			self::CAP_SUBMIT_SHIFT_REQUESTS,
			self::CAP_VIEW_OWN_SCHEDULE,
			self::CAP_MANAGE_OWN_BOOKINGS,
			self::CAP_MANAGE_CONSUMABLES,
		);
	}

	/**
	 * 保險絲：`manage_options`（等同「超級管理員」等級）永遠拿得到
	 * `uappt_manage_bookings`，就算店家用 User Role Editor 手滑把
	 * administrator 的這個 capability 取消勾選，站長還是進得去外掛的
	 * 後台頁面自己改回來，不會把自己整個鎖在外面。
	 *
	 * 刻意**不**做成「凡是 manage_woocommerce 就自動給 uappt_manage_bookings」
	 * 這種全面對映——那樣的話 User Role Editor 取消勾選 uappt_manage_bookings
	 * 會完全沒有作用，這個新 capability 就失去了獨立調整的意義。只保這一條
	 * 最底線的安全網。
	 *
	 * @param array   $allcaps 使用者目前擁有的所有 capability（true/false 對照表）。
	 * @param array   $caps    這次要檢查的 primitive capability 清單。
	 * @param array   $args    原始 current_user_can() 呼叫的參數。
	 * @param WP_User $user    使用者物件。
	 * @return array
	 */
	public static function ensure_admin_has_manage_bookings( $allcaps, $caps, $args, $user ) {
		if ( in_array( self::CAP_MANAGE_BOOKINGS, $caps, true ) && ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::CAP_MANAGE_BOOKINGS ] = true;
		}
		return $allcaps;
	}
}
