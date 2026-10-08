<?php
/**
 * 人力資源（Staff）CRUD 與營業時間 / 命名時段 / 請假查詢。
 *
 * v2.0.0 由「服務資源（帶數量的池子）」改為「人力資源（具名個體）」：每一列都是
 * 一位真實的人，而不是一個數字。這是必要的改動，不是重新命名——「指定服務
 * 人員」在數量模型下無法正確防止超賣（見 class-uappt-booking.php 的說明）。
 *
 * 業務時間 JSON 格式範例：
 * {"mon":[["10:00","19:00"]],"tue":[["10:00","19:00"]],"wed":[],...}
 * 空陣列代表該天不營業；一天可有多個時段（例如中午休息）。若 is_24h 為真，
 * business_hours 完全不會被讀取，每天固定視為 00:00 到隔天 00:00。
 *
 * 時段分類（time_segments）JSON 格式：
 * {"labels":["上午","下午","晚間"],"boundaries":["12:00","18:00"]}
 * 用於前台時段篩選頁籤。三段固定為 [00:00,分界1)、[分界1,分界2)、[分界2,24:00)，
 * 連續覆蓋一整天，因此任何時段一定歸屬於且只歸屬於其中一段。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Staff {

	const WEEKDAY_KEYS = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );

	/**
	 * `staff_overrides.closed_reason`：這天為什麼不開放（`is_closed=1` 時才有意義）。
	 *
	 * - `CLOSED_OFF`（例休）：**排定**不上班。週日固定休、輪班的休假日。
	 *   「這天我排過了，結論是休」——所以它算進「班表排到哪一天」。
	 * - `CLOSED_LEAVE`（請假）：本來要上班，臨時不上。員工送出的排班申請核准後
	 *   寫的就是這個。
	 *
	 * ⚠️ 第三種狀態「**未排班**」不在這裡——它是**沒有那一列**。這三者一定要分得
	 * 開：月曆上看到灰底卻不知道是「他排休」「他請假」還是「還沒排到這天」，會跑去
	 * 改錯的地方；而「還沒排到」正是之後護欄要量的東西（見
	 * docs/staff-schedule-v3-plan.md 第 5 節）。
	 */
	const CLOSED_OFF   = 'off';
	const CLOSED_LEAVE = 'leave';

	/**
	 * closed_reason 的顯示名稱。
	 *
	 * 空字串（v2.84.0 以前的資料）一律當請假——那時候的 `is_closed=1` 列語意上
	 * 全部都是請假（v2.84.0 曾有一支回填把它們標成 leave，v3.1.0 刪掉了）。
	 *
	 * @param string $reason closed_reason 欄位值。
	 * @return string
	 */
	public static function closed_reason_label( $reason ) {
		return self::CLOSED_OFF === $reason
			? __( '例休', 'ultimate-appointments' )
			: __( '請假', 'ultimate-appointments' );
	}

	/**
	 * 把任意輸入收斂成合法的 closed_reason。
	 *
	 * ⚠️ **認不得一律回 `leave`，不是 `off`。** 這支方法在 v2.84.0 之前的所有寫入
	 * 路徑寫的都是請假（主管手動請假、核准的請假申請、CSV 的 leave），預設給 `off`
	 * 會讓任何還沒更新的呼叫端**靜默改變語意**——存進去不會報錯，只是報表上的請假
	 * 天數開始對不起來。「例休」是新概念，一律要明寫。
	 *
	 * @param mixed $raw 輸入。
	 * @return string
	 */
	public static function sanitize_closed_reason( $raw ) {
		return self::CLOSED_OFF === sanitize_key( (string) $raw ) ? self::CLOSED_OFF : self::CLOSED_LEAVE;
	}

	/**
	 * `staff.schedule_mode`：這位人員怎麼排班（v2.93.0）。
	 *
	 * - `MODE_FIXED`（固定班）：每週差不多一樣。樣板由 cron 自動鋪成逐日的列。
	 * - `MODE_FLEX`（彈性班）：每週每月都不一樣。**樣板完全不參與**，班表只來自
	 *   月曆、排班申請與匯入。
	 *
	 * 24 小時不是第三個值——它是既有的 `is_24h`，畫面上才合併成三選一。理由見
	 * docs/staff-roster-plan.md 的 D1。
	 */
	const MODE_FIXED = 'fixed';
	const MODE_FLEX  = 'flex';

	/**
	 * 彈性班的護欄提前幾天提醒「快要斷班了」。
	 *
	 * 固定班的期待是「從今天到開放預約期限每天都有列」，尾巴一缺就該叫；彈性班是按
	 * 月排的，10/3 時 11 月還沒排是常態。照固定班的口徑會讓提示從月初叫到月底，然後
	 * 被忽略——7 天讓按月排的店大約在 24、25 號開始看到提醒，正好是該動手的時候。
	 */
	const FLEX_NOTICE_DAYS = 7;

	/**
	 * 樣板上有沒有填任何一段。
	 *
	 * 「整張空白」用來推導新增人員時沒指定的排班方式（空白＝彈性班，CSV 匯入
	 * 走這條）。v2.93.0 的舊資料分類也是同一條規則。
	 *
	 * @param array $hours 每週樣板（weekday key => ranges）。
	 * @return bool
	 */
	public static function template_has_ranges( $hours ) {
		foreach ( (array) $hours as $ranges ) {
			if ( is_array( $ranges ) && ! empty( $ranges ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 把任意輸入收斂成合法的排班方式；認不得回空字串（＝呼叫端沒指定）。
	 *
	 * @param mixed $raw 輸入。
	 * @return string
	 */
	public static function sanitize_schedule_mode( $raw ) {
		$raw = sanitize_key( (string) $raw );
		return in_array( $raw, array( self::MODE_FIXED, self::MODE_FLEX ), true ) ? $raw : '';
	}

	/**
	 * 這位人員是不是彈性班。
	 *
	 * @param array $staff 人員資料（已 hydrate）。
	 * @return bool
	 */
	public static function is_flex( $staff ) {
		return isset( $staff['schedule_mode'] ) && self::MODE_FLEX === $staff['schedule_mode'];
	}

	/**
	 * 排班方式的顯示名稱（24 小時優先）。
	 *
	 * @param array $staff 人員資料（已 hydrate）。
	 * @return string
	 */
	public static function schedule_kind_label( $staff ) {
		if ( ! empty( $staff['is_24h'] ) ) {
			return __( '24 小時', 'ultimate-appointments' );
		}
		return self::is_flex( $staff ) ? __( '彈性班', 'ultimate-appointments' ) : __( '固定班', 'ultimate-appointments' );
	}

	/**
	 * 取得單一人員。
	 *
	 * @param int $staff_id 人員 ID。
	 * @return array|null
	 */
	public static function get( $staff_id ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'staff' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $staff_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * 依綁定的 WordPress 使用者 ID 找出對應的人員。前台員工中心
	 * （排班申請、我的班表）解析「目前登入的是誰」的唯一入口——不要在
	 * 別的地方用姓名或 email 比對，那種隱性關聯換一個名字就對不上了。
	 *
	 * @param int $user_id WordPress 使用者 ID。
	 * @return array|null 找不到綁定（或 $user_id 是 0）回傳 null。
	 */
	public static function get_by_user_id( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return null;
		}

		global $wpdb;
		$table = UAPPT_Install::table( 'staff' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * 依 ID 清單取得多位人員（引擎解析候選人員時用），保留傳入順序中實際存在的部分。
	 *
	 * @param array $staff_ids 人員 ID 清單。
	 * @return array 每個元素為完整的人員資料。
	 */
	public static function get_many( $staff_ids ) {
		global $wpdb;

		$staff_ids = array_values( array_unique( array_map( 'intval', (array) $staff_ids ) ) );
		if ( empty( $staff_ids ) ) {
			return array();
		}

		$table        = UAPPT_Install::table( 'staff' );
		$placeholders = implode( ',', array_fill( 0, count( $staff_ids ), '%d' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", $staff_ids ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['id'] ] = self::hydrate( $row );
		}

		$ordered = array();
		foreach ( $staff_ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$ordered[] = $by_id[ $id ];
			}
		}
		return $ordered;
	}

	/**
	 * 取得所有人員。
	 *
	 * @param bool $active_only 是否只回傳啟用中的人員。
	 * @return array
	 */
	public static function get_all( $active_only = false ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'staff' );

		if ( $active_only ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY sort_order ASC, name ASC", 'active' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/**
	 * 解碼資料庫原始列的 JSON 欄位。
	 *
	 * @param array $row 資料庫原始列。
	 * @return array
	 */
	protected static function hydrate( $row ) {
		$row['business_hours'] = self::decode_hours( $row['business_hours'] );
		$row['time_segments']  = self::decode_segments( $row['time_segments'] );
		$row['commission']     = self::decode_commission( isset( $row['commission'] ) ? $row['commission'] : null );
		$row['is_24h']         = ! empty( $row['is_24h'] );
		// 空字串＝回填還沒跑到（或欄位剛建出來）。讀取端一律照回填的同一條規則推導，
		// 不要讓畫面看到第三種值——那樣三選一會變成一個都沒選。
		$row['schedule_mode']  = self::sanitize_schedule_mode( isset( $row['schedule_mode'] ) ? $row['schedule_mode'] : '' );
		if ( '' === $row['schedule_mode'] ) {
			$row['schedule_mode'] = self::template_has_ranges( $row['business_hours'] ) ? self::MODE_FIXED : self::MODE_FLEX;
		}
		// 防禦式下限：0 或負值會讓「這位人員這格還有沒有名額」的判斷式整個失效
		// （capacity 0 代表這格永遠算滿），資料庫欄位雖然是 DEFAULT 1，這裡再保險一次。
		$row['capacity']       = max( 1, (int) $row['capacity'] );
		// user_id 保留 0（不是 null）代表「沒有綁定」，跟 staff_id／customer_id
		// 這類外鍵欄位在這個外掛裡的慣例一致（PHP 陣列比對用 0 比 null 好處理，
		// 不需要每個呼叫端都多寫一次 null 判斷）。
		$row['user_id']        = ! empty( $row['user_id'] ) ? (int) $row['user_id'] : 0;
		// 附件 ID，0 代表沒有照片。跟 user_id 不一樣，這個欄位沒有唯一索引，
		// 所以資料庫直接存 0 就好，不需要走 NULL。
		$row['photo_id']       = ! empty( $row['photo_id'] ) ? (int) $row['photo_id'] : 0;
		return $row;
	}

	/**
	 * 取得人員照片網址。
	 *
	 * 前台的人員卡片用得到（見 docs/booking-wizard-plan.md）。附件被刪掉時
	 * `wp_get_attachment_image_url()` 會回傳 false，這裡統一收斂成空字串，
	 * 讓呼叫端只要判斷真假值就好，不用同時處理 false 跟 ''。
	 *
	 * @param array  $staff 人員資料（已 hydrate）。
	 * @param string $size  圖片尺寸，預設 thumbnail。
	 * @return string 沒有照片或附件已刪除時回傳空字串。
	 */
	public static function get_photo_url( $staff, $size = 'thumbnail' ) {
		if ( empty( $staff['photo_id'] ) ) {
			return '';
		}
		$url = wp_get_attachment_image_url( (int) $staff['photo_id'], $size );
		return $url ? $url : '';
	}

	/**
	 * 新增或更新人員。
	 *
	 * @param array $data 欄位：id(可選), name, status, is_24h, business_hours(陣列),
	 *                    slot_interval(可選), time_segments(['labels'=>string[3],'boundaries'=>string[2]]),
	 *                    price_adjustment, sort_order, capacity(可選，同時可服務人數，預設 1)，
	 *                    user_id(可選，綁定的 WordPress 使用者 ID；0 或留空代表不綁定)，
	 *                    photo_id(可選，照片的附件 ID；0 代表沒有照片)，
	 *                    schedule_mode(可選，fixed／flex；**沒給就不動**，見下方 ⚠️)。
	 * @return int|WP_Error 人員 ID；時間格式錯誤或使用者已被別人綁定時回傳包含具體描述的 WP_Error。
	 */
	public static function save( $data ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'staff' );

		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'uappt_invalid_staff', __( '人員姓名不可為空。', 'ultimate-appointments' ) );
		}

		// 一個 WordPress 使用者只能綁定一位人員：資料庫的 UNIQUE KEY 是最後一道
		// 防線，但直接讓 $wpdb->insert()/update() 因為違反唯一索引而悄悄失敗，
		// 管理者只會看到一片空白、猜不出原因。這裡先用 get_by_user_id() 查一次，
		// 講清楚是撞到誰。
		$user_id = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0;
		if ( $user_id ) {
			$linked      = self::get_by_user_id( $user_id );
			$editing_id  = ! empty( $data['id'] ) ? (int) $data['id'] : 0;
			if ( $linked && (int) $linked['id'] !== $editing_id ) {
				return new WP_Error(
					'uappt_user_already_linked',
					sprintf(
						/* translators: %s: 已經綁定該使用者的人員姓名 */
						__( '這個 WordPress 使用者已經綁定給另一位人員（%s），一個使用者只能綁定一位人員，請先解除原本的綁定。', 'ultimate-appointments' ),
						$linked['name']
					)
				);
			}
		}

		$is_24h = ! empty( $data['is_24h'] );

		// 時間格式錯誤時要讓管理員清楚看到是哪一列出錯，而不是靜默把資料丟掉。
		// 24 小時營業時完全不看每週時段輸入，格式驗證也就沒有意義，直接跳過。
		$errors     = array();
		$hours_json = $is_24h
			? wp_json_encode( array() )
			: self::encode_hours( isset( $data['business_hours'] ) ? $data['business_hours'] : array(), $errors );

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'uappt_invalid_time_format', implode( ' ', $errors ) );
		}

		$status           = ( isset( $data['status'] ) && 'inactive' === $data['status'] ) ? 'inactive' : 'active';
		$slot_interval    = ( isset( $data['slot_interval'] ) && '' !== $data['slot_interval'] )
			? max( 5, (int) $data['slot_interval'] )
			: null;
		$price_adjustment = isset( $data['price_adjustment'] ) ? (float) $data['price_adjustment'] : 0.0;
		$sort_order       = isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0;
		// 同時可服務人數：沒有「留空沿用全域」的概念，永遠是明確數字，下限 1
		// （0 會讓 cells_are_free() 的容量檢查恆為「已滿」，這位人員會變成永遠約不到）。
		$capacity         = isset( $data['capacity'] ) ? max( 1, (int) $data['capacity'] ) : 1;
		$photo_id         = isset( $data['photo_id'] ) ? absint( $data['photo_id'] ) : 0;
		$now              = current_time( 'mysql' );

		// ⚠️ **排班方式沒給就不動，不要給預設值。** 快速停用、CSV 匯入都會呼叫這一支，
		// 而它們不知道有這個欄位——預設成任何一個值，停用一個人就會悄悄改掉他的排班
		// 方式（而且固定 → 彈性還會把他未來的樣板列改標成手動，見下面）。
		// 新增時沒給，照回填的同一條規則推導（樣板有填＝固定班）。
		$schedule_mode = self::sanitize_schedule_mode( isset( $data['schedule_mode'] ) ? $data['schedule_mode'] : '' );

		// ⚠️ **格式用「欄位名 → 格式」的對照表，不要寫成一排位置相依的陣列。**
		//
		// 原本是 `$formats = array( '%s', '%s', … )`，靠順序跟 $fields 對上。
		// v2.76.0 實測抓到它已經錯位了：13 個格式對 12 個欄位，從
		// price_adjustment 之後整排偏移一格，`commission` 拿到 `%f`——
		// $wpdb 把整串 JSON 轉成 float，存進去變成 `0.000000`，這位人員的
		// 抽成設定就沒了。`updated_at` 拿到 `%d`，存成 `0000-00-00 00:00:00`。
		//
		// 站上真的有人中招（王小美的 commission 欄位就是 `0.000000`）。
		// 這種 bug 完全沒有症狀：存檔成功、畫面沒有錯誤，只是設定悄悄消失。
		//
		// 對照表讓「加一個欄位忘了加格式」變成不可能——漏掉的話下面的
		// array_map 會直接抓不到 key，而不是靜默把後面全部推移一格。
		$field_formats = array(
			'name'             => '%s',
			'status'           => '%s',
			'photo_id'         => '%d',
			'is_24h'           => '%d',
			'schedule_mode'    => '%s',
			'business_hours'   => '%s',
			'slot_interval'    => '%d',
			'price_adjustment' => '%f',
			'commission'       => '%s',
			'sort_order'       => '%d',
			'capacity'         => '%d',
			'user_id'          => '%d',
			'updated_at'       => '%s',
			'created_at'       => '%s',
		);

		$fields = array(
			'name'             => $name,
			'status'           => $status,
			'photo_id'         => $photo_id,
			'is_24h'           => $is_24h ? 1 : 0,
			'business_hours'   => $hours_json,
			'slot_interval'    => $slot_interval,
			'price_adjustment' => $price_adjustment,
			'commission'       => self::encode_commission( isset( $data['commission'] ) ? $data['commission'] : array() ),
			'sort_order'       => $sort_order,
			'capacity'         => $capacity,
			// 存 NULL 不是 0：欄位是 UNIQUE KEY，如果拿 0 代表「沒有綁定」，
			// 第二位沒有綁定的人員一存檔就會撞到唯一索引。NULL 在 MySQL 的
			// unique index 裡可以重複出現，這才是「沒有綁定」該存的值。
			'user_id'          => $user_id ? $user_id : null,
			'updated_at'       => $now,
		);

		if ( '' !== $schedule_mode ) {
			$fields['schedule_mode'] = $schedule_mode;
		}

		if ( ! empty( $data['id'] ) ) {
			$staff_id = (int) $data['id'];

			// 改了樣板（或 24 小時旗標）就要重鋪由樣板產生的那些列。
			//
			// ⚠️ **放在 save() 裡而不是後台的 handler 裡**：CSV 匯入也走這一支，
			// 兩邊各寫一次遲早會漏掉其中一邊，而漏掉的樣子是「樣板改了、班表沒變」
			// ——存檔成功、畫面沒有錯誤，只是不生效。
			//
			// 先比對再重鋪：重鋪是幾百列的刪除與寫入，每次存檔（改個電話、調個
			// 排序）都跑一次太浪費。
			$before      = $wpdb->get_row( $wpdb->prepare( "SELECT business_hours, is_24h, schedule_mode FROM {$table} WHERE id = %d", $staff_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$before_mode = $before ? self::sanitize_schedule_mode( $before['schedule_mode'] ) : '';
			$to_flex     = '' !== $schedule_mode && self::MODE_FLEX === $schedule_mode && self::MODE_FLEX !== $before_mode;
			$needs_rebuild = ! $before
				|| (string) $before['business_hours'] !== (string) $hours_json
				|| (int) $before['is_24h'] !== ( $is_24h ? 1 : 0 )
				|| ( '' !== $schedule_mode && $schedule_mode !== $before_mode );

			$wpdb->update( $table, $fields, array( 'id' => $staff_id ), self::formats_for( $fields, $field_formats ), array( '%d' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( $to_flex ) {
				// 固定 → 彈性：**先**把樣板鋪好的未來改標成手動，再讓下面的重鋪跑。
				// 順序反過來的話，重鋪會先把它們刪掉（彈性班不鋪），客人已經約得到的
				// 時段就在切換的那一刻消失了（使用者決定：保留，見計畫 Q5）。
				self::release_template_rows( $staff_id );
			}

			if ( $needs_rebuild ) {
				self::rebuild_template_rows( self::get( $staff_id ) );
			}

			return $staff_id;
		}

		$fields['created_at'] = $now;
		if ( ! isset( $fields['schedule_mode'] ) ) {
			$fields['schedule_mode'] = self::template_has_ranges( isset( $data['business_hours'] ) ? $data['business_hours'] : array() ) ? self::MODE_FIXED : self::MODE_FLEX;
		}

		$wpdb->insert( $table, $fields, self::formats_for( $fields, $field_formats ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$staff_id = (int) $wpdb->insert_id;

		// 新建立的人員：樣板填了什麼就立刻鋪成班表。不鋪的話要等 cron 跑過才會
		// 有班——而「建好了，客人卻約不到」正是這個改版最想消滅的那種沉默失效。
		if ( $staff_id ) {
			self::rebuild_template_rows( self::get( $staff_id ) );
		}

		return $staff_id;
	}

	/**
	 * 依欄位名取出 $wpdb 要的格式陣列，順序跟著 $fields 走。
	 *
	 * $wpdb 的 $format 參數是**位置相依**的，手寫一排 '%s','%d' 很容易跟
	 * $fields 對不上，而且對不上完全不會報錯——只會把值靜默轉成錯的型別。
	 * 這支把對應關係交給 key，順序由 $fields 自己決定。
	 *
	 * 少定義一個欄位的格式時**寧可炸掉也不要猜**：猜 '%s' 會讓 float 欄位
	 * 被當字串寫入這類問題繼續藏著。
	 *
	 * @param array $fields 要寫入的欄位（key => value）。
	 * @param array $map    欄位名 => 格式。
	 * @return array
	 */
	protected static function formats_for( array $fields, array $map ) {
		$formats = array();
		foreach ( array_keys( $fields ) as $key ) {
			if ( ! isset( $map[ $key ] ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html( sprintf( '人員資料表欄位「%s」沒有定義寫入格式。', $key ) ),
					'2.76.0'
				);
				$formats[] = '%s';
				continue;
			}
			$formats[] = $map[ $key ];
		}

		return $formats;
	}

	/**
	 * 刪除人員。呼叫端（UAPPT_Admin::handle_delete_staff()）必須先用
	 * UAPPT_Booking::count_upcoming_for_staff() 確認沒有進行中或未來的預約／
	 * 時段佔用才能呼叫這裡——這支方法本身不做那個檢查，只負責把資料清乾淨。
	 *
	 * 一併清掉 slot_grid 裡這位人員的所有格子：呼叫端已經保證沒有未來的有效
	 * 紀錄，殘留的格子只可能是過去的（不影響任何運作）或已釋放為 0 的，留著
	 * 純粹是浪費資料列。
	 *
	 * @param int $staff_id 人員 ID。
	 */
	public static function delete( $staff_id ) {
		global $wpdb;
		$staff_id = (int) $staff_id;
		$wpdb->delete( UAPPT_Install::table( 'staff' ), array( 'id' => $staff_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->delete( UAPPT_Install::table( 'staff_overrides' ), array( 'staff_id' => $staff_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->delete( UAPPT_Install::table( 'slot_grid' ), array( 'staff_id' => $staff_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::flush_override_cache( $staff_id );
	}

	/**
	 * 解除某位人員綁定的 WordPress 使用者。掛在 `deleted_user`（見
	 * ultimate-appointments.php 的 `uappt_on_user_deleted()`）：WP 使用者被刪除
	 * 時，人員本身**絕對不要跟著被刪**——底下可能還掛著預約紀錄，
	 * `count_upcoming_for_staff()` 的保護邏輯是防「管理者手動刪人員」，
	 * 這裡是完全不同的觸發來源，不能繞過去。只解除綁定，人員繼續存在，
	 * 之後可以重新綁一個新帳號，或直接繼續當「沒有登入帳號的人員」使用
	 * （這本來就是這個外掛從第一版就支援的正常狀態）。
	 *
	 * 刻意不透過 save()：那支是整列覆寫，需要重新驗證/編碼 business_hours
	 * 等欄位，對「只解除一個綁定」這麼單純的動作太重，直接下一個針對性的
	 * UPDATE 更安全、更不會有副作用。
	 *
	 * @param int $staff_id 人員 ID。
	 */
	public static function unlink_user( $staff_id ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			UAPPT_Install::table( 'staff' ),
			array(
				'user_id'    => null,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $staff_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * 取得某人員實際要使用的時間格顆粒（分鐘）。
	 *
	 * 人員自己有設定就用它，否則回退到全域設定。這是唯一的顆粒判斷入口——
	 * UAPPT_Booking 內所有需要顆粒的地方都必須呼叫這裡，不要各自重複讀取
	 * get_option()，否則容易在「建立暫留」與「釋放」之間因設定變更而對不齊
	 * （曾經是實際發生過的 bug）。真正的保險是 UAPPT_Booking 會把建立當下算出的
	 * 顆粒存進該筆預約紀錄的 grid_interval，釋放時一律以紀錄本身的值為準，
	 * 這裡只是提供「現在該用多少」的單一來源。
	 *
	 * @param array|null $staff 人員資料，可為 null（此時只看全域設定）。
	 * @return int
	 */
	public static function get_slot_interval( $staff ) {
		if ( is_array( $staff ) && ! empty( $staff['slot_interval'] ) ) {
			return max( 5, (int) $staff['slot_interval'] );
		}
		return max( 5, (int) get_option( 'uappt_slot_interval_minutes', 15 ) );
	}

	/**
	 * 取得某人員在某天的營業時段（陣列，每個元素 [開始, 結束]，24hr HH:mm）。
	 *
	 * 判斷順序：該日請假（全休）→ 該日有逐日自訂時段（v2.14.0 新增，覆蓋
	 * 每週範本）→ 都沒有才回退到每週範本。**這個順序對 24 小時營業的人員
	 * 也一樣適用**——is_24h 的「固定全天」捷徑是 get_business_windows() 自己
	 * 的事，這裡不再搶在請假／自訂時段判斷之前就先擋掉，否則 24 小時人員
	 * 的逐日自訂時段會被無聲忽略（v2.14.0 之前的行為，是這次要修的問題）。
	 * 24 小時人員在「沒有請假、沒有自訂時段」時，每週範本本來就是空的
	 * （is_24h 開著就不會有人去填每週範本），回傳空陣列是正確答案，交給
	 * get_business_windows() 的捷徑去處理真正的全天邏輯。
	 *
	 * `$override_dry_run` 是排班申請審核（v2.15.0）要用的 dry-run 注入口——
	 * 「如果這天的 override 變成這樣，營業時段會長怎樣」，不用另外複製一份
	 * 解析邏輯（設計紀律 #7：這仍然是唯一的解析點，只是多一個「override 從
	 * 哪裡來」的選項）。預設值 `false` 代表「沒有提供，照舊查資料庫」；
	 * 顯式傳 `null` 代表「假設這天完全沒有 override」；傳一個
	 * `['is_closed'=>.., 'hours'=>..]` 形狀的陣列（跟 `get_override()` 回傳的
	 * 資料列同形狀）代表「假設這天的 override 是這樣」。
	 *
	 * @param array      $staff 人員資料（已解碼 business_hours）。
	 * @param string     $date_ymd 日期 (Y-m-d)。
	 * @param array|null|false $override_dry_run 見上方說明，預設 false（照查資料庫）。
	 * @return array
	 */
	public static function get_business_ranges_for_date( $staff, $date_ymd, $override_dry_run = false ) {
		$override = ( false !== $override_dry_run ) ? $override_dry_run : self::get_override( $staff['id'], $date_ymd );
		if ( $override && ! empty( $override['is_closed'] ) ) {
			return array();
		}
		if ( $override && ! empty( $override['hours'] ) ) {
			$decoded = json_decode( $override['hours'], true );
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				return $decoded;
			}
		}

		// 店休（v2.79.0）。位置就是優先序：**單日調整已經在上面處理完了**，
		// 所以單日自訂時段可以在店休日破例開工（過年留一位師傅值班）；到這裡
		// 還沒 return 的，才輪到店休蓋掉每週範本。
		//
		// ⚠️ 判斷用的是呼叫端傳進來的 $date_ymd，而 get_business_windows() 是
		// 拿 **owner_date**（開店那一天）來呼叫這支的——所以跨午夜的深夜班跟
		// 請假走同一條規則：週五的班延伸到週六凌晨，標週六店休不會把它切掉。
		// 這是既有的 owner_date 慣例，不是這裡另外決定的。
		if ( UAPPT_Shop_Closure::is_closed( $date_ymd ) ) {
			return array();
		}

		// ⚠️ **v2.88.0 起這裡沒有「回退到每週範本」了。**
		//
		// 以前最後一段是「這天沒有逐日調整 → 看 business_hours[星期幾]」，也就是
		// 每週範本是**讀取時推導**出來的。從這一版起 `business_hours` 降級成
		// 「固定班樣板」：它不再於讀取時被套用，而是由
		// `extend_from_template()`（升級時跑一次、之後每天 cron 維持）**寫成真實的
		// 逐日資料列**。
		//
		// 換掉的理由（見 docs/staff-schedule-v3-plan.md 第 2 節）：月曆上看到的就是
		// 資料庫裡的那一列，不再有「畫面是範本推導、實際是另一回事」的落差；而且
		// 輪班制的店本來就表達不了「做二休一」這種規律，範本對他們是零價值的一層。
		//
		// ⚠️ 24 小時人員仍然在這裡回傳空陣列——他們走的是
		// `get_business_windows()` 的捷徑，**而且 cron 不會幫他們產生任何列**
		// （產生了反而會讓捷徑失效，見 extend_from_template()）。
		return array();
	}

	/**
	 * 這天是否偏離「單純的每週範本／24 小時」預設。
	 *
	 * `get_business_windows()` 用這支決定 24 小時人員要不要走「固定
	 * 00:00–24:00」的捷徑——只有這裡回傳 false 才走捷徑，否則一律改用跟非 24
	 * 小時人員相同的區間解析（見 get_business_ranges_for_date()）。
	 *
	 * 偏離的來源有兩種：
	 * 1. 人員自己的逐日 override（整天休假，或逐日自訂時段）
	 * 2. **全店公休**（v2.79.0 起）
	 *
	 * ⚠️ **第 2 項是必要的，不是順手加的。** 24 小時人員在沒有 override 的日子
	 * 會走捷徑、**根本不會呼叫 get_business_ranges_for_date()**——店休的判斷
	 * 寫在那支裡面，少了這裡這一條，24 小時人員在店休日照樣全天開放。
	 *
	 * 函式名 v2.79.0 從 `has_day_override()` 改成現在這個：它早就不只在問
	 * 「有沒有 override」了，名字繼續叫 override 會騙到下一個人。
	 *
	 * 同樣支援 `$override_dry_run` 注入（見 get_business_ranges_for_date() 的說明），
	 * 讓 get_business_windows() 對同一天的短路判斷與區間解析用的是同一份假設。
	 *
	 * @param int              $staff_id 人員 ID。
	 * @param string           $date_ymd 日期 (Y-m-d)。
	 * @param array|null|false $override_dry_run 預設 false（照查資料庫）。
	 * @return bool
	 */
	protected static function day_deviates_from_default( $staff_id, $date_ymd, $override_dry_run = false ) {
		if ( UAPPT_Shop_Closure::is_closed( $date_ymd ) ) {
			return true;
		}

		return self::has_day_override( $staff_id, $date_ymd, $override_dry_run );
	}

	/**
	 * 這天有沒有人員自己的逐日 override（全休，或逐日自訂時段）。
	 *
	 * 只問 override，不管店休——要連店休一起問請用
	 * `day_deviates_from_default()`。
	 *
	 * @param int              $staff_id 人員 ID。
	 * @param string           $date_ymd 日期 (Y-m-d)。
	 * @param array|null|false $override_dry_run 預設 false（照查資料庫）。
	 * @return bool
	 */
	protected static function has_day_override( $staff_id, $date_ymd, $override_dry_run = false ) {
		$override = ( false !== $override_dry_run ) ? $override_dry_run : self::get_override( $staff_id, $date_ymd );
		if ( ! $override ) {
			return false;
		}
		if ( ! empty( $override['is_closed'] ) ) {
			return true;
		}
		if ( ! empty( $override['hours'] ) ) {
			$decoded = json_decode( $override['hours'], true );
			return is_array( $decoded ) && ! empty( $decoded );
		}
		return false;
	}

	/**
	 * 取得「可能產生開始於 $date_ymd 的時段」的所有營業區間（絕對時間）。
	 *
	 * 這是營業時間的單一入口，把跨日與 24 小時營業的複雜度集中在這裡處理，
	 * 呼叫端只要拿到 open/close 兩個絕對時間戳就好。
	 *
	 * 一般（非 24 小時）情況下區間有兩個來源：
	 * 1. 當天自己的時段。結束早於開始代表跨日，close 落在隔天。
	 * 2. 前一天的跨日時段——它的尾巴會延伸進今天（例如週五 20:00–02:00 會讓
	 *    週六凌晨 00:00–02:00 也可以預約）。
	 *
	 * 每個區間都帶 owner_date（開店那一天）。請假與否一律以 owner_date 為準：
	 * 週五的深夜班延伸到週六凌晨那段，仍然屬於「週五這一班」，週六請假不該
	 * 把它關掉。
	 *
	 * `$dry_run`（排班申請審核，v2.15.0 新增）是「假設某一天的 override 變成
	 * 另一個樣子，這天的營業區間會長怎樣」的注入口，格式是
	 * `['date' => 'Y-m-d', 'override' => [...]]`（override 形狀同
	 * `get_business_ranges_for_date()` 的 `$override_dry_run`）。只有
	 * `$owner_date === $dry_run['date']` 那一天會套用假設值，另一天（通常是
	 * 前一天延伸過來的尾巴）照舊查真實資料庫——申請只改變它自己那一天的
	 * override，不該連帶影響前一天已經定案的班別。
	 *
	 * @param array      $staff    人員資料（已解碼 business_hours）。
	 * @param string     $date_ymd 日期 (Y-m-d)。
	 * @param array|null $dry_run  見上方說明，預設 null（不做 dry-run，照查資料庫）。
	 * @return array 每個元素：['open_ts'=>int,'close_ts'=>int,'owner_date'=>string]。
	 */
	public static function get_business_windows( $staff, $date_ymd, $dry_run = null ) {
		$windows   = array();
		$prev_date = uappt_local_date( $date_ymd, '-1 day' );

		foreach ( array( $prev_date, $date_ymd ) as $owner_date ) {
			if ( '' === $owner_date ) {
				continue;
			}

			$owner_override_dry_run = ( $dry_run && $dry_run['date'] === $owner_date ) ? $dry_run['override'] : false;

			if ( ! empty( $staff['is_24h'] ) && ! self::day_deviates_from_default( $staff['id'], $owner_date, $owner_override_dry_run ) ) {
				// 24 小時營業、且這天沒有任何 override（全休／逐日自訂時段）：
				// 固定「當天 00:00 到隔天 00:00」，本身就是最大範圍，不會有
				// 前一天延伸進來的尾巴，只需要處理 owner_date 自己。這是絕大
				// 多數日子會走的捷徑，避免每天都要多算一次區間解析。
				if ( $owner_date !== $date_ymd ) {
					continue;
				}
				$next_date = uappt_local_date( $owner_date, '+1 day' );
				$windows[] = array(
					'open_ts'    => self::local_ts( $owner_date . ' 00:00:00' ),
					'close_ts'   => self::local_ts( $next_date . ' 00:00:00' ),
					'owner_date' => $owner_date,
				);
				continue;
			}

			// 一般（非 24 小時）人員，或 24 小時人員但這天有 override：兩種情況
			// 共用同一套區間解析。get_business_ranges_for_date() 內部已經處理
			// 「該日請假／全休」跟「逐日自訂時段優先於每週範本」，全休會回傳
			// 空陣列，該日的區間自然不會產生（含它延伸到隔天的尾巴）。
			foreach ( self::get_business_ranges_for_date( $staff, $owner_date, $owner_override_dry_run ) as $range ) {
				$open_ts = self::local_ts( $owner_date . ' ' . $range[0] . ':00' );

				if ( $range[1] > $range[0] ) {
					// 一般區間：當天結束。
					$close_ts = self::local_ts( $owner_date . ' ' . $range[1] . ':00' );
				} else {
					// 跨日區間：結束時間落在隔天。
					$next_date = uappt_local_date( $owner_date, '+1 day' );
					$close_ts  = self::local_ts( $next_date . ' ' . $range[1] . ':00' );
				}

				if ( $close_ts <= $open_ts ) {
					continue;
				}

				// 前一天的區間只有「跨日並延伸進今天」的才與今天有關，一般區間跳過。
				if ( $owner_date !== $date_ymd && $range[1] > $range[0] ) {
					continue;
				}

				$windows[] = array(
					'open_ts'    => $open_ts,
					'close_ts'   => $close_ts,
					'owner_date' => $owner_date,
				);
			}
		}

		return $windows;
	}

	/**
	 * 報表的「時段利用率」要用：某位人員在一段日期區間內，總共有多少分鐘
	 * 是「有營業」的（還沒乘上同時可服務人數）。
	 *
	 * 逐日呼叫 get_business_windows()（營業時間唯一入口，設計紀律 #7），
	 * 用 `owner_date` 當去重鍵，不是用「這次是在哪一天呼叫時看到的」——
	 * get_business_windows() 對每一天都會連同「前一天延伸過來的跨午夜尾巴」
	 * 一起回傳，同一個班在相鄰兩次呼叫裡會各出現一次，不用 owner_date 去重
	 * 會把跨午夜的班算成兩份時數。多掃 $date_to 的隔天一次，是因為
	 * $date_to 當天的跨午夜班要到查「隔天」時才會被回傳出來。
	 *
	 * @param array  $staff     人員資料（已解碼 business_hours）。
	 * @param string $date_from 起始日期 (Y-m-d)，含當天。
	 * @param string $date_to   結束日期 (Y-m-d)，含當天。
	 * @return float 總分鐘數。
	 */
	public static function get_business_minutes_in_range( $staff, $date_from, $date_to ) {
		$total_minutes = 0.0;
		$seen          = array();

		$scan_until = uappt_local_date( $date_to, '+1 day' );
		$cursor     = $date_from;

		while ( '' !== $cursor && $cursor <= $scan_until ) {
			foreach ( self::get_business_windows( $staff, $cursor ) as $window ) {
				if ( $window['owner_date'] < $date_from || $window['owner_date'] > $date_to ) {
					continue;
				}
				$key = $window['owner_date'] . '|' . $window['open_ts'];
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ]   = true;
				$total_minutes += ( $window['close_ts'] - $window['open_ts'] ) / 60;
			}
			$cursor = uappt_local_date( $cursor, '+1 day' );
		}

		return $total_minutes;
	}

	/**
	 * 把站台本地時間字串轉為 timestamp。
	 *
	 * 與 UAPPT_Booking::local_ts() 同樣的作法：明確指定 wp_timezone()，不用裸
	 * strtotime()（會受 PHP 預設時區影響，該時區未必等於網站設定的時區）。
	 *
	 * @param string $local_datetime 本地時間字串。
	 * @return int
	 */
	protected static function local_ts( $local_datetime ) {
		$dt = date_create( $local_datetime, wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}

	/**
	 * 請求內的逐日調整快取：staff_id => [ 'Y-m-d' => 資料列|null ]。
	 *
	 * 只在 prime_overrides() 明確預熱過的日期才有值；`null` 是有意義的值
	 * （代表「這天確定沒有 override」），所以查快取一律用 array_key_exists()
	 * 而不是 isset()。
	 *
	 * @var array
	 */
	protected static $override_cache = array();

/**
	 * 組出「月曆」的班表資料。排班在真實情境裡是一週／一個月排一次，月曆比
	 * 過去的「本週＋下週共 14 天」直式清單更貼近員工實際排班的方式，也是
	 * 「一次選多天再送出申請」唯一說得通的操作介面。
	 *
	 * 每一天直接用 `UAPPT_Staff::get_business_ranges_for_date()`，不是
	 * `get_business_windows()`——這裡要的是「這一天自己的營業區間」給人看，
	 * 不是控房引擎要的、含前一天跨午夜尾巴的絕對時間戳版本，用錯的那支只是
	 * 徒增複雜度，還可能讓同一段班在畫面上重複出現兩次。
	 *
	 * @param array  $staff 人員資料。
	 * @param string $month 月份 (Y-m)。
	 * @return array {
	 *     @type string $month      當前月份 (Y-m)。
	 *     @type string $prev_month 上一個月 (Y-m)。
	 *     @type string $next_month 下一個月 (Y-m)。
	 *     @type array  $weeks      每週一個陣列，每個元素是一天的資料（見下）。
	 *     @type array  $days       同一份資料，key 是 Y-m-d，方便查表。
	 * }
	 */
	public static function get_schedule_month( $staff, $month ) {
		$month_start = $month . '-01';
		$month_dt    = date_create( $month_start, wp_timezone() );
		$month_end   = $month_dt ? uappt_local_date( $month_start, 'last day of this month' ) : $month_start;

		// 月曆格線：從月初那週的週一開始，補到月底那週的週日結束，讓每一列
		// 都是完整的一週。
		$grid_from = uappt_local_date( $month_start, '-' . ( uappt_local_weekday_iso( $month_start ) - 1 ) . ' days' );
		$grid_to   = uappt_local_date( $month_end, '+' . ( 7 - uappt_local_weekday_iso( $month_end ) ) . ' days' );

		$overrides = UAPPT_Staff::get_overrides_in_range( $staff['id'], $grid_from, $grid_to );

		$today = current_time( 'Y-m-d' );
		$days  = array();

		$cursor = $grid_from;
		while ( '' !== $cursor && $cursor <= $grid_to ) {
			$override = isset( $overrides[ $cursor ] ) ? $overrides[ $cursor ] : null;
			$days[ $cursor ] = array(
				'date'         => $cursor,
				'is_in_month'  => ( substr( $cursor, 0, 7 ) === $month ),
				'is_today'     => ( $cursor === $today ),
				'is_past'      => ( $cursor < $today ),
				'weekday'      => UAPPT_Staff::WEEKDAY_KEYS[ uappt_local_weekday_w( $cursor ) ],
				'ranges'       => UAPPT_Staff::get_business_ranges_for_date( $staff, $cursor, $override ),
				'override'     => $override,
				'pending'      => array(),
				'bookings'     => array(),
			);
			$cursor = uappt_local_date( $cursor, '+1 day' );
		}

		$booking_query = UAPPT_Booking::query(
			array(
				'staff_id'  => $staff['id'],
				'date_from' => $grid_from,
				'date_to'   => $grid_to,
				'kind'      => 'any',
				'order'     => 'ASC',
				'per_page'  => 500,
			)
		);

		foreach ( $booking_query['items'] as $booking ) {
			// 已取消／已逾時釋放的不用再出現在班表上——那個時段已經不是真的
			// 佔用了，留著只會讓班表看起來比實際忙碌。時段佔用一律顯示
			// （create_block() 一建立就是 confirmed，沒有其他狀態）。
			if ( UAPPT_Booking::KIND_BOOKING === $booking['kind']
				&& in_array( $booking['status'], array( UAPPT_Booking::STATUS_CANCELLED, UAPPT_Booking::STATUS_EXPIRED ), true )
			) {
				continue;
			}
			$date_key = substr( $booking['service_start'], 0, 10 );
			if ( isset( $days[ $date_key ] ) ) {
				$days[ $date_key ]['bookings'][] = $booking;
			}
		}

		$pending_rows = UAPPT_Shift_Request::query(
			array(
				'staff_id' => $staff['id'],
				'status'   => UAPPT_Shift_Request::STATUS_PENDING,
				'date_from' => $grid_from,
				'date_to'   => $grid_to,
			)
		);
		foreach ( $pending_rows as $row ) {
			if ( isset( $days[ $row['request_date'] ] ) ) {
				$days[ $row['request_date'] ]['pending'][] = $row;
			}
		}

		$weeks = array_chunk( array_values( $days ), 7 );

		return array(
			'month'      => $month,
			'prev_month' => uappt_local_date( $month_start, '-1 month' ) ? substr( uappt_local_date( $month_start, '-1 month' ), 0, 7 ) : $month,
			'next_month' => uappt_local_date( $month_start, '+1 month' ) ? substr( uappt_local_date( $month_start, '+1 month' ), 0, 7 ) : $month,
			'weeks'      => $weeks,
			'days'       => $days,
		);
	}

	/**
	 * 一段日期的「班表計畫」：每一天要存成什麼。
	 *
	 * 回傳的形狀**跟月曆塗抹送出的形狀一樣**（`t` 類型、`r` 時段），所以三個產生器
	 * （複製上個月／複製同事／套用固定班樣板）拿到的東西，跟使用者自己用畫筆塗出來
	 * 的完全一致——產生器因此不需要第二條寫入路徑，只是「幫你先塗好」而已。
	 *
	 * 鍵刻意取短名（`t` 類型 / `r` 時段 / `l` 標籤 / `s` 手機版短標籤）：這份資料要
	 * 整包印進 HTML 給 JS 用，十幾位人員各一個月的話，欄位名稱佔的比內容還多。
	 *
	 * `$ignore_overrides` 為真＝「照固定班樣板會排成什麼」，走 `template_day()`——
	 * 跟 cron 把樣板鋪成實際的列是**同一支**，所以「套用固定班樣板」產生器塗出來的，
	 * 一定就是樣板會寫進去的東西。
	 *
	 * ⚠️ **v2.95.0 以前這裡走的是 `get_business_ranges_for_date()` 的 dry-run 注入口**
	 * （傳 `null` ＝假設這天沒有 override，退回每週範本）。v2.88.0 把那個「退回每週
	 * 範本」拿掉之後，這條路就只會回空陣列——「套用固定班樣板」從那時起把整個月塗成
	 * 未排班，按下儲存等於把這個人的班整個月刪光。存檔不會報錯，月曆也照實顯示
	 * 「未排班」，只是沒有人會想到是那顆鈕做的。做全店月排班表時才發現。
	 *
	 * ⚠️ 店休日一律算成 `clear`（不排班）。「套用固定班樣板」的意思是「照這個人的
	 * 每週規律排」，而店休日全店都不開——要讓某個人在店休日破例上班是**例外**，
	 * 走「改這一天」那條路（單日調整的優先序本來就高於店休）。
	 *
	 * @param array  $staff            人員資料（已 hydrate）。
	 * @param string $from             起日 (Y-m-d)。
	 * @param string $to               迄日 (Y-m-d)。
	 * @param bool   $ignore_overrides 真＝假裝這段期間完全沒有逐日調整（只看範本）。
	 * @return array date => array{t:string, r:array, l:string, s:string, c:string}
	 */
	public static function get_range_plan( $staff, $from, $to, $ignore_overrides = false ) {
		$plan = array();

		if ( '' === $from || '' === $to || $from > $to ) {
			return $plan;
		}

		// 一次查完整段的 override，下面逐日就不會每天各查一次。
		if ( ! $ignore_overrides ) {
			self::prime_overrides( $staff['id'], $from, $to );
		}

		$cursor = $from;
		while ( '' !== $cursor && $cursor <= $to ) {
			if ( $ignore_overrides ) {
				$day    = self::template_day( $staff, $cursor );
				$type   = $day['t'];
				$ranges = $day['r'];

				$plan[ $cursor ] = array(
					't' => $type,
					'r' => $ranges,
					'l' => self::plan_label( $type, $ranges ),
					's' => self::plan_label( $type, $ranges, true ),
					'c' => 'hours' === $type ? UAPPT_Shift_Preset::color_for_ranges( $ranges ) : '',
				);

				$cursor = uappt_local_date( $cursor, '+1 day' );
				continue;
			}

			$override = self::get_override( $staff['id'], $cursor );
			$ranges   = self::get_business_ranges_for_date( $staff, $cursor, false );

			if ( $override && ! empty( $override['is_closed'] ) ) {
				$type   = ( self::CLOSED_OFF === ( isset( $override['closed_reason'] ) ? $override['closed_reason'] : '' ) )
					? self::CLOSED_OFF
					: self::CLOSED_LEAVE;
				$ranges = array();
			} elseif ( ! empty( $ranges ) ) {
				$type = 'hours';
			} else {
				// 沒有時段也沒有請假＝這天不排班。對應到畫筆的「清除」，存進去就是
				// 「沒有那一列」——跟階段 2 定下的五種狀態一致。
				$type   = 'clear';
				$ranges = array();
			}

			$plan[ $cursor ] = array(
				't' => $type,
				'r' => array_values( (array) $ranges ),
				'l' => self::plan_label( $type, $ranges ),
				's' => self::plan_label( $type, $ranges, true ),
				// 班別顏色（v2.99.0）。跟標籤一樣由 PHP 算好帶出去，產生器塗上去的格子
				// 才會跟存檔後的長得一樣。
				'c' => 'hours' === $type ? UAPPT_Shift_Preset::color_for_ranges( $ranges ) : '',
			);

			$cursor = uappt_local_date( $cursor, '+1 day' );
		}

		return $plan;
	}

	/**
	 * 一天的班要在月曆上印成什麼字。
	 *
	 * ⚠️ **標籤由 PHP 算，不讓 JS 從畫筆按鈕上抄。** 產生器複製過來的時段**不保證**
	 * 剛好等於某一個班別（上個月手動微調過的日子、或同事的班別跟你不一樣），
	 * JS 若退而求其次「隨便挑一顆上班畫筆」當標籤，格子就會寫著「早班 08:00–12:00」
	 * 卻存進完全不同的時段——**畫面在騙人，而存檔結果是對的**，最難發現的那一種。
	 *
	 * 時段剛好等於某個班別時印班別名（這就是 v2.83.0 那個反查的用途）；對不上就
	 * 老實印時間。
	 *
	 * v2.96.0 改成 public：全店月排班表的待審申請也要印同一種標籤（申請的「早班」
	 * 跟已排好的「早班」寫法不一樣的話，主管對不起來）。
	 *
	 * @param string $type   類型（hours／off／leave／clear）。
	 * @param array  $ranges 時段。
	 * @param bool   $short  手機版用的短標籤（一格只有 46px 寬，塞不下時間）。
	 * @return string
	 */
	public static function plan_label( $type, $ranges, $short = false ) {
		if ( self::CLOSED_OFF === $type ) {
			return __( '例休', 'ultimate-appointments' );
		}
		if ( self::CLOSED_LEAVE === $type ) {
			return __( '請假', 'ultimate-appointments' );
		}
		if ( 'hours' !== $type ) {
			return __( '未排班', 'ultimate-appointments' );
		}

		$formatted = UAPPT_Shift_Preset::format_ranges( $ranges );
		$preset     = UAPPT_Shift_Preset::label_for_ranges( $ranges );

		if ( '' !== $preset ) {
			return $short ? $preset : $preset . ' ' . $formatted;
		}

		// 對不上任何班別。短標籤印**開始時間**（「09:00」），不是「自訂」——
		// v2.94.0 改的，理由有兩個：
		//
		// - 跟已存檔的格子一致：month-grid.php 的手機短標籤對不上班別時本來就印開始
		//   時間，只有「產生器塗上去還沒存」的格子印「自訂」，同一天存檔前後長得不一樣。
		// - 全店月排班表一格只放得下短標籤。「自訂」兩個字在一整排都是自訂的工讀生
		//   那一列上等於沒寫；「幾點上班」是五個字裡資訊量最高的。
		if ( $short ) {
			$first = reset( $ranges );
			return is_array( $first ) && isset( $first[0] ) ? (string) $first[0] : __( '自訂', 'ultimate-appointments' );
		}
		return $formatted;
	}

	/**
	 * 把「上個月」的班表對應到「這個月」的每一天。
	 *
	 * **對應規則：月曆上的「第幾列 × 星期幾」。** 上個月月曆第 2 列的週三，對到這個月
	 * 月曆第 2 列的週三。
	 *
	 * 為什麼不是按日期對齊（1 號→1 號）：服務業的班表是**跟著星期走**的（每週二晚班、
	 * 每週日休），按日期對齊會把整組規律錯開——5 月 1 日是週六、6 月 1 日是週二，照
	 * 日期抄過去等於把每個人的班往後挪三天。
	 *
	 * 這個月的列數比上個月多時（4 列對 5 列）就**從上個月的第一列再循環一次**。輪班制
	 * （做二休一）沒有任何對應方式是完全正確的，循環至少是說得出口、可預期的規則；
	 * 不滿意的日子塗一塗就好——產生器只是先幫你塗好，還沒存進去。
	 *
	 * @param array  $staff 人員資料。
	 * @param string $month 這個月 (Y-m)。
	 * @return array 這個月的 date => array{t:string, r:array}
	 */
	public static function get_prev_month_plan_aligned( $staff, $month ) {
		$month_start = $month . '-01';
		$prev_start  = uappt_local_date( $month_start, '-1 month' );
		if ( '' === $prev_start ) {
			return array();
		}
		$prev_start = substr( $prev_start, 0, 7 ) . '-01';

		// 兩個月各自的月曆起點（都是「月初那一週的週一」，跟 get_schedule_month()
		// 同一條規則——⚠️ 兩邊算法一旦分家，對應就會整個錯開）。
		$grid_from      = self::month_grid_start( $month_start );
		$prev_grid_from = self::month_grid_start( $prev_start );
		$prev_grid_to   = self::month_grid_end( $prev_start );

		$prev_weeks = (int) floor( ( self::days_between( $prev_grid_from, $prev_grid_to ) ) / 7 ) + 1;
		if ( $prev_weeks < 1 ) {
			return array();
		}

		$source = self::get_range_plan( $staff, $prev_grid_from, $prev_grid_to );

		$plan      = array();
		$month_end = uappt_local_date( $month_start, 'last day of this month' );
		$cursor    = $month_start;

		while ( '' !== $cursor && $cursor <= $month_end ) {
			$offset = self::days_between( $grid_from, $cursor );
			$week   = (int) floor( $offset / 7 );
			$column = $offset % 7;

			$prev_date = uappt_local_date( $prev_grid_from, '+' . ( ( $week % $prev_weeks ) * 7 + $column ) . ' days' );
			if ( '' !== $prev_date && isset( $source[ $prev_date ] ) ) {
				$plan[ $cursor ] = $source[ $prev_date ];
			}

			$cursor = uappt_local_date( $cursor, '+1 day' );
		}

		return $plan;
	}

	/**
	 * 月曆格線的第一天（月初那一週的週一）。跟 get_schedule_month() 同一條規則。
	 *
	 * @param string $month_start 月初 (Y-m-01)。
	 * @return string
	 */
	protected static function month_grid_start( $month_start ) {
		return uappt_local_date( $month_start, '-' . ( uappt_local_weekday_iso( $month_start ) - 1 ) . ' days' );
	}

	/**
	 * 月曆格線的最後一天（月底那一週的週日）。
	 *
	 * @param string $month_start 月初 (Y-m-01)。
	 * @return string
	 */
	protected static function month_grid_end( $month_start ) {
		$month_end = uappt_local_date( $month_start, 'last day of this month' );
		return uappt_local_date( $month_end, '+' . ( 7 - uappt_local_weekday_iso( $month_end ) ) . ' days' );
	}

	/**
	 * 兩個日期相差幾天（時區安全）。
	 *
	 * @param string $from 起日。
	 * @param string $to   迄日。
	 * @return int
	 */
	protected static function days_between( $from, $to ) {
		$a = date_create( $from . ' 00:00:00', wp_timezone() );
		$b = date_create( $to . ' 00:00:00', wp_timezone() );
		if ( ! $a || ! $b ) {
			return 0;
		}
		return (int) $a->diff( $b )->format( '%r%a' );
	}

	/**
	 * 固定班樣板要往前鋪到哪一天（含）。
	 *
	 * 「開放預約天數」是客人**能**預約的範圍，所以那是必須覆蓋的最小值；再加兩週
	 * 緩衝、往上取整到月底——取整到月底是為了讓管理者打開下個月不是一片空白
	 * （看到空白會以為自己沒排，然後重排一次）。
	 *
	 * @return string Y-m-d。
	 */
	public static function template_horizon_end() {
		$days = max( 90, (int) get_option( 'uappt_booking_horizon_days', 30 ) ) + 14;
		$end  = uappt_local_date( current_time( 'Y-m-d' ), '+' . $days . ' day' );
		return '' !== $end ? uappt_local_date( $end, 'last day of this month' ) : $end;
	}

	/**
	 * 固定班樣板說「這一天」該是什麼（v2.95.0）。
	 *
	 * **「樣板怎麼翻成某一天」只有這一份定義**，兩個地方共用：
	 * - `extend_from_template()`：cron 把樣板鋪成實際的列
	 * - `get_range_plan( …, true )`：「套用固定班樣板」產生器（人員編輯頁、全店月排班表）
	 *
	 * 兩邊各寫一份的話，產生器塗出來的會跟樣板真正寫進去的不一樣——v2.88.0 到
	 * v2.94.0 就是這樣壞掉的（見 get_range_plan() 的 ⚠️）。
	 *
	 * 規則跟 extend_from_template() 一直以來的規則相同：
	 * - 全店公休 → `clear`（樣板在公休日不寫列，理由見那支的 ⚠️）
	 * - 樣板上那個星期空白 → `off`（例休：排過了，結論是休）
	 * - 其他 → `hours`
	 *
	 * 「整張樣板空白」「彈性班」「24 小時」不在這裡判斷——那些是「要不要用樣板」，
	 * 由呼叫端決定；這支只回答「用的話，這一天是什麼」。
	 *
	 * @param array  $staff    人員資料（已 hydrate）。
	 * @param string $date_ymd 日期 (Y-m-d)。
	 * @return array{t:string, r:array}
	 */
	public static function template_day( $staff, $date_ymd ) {
		if ( UAPPT_Shop_Closure::is_closed( $date_ymd ) ) {
			return array( 't' => 'clear', 'r' => array() );
		}

		$template = isset( $staff['business_hours'] ) && is_array( $staff['business_hours'] ) ? $staff['business_hours'] : array();
		$key      = self::WEEKDAY_KEYS[ uappt_local_weekday_w( $date_ymd ) ];
		$ranges   = isset( $template[ $key ] ) && is_array( $template[ $key ] ) ? array_values( $template[ $key ] ) : array();

		return empty( $ranges )
			? array( 't' => self::CLOSED_OFF, 'r' => array() )
			: array( 't' => 'hours', 'r' => $ranges );
	}

	/**
	 * 把某位人員的固定班樣板鋪成實際的逐日資料列。
	 *
	 * **只寫「本來就沒有任何列」的日子**——靠 `UNIQUE(staff_id, override_date)` ＋
	 * `INSERT IGNORE` 達成，不是先查再寫。所以手動塗過的、核准排班申請寫的、
	 * CSV 匯入的日子一律不會被動到（D5：樣板只擁有 `source='template'` 的列），
	 * 而且沒有「查完到寫入之間被別人插隊」的競態。
	 *
	 * ⚠️ **24 小時人員直接跳過。** `get_business_windows()` 對他們走捷徑，條件是
	 * `! day_deviates_from_default()`——幫他們寫任何一列都會讓捷徑失效，他們會從
	 * 「全天開放」變成「只上那一列的時段」。這是 v2.14.0 踩過的坑的反向版本。
	 *
	 * ⚠️ **店休日不寫。** 逐日自訂時段的優先序**高於**店休（這是刻意的，為了讓
	 * 過年留一位師傅值班），所以在店休日寫一列 hours 等於讓這個人在全店公休時
	 * 照常接客。跳過之後那幾天沒有列，月曆照樣顯示紅色的店休；日後若把店休拿掉，
	 * 每天跑的 cron 會自動把它們補起來。
	 *
	 * 樣板上空白的星期寫 `例休`（不是「不寫」）：有那一列才代表「這天排過了，
	 * 結論是休」，護欄量的「班表排到哪一天」才有意義（見階段 2、5）。
	 *
	 * @param array  $staff    人員資料（已 hydrate）。
	 * @param string $from     起日 (Y-m-d)。
	 * @param string $to       迄日 (Y-m-d)。
	 * @param bool   $dry_run  真＝只算會寫幾列，不真的寫。
	 * @return int 實際寫入（或 dry-run 時預計寫入）的列數。
	 */
	public static function extend_from_template( $staff, $from, $to, $dry_run = false ) {
		global $wpdb;

		if ( ! empty( $staff['is_24h'] ) ) {
			return 0;
		}
		// 彈性班＝樣板完全不參與（v2.93.0）。樣板本身留著，切回固定班時還在。
		if ( self::is_flex( $staff ) ) {
			return 0;
		}
		if ( '' === $from || '' === $to || $from > $to ) {
			return 0;
		}

		$template = isset( $staff['business_hours'] ) && is_array( $staff['business_hours'] ) ? $staff['business_hours'] : array();

		// ⚠️ **整張樣板都空白＝這位人員不用固定班，一列都不要鋪。**
		//
		// 下面「空白的星期鋪成例休」那條規則，前提是「這個人有固定班，而這一天是
		// 他的休假日」。整張空白的時候套同一條規則，會把輪班制人員的未來鋪成 120 天
		// 的「例休」——而「例休」的語意是**排過了、結論是休**，跟「還沒排到這個人」
		// 是兩回事（階段 2 定下的五種狀態就是為了分開這兩者）。
		//
		// 鋪成例休還會讓月曆整片顯示「例休」而不是「未排班」，排班的人會以為自己
		// 排過了。護欄本身不受影響（它看的是真的有沒有營業時段），但畫面會騙人。
		$has_template = false;
		foreach ( $template as $ranges ) {
			if ( is_array( $ranges ) && ! empty( $ranges ) ) {
				$has_template = true;
				break;
			}
		}
		if ( ! $has_template ) {
			return 0;
		}

		$now      = current_time( 'mysql' );
		$staff_id = (int) $staff['id'];

		$rows   = array();
		$cursor = $from;
		while ( '' !== $cursor && $cursor <= $to ) {
			// 「樣板說這天是什麼」只有 template_day() 一份定義（產生器也用它）。
			$day = self::template_day( $staff, $cursor );
			if ( 'clear' === $day['t'] ) {
				$cursor = uappt_local_date( $cursor, '+1 day' );
				continue;
			}

			$is_off = ( self::CLOSED_OFF === $day['t'] );
			$rows[] = $wpdb->prepare(
				'(%d, %s, %d, %s, %s, %s, NULL, %s, %s)',
				$staff_id,
				$cursor,
				$is_off ? 1 : 0,
				$is_off ? self::CLOSED_OFF : '',
				$is_off ? null : wp_json_encode( $day['r'] ),
				'template',
				'',
				$now
			);

			$cursor = uappt_local_date( $cursor, '+1 day' );
		}

		if ( empty( $rows ) || $dry_run ) {
			return count( $rows );
		}

		$table = UAPPT_Install::table( 'staff_overrides' );

		// 分批送，一次一個月左右。一次 450 列的 SQL 字串在 max_allowed_packet
		// 小的主機上會被拒絕，而被拒絕的樣子是「什麼都沒發生」。
		$written = 0;
		foreach ( array_chunk( $rows, 60 ) as $chunk ) {
			$written += (int) $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"INSERT IGNORE INTO {$table}
					(staff_id, override_date, is_closed, closed_reason, hours, source, request_id, note, created_at)
				 VALUES " . implode( ',', $chunk )
			);
		}

		self::flush_override_cache( $staff_id );

		return $written;
	}

	/**
	 * 重建某位人員由樣板產生的那些列。
	 *
	 * 「樣板只擁有 `source='template'` 的列」（D5）這條規則在這裡兌現：**先刪掉
	 * 今天以後所有 `source='template'` 的列，再重鋪一次**。手動塗過的、核准申請
	 * 寫的、匯入的通通不動——所以「改了樣板之後，已經排好的日子會不會被洗掉」
	 * 有一個講得出來的答案：不會。
	 *
	 * ⚠️ **只刪今天以後的。** 過去的列是歷史（報表的產能分母會讀它們），改了樣板
	 * 不該追溯修改已經發生過的事。
	 *
	 * @param array $staff 人員資料。
	 * @return int 重新寫入的列數。
	 */
	public static function rebuild_template_rows( $staff ) {
		global $wpdb;

		$today = current_time( 'Y-m-d' );
		$table = UAPPT_Install::table( 'staff_overrides' );

		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE staff_id = %d AND source = %s AND override_date >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(int) $staff['id'],
				'template',
				$today
			)
		);
		self::flush_override_cache( (int) $staff['id'] );

		// 24 小時人員只刪不補：他們不該有任何樣板列（見 extend_from_template()）。
		return self::extend_from_template( $staff, $today, self::template_horizon_end() );
	}

	/**
	 * 把今天以後由樣板產生的列改標成手動（固定班切成彈性班時用）。
	 *
	 * ⚠️ **改標，不是刪除。** 那些日子客人已經約得到，切換排班方式不該讓它們突然
	 * 消失（使用者決定，見 docs/staff-roster-plan.md 的 Q5）。改標之後它們就是
	 * 「已經排好的班」，樣板不再擁有它們——日後切回固定班也不會被樣板重鋪蓋掉
	 * （D5：樣板只擁有 `source='template'` 的列）。
	 *
	 * 過去的列不動：那是歷史，而且它們確實是樣板產生的。
	 *
	 * @param int $staff_id 人員 ID。
	 * @return int 改標的列數。
	 */
	public static function release_template_rows( $staff_id ) {
		global $wpdb;

		$table   = UAPPT_Install::table( 'staff_overrides' );
		$changed = (int) $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET source = %s WHERE staff_id = %d AND source = %s AND override_date >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'manual',
				(int) $staff_id,
				'template',
				current_time( 'Y-m-d' )
			)
		);
		self::flush_override_cache( (int) $staff_id );

		return $changed;
	}

	/**
	 * 所有啟用中人員都往前鋪到 `template_horizon_end()`。
	 *
	 * cron 每天跑這一支維持不變量：**從今天到開放預約期限，每一天都有列。**
	 * 固定班店因此設定一次就不用再碰，而護欄（`coverage_tail()`）對他們永遠不會
	 * 叫——那正是它註解裡原本就寫著的設計意圖。
	 *
	 * @param bool $dry_run 真＝只算會寫幾列。
	 * @return int 寫入的列數。
	 */
	public static function extend_all_from_template( $dry_run = false ) {
		$total = 0;
		foreach ( self::get_all( true ) as $staff ) {
			$total += self::extend_from_template( $staff, current_time( 'Y-m-d' ), self::template_horizon_end(), $dry_run );
		}
		return $total;
	}

	/**
	 * 掃描的天數：預設取「開放預約天數」，並給一個上限。
	 *
	 * 用「開放預約天數」而不是寫死的數字：那正好是客人**能**預約的範圍，超出去的
	 * 缺口本來就約不到，不構成損失。上限 90 是成本考量——開放預約天數設成 365 的
	 * 店不該為了這個提示在每次開後台首頁時多跑一年的班表。
	 *
	 * @param int $days 呼叫端指定的天數；0 或負值代表取設定值。
	 * @return int
	 */
	protected static function coverage_days( $days = 0 ) {
		$days = $days > 0 ? (int) $days : max( 1, (int) get_option( 'uappt_booking_horizon_days', 30 ) );
		return min( $days, 90 );
	}

	/**
	 * **某一位人員**的班表排到哪一天為止。
	 *
	 * v2.87.0 從 `coverage_tail()` 裡抽出來。原本只有全店口徑（任何一人有班就算
	 * 覆蓋），而那個口徑在輪班制的店會**掩蓋掉真正的問題**：小明下個月一天都沒排，
	 * 但小美排滿了，全店看起來一切正常——直到客人指名小明卻看不到任何時段。
	 *
	 * ⚠️ 用 `get_business_windows()` 而不是 `get_business_ranges_for_date()`：
	 * 24 小時人員在後者會回傳空陣列（他們走的是前者的捷徑），只看後者會把每一位
	 * 24 小時人員都報成「完全沒排班」。這是唯一的解析點（紀律 #7）的用途之一。
	 *
	 * @param array $staff 人員資料（已 hydrate）。
	 * @param int   $days  掃幾天；0 代表取「開放預約天數」。
	 * @return array{
	 *     days:int,            掃描的天數
	 *     last_covered:?string 最後一天有班的日期 (Y-m-d)；完全沒有則為 null
	 *     uncovered:int        尾巴有幾天沒班（0 代表排滿了）
	 *     next_gap:string      第一個沒排的日子 (Y-m-d)，給「跳到那個月」用
	 *     flex:bool            這位是不是彈性班
	 *     short:bool           **該不該提醒**。固定班＝尾巴有缺；彈性班＝一週內就會
	 *                          斷班（FLEX_NOTICE_DAYS）。畫面一律看這個，不要自己
	 *                          拿 uncovered 判斷——那會把彈性班從月初叫到月底。
	 * }
	 */
	public static function coverage_for( $staff, $days = 0 ) {
		$days  = self::coverage_days( $days );
		$today = current_time( 'Y-m-d' );
		$end   = uappt_local_date( $today, '+' . ( $days - 1 ) . ' day' );

		// 一次查完整段的 override。⚠️ 要從**前一天**開始：get_business_windows()
		// 為了處理前一天延伸進來的跨午夜班，每一天其實會問兩天的 override。
		self::prime_overrides( $staff['id'], uappt_local_date( $today, '-1 day' ), $end );

		$last_covered = null;
		$cursor       = $today;
		for ( $i = 0; $i < $days; $i++ ) {
			// 「排過了」＝有上班時段，**或那一天已經有結論**（例休、請假的列）。
			// v2.93.0 以前只看有沒有上班時段，週一到五的固定班在開放預約期限剛好落在
			// 週末時，會被報成「之後還有 2 天沒排」——例休的定義明明就是「排過了，
			// 結論是休」（見 CLOSED_OFF），護欄卻把它當成忘了排。
			//
			// ⚠️ **全店公休不算「排過了」**，它在下面數尾巴時另外跳過。v2.93.0 第一版
			// 把公休也算成排過了，結果「每週日公休」的店，一個班都沒排的人只要開放
			// 預約期限的最後一天剛好是週日，就被當成「排到最後一天」——完全沒排班的人
			// 從護欄上消失（v2.96.1 全面檢查時抓到）。公休只該決定「尾巴哪幾天不用
			// 排」，不能替一個人證明他排過班。
			if ( ! empty( self::get_business_windows( $staff, $cursor ) )
				|| self::get_override( $staff['id'], $cursor ) ) {
				$last_covered = $cursor;
			}
			$cursor = uappt_local_date( $cursor, '+1 day' );
			if ( '' === $cursor ) {
				break;
			}
		}

		// ⚠️ **只看尾巴，不報中間的空日。** 中間某一天沒人上班最常見的原因是公休
		// （週日不營業的店，每個週日都是空的），把那些報出來會讓提示每天都在叫、
		// 然後被忽略。而「最後一天之後全空」沒有這種歧義。
		list( $uncovered, $next_gap ) = self::open_tail( $last_covered, $today, $end );

		$flex = empty( $staff['is_24h'] ) && self::is_flex( $staff );

		return array(
			'days'         => $days,
			'last_covered' => $last_covered,
			'uncovered'    => $uncovered,
			'next_gap'     => $next_gap,
			'flex'         => $flex,
			'short'        => $uncovered > 0 && ( ! $flex || self::gap_is_near( $next_gap ) ),
		);
	}

	/**
	 * 「最後排到的那天」之後，到期限為止還有幾天**本來該營業卻沒排**。
	 *
	 * 公休日跳過：尾巴剛好落在公休日不是忘了排，那幾天本來就不開（v2.93.0 想解決
	 * 的是這件事）。但公休日**不會**讓 last_covered 往後挪——那是 coverage_for()
	 * 的事，理由見那裡的 ⚠️。
	 *
	 * @param string|null $last_covered 最後一天排過的日子；null＝完全沒排。
	 * @param string      $today        今天 (Y-m-d)。
	 * @param string      $end          期限最後一天 (Y-m-d)。
	 * @return array{0:int, 1:string} [沒排的天數, 第一個沒排的日子]
	 */
	protected static function open_tail( $last_covered, $today, $end ) {
		$cursor    = null === $last_covered ? $today : uappt_local_date( $last_covered, '+1 day' );
		$uncovered = 0;
		$next_gap  = '';
		while ( '' !== $cursor && $cursor <= $end ) {
			if ( ! UAPPT_Shop_Closure::is_closed( $cursor ) ) {
				$uncovered++;
				if ( '' === $next_gap ) {
					$next_gap = $cursor;
				}
			}
			$cursor = uappt_local_date( $cursor, '+1 day' );
		}

		// 尾巴全部是公休（或已經排到最後一天）：沒有缺口，next_gap 給「隔天」只是
		// 讓呼叫端拿得到一個合法的日期（不會被用來提醒，因為 uncovered 是 0）。
		if ( '' === $next_gap ) {
			$next_gap = null === $last_covered ? $today : uappt_local_date( $last_covered, '+1 day' );
		}

		return array( $uncovered, $next_gap );
	}

	/**
	 * 斷班的那一天是不是在 FLEX_NOTICE_DAYS 天之內。
	 *
	 * @param string $next_gap 第一個沒排的日子 (Y-m-d)。
	 * @return bool
	 */
	protected static function gap_is_near( $next_gap ) {
		$limit = uappt_local_date( current_time( 'Y-m-d' ), '+' . self::FLEX_NOTICE_DAYS . ' day' );
		return '' === $limit || $next_gap < $limit;
	}

	/**
	 * 全店的排班缺口，**逐人算完再彙總**。
	 *
	 * **這支要抓的失效是：輪班制的店把班表排到某一天就忘了往下排。**
	 * 前台不會報錯，只會安靜地沒有任何時段可選——客人約不到，店家什麼都不知道。
	 *
	 * 固定班表的店不會遇到（每週範本自動蓋滿未來），所以這個提示對他們永遠不會
	 * 出現，不會變成雜訊。⚠️ 階段 6 把每週範本降級成「cron 自動產生真實日子」
	 * 之後，這句話仍然成立——cron 維持的就是同一個不變量。
	 *
	 * 回傳值保留了 v2.86.0 以前的四個鍵（全店口徑），另外多一個 `staff`
	 * （逐人明細）與 `gaps`（只有缺口的那幾位）。全店的 `last_covered` 是**所有人
	 * 之中最晚的那一天**——維持舊語意，儀表板那張既有的卡片不用改寫。
	 *
	 * @param int $days 要掃幾天；預設取「開放預約天數」。
	 * @return array{
	 *     days:int, has_active_staff:bool, last_covered:?string, uncovered:int,
	 *     staff:array, gaps:array
	 * }
	 */
	public static function coverage_tail( $days = 0 ) {
		$days = self::coverage_days( $days );

		$staff_list = self::get_all( true );
		if ( empty( $staff_list ) ) {
			return array(
				'days'             => $days,
				'has_active_staff' => false,
				'last_covered'     => null,
				'uncovered'        => $days,
				'short'            => true,
				'staff'            => array(),
				'gaps'             => array(),
			);
		}

		$per_staff    = array();
		$last_covered = null;
		$all_flex     = true;

		foreach ( $staff_list as $staff ) {
			$coverage          = self::coverage_for( $staff, $days );
			$coverage['staff'] = $staff;
			$per_staff[]       = $coverage;
			if ( ! $coverage['flex'] ) {
				$all_flex = false;
			}

			if ( null !== $coverage['last_covered'] && ( null === $last_covered || $coverage['last_covered'] > $last_covered ) ) {
				$last_covered = $coverage['last_covered'];
			}
		}

		// 全店的 uncovered 從全店的 last_covered 推：這是「整間店完全約不到」的
		// 天數，跟逐人的缺口是兩個不同的問題，兩個都要說得出來。
		// 同一條數法（公休日不算缺口），全店與逐人的數字才對得起來。
		$today = current_time( 'Y-m-d' );
		list( $uncovered, $next_gap ) = self::open_tail( $last_covered, $today, uappt_local_date( $today, '+' . ( $days - 1 ) . ' day' ) );

		// 全店口徑的「該不該提醒」（v2.93.0）：**店裡啟用中的人全部是彈性班**時才改用
		// 一週口徑。有任何固定班人員就維持原口徑——固定班有 cron 維持覆蓋，這張卡片
		// 對他們本來就不會叫；會叫的時候一定是真的出事了。
		return array(
			'days'             => $days,
			'has_active_staff' => true,
			'last_covered'     => $last_covered,
			'uncovered'        => $uncovered,
			'short'            => $uncovered > 0 && ( ! $all_flex || self::gap_is_near( $next_gap ) ),
			'staff'            => $per_staff,
			'gaps'             => array_values(
				array_filter(
					$per_staff,
					static function ( $row ) {
						return $row['short'];
					}
				)
			),
		);
	}

	/**
	 * 預熱一段日期區間的逐日調整，之後這個區間內的 get_override() 都不再查資料庫。
	 *
	 * 為什麼需要：get_business_windows() 每算一天就會查一次 override，而且為了
	 * 處理前一天延伸進來的跨日班，它其實會查「前一天」跟「當天」兩次。要畫
	 * 14 天的日期列就是 28 次查詢乘上候選人員數。
	 *
	 * 刻意做成快取而不是再開一條「批次版」的營業時間解析：設計紀律 #7 說營業
	 * 時間只能有一個解析點，複製一份批次版遲早會跟正本走鐘。快取讓
	 * get_business_windows() 一個字都不用改，所有既有呼叫端也一起受惠。
	 *
	 * 任何寫入 override 的路徑都會把快取清掉（見 flush_override_cache() 的
	 * 呼叫點），所以同一個請求裡先寫後讀不會拿到舊值。
	 *
	 * @param int    $staff_id  人員 ID。
	 * @param string $date_from 起始日期 (Y-m-d)，含當天。
	 * @param string $date_to   結束日期 (Y-m-d)，含當天。
	 */
	public static function prime_overrides( $staff_id, $date_from, $date_to ) {
		$staff_id = (int) $staff_id;
		if ( $staff_id <= 0 || '' === $date_from || '' === $date_to || $date_from > $date_to ) {
			return;
		}

		$rows = self::get_overrides_in_range( $staff_id, $date_from, $date_to );

		if ( ! isset( self::$override_cache[ $staff_id ] ) ) {
			self::$override_cache[ $staff_id ] = array();
		}

		// 區間內每一天都要寫進快取，包含「沒有 override」的日子——沒寫進去的話
		// 那些天還是會各自回去查一次資料庫，等於沒省到。
		$cursor = $date_from;
		while ( '' !== $cursor && $cursor <= $date_to ) {
			self::$override_cache[ $staff_id ][ $cursor ] = isset( $rows[ $cursor ] ) ? $rows[ $cursor ] : null;
			$cursor = uappt_local_date( $cursor, '+1 day' );
		}
	}

	/**
	 * 清掉逐日調整快取。任何寫入 override 之後都必須呼叫。
	 *
	 * @param int $staff_id 只清這位人員；0 代表全部清掉。
	 */
	public static function flush_override_cache( $staff_id = 0 ) {
		$staff_id = (int) $staff_id;
		if ( $staff_id > 0 ) {
			unset( self::$override_cache[ $staff_id ] );
			return;
		}
		self::$override_cache = array();
	}

	/**
	 * 取得某人員在某日的請假設定。
	 *
	 * @param int    $staff_id 人員 ID。
	 * @param string $date_ymd 日期 (Y-m-d)。
	 * @return array|null
	 */
	public static function get_override( $staff_id, $date_ymd ) {
		global $wpdb;

		$staff_id = (int) $staff_id;
		if ( isset( self::$override_cache[ $staff_id ] )
			&& array_key_exists( $date_ymd, self::$override_cache[ $staff_id ] )
		) {
			return self::$override_cache[ $staff_id ][ $date_ymd ];
		}

		$table = UAPPT_Install::table( 'staff_overrides' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE staff_id = %d AND override_date = %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$staff_id,
				$date_ymd
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * 一次撈回一段日期區間內的所有逐日調整，key 是日期。
	 *
	 * 月曆檢視要畫一整個月，逐日呼叫 get_override() 就是 30 次查詢。撈回來之後
	 * 用 get_business_ranges_for_date() 既有的 `$override_dry_run` 注入口餵進去
	 * （`null` 代表「這天確定沒有 override」、陣列代表「這天的 override 是這個」），
	 * **不新增第二個營業時間解析點**（設計紀律 #7）——那個參數本來就是為了
	 * 「override 從哪裡來」而存在的，這裡只是多一個呼叫端。
	 *
	 * @param int    $staff_id  人員 ID。
	 * @param string $date_from 起始日期 (Y-m-d)，含當天。
	 * @param string $date_to   結束日期 (Y-m-d)，含當天。
	 * @return array 日期 (Y-m-d) => override 資料列。
	 */
	public static function get_overrides_in_range( $staff_id, $date_from, $date_to ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'staff_overrides' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE staff_id = %d AND override_date >= %s AND override_date <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(int) $staff_id,
				sanitize_text_field( $date_from ),
				sanitize_text_field( $date_to )
			),
			ARRAY_A
		);

		$by_date = array();
		foreach ( (array) $rows as $row ) {
			$by_date[ $row['override_date'] ] = $row;
		}

		return $by_date;
	}


	/**
	 * 新增或更新某日請假／逐日自訂時段設定。
	 * `is_closed=1`（整天休假）時 `hours` 一律清空，兩者不會同時生效——
	 * 一天只有一列（見 UNIQUE KEY staff_date），全休與自訂時段是互斥的兩種狀態。
	 *
	 * @param array $data {
	 *     @type int    $staff_id      人員 ID。
	 *     @type string $override_date 日期 (Y-m-d)。
	 *     @type bool   $is_closed     是否整天不開放。
	 *     @type string $closed_reason 為什麼不開放：CLOSED_OFF（例休）或 CLOSED_LEAVE（請假）。
	 *                                 只在 $is_closed 為真時有意義；**沒給一律當請假**，
	 *                                 理由見 sanitize_closed_reason()。
	 *     @type array  $hours         逐日自訂時段，[[開始,結束], ...]（is_closed 為真時忽略）。
	 *     @type string $note          備註。
	 *     @type string $source        來源，預設 'manual'（申請核准會傳 'shift_request'）。
	 *     @type int    $request_id    來源申請的 ID（無則為 0）。
	 * }
	 * @return int|WP_Error
	 */
	public static function save_override( $data ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'staff_overrides' );

		$staff_id = isset( $data['staff_id'] ) ? (int) $data['staff_id'] : 0;
		$date     = isset( $data['override_date'] ) ? sanitize_text_field( $data['override_date'] ) : '';

		if ( ! $staff_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'uappt_invalid_override', __( '請假日期資料不完整。', 'ultimate-appointments' ) );
		}

		$is_closed  = ! empty( $data['is_closed'] ) ? 1 : 0;
		// ⚠️ 只有「關起來」的日子才有「為什麼關」。沒關的日子一律存空字串，不要留
		// 上一次的值——否則同一列從請假改成自訂時段之後，closed_reason 還寫著
		// 'leave'，之後誰拿它做統計都會多算一天。
		$closed_reason = $is_closed
			? self::sanitize_closed_reason( isset( $data['closed_reason'] ) ? $data['closed_reason'] : '' )
			: '';
		$note       = isset( $data['note'] ) ? sanitize_text_field( $data['note'] ) : '';
		$source     = isset( $data['source'] ) && sanitize_key( $data['source'] ) ? sanitize_key( $data['source'] ) : 'manual';
		$request_id = isset( $data['request_id'] ) ? (int) $data['request_id'] : 0;

		$hours_json = null;
		if ( ! $is_closed ) {
			$errors     = array();
			$hours_json = self::encode_day_hours(
				isset( $data['hours'] ) && is_array( $data['hours'] ) ? $data['hours'] : array(),
				$date,
				$errors
			);
			if ( $errors ) {
				return new WP_Error( 'uappt_invalid_override_hours', implode( ' ', $errors ) );
			}
		}

		// ⚠️ **$fields 與 $formats 一定要位置對位置。** v2.76.0 踩過一次錯位，
		// 症狀是資料被寫到別的欄位而且不會報錯——新增欄位時兩個陣列要一起改。
		$fields  = array(
			'is_closed'     => $is_closed,
			'closed_reason' => $closed_reason,
			'hours'         => $hours_json,
			'source'        => $source,
			'request_id'    => $request_id ? $request_id : null,
			'note'          => $note,
		);
		$formats = array( '%d', '%s', '%s', '%s', '%d', '%s' );

		$existing = self::get_override( $staff_id, $date );

		if ( $existing ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$table,
				$fields,
				array( 'id' => $existing['id'] ),
				$formats,
				array( '%d' )
			);
			self::flush_override_cache( $staff_id );
			return (int) $existing['id'];
		}

		$fields['staff_id']      = $staff_id;
		$fields['override_date'] = $date;
		$fields['created_at']    = current_time( 'mysql' );

		$wpdb->insert( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$table,
			$fields,
			array_merge( $formats, array( '%d', '%s', '%s' ) )
		);

		self::flush_override_cache( $staff_id );

		return (int) $wpdb->insert_id;
	}

	/**
	 * 刪除請假紀錄。
	 *
	 * @param int $override_id 請假紀錄 ID。
	 */
	public static function delete_override( $override_id ) {
		global $wpdb;
		$wpdb->delete( UAPPT_Install::table( 'staff_overrides' ), array( 'id' => (int) $override_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// 這支只拿得到 override_id，不知道是哪位人員，索性整份清掉——
		// 刪除逐日調整是低頻操作，沒必要為了精準而多查一次。
		self::flush_override_cache();
	}

	/**
	 * 將營業時間陣列編碼為 JSON 字串，並確保七天鍵值齊全。
	 * 格式錯誤或起訖相同的列會記錄到 $errors（by reference），不會被靜默丟棄。
	 *
	 * @param array $hours  營業時間陣列。
	 * @param array $errors 由參照傳入，錯誤訊息會附加到這裡。
	 * @return string
	 */
	protected static function encode_hours( $hours, array &$errors ) {
		$normalized = array();

		foreach ( self::WEEKDAY_KEYS as $key ) {
			$raw_ranges          = ( ! empty( $hours[ $key ] ) && is_array( $hours[ $key ] ) ) ? $hours[ $key ] : array();
			$normalized[ $key ] = self::sanitize_ranges( $raw_ranges, self::weekday_label( $key ), $errors );
		}

		return wp_json_encode( $normalized );
	}

	/**
	 * 解碼營業時間 JSON。
	 *
	 * @param string|null $json JSON 字串。
	 * @return array
	 */
	protected static function decode_hours( $json ) {
		$decoded = $json ? json_decode( $json, true ) : array();
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}
		foreach ( self::WEEKDAY_KEYS as $key ) {
			if ( ! isset( $decoded[ $key ] ) || ! is_array( $decoded[ $key ] ) ) {
				$decoded[ $key ] = array();
			}
		}
		return $decoded;
	}

	/**
	 * 驗證並清理一組時段（[開始, 結束] 的陣列）。每週範本（逐天呼叫）與
	 * 逐日自訂時段（`override.hours`）共用同一套規則，包含 `sanitize_time()`
	 * 對 `930`／`9`／`9:5` 這類寫法的容錯，以及「結束早於開始＝跨午夜」的既有慣例。
	 * 格式錯誤或起訖相同的列會記錄到 $errors（by reference），不會被靜默丟棄；
	 * 兩邊都空白的列視為沒填，直接跳過、不算錯誤。
	 *
	 * 公開方法——排班申請（v2.15.0）的「自訂時段」申請也要用同一套規則驗證，
	 * 不要另外手寫一個時間解析器（見 UAPPT_Shift_Request::create()）。
	 *
	 * @param array  $ranges       時段陣列，每個元素 [開始, 結束]。
	 * @param string $label_prefix 錯誤訊息用的描述前綴（例如「週三」或某個日期）。
	 * @param array  $errors       由參照傳入，錯誤訊息會附加到這裡。
	 * @return array 清理後的時段陣列。
	 */
	public static function sanitize_ranges( $ranges, $label_prefix, array &$errors ) {
		$clean = array();

		if ( ! is_array( $ranges ) ) {
			return $clean;
		}

		foreach ( $ranges as $index => $range ) {
			$raw_start = isset( $range[0] ) ? $range[0] : '';
			$raw_end   = isset( $range[1] ) ? $range[1] : '';

			if ( '' === trim( (string) $raw_start ) && '' === trim( (string) $raw_end ) ) {
				continue; // 兩邊都空白代表沒填，不是錯誤。
			}

			$label = sprintf(
				/* translators: 1: 星期幾或日期 2: 時段序號（第幾組時間） */
				__( '%1$s 時段%2$d', 'ultimate-appointments' ),
				$label_prefix,
				$index + 1
			);

			$start = self::sanitize_time( $raw_start );
			$end   = self::sanitize_time( $raw_end );

			if ( null === $start || null === $end ) {
				$errors[] = sprintf(
					/* translators: %s: 時段描述，例如「週三 時段1」 */
					__( '%s 的時間格式不正確，請輸入類似 09:30 的格式。', 'ultimate-appointments' ),
					$label
				);
				continue;
			}

			// 結束時間早於開始時間 = 跨日班別（例如 20:00–02:00），是合法設定。
			// 只有起訖完全相同才是錯的（零長度區間，產不出任何時段；整天不打烊
			// 請直接勾選「24 小時營業」，不要試著填 00:00–00:00）。
			if ( $start === $end ) {
				$errors[] = sprintf(
					/* translators: %s: 時段描述 */
					__( '%s 的開始與結束時間不可相同。若要設定跨日班別（例如 20:00 到隔天 02:00），把結束時間填成比開始時間早即可；整天都上班請把排班方式改成「24 小時」。', 'ultimate-appointments' ),
					$label
				);
				continue;
			}

			$clean[] = array( $start, $end );
		}

		return $clean;
	}

	/**
	 * 將某一天的逐日自訂時段編碼為 JSON，供 `staff_overrides.hours` 儲存。
	 * 清理後是空陣列時回傳 null——代表「沒有自訂時段，回退到每週範本」，
	 * 跟「有 override 但時段是空的」刻意不做區分，避免產生一種
	 * 「有 override、卻永遠不會有任何可預約時段」的死狀態。
	 *
	 * @param array  $ranges       時段陣列，每個元素 [開始, 結束]。
	 * @param string $label_prefix 錯誤訊息用的日期描述。
	 * @param array  $errors       由參照傳入，錯誤訊息會附加到這裡。
	 * @return string|null
	 */
	protected static function encode_day_hours( $ranges, $label_prefix, array &$errors ) {
		$clean = self::sanitize_ranges( $ranges, $label_prefix, $errors );

		if ( empty( $clean ) ) {
			return null;
		}

		return wp_json_encode( $clean );
	}

	/**
	 * 業績抽成設定的預設值：不抽成。
	 *
	 * 空的級距表就是「這位人員不抽成」——不要塞一組假的預設比例進去，
	 * 那會讓沒設定過的人員在報表上憑空長出一筆抽成金額。
	 *
	 * @return array{mode:string, tiers:array, upcharge_rate:float}
	 */
	public static function default_commission() {
		return array(
			// marginal＝累進（分段套用）；flat＝全額適用（達標整包跳級）。
			// 兩種在台灣美業都很常見，差距很大：業績 25 萬、級距 20 萬以下
			// 30%／以上 40% 的話，累進是 80,000、全額是 100,000，差 25%。
			// 所以不預設任何一種「業界慣例」，由每位人員各自指定。
			'mode'          => 'marginal',
			'tiers'         => array(),
			// 指定加價的分成率（%）。獨立於級距之外，見 calculate_commission()。
			'upcharge_rate' => 0.0,
		);
	}

	/**
	 * 把抽成設定清洗成可信的結構。
	 *
	 * @param mixed $config 來源（表單或 JSON 解出來的陣列）。
	 * @return array
	 */
	public static function sanitize_commission( $config ) {
		$out = self::default_commission();

		if ( ! is_array( $config ) ) {
			return $out;
		}

		if ( isset( $config['mode'] ) && 'flat' === $config['mode'] ) {
			$out['mode'] = 'flat';
		}

		if ( isset( $config['upcharge_rate'] ) ) {
			$out['upcharge_rate'] = min( 100.0, max( 0.0, round( (float) $config['upcharge_rate'], 3 ) ) );
		}

		$tiers = array();
		if ( isset( $config['tiers'] ) && is_array( $config['tiers'] ) ) {
			foreach ( $config['tiers'] as $tier ) {
				if ( ! is_array( $tier ) ) {
					continue;
				}
				$raw_rate = isset( $tier['rate'] ) ? $tier['rate'] : '';
				$from     = isset( $tier['from'] ) ? (float) $tier['from'] : 0.0;

				// ⚠️ **「沒填」跟「填 0」是兩回事，一定要用原始值分辨。**
				//
				// 級距表永遠多印一列空白供新增（見 staff-edit.php），那一列送
				// 上來是 rate=''，轉成 float 就變 0.0。v2.76.0 以前靠
				// `$rate <= 0` 一起擋掉，等於讓同一條規則兼差當空白列過濾器
				// ——所以 0% 也一併被丟掉，做不出「基本業績門檻」。
				//
				// 現在改成看原始值：空字串／null＝這一列沒填，丟掉；明確打了
				// 0 才算 0%。只填門檻沒填比例的也丟掉——沒有比例的級距不是
				// 級距，而把它當成 0% 會悄悄讓人領不到錢。
				if ( null === $raw_rate || '' === trim( (string) $raw_rate ) ) {
					continue;
				}

				$rate = (float) $raw_rate;
				if ( $rate < 0 ) {
					continue;
				}

				$tiers[] = array(
					'from' => max( 0.0, round( $from, 2 ) ),
					'rate' => min( 100.0, round( $rate, 3 ) ),
				);
			}
		}

		usort(
			$tiers,
			function ( $a, $b ) {
				return $a['from'] <=> $b['from'];
			}
		);

		// 最低那一段沒從 0 開始時，補一段 0%，而**不是**把它的門檻拉到 0。
		//
		// ⚠️ v2.76.0 以前這裡是 `$tiers[0]['from'] = 0.0;`——使用者填「20 萬
		// 以上 30%」會被靜默改寫成「0 以上 30%」，業績 15 萬照抽 45,000。
		// 那個解釋是**最貴的那一種**，而薪資試算寧可少算也不要多算：多算的話
		// 店家發現時錢已經照那個數字發出去了。
		//
		// 補 0% 段則是照字面讀：「20 萬以上抽 30%」就是 20 萬以下不抽。
		// calculate_commission() 一樣有東西可以算，不會回到當初要防的那個問題。
		if ( $tiers && $tiers[0]['from'] > 0 ) {
			array_unshift(
				$tiers,
				array(
					'from' => 0.0,
					'rate' => 0.0,
				)
			);
		}

		// 門檻重複的只留第一個（排序後在前面的那個），不然累進計算會出現
		// 寬度為 0 的區段。
		$seen  = array();
		$clean = array();
		foreach ( $tiers as $tier ) {
			$key = (string) $tier['from'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$clean[]      = $tier;
		}

		$out['tiers'] = $clean;

		return $out;
	}

	/**
	 * 抽成設定 → JSON（存進資料表）。
	 *
	 * 沒有任何級距也沒有指定加價分成時存 NULL，不存 `{"tiers":[]}`——
	 * 「沒設定」跟「設定成空的」在這裡是同一件事，而 NULL 讓查詢一眼看得出
	 * 哪些人員還沒設定過。
	 *
	 * ⚠️ **「每一段都是 0%」也算沒設定**（v2.76.0）：0% 級距開放之後，只填
	 * 一段 0% 會存出一張非空的級距表，但它算出來永遠是 0。讓它落到 NULL，
	 * 抽成報表才會照舊把這位人員整個略過（見 class-uappt-admin.php 的
	 * `empty( $config['tiers'] )` 判斷），而不是列出一整排 0 元。
	 *
	 * @param mixed $config 設定。
	 * @return string|null
	 */
	public static function encode_commission( $config ) {
		$clean = self::sanitize_commission( $config );

		$has_rate = false;
		foreach ( $clean['tiers'] as $tier ) {
			if ( $tier['rate'] > 0 ) {
				$has_rate = true;
				break;
			}
		}

		if ( ! $has_rate && $clean['upcharge_rate'] <= 0 ) {
			return null;
		}

		return wp_json_encode( $clean );
	}

	/**
	 * JSON → 抽成設定。
	 *
	 * @param string|null $json 資料表欄位。
	 * @return array
	 */
	public static function decode_commission( $json ) {
		if ( '' === $json || null === $json ) {
			return self::default_commission();
		}

		$decoded = json_decode( $json, true );

		return self::sanitize_commission( is_array( $decoded ) ? $decoded : array() );
	}

	/**
	 * 算一位人員在**某一個日曆月**的抽成。
	 *
	 * ⚠️ **級距天生是「月」的東西**，不能拿任意期間去套：看一週的報表算不出
	 * 抽成，因為不知道整個月會落在哪一級。呼叫端一定要傳整月的數字進來。
	 *
	 * **一般業績與指定加價完全分開算**（v2.63.0 的設計決定）：
	 *
	 *     一般業績 = 業績 − 指定加價
	 *     抽成     = 級距套用( 一般業績 ) + 指定加價 × 指定加價分成率
	 *
	 * 級距的門檻也只看**一般業績**，不含指定加價——「獨立比例」的意思就是
	 * 那筆錢走自己的一套，包括不參與級距的判定。這個取捨要寫在畫面上，
	 * 不然使用者會以為指定費也能墊高級距。
	 *
	 * @param array $config   抽成設定（已清洗）。
	 * @param float $revenue  該月業績（含指定加價，就是報表上的「業績」）。
	 * @param float $upcharge 該月的指定加價合計。
	 * @return array{base:float, tier_rate:float, tier_amount:float, upcharge_amount:float, total:float}
	 */
	public static function calculate_commission( array $config, $revenue, $upcharge ) {
		$config   = self::sanitize_commission( $config );
		$upcharge = max( 0.0, (float) $upcharge );
		$base     = max( 0.0, (float) $revenue - $upcharge );

		$tier_amount = 0.0;
		$tier_rate   = 0.0;

		if ( $config['tiers'] ) {
			if ( 'flat' === $config['mode'] ) {
				// 全額適用：找出達到的最高一級，整筆套那個比例。
				foreach ( $config['tiers'] as $tier ) {
					if ( $base >= $tier['from'] ) {
						$tier_rate = $tier['rate'];
					}
				}
				$tier_amount = $base * $tier_rate / 100;
			} else {
				// 累進：每一段只對「落在這一段裡的金額」套自己的比例。
				$count = count( $config['tiers'] );
				foreach ( $config['tiers'] as $i => $tier ) {
					$upper = ( $i + 1 < $count ) ? $config['tiers'][ $i + 1 ]['from'] : INF;
					$slice = min( $base, $upper ) - $tier['from'];
					if ( $slice <= 0 ) {
						continue;
					}
					$tier_amount += $slice * $tier['rate'] / 100;
					$tier_rate    = $tier['rate']; // 記錄最後（最高）觸及的那一級，畫面上顯示用
				}
			}
		}

		$upcharge_amount = $upcharge * $config['upcharge_rate'] / 100;

		return array(
			'base'            => round( $base, 2 ),
			'tier_rate'       => $tier_rate,
			'tier_amount'     => round( $tier_amount, 2 ),
			'upcharge_amount' => round( $upcharge_amount, 2 ),
			'total'           => round( $tier_amount + $upcharge_amount, 2 ),
		);
	}

	/**
	 * 解碼時段分類設定。
	 *
	 * ⚠️ v2.55.0 起**沒有任何地方會讀這個值**——時段分類已經搬成全站設定
	 * （UAPPT_Product::segment_config()）。這裡仍然解碼是刻意的：欄位還在，
	 * 萬一遷移挑錯了那一組，原始值仍然讀得出來。等確認沒問題之後，這支與
	 * 欄位可以一起清掉。
	 *
	 * @param string|null $json JSON 字串。
	 * @return array{labels:array, boundaries:array}
	 */
	protected static function decode_segments( $json ) {
		$defaults = UAPPT_Product::default_segment_config();
		$decoded  = $json ? json_decode( $json, true ) : null;

		if ( ! is_array( $decoded ) || ! isset( $decoded['labels'], $decoded['boundaries'] ) ) {
			return $defaults;
		}

		$labels = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$label        = isset( $decoded['labels'][ $i ] ) ? (string) $decoded['labels'][ $i ] : '';
			$labels[ $i ] = ( '' !== $label ) ? $label : $defaults['labels'][ $i ];
		}

		$boundaries = array();
		for ( $i = 0; $i < 2; $i++ ) {
			$time             = isset( $decoded['boundaries'][ $i ] ) ? (string) $decoded['boundaries'][ $i ] : '';
			$boundaries[ $i ] = preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time ) ? $time : $defaults['boundaries'][ $i ];
		}

		return array(
			'labels'     => $labels,
			'boundaries' => $boundaries,
		);
	}

	/**
	 * 星期 key 對應的單字標籤（日／一／二…）。
	 *
	 * 跟 weekday_label() 分開是因為用途不同：那支給錯誤訊息用，回「週日」是
	 * 完整的；這支是要拼進「每週日、六」這種清單，塞「週日、週六」會變成
	 * 「每週週日、週六」。
	 *
	 * @param string $key sun/mon/tue/wed/thu/fri/sat.
	 * @return string
	 */
	public static function weekday_short_label( $key ) {
		$labels = array(
			'sun' => __( '日', 'ultimate-appointments' ),
			'mon' => __( '一', 'ultimate-appointments' ),
			'tue' => __( '二', 'ultimate-appointments' ),
			'wed' => __( '三', 'ultimate-appointments' ),
			'thu' => __( '四', 'ultimate-appointments' ),
			'fri' => __( '五', 'ultimate-appointments' ),
			'sat' => __( '六', 'ultimate-appointments' ),
		);
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	/**
	 * 星期 key 對應的中文標籤，供錯誤訊息使用。
	 *
	 * @param string $key sun/mon/tue/wed/thu/fri/sat.
	 * @return string
	 */
	protected static function weekday_label( $key ) {
		$labels = array(
			'sun' => __( '週日', 'ultimate-appointments' ),
			'mon' => __( '週一', 'ultimate-appointments' ),
			'tue' => __( '週二', 'ultimate-appointments' ),
			'wed' => __( '週三', 'ultimate-appointments' ),
			'thu' => __( '週四', 'ultimate-appointments' ),
			'fri' => __( '週五', 'ultimate-appointments' ),
			'sat' => __( '週六', 'ultimate-appointments' ),
		);
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	/**
	 * 檢查並正規化時間字串，接受多種手動輸入格式，一律正規化為 HH:mm：
	 * - 標準格式："09:30"、"9:30"、"9:5"
	 * - 純數字："930"（→09:30）、"1830"（→18:30）、"0930"（→09:30）、
	 *   "9"（→09:00，1-2 碼視為只填小時）
	 *
	 * ⚠️ public 而不是 protected：UAPPT_Product 的時段分類設定（v2.55.0 從這裡
	 * 搬過去的全站設定）要用同一套寬鬆解析，管理者在兩邊填時間的體驗才一致。
	 *
	 * @param string $time 時間字串。
	 * @return string|null 正規化後的 "HH:mm"，無法辨識則回傳 null。
	 */
	public static function sanitize_time( $time ) {
		$time = trim( (string) $time );
		if ( '' === $time ) {
			return null;
		}

		if ( preg_match( '/^(\d{1,2}):(\d{1,2})$/', $time, $matches ) ) {
			$hour   = (int) $matches[1];
			$minute = (int) $matches[2];
			return ( $hour <= 23 && $minute <= 59 ) ? sprintf( '%02d:%02d', $hour, $minute ) : null;
		}

		if ( preg_match( '/^\d{1,4}$/', $time ) ) {
			$len = strlen( $time );
			if ( $len <= 2 ) {
				$hour   = (int) $time;
				$minute = 0;
			} elseif ( 3 === $len ) {
				$hour   = (int) substr( $time, 0, 1 );
				$minute = (int) substr( $time, 1, 2 );
			} else {
				$hour   = (int) substr( $time, 0, 2 );
				$minute = (int) substr( $time, 2, 2 );
			}
			return ( $hour <= 23 && $minute <= 59 ) ? sprintf( '%02d:%02d', $hour, $minute ) : null;
		}

		return null;
	}
}
