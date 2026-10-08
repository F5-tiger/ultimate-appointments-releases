<?php
/**
 * 耗材管理：主檔、配方、異動流水帳。
 *
 * 美容／美甲這類業態每做一次服務就會用掉一些東西（凝膠、面膜、拋棄式毛巾），
 * 店家要知道還剩多少、快沒了沒、花了多少成本、帳跟實際差多少。
 *
 * 三個**不可違反**的規則（改之前先讀完理由，這幾條錯了資料就對不起來）：
 *
 * 1. **扣帳一律寫流水帳，不直接改 `consumables.stock`。** `stock` 只是結存
 *    快照，事實來源是 `consumable_moves`。有流水帳才查得出「這個月用掉多少、
 *    誰用的、哪筆預約用的」（報表要用），回沖才能用「寫一筆反向異動」而不是
 *    刪紀錄，盤點才留得下「帳面 vs 實際」的落差——那個落差正是店家想知道的
 *    耗損率。保留 `stock` 欄位是為了不用每次 SUM 整張表（跟 `bookings.amount`
 *    同一個設計哲學，設計紀律 #4），帳不符時用 `recalculate_stock()` 重算。
 *
 * 2. **扣帳點是「服務完成」，還原時必須配對回沖。** 耗材是真的做了才用掉，
 *    所以扣在 `UAPPT_Booking::complete()` 成功之後；`no_show` 不扣（人沒來就
 *    沒用到）。⚠️ `revert_to_confirmed()` 一定要呼叫 `revert_for_booking()`，
 *    漏掉的話每還原一次庫存就少一份，而且沒有任何地方會報錯。
 *
 * 3. **成本用快照。** 扣帳當下把 `unit_cost`／`cost_total` 寫進異動列，
 *    進貨價之後漲了不會回頭改歷史，報表的材料成本／毛利才站得住。
 *
 * 庫存允許扣成負數，這是刻意的：服務已經做完了，不該因為帳面數字不夠就擋下來
 * 或悄悄扣不到。負數是一個「該去盤點了」的訊號，清單頁會標紅。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Consumable {

	const STATUS_ACTIVE   = 'active';
	const STATUS_INACTIVE = 'inactive';

	// 異動類型。`revert` 跟 `restock` 刻意分開：兩者都是把數量加回去，但
	// 「還原一筆誤標完成的預約」跟「進了一批新貨」在報表上是完全不同的事。
	const MOVE_CONSUME = 'consume';
	const MOVE_RESTOCK = 'restock';
	const MOVE_ADJUST  = 'adjust';
	const MOVE_WASTE   = 'waste';
	const MOVE_REVERT  = 'revert';

	// 配方的三個層級。`global` 是 union（每次服務都加上去），`plan` 覆寫
	// `product`——跟服務方案「留空＝沿用商品層級」同一套心智模型。
	const SCOPE_GLOBAL  = 'global';
	const SCOPE_PRODUCT = 'product';
	const SCOPE_PLAN    = 'plan';

	/**
	 * 低量提醒每天最多發一次的去重旗標（存最後一次發送的日期 Y-m-d）。
	 */
	const LOW_STOCK_NOTICE_OPTION = 'uappt_low_stock_notified_on';

	/**
	 * 掛載執行期的 hook。沒有實例狀態，跟 UAPPT_Caps／UAPPT_Service_Index 一樣
	 * 是靜態 init()。
	 */
	public static function init() {
		// 低量提醒搭既有的提醒排程便車（每 5 分鐘一次），自己用「今天發過了
		// 沒」的旗標去重，不另外開一支 cron——多一支排程就多一個會在低流量
		// 站台上不準時觸發的東西。
		add_action( UAPPT_Cron::HOOK_REMINDERS, array( __CLASS__, 'maybe_notify_low_stock' ) );
	}

	/* ---------------------------------------------------------------------
	 * 主檔 CRUD
	 * ------------------------------------------------------------------- */

	/**
	 * 取得耗材清單。
	 *
	 * @param array $args {
	 *     @type string $status  'active'／'inactive'／'any'（預設 'any'）。
	 *     @type string $search  名稱或 SKU 的模糊比對。
	 *     @type bool   $low_only 只回傳低於警示量的。
	 * }
	 * @return array
	 */
	public static function get_all( $args = array() ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'consumables' );

		$where  = array( '1=1' );
		$params = array();

		$status = isset( $args['status'] ) ? $args['status'] : 'any';
		if ( 'any' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( name LIKE %s OR sku LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $args['low_only'] ) ) {
			// 沒設警示量的不算低量——「沒設定」跟「設成 0」是兩件事，前者是
			// 店家還沒決定要在哪裡示警，不該替他猜一個。
			$where[] = 'low_stock_threshold IS NOT NULL AND stock <= low_stock_threshold';
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY sort_order ASC, name ASC';

		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $rows ? $rows : array();
	}

	/**
	 * 取得單筆耗材。
	 *
	 * @param int $id 耗材 ID。
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'consumables' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : null;
	}

	/**
	 * 依 ID 清單一次撈回多筆，回傳以 ID 為鍵的陣列。異動紀錄、配方頁都要
	 * 「一堆 ID → 名稱」，各自迴圈呼叫 get() 會變成 N 次查詢。
	 *
	 * @param int[] $ids 耗材 ID 清單。
	 * @return array id => row
	 */
	public static function get_many( $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}

		$table        = UAPPT_Install::table( 'consumables' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * 新增或更新耗材主檔。
	 *
	 * ⚠️ **這支不碰 `stock`。** 庫存只能透過 `record_move()` 變動，不然流水帳
	 * 跟結存就對不起來了（規則 1）。新增時的「期初庫存」由呼叫端在建立之後
	 * 補寫一筆 `restock` 異動，理由一樣：那也是一次真實的入庫。
	 *
	 * @param array $data 欄位資料，含 id 時為更新。
	 * @return int|WP_Error 耗材 ID。
	 */
	public static function save( $data ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'consumables' );

		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		if ( '' === trim( $name ) ) {
			return new WP_Error( 'uappt_consumable_no_name', __( '耗材名稱不能空白。', 'ultimate-appointments' ) );
		}

		$fields = array(
			'name'       => $name,
			'sku'        => isset( $data['sku'] ) ? sanitize_text_field( $data['sku'] ) : '',
			'unit'       => isset( $data['unit'] ) ? sanitize_text_field( $data['unit'] ) : '',
			'status'     => ( isset( $data['status'] ) && self::STATUS_INACTIVE === $data['status'] ) ? self::STATUS_INACTIVE : self::STATUS_ACTIVE,
			'note'       => isset( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : '',
			'sort_order' => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
			'updated_at' => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

		// 警示量與單價都是「可以不填」的：NULL 代表沒設定，0 代表真的設成 0。
		// 兩者在低量判斷（`low_stock_threshold IS NOT NULL`）與成本計算上意義
		// 完全不同，不能把空字串當成 0 存進去。
		$fields['low_stock_threshold'] = ( isset( $data['low_stock_threshold'] ) && '' !== $data['low_stock_threshold'] )
			? (float) $data['low_stock_threshold']
			: null;
		$formats[] = '%f';

		$fields['unit_cost'] = ( isset( $data['unit_cost'] ) && '' !== $data['unit_cost'] )
			? (float) $data['unit_cost']
			: null;
		$formats[] = '%f';

		$id = isset( $data['id'] ) ? absint( $data['id'] ) : 0;

		if ( $id ) {
			$wpdb->update( $table, $fields, array( 'id' => $id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return $id;
		}

		$fields['created_at'] = current_time( 'mysql' );
		$formats[]            = '%s';

		$inserted = $wpdb->insert( $table, $fields, $formats ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $inserted ) {
			return new WP_Error( 'uappt_consumable_insert_failed', __( '建立耗材時發生錯誤，請稍後再試。', 'ultimate-appointments' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * 刪除耗材。
	 *
	 * **有異動紀錄的一律不給刪**，只能停用。刪掉之後那些異動列會變成孤兒，
	 * 異動紀錄頁查不到名稱、報表的歷史用量也會憑空少一塊——而「這個耗材不再
	 * 使用了」的真正需求，停用就已經滿足（前台不會再被扣、配方頁不再列出，
	 * 但歷史查得到）。
	 *
	 * @param int $id 耗材 ID。
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return new WP_Error( 'uappt_consumable_missing', __( '找不到這筆耗材。', 'ultimate-appointments' ) );
		}

		$moves = UAPPT_Install::table( 'consumable_moves' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$moves} WHERE consumable_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $count > 0 ) {
			return new WP_Error(
				'uappt_consumable_has_moves',
				__( '這個耗材已經有異動紀錄，不能刪除（刪掉會讓歷史用量與成本憑空少一塊）。改用「停用」：之後不會再被扣用、配方頁也不再列出，但過去的紀錄查得到。', 'ultimate-appointments' )
			);
		}

		$wpdb->delete( UAPPT_Install::table( 'consumable_recipes' ), array( 'consumable_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->delete( UAPPT_Install::table( 'consumables' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return true;
	}

	/* ---------------------------------------------------------------------
	 * 異動流水帳
	 * ------------------------------------------------------------------- */

	/**
	 * 寫一筆異動，並同步結存快照。**所有庫存變動的唯一入口。**
	 *
	 * 在交易裡用 `SELECT ... FOR UPDATE` 鎖住那一列再算新結存：兩筆預約同時
	 * 被標記完成、又剛好用到同一個耗材時，不鎖的話兩邊會讀到同一個舊結存、
	 * 各自算出同一個新值，其中一筆的扣用等於消失。這跟 `slot_grid` 用
	 * UNIQUE 擋超賣是同一種「資料庫層級才擋得住」的問題。
	 *
	 * @param int    $consumable_id 耗材 ID。
	 * @param string $type          異動類型（MOVE_* 常數）。
	 * @param float  $qty           變動量，**帶正負號**（扣用是負的）。
	 * @param array  $args {
	 *     @type int    $booking_id 關聯的預約 ID。
	 *     @type int    $staff_id   關聯的人員 ID。
	 *     @type string $note       備註。
	 *     @type int    $created_by 操作者；預設目前登入者。
	 *     @type float  $set_to     盤點用：把結存校正成這個數字，$qty 由鎖住之後讀到的帳面算出
	 *                              （傳進來的 $qty 不看）。差額是 0 就回 WP_Error。
	 * }
	 * @return int|WP_Error 異動 ID。
	 */
	public static function record_move( $consumable_id, $type, $qty, $args = array() ) {
		global $wpdb;

		$consumable_id = absint( $consumable_id );
		$qty           = round( (float) $qty, 3 );
		$set_to        = isset( $args['set_to'] ) ? round( (float) $args['set_to'], 3 ) : null;

		if ( ! $consumable_id ) {
			return new WP_Error( 'uappt_consumable_missing', __( '找不到這筆耗材。', 'ultimate-appointments' ) );
		}
		// 0 的異動不寫：它不改變任何東西，只會讓異動紀錄多出一堆看不出意義的列。
		// 盤點（set_to）的差額要鎖住之後才知道，下面再判斷。
		if ( null === $set_to && 0.0 === $qty ) {
			return new WP_Error( 'uappt_consumable_zero_qty', __( '異動數量是 0，沒有東西可以記錄。', 'ultimate-appointments' ) );
		}

		$table       = UAPPT_Install::table( 'consumables' );
		$moves_table = UAPPT_Install::table( 'consumable_moves' );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, stock, unit_cost FROM {$table} WHERE id = %d FOR UPDATE", $consumable_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new WP_Error( 'uappt_consumable_missing', __( '找不到這筆耗材。', 'ultimate-appointments' ) );
		}

		// 盤點：差額要用鎖住之後的帳面算（v3.0.2）。以前是交易外先讀帳面再算差額，盤點
		// 當下剛好有預約被標完成的話，那筆扣用會被這次校正抵銷掉。
		if ( null !== $set_to ) {
			$qty = round( $set_to - (float) $row['stock'], 3 );
			if ( 0.0 === $qty ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return new WP_Error( 'uappt_consumable_no_diff', __( '盤點數量跟帳面一樣，沒有差額要校正。', 'ultimate-appointments' ) );
			}
			if ( empty( $args['note'] ) ) {
				$args['note'] = sprintf(
					/* translators: 1: 帳面數量 2: 實際盤點數量 */
					__( '盤點校正：帳面 %1$s → 實際 %2$s', 'ultimate-appointments' ),
					self::format_qty( $row['stock'] ),
					self::format_qty( $set_to )
				);
			}
		}

		$balance_after = round( (float) $row['stock'] + $qty, 3 );

		// 成本快照：用「異動當下」的單價，不是報表產出時的單價（規則 3）。
		$unit_cost  = null;
		$cost_total = null;
		if ( null !== $row['unit_cost'] ) {
			$unit_cost  = (float) $row['unit_cost'];
			$cost_total = round( $unit_cost * abs( $qty ), 2 );
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$moves_table,
			array(
				'consumable_id' => $consumable_id,
				'type'          => sanitize_key( $type ),
				'qty'           => $qty,
				'balance_after' => $balance_after,
				'booking_id'    => ! empty( $args['booking_id'] ) ? (int) $args['booking_id'] : null,
				'staff_id'      => ! empty( $args['staff_id'] ) ? (int) $args['staff_id'] : null,
				'unit_cost'     => $unit_cost,
				'cost_total'    => $cost_total,
				'note'          => isset( $args['note'] ) ? sanitize_text_field( $args['note'] ) : '',
				'created_by'    => isset( $args['created_by'] ) ? (int) $args['created_by'] : get_current_user_id(),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%f', '%f', '%d', '%d', '%f', '%f', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new WP_Error( 'uappt_move_insert_failed', __( '寫入耗材異動時發生錯誤，請稍後再試。', 'ultimate-appointments' ) );
		}

		$move_id = (int) $wpdb->insert_id;

		$wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$table,
			array(
				'stock'      => $balance_after,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $consumable_id ),
			array( '%f', '%s' ),
			array( '%d' )
		);

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $move_id;
	}

	/**
	 * 盤點校正：輸入**實際數量**，系統自己算差額寫一筆 `adjust`。
	 *
	 * ⚠️ **不要改成直接覆寫 `stock`。** 那樣「帳面 vs 實際」的落差會當場蒸發，
	 * 而那個落差正是店家做盤點想知道的東西（耗損、漏記、被拿走）。
	 *
	 * @param int    $consumable_id 耗材 ID。
	 * @param float  $actual_qty    實際盤點到的數量。
	 * @param string $note          備註。
	 * @return int|WP_Error 異動 ID；帳面與實際相同時回傳 WP_Error（沒有差額可記）。
	 */
	public static function adjust_to( $consumable_id, $actual_qty, $note = '' ) {
		// 差額在 record_move() 鎖住那一列之後才算，見 set_to 的說明。
		return self::record_move(
			$consumable_id,
			self::MOVE_ADJUST,
			0,
			array(
				'set_to' => (float) $actual_qty,
				'note'   => $note,
			)
		);
	}


	/**
	 * 依流水帳重算結存，修掉帳面與明細對不上的情況。
	 *
	 * 正常情況下不該需要——`record_move()` 是唯一入口且在交易裡做。這支是給
	 * 「直接改過資料庫」「匯入過舊資料」這類例外情況善後用的工具。
	 *
	 * @param int $consumable_id 耗材 ID；0 表示全部。
	 * @return int 修正了幾筆。
	 */
	public static function recalculate_stock( $consumable_id = 0 ) {
		global $wpdb;

		$table = UAPPT_Install::table( 'consumables' );
		$moves = UAPPT_Install::table( 'consumable_moves' );

		$targets = $consumable_id ? array( self::get( $consumable_id ) ) : self::get_all();
		$fixed   = 0;

		foreach ( array_filter( (array) $targets ) as $row ) {
			$id  = (int) $row['id'];
			$sum = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE( SUM( qty ), 0 ) FROM {$moves} WHERE consumable_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sum = round( $sum, 3 );

			if ( round( (float) $row['stock'], 3 ) === $sum ) {
				continue;
			}

			$wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$table,
				array(
					'stock'      => $sum,
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $id ),
				array( '%f', '%s' ),
				array( '%d' )
			);
			$fixed++;
		}

		return $fixed;
	}

	/**
	 * 查詢異動紀錄（分頁）。
	 *
	 * @param array $args {
	 *     @type int    $consumable_id 篩選耗材。
	 *     @type string $type          篩選類型。
	 *     @type string $date_from     起始日期 (Y-m-d)。
	 *     @type string $date_to       結束日期 (Y-m-d)。
	 *     @type int    $paged         頁碼。
	 *     @type int    $per_page      每頁筆數。
	 * }
	 * @return array ['items' => array, 'total' => int]
	 */
	public static function query_moves( $args = array() ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'consumable_moves' );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['consumable_id'] ) ) {
			$where[]  = 'consumable_id = %d';
			$params[] = (int) $args['consumable_id'];
		}
		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = sanitize_key( $args['type'] );
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 50;
		$paged     = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
		$offset    = ( $paged - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// 同一秒內寫進來的幾筆（同一次服務扣好幾種耗材）用 id 當第二排序鍵，
		// 不然順序是不定的，重新整理一次畫面上的列就跳一次。
		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
		$all = array_merge( $params, array( $per_page, $offset ) );

		$items = $wpdb->get_results( $wpdb->prepare( $sql, $all ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/* ---------------------------------------------------------------------
	 * 配方
	 * ------------------------------------------------------------------- */

	/**
	 * 取得某一個層級自己設定的配方列（不做任何繼承或合併）。
	 *
	 * @param string $scope      SCOPE_* 常數。
	 * @param int    $product_id 商品 ID（global 時為 0）。
	 * @param string $plan_key   方案鍵（只有 plan 用得到）。
	 * @return array 每個元素：['consumable_id','qty_per_service','per_unit']
	 */
	public static function get_recipe_rows( $scope, $product_id = 0, $plan_key = '' ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'consumable_recipes' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT consumable_id, qty_per_service, per_unit FROM {$table}
				WHERE scope = %s AND product_id = %d AND plan_key = %s
				ORDER BY id ASC",
				sanitize_key( $scope ),
				(int) $product_id,
				(string) $plan_key
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * 整組覆寫某一個層級的配方（先刪後寫）。
	 *
	 * 先刪後寫而不是逐列比對更新，是因為「這個方案的配方」在概念上就是一份
	 * 完整清單，使用者刪掉一列的意思就是它不該存在了；逐列 diff 只會多出
	 * 「哪些要刪」的判斷，沒有任何好處。
	 *
	 * @param string $scope      SCOPE_* 常數。
	 * @param int    $product_id 商品 ID。
	 * @param string $plan_key   方案鍵。
	 * @param array  $rows       每個元素：['consumable_id','qty_per_service','per_unit']。
	 * @return int 實際寫入幾列。
	 */
	public static function save_recipe_rows( $scope, $product_id, $plan_key, $rows ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'consumable_recipes' );

		$scope      = sanitize_key( $scope );
		$product_id = (int) $product_id;
		$plan_key   = (string) $plan_key;

		$wpdb->delete( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$table,
			array(
				'scope'      => $scope,
				'product_id' => $product_id,
				'plan_key'   => $plan_key,
			),
			array( '%s', '%d', '%s' )
		);

		$written = 0;
		$seen    = array();

		foreach ( (array) $rows as $row ) {
			$consumable_id = isset( $row['consumable_id'] ) ? absint( $row['consumable_id'] ) : 0;
			$qty           = isset( $row['qty_per_service'] ) ? round( (float) $row['qty_per_service'], 3 ) : 0.0;

			// 沒選耗材、或用量 <= 0 的列直接丟掉：表單上多出來的空白列本來就
			// 不該變成一筆「每次用 0」的配方（那只會在扣帳時被 record_move()
			// 以「數量是 0」再擋一次，白繞一圈）。
			if ( ! $consumable_id || $qty <= 0 ) {
				continue;
			}
			// 同一個耗材在同一層級只能有一列（UNIQUE 也擋，但先過濾掉比讓
			// insert 靜默失敗好——後面那樣使用者會以為存進去了）。
			if ( isset( $seen[ $consumable_id ] ) ) {
				continue;
			}
			$seen[ $consumable_id ] = true;

			$wpdb->insert( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$table,
				array(
					'scope'           => $scope,
					'product_id'      => $product_id,
					'plan_key'        => $plan_key,
					'consumable_id'   => $consumable_id,
					'qty_per_service' => $qty,
					'per_unit'        => ! empty( $row['per_unit'] ) ? 1 : 0,
				),
				array( '%s', '%d', '%s', '%d', '%f', '%d' )
			);
			$written++;
		}

		return $written;
	}

	/**
	 * 解析某一次服務實際要扣哪些耗材。
	 *
	 * 合併規則（跟服務方案的設定繼承同一套心智模型，但 global 是 union）：
	 *
	 * 1. `global`（每次服務必用）永遠加上去
	 * 2. 這個方案自己有設定就用方案的；方案沒設定才往上看商品層級
	 * 3. 同一個耗材同時出現在 global 與服務層級時，**服務層級的用量勝出**
	 *    ——店家在那個方案裡明確寫了數字，代表他知道自己在做什麼
	 *
	 * ⚠️ 配方之後被改動**不會回頭重算已經扣過的異動**，那是刻意的：跟
	 * `plan_name` 快照同一個道理，歷史要反映當時實際發生的事。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $plan_key   方案鍵。
	 * @return array consumable_id => ['qty_per_service','per_unit']
	 */
	public static function resolve_recipe( $product_id, $plan_key = '' ) {
		$merged = array();

		foreach ( self::get_recipe_rows( self::SCOPE_GLOBAL ) as $row ) {
			$merged[ (int) $row['consumable_id'] ] = array(
				'qty_per_service' => (float) $row['qty_per_service'],
				'per_unit'        => ! empty( $row['per_unit'] ),
			);
		}

		$specific = array();
		if ( '' !== (string) $plan_key ) {
			$specific = self::get_recipe_rows( self::SCOPE_PLAN, $product_id, $plan_key );
		}
		if ( ! $specific ) {
			$specific = self::get_recipe_rows( self::SCOPE_PRODUCT, $product_id );
		}

		foreach ( $specific as $row ) {
			$merged[ (int) $row['consumable_id'] ] = array(
				'qty_per_service' => (float) $row['qty_per_service'],
				'per_unit'        => ! empty( $row['per_unit'] ),
			);
		}

		return $merged;
	}

	/**
	 * 某個服務有沒有任何配方（商品編輯頁要顯示摘要用）。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $plan_key   方案鍵。
	 * @return array consumable_id => ['qty_per_service','per_unit','name','unit']
	 */
	public static function describe_recipe( $product_id, $plan_key = '' ) {
		$recipe = self::resolve_recipe( $product_id, $plan_key );
		if ( ! $recipe ) {
			return array();
		}

		$consumables = self::get_many( array_keys( $recipe ) );
		$out         = array();

		foreach ( $recipe as $id => $row ) {
			if ( ! isset( $consumables[ $id ] ) ) {
				continue;
			}
			$out[ $id ] = array_merge(
				$row,
				array(
					'name' => $consumables[ $id ]['name'],
					'unit' => $consumables[ $id ]['unit'],
				)
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * 跟預約的串接
	 * ------------------------------------------------------------------- */

	/**
	 * 這筆預約目前是「已扣用」狀態嗎？
	 *
	 * 用流水帳自己算（consume 筆數減 revert 筆數），不另外在 bookings 加旗標
	 * 欄位：流水帳本來就是事實來源，多一個旗標就多一個會跟它不同步的東西。
	 *
	 * @param int $booking_id 預約 ID。
	 * @return bool
	 */
	public static function is_consumed( $booking_id ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'consumable_moves' );

		$net = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT COALESCE( SUM( CASE WHEN type = %s THEN 1 WHEN type = %s THEN -1 ELSE 0 END ), 0 )
				FROM {$table} WHERE booking_id = %d",
				self::MOVE_CONSUME,
				self::MOVE_REVERT,
				(int) $booking_id
			)
		);

		return $net > 0;
	}

	/**
	 * 服務完成：照配方扣用耗材。
	 *
	 * 由 `UAPPT_Booking::complete()` 在狀態真的寫進去之後呼叫。**扣不到不會讓
	 * 完成失敗**——服務已經做了，因為庫存記錄的問題把「標記完成」擋下來是本末
	 * 倒置；庫存扣成負數本身就是「該去盤點了」的訊號。
	 *
	 * @param array $booking 預約紀錄（`UAPPT_Booking::get()` 的回傳值）。
	 * @return int 實際扣了幾種耗材。
	 */
	public static function consume_for_booking( $booking ) {
		// gating 的第 2 層（hook）。模組關著就不要偷偷扣庫存——店家連耗材頁面
		// 都看不到，帳卻一直在動，那是最糟的狀態。
		//
		// ⚠️ **這裡擋、revert_for_booking() 不擋，是刻意的不對稱。** 回沖是
		// 「把已經發生的事收回來」，不是使用這項功能：如果一筆預約在模組開著
		// 的時候完成（扣過帳）、之後模組被關掉、再被還原成「已確認」，回沖若
		// 也一起擋掉，流水帳上會永遠留著一筆沒有對應的扣用，而那筆預約明明
		// 已經不是完成狀態了。降級的原則是「隱藏但資料完整保留」，帳本自己
		// 對不起來就不叫完整。
		if ( ! UAPPT_Modules::enabled( 'consumables' ) ) {
			return 0;
		}

		if ( ! $booking || UAPPT_Booking::KIND_BOOKING !== $booking['kind'] ) {
			return 0;
		}

		$booking_id = (int) $booking['id'];

		// 重複扣的保險絲。complete() 本身有狀態守衛擋著，但「完成 → 還原 →
		// 完成」是真實會發生的操作序列，而且回沖之後 is_consumed() 會回 false，
		// 第二次完成要能正常再扣一次。
		if ( self::is_consumed( $booking_id ) ) {
			return 0;
		}

		$recipe = self::resolve_recipe( (int) $booking['product_id'], (string) $booking['plan_key'] );
		if ( ! $recipe ) {
			return 0;
		}

		$units = max( 1, (int) $booking['occupied_units'] );
		$done  = 0;

		foreach ( $recipe as $consumable_id => $row ) {
			// per_unit 才乘人數：拋棄式毛巾一人一條，一罐凝膠不會因為來了
			// 三個人就用掉三倍。
			$qty = $row['qty_per_service'] * ( $row['per_unit'] ? $units : 1 );
			if ( $qty <= 0 ) {
				continue;
			}

			$result = self::record_move(
				$consumable_id,
				self::MOVE_CONSUME,
				-$qty,
				array(
					'booking_id' => $booking_id,
					'staff_id'   => (int) $booking['staff_id'],
					'note'       => UAPPT_Booking::get_booking_display_name( $booking ),
				)
			);

			if ( ! is_wp_error( $result ) ) {
				$done++;
			}
		}

		return $done;
	}

	/**
	 * 還原成「已確認」：把這筆預約扣掉的耗材加回去。
	 *
	 * ⚠️ **這支跟 `consume_for_booking()` 必須成對出現。** 漏掉的話每還原一次
	 * 庫存就少一份，而且沒有任何地方會報錯——這是整個耗材功能最容易出錯、
	 * 出錯又最難發現的地方。
	 *
	 * 回沖是**寫一筆反向異動**，不是刪掉原本那筆：稽核痕跡要留著，才看得出
	 * 「這筆預約曾經被標記完成又被還原」。
	 *
	 * @param array $booking 預約紀錄。
	 * @return int 實際回沖了幾筆。
	 */
	public static function revert_for_booking( $booking ) {
		global $wpdb;

		// ⚠️ **刻意不做模組檢查**，理由見 consume_for_booking()：回沖是收拾
		// 已經發生的事，擋掉會讓流水帳永遠對不起來。沒扣過的預約本來就會被
		// 下面的 is_consumed() 擋掉，所以模組從頭到尾關著時這支也不會做事。
		if ( ! $booking ) {
			return 0;
		}

		$booking_id = (int) $booking['id'];
		if ( ! self::is_consumed( $booking_id ) ) {
			return 0;
		}

		$table = UAPPT_Install::table( 'consumable_moves' );

		// 回沖的是「這筆預約最近一次扣用」的那幾筆。用 created_at 找出最後
		// 一批 consume——完成→還原→完成→還原的序列裡，每次回沖都只該對應
		// 到它自己那一次的扣用。
		$last_consume_at = $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT MAX( created_at ) FROM {$table} WHERE booking_id = %d AND type = %s",
				$booking_id,
				self::MOVE_CONSUME
			)
		);

		if ( ! $last_consume_at ) {
			return 0;
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT consumable_id, qty FROM {$table} WHERE booking_id = %d AND type = %s AND created_at = %s",
				$booking_id,
				self::MOVE_CONSUME,
				$last_consume_at
			),
			ARRAY_A
		);

		$done = 0;
		foreach ( (array) $rows as $row ) {
			$result = self::record_move(
				(int) $row['consumable_id'],
				self::MOVE_REVERT,
				// 原本是負的（扣用），乘 -1 變成正的加回去。
				-1 * (float) $row['qty'],
				array(
					'booking_id' => $booking_id,
					'staff_id'   => (int) $booking['staff_id'],
					'note'       => __( '預約還原為已確認，耗材回沖', 'ultimate-appointments' ),
				)
			);
			if ( ! is_wp_error( $result ) ) {
				$done++;
			}
		}

		return $done;
	}

	/* ---------------------------------------------------------------------
	 * 低量提醒
	 * ------------------------------------------------------------------- */

	/**
	 * 低於警示量的耗材（只看啟用中的）。
	 *
	 * @return array
	 */
	public static function get_low_stock() {
		return self::get_all(
			array(
				'status'   => self::STATUS_ACTIVE,
				'low_only' => true,
			)
		);
	}

	/**
	 * 每天一次，把低於警示量的耗材推播給店家。
	 *
	 * 一天只發一次（用 option 記最後發送日期去重）：這支搭 5 分鐘一次的提醒
	 * 排程便車，不去重的話店家一天會收到 288 則一模一樣的訊息。
	 */
	public static function maybe_notify_low_stock() {
		$today = current_time( 'Y-m-d' );
		if ( get_option( self::LOW_STOCK_NOTICE_OPTION ) === $today ) {
			return;
		}

		// 太早發沒有意義（店還沒開），這裡沿用店家提醒的同一個發送時間設定，
		// 不另外開一個設定欄位。
		if ( current_time( 'H:i' ) < UAPPT_Reminders::get_send_time() ) {
			return;
		}

		// 先記旗標再發送：推播失敗時不要變成「每 5 分鐘重試一次」，那會在
		// LINE token 過期之類的情況下狂打外部 API。
		update_option( self::LOW_STOCK_NOTICE_OPTION, $today );

		$low = self::get_low_stock();
		if ( ! $low ) {
			return;
		}

		$lines = array( __( '【耗材低量提醒】', 'ultimate-appointments' ) );
		foreach ( $low as $row ) {
			$lines[] = sprintf(
				/* translators: 1: 耗材名稱 2: 目前數量（含單位） 3: 警示量（含單位） */
				__( '%1$s：剩 %2$s（警示量 %3$s）', 'ultimate-appointments' ),
				$row['name'],
				self::format_qty( $row['stock'], $row['unit'] ),
				self::format_qty( $row['low_stock_threshold'], $row['unit'] )
			);
		}

		$text = implode( "\n", $lines );

		foreach ( UAPPT_Line::get_shop_targets() as $target ) {
			UAPPT_Line::push_text( $target, $text );
		}
	}

	/* ---------------------------------------------------------------------
	 * 報表
	 * ------------------------------------------------------------------- */

	/**
	 * 一段期間內每一項耗材的用量、成本與進出（報表的「耗材」頁籤）。
	 *
	 * `revert`（回沖）在每一欄都要**反向抵掉** `consume`：一筆預約被標記完成
	 * 又被還原，等於沒有真的用掉，用量跟成本都不該留在報表裡。`cost_total`
	 * 存的一律是正數（`abs(qty) * unit_cost`），所以要靠 `type` 判斷方向，
	 * 不能直接 SUM。
	 *
	 * @param string $date_from 起始日期 (Y-m-d)。
	 * @param string $date_to   結束日期 (Y-m-d)。
	 * @return array consumable_id => ['used','cost','restocked','adjusted','wasted']
	 */
	public static function get_usage_stats( $date_from, $date_to ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'consumable_moves' );

		$sql = "SELECT
				consumable_id,
				SUM( CASE WHEN type = %s THEN -qty WHEN type = %s THEN -qty ELSE 0 END ) AS used,
				SUM( CASE WHEN type = %s THEN cost_total WHEN type = %s THEN -cost_total ELSE 0 END ) AS cost,
				SUM( CASE WHEN type = %s THEN qty ELSE 0 END ) AS restocked,
				SUM( CASE WHEN type = %s THEN qty ELSE 0 END ) AS adjusted,
				SUM( CASE WHEN type = %s THEN -qty ELSE 0 END ) AS wasted
			FROM {$table}
			WHERE created_at >= %s AND created_at <= %s
			GROUP BY consumable_id"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				$sql,
				// used：consume 是負的，取負號變正；revert 是正的，取負號變負，
				// 剛好把被還原的那份扣掉。
				self::MOVE_CONSUME,
				self::MOVE_REVERT,
				self::MOVE_CONSUME,
				self::MOVE_REVERT,
				self::MOVE_RESTOCK,
				self::MOVE_ADJUST,
				self::MOVE_WASTE,
				sanitize_text_field( $date_from ) . ' 00:00:00',
				sanitize_text_field( $date_to ) . ' 23:59:59'
			),
			ARRAY_A
		);

		$stats = array();
		foreach ( (array) $rows as $row ) {
			$stats[ (int) $row['consumable_id'] ] = array(
				'used'      => (float) $row['used'],
				'cost'      => (float) $row['cost'],
				'restocked' => (float) $row['restocked'],
				'adjusted'  => (float) $row['adjusted'],
				'wasted'    => (float) $row['wasted'],
			);
		}

		return $stats;
	}

	/**
	 * 材料成本，依報表的分組方式攤到每一格（毛利要用）。
	 *
	 * 跟 `get_usage_stats()` 的差別是**掛在預約上**：這裡 join 回 bookings，
	 * 用「服務發生的時間」分組，而不是「異動寫入的時間」。同一筆預約可能隔天
	 * 才被補標記完成，成本要算在服務那一天才對得起營收。手動進貨／報廢沒有
	 * `booking_id`，自然不會出現在這裡——那是庫存的事，不是某一次服務的成本。
	 *
	 * @param string $date_from 起始日期 (Y-m-d)。
	 * @param string $date_to   結束日期 (Y-m-d)。
	 * @param int    $staff_id  篩選人員；0 表示全部。
	 * @param string $group_by  'day'／'month'／'staff'／'service'／'all'。
	 * @return array gkey => 材料成本
	 */
	public static function get_cost_by_period( $date_from, $date_to, $staff_id = 0, $group_by = 'all' ) {
		global $wpdb;

		// 模組關著就不要跑這個查詢。報表的每一個頁籤都會呼叫一次，而那時候
		// 材料成本沒有意義（毛利那張卡片也已經不顯示了，見 views/reports.php）。
		// 回傳空陣列，呼叫端拿不到對應的 key 時本來就會當成 0——跟「這期間
		// 沒有耗用」在資料上是同一件事，差別只在畫面上不會把那個 0 印出來。
		if ( ! UAPPT_Modules::enabled( 'consumables' ) ) {
			return array();
		}

		$moves    = UAPPT_Install::table( 'consumable_moves' );
		$bookings = UAPPT_Install::table( 'bookings' );

		// 跟 UAPPT_Booking::get_report_stats() 一樣是寫死的對照表，不接使用者
		// 的字串進 SQL。
		//
		// ⚠️ **這份鍵名必須跟 UAPPT_Booking::get_report_stats() 的那一份對齊**，
		// 而且分組運算式要算出**一模一樣的鍵**——報表是拿業績的分組鍵去查這裡
		// 的結果（`$costs[ $key ]`），對不上就查不到，而查不到在 PHP 裡不是
		// 錯誤，是 0。畫面上會看到「材料成本 0、毛利＝業績」這種看起來完全
		// 正常、實際上是假的一整欄。
		//
		// v2.58.1 就是在補兩個漏掉的鍵造成的這種安靜錯誤：
		// - `week`：營收頁切到「每週」時，13 列的材料成本全部是 0（每日與
		//   每月是對的），所以同一段期間換個顆粒看，毛利會多出六萬多。
		// - `staff_service`：「人員 × 項目」整張表的材料成本是 0，跟它正上方
		//   的人員總表（16,411）自相矛盾。
		$group_sql = array(
			'day'     => 'DATE( b.service_start )',
			// 週的鍵要跟 get_report_stats() 完全相同：那邊用「那一週的星期一」，
			// 不是 YEARWEEK()。兩邊算法只要差一點，鍵就對不起來。
			'week'    => 'DATE_SUB( DATE( b.service_start ), INTERVAL WEEKDAY( b.service_start ) DAY )',
			'month'   => "DATE_FORMAT( b.service_start, '%%Y-%%m' )",
			'staff'   => 'b.staff_id',
			'service' => "CONCAT( b.product_id, ':', COALESCE( b.plan_key, '' ) )",
			// 分隔符號用 `|`，理由同 get_report_stats()：plan_key 不含 `|`。
			'staff_service' => "CONCAT( b.staff_id, '|', b.product_id, ':', COALESCE( b.plan_key, '' ) )",
			'all'     => '0',
		);
		// 認不得的鍵會落回 'all'，而那在呼叫端看起來就是「成本全部是 0」。
		// 開發時直接吵出來，不要等到有人盯著報表覺得哪裡怪怪的才發現。
		if ( ! isset( $group_sql[ $group_by ] ) && 'all' !== $group_by ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: 分組鍵名稱 */
					esc_html__( '不認得的分組鍵「%s」，材料成本會全部算成 0。請確認它同時存在於 UAPPT_Consumable::get_cost_by_period() 與 UAPPT_Booking::get_report_stats() 的對照表裡。', 'ultimate-appointments' ),
					esc_html( (string) $group_by )
				),
				'2.58.1'
			);
		}

		$gkey = isset( $group_sql[ $group_by ] ) ? $group_sql[ $group_by ] : $group_sql['all'];

		$where  = array( 'b.service_start >= %s', 'b.service_start <= %s' );
		$params = array(
			sanitize_text_field( $date_from ) . ' 00:00:00',
			sanitize_text_field( $date_to ) . ' 23:59:59',
		);
		if ( $staff_id ) {
			$where[]  = 'b.staff_id = %d';
			$params[] = (int) $staff_id;
		}
		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT {$gkey} AS gkey,
				SUM( CASE WHEN m.type = %s THEN m.cost_total WHEN m.type = %s THEN -m.cost_total ELSE 0 END ) AS cost
			FROM {$moves} m
			INNER JOIN {$bookings} b ON b.id = m.booking_id
			WHERE {$where_sql}
			GROUP BY gkey"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( $sql, array_merge( array( self::MOVE_CONSUME, self::MOVE_REVERT ), $params ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['gkey'] ] = (float) $row['cost'];
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * 顯示工具
	 * ------------------------------------------------------------------- */

	/**
	 * 把數量印成人看得懂的樣子。
	 *
	 * DECIMAL(12,3) 從資料庫回來一律是 "1.000" 這種字串，直接印在畫面上
	 * 「毛巾剩 12.000 條」很難讀。整數就不印小數點，有小數才印，並去掉尾端
	 * 多餘的 0（0.500 → 0.5）。
	 *
	 * @param float|string $qty  數量。
	 * @param string       $unit 單位；給了就接在數字後面。
	 * @return string
	 */
	public static function format_qty( $qty, $unit = '' ) {
		$qty  = (float) $qty;
		$text = ( floor( $qty ) === $qty )
			? number_format( $qty, 0 )
			: rtrim( rtrim( number_format( $qty, 3 ), '0' ), '.' );

		return '' !== $unit ? $text . ' ' . $unit : $text;
	}

	/**
	 * 異動類型的中文標籤。
	 *
	 * @param string $type MOVE_* 常數。
	 * @return string
	 */
	public static function move_type_label( $type ) {
		$labels = array(
			self::MOVE_CONSUME => __( '服務扣用', 'ultimate-appointments' ),
			self::MOVE_RESTOCK => __( '進貨', 'ultimate-appointments' ),
			self::MOVE_ADJUST  => __( '盤點校正', 'ultimate-appointments' ),
			self::MOVE_WASTE   => __( '報廢', 'ultimate-appointments' ),
			self::MOVE_REVERT  => __( '回沖', 'ultimate-appointments' ),
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * 全部異動類型，供篩選下拉使用。
	 *
	 * @return array type => label
	 */
	public static function move_types() {
		return array(
			self::MOVE_CONSUME => self::move_type_label( self::MOVE_CONSUME ),
			self::MOVE_RESTOCK => self::move_type_label( self::MOVE_RESTOCK ),
			self::MOVE_ADJUST  => self::move_type_label( self::MOVE_ADJUST ),
			self::MOVE_WASTE   => self::move_type_label( self::MOVE_WASTE ),
			self::MOVE_REVERT  => self::move_type_label( self::MOVE_REVERT ),
		);
	}
}
