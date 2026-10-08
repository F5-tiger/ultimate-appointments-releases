<?php
/**
 * 人力資源批次匯入：人員主檔（staff）與逐日班表／請假（overrides）兩支匯入器。
 *
 * 流程刻意分成「規劃」與「套用」兩步：plan() 只讀不寫，把每一列會發生什麼事
 * （新增／更新／刪除／略過／錯誤／被衝突擋下）算出來給管理者看，確認之後才由
 * apply() 真的寫入。匯入是少數「一次動到幾十上百列」的操作，沒有預覽的話，
 * 一個表頭打錯就可能把整批人的班表寫成空的，而且事後很難看出哪裡不對。
 *
 * 兩支匯入器都**不自己下 SQL、也不自己驗證時間格式**：寫入一律走
 * UAPPT_Staff::save()／save_override()／delete_override()，時間解析一律走
 * UAPPT_Staff::sanitize_ranges()。批次層只負責「這一列要送去哪一支方法」與
 * 「怎麼彙總結果」——跟 UAPPT_Shift_Request::create_batch()／
 * UAPPT_Admin::handle_bulk_booking_action() 是同一條紀律，驗證規則一條都不重寫。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Import {

	const TYPE_STAFF     = 'staff';
	const TYPE_OVERRIDES = 'overrides';

	/**
	 * 單次匯入的列數上限。
	 *
	 * 理由跟 UAPPT_Shift_Request::MAX_BATCH_DAYS 一樣：逐日班表的衝突檢查會對
	 * 每一列各查一次既有預約，成本隨列數線性成長；一個手滑貼上整年份的檔案
	 * 會讓預覽頁直接逾時。
	 */
	const MAX_ROWS = 1000;

	/**
	 * 預覽結果暫存的存活時間。管理者看完預覽、確認、送出通常在幾分鐘內，
	 * 一小時足夠寬鬆，又不會讓過期的計畫一直躺在 options 表裡。
	 */
	const PREVIEW_TTL = HOUR_IN_SECONDS;

	/**
	 * 星期欄位：CSV 欄位鍵 => UAPPT_Staff 的星期鍵。
	 */
	const WEEKDAY_KEYS = array(
		'mon' => 'mon',
		'tue' => 'tue',
		'wed' => 'wed',
		'thu' => 'thu',
		'fri' => 'fri',
		'sat' => 'sat',
		'sun' => 'sun',
	);

	/**
	 * 欄位定義：內部鍵 => 顯示用的中文表頭。
	 *
	 * 這是匯出範本、表頭解析、預覽表格**共同**的唯一來源——匯出的檔案改一改
	 * 就能直接匯回來，是這個功能最重要的使用方式（沒有人想自己手刻表頭），
	 * 兩邊各維護一份欄位清單遲早會對不起來。
	 *
	 * @param string $type self::TYPE_STAFF 或 self::TYPE_OVERRIDES。
	 * @return array
	 */
	public static function columns( $type ) {
		if ( self::TYPE_OVERRIDES === $type ) {
			return array(
				'staff_id'   => __( '人員編號', 'ultimate-appointments' ),
				'staff_name' => __( '人員姓名（僅供對照）', 'ultimate-appointments' ),
				'date'       => __( '日期', 'ultimate-appointments' ),
				'type'       => __( '類型', 'ultimate-appointments' ),
				'hours'      => __( '時段', 'ultimate-appointments' ),
				'note'       => __( '備註', 'ultimate-appointments' ),
			);
		}

		return array(
			'id'               => __( '編號', 'ultimate-appointments' ),
			'name'             => __( '姓名', 'ultimate-appointments' ),
			'status'           => __( '狀態', 'ultimate-appointments' ),
			'is_24h'           => __( '24小時營業', 'ultimate-appointments' ),
			'capacity'         => __( '同時可服務人數', 'ultimate-appointments' ),
			'slot_interval'    => __( '時間格顆粒', 'ultimate-appointments' ),
			'price_adjustment' => __( '指定加價', 'ultimate-appointments' ),
			'sort_order'       => __( '排序', 'ultimate-appointments' ),
			'user_email'       => __( '綁定帳號Email', 'ultimate-appointments' ),
			'mon'              => __( '週一', 'ultimate-appointments' ),
			'tue'              => __( '週二', 'ultimate-appointments' ),
			'wed'              => __( '週三', 'ultimate-appointments' ),
			'thu'              => __( '週四', 'ultimate-appointments' ),
			'fri'              => __( '週五', 'ultimate-appointments' ),
			'sat'              => __( '週六', 'ultimate-appointments' ),
			'sun'              => __( '週日', 'ultimate-appointments' ),
		);
	}

	/**
	 * 讀取上傳的 CSV。
	 *
	 * 表頭同時接受中文標籤與內部英文鍵：中文是匯出範本長的樣子，英文則讓習慣
	 * 從別的系統匯出的人不必先翻譯一輪。認不得的欄位直接忽略（不是錯誤）——
	 * 從其他系統匯出的檔案往往多帶幾欄無關的資料，為此整批退回沒有意義。
	 *
	 * @param string $type      匯入類型。
	 * @param string $file_path 上傳檔案的暫存路徑。
	 * @param string $error     解析失敗時的錯誤訊息（傳參考）。
	 * @return array|false ['headers' => 內部鍵陣列, 'rows' => 每列的關聯陣列]，失敗回 false。
	 */
	public static function read_csv( $type, $file_path, &$error = '' ) {
		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			$error = __( '無法讀取上傳的檔案。', 'ultimate-appointments' );
			return false;
		}

		$header_row = fgetcsv( $handle );
		if ( ! $header_row ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$error = __( '這個檔案是空的，或第一列不是表頭。', 'ultimate-appointments' );
			return false;
		}

		// 匯出的檔案開頭有 UTF-8 BOM（Excel 需要），原封不動匯回來時第一個
		// 欄位名稱會多帶三個看不見的位元組，比對表頭就會失敗——這是 CSV 來回
		// 最容易踩到、又最難從畫面上看出原因的一個坑。
		if ( isset( $header_row[0] ) ) {
			$header_row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header_row[0] );
		}

		$label_to_key = array();
		foreach ( self::columns( $type ) as $key => $label ) {
			$label_to_key[ self::normalize_header( $label ) ] = $key;
			$label_to_key[ $key ]                             = $key;
		}

		$headers = array();
		foreach ( $header_row as $index => $raw ) {
			$normalized       = self::normalize_header( $raw );
			$headers[ $index ] = isset( $label_to_key[ $normalized ] ) ? $label_to_key[ $normalized ] : null;
		}

		$known = array_values( array_filter( $headers ) );
		if ( ! $known ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$error = __( '表頭認不出任何欄位，請用下方的「下載目前資料」當範本。', 'ultimate-appointments' );
			return false;
		}

		$rows = array();
		$line = 1;
		while ( ( $data = fgetcsv( $handle ) ) !== false ) {
			$line++;

			// 完全空白的列直接跳過：Excel 存檔常在結尾留下幾列空的。
			if ( array_filter( $data, static function ( $cell ) {
				return '' !== trim( (string) $cell );
			} ) === array() ) {
				continue;
			}

			$row = array( '_line' => $line );
			foreach ( $headers as $index => $key ) {
				if ( ! $key ) {
					continue;
				}
				$row[ $key ] = isset( $data[ $index ] ) ? trim( (string) $data[ $index ] ) : '';
			}
			$rows[] = $row;

			if ( count( $rows ) > self::MAX_ROWS ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$error = sprintf(
					/* translators: %d: 單次可匯入的列數上限 */
					__( '一次最多匯入 %d 列，請拆成幾個檔案分批上傳。', 'ultimate-appointments' ),
					self::MAX_ROWS
				);
				return false;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $rows ) {
			$error = __( '檔案裡只有表頭，沒有任何資料列。', 'ultimate-appointments' );
			return false;
		}

		return array(
			'headers' => $known,
			'rows'    => $rows,
		);
	}

	/**
	 * 表頭正規化：去掉空白、全形括號註記與大小寫差異，讓「人員編號」與
	 * 「人員編號 」、「staff_id」與「Staff ID」都對得上。
	 *
	 * @param string $raw 原始表頭字串。
	 * @return string
	 */
	protected static function normalize_header( $raw ) {
		$raw = (string) $raw;
		// 「人員姓名（僅供對照）」這種括號註記只是給人看的，比對時拿掉。
		$raw = preg_replace( '/[（(].*?[）)]/u', '', $raw );
		$raw = str_replace( array( ' ', "\t", '　', '_', '-' ), '', $raw );
		return strtolower( trim( $raw ) );
	}

	/**
	 * 規劃：把每一列變成「會發生什麼」，**完全不寫入任何資料**。
	 *
	 * @param string $type    匯入類型。
	 * @param array  $parsed  read_csv() 的回傳值。
	 * @return array 每個元素：['line','op','message','label','data']
	 *               op：create／update／delete／error／blocked
	 */
	public static function plan( $type, $parsed ) {
		return self::TYPE_OVERRIDES === $type
			? self::plan_overrides( $parsed )
			: self::plan_staff( $parsed );
	}

	/**
	 * 人員主檔的逐列規劃。
	 *
	 * ⚠️ **更新既有人員時一定要先把整列讀回來再合併。** UAPPT_Staff::save() 是
	 * 整列覆寫（沒有「只改一欄」的寫法），直接把 CSV 那幾欄丟進去，沒出現在
	 * 檔案裡的班表、綁定帳號、指定加價會全部被存成空值。v2.7.0 的快速停用與
	 * v2.13.0 的帳號綁定各踩過一次同一個坑，這裡是第三次，寫下來提醒後人。
	 *
	 * @param array $parsed read_csv() 的回傳值。
	 * @return array
	 */
	protected static function plan_staff( $parsed ) {
		$headers  = $parsed['headers'];
		$plans    = array();
		$weekdays = array_intersect( array_keys( self::WEEKDAY_KEYS ), $headers );

		foreach ( $parsed['rows'] as $row ) {
			$line = $row['_line'];
			$id   = isset( $row['id'] ) ? absint( $row['id'] ) : 0;

			if ( $id ) {
				$existing = UAPPT_Staff::get( $id );
				if ( ! $existing ) {
					$plans[] = self::error_plan( $line, sprintf( /* translators: %d: 人員編號 */ __( '找不到編號 %d 的人員。編號留空代表新增一位人員。', 'ultimate-appointments' ), $id ) );
					continue;
				}
				// hydrate() 出來的形狀本來就跟 save() 的輸入對稱，可以直接當底稿。
				$data = $existing;
				$op   = 'update';
			} else {
				$data = array(
					'name'             => '',
					'status'           => 'active',
					'is_24h'           => false,
					'slot_interval'    => '',
					'business_hours'   => array(),
					'price_adjustment' => 0,
					'sort_order'       => 0,
					'capacity'         => 1,
					'user_id'          => 0,
				);
				$op   = 'create';
			}

			// 空白儲存格一律代表「這一欄不動」，不是「清成空值」。匯入最怕的是
			// 沒填的欄位默默把既有資料洗掉，而「要清空」本來就該去編輯頁明確地做。
			if ( self::filled( $row, 'name' ) ) {
				$data['name'] = $row['name'];
			}
			if ( self::filled( $row, 'status' ) ) {
				$data['status'] = self::parse_status( $row['status'] );
			}
			if ( self::filled( $row, 'is_24h' ) ) {
				$data['is_24h'] = self::parse_bool( $row['is_24h'] );
			}
			if ( self::filled( $row, 'capacity' ) ) {
				$data['capacity'] = (int) $row['capacity'];
			}
			if ( self::filled( $row, 'slot_interval' ) ) {
				$data['slot_interval'] = (int) $row['slot_interval'];
			}
			if ( self::filled( $row, 'price_adjustment' ) ) {
				$data['price_adjustment'] = (float) $row['price_adjustment'];
			}
			if ( self::filled( $row, 'sort_order' ) ) {
				$data['sort_order'] = (int) $row['sort_order'];
			}

			if ( '' === trim( (string) $data['name'] ) ) {
				$plans[] = self::error_plan( $line, __( '姓名不可為空。', 'ultimate-appointments' ) );
				continue;
			}

			if ( self::filled( $row, 'user_email' ) ) {
				$user = get_user_by( 'email', $row['user_email'] );
				if ( ! $user ) {
					$plans[] = self::error_plan(
						$line,
						sprintf(
							/* translators: %s: CSV 裡填的 email */
							__( '找不到 email 是 %s 的 WordPress 使用者。請先建立帳號，或把這一格留空。', 'ultimate-appointments' ),
							$row['user_email']
						)
					);
					continue;
				}
				$data['user_id'] = (int) $user->ID;
			}

			// 只要表頭出現任何一個星期欄，整週班表就以 CSV 為準：沒出現的那幾
			// 天視為「那天不上班」。這條規則刻意跟其他欄位的「空白＝不動」不同
			// ——班表是一個整體，「週三留空」的意思幾乎一定是「週三休」，若沿用
			// 「不動」的規則，就永遠沒有辦法用匯入把某一天改成休息。
			if ( $weekdays ) {
				$hours  = array();
				$errors = array();
				foreach ( self::WEEKDAY_KEYS as $col => $day_key ) {
					$ranges = self::parse_ranges_cell( isset( $row[ $col ] ) ? $row[ $col ] : '' );
					$clean  = UAPPT_Staff::sanitize_ranges( $ranges, self::columns( self::TYPE_STAFF )[ $col ], $errors );
					if ( $clean ) {
						$hours[ $day_key ] = $clean;
					}
				}
				if ( $errors ) {
					$plans[] = self::error_plan( $line, implode( ' ', $errors ) );
					continue;
				}
				$data['business_hours'] = $hours;
			}

			$plans[] = array(
				'line'    => $line,
				'op'      => $op,
				'label'   => $data['name'],
				'message' => 'create' === $op
					? __( '新增人員', 'ultimate-appointments' )
					: sprintf( /* translators: %d: 人員編號 */ __( '更新人員 #%d', 'ultimate-appointments' ), $id ),
				'data'    => $data,
			);
		}

		return $plans;
	}

	/**
	 * 逐日班表／請假的逐列規劃。
	 *
	 * 衝突檢查直接借 UAPPT_Shift_Request::find_conflicts()——它已經是「假設這天
	 * 改成這樣，既有預約會不會落在營業時間外」的唯一實作，另外寫一份只會多一
	 * 個要跟它保持同步的地方。政策也照抄審核頁：hard（有客人的 held／confirmed
	 * 預約會被排到營業時間外）擋下不套用，其餘只提醒。
	 *
	 * @param array $parsed read_csv() 的回傳值。
	 * @return array
	 */
	protected static function plan_overrides( $parsed ) {
		$plans = array();

		foreach ( $parsed['rows'] as $row ) {
			$line     = $row['_line'];
			$staff_id = isset( $row['staff_id'] ) ? absint( $row['staff_id'] ) : 0;
			$staff    = $staff_id ? UAPPT_Staff::get( $staff_id ) : null;

			if ( ! $staff ) {
				$plans[] = self::error_plan( $line, __( '找不到這位人員（請填「人員編號」，不是姓名）。', 'ultimate-appointments' ) );
				continue;
			}

			$date = isset( $row['date'] ) ? self::normalize_date( $row['date'] ) : '';
			if ( ! $date ) {
				$plans[] = self::error_plan( $line, __( '日期格式不正確，請用 2026-09-30 這種寫法。', 'ultimate-appointments' ) );
				continue;
			}

			$type     = self::parse_override_type( isset( $row['type'] ) ? $row['type'] : '' );
			// 例休與請假都是「整天不開放」，差別只在 closed_reason。衝突檢查那一側
			// 只認得 TYPE_LEAVE／TYPE_HOURS，所以另外換算一個給它用的值。
			$row_closed    = in_array( $type, array( UAPPT_Shift_Request::TYPE_LEAVE, UAPPT_Staff::CLOSED_OFF ), true );
			$closed_reason = ( UAPPT_Staff::CLOSED_OFF === $type ) ? UAPPT_Staff::CLOSED_OFF : UAPPT_Staff::CLOSED_LEAVE;
			$conflict_type = $row_closed ? UAPPT_Shift_Request::TYPE_LEAVE : UAPPT_Shift_Request::TYPE_HOURS;
			$existing = UAPPT_Staff::get_override( $staff_id, $date );
			$label    = $staff['name'] . ' / ' . $date;

			if ( 'clear' === $type ) {
				if ( ! $existing ) {
					$plans[] = array(
						'line'    => $line,
						'op'      => 'skip',
						'label'   => $label,
						'message' => __( '這天本來就沒有逐日調整，不用刪除。', 'ultimate-appointments' ),
						'data'    => array(),
					);
					continue;
				}
				$plans[] = array(
					'line'    => $line,
					'op'      => 'delete',
					'label'   => $label,
					'message' => __( '刪除逐日調整，這天回到每週預設班表', 'ultimate-appointments' ),
					'data'    => array( 'override_id' => (int) $existing['id'] ),
				);
				continue;
			}

			if ( ! $type ) {
				$plans[] = self::error_plan( $line, __( '類型只能填「例休」「請假」「自訂時段」或「清除」（也接受 off／leave／hours／clear）。', 'ultimate-appointments' ) );
				continue;
			}

			$hours = array();
			if ( UAPPT_Shift_Request::TYPE_HOURS === $type ) {
				$errors = array();
				$hours  = UAPPT_Staff::sanitize_ranges(
					self::parse_ranges_cell( isset( $row['hours'] ) ? $row['hours'] : '' ),
					__( '時段', 'ultimate-appointments' ),
					$errors
				);
				if ( $errors ) {
					$plans[] = self::error_plan( $line, implode( ' ', $errors ) );
					continue;
				}
				if ( ! $hours ) {
					$plans[] = self::error_plan( $line, __( '類型是「自訂時段」時必須填時段，例如 09:00-13:00|14:00-18:00。整天不上班請把類型改成「例休」（排定不上班）或「請假」。', 'ultimate-appointments' ) );
					continue;
				}
			}

			$conflicts = UAPPT_Shift_Request::find_conflicts(
				array(
					'staff_id'     => $staff_id,
					'type'         => $conflict_type,
					'request_date' => $date,
					'hours'        => $hours,
				)
			);

			if ( ! empty( $conflicts['hard'] ) ) {
				$plans[] = array(
					'line'    => $line,
					'op'      => 'blocked',
					'label'   => $label,
					'message' => sprintf(
						/* translators: %d: 會落在營業時間外的既有預約筆數 */
						__( '這天有 %d 筆已確認／待付款的預約會落在新的營業時間外，這一列不會套用。請先改期或改派這些預約。', 'ultimate-appointments' ),
						count( $conflicts['hard'] )
					),
					'data'    => array(),
				);
				continue;
			}

			$note = isset( $row['note'] ) ? $row['note'] : '';
			$soft = count( $conflicts['soft_bookings'] ) + count( $conflicts['soft_requests'] );

			$message = $row_closed
				? UAPPT_Staff::closed_reason_label( $closed_reason )
				: sprintf(
					/* translators: %s: 時段列表 */
					__( '自訂時段：%s', 'ultimate-appointments' ),
					implode( '、', array_map(
						static function ( $range ) {
							return $range[0] . '–' . $range[1];
						},
						$hours
					) )
				);

			if ( $soft ) {
				$message .= '　' . sprintf(
					/* translators: %d: 僅需注意的既有項目筆數 */
					__( '（注意：這天另有 %d 筆時段佔用／待審申請）', 'ultimate-appointments' ),
					$soft
				);
			}

			$plans[] = array(
				'line'    => $line,
				'op'      => $existing ? 'update' : 'create',
				'label'   => $label,
				'message' => $message,
				'data'    => array(
					'staff_id'      => $staff_id,
					'override_date' => $date,
					'is_closed'     => $row_closed ? 1 : 0,
					'closed_reason' => $closed_reason,
					'hours'         => $hours,
					'note'          => $note,
					// 逐日調整表格的「來源」欄才說得出這天為什麼跟平常不一樣。
					'source'        => 'import',
				),
			);
		}

		return $plans;
	}

	/**
	 * 套用計畫：逐列呼叫既有的單筆方法，統計成功與失敗。
	 *
	 * **單列失敗不中斷整批**——跟 create_batch()／handle_bulk_booking_action()
	 * 同一個精神：一列出錯就整批退回，會讓管理者陷入「改一列、重跑一次、再撞
	 * 到下一列」的地獄。錯誤逐列回報，讓他一次修完。
	 *
	 * @param string $type  匯入類型。
	 * @param array  $plans plan() 的回傳值。
	 * @return array ['ok'=>int, 'skipped'=>int, 'failed'=>[行號 => 錯誤訊息]]
	 */
	public static function apply( $type, $plans ) {
		$result = array(
			'ok'      => 0,
			'skipped' => 0,
			'failed'  => array(),
		);

		foreach ( $plans as $plan ) {
			// error／blocked／skip 在預覽時就已經決定不套用，這裡不再重試。
			if ( ! in_array( $plan['op'], array( 'create', 'update', 'delete' ), true ) ) {
				$result['skipped']++;
				continue;
			}

			if ( self::TYPE_OVERRIDES === $type ) {
				$outcome = 'delete' === $plan['op']
					? UAPPT_Staff::delete_override( $plan['data']['override_id'] )
					: UAPPT_Staff::save_override( $plan['data'] );
			} else {
				$outcome = UAPPT_Staff::save( $plan['data'] );
			}

			if ( is_wp_error( $outcome ) ) {
				$result['failed'][ $plan['line'] ] = $outcome->get_error_message();
				continue;
			}

			$result['ok']++;
		}

		return $result;
	}

	/**
	 * 這一欄有沒有出現在檔案裡、而且有填東西。
	 *
	 * @param array  $row 一列資料。
	 * @param string $key 欄位鍵。
	 * @return bool
	 */
	protected static function filled( $row, $key ) {
		return isset( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] );
	}

	/**
	 * 產生一列「這列有錯、不會套用」的計畫。
	 *
	 * @param int    $line    行號。
	 * @param string $message 錯誤訊息。
	 * @return array
	 */
	protected static function error_plan( $line, $message ) {
		return array(
			'line'    => $line,
			'op'      => 'error',
			'label'   => '',
			'message' => $message,
			'data'    => array(),
		);
	}

	/**
	 * 「09:00-13:00|14:00-18:00」→ [['09:00','13:00'],['14:00','18:00']]。
	 *
	 * 只負責把儲存格拆成 [開始, 結束] 的陣列，**格式對不對交給
	 * UAPPT_Staff::sanitize_ranges()**（它已經處理 930／9／9:5 的容錯、跨午夜
	 * 慣例與起訖相同的擋錯），不在這裡另外寫一個時間解析器。
	 *
	 * 分隔符同時吃連字號與 en dash：介面上顯示的是「09:00–13:00」（en dash），
	 * 使用者把畫面上的字複製到 Excel 是很自然的事。
	 *
	 * @param string $cell 儲存格內容。
	 * @return array
	 */
	protected static function parse_ranges_cell( $cell ) {
		$cell = trim( (string) $cell );
		if ( '' === $cell ) {
			return array();
		}

		$ranges = array();
		foreach ( preg_split( '/[|,、]/u', $cell ) as $chunk ) {
			$chunk = trim( $chunk );
			if ( '' === $chunk ) {
				continue;
			}
			$parts    = preg_split( '/\s*[-–~〜～]\s*/u', $chunk, 2 );
			$ranges[] = array(
				isset( $parts[0] ) ? trim( $parts[0] ) : '',
				isset( $parts[1] ) ? trim( $parts[1] ) : '',
			);
		}

		return $ranges;
	}

	/**
	 * 狀態欄：接受中文與英文寫法。
	 *
	 * @param string $raw 儲存格內容。
	 * @return string 'active' 或 'inactive'。
	 */
	protected static function parse_status( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		return in_array( $raw, array( 'inactive', '停用', '停止', '0', 'no' ), true ) ? 'inactive' : 'active';
	}

	/**
	 * 布林欄：接受 1/0、是/否、true/false、yes/no。
	 *
	 * @param string $raw 儲存格內容。
	 * @return bool
	 */
	protected static function parse_bool( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		return in_array( $raw, array( '1', 'true', 'yes', 'y', '是', 'v' ), true );
	}

	/**
	 * 類型欄：接受中文與英文寫法，認不得回空字串。
	 *
	 * @param string $raw 儲存格內容。
	 * ⚠️ 回傳值裡的 `off`（例休）**不是** UAPPT_Shift_Request 的類型，是
	 * `staff_overrides.closed_reason`。排班申請沒有「例休」這種類型（員工不會替
	 * 自己排例休），所以不要為了對稱而在那邊補一個常數——呼叫端把 `off` 與
	 * TYPE_LEAVE 都當成「整天不開放」，只有 closed_reason 不一樣。
	 *
	 * @return string TYPE_LEAVE／UAPPT_Staff::CLOSED_OFF／TYPE_HOURS／'clear'／''。
	 */
	protected static function parse_override_type( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );

		// 例休要排在請假前面比對：兩組關鍵字沒有重疊，但順序寫下來比較好讀。
		if ( in_array( $raw, array( 'off', '例休', '排休', '固定休', '公休' ), true ) ) {
			return UAPPT_Staff::CLOSED_OFF;
		}
		if ( in_array( $raw, array( 'leave', '請假', '休假', '整天休假' ), true ) ) {
			return UAPPT_Shift_Request::TYPE_LEAVE;
		}
		if ( in_array( $raw, array( 'hours', '自訂時段', '時段', '上班' ), true ) ) {
			return UAPPT_Shift_Request::TYPE_HOURS;
		}
		if ( in_array( $raw, array( 'clear', 'delete', '清除', '刪除', '取消調整' ), true ) ) {
			return 'clear';
		}

		return '';
	}

	/**
	 * 日期欄正規化：Excel 常把日期存成 2026/9/30 或 2026.9.30。
	 *
	 * 認不得就回空字串讓呼叫端報錯，**不要猜**——猜錯會把班表寫到別的日子，
	 * 而且不會有任何人發現。
	 *
	 * @param string $raw 儲存格內容。
	 * @return string Y-m-d，或空字串。
	 */
	protected static function normalize_date( $raw ) {
		$raw = trim( (string) $raw );
		$raw = str_replace( array( '/', '.' ), '-', $raw );

		if ( ! preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m ) ) {
			return '';
		}

		$date = sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $date : '';
	}

	/**
	 * 預覽計畫暫存用的 key。跟著使用者走，兩位管理者同時匯入不會互相覆蓋。
	 *
	 * @return string
	 */
	public static function preview_key() {
		return 'uappt_import_preview_' . get_current_user_id();
	}
}
