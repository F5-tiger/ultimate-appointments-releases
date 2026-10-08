<?php
/**
 * 排程：定期釋放逾時未付款的暫留時段。
 *
 * 這是「別人棄單後時段要重新開放」的保險機制——購物車移除/訂單取消等事件已經
 * 會即時釋放，這支 cron 是用來清掉那些客人直接關閉分頁、沒有觸發任何事件的
 * 殘留暫留。若網站流量低、WP-Cron（靠訪客觸發）可能不夠準時，建議改用主機的
 * 系統 cron 定期打 wp-cron.php。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Cron {

	const HOOK           = 'uappt_release_expired_holds';
	const HOOK_REMINDERS = 'uappt_send_reminders';
	const SCHEDULE       = 'uappt_five_minutes';

	/**
	 * @var UAPPT_Cron|null
	 */
	protected static $instance = null;

	/**
	 * 單例。
	 *
	 * @return UAPPT_Cron
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
		add_action( self::HOOK, array( __CLASS__, 'run' ) );

		// 保險絲：register_activation_hook 只有在外掛「真正從停用變啟用」時才觸發，
		// 用 zip 覆蓋更新已啟用中的外掛不會觸發它。這裡每次載入都補呼叫一次，
		// 讓升級的站台也能拿到 v1.4.0 新增的提醒排程。schedule_events() 內部有
		// wp_next_scheduled() 判斷，重複呼叫是安全的。
		self::schedule_events();
	}

	/**
	 * 註冊每 5 分鐘一次的自訂排程週期。
	 *
	 * @param array $schedules 現有排程週期。
	 * @return array
	 */
	public static function register_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( '每 5 分鐘（終極預約）', 'ultimate-appointments' ),
		);
		return $schedules;
	}

	/**
	 * 啟用外掛時排程事件。
	 */
	public static function schedule_events() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
		}
		if ( ! wp_next_scheduled( self::HOOK_REMINDERS ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK_REMINDERS );
		}
	}

	/**
	 * 停用外掛時清除排程事件。
	 */
	public static function clear_events() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::HOOK_REMINDERS );
	}

	/**
	 * 執行釋放逾時暫留。
	 */
	public static function run() {
		UAPPT_Booking::release_expired_holds();
		// 待付款訂單的安全網（見該方法的說明）：跟購物車暫留過期共用同一個
		// 5 分鐘排程，兩者都是「把不該再佔著時段的東西清掉」，沒必要為了
		// 這個多開一支排程。
		UAPPT_Booking::release_stale_pending_orders();
	}
}

/*
 * 註冊自訂的「每 5 分鐘」排程週期，刻意寫在檔案最外層（而不是放進建構子）：
 * 外掛啟用當下 uappt_activate_plugin() 是直接 require 這支檔案後呼叫
 * UAPPT_Cron::schedule_events()，並不會經過 plugins_loaded -> UAPPT_Cron::instance()
 * 那條路徑。如果只在建構子註冊，啟用當下呼叫 wp_schedule_event() 時
 * cron_schedules 篩選器根本還沒掛上，會因為排程週期不存在而悄悄失敗、
 * 永遠排不進去。寫在檔案最外層可以確保只要這支檔案被載入（不管是正常
 * bootstrap 還是啟用當下的直接 require），篩選器都一定已經註冊。
 */
add_filter( 'cron_schedules', array( 'UAPPT_Cron', 'register_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
