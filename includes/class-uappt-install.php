<?php
/**
 * 資料表安裝與升級。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Install {

	/**
	 * 建立/升級資料表，並寫入預設選項。
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'uappt_';

		$sql = array();

		// 人力資源（v2.0.0 起取代 pools 成為主要實體）。
		//
		// v2.93.0 新增 `schedule_mode`：`fixed`（固定班，樣板自動鋪）或 `flex`
		// （彈性班，樣板完全不參與）。預設空字串＝「還沒分類」，讀取時由
		// UAPPT_Staff 依樣板推導（v2.93.0 當時的一次性回填 v3.1.0 已刪除）。
		//
		// ⚠️ 跟 `is_24h` 是兩個欄位：畫面上合併成一個三選一（固定／彈性／24 小時），
		// 但十幾處讀 `is_24h` 的程式不用改，而且取消 24 小時會回到原本的方式。
		$sql[] = "CREATE TABLE {$prefix}staff (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			photo_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			business_hours LONGTEXT NULL,
			is_24h TINYINT(1) NOT NULL DEFAULT 0,
			schedule_mode VARCHAR(20) NOT NULL DEFAULT '',
			slot_interval SMALLINT UNSIGNED NULL,
			time_segments LONGTEXT NULL,
			price_adjustment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			commission LONGTEXT NULL,
			sort_order INT NOT NULL DEFAULT 0,
			capacity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			user_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY sort_order (sort_order),
			UNIQUE KEY user_id (user_id)
		) {$charset_collate};";

		// 人員個別請假／逐日調整。v2.14.0 起不再只是「整天休假」的二元開關——
		// `hours` 讓一天可以自訂跟每週範本不一樣的時段（例如「這天只上半天」），
		// `is_closed` 專門保留給整天休假；一天只有一列（見 UNIQUE KEY），
		// 半天假是用 `hours` 表達「剩下的那段」，不是另外開第二列。
		//
		// v2.84.0 新增 `closed_reason`：`is_closed=1` 時**為什麼關**——
		// `off`（例休，排定不上班）或 `leave`（請假）。
		//
		// ⚠️ **刻意不叫 `day_type`、也不跟 `is_closed` 重複表達同一件事。**
		// `is_closed` 回答「有沒有關」，這一欄只回答「為什麼關」，兩者沒有重疊，
		// 十幾處讀 `is_closed` 的查詢一行都不用改。做成一個 day_type 列舉
		// （hours/off/leave）的話 `is_closed` 就變成衍生欄位，兩邊不同步時沒有
		// 任何地方會報錯——那正是紀律 #4（同一件事只存一次）在防的東西。
		//
		// 為什麼要分：週日的「例休」跟臨時「請假」在班表上是兩件事（排班的人要
		// 分得出「這天我排過了，結論是休」與「這個人今天本來要上班卻請假」），
		// 而且分開之後報表才答得出「這個月請假幾天、排休幾天」。
		$sql[] = "CREATE TABLE {$prefix}staff_overrides (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			staff_id BIGINT UNSIGNED NOT NULL,
			override_date DATE NOT NULL,
			is_closed TINYINT(1) NOT NULL DEFAULT 0,
			closed_reason VARCHAR(20) NOT NULL DEFAULT '',
			hours LONGTEXT NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'manual',
			request_id BIGINT UNSIGNED NULL,
			note VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY staff_date (staff_id, override_date)
		) {$charset_collate};";

		// 時間格佔用表（控房核心）。v2.0.0 起改為逐人記錄：occupied 只會是 0 或 1，
		// UNIQUE(staff_id, slot_start) 讓資料庫本身成為「同一人同一時間格不可能有兩筆」
		// 的最後一道防線。
		$sql[] = "CREATE TABLE {$prefix}slot_grid (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			staff_id BIGINT UNSIGNED NOT NULL,
			slot_start DATETIME NOT NULL,
			occupied SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY staff_slot (staff_id, slot_start)
		) {$charset_collate} ENGINE=InnoDB;";

		// 預約紀錄表。service_start 的索引（v2.4.1 補上）是因為預約列表、日/月曆、
		// 提醒排程、店家明日清單、pick_least_booked() 幾乎所有查詢都在這個欄位上
		// 做範圍篩選＋排序；資料量小時看不出差異，但這是全外掛使用頻率最高的
		// 查詢模式，早點補上比之後資料量大了才發現效能問題便宜得多。
		//
		// `report` 複合索引（v2.27.0）：報表的每一支聚合查詢都是
		// `WHERE kind = ? AND status IN (...) AND service_start BETWEEN ? AND ?`，
		// 三個欄位的順序就照這個樣式排（等值的放前面、範圍的放最後，範圍條件
		// 之後的欄位用不到索引）。只有 service_start 單欄索引的話，得先掃出整段
		// 期間的所有列、再逐列過濾 kind 與 status。

		$sql[] = "CREATE TABLE {$prefix}bookings (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			staff_id BIGINT UNSIGNED NULL,
			staff_requested TINYINT(1) NOT NULL DEFAULT 0,
			staff_price_adjustment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			product_id BIGINT UNSIGNED NOT NULL,
			plan_key VARCHAR(64) NULL,
			plan_name VARCHAR(191) NULL,
			order_id BIGINT UNSIGNED NULL,
			order_item_id BIGINT UNSIGNED NULL,
			cart_item_key VARCHAR(191) NULL,
			customer_id BIGINT UNSIGNED NULL,
			customer_name VARCHAR(191) NULL,
			customer_phone VARCHAR(50) NULL,
			service_start DATETIME NOT NULL,
			service_end DATETIME NOT NULL,
			block_start DATETIME NOT NULL,
			block_end DATETIME NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'held',
			expires_at DATETIME NULL,
			grid_interval SMALLINT UNSIGNED NULL,
			note TEXT NULL,
			staff_note TEXT NULL,
			reminder_sent_at DATETIME NULL,
			reminder2_sent_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			assignment_state VARCHAR(20) NOT NULL DEFAULT '',
			kind VARCHAR(20) NOT NULL DEFAULT 'booking',
			occupied_units SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			amount DECIMAL(10,2) NULL,
			payment_method VARCHAR(32) NOT NULL DEFAULT '',
			fee_rate DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
			no_charge_reason VARCHAR(32) NOT NULL DEFAULT '',
			no_charge_note VARCHAR(191) NULL,
			PRIMARY KEY  (id),
			KEY staff_status (staff_id, status),
			KEY order_id (order_id),
			KEY cart_item_key (cart_item_key),
			KEY status_expires (status, expires_at),
			KEY customer_id (customer_id),
			KEY assignment_state (assignment_state),
			KEY kind (kind),
			KEY service_start (service_start),
			KEY report (kind, status, service_start)
		) {$charset_collate} ENGINE=InnoDB;";

		// 耗材主檔（v2.24.0）。刻意不用 WooCommerce 商品：耗材不賣，做成商品會被
		// 前台搜尋到、能加入購物車，庫存語意也對不上（ml／片／支 vs 件）。
		//
		// `unit` 是自由字串，因為兩種記法都要支援：填 `ml` 是精確記法，填 `次`
		// （一罐 = 30 次、每次扣 1）是次數法。美甲的凝膠「一罐 15ml、一次用不到
		// 1ml」實務上沒有人會去量，強迫精確記法只會讓店家不填。
		//
		// `stock` 是**結存快照**，真正的事實來源是 consumable_moves 的流水帳
		// （見那張表的說明）。DECIMAL(12,3)：次數法用不到小數，精確法用得到。
		$sql[] = "CREATE TABLE {$prefix}consumables (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			sku VARCHAR(64) NULL,
			unit VARCHAR(32) NOT NULL DEFAULT '',
			stock DECIMAL(12,3) NOT NULL DEFAULT 0.000,
			low_stock_threshold DECIMAL(12,3) NULL,
			unit_cost DECIMAL(10,2) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			note TEXT NULL,
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY sort_order (sort_order)
		) {$charset_collate} ENGINE=InnoDB;";

		// 配方：哪個服務會用掉哪些耗材、每次用多少。
		//
		// 三個 scope 沿用「留空＝沿用上層」的既有慣例（跟服務方案的 duration／
		// buffer／staff_ids 同一套心智模型），但 global 是 **union** 不是 override：
		// 毛巾、酒精棉這種「每位客人都用」的東西不該逐方案設定一次。
		//
		// `per_unit`：團體預約（occupied_units > 1）時要不要乘人數。毛巾要乘、
		// 凝膠罐不用。
		//
		// UNIQUE 的三個可為 NULL 的欄位在 MySQL 的唯一索引裡不會互相衝突
		// （NULL != NULL），所以 product_id／plan_key 一律存 0／''，不存 NULL。
		$sql[] = "CREATE TABLE {$prefix}consumable_recipes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			scope VARCHAR(20) NOT NULL DEFAULT 'product',
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			plan_key VARCHAR(64) NOT NULL DEFAULT '',
			consumable_id BIGINT UNSIGNED NOT NULL,
			qty_per_service DECIMAL(12,3) NOT NULL DEFAULT 0.000,
			per_unit TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY recipe_target (scope, product_id, plan_key, consumable_id),
			KEY consumable_id (consumable_id),
			KEY scope_product (scope, product_id)
		) {$charset_collate} ENGINE=InnoDB;";

		// 耗材異動流水帳。**扣帳一律寫這張表，不直接改 consumables.stock**，
		// 理由跟 bookings.amount 完全一樣（設計紀律 #4：紀錄自己存的值才靠得住）：
		//
		// - 有流水帳才查得出「這個月用掉多少、誰用的、哪筆預約用的」——報表要用
		// - 回沖＝寫一筆反向異動，不是刪掉原紀錄，稽核痕跡才不會消失
		// - 盤點校正寫的是「差額」，帳面與實際的落差因此留得下來（那正是耗損率）
		//
		// `unit_cost`／`cost_total` 是**當下的成本快照**：進貨價之後漲了不會回頭
		// 改歷史，報表的材料成本／毛利才站得住。
		$sql[] = "CREATE TABLE {$prefix}consumable_moves (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			consumable_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL,
			qty DECIMAL(12,3) NOT NULL,
			balance_after DECIMAL(12,3) NOT NULL,
			booking_id BIGINT UNSIGNED NULL,
			staff_id BIGINT UNSIGNED NULL,
			unit_cost DECIMAL(10,2) NULL,
			cost_total DECIMAL(10,2) NULL,
			note VARCHAR(191) NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY consumable_created (consumable_id, created_at),
			KEY booking_id (booking_id),
			KEY type_created (type, created_at),
			KEY created_at (created_at)
		) {$charset_collate} ENGINE=InnoDB;";

		// 回訪管理的聯絡紀錄（v2.28.0）。
		//
		// ⚠️ **關聯用的是報表那把「客人去重鍵」**（`UAPPT_Booking::customer_key_sql()`
		// 產生的 `c:123`／`p:0912345678`／`b:456`），不是會員 ID——這樣才涵蓋得到
		// 沒有帳號的純現場客人，而他們正是最需要被打電話的一群。
		//
		// **刻意不做客戶主檔。** 一旦開一張 customers 表，就得回答「這兩筆是不是
		// 同一個人」「合併之後歷史怎麼算」「誰能改」這一串問題，那是一個無底洞；
		// 而回訪管理真正需要的只有「這個鍵最後一次被聯絡是什麼時候」。
		//
		// `UNIQUE KEY customer_key` ＝ 一個客人只留最後一次聯絡（用
		// ON DUPLICATE KEY UPDATE 覆蓋）。完整的聯絡歷史目前用不到，真的需要時
		// 拿掉 UNIQUE 就變成流水帳，不必改結構。
		$sql[] = "CREATE TABLE {$prefix}customer_contacts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_key VARCHAR(191) NOT NULL,
			contacted_at DATETIME NOT NULL,
			note VARCHAR(255) NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY customer_key (customer_key),
			KEY contacted_at (contacted_at)
		) {$charset_collate} ENGINE=InnoDB;";

		// v1.x 的 pools／pool_overrides、bookings 的 pool_id／variation_id：v3.1.0 起不再建立
		// （讀它們的舊版升級程式已經刪掉）。**升級上來的站台已經存在的表與欄位不動**——
		// dbDelta 不會刪欄位，這裡也刻意不 DROP：不可逆，而且裡面是唯一能辨識 v1.x
		// 資料的線索。

		// 排班申請（v2.15.0）。刻意不塞進 bookings——那張表每一列都帶時段佔用，
		// 放不佔用時段的「申請」進去會汙染 query()、日檢視、
		// count_upcoming_for_staff() 與提醒查詢。核准後不消耗掉這一列（只是把
		// status 改成 approved、寫 applied_ref），是「誰申請、誰核准」的唯一憑證；
		// 真正生效的變更另外寫進 staff_overrides（hours/leave）或 bookings（block）。
		// 不下 UNIQUE(staff_id, request_date)：被駁回的歷史要能累積、同一天也可能
		// 同時有 hours 與 block 兩種申請，「同一天同類型只能有一筆 pending」交給
		// UAPPT_Shift_Request::has_pending() 用 PHP 檢查。
		//
		// batch_key（v2.17.0）：員工在月曆上一次勾選多天送出時，那幾天共用同一個
		// 隨機鍵，前後台就能把它們當成「一批」呈現與一鍵審核。刻意不另外開一張
		// batch header 表——一批需要的一切（人員、類型、日期範圍、送出時間、備註）
		// 都能從成員列推導出來，多一張表只是多一個要維持一致的地方。舊資料與
		// 單日申請維持 NULL，view 一律用「一批一筆」的方式渲染，不分岔。
		$sql[] = "CREATE TABLE {$prefix}shift_requests (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			staff_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL,
			request_date DATE NOT NULL,
			hours LONGTEXT NULL,
			start_hm VARCHAR(5) NULL,
			end_hm VARCHAR(5) NULL,
			units SMALLINT UNSIGNED NULL,
			staff_note VARCHAR(255) NULL,
			batch_key VARCHAR(40) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			reviewed_by BIGINT UNSIGNED NULL,
			reviewed_at DATETIME NULL,
			review_note VARCHAR(255) NULL,
			applied_ref BIGINT UNSIGNED NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY staff_status (staff_id, status),
			KEY status_date (status, request_date),
			KEY batch_key (batch_key)
		) {$charset_collate};";

		foreach ( $sql as $query ) {
			dbDelta( $query );
		}

		// 確保控房相關資料表使用 InnoDB（dbDelta 對已存在的表不會補 ENGINE，這裡再保險轉一次）。
		$wpdb->query( "ALTER TABLE {$prefix}slot_grid ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "ALTER TABLE {$prefix}bookings ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// 耗材扣帳要在交易裡做（結存快照與流水帳必須同生共死），所以同樣強制 InnoDB。
		$wpdb->query( "ALTER TABLE {$prefix}consumables ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "ALTER TABLE {$prefix}consumable_moves ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		update_option( 'uappt_db_version', UAPPT_DB_VERSION );

		// 預設選項。
		add_option( 'uappt_hold_minutes', 15 );
		add_option( 'uappt_slot_interval_minutes', 15 );
		add_option( 'uappt_booking_horizon_days', 30 );

		// v2.1.0 新增的預約政策與前台顯示設定。
		add_option( 'uappt_min_lead_minutes', 0 );
		add_option( 'uappt_cancel_deadline_hours', 24 );
		add_option( 'uappt_show_remaining', 'auto' );
		add_option( 'uappt_show_staff_selector', 1 );

		// v2.9.0：待付款訂單逾時安全網（見 UAPPT_Booking::release_stale_pending_orders()）。
		add_option( 'uappt_pending_order_timeout_hours', 72 );

		// v2.10.0：服務前 N 小時的第二次提醒，跟前一天固定時間那次是獨立設定
		// （見 UAPPT_Reminders::send_hour_reminders()）。
		add_option( 'uappt_reminder2_enabled', 0 );
		add_option( 'uappt_reminder2_hours', 3 );
	}

	/**
	 * 若資料表版本落後就重跑一次 dbDelta 補上新欄位。
	 *
	 * 需要這段的原因：外掛更新後，使用者通常不會特地停用再啟用，
	 * register_activation_hook 不會再次觸發，新欄位就永遠補不上去。
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'uappt_db_version' ) === UAPPT_DB_VERSION ) {
			return;
		}

		self::install();

		// 這次升級新增了「我的預約」帳戶端點（UAPPT_Account），需要 flush rewrite
		// rules 才不會 404；排一個旗標，讓 init 晚一點的階段再真的執行。
		self::schedule_rewrite_flush();

		// v2.0.0 新增了提醒排程，舊站台不會再觸發 activation hook，這裡補排。
		if ( class_exists( 'UAPPT_Cron' ) ) {
			UAPPT_Cron::schedule_events();
		}
	}

	/**
	 * 排定下一次請求時要 flush rewrite rules。
	 *
	 * 不直接呼叫 flush_rewrite_rules()，是因為這個方法呼叫當下，新的端點
	 * （由 UAPPT_Account 掛在 init 的 add_rewrite_endpoint()）不一定已經註冊過
	 * ——尤其是外掛剛啟用的那次請求，activate_plugin() 是把外掛檔案直接
	 * require 進來，早已錯過本次請求的 init。用旗標延後到下一次請求的 init
	 * （見 ultimate-appointments.php 掛的 uappt_maybe_flush_rewrite_rules）才能
	 * 確保端點已經註冊好，flush 出來的規則才會包含它。
	 */
	public static function schedule_rewrite_flush() {
		update_option( 'uappt_flush_rewrite_rules', 1 );
	}

	/**
	 * 取得資料表完整名稱。
	 *
	 * @param string $table 表名（不含前綴）：staff / staff_overrides / slot_grid / bookings / shift_requests / consumables / consumable_recipes / consumable_moves / customer_contacts。
	 * @return string
	 */
	public static function table( $table ) {
		global $wpdb;
		return $wpdb->prefix . 'uappt_' . $table;
	}
}
