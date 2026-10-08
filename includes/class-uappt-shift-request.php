<?php
/**
 * 排班申請：員工預先排班／請假／登記時段佔用，主管審核通過後才生效。
 *
 * 員工不可能自己關掉自己的可預約時段——這支類別的每一筆資料在核准之前都只是
 *「申請」，完全不影響日曆或任何可預約時段；只有 approve() 真的成功寫入
 * staff_overrides 或 bookings 之後，才會反映到客人看得到的時段上。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Shift_Request {

	const TYPE_HOURS = 'hours';
	const TYPE_LEAVE = 'leave';
	const TYPE_BLOCK = 'block';

	const STATUS_PENDING   = 'pending';
	const STATUS_APPROVED  = 'approved';
	const STATUS_REJECTED  = 'rejected';
	const STATUS_WITHDRAWN = 'withdrawn';

	/**
	 * 一次送出最多幾天。排班的實務單位是一週／一個月，92 天（約一季）已經
	 * 遠遠超過，上限存在的目的只是避免一個手滑的日期區間生出整年份的資料列，
	 * 讓審核頁跟衝突檢查被拖垮。
	 */
	const MAX_BATCH_DAYS = 92;

	/**
	 * 建立一筆申請（一律是 pending，不會直接生效）。
	 *
	 * @param array $data {
	 *     @type int    $staff_id     人員 ID。
	 *     @type string $type         hours｜leave｜block。
	 *     @type string $request_date 日期 (Y-m-d)，不可為過去。
	 *     @type array  $hours        type=hours 時必填，[[開始,結束], ...]。
	 *     @type string $start_hm     type=block 時必填，開始時間 H:i。
	 *     @type string $end_hm       type=block 時必填，結束時間 H:i。
	 *     @type int    $units        type=block 時可選，要佔用幾個名額；0 或省略＝佔滿。
	 *     @type string $staff_note   員工備註。
	 *     @type int    $created_by   建立者 user ID。
	 *     @type string $batch_key    同一次送出的多天共用的隨機鍵；單日申請留空。
	 * }
	 * @return int|WP_Error 申請 ID。
	 */
	public static function create( $data ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'shift_requests' );

		$staff_id = isset( $data['staff_id'] ) ? (int) $data['staff_id'] : 0;
		$type     = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : '';
		$date     = isset( $data['request_date'] ) ? sanitize_text_field( $data['request_date'] ) : '';

		if ( ! in_array( $type, array( self::TYPE_HOURS, self::TYPE_LEAVE, self::TYPE_BLOCK ), true ) ) {
			return new WP_Error( 'uappt_invalid_shift_request', __( '申請類型不正確。', 'ultimate-appointments' ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'uappt_invalid_shift_request', __( '日期格式不正確。', 'ultimate-appointments' ) );
		}
		if ( $date < current_time( 'Y-m-d' ) ) {
			return new WP_Error( 'uappt_invalid_shift_request', __( '不能替過去的日期申請。', 'ultimate-appointments' ) );
		}

		$staff = UAPPT_Staff::get( $staff_id );
		if ( ! $staff || 'active' !== $staff['status'] ) {
			return new WP_Error( 'uappt_invalid_staff', __( '找不到這位人員，或這位人員已停用。', 'ultimate-appointments' ) );
		}

		// 同一天同類型只能有一筆 pending，避免審核者看到一堆重複、互相矛盾的待審申請。
		// 已核准／已駁回／已撤回的歷史不受影響，可以無限累積。
		if ( self::has_pending( $staff_id, $date, $type ) ) {
			return new WP_Error(
				'uappt_duplicate_pending',
				__( '這一天已經有一筆同類型的申請正在等待審核，請先撤回舊的申請再重新送出。', 'ultimate-appointments' )
			);
		}

		$hours_json = null;
		$start_hm   = null;
		$end_hm     = null;
		$units      = null;

		if ( self::TYPE_HOURS === $type ) {
			$errors = array();
			$clean  = UAPPT_Staff::sanitize_ranges(
				isset( $data['hours'] ) && is_array( $data['hours'] ) ? $data['hours'] : array(),
				$date,
				$errors
			);
			if ( $errors ) {
				return new WP_Error( 'uappt_invalid_hours', implode( ' ', $errors ) );
			}
			if ( empty( $clean ) ) {
				return new WP_Error( 'uappt_invalid_hours', __( '請至少填寫一段時段。', 'ultimate-appointments' ) );
			}
			$hours_json = wp_json_encode( $clean );
		} elseif ( self::TYPE_BLOCK === $type ) {
			$start_hm = isset( $data['start_hm'] ) ? sanitize_text_field( $data['start_hm'] ) : '';
			$end_hm   = isset( $data['end_hm'] ) ? sanitize_text_field( $data['end_hm'] ) : '';
			if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $start_hm ) || ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $end_hm ) ) {
				return new WP_Error( 'uappt_invalid_block_time', __( '時間格式不正確，請輸入類似 09:30 的格式。', 'ultimate-appointments' ) );
			}
			if ( $start_hm === $end_hm ) {
				return new WP_Error( 'uappt_invalid_block_time', __( '開始與結束時間不可相同。', 'ultimate-appointments' ) );
			}
			$units = isset( $data['units'] ) ? max( 0, (int) $data['units'] ) : 0;
		}
		// type=leave 不需要額外欄位——整天休假本身就是完整的語意。

		$staff_note = isset( $data['staff_note'] ) ? sanitize_text_field( $data['staff_note'] ) : '';
		$created_by = isset( $data['created_by'] ) ? (int) $data['created_by'] : 0;
		$batch_key  = isset( $data['batch_key'] ) ? sanitize_text_field( $data['batch_key'] ) : '';
		$now        = current_time( 'mysql' );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$table,
			array(
				'staff_id'     => $staff_id,
				'type'         => $type,
				'request_date' => $date,
				'hours'        => $hours_json,
				'start_hm'     => $start_hm,
				'end_hm'       => $end_hm,
				'units'        => $units,
				'staff_note'   => $staff_note,
				'batch_key'    => '' !== $batch_key ? $batch_key : null,
				'status'       => self::STATUS_PENDING,
				'created_by'   => $created_by ? $created_by : null,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		// 寫入失敗時 insert_id 還是上一筆 insert 的值（可能是別張表的），不能直接回傳。
		if ( ! $inserted ) {
			return new WP_Error( 'uappt_insert_failed', __( '送出申請時發生錯誤，請稍後再試。', 'ultimate-appointments' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * 一次送出多天：員工在月曆上勾選好日期後，這些天共用同一個 batch_key，
	 * 前後台就能把它們當成「一批」呈現與一鍵審核。
	 *
	 * ⚠️ **驗證規則一條都不重寫。** 逐日呼叫既有的 create()，過去日期、人員是否
	 * 停用、同日同類型只能有一筆 pending、時段格式容錯全部沿用那一支；這裡只
	 * 負責「把哪幾天送進去」跟「怎麼回報」。
	 *
	 * ⚠️ **單日失敗不中斷整批。** 某一天已經有待審申請就略過那一天並記進
	 * `skipped`，不是整批退回——員工在月曆上框了一個月，不該因為其中一天重複
	 * 就得重來。這跟 UAPPT_Admin::handle_bulk_booking_action()「呼叫既有單筆方法、
	 * 統計成功筆數」是同一個精神。一天都沒建立成功時才回傳 WP_Error。
	 *
	 * @param array $data 同 create()，但用 `dates`（Y-m-d 陣列）取代 `request_date`。
	 * @return array|WP_Error ['batch_key'=>string, 'created'=>int, 'skipped'=>[date=>訊息]]
	 */
	public static function create_batch( $data ) {
		$dates = isset( $data['dates'] ) && is_array( $data['dates'] ) ? $data['dates'] : array();
		$dates = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $dates ) ) ) );
		sort( $dates );

		if ( empty( $dates ) ) {
			return new WP_Error( 'uappt_no_dates', __( '請先在月曆上選取至少一天。', 'ultimate-appointments' ) );
		}
		if ( count( $dates ) > self::MAX_BATCH_DAYS ) {
			return new WP_Error(
				'uappt_too_many_dates',
				sprintf(
					/* translators: %d: 一次可送出的天數上限 */
					__( '一次最多只能送出 %d 天，請分次申請。', 'ultimate-appointments' ),
					self::MAX_BATCH_DAYS
				)
			);
		}

		// 沿用既有的隨機鍵產生器（服務方案的 plan_key 也是用這一支），不另外
		// 寫一份 UUID 邏輯。
		$batch_key = UAPPT_Product::generate_plan_key();

		$created = 0;
		$skipped = array();

		foreach ( $dates as $date ) {
			$result = self::create(
				array_merge(
					$data,
					array(
						'request_date' => $date,
						'batch_key'    => $batch_key,
					)
				)
			);

			if ( is_wp_error( $result ) ) {
				$skipped[ $date ] = $result->get_error_message();
				continue;
			}
			$created++;
		}

		if ( 0 === $created ) {
			// 一天都沒成功：直接把第一個錯誤原因回給員工，不要留下一個空批次。
			return new WP_Error( 'uappt_batch_failed', reset( $skipped ) );
		}

		return array(
			'batch_key' => $batch_key,
			'created'   => $created,
			'skipped'   => $skipped,
		);
	}

	/**
	 * 取得單筆申請。
	 *
	 * @param int $id 申請 ID。
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'shift_requests' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * 列表查詢。
	 *
	 * @param array $filters staff_id, status, request_date, date_from, date_to,
	 *                       exclude_id, order_by_date_asc, limit.
	 * @return array
	 */
	public static function query( $filters = array() ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'shift_requests' );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['staff_id'] ) ) {
			$where[]  = 'staff_id = %d';
			$params[] = (int) $filters['staff_id'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_text_field( $filters['status'] );
		}
		if ( ! empty( $filters['request_date'] ) ) {
			$where[]  = 'request_date = %s';
			$params[] = sanitize_text_field( $filters['request_date'] );
		}
		// 月曆檢視要用：一次撈某個月份範圍內的申請，不用逐日各查一次。跟
		// request_date（精確比對單一天）互斥，通常只會用其中一種。
		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'request_date >= %s';
			$params[] = sanitize_text_field( $filters['date_from'] );
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'request_date <= %s';
			$params[] = sanitize_text_field( $filters['date_to'] );
		}
		if ( ! empty( $filters['exclude_id'] ) ) {
			$where[]  = 'id != %d';
			$params[] = (int) $filters['exclude_id'];
		}

		$order_sql = ! empty( $filters['order_by_date_asc'] ) ? 'ORDER BY request_date ASC, id ASC' : 'ORDER BY created_at DESC';
		$limit     = ! empty( $filters['limit'] ) ? max( 1, (int) $filters['limit'] ) : 200;

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT * FROM {$table} WHERE {$where_sql} {$order_sql} LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params[]  = $limit;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 把 query() 的結果照 batch_key 分組成「一批一列」。
	 *
	 * 純 PHP 分組，**不寫第二套 SQL**——批次需要的一切（人員、類型、日期範圍、
	 * 狀態分佈）都能從成員列推導，多一份 GROUP BY 查詢只是多一個要跟 query()
	 * 保持同步的地方。
	 *
	 * `batch_key` 是 NULL 的舊資料與單日申請也一樣會變成一批（key 用
	 * `single-{id}`），呼叫端因此永遠只要處理「批」這一種形狀，view 不用分岔。
	 *
	 * @param array $filters 同 query()。
	 * @return array 每個元素：
	 *               ['key','is_single','staff_id','type','staff_note','rows',
	 *                'dates','date_from','date_to','status_counts','created_at']
	 */
	public static function query_batches( $filters = array() ) {
		$rows = self::query( $filters );

		$batches = array();
		foreach ( $rows as $row ) {
			$key = ! empty( $row['batch_key'] ) ? $row['batch_key'] : 'single-' . $row['id'];

			if ( ! isset( $batches[ $key ] ) ) {
				$batches[ $key ] = array(
					'key'           => $key,
					'is_single'     => empty( $row['batch_key'] ),
					'staff_id'      => (int) $row['staff_id'],
					'type'          => $row['type'],
					'staff_note'    => $row['staff_note'],
					'created_at'    => $row['created_at'],
					'rows'          => array(),
					'dates'         => array(),
					'status_counts' => array(),
				);
			}

			$batches[ $key ]['rows'][]  = $row;
			$batches[ $key ]['dates'][] = $row['request_date'];

			$status = $row['status'];
			$batches[ $key ]['status_counts'][ $status ] = isset( $batches[ $key ]['status_counts'][ $status ] )
				? $batches[ $key ]['status_counts'][ $status ] + 1
				: 1;
		}

		foreach ( $batches as &$batch ) {
			sort( $batch['dates'] );
			$batch['date_from'] = reset( $batch['dates'] );
			$batch['date_to']   = end( $batch['dates'] );
			usort(
				$batch['rows'],
				static function ( $a, $b ) {
					return strcmp( $a['request_date'], $b['request_date'] );
				}
			);
		}
		unset( $batch );

		return array_values( $batches );
	}

	/**
	 * 核准一整批。
	 *
	 * ⚠️ **不包外層交易。** approve() 底下的 create_block() 自己會開交易，MySQL
	 * 不支援巢狀交易；批次核准是「依序執行 N 次 approve()」，外層再包一層會被
	 * 第一筆的 COMMIT 一起提交掉。這條紀律在批次情境下比單筆更要緊。
	 *
	 * ⚠️ **允許部分成功。** 某一天跟既有預約硬衝突時，只有那一天維持 pending，
	 * 其餘照樣生效——一個月的班表不該因為其中一天有客人就整批卡住。呼叫端要
	 * 老實把 ok／failed 兩個數字都講出來，讓主管知道還有幾天要處理。
	 *
	 * @param string $batch_key   批次鍵（或 query_batches() 給的 'single-{id}'）。
	 * @param int    $reviewer_id 審核者 user ID。
	 * @param string $review_note 審核備註。
	 * @return array ['ok'=>int, 'failed'=>[request_id=>錯誤訊息]]
	 */
	public static function approve_batch( $batch_key, $reviewer_id, $review_note = '' ) {
		return self::review_batch( $batch_key, 'approve', $reviewer_id, $review_note );
	}

	/**
	 * 駁回一整批。形狀同 approve_batch()。
	 *
	 * @param string $batch_key   批次鍵。
	 * @param int    $reviewer_id 審核者 user ID。
	 * @param string $review_note 駁回原因。
	 * @return array ['ok'=>int, 'failed'=>[request_id=>錯誤訊息]]
	 */
	public static function reject_batch( $batch_key, $reviewer_id, $review_note = '' ) {
		return self::review_batch( $batch_key, 'reject', $reviewer_id, $review_note );
	}

	/**
	 * 員工整批撤回自己還在等待審核的申請。
	 *
	 * @param string $batch_key 批次鍵。
	 * @param int    $staff_id  目前登入者對應的人員 ID（擁有權檢查交給 withdraw()）。
	 * @return array ['ok'=>int, 'failed'=>[request_id=>錯誤訊息]]
	 */
	public static function withdraw_batch( $batch_key, $staff_id ) {
		$result = array(
			'ok'     => 0,
			'failed' => array(),
		);

		foreach ( self::get_pending_rows_in_batch( $batch_key ) as $row ) {
			$outcome = self::withdraw( $row['id'], $staff_id );
			if ( is_wp_error( $outcome ) ) {
				$result['failed'][ $row['id'] ] = $outcome->get_error_message();
				continue;
			}
			$result['ok']++;
		}

		return $result;
	}

	/**
	 * approve_batch()／reject_batch() 的共同骨架：撈出這批還在等待審核的列，
	 * 逐筆呼叫既有的單筆方法，統計成功與失敗。單筆方法本身就是安全的（狀態
	 * 不對、有硬衝突都會回 WP_Error 而不動資料），這裡不重複判斷。
	 *
	 * @param string $batch_key   批次鍵。
	 * @param string $op          'approve' 或 'reject'。
	 * @param int    $reviewer_id 審核者 user ID。
	 * @param string $review_note 審核備註。
	 * @return array ['ok'=>int, 'failed'=>[request_id=>錯誤訊息]]
	 */
	protected static function review_batch( $batch_key, $op, $reviewer_id, $review_note ) {
		$result = array(
			'ok'     => 0,
			'failed' => array(),
		);

		$rows = self::get_pending_rows_in_batch( $batch_key );
		if ( empty( $rows ) ) {
			return $result;
		}

		// 整批只寄一封通知信，不是每天一封——一次核准 30 天會塞爆員工信箱。
		// 逐筆呼叫時把通知關掉，最後自己組一封總結。
		foreach ( $rows as $row ) {
			$outcome = ( 'approve' === $op )
				? self::approve( $row['id'], $reviewer_id, $review_note, false )
				: self::reject( $row['id'], $reviewer_id, $review_note, false );

			if ( is_wp_error( $outcome ) ) {
				$result['failed'][ $row['id'] ] = $outcome->get_error_message();
				continue;
			}
			$result['ok']++;
		}

		if ( $result['ok'] > 0 ) {
			$first   = reset( $rows );
			$approved = ( 'approve' === $op );
			self::notify_staff_batch(
				(int) $first['staff_id'],
				$approved ? __( '排班申請已核准', 'ultimate-appointments' ) : __( '排班申請已駁回', 'ultimate-appointments' ),
				sprintf(
					/* translators: 1: 類型 2: 天數 3: 動作結果 4: 備註（可能為空） */
					__( "你送出的「%1\$s」共 %2\$d 天%3\$s。%4\$s", 'ultimate-appointments' ),
					UAPPT_Admin::shift_request_type_label( $first['type'] ),
					$result['ok'],
					$approved ? __( '已經核准，班表已經生效', 'ultimate-appointments' ) : __( '已經被駁回', 'ultimate-appointments' ),
					trim( (string) $review_note ) ? "\n\n" . __( '審核備註：', 'ultimate-appointments' ) . $review_note : ''
				)
				. ( $result['failed']
					? "\n\n" . sprintf(
						/* translators: %d: 仍在等待審核的天數 */
						__( '另有 %d 天因為與既有預約衝突，仍在等待審核。', 'ultimate-appointments' ),
						count( $result['failed'] )
					)
					: '' )
			);
		}

		return $result;
	}

	/**
	 * 一次處理一堆**不同批、不同人**的申請（全店月排班表用，v2.96.0）。
	 *
	 * review_batch() 處理的是「同一個人同一次送出的一批」，這裡是主管在全店表上
	 * 東選一格、西選一格，一次存檔裡可能有五個人各幾天。骨架一樣：逐筆呼叫既有的
	 * approve()／reject()（狀態不對、硬衝突都由它們擋），**通知信每個人一封總結**，
	 * 不是每天一封。
	 *
	 * @param array  $decisions   申請 ID => 'approve'｜'reject'。
	 * @param int    $reviewer_id 審核者 user ID。
	 * @return array ['approved'=>int, 'rejected'=>int, 'failed'=>[申請 ID=>錯誤訊息]]
	 */
	public static function review_many( array $decisions, $reviewer_id ) {
		$result    = array(
			'approved' => 0,
			'rejected' => 0,
			'failed'   => array(),
		);
		$per_staff = array();

		foreach ( $decisions as $id => $decision ) {
			$row = self::get( (int) $id );
			if ( ! $row || self::STATUS_PENDING !== $row['status'] ) {
				continue; // 已經被別人處理掉了（或員工剛撤回）：不算失敗，也不寄信。
			}

			$approve = ( 'approve' === $decision );
			$outcome = $approve
				? self::approve( $row['id'], $reviewer_id, '', false )
				: self::reject( $row['id'], $reviewer_id, '', false );

			$staff_id = (int) $row['staff_id'];
			if ( ! isset( $per_staff[ $staff_id ] ) ) {
				$per_staff[ $staff_id ] = array(
					'approved' => array(),
					'rejected' => array(),
					'failed'   => array(),
				);
			}

			if ( is_wp_error( $outcome ) ) {
				$result['failed'][ $row['id'] ]     = $outcome->get_error_message();
				$per_staff[ $staff_id ]['failed'][] = $row;
				continue;
			}

			$key = $approve ? 'approved' : 'rejected';
			$result[ $key ]++;
			$per_staff[ $staff_id ][ $key ][] = $row;
		}

		foreach ( $per_staff as $staff_id => $groups ) {
			if ( empty( $groups['approved'] ) && empty( $groups['rejected'] ) ) {
				continue; // 全部都卡在衝突：什麼都沒變，不用通知。
			}
			self::notify_staff_batch( $staff_id, __( '排班申請處理結果', 'ultimate-appointments' ), self::summary_body( $groups ) );
		}

		return $result;
	}

	/**
	 * review_many() 寄給員工的總結內文：核准了哪幾天、沒核准哪幾天、哪幾天還在等。
	 *
	 * 一天一行，寫出星期幾與申請內容——員工一次申請了整個月，只說「核准 12 天」
	 * 他不會知道是哪 12 天。
	 *
	 * @param array $groups ['approved'=>rows, 'rejected'=>rows, 'failed'=>rows]
	 * @return string
	 */
	protected static function summary_body( array $groups ) {
		$line = static function ( $row ) {
			$ts   = strtotime( $row['request_date'] . ' 12:00:00' );
			$what = UAPPT_Admin::shift_request_type_label( $row['type'] );
			if ( self::TYPE_HOURS === $row['type'] ) {
				// 「自訂時段」三個字員工看了不知道是哪一筆，印實際的時間。
				$ranges = json_decode( (string) $row['hours'], true );
				$what   = is_array( $ranges ) && $ranges ? UAPPT_Shift_Preset::format_ranges( $ranges ) : $what;
			}
			return '・' . wp_date( 'n/j（D）', $ts ) . ' ' . $what;
		};

		$parts = array( __( '你送出的排班申請已經處理了：', 'ultimate-appointments' ) );

		if ( $groups['approved'] ) {
			$parts[] = __( '已核准（班表已經生效）：', 'ultimate-appointments' ) . "\n" . implode( "\n", array_map( $line, $groups['approved'] ) );
		}
		if ( $groups['rejected'] ) {
			$parts[] = __( '沒有核准：', 'ultimate-appointments' ) . "\n" . implode( "\n", array_map( $line, $groups['rejected'] ) );
		}
		if ( $groups['failed'] ) {
			$parts[] = __( '下面幾天因為會影響已經成立的預約，暫時無法核准，仍在等待審核：', 'ultimate-appointments' ) . "\n" . implode( "\n", array_map( $line, $groups['failed'] ) );
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * 撈出一批之中還在等待審核的列。`single-{id}` 這種 key（舊資料／單日申請，
	 * batch_key 是 NULL）也要能用，所以特別處理。
	 *
	 * @param string $batch_key 批次鍵。
	 * @return array
	 */
	protected static function get_pending_rows_in_batch( $batch_key ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'shift_requests' );

		$batch_key = sanitize_text_field( $batch_key );

		if ( 0 === strpos( $batch_key, 'single-' ) ) {
			$row = self::get( (int) substr( $batch_key, 7 ) );
			return ( $row && self::STATUS_PENDING === $row['status'] ) ? array( $row ) : array();
		}

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE batch_key = %s AND status = %s ORDER BY request_date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$batch_key,
				self::STATUS_PENDING
			),
			ARRAY_A
		);
	}

	/**
	 * 待審**批次**數。徽章與「今日營運」卡片用這個，不是待審的列數：一位員工在
	 * 月曆上排一整個月會產生 30 列，用列數當徽章會顯示「30」，讓人以為有 30 件事
	 * 要處理，其實只有一件。（只數列數的 get_pending_count() 在 v3.0.3 刪掉了。）
	 *
	 * `batch_key` 是 NULL 的舊資料／單日申請各自算一批（`COUNT(DISTINCT ...)`
	 * 會把所有 NULL 併成 0 筆，所以用 `IFNULL(batch_key, id)` 讓它們各自成組）。
	 *
	 * @return int
	 */
	public static function get_pending_batch_count() {
		global $wpdb;
		$table = UAPPT_Install::table( 'shift_requests' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT IFNULL(batch_key, CONCAT('single-', id))) FROM {$table} WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::STATUS_PENDING
			)
		);
	}

	/**
	 * 員工撤回自己還在等待審核的申請。
	 *
	 * @param int $id       申請 ID。
	 * @param int $staff_id 目前登入者對應的人員 ID——擁有權檢查用，不能撤回別人的申請。
	 * @return true|WP_Error
	 */
	public static function withdraw( $id, $staff_id ) {
		$request = self::get( $id );
		if ( ! $request || (int) $request['staff_id'] !== (int) $staff_id ) {
			return new WP_Error( 'uappt_not_found', __( '找不到這筆申請。', 'ultimate-appointments' ) );
		}
		$claimed = self::claim(
			$id,
			array(
				'status'     => self::STATUS_WITHDRAWN,
				'updated_at' => current_time( 'mysql' ),
			)
		);
		if ( ! $claimed ) {
			return new WP_Error( 'uappt_not_pending', __( '只能撤回還在等待審核的申請。', 'ultimate-appointments' ) );
		}

		return true;
	}

	/**
	 * 駁回申請。
	 *
	 * @param int    $id          申請 ID。
	 * @param int    $reviewer_id 審核者 user ID。
	 * @param string $review_note 審核備註。
	 * @param bool   $notify      是否寄單筆通知信。批次審核時傳 false，改由
	 *                            review_batch() 最後寄一封總結（一次駁回 30 天
	 *                            寄 30 封信只是騷擾）。
	 * @return true|WP_Error
	 */
	public static function reject( $id, $reviewer_id, $review_note = '', $notify = true ) {
		$request = self::get( $id );
		$claimed = $request && self::claim(
			$id,
			array(
				'status'      => self::STATUS_REJECTED,
				'reviewed_by' => (int) $reviewer_id,
				'reviewed_at' => current_time( 'mysql' ),
				'review_note' => sanitize_text_field( $review_note ),
				'updated_at'  => current_time( 'mysql' ),
			)
		);
		if ( ! $claimed ) {
			return new WP_Error( 'uappt_not_pending', __( '這筆申請已經處理過了。', 'ultimate-appointments' ) );
		}

		if ( ! $notify ) {
			return true;
		}

		self::notify_staff(
			$request,
			__( '排班申請已駁回', 'ultimate-appointments' ),
			sprintf(
				/* translators: 1: 日期 2: 申請類型 3: 駁回原因（可能為空） */
				__( "你在 %1\$s 申請的「%2\$s」已經被駁回。%3\$s", 'ultimate-appointments' ),
				$request['request_date'],
				UAPPT_Admin::shift_request_type_label( $request['type'] ),
				trim( (string) $review_note ) ? "\n\n" . __( '駁回原因：', 'ultimate-appointments' ) . $review_note : ''
			)
		);

		return true;
	}

	/**
	 * 核准申請：先重新跑一次衝突檢查（真正的防線，畫面上看到的那次只是給
	 * 審核者參考，這裡才是最後把關），沒有硬擋才真的寫入 staff_overrides
	 * 或 bookings。任何一步失敗都維持 pending、不寫任何狀態，讓審核者可以
	 * 看著錯誤訊息修正後重試。
	 *
	 * ⚠️ 這裡不包一個外層交易——apply() 底下呼叫的 UAPPT_Booking::create_block()
	 * 自己會開交易，MySQL 不支援巢狀交易，外層包了反而會被它的 COMMIT 一起
	 * 提交掉。依序執行、只有成功才寫狀態，就足夠了。
	 *
	 * @param int    $id          申請 ID。
	 * @param int    $reviewer_id 審核者 user ID。
	 * @param string $review_note 審核備註。
	 * @param bool   $notify      是否寄單筆通知信。批次審核時傳 false，改由
	 *                            review_batch() 最後寄一封總結。
	 * @return true|WP_Error
	 */
	public static function approve( $id, $reviewer_id, $review_note = '', $notify = true ) {
		global $wpdb;

		$request = self::get( $id );
		if ( ! $request ) {
			return new WP_Error( 'uappt_not_found', __( '找不到這筆申請。', 'ultimate-appointments' ) );
		}
		if ( self::STATUS_PENDING !== $request['status'] ) {
			return new WP_Error( 'uappt_not_pending', __( '這筆申請已經處理過了。', 'ultimate-appointments' ) );
		}

		$conflicts = self::find_conflicts( $request );
		if ( ! empty( $conflicts['hard'] ) ) {
			return new WP_Error(
				'uappt_shift_request_conflict',
				__( '這天已經有預約會被這筆申請影響到，請先改期、換人或取消那些預約，才能核准。', 'ultimate-appointments' )
			);
		}

		// 先認領、再套用（v3.0.1）：以前是套用完才改狀態，兩位主管同時按核准時兩邊
		// 都讀到 pending，時段佔用類的申請會被建兩筆。
		$claimed = self::claim(
			$id,
			array(
				'status'      => self::STATUS_APPROVED,
				'reviewed_by' => (int) $reviewer_id,
				'reviewed_at' => current_time( 'mysql' ),
				'review_note' => sanitize_text_field( $review_note ),
				'updated_at'  => current_time( 'mysql' ),
			)
		);
		if ( ! $claimed ) {
			return new WP_Error( 'uappt_not_pending', __( '這筆申請已經處理過了。', 'ultimate-appointments' ) );
		}

		$applied_ref = self::apply( $request );
		if ( is_wp_error( $applied_ref ) ) {
			// 套用失敗：把認領退回去，申請照樣留在待審，主管看得到還有一筆沒處理。
			$wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				UAPPT_Install::table( 'shift_requests' ),
				array(
					'status'      => self::STATUS_PENDING,
					'reviewed_by' => null,
					'reviewed_at' => null,
					'review_note' => null,
					'updated_at'  => current_time( 'mysql' ),
				),
				array( 'id' => (int) $id ),
				array( '%s', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return $applied_ref;
		}

		if ( $applied_ref ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				UAPPT_Install::table( 'shift_requests' ),
				array( 'applied_ref' => (int) $applied_ref ),
				array( 'id' => (int) $id ),
				array( '%d' ),
				array( '%d' )
			);
		}

		if ( ! $notify ) {
			return true;
		}

		self::notify_staff(
			$request,
			__( '排班申請已核准', 'ultimate-appointments' ),
			sprintf(
				/* translators: 1: 日期 2: 申請類型 3: 審核備註（可能為空） */
				__( "你在 %1\$s 申請的「%2\$s」已經核准，班表已經生效。%3\$s", 'ultimate-appointments' ),
				$request['request_date'],
				UAPPT_Admin::shift_request_type_label( $request['type'] ),
				trim( (string) $review_note ) ? "\n\n" . __( '審核備註：', 'ultimate-appointments' ) . $review_note : ''
			)
		);

		return true;
	}

	/**
	 * 核准後實際生效的動作：hours/leave 寫 staff_overrides，block 建立時段佔用。
	 * 三種都刻意標記 source='shift_request' + request_id，讓 override 說得出
	 * 「這天為什麼跟平常不一樣」。
	 *
	 * @param array $request 申請資料列。
	 * @return int|WP_Error 生效後的紀錄 ID（override id 或 booking id）。
	 */
	protected static function apply( $request ) {
		switch ( $request['type'] ) {
			case self::TYPE_HOURS:
				$ranges = json_decode( $request['hours'], true );
				return UAPPT_Staff::save_override(
					array(
						'staff_id'      => $request['staff_id'],
						'override_date' => $request['request_date'],
						'is_closed'     => false,
						'hours'         => is_array( $ranges ) ? $ranges : array(),
						'note'          => $request['staff_note'],
						'source'        => 'shift_request',
						'request_id'    => $request['id'],
					)
				);

			case self::TYPE_LEAVE:
				return UAPPT_Staff::save_override(
					array(
						'staff_id'      => $request['staff_id'],
						'override_date' => $request['request_date'],
						'is_closed'     => true,
						// 明寫而不是靠 save_override() 的預設：員工送上來的就是
						// 「請假」，而不是主管排的「例休」——員工端沒有、也不該有
						// 替自己排例休的入口（那是排班，不是申請）。
						'closed_reason' => UAPPT_Staff::CLOSED_LEAVE,
						'note'          => $request['staff_note'],
						'source'        => 'shift_request',
						'request_id'    => $request['id'],
					)
				);

			case self::TYPE_BLOCK:
				return UAPPT_Booking::create_block(
					array(
						'staff_id'   => $request['staff_id'],
						'date_ymd'   => $request['request_date'],
						'start_hm'   => $request['start_hm'],
						'end_hm'     => $request['end_hm'],
						'units'      => $request['units'],
						'note'       => $request['staff_note'],
						'created_by' => $request['created_by'],
					)
				);
		}

		return new WP_Error( 'uappt_unknown_type', __( '未知的申請類型。', 'ultimate-appointments' ) );
	}

	/**
	 * 這筆申請如果核准，會不會跟既有預約衝突。渲染審核頁時跑一次（給審核者
	 * 參考），approve() 核准當下再跑一次（真正的防線）。
	 *
	 * 營業時間與 slot_grid 是兩套獨立系統：核准縮減時數／整天休假**不會釋放
	 * 任何時間格，也不會影響既有預約**——這裡只是找出「核准後這些預約會落在
	 * 營業時間外」，讓審核者知道，不會自動取消或釋放任何東西。
	 *
	 * @param array $request 申請資料列（型狀跟 get()／create() 的輸入相容：
	 *                       至少要有 staff_id/type/request_date，
	 *                       hours 型要有 hours，block 型要有 start_hm/end_hm）。
	 * @return array {
	 *     @type array $hard           落在提案時段外的 held/confirmed 客人預約——硬擋，不能核准。
	 *     @type array $soft_bookings  落在提案時段外的其他預約（時段佔用、已完成、已取消等）——僅警告。
	 *     @type array $soft_requests  同一天其他還在等待審核的申請——僅警告。
	 * }
	 */
	public static function find_conflicts( $request ) {
		$result = array(
			'hard'          => array(),
			'soft_bookings' => array(),
			'soft_requests' => array(),
		);

		$staff_id = (int) $request['staff_id'];
		$date     = $request['request_date'];
		$type     = $request['type'];

		$staff = UAPPT_Staff::get( $staff_id );
		if ( ! $staff ) {
			return $result;
		}

		$prev_date = uappt_local_date( $date, '-1 day' );
		$next_date = uappt_local_date( $date, '+1 day' );

		// 撈當天與前一天（跨午夜的尾巴，跟日檢視同一個做法），kind=any 才看得到
		// 時段佔用——時段佔用本身刻意不檢查營業時間，落在提案時段外不算硬擋，
		// 但審核者應該看得到「這個人這天其實還有別的事」。
		$query = UAPPT_Booking::query(
			array(
				'staff_id'  => $staff_id,
				'date_from' => $prev_date,
				'date_to'   => $date,
				'kind'      => 'any',
				'per_page'  => 500,
				'order'     => 'ASC',
			)
		);

		$day_start_ts = self::local_ts( $date . ' 00:00:00' );
		$day_end_ts   = self::local_ts( $next_date . ' 00:00:00' );

		$proposed_windows = array();
		$block_start_ts   = null;
		$block_end_ts     = null;

		if ( self::TYPE_BLOCK === $type ) {
			$block_start_ts = self::local_ts( $date . ' ' . $request['start_hm'] . ':00' );
			$block_end_ts   = self::local_ts( $date . ' ' . $request['end_hm'] . ':00' );
			if ( $block_end_ts <= $block_start_ts ) {
				$block_end_ts += DAY_IN_SECONDS;
			}
		} else {
			// hours/leave：假設核准後，這天的營業區間會長怎樣——直接借用
			// get_business_windows() 的 dry-run 注入口，不另外複製一份解析邏輯。
			$dry_run_override = ( self::TYPE_LEAVE === $type )
				? array(
					'is_closed' => 1,
					'hours'     => null,
				)
				: array(
					'is_closed' => 0,
					'hours'     => $request['hours'],
				);

			$proposed_windows = UAPPT_Staff::get_business_windows(
				$staff,
				$date,
				array(
					'date'     => $date,
					'override' => $dry_run_override,
				)
			);
		}

		foreach ( $query['items'] as $booking ) {
			$b_start = self::local_ts( $booking['service_start'] );
			$b_end   = self::local_ts( $booking['service_end'] );

			if ( $b_end <= $day_start_ts || $b_start >= $day_end_ts ) {
				continue; // 跟這一天無關（前一天查回來但沒有跨夜延伸進今天）。
			}

			if ( self::TYPE_BLOCK === $type ) {
				if ( $b_end <= $block_start_ts || $b_start >= $block_end_ts ) {
					continue; // 沒有跟提案的佔用時段重疊。
				}
			} else {
				$covered = false;
				foreach ( $proposed_windows as $w ) {
					if ( $b_start >= $w['open_ts'] && $b_end <= $w['close_ts'] ) {
						$covered = true;
						break;
					}
				}
				if ( $covered ) {
					continue; // 核准後這筆預約仍然在營業時間內，不受影響。
				}
			}

			$is_customer_booking = ( UAPPT_Booking::KIND_BOOKING === $booking['kind'] );
			$is_active_booking   = in_array( $booking['status'], array( UAPPT_Booking::STATUS_HELD, UAPPT_Booking::STATUS_CONFIRMED ), true );

			if ( $is_customer_booking && $is_active_booking ) {
				$result['hard'][] = $booking;
			} else {
				$result['soft_bookings'][] = $booking;
			}
		}

		$result['soft_requests'] = self::query(
			array(
				'staff_id'     => $staff_id,
				'request_date' => $date,
				'status'       => self::STATUS_PENDING,
				'exclude_id'   => isset( $request['id'] ) ? (int) $request['id'] : 0,
			)
		);

		return $result;
	}

	/**
	 * 核准／駁回後寄一封通知信給申請人。刻意不複用
	 * `UAPPT_Reminders::send_customer_message()`——那支是客人形狀的（LINE 綁定、
	 * 訂單備註、行事曆連結），這裡只是「有沒有綁定帳號、有沒有 email，有就
	 * 寄一封純文字信」，用不到那一整套管道挑選與降級邏輯，另外寫一支極薄的
	 * 就好。沒有綁定帳號或帳號沒有 email 時靜默略過——通知本來就是錦上添花，
	 * 不能因為寄不出信就讓核准／駁回本身失敗。
	 *
	 * @param array  $request        申請資料列。
	 * @param string $subject_suffix 信件主旨的通知類別（例如「排班申請已核准」）。
	 * @param string $body           信件內文（純文字，換行會被轉成 <br>）。
	 */
	protected static function notify_staff( $request, $subject_suffix, $body ) {
		self::notify_staff_batch( (int) $request['staff_id'], $subject_suffix, $body );
	}

	/**
	 * 真正寄信的那一半。單筆（notify_staff()）與批次總結共用同一支，差別只在
	 * 內文怎麼組——寄信的降級規則（沒綁定帳號、帳號沒有 email 就靜默略過）
	 * 只能有一份。
	 *
	 * @param int    $staff_id       人員 ID。
	 * @param string $subject_suffix 信件主旨的通知類別。
	 * @param string $body           信件內文（純文字，換行會被轉成 <br>）。
	 */
	protected static function notify_staff_batch( $staff_id, $subject_suffix, $body ) {
		$staff = UAPPT_Staff::get( $staff_id );
		if ( ! $staff || empty( $staff['user_id'] ) ) {
			return;
		}

		$user = get_userdata( $staff['user_id'] );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: 店名 2: 通知類別 */
			__( '【%1$s】%2$s', 'ultimate-appointments' ),
			get_bloginfo( 'name' ),
			$subject_suffix
		);

		wp_mail(
			$user->user_email,
			$subject,
			nl2br( esc_html( $body ) ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * 把一筆還在 pending 的申請改成新狀態：帶原狀態條件的 UPDATE，回傳有沒有真的改到。
	 *
	 * ⚠️ 核准／駁回／撤回都要走這支，不能「先讀狀態、再更新」：兩個請求同時進來時
	 * 兩邊都會讀到 pending。條件式 UPDATE 只有一邊改得到，另一邊拿到 0 列就知道
	 * 已經有人處理過了。
	 *
	 * @param int   $id     申請 ID。
	 * @param array $fields 要寫入的欄位（值是 null 就寫 NULL）。
	 * @return bool
	 */
	protected static function claim( $id, $fields ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'shift_requests' );

		$set    = array();
		$values = array();
		foreach ( $fields as $column => $value ) {
			if ( null === $value ) {
				$set[] = "`{$column}` = NULL";
				continue;
			}
			$set[]    = "`{$column}` = " . ( is_int( $value ) ? '%d' : '%s' );
			$values[] = $value;
		}
		$values[] = (int) $id;
		$values[] = self::STATUS_PENDING;

		$sql = "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE id = %d AND status = %s'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $wpdb->query( $wpdb->prepare( $sql, $values ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 同一天、同人員、同類型是否已經有一筆 pending 申請。
	 *
	 * @param int    $staff_id 人員 ID。
	 * @param string $date_ymd 日期 (Y-m-d)。
	 * @param string $type     申請類型。
	 * @return bool
	 */
	protected static function has_pending( $staff_id, $date_ymd, $type ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'shift_requests' );

		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE staff_id = %d AND request_date = %s AND type = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(int) $staff_id,
				$date_ymd,
				$type,
				self::STATUS_PENDING
			)
		);

		return $count > 0;
	}

	/**
	 * 把站台本地時間字串轉為 timestamp。跟 UAPPT_Staff::local_ts()／
	 * UAPPT_Booking::local_ts() 同樣的作法：明確指定 wp_timezone()，不用裸
	 * strtotime()。
	 *
	 * @param string $local_datetime 本地時間字串。
	 * @return int
	 */
	protected static function local_ts( $local_datetime ) {
		$dt = date_create( $local_datetime, wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}
}
