<?php
/**
 * 功能模組開關：哪些功能在這個站台是開著的。
 *
 * 為日後分「基礎／專業／旗艦」收費做準備，做法參考同一套產品的終極電商
 * （`twshop_module_settings` ＋ `twshop_module_enabled()`）。完整的規劃與
 * 決策紀錄見 `docs/feature-modules-plan.md`。
 *
 * ## 只有五個開關
 *
 * 判準只有一條：**有沒有客戶會「買 A 但不買 B」**。開關存在的理由只有一個
 * ——它是一條收費的界線，不是為了「看起來模組化」，也不是為了對齊選單。
 *
 * 過不了這條判準的一律出貨，**基礎層完全不做成開關**：手動建單、日曆檢視、
 * 預約提醒、總覽與營收報表、預約精靈、批次匯入……沒有人買了預約外掛卻要關掉
 * 現場建單。一個永遠開著的開關不是開關，是三層 gating 的程式碼 ＋ 兩種狀態的
 * 測試成本 ＋ 一個出錯的機會，換零價值。
 *
 * ## 預設全關
 *
 * 結果跟終極電商一樣是預設全關，但理由不同：那邊是因為模組系統只有新裝才有；
 * 這裡是因為**「五個全關」本身就是一個完整可用的產品**——基礎版。部署時忘記
 * 設定的後果是客戶拿到可用的基礎版，不是一支壞掉的外掛，也不會默默送出旗艦
 * 功能。這也是為什麼「基礎層不做開關」不只是省事：它讓預設值這件事沒有兩難。
 *
 * ⚠️ **不做一次性遷移。** 2026-09-18 確認終極預約沒有客戶站台在用，補遷移
 * 等於把「預設全開」從後門放回來。
 *
 * ## enabled() 就是授權的抽象層
 *
 * 這一輪只做本地開關，但**所有判斷都只經過 `enabled()` 一個入口**。以後要換成
 * 「向伺服器問授權」時，只改這一支的內部實作，呼叫端一行都不用動。不需要為此
 * 先做多餘的介面層——一個 helper 本身就是那層抽象。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Modules {

	/**
	 * 存開關狀態的 option。值是 `key => '1'|'0'`。
	 */
	const OPTION = 'uappt_module_settings';

	/**
	 * 五個模組的 key 與預設值（`'1'` 開、`'0'` 關）。**預設值只有這一個來源**，
	 * definitions() 也是讀這裡。
	 *
	 * ⚠️ 跟 definitions() 分開是因為 enabled() 在 `plugins_loaded` 就會被呼叫（主檔
	 * 決定要不要載入員工中心），那時候還不能翻譯——definitions() 的標籤用了 `__()`，
	 * 會讓文字網域太早載入，WordPress 6.7 起每個請求記一則 notice（v3.0.2 以前
	 * debug.log 累積了上萬則）。判斷開關只需要 key 跟預設值，用不到標籤。
	 */
	const DEFAULTS = array(
		'staff_portal'      => '0',
		'reports_team'      => '0',
		'consumables'       => '0',
		'customer_followup' => '0',
		'reports_insight'   => '0',
	);

	/**
	 * 請求內快取。用 class 屬性而不是 settings() 裡的 `static` 變數，是因為
	 * 存檔之後同一個請求要有辦法把它清掉——`static` 從外面重設不了。
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * 五個模組。
	 *
	 * `tier` 只是分組顯示用的標籤，**不是資料上的狀態**——真正的事實來源永遠
	 * 是這五個開關本身（見 tier_presets() 的說明）。
	 *
	 * @return array
	 */
	public static function definitions() {
		return array(
			'staff_portal'      => array(
				'label'   => __( '員工中心', 'ultimate-appointments' ),
				'desc'    => __( '前台「我的班表」「本月明細」、員工自己標記完成，以及排班申請的送出與後台審核。含服務人員角色與權限。', 'ultimate-appointments' ),
				'tier'    => 'pro',
				'default' => self::DEFAULTS['staff_portal'],
			),
			'reports_team'      => array(
				'label'   => __( '人員報表', 'ultimate-appointments' ),
				'desc'    => __( '報表的「人員」頁籤：每位人員的業績、操作數，以及人員 × 項目。', 'ultimate-appointments' ),
				'tier'    => 'pro',
				'default' => self::DEFAULTS['reports_team'],
			),
			'consumables'       => array(
				'label'   => __( '耗材管理', 'ultimate-appointments' ),
				'desc'    => __( '耗材主檔與配方、預約完成時自動扣帳、盤點與低量提醒。', 'ultimate-appointments' ),
				'tier'    => 'ultimate',
				'default' => self::DEFAULTS['consumables'],
			),
			'customer_followup' => array(
				'label'   => __( '回訪管理', 'ultimate-appointments' ),
				'desc'    => __( '「該聯絡的客人」名單與聯絡紀錄：太久沒來、而且還沒約下一次的客人。', 'ultimate-appointments' ),
				'tier'    => 'ultimate',
				'default' => self::DEFAULTS['customer_followup'],
			),
			'reports_insight'   => array(
				'label'   => __( '經營分析報表', 'ultimate-appointments' ),
				'desc'    => __( '報表的「客人」「預約」頁籤：回店預約率、回訪週期、時段熱度與產能缺口。', 'ultimate-appointments' ),
				'tier'    => 'ultimate',
				'default' => self::DEFAULTS['reports_insight'],
			),
		);
	}

	/**
	 * 三個層級。
	 *
	 * ⚠️ 兩個欄位的語意不同，不要混用：
	 *
	 * - `modules` 是**累加**的（旗艦版含專業版那兩個）。它只用來反推
	 *   「目前落在哪一層」（`current_tier()`）。
	 * - `desc` 描述的是**這一組本身**，因為設定頁把它當成群組標題用，旁邊那個
	 *   開關管的也只是這一組（`$uappt_by_tier`），不是累加的層級。
	 *
	 * ⚠️ **層級不是第四種狀態。** 它在資料上不存在——按完按鈕之後系統不會記得
	 * 你按了哪一顆，真正的事實來源永遠是那五個開關。這樣「專業版加購耗材」
	 * 不需要任何架構改動，也不會出現「方案欄位說是專業、開關卻是旗艦」這種
	 * 對不起來的狀態。
	 *
	 * 層級是累加的，所以這裡也不能寫成三個獨立開關——「專業開、基礎關」沒有
	 * 意義。它是三顆一鍵套用的按鈕，不是一組核取方塊。
	 *
	 * @return array
	 */
	public static function tier_presets() {
		return array(
			'basic'    => array(
				'label'   => __( '基礎版', 'ultimate-appointments' ),
				// 基礎版沒有自己的模組，所以設定頁上沒有對應的群組——全部關掉
				// 就是基礎版。這一行目前沒有地方會印出來，留著是為了讓三個層級
				// 的定義完整。
				'desc'    => __( '接單、日曆、預約提醒、總覽與營收報表。', 'ultimate-appointments' ),
				'modules' => array(),
			),
			'pro'      => array(
				'label'   => __( '專業版', 'ultimate-appointments' ),
				'desc'    => __( '有團隊之後才需要的：誰做了什麼、做得怎樣。', 'ultimate-appointments' ),
				'modules' => array( 'staff_portal', 'reports_team' ),
			),
			'ultimate' => array(
				'label'   => __( '旗艦版', 'ultimate-appointments' ),
				'desc'    => __( '要控成本、要留住客人、要看得懂數字。', 'ultimate-appointments' ),
				'modules' => array( 'staff_portal', 'reports_team', 'consumables', 'customer_followup', 'reports_insight' ),
			),
		);
	}

	/**
	 * 目前的**基準層級**：全部模組都開著的最高層級。
	 *
	 * 三個方案是累加的，所以任何一組開關都必然落在某一層之上——這一支回答
	 * 「落在哪一層」，多出來的就是加購。方案開關（設定頁那三個滑桿）顯示的
	 * 就是這個值，所以它永遠只有一個是開的，也永遠跟模組開關一致。
	 *
	 * ⚠️ `assets/js/admin-modules.js` 有一份**同樣規則**的實作（撥開關時要
	 * 即時更新方案開關，不能每次都回伺服器問）。兩邊吃的是同一份
	 * `tier_presets()` 資料，改規則時兩邊都要動。
	 *
	 * @return string 方案 key。
	 */
	public static function current_tier() {
		$presets = self::tier_presets();
		$enabled = array_keys( array_filter( self::settings() ) );

		// 由高往低找：第一個「模組全都開著」的層級就是基準。
		foreach ( array_reverse( array_keys( $presets ) ) as $key ) {
			if ( ! array_diff( $presets[ $key ]['modules'], $enabled ) ) {
				return $key;
			}
		}

		return 'basic';
	}

	/**
	 * 某個模組是不是開著的。**全外掛判斷模組狀態的唯一入口。**
	 *
	 * 未知的 key 一律回傳 false：拼錯字的後果會是「那個功能不見了」，比
	 * 「拼錯字等於永遠開著」容易發現得多。
	 *
	 * @param string $key 模組 key。
	 * @return bool
	 */
	public static function enabled( $key ) {
		return ! empty( self::settings()[ $key ] );
	}

	/**
	 * 目前的開關狀態（`key => bool`），帶請求內快取。
	 *
	 * 讀不到設定時回退 `DEFAULTS`——預設值只有那一個來源，
	 * 不像終極電商在 helpers 與設定頁各寫一次 `?? '0'`，遲早會歪掉一邊。
	 *
	 * @return array
	 */
	public static function settings() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();

		foreach ( self::DEFAULTS as $key => $default ) {
			$raw         = isset( $saved[ $key ] ) ? $saved[ $key ] : $default;
			$out[ $key ] = ( '1' === (string) $raw );
		}

		self::$cache = $out;

		return self::$cache;
	}

	/**
	 * 寫入開關狀態。
	 *
	 * 只認 `DEFAULTS` 裡的 key，沒出現在 `$enabled` 裡的一律視為關閉
	 * ——表單的核取方塊沒勾就不會送出，這是必要的處理，不是防禦性程式碼。
	 *
	 * @param array $enabled 要打開的模組 key 清單。
	 */
	public static function save( array $enabled ) {
		$new = array();

		foreach ( array_keys( self::DEFAULTS ) as $key ) {
			$new[ $key ] = in_array( $key, $enabled, true ) ? '1' : '0';
		}

		update_option( self::OPTION, $new );
		self::flush_cache();
	}

	/**
	 * 清掉請求內快取。存檔之後同一個請求若再讀一次（例如存檔後重畫表單），
	 * 沒清的話會拿到舊值。
	 */
	public static function flush_cache() {
		self::$cache = null;
	}
}
