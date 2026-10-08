<?php
/**
 * 預約核心引擎：可預約時段計算、交易鎖定暫留、確認、釋放、改期。
 *
 * v2.0.0 起，控房邏輯從「服務資源（帶數量的池子）」改為「人力資源（具名個體）」：
 * slot_grid 的鍵是 staff_id，occupied 只會是 0 或 1（一個人同一時間只能服務一位）。
 * 這是必要的改動：「指定服務人員」在數量模型下無法防止超賣——3 位人員的池子，
 * 兩位客人都指定同一人、第三位不指定，數量模型只看到「3 ≤ 3」，實際上某個人
 * 卻被排了兩個客人。逐人記錄後，UNIQUE(staff_id, slot_start) 讓這種情況在
 * 資料庫層級就不可能發生。
 *
 * 「不指定服務人員」時，鎖定時必須在同一個交易內就選出一位具體的人並寫入，
 * 不能事後再分配，否則併發時一樣會撞（見 create_hold()）。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Booking {

	const STATUS_HELD      = 'held';
	const STATUS_CONFIRMED = 'confirmed';
	const STATUS_CANCELLED = 'cancelled';
	const STATUS_EXPIRED   = 'expired';
	const STATUS_COMPLETED = 'completed';
	const STATUS_NO_SHOW   = 'no_show';

	/**
	 * `assignment_state` 欄位的值。空字串（''）是預設／一般狀態，不需要常數。
	 *
	 * PENDING：客人（或後台客服）沒有指定人員，商品又開啟了「由管理者手動安排」，
	 * 系統照常挑一位候選人員把時段鎖住（見設計紀律 #3：不指定人員必須在同一個
	 * 交易內就決定出具體的人），但這個人選只是暫定，要等管理者確認或改派。
	 *
	 * REASSIGNED：管理者用 reassign_staff() 真的把人員換成別人（不是原本那位）。
	 * 單純的歷史標記，不影響任何判斷邏輯。**確認暫定人選**（新人員＝舊人員）
	 * 不算換人，會直接清回空字串——見 reassign_staff() 的說明。
	 */
	const ASSIGNMENT_PENDING    = 'pending';
	const ASSIGNMENT_REASSIGNED = 'reassigned';

	/**
	 * `kind` 欄位的值：這一列是客人的預約，還是純粹的時段佔用。
	 *
	 * BOOKING：一般預約（網站下單或後台建單），有客人、可能有訂單、會寄提醒。
	 *
	 * BLOCK：時段佔用——教育訓練、休息、私事，或門市現場客人臨時佔掉的人力。
	 * 沒有客人也沒有訂單，**不會**出現在預約列表預設檢視、不寄提醒、不進店家
	 * 明日清單，但一樣走同一套控房鎖定，所以前台不會把已經被佔掉的時段賣出去。
	 * 這是「人力不是只有網站訂單」的解法：門市現場消耗掉的人力也要進到同一本帳。
	 *
	 * BLOCK 的 product_id 存 0（欄位是 NOT NULL，用哨兵值），所有顯示路徑一律
	 * 先看 kind 再決定要不要去查商品。
	 */
	const KIND_BOOKING = 'booking';
	const KIND_BLOCK   = 'block';

	/**
	 * 計算某商品在某日期的可預約時段清單。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $date_ymd   日期 (Y-m-d)。
	 * @param string $plan_key   服務方案鍵；沒有方案的商品傳空字串。
	 * @param int    $staff_id   指定服務人員 ID；0 表示不指定（列出所有候選人員的聯集）。
	 * @return array|WP_Error 陣列元素：['start','end','remaining','max_group_size','segment']。
	 *                        remaining 是跨候選人員加總（既有「剩 X 位」顯示用）；
	 *                        max_group_size 是單一候選人員的最大剩餘名額，團體預約
	 *                        的「報名人數」欄位上限要用這個，不是 remaining——一筆
	 *                        預約最終只會落在一位人員身上，見 create_hold()。
	 *                        或錯誤。
	 */
	public static function get_available_slots( $product_id, $date_ymd, $plan_key = '', $staff_id = 0 ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_ymd ) ) {
			return new WP_Error( 'uappt_invalid_date', __( '日期格式錯誤。', 'ultimate-appointments' ) );
		}

		$settings = UAPPT_Product::get_booking_settings( $product_id, $plan_key );
		if ( ! $settings ) {
			return new WP_Error( 'uappt_not_bookable', __( '此商品未開放預約。', 'ultimate-appointments' ) );
		}

		$horizon_days = UAPPT_Product::get_horizon_days( $product_id );
		$today        = current_time( 'Y-m-d' );
		if ( $date_ymd < $today ) {
			return array();
		}
		if ( $horizon_days > 0 ) {
			$max_date = uappt_local_date( $today, "+{$horizon_days} days" );
			if ( $date_ymd > $max_date ) {
				return array();
			}
		}

		$candidates = self::resolve_candidate_staff( $settings, $staff_id );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}
		if ( empty( $candidates ) ) {
			return array();
		}

		$duration = (int) $settings['duration_minutes'];
		$buf_pre  = (int) $settings['buffer_before'];
		$buf_post = (int) $settings['buffer_after'];

		// 注意：這裡刻意用 time()（真實 UTC 時間戳）而非 current_time('timestamp')
		// （WP 舊式、依站台時區位移過的假時間戳），因為 local_ts() 回傳的是真實 UTC
		// 時間戳，兩者混用比較會因時區位移產生誤判。
		//
		// 最少提前預約時間直接加在這個界線上：早於它的時段一律不列出，讓店家有
		// 準備時間。這裡只是體驗層，真正的把關在 create_hold()。
		$now_ts = time() + UAPPT_Product::get_lead_minutes( $product_id ) * 60;

		// 時段分類頁籤用「代表人員」的設定（候選清單中排序最前的一位；若客人已
		// 指定人員就是那一位）。分類只是前台顯示分組的方便性設計，不是功能性
		// 限制，多位候選人員各自的分類設定不一致時，統一用同一套顯示即可。
		$segments = self::segment_definitions();

		// 每位候選人員各自有自己的班表、顆粒、佔用狀況——分開計算不能共用一個
		// 掃描步進值，否則會讓時長顆粒較粗的人員（例如 30 分鐘一格）被誤判成
		// 也能承接不屬於他自己時間格線上的開始時間（例如 09:15）。
		$results = array(); // 開始時間(H:i) => ['start','end','remaining','segment']

		foreach ( $candidates as $staff ) {
			$windows = UAPPT_Staff::get_business_windows( $staff, $date_ymd );
			if ( empty( $windows ) ) {
				continue;
			}

			$interval  = UAPPT_Staff::get_slot_interval( $staff );
			$occupancy = self::fetch_occupancy_map( $staff['id'], $date_ymd );

			foreach ( $windows as $window ) {
				for ( $service_start = $window['open_ts']; $service_start + $duration * 60 <= $window['close_ts']; $service_start += $interval * 60 ) {
					if ( $service_start < $now_ts ) {
						continue;
					}

					// 只保留「開始時間」的本地日期等於使用者查詢的 $date_ymd——這就是
					// 「時段歸在實際日期」的落地：凌晨 01:00 只出現在那天的選單。
					if ( wp_date( 'Y-m-d', $service_start ) !== $date_ymd ) {
						continue;
					}

					$service_end = $service_start + $duration * 60;
					$block_start = $service_start - $buf_pre * 60;
					$block_end   = $service_end + $buf_post * 60;

					if ( ! self::staff_is_free( $occupancy, $block_start, $block_end, $interval, $staff['capacity'] ) ) {
						continue;
					}

					$start_label = wp_date( 'H:i', $service_start );

					if ( ! isset( $results[ $start_label ] ) ) {
						$minute_of_day            = ( (int) wp_date( 'G', $service_start ) ) * 60 + (int) wp_date( 'i', $service_start );
						$results[ $start_label ] = array(
							'start'          => $start_label,
							'end'            => wp_date( 'H:i', $service_end ),
							'remaining'      => 0,
							'max_group_size' => 0,
							'segment'        => self::classify_segment( $segments, $minute_of_day ),
						);
					}

					$staff_remaining = self::remaining_capacity_in_block( $occupancy, $block_start, $block_end, $interval, $staff['capacity'] );

					// remaining：跨候選人員加總，是既有「剩 X 位」顯示功能的口徑，
					// 不要動它的語意。max_group_size 是新加的欄位，給團體預約的
					// 「報名人數」欄位當作可以填的上限用——一筆團體預約最終只會
					// 落在單一一位人員身上（見 create_hold() 挑人邏輯），所以能填
					// 的上限是「單一候選人員」的剩餘名額，不是跨人員加總；用加總
					// 的話會讓客人以為能訂到其實分散在好幾位人員身上、任何單一
					// 人員都無法真正一次滿足的人數。
					$results[ $start_label ]['remaining']      += $staff_remaining;
					$results[ $start_label ]['max_group_size']  = max( $results[ $start_label ]['max_group_size'], $staff_remaining );
				}
			}
		}

		$list = array_values( $results );

		usort(
			$list,
			function ( $a, $b ) {
				return strcmp( $a['start'], $b['start'] );
			}
		);

		return $list;
	}

	/**
	 * 一次算出一段日期區間內每一天的「有沒有位」，給前台預約精靈的日期列用。
	 *
	 * **為什麼不是直接呼叫 14 次 get_available_slots()**：那支每天都會為每位
	 * 候選人員查一次佔用表、查一到兩次逐日調整。14 天 × 3 位人員就是 40～80 次
	 * 查詢，只為了畫一排日期。這裡把「查資料」跟「算時段」拆開：資料一次撈完
	 * （佔用表用 fetch_occupancy_map_range()、逐日調整用
	 * UAPPT_Staff::prime_overrides()），時段計算則沿用完全相同的邏輯。
	 *
	 * **刻意跟 get_available_slots() 用同一套判斷**（同樣的營業區間來源、同樣的
	 * 顆粒、同樣的 staff_is_free()、同樣的最少提前預約時間）。這兩支只要有一點
	 * 不一致，就會變成「日期列顯示有空、點進去卻沒有任何時段」——那是客人最
	 * 無法理解的一種壞掉方式。差別只有一個：這裡找到第一個可用時段就停，不必
	 * 把整天的時段都算完。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $date_from  起始日期 (Y-m-d)，含當天。
	 * @param string $date_to    結束日期 (Y-m-d)，含當天。
	 * @param string $plan_key   服務方案鍵；沒有方案的商品傳空字串。
	 * @param int    $staff_id   指定服務人員 ID；0 表示不指定（任一候選人員有空就算有空）。
	 * @return array|WP_Error 日期 (Y-m-d) => 'open'|'full'|'closed'|'past'|'beyond'。
	 *                        open=有位、full=有排班但約滿、closed=沒排班或休假、
	 *                        past=已過去、beyond=超出開放預約天數。
	 */
	public static function get_day_availability( $product_id, $date_from, $date_to, $plan_key = '', $staff_id = 0 ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			return new WP_Error( 'uappt_invalid_date', __( '日期格式錯誤。', 'ultimate-appointments' ) );
		}
		if ( $date_from > $date_to ) {
			return new WP_Error( 'uappt_invalid_range', __( '日期區間的起訖顛倒了。', 'ultimate-appointments' ) );
		}

		$settings = UAPPT_Product::get_booking_settings( $product_id, $plan_key );
		if ( ! $settings ) {
			return new WP_Error( 'uappt_not_bookable', __( '此商品未開放預約。', 'ultimate-appointments' ) );
		}

		$candidates = self::resolve_candidate_staff( $settings, $staff_id );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		$duration = (int) $settings['duration_minutes'];
		$buf_pre  = (int) $settings['buffer_before'];
		$buf_post = (int) $settings['buffer_after'];

		$today        = current_time( 'Y-m-d' );
		$horizon_days = UAPPT_Product::get_horizon_days( $product_id );
		$max_date     = $horizon_days > 0 ? uappt_local_date( $today, "+{$horizon_days} days" ) : '';
		$now_ts       = time() + UAPPT_Product::get_lead_minutes( $product_id ) * 60;

		// --- 資料一次撈完 -------------------------------------------------
		// 逐日調整要從「前一天」開始預熱：get_business_windows() 為了接住跨午夜
		// 的班，每天都會順便看前一天的 override。少熱這一天，區間第一天還是會
		// 回去查資料庫。
		$prime_from = uappt_local_date( $date_from, '-1 day' );
		$occupancy  = array();

		foreach ( $candidates as $staff ) {
			UAPPT_Staff::prime_overrides( $staff['id'], $prime_from ? $prime_from : $date_from, $date_to );
			$occupancy[ (int) $staff['id'] ] = self::fetch_occupancy_map_range( $staff['id'], $date_from, $date_to );
		}

		// --- 逐日判斷 -----------------------------------------------------
		$result = array();
		$cursor = $date_from;

		while ( '' !== $cursor && $cursor <= $date_to ) {
			$result[ $cursor ] = self::classify_day(
				$candidates,
				$cursor,
				$occupancy,
				$duration,
				$buf_pre,
				$buf_post,
				$now_ts,
				$today,
				$max_date
			);
			$cursor = uappt_local_date( $cursor, '+1 day' );
		}

		return $result;
	}

	/**
	 * 判斷單一一天的狀態，供 get_day_availability() 使用。
	 *
	 * @param array  $candidates 候選人員。
	 * @param string $date_ymd   日期 (Y-m-d)。
	 * @param array  $occupancy  staff_id => 佔用表（已預先撈好整段區間）。
	 * @param int    $duration   服務時長（分鐘）。
	 * @param int    $buf_pre    前置緩衝（分鐘）。
	 * @param int    $buf_post   後置緩衝（分鐘）。
	 * @param int    $now_ts     已含最少提前預約時間的界線。
	 * @param string $today      今天 (Y-m-d)。
	 * @param string $max_date   開放預約的最後一天 (Y-m-d)；空字串代表不限制。
	 * @return string open|full|closed|past|beyond
	 */
	protected static function classify_day( $candidates, $date_ymd, $occupancy, $duration, $buf_pre, $buf_post, $now_ts, $today, $max_date ) {
		if ( $date_ymd < $today ) {
			return 'past';
		}
		if ( '' !== $max_date && $date_ymd > $max_date ) {
			return 'beyond';
		}

		$has_any_window = false;

		foreach ( $candidates as $staff ) {
			$windows = UAPPT_Staff::get_business_windows( $staff, $date_ymd );
			if ( empty( $windows ) ) {
				continue;
			}
			$has_any_window = true;

			$interval = UAPPT_Staff::get_slot_interval( $staff );
			$map      = isset( $occupancy[ (int) $staff['id'] ] ) ? $occupancy[ (int) $staff['id'] ] : array();

			foreach ( $windows as $window ) {
				for ( $service_start = $window['open_ts']; $service_start + $duration * 60 <= $window['close_ts']; $service_start += $interval * 60 ) {
					if ( $service_start < $now_ts ) {
						continue;
					}
					// 跟 get_available_slots() 一樣，只認「開始時間落在這一天」的時段。
					if ( wp_date( 'Y-m-d', $service_start ) !== $date_ymd ) {
						continue;
					}

					$block_start = $service_start - $buf_pre * 60;
					$block_end   = $service_start + $duration * 60 + $buf_post * 60;

					if ( self::staff_is_free( $map, $block_start, $block_end, $interval, $staff['capacity'] ) ) {
						// 找到一個就夠了：日期列只要知道「有沒有位」。
						return 'open';
					}
				}
			}
		}

		return $has_any_window ? 'full' : 'closed';
	}

	/**
	 * 目前套用的時段分類定義，供前台頁籤與後台顯示使用。
	 *
	 * v2.55.0 起不再需要商品與方案：分類是全站一份，跟哪一個商品、由誰服務
	 * 都無關。呼叫端（availability-query）本來就只拿它來把時段標上頁籤名稱，
	 * 而「這一天有沒有那一段的時段」是另外用實際時段數量判斷的，所以無條件
	 * 回傳三段定義不會讓前台冒出空頁籤。
	 *
	 * @return array 每個元素：['key'=>string,'name'=>string,'start_min'=>int,'end_min'=>int]
	 */
	public static function get_segment_definitions() {
		return self::segment_definitions();
	}

	/**
	 * 由今天以後開始，找出某商品第一個「有可預約時段」的日期（額滿時的建議改期用）。
	 * 找到第一天就停止；掃描範圍以「開放預約天數」為上限，避免無界查詢。
	 *
	 * @param int    $product_id    商品 ID。
	 * @param string $plan_key      服務方案鍵。
	 * @param string $from_date_ymd 從這天的隔天開始找 (Y-m-d)。
	 * @param int    $staff_id      指定服務人員 ID；0 表示不指定。
	 * @return string|null 找到則回傳 Y-m-d，否則 null。
	 */
	public static function find_next_available_date( $product_id, $plan_key, $from_date_ymd, $staff_id = 0 ) {
		$horizon_days = UAPPT_Product::get_horizon_days( $product_id );
		$cursor       = $from_date_ymd;

		for ( $i = 0; $i < $horizon_days; $i++ ) {
			$cursor = uappt_local_date( $cursor, '+1 day' );
			if ( ! $cursor ) {
				break;
			}

			$slots = self::get_available_slots( $product_id, $cursor, $plan_key, $staff_id );
			if ( is_wp_error( $slots ) ) {
				break;
			}
			if ( ! empty( $slots ) ) {
				return $cursor;
			}
		}

		return null;
	}

	/**
	 * 建立暫留鎖定（加入購物車時呼叫）。交易內鎖定所有候選人員涉及的時間格，
	 * 從整段都空著的人員中選出一位並只對那一位寫入佔用。
	 *
	 * @param array $args {
	 *     @type int    $product_id
	 *     @type string $plan_key     服務方案鍵；沒有方案的商品傳空字串
	 *     @type bool   $ignore_paused 略過銷售政策檢查——「暫停接受預約」與「方案已停用」
	 *                                兩者都算（後台手動建單用）
	 *     @type string $date_ymd     Y-m-d
	 *     @type string $time_hm      H:i（服務開始時間）
	 *     @type int    $staff_id     指定服務人員 ID；0 或省略表示不指定，由系統挑選
	 *     @type string $cart_item_key
	 *     @type int    $customer_id
	 * }
	 * @return int|WP_Error 預約 ID。
	 */
	public static function create_hold( $args ) {
		global $wpdb;

		$product_id      = (int) $args['product_id'];
		$plan_key        = isset( $args['plan_key'] ) ? sanitize_key( (string) $args['plan_key'] ) : '';
		$date_ymd        = isset( $args['date_ymd'] ) ? sanitize_text_field( $args['date_ymd'] ) : '';
		$time_hm         = isset( $args['time_hm'] ) ? sanitize_text_field( $args['time_hm'] ) : '';
		$requested_staff = isset( $args['staff_id'] ) ? (int) $args['staff_id'] : 0;
		$staff_requested = $requested_staff > 0;

		// 客人（或後台客服）自己指定了人員就不需要分派，只看「沒指定」的情況。
		// 時段的鎖定完全不受這個標記影響——下面照常會挑一位候選人員把格子佔住，
		// 這裡純粹是給管理者事後確認／改派用的提示（見設計紀律 #3 的說明）。
		$assignment_state = ( ! $staff_requested && UAPPT_Product::manual_assignment_enabled( $product_id ) )
			? self::ASSIGNMENT_PENDING
			: '';

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_ymd ) || ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time_hm ) ) {
			return new WP_Error( 'uappt_invalid_slot', __( '選擇的時段格式不正確，請重新選擇。', 'ultimate-appointments' ) );
		}

		$settings = UAPPT_Product::get_booking_settings( $product_id, $plan_key );
		if ( ! $settings ) {
			return new WP_Error( 'uappt_not_bookable', __( '此商品未開放預約，或選擇的服務方案已不存在。', 'ultimate-appointments' ) );
		}

		// 暫停接受預約：這裡才是真正的防線（前台不顯示時段只是體驗層，表單欄位是
		// 客人可以自己補的）。後台手動建立預約會明確傳入 ignore_paused——刻意用一個
		// 獨立參數而不是看 created_by 有沒有值來暗示，那種隱性關聯太脆弱。
		if ( empty( $args['ignore_paused'] ) && UAPPT_Product::is_paused( $product_id ) ) {
			return new WP_Error( 'uappt_paused', __( '此服務目前暫停接受預約，請聯繫客服。', 'ultimate-appointments' ) );
		}

		// 方案層級的停售，跟商品層級的暫停是同一種東西（銷售政策），所以受同一個
		// 旗標保護：前台擋、後台手動建單放行。這個檢查刻意放在 get_booking_settings()
		// 之後——引擎那邊必須照樣解析得出已停用的方案，既有預約才改得了期。
		if ( empty( $args['ignore_paused'] ) && '' !== $plan_key && UAPPT_Product::is_plan_inactive( $product_id, $plan_key ) ) {
			return new WP_Error( 'uappt_plan_inactive', __( '此服務方案目前暫停銷售，請改選其他方案或聯繫客服。', 'ultimate-appointments' ) );
		}

		// 開放預約天數也是銷售政策，跟上面兩個同一組：前台擋，後台手動建單與付款後補鎖
		// （都帶 ignore_paused）放行。get_available_slots() 不列出那些日子只是體驗層，
		// v3.0.2 以前直接送出可以訂到開放天數以外。
		if ( empty( $args['ignore_paused'] ) ) {
			$horizon_days = UAPPT_Product::get_horizon_days( $product_id );
			if ( $horizon_days > 0 && $date_ymd > uappt_local_date( current_time( 'Y-m-d' ), "+{$horizon_days} days" ) ) {
				return new WP_Error(
					'uappt_beyond_horizon',
					sprintf(
						/* translators: %d: 開放預約天數 */
						__( '目前只開放預約 %d 天內的時段，請重新選擇日期。', 'ultimate-appointments' ),
						$horizon_days
					)
				);
			}
		}

		$candidates = self::resolve_candidate_staff( $settings, $requested_staff );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}
		if ( empty( $candidates ) ) {
			return new WP_Error( 'uappt_no_staff_available', __( '目前沒有可服務的人員，請稍後再試或聯繫客服。', 'ultimate-appointments' ) );
		}

		// 團體預約：一張訂單一次佔用幾個名額。留空／傳 1 就是今天的行為（每筆
		// 預約吃一個名額），跟現有站台完全無感。>1 時必須是商品明確開放「一次
		// 幫多人報名」才放行，還要守商品自己設的每張訂單人數上限——這兩個檢查
		// 都要在這裡（真正把關的地方），前台欄位的顯示/隱藏跟 max 屬性只是體驗層。
		$requested_units = isset( $args['units'] ) ? max( 1, (int) $args['units'] ) : 1;
		if ( $requested_units > 1 ) {
			if ( empty( $settings['group_booking'] ) ) {
				return new WP_Error( 'uappt_group_booking_disabled', __( '此服務不開放一次幫多人報名，請分開預約或聯繫客服。', 'ultimate-appointments' ) );
			}
			if ( ! empty( $settings['group_max_participants'] ) && $requested_units > (int) $settings['group_max_participants'] ) {
				return new WP_Error(
					'uappt_group_max_exceeded',
					sprintf(
						/* translators: %d: 每張訂單人數上限 */
						__( '這項服務單張訂單最多只能報名 %d 人，請減少人數或分開預約。', 'ultimate-appointments' ),
						(int) $settings['group_max_participants']
					)
				);
			}
		}

		$duration = (int) $settings['duration_minutes'];
		$buf_pre  = (int) $settings['buffer_before'];
		$buf_post = (int) $settings['buffer_after'];

		$service_start_ts = self::local_ts( $date_ymd . ' ' . $time_hm . ':00' );
		$service_end_ts   = $service_start_ts + $duration * 60;

		$now_ts       = time(); // 真實 UTC 時間戳，需與 local_ts() 的回傳值同基準才能正確比較。
		$lead_minutes = UAPPT_Product::get_lead_minutes( $product_id );

		// 最少提前預約時間在這裡才是真正的防線。get_available_slots() 不列出這些
		// 時段只是體驗層，直接 POST 一個時間過來一樣要被擋下（設計紀律：前端不可信）。
		//
		// $args['bypass_lead_time']：只給 confirm_or_relock() 的補救鎖定用——客人
		// 已經付款完成，這是系統自己要把同一個時段找回來，不是新的預約請求，不該
		// 被「最少提前預約時間」擋下（例如 lead time 設 120 分鐘、客人在服務前
		// 30 分鐘才完成付款，補救鎖定會被自己的銷售政策擋掉，變成已收款卻沒時段）。
		if ( empty( $args['bypass_lead_time'] ) && $service_start_ts < $now_ts + $lead_minutes * 60 ) {
			if ( $lead_minutes > 0 && $service_start_ts >= $now_ts ) {
				return new WP_Error(
					'uappt_lead_time',
					sprintf(
						/* translators: %d: 最少提前預約分鐘數 */
						__( '此時段已無法預約，最晚需於服務開始前 %d 分鐘完成預約。', 'ultimate-appointments' ),
						$lead_minutes
					)
				);
			}
			return new WP_Error( 'uappt_slot_passed', __( '此時段已經過了，請重新選擇。', 'ultimate-appointments' ) );
		}

		// 篩出「這個時間點落在班表內」的候選人員，並各自算出要鎖定的時間格
		// （緩衝、顆粒都是各人員自己的設定，必須各自計算，不能共用一份）。
		$eligible = self::build_eligible_staff_cells( $candidates, $service_start_ts, $service_end_ts, $date_ymd, $buf_pre, $buf_post );
		if ( empty( $eligible ) ) {
			return new WP_Error( 'uappt_outside_hours', __( '此時段不在營業時間內，請重新選擇。', 'ultimate-appointments' ) );
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		self::ensure_cells_exist( $eligible );
		$occupied_map = self::lock_cells( $eligible );

		$free_staff_ids = array();
		foreach ( $eligible as $sid => $info ) {
			if ( self::cells_are_free( $occupied_map, $sid, $info['cells'], $info['staff']['capacity'], $requested_units ) ) {
				$free_staff_ids[] = $sid;
			}
		}

		if ( empty( $free_staff_ids ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $staff_requested ) {
				return new WP_Error( 'uappt_staff_unavailable', __( '很抱歉，這位人員的這個時段剛被預約走了，請重新選擇。', 'ultimate-appointments' ) );
			}
			if ( $requested_units > 1 ) {
				return new WP_Error( 'uappt_slot_unavailable', __( '很抱歉，這個時段剩餘名額不足以容納這麼多人，請減少人數或重新選擇時段。', 'ultimate-appointments' ) );
			}
			return new WP_Error( 'uappt_slot_unavailable', __( '很抱歉，這個時段剛被別人預約走了，請重新選擇時段。', 'ultimate-appointments' ) );
		}

		$chosen_id = $staff_requested ? $requested_staff : self::pick_least_booked( $free_staff_ids, $date_ymd );
		$chosen    = $eligible[ $chosen_id ];

		self::set_cells_occupied( $chosen_id, $chosen['cells'], $requested_units );

		$hold_minutes = max( 1, (int) get_option( 'uappt_hold_minutes', 15 ) );
		$now_mysql    = current_time( 'mysql' );
		$expires_at   = self::mysql_from_ts( $now_ts + $hold_minutes * 60 );

		// 報表要用的金額快照：方案價（留空時退回商品目前售價，是同一套算法，
		// 見 UAPPT_Cart::apply_cart_item_price()）× 人數 + 人員指定加價。這裡
		// 只是「當下看起來會收多少」的初始值——真正走過折扣碼／稅之後的實際
		// 金額，會在訂單成立時由 link_to_order() 用訂單項目的金額覆蓋一次。
		// 沒有訂單的手動建單（不勾「建立訂單」那種）會一直停在這個初始快照，
		// 這也是刻意的：那種情境本來就沒有訂單可以回頭問。
		if ( '' !== (string) $settings['price'] ) {
			$base_price = (float) $settings['price'];
		} else {
			$fresh_product = wc_get_product( $product_id );
			$base_price    = $fresh_product ? (float) $fresh_product->get_price() : 0.0;
		}
		$upcharge = self::upcharge_for( $chosen['staff'], $staff_requested );
		$amount   = $base_price * $requested_units + $upcharge;

		$bookings_table = UAPPT_Install::table( 'bookings' );
		$inserted       = $wpdb->insert( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$bookings_table,
			array(
				'staff_id'                => $chosen_id,
				'staff_requested'         => $staff_requested ? 1 : 0,
				'staff_price_adjustment'  => $upcharge,
				'product_id'              => $product_id,
				// 方案名稱是「建立當下」的快照：方案日後被改名或刪除，日曆／提醒／
				// 會員頁仍要顯示客人當初買的是什麼。這是刻意的資料冗餘。
				'plan_key'                => $settings['plan_key'],
				'plan_name'               => $settings['plan_name'],
				'cart_item_key'           => isset( $args['cart_item_key'] ) ? sanitize_text_field( $args['cart_item_key'] ) : null,
				'customer_id'             => isset( $args['customer_id'] ) ? (int) $args['customer_id'] : null,
				'customer_name'           => isset( $args['customer_name'] ) ? sanitize_text_field( $args['customer_name'] ) : null,
				'customer_phone'          => isset( $args['customer_phone'] ) ? sanitize_text_field( $args['customer_phone'] ) : null,
				'service_start'           => self::mysql_from_ts( $service_start_ts ),
				'service_end'             => self::mysql_from_ts( $service_end_ts ),
				'block_start'             => self::mysql_from_ts( $chosen['block_start_ts'] ),
				'block_end'               => self::mysql_from_ts( $chosen['block_end_ts'] ),
				'status'                  => isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : self::STATUS_HELD,
				'expires_at'              => isset( $args['status'] ) && self::STATUS_CONFIRMED === $args['status'] ? null : $expires_at,
				'grid_interval'           => $chosen['interval'],
				'created_by'              => isset( $args['created_by'] ) ? (int) $args['created_by'] : null,
				'created_at'              => $now_mysql,
				'updated_at'              => $now_mysql,
				'assignment_state'        => $assignment_state,
				'kind'                    => self::KIND_BOOKING,
				// 一般預約通常吃一個名額，團體預約（見上面的 $requested_units）
				// 一次吃好幾個。釋放/改期時一律讀這個欄位本身的值（見 release()／
				// reschedule()），不要重算——道理跟時段佔用完全一樣：容量事後被
				// 調整，重算會對不齊，只有紀錄自己存的值靠得住（設計紀律 #4）。
				'occupied_units'          => $requested_units,
				'amount'                  => $amount,
			),
			array( '%d', '%d', '%f', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%f' )
		);

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new WP_Error( 'uappt_insert_failed', __( '建立預約時發生錯誤，請稍後再試。', 'ultimate-appointments' ) );
		}

		$booking_id = (int) $wpdb->insert_id;
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $booking_id;
	}

	/**
	 * 把一筆預約改到新的日期/時段（後台編輯用，客人要求改期時呼叫）。
	 *
	 * 安全性設計：在同一個資料庫交易內，先「暫時」釋放原時段、確認新時段還有
	 * 名額並鎖定，任何一步失敗就整筆 ROLLBACK——連同前面暫時釋放的動作一併
	 * 復原，舊時段完全不受影響。不會出現「舊的已經放掉、新的卻沒搶到」這種
	 * 客人時段整個消失的空窗。
	 *
	 * 人員指派規則：客人當初有指定人員的話，改期只會嘗試同一位人員，該人沒空
	 * 就直接回報失敗（不會偷偷換成別人）；當初沒指定的話，優先嘗試留在原本
	 * 那位人員（客人體驗上比較連續），若他這個新時段沒空，才會在其他候選人員
	 * 中依「當日已排班數最少」重新分配。
	 *
	 * @param int    $booking_id 要改期的預約 ID。
	 * @param string $date_ymd   新日期 (Y-m-d)。
	 * @param string $time_hm    新時段開始時間 (H:i)。
	 * @return true|WP_Error
	 */
	public static function reschedule( $booking_id, $date_ymd, $time_hm ) {
		global $wpdb;

		$booking = self::get( $booking_id );
		if ( ! $booking || ! in_array( $booking['status'], array( self::STATUS_HELD, self::STATUS_CONFIRMED ), true ) ) {
			return new WP_Error( 'uappt_invalid_booking', __( '找不到這筆預約，或目前狀態已無法改期。', 'ultimate-appointments' ) );
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_ymd ) || ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time_hm ) ) {
			return new WP_Error( 'uappt_invalid_slot', __( '選擇的時段格式不正確，請重新選擇。', 'ultimate-appointments' ) );
		}

		// 用商品「目前」的設定計算新時段——時長/緩衝/可服務人員都可能在原本建立
		// 預約之後被商家調整過，改期應該反映最新設定，而不是沿用舊值。
		$settings = UAPPT_Product::get_booking_settings( $booking['product_id'], isset( $booking['plan_key'] ) ? (string) $booking['plan_key'] : '' );
		if ( ! $settings ) {
			return new WP_Error( 'uappt_not_bookable', __( '此商品目前已不開放預約，無法改期，請改用取消後重新建立。', 'ultimate-appointments' ) );
		}

		$old_staff_id    = (int) $booking['staff_id'];
		$staff_requested = ! empty( $booking['staff_requested'] );

		// 客人有指定人員：候選清單就只有原本那一位。沒指定：所有候選人員都可能，
		// 原本那位有空就優先留給他（見下面挑人的地方）。
		$candidates = self::resolve_candidate_staff( $settings, $staff_requested ? $old_staff_id : 0 );

		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}
		if ( empty( $candidates ) ) {
			return new WP_Error( 'uappt_no_staff_available', __( '目前沒有可服務的人員，請稍後再試或聯繫客服。', 'ultimate-appointments' ) );
		}

		$duration = (int) $settings['duration_minutes'];
		$buf_pre  = (int) $settings['buffer_before'];
		$buf_post = (int) $settings['buffer_after'];

		$service_start_ts = self::local_ts( $date_ymd . ' ' . $time_hm . ':00' );
		$service_end_ts   = $service_start_ts + $duration * 60;

		$now_ts = time(); // 真實 UTC 時間戳，需與 local_ts() 的回傳值同基準才能正確比較。
		if ( $service_start_ts < $now_ts ) {
			return new WP_Error( 'uappt_slot_passed', __( '此時段已經過了，請重新選擇。', 'ultimate-appointments' ) );
		}

		$eligible = self::build_eligible_staff_cells( $candidates, $service_start_ts, $service_end_ts, $date_ymd, $buf_pre, $buf_post );
		if ( empty( $eligible ) ) {
			return new WP_Error( 'uappt_outside_hours', __( '此時段不在營業時間內，請重新選擇。', 'ultimate-appointments' ) );
		}

		// 舊時段一律用這筆預約自己儲存的 staff_id / grid_interval / block 範圍推算，
		// 不重讀現在的設定——設定可能在這筆預約建立之後被改過，用當下設定反推
		// 舊時間格會對不齊，導致舊格的佔用永遠還不回去（v1.2.0 已修過的同一類問題）。
		$old_interval       = ! empty( $booking['grid_interval'] ) ? (int) $booking['grid_interval'] : max( 5, (int) get_option( 'uappt_slot_interval_minutes', 15 ) );
		$old_block_start_ts = self::local_ts( $booking['block_start'] );
		$old_block_end_ts   = self::local_ts( $booking['block_end'] );
		$old_cells          = self::grid_cells( $old_block_start_ts, $old_block_end_ts, $old_interval );

		// 這筆紀錄佔了幾個名額，搬到新時段後也要佔一樣多、釋放時也要還一樣多。
		$units = max( 1, (int) $booking['occupied_units'] );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// 上面那些舊格子是用交易外讀的資料算的；鎖住之後若已經被別人改期、換人或取消，
		// 照那份計算去還格子會還錯（見 lock_booking_row()）。
		if ( ! self::same_slot( $booking, self::lock_booking_row( $booking_id ) ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new WP_Error( 'uappt_booking_changed', __( '這筆預約剛被其他操作更新過，請重新整理頁面再試一次。', 'ultimate-appointments' ) );
		}

		// 舊格子也要確保存在並鎖定（正常情況下一定已存在，這裡是防禦性寫法），
		// 跟新格子一起排序後再寫，上鎖順序才會跟 lock_cells() 一致。
		self::ensure_cells_exist( $eligible, $old_staff_id ? array( $old_staff_id => $old_cells ) : array() );

		// 先「暫時」釋放舊格子——若後面所有候選人員都沒空，整筆交易會 ROLLBACK，
		// 這個釋放動作也會一併復原，舊時段完全不受影響。
		if ( $old_staff_id && ! empty( $old_cells ) ) {
			self::set_cells_occupied( $old_staff_id, $old_cells, -$units );
		}

		// 釋放舊格子後才重新查詢佔用狀態：若某位候選人員剛好就是原本那位，這一步
		// 讓 occupied 少算了這筆自己的佔用（容量 > 1 時是「減 1」，不是變成 0——
		// 那一格可能還有其他筆預約佔著），能正確反映「這段其實是他自己的」。
		$occupied_map = self::lock_cells( $eligible );

		$free_ids = array();
		foreach ( $eligible as $sid => $info ) {
			if ( $staff_requested && $sid !== $old_staff_id ) {
				continue; // 客人有指定人員時，只考慮那一位。
			}
			if ( self::cells_are_free( $occupied_map, $sid, $info['cells'], $info['staff']['capacity'], $units ) ) {
				$free_ids[] = $sid;
			}
		}

		// 原本的人員有空就留給他；沒空才在其他人裡挑當日排得最少的（跟 create_hold()
		// 同一套平均工作量的規則）。v3.0.2 以前是照清單順序挑第一位，跟這裡的說明不符。
		$chosen_id = null;
		if ( in_array( $old_staff_id, $free_ids, true ) ) {
			$chosen_id = $old_staff_id;
		} elseif ( $free_ids ) {
			$chosen_id = self::pick_least_booked( $free_ids, $date_ymd );
		}

		if ( null === $chosen_id ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $staff_requested ) {
				return new WP_Error( 'uappt_staff_unavailable', __( '很抱歉，這位人員在新時段沒有空檔，請重新選擇。', 'ultimate-appointments' ) );
			}
			return new WP_Error( 'uappt_slot_unavailable', __( '很抱歉，這個時段剛被別人預約走了，請重新選擇時段。', 'ultimate-appointments' ) );
		}

		$chosen = $eligible[ $chosen_id ];
		self::set_cells_occupied( $chosen_id, $chosen['cells'], $units );

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			UAPPT_Install::table( 'bookings' ),
			array(
				'staff_id'               => $chosen_id,
				'staff_price_adjustment' => self::upcharge_for( $chosen['staff'], $staff_requested ),
				'service_start'          => self::mysql_from_ts( $service_start_ts ),
				'service_end'            => self::mysql_from_ts( $service_end_ts ),
				'block_start'            => self::mysql_from_ts( $chosen['block_start_ts'] ),
				'block_end'              => self::mysql_from_ts( $chosen['block_end_ts'] ),
				'grid_interval'          => $chosen['interval'],
				'updated_at'             => current_time( 'mysql' ),
			),
			array( 'id' => (int) $booking_id ),
			array( '%d', '%f', '%s', '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new WP_Error( 'uappt_update_failed', __( '改期時發生錯誤，請稍後再試。', 'ultimate-appointments' ) );
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return true;
	}

	/**
	 * 更換一筆預約的服務人員（後台用：確認待分派人選、或客服直接改派）。
	 *
	 * 直接照抄 reschedule() 的交易樣式——「舊時段不可消失」這條保證兩者是同一件事：
	 * 先暫時釋放舊格子、確認新人員整段真的空著才佔用，任何一步失敗就整筆 ROLLBACK，
	 * 連同暫時釋放一併復原，原本的預約完全不受影響。差別只在於這裡的「新」是換人，
	 * 不是換時段——service_start/service_end 維持原樣，只有 staff_id 與跟著人員
	 * 走的欄位（緩衝顆粒可能不同、指定加價）會變。
	 *
	 * 刻意保留的邊界決策（都已與店家確認過）：
	 * - **`staff_requested` 不會被這支方法改動**。客人原本指定 A、後台改成 B 之後，
	 *   日後改期只會嘗試 B——這是刻意的：B 已經是店家承諾的人選，不是「系統隨便
	 *   選的」，reschedule() 沒理由自作主張換回別人。不要把這個行為當成 bug 修掉。
	 * - **不會自動調整訂單金額**。人員指定加價的差額只更新 staff_price_adjustment
	 *   這個紀錄欄位，訂單金額由管理者自己處理——客人多半已經付款，靜默改金額
	 *   會變成要退補差額，這點跟現有的 reschedule() 是同一個處理方式。
	 * - 只能選這項服務目前候選名單內的人員（get_booking_settings() 的 staff_ids），
	 *   排一個不會做這項服務的人上去是更糟的問題。
	 *
	 * @param int $booking_id   預約 ID。
	 * @param int $new_staff_id 新的服務人員 ID。
	 * @return true|WP_Error
	 */
	public static function reassign_staff( $booking_id, $new_staff_id ) {
		global $wpdb;

		$new_staff_id = (int) $new_staff_id;
		if ( ! $new_staff_id ) {
			return new WP_Error( 'uappt_invalid_staff', __( '請選擇一位服務人員。', 'ultimate-appointments' ) );
		}

		$booking = self::get( $booking_id );
		if ( ! $booking || ! in_array( $booking['status'], array( self::STATUS_HELD, self::STATUS_CONFIRMED ), true ) ) {
			return new WP_Error( 'uappt_invalid_booking', __( '找不到這筆預約，或目前狀態已無法更換服務人員。', 'ultimate-appointments' ) );
		}

		$old_staff_id = (int) $booking['staff_id'];

		// 用商品「目前」的設定驗證候選名單，跟 reschedule() 一致的理由：人員名單
		// 可能在這筆預約建立之後被商家調整過。時段本身不變，不需要重算時長/緩衝。
		$settings = UAPPT_Product::get_booking_settings( $booking['product_id'], isset( $booking['plan_key'] ) ? (string) $booking['plan_key'] : '' );
		if ( ! $settings ) {
			return new WP_Error( 'uappt_not_bookable', __( '此商品目前已不開放預約，無法更換服務人員。', 'ultimate-appointments' ) );
		}

		// resolve_candidate_staff() 已經做了「在不在候選名單內」與「是否在職」的
		// 檢查，不在這裡另外重寫一套——理由跟 reschedule() 沿用它是一樣的：同一個
		// 問題只能有一套判斷。
		$candidates = self::resolve_candidate_staff( $settings, $new_staff_id );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}
		$new_staff = $candidates[0];

		// 新人員＝舊人員：管理者只是要「確認」暫定的人選，不動任何格子，
		// 純粹把待分派標記清掉。這是待分派流程最常走的一條路，不值得為它
		// 開一次資料庫交易。
		if ( $new_staff_id === $old_staff_id ) {
			if ( self::ASSIGNMENT_PENDING !== $booking['assignment_state'] ) {
				return true; // 沒有東西要改，視為成功（冪等，重複點擊也不會出錯）。
			}

			$confirmed = $wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				UAPPT_Install::table( 'bookings' ),
				array(
					'assignment_state' => '',
					'updated_at'       => current_time( 'mysql' ),
				),
				array( 'id' => (int) $booking_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			return false !== $confirmed ? true : new WP_Error( 'uappt_update_failed', __( '確認人選時發生錯誤，請稍後再試。', 'ultimate-appointments' ) );
		}

		// 舊格子一律用這筆預約自己存的 grid_interval / block 範圍推算，不重讀現在的
		// 設定——設定可能被改過，用當下設定反推舊時間格會對不齊（見 reschedule()
		// 的同一段說明，這是 v1.2.0 修過的同一類問題）。
		$old_interval       = ! empty( $booking['grid_interval'] ) ? (int) $booking['grid_interval'] : max( 5, (int) get_option( 'uappt_slot_interval_minutes', 15 ) );
		$old_block_start_ts = self::local_ts( $booking['block_start'] );
		$old_block_end_ts   = self::local_ts( $booking['block_end'] );
		$old_cells          = self::grid_cells( $old_block_start_ts, $old_block_end_ts, $old_interval );

		// 換人也要照這筆紀錄原本佔的名額數搬過去，不能寫死 1。
		$units = max( 1, (int) $booking['occupied_units'] );

		$service_start_ts = self::local_ts( $booking['service_start'] );
		$service_end_ts   = self::local_ts( $booking['service_end'] );

		// 用新人員的班表/請假/顆粒重新算格子——新人員的緩衝顆粒、跨日班表都可能
		// 跟舊人員不同，不能沿用舊人員的 $old_cells。date_ymd 傳服務開始時刻的
		// 當地日曆日期：get_business_windows() 內部本來就會一併檢查前一天，
		// 覆蓋得到「這個時刻其實屬於前一天跨日班」的情況，跟客人當初挑日期時
		// 走的是同一條解析路徑。
		$eligible = self::build_eligible_staff_cells(
			array( $new_staff ),
			$service_start_ts,
			$service_end_ts,
			wp_date( 'Y-m-d', $service_start_ts ),
			(int) $settings['buffer_before'],
			(int) $settings['buffer_after']
		);
		if ( empty( $eligible ) ) {
			return new WP_Error( 'uappt_outside_hours', __( '這位人員在這個時段不在營業時間內，請重新選擇。', 'ultimate-appointments' ) );
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// 同 reschedule()：鎖住之後確認舊格子的計算還成立。
		if ( ! self::same_slot( $booking, self::lock_booking_row( $booking_id ) ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new WP_Error( 'uappt_booking_changed', __( '這筆預約剛被其他操作更新過，請重新整理頁面再試一次。', 'ultimate-appointments' ) );
		}

		// 舊格子跟新格子一起排序後再寫（照抄 reschedule() 的同一段）。
		self::ensure_cells_exist( $eligible, $old_staff_id ? array( $old_staff_id => $old_cells ) : array() );

		// 先「暫時」釋放舊格子——若新人員這個時段沒空，整筆交易會 ROLLBACK，
		// 這個釋放動作也會一併復原，原本的預約完全不受影響。
		if ( $old_staff_id && ! empty( $old_cells ) ) {
			self::set_cells_occupied( $old_staff_id, $old_cells, -$units );
		}

		$occupied_map = self::lock_cells( $eligible );

		// 只有一位候選人員（新人員本人），不需要像 reschedule() 那樣迴圈挑選——
		// 直接檢查他這個時段空不空。
		$new_staff_id_key = array_key_first( $eligible );
		$chosen_id        = self::cells_are_free( $occupied_map, $new_staff_id_key, $eligible[ $new_staff_id_key ]['cells'], $eligible[ $new_staff_id_key ]['staff']['capacity'], $units )
			? $new_staff_id_key
			: null;

		if ( null === $chosen_id ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new WP_Error( 'uappt_staff_unavailable', __( '這位人員在這個時段沒有空檔，請重新選擇。', 'ultimate-appointments' ) );
		}

		$chosen = $eligible[ $chosen_id ];
		self::set_cells_occupied( $chosen_id, $chosen['cells'], $units );

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			UAPPT_Install::table( 'bookings' ),
			array(
				'staff_id'               => $chosen_id,
				// 後台換人不會改 staff_requested（見上面的邊界決策），所以這裡
				// 讀這筆預約原本的值：客人當初沒有指定，換了人也不該突然開始
				// 收指定加價。
				'staff_price_adjustment' => self::upcharge_for( $chosen['staff'], ! empty( $booking['staff_requested'] ) ),
				'block_start'            => self::mysql_from_ts( $chosen['block_start_ts'] ),
				'block_end'              => self::mysql_from_ts( $chosen['block_end_ts'] ),
				'grid_interval'          => $chosen['interval'],
				'assignment_state'       => self::ASSIGNMENT_REASSIGNED,
				'updated_at'             => current_time( 'mysql' ),
			),
			array( 'id' => (int) $booking_id ),
			array( '%d', '%f', '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new WP_Error( 'uappt_update_failed', __( '更換服務人員時發生錯誤，請稍後再試。', 'ultimate-appointments' ) );
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return true;
	}

	/**
	 * 把已確認的預約標記為已完成（客人已實際到場完成療程）。
	 *
	 * 只更新狀態，刻意不歸還時間格佔用——那段時間確實已經被使用過，
	 * 不該重新開放給別人預約。
	 *
	 * @param int $booking_id 預約 ID。
	 * @return bool
	 */
	public static function complete( $booking_id ) {
		global $wpdb;

		$booking = self::get( $booking_id );
		if ( ! $booking || self::STATUS_CONFIRMED !== $booking['status'] ) {
			return false;
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			UAPPT_Install::table( 'bookings' ),
			array(
				'status'     => self::STATUS_COMPLETED,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $booking_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		// 耗材扣帳：**狀態真的寫進去之後**才扣，而且扣不到也不會讓完成失敗
		// ——服務已經做了，因為庫存記錄的問題把「標記完成」擋下來是本末倒置
		// （庫存扣成負數本身就是「該去盤點了」的訊號）。
		//
		// ⚠️ 這裡跟 revert_to_confirmed() 的回沖**必須成對存在**，漏掉一邊
		// 就會每還原一次少一份庫存，而且沒有任何地方會報錯。
		UAPPT_Consumable::consume_for_booking( $booking );

		return true;
	}

	/**
	 * 標記客人未到（no-show）。只能從 confirmed 轉，而且服務時間要已經過了——
	 * 前者避免把一筆根本還沒發生（held）或已經處理過（cancelled/completed）
	 * 的預約誤標成未到；後者是防手滑：服務都還沒開始，不可能已經知道客人
	 * 沒來。
	 *
	 * 刻意**不動 `slot_grid`**，這是跟 release() 本質不同的動作——客人沒來，
	 * 但那個時段是真的被這筆預約佔用掉了（人力在等，沒辦法臨時接別的客人），
	 * 而且時間已經過去，釋放一個已經過去的時段沒有意義，也沒有任何後續預約
	 * 會用到那個已經流逝的名額。不要複用 release()。
	 *
	 * @param int $booking_id 預約 ID。
	 * @return bool
	 */
	public static function mark_no_show( $booking_id ) {
		global $wpdb;

		$booking = self::get( $booking_id );
		if ( ! $booking || self::STATUS_CONFIRMED !== $booking['status'] ) {
			return false;
		}
		if ( self::local_ts( $booking['service_start'] ) > time() ) {
			return false;
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			UAPPT_Install::table( 'bookings' ),
			array(
				'status'     => self::STATUS_NO_SHOW,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $booking_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * 把「已完成」或「未到」還原成「已確認」——標錯了要有路可以回頭。
	 *
	 * ⚠️ **只收這兩個狀態，這是這支方法能安全存在的唯一前提。** completed 與
	 * no_show 都是「服務時段結束後蓋棺論定」的終止狀態，兩者都刻意沒有動
	 * `slot_grid`（見 complete()／mark_no_show() 的說明），所以還原只是把狀態
	 * 字串改回去，不需要重新搶格子，也不會跟任何人競爭名額。
	 *
	 * `cancelled`／`expired` 正好相反：它們已經呼叫過 release() 把格子還回去了，
	 * 那個時段可能早就被別的客人買走。把它們「還原」成 confirmed 會憑空生出一筆
	 * 沒有對應佔用的預約，是真正的超賣——所以一律拒絕，要復活那種預約只能重新
	 * 建立（走完整的鎖格交易）。`held` 也不收：它是「還在進行中」而不是「已經
	 * 結束」，沒有還原的語意。
	 *
	 * @param int $booking_id 預約 ID。
	 * @return bool
	 */
	public static function revert_to_confirmed( $booking_id ) {
		global $wpdb;

		$booking = self::get( $booking_id );
		if ( ! $booking || self::KIND_BOOKING !== $booking['kind'] ) {
			return false;
		}
		if ( ! in_array( $booking['status'], array( self::STATUS_COMPLETED, self::STATUS_NO_SHOW ), true ) ) {
			return false;
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			UAPPT_Install::table( 'bookings' ),
			array(
				'status'     => self::STATUS_CONFIRMED,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $booking_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		// 耗材回沖，跟 complete() 的扣帳成對。只有從「已完成」還原回來時才
		// 真的會有東西可回沖——「未到」本來就沒扣過（人沒來就沒用到耗材），
		// revert_for_booking() 自己會用 is_consumed() 判斷，這裡不需要再依
		// 狀態分支一次。
		UAPPT_Consumable::revert_for_booking( $booking );

		return true;
	}

	/**
	 * 更新預約的聯絡資訊/內部備註/金額（改期以外的編輯欄位，不影響時段鎖定）。
	 *
	 * @param int   $booking_id 預約 ID。
	 * @param array $fields 允許的 key：customer_name, customer_phone, note, staff_note, amount；只會更新有出現在陣列裡的欄位。
	 *                       ⚠️ staff_note 跟 note 是刻意分開的兩個欄位（v2.16.0）：note 是後台
	 *                       管理者的內部備註，staff_note 是前台員工中心給服務人員自己寫的備註，
	 *                       共用一個欄位會讓兩邊互相覆蓋掉對方寫的內容。
	 * @return bool
	 */
	public static function update_details( $booking_id, $fields ) {
		global $wpdb;

		$update  = array();
		$formats = array();

		if ( array_key_exists( 'customer_name', $fields ) ) {
			$update['customer_name'] = sanitize_text_field( $fields['customer_name'] );
			$formats[]                = '%s';
		}
		if ( array_key_exists( 'customer_phone', $fields ) ) {
			$update['customer_phone'] = sanitize_text_field( $fields['customer_phone'] );
			$formats[]                 = '%s';
		}
		if ( array_key_exists( 'note', $fields ) ) {
			$update['note'] = sanitize_textarea_field( $fields['note'] );
			$formats[]       = '%s';
		}
		if ( array_key_exists( 'staff_note', $fields ) ) {
			$update['staff_note'] = sanitize_textarea_field( $fields['staff_note'] );
			$formats[]             = '%s';
		}
		// 金額快照（報表唯一的營收來源）。開放這個欄位是為了現場櫃檯的自訂
		// 金額——熟客折扣、湊整數、招待。格式跟 create_hold()／link_to_order()
		// 一致用 %f，三個寫入點對同一個欄位不能各用各的型別。
		//
		// ⚠️ **有訂單的預約不該直接走這裡改金額**：`link_to_order()` 會用訂單
		// 項目的實際金額覆蓋這個快照，訂單那邊才是事實來源，只改這裡會讓兩個
		// 數字打架。呼叫端（UAPPT_Admin::handle_update_booking_amount()）負責
		// 分流：有訂單就先改訂單項目再同步回來，沒訂單才直接寫這裡。
		if ( array_key_exists( 'amount', $fields ) ) {
			$update['amount'] = (float) $fields['amount'];
			$formats[]         = '%f';
		}

		if ( empty( $update ) ) {
			return true;
		}

		$update['updated_at'] = current_time( 'mysql' );
		$formats[]             = '%s';

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			UAPPT_Install::table( 'bookings' ),
			$update,
			array( 'id' => (int) $booking_id ),
			$formats,
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * 將暫留轉為已確認（付款完成）。
	 *
	 * @param int $booking_id 預約 ID。
	 * @param int $order_id 訂單 ID。
	 * @param int $order_item_id 訂單項目 ID。
	 */
	public static function confirm( $booking_id, $order_id = 0, $order_item_id = 0 ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// 同 release()：鎖住之後才判斷。交易外讀的話，剛好被逾時排程釋放的暫留會被
		// 這裡改回 confirmed——格子已經還回去了，等於一筆沒有佔用的確認預約。回傳
		// false 讓 confirm_or_relock() 改走補鎖。
		$booking = self::lock_booking_row( $booking_id );
		if ( ! $booking || ! in_array( $booking['status'], array( self::STATUS_HELD, self::STATUS_CONFIRMED ), true ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return false;
		}

		$fields  = array(
			'status'     => self::STATUS_CONFIRMED,
			'expires_at' => null,
			'updated_at' => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s', '%s' );

		if ( $order_id ) {
			$fields['order_id'] = (int) $order_id;
			$formats[]          = '%d';
		}
		if ( $order_item_id ) {
			$fields['order_item_id'] = (int) $order_item_id;
			$formats[]               = '%d';
		}

		$ok = false !== $wpdb->update( $table, $fields, array( 'id' => (int) $booking_id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $ok ? 'COMMIT' : 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $ok;
	}


	/**
	 * 建立一段「時段佔用」：把某位人員的某段時間卡住，但不是客人的預約。
	 *
	 * 用途是把**非網站來源的人力消耗**記進同一本帳——教育訓練、休息、私事，或
	 * 門市現場客人臨時佔掉的人力。系統原本只看得到網站訂單，排出來的班就會跟
	 * 現場實況對不上；有了這個，前台就不會把現場已經佔掉的時段再賣一次。
	 *
	 * 走的是跟 create_hold() 完全相同的交易樣式（ensure → lock → 檢查 → 佔用 →
	 * COMMIT/ROLLBACK），所以一樣不會超賣；差別只在於沒有商品、沒有客人、沒有
	 * 訂單，而且可以一次吃掉好幾個名額（$units）。
	 *
	 * @param array $args {
	 *     @type int    $staff_id  人員 ID。
	 *     @type string $date_ymd  日期 Y-m-d。
	 *     @type string $start_hm  開始時間 H:i。
	 *     @type string $end_hm    結束時間 H:i；比開始早代表跨到隔天（例如 22:00→02:00）。
	 *     @type int    $units     要佔用幾個名額；0 或省略代表「佔滿」（＝該人員當下的容量）。
	 *     @type string $note      備註（會顯示在後台清單與日檢視）。
	 *     @type int    $created_by 建立者 user ID。
	 * }
	 * @return int|WP_Error 佔用紀錄 ID。
	 */
	public static function create_block( $args ) {
		global $wpdb;

		$staff_id = isset( $args['staff_id'] ) ? (int) $args['staff_id'] : 0;
		$date_ymd = isset( $args['date_ymd'] ) ? sanitize_text_field( $args['date_ymd'] ) : '';
		$start_hm = isset( $args['start_hm'] ) ? sanitize_text_field( $args['start_hm'] ) : '';
		$end_hm   = isset( $args['end_hm'] ) ? sanitize_text_field( $args['end_hm'] ) : '';

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_ymd )
			|| ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $start_hm )
			|| ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $end_hm )
		) {
			return new WP_Error( 'uappt_invalid_block', __( '日期或時間格式不正確。', 'ultimate-appointments' ) );
		}

		$staff = UAPPT_Staff::get( $staff_id );
		if ( ! $staff || 'active' !== $staff['status'] ) {
			return new WP_Error( 'uappt_invalid_staff', __( '找不到這位人員，或這位人員已停用。', 'ultimate-appointments' ) );
		}

		$start_ts = self::local_ts( $date_ymd . ' ' . $start_hm . ':00' );
		$end_ts   = self::local_ts( $date_ymd . ' ' . $end_hm . ':00' );

		// 結束時間比開始早＝跨到隔天（深夜班的休息時段會用到），跟營業時間的
		// 跨日寫法一致。
		if ( $end_ts <= $start_ts ) {
			$end_ts += DAY_IN_SECONDS;
		}

		$capacity = max( 1, (int) $staff['capacity'] );
		$units    = isset( $args['units'] ) ? (int) $args['units'] : 0;
		// 0 或超過容量都視為「佔滿」——訓練/休息這類情境整個人都不在，
		// 一次把容量吃光才是正確語意。
		$units = ( $units < 1 || $units > $capacity ) ? $capacity : $units;

		$interval = UAPPT_Staff::get_slot_interval( $staff );
		$cells    = self::grid_cells( $start_ts, $end_ts, $interval );
		if ( empty( $cells ) ) {
			return new WP_Error( 'uappt_invalid_block', __( '這段時間長度不足一個時間格，請重新設定。', 'ultimate-appointments' ) );
		}

		// build_eligible_staff_cells() 會檢查營業時間，但時段佔用刻意**不檢查**——
		// 「這個人今天沒排班，但我要把這段時間標成訓練」是合理的需求，不該被
		// 營業時間擋下來。自己組出 lock_cells()/ensure_cells_exist() 要的結構。
		$eligible = array(
			$staff_id => array(
				'staff'          => $staff,
				'interval'       => $interval,
				'block_start_ts' => $start_ts,
				'block_end_ts'   => $end_ts,
				'cells'          => $cells,
			),
		);

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		self::ensure_cells_exist( $eligible );
		$occupied_map = self::lock_cells( $eligible );

		if ( ! self::cells_are_free( $occupied_map, $staff_id, $cells, $capacity, $units ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new WP_Error(
				'uappt_block_unavailable',
				__( '這段時間已經有預約或其他佔用，名額不足，請先處理既有預約或縮小佔用範圍。', 'ultimate-appointments' )
			);
		}

		self::set_cells_occupied( $staff_id, $cells, $units );

		$now_mysql = current_time( 'mysql' );
		$inserted  = $wpdb->insert( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			UAPPT_Install::table( 'bookings' ),
			array(
				'staff_id'       => $staff_id,
				// product_id 是 NOT NULL，時段佔用沒有商品，用 0 當哨兵值；
				// 所有顯示路徑一律先看 kind，不會拿 0 去查商品。
				'product_id'     => 0,
				'kind'           => self::KIND_BLOCK,
				'occupied_units' => $units,
				'service_start'  => self::mysql_from_ts( $start_ts ),
				'service_end'    => self::mysql_from_ts( $end_ts ),
				'block_start'    => self::mysql_from_ts( $start_ts ),
				'block_end'      => self::mysql_from_ts( $end_ts ),
				// 佔用一建立就是定案，沒有「等付款」的概念，直接用 confirmed，
				// 這樣 release() 的狀態檢查與日檢視的顯示都不用另外開特例。
				'status'         => self::STATUS_CONFIRMED,
				'grid_interval'  => $interval,
				'note'           => isset( $args['note'] ) ? sanitize_text_field( $args['note'] ) : null,
				'created_by'     => isset( $args['created_by'] ) ? (int) $args['created_by'] : null,
				'created_at'     => $now_mysql,
				'updated_at'     => $now_mysql,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new WP_Error( 'uappt_insert_failed', __( '建立時段佔用時發生錯誤，請稍後再試。', 'ultimate-appointments' ) );
		}

		$block_id = (int) $wpdb->insert_id;
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $block_id;
	}

	/**
	 * 取得某位人員未來的時段佔用（後台人力資源頁列出用）。
	 *
	 * @param int $staff_id 人員 ID。
	 * @param int $limit    最多幾筆。
	 * @return array
	 */
	public static function get_upcoming_blocks( $staff_id, $limit = 30 ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE staff_id = %d AND kind = %s AND status = %s AND service_end >= %s
				 ORDER BY service_start ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(int) $staff_id,
				self::KIND_BLOCK,
				self::STATUS_CONFIRMED,
				current_time( 'mysql' ),
				(int) $limit
			),
			ARRAY_A
		);
	}

	/**
	 * 釋放預約（取消 / 逾時），並歸還時間格佔用。具備冪等性：非 held/confirmed 狀態不重複釋放。
	 *
	 * @param int    $booking_id 預約 ID。
	 * @param string $new_status 釋放後狀態：cancelled 或 expired。
	 * @return bool
	 */
	public static function release( $booking_id, $new_status = self::STATUS_CANCELLED ) {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// 狀態在鎖住之後才判斷（見 lock_booking_row()）：冪等不能只靠「先讀再改」，
		// 兩個同時的釋放都會讀到 held。
		$booking = self::lock_booking_row( $booking_id );
		if ( ! $booking || ! in_array( $booking['status'], array( self::STATUS_HELD, self::STATUS_CONFIRMED ), true ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return false;
		}

		if ( ! empty( $booking['staff_id'] ) ) {
			// 一律用這筆預約「建立當下」實際使用的顆粒（grid_interval）來推算要歸還
			// 哪些時間格，不重新讀取現在的設定——設定可能在暫留建立之後被改掉，
			// 兩邊算出的時間格邊界對不齊就會導致佔用還不回去、時段被永久卡死。
			$interval       = ! empty( $booking['grid_interval'] ) ? (int) $booking['grid_interval'] : max( 5, (int) get_option( 'uappt_slot_interval_minutes', 15 ) );
			$block_start_ts = self::local_ts( $booking['block_start'] );
			$block_end_ts   = self::local_ts( $booking['block_end'] );
			$cells          = self::grid_cells( $block_start_ts, $block_end_ts, $interval );

			if ( ! empty( $cells ) ) {
				// occupied 是計數器（同時可服務人數 > 1 時，同一格可能有好幾筆預約
				// 共用），只能減自己這一筆的份，不能整個歸零——那格可能還有別人的
				// 預約佔著。要減多少一律讀這筆紀錄自己存的 occupied_units（時段佔用
				// 可能一次吃掉整個容量），跟 grid_interval 是同一個道理：容量之後被
				// 改，釋放才不會還錯（設計紀律 #4）。set_cells_occupied() 的
				// GREATEST(0, ...) 保證不會因為重複釋放而變成負數。
				$units = max( 1, (int) $booking['occupied_units'] );
				self::set_cells_occupied( (int) $booking['staff_id'], $cells, -$units );
			}
		}

		$table   = UAPPT_Install::table( 'bookings' );
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$table,
			array(
				'status'     => $new_status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $booking_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// 狀態寫不進去就整筆退回：格子已經還了、狀態卻還是 held／confirmed，
		// 這一筆會變成「佔著名額的紀錄沒有佔用」，下一個人就把它賣掉了。
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return false;
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return true;
	}

	/**
	 * 釋放所有已逾時的暫留（由 Cron 呼叫）。
	 *
	 * @return int 已釋放的筆數。
	 */
	public static function release_expired_holds() {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::STATUS_HELD,
				current_time( 'mysql' )
			)
		);

		$count = 0;
		foreach ( $ids as $id ) {
			if ( self::release( (int) $id, self::STATUS_EXPIRED ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * 取消「訂單逾時仍未付款」的訂單，連帶釋放它掛著的預約時段（由 Cron 呼叫）。
	 *
	 * 這是待付款訂單的安全網，補上 WooCommerce 自己顧不到的兩個洞：
	 *
	 * 1. WooCommerce 核心的 `wc_cancel_unpaid_orders()` 只處理 `pending`，
	 *    完全不管 `on-hold`——綠界的 ATM 虛擬帳號／超商代碼這類「先給付款
	 *    資訊、之後才真的付款」的方式，訂單一開始就是 `on-hold`，核心那支
	 *    永遠不會碰它，時段就這樣一直鎖著。
	 * 2. 就算是 `pending`，核心那支還有前提：`woocommerce_hold_stock_minutes`
	 *    要大於 0、`woocommerce_manage_stock` 要是 `yes`。純服務網站沒有
	 *    實體庫存，店家哪天把「管理庫存」關掉，連信用卡放棄付款的清理都會
	 *    跟著停掉，而且不會有任何警示——這個時間點完全是一個跟預約邏輯
	 *    無關的全域設定，外掛不該把自己的正確性賭在它身上。
	 *
	 * 逾時只取消訂單（`$order->update_status('cancelled', ...)`），不直接呼叫
	 * `release()`——訂單一旦變成 cancelled，會觸發 `UAPPT_Order::handle_release()`
	 * （掛在 `woocommerce_order_status_cancelled`），走的是跟「客人自己取消」
	 * 或「管理者在訂單頁取消」完全相同的既有路徑，不需要另外重寫一份釋放邏輯，
	 * 狀態語意也一致（都是 CANCELLED，不是 EXPIRED——這是一筆真實訂單被取消，
	 * 不是購物車暫留過期）。
	 *
	 * 逾時時間刻意做成獨立設定（`uappt_pending_order_timeout_hours`），不沿用
	 * 「暫留鎖定時間」或 WooCommerce 的庫存持有分鐘數：這裡要保護的是已經
	 * 送出的真實訂單，不是購物車，時間尺度天差地遠（信用卡放棄付款可能幾分鐘
	 * 內就該收回，ATM／超商代碼合法的付款期卻是好幾天），預設值故意抓長
	 * （72 小時），只當最後一道安全網，不搶在店家自己的付款期限之前誤殺。
	 *
	 * @return int 已取消的訂單數。
	 */
	public static function release_stale_pending_orders() {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		$timeout_hours = max( 1, (int) get_option( 'uappt_pending_order_timeout_hours', 72 ) );
		$cutoff_ts     = time() - $timeout_hours * HOUR_IN_SECONDS;

		// 用 DISTINCT order_id（而不是逐筆預約處理）：同一張訂單可能掛著不只
		// 一筆預約，取消訂單一次就會透過 handle_release() 把底下所有預約一起
		// 釋放，逐筆處理只會對同一張訂單重複呼叫 update_status()。
		$order_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT DISTINCT order_id FROM {$table} WHERE status = %s AND order_id IS NOT NULL", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::STATUS_HELD
			)
		);

		$count = 0;
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order || ! in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) {
				continue;
			}

			// 真實 UTC 時間戳跟 time() 同基準，不用裸 strtotime()（設計紀律 #1）。
			$created = $order->get_date_created();
			if ( ! $created || $created->getTimestamp() > $cutoff_ts ) {
				continue;
			}

			$order->update_status(
				'cancelled',
				__( '訂單逾時仍未完成付款，系統自動取消，預約時段已釋放。', 'ultimate-appointments' )
			);
			++$count;
		}

		return $count;
	}

	/**
	 * 依購物車項目 key 釋放暫留（移除購物車項目時呼叫）。
	 *
	 * @param string $cart_item_key 購物車項目 key。
	 */
	public static function release_by_cart_item_key( $cart_item_key ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE cart_item_key = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$cart_item_key,
				self::STATUS_HELD
			)
		);

		if ( $id ) {
			self::release( (int) $id, self::STATUS_CANCELLED );
		}
	}

	/**
	 * 依訂單釋放所有相關預約（訂單取消/失敗/退款時呼叫）。
	 *
	 * @param int    $order_id 訂單 ID。
	 * @param string $new_status 釋放後狀態。
	 */
	public static function release_by_order( $order_id, $new_status = self::STATUS_CANCELLED ) {
		foreach ( self::get_by_order( $order_id ) as $booking ) {
			self::release( (int) $booking['id'], $new_status );
		}
	}

	/**
	 * 依訂單將所有相關預約確認（付款完成時呼叫）。
	 *
	 * 因為暫留鎖定只保留短短幾分鐘（預設 15 分），客人若在外部金流頁面（綠界）
	 * 停留較久，暫留可能已被 cron 逾時釋放、甚至被別人搶走同一時段。這裡在確認
	 * 階段做一次「補救」：若原暫留已失效，嘗試用相同時段重新鎖定為已確認；
	 * 如果那個時段已經被別人拿走，回傳衝突旗標，交由呼叫端（訂單事件）記錄
	 * 訂單備註並提醒商家人工處理，而不是靜默失敗。
	 *
	 * @param int  $order_id         訂單 ID。
	 * @param bool $relock_cancelled 訂單是不是剛從 cancelled／failed／refunded 回到已付款——
	 *                               只有這種時候，cancelled 的預約才是「被訂單取消連帶釋放」
	 *                               的，該補鎖回來。見 confirm_or_relock()。
	 * @return array 每筆結果：['old_booking_id','booking_id','ok'=>bool,'relocked'=>bool].
	 */
	public static function confirm_by_order( $order_id, $relock_cancelled = false ) {
		$results = array();

		foreach ( self::get_by_order( $order_id ) as $booking ) {
			$results[] = self::confirm_or_relock( $booking, $order_id, $relock_cancelled );
		}

		return $results;
	}

	/**
	 * 嘗試確認單筆預約；若原暫留已失效則嘗試用同一時段重新鎖定。
	 *
	 * ⚠️ **只有兩種失效該補鎖**（v3.0.1）：
	 *
	 * - `expired`：購物車暫留逾時，客人卻在外部金流頁完成了付款——這就是補鎖存在的理由。
	 * - `cancelled`，**而且**訂單是從取消／失敗／退款回到已付款（`$relock_cancelled`）：
	 *   那批是 UAPPT_Order::handle_release() 跟著訂單一起釋放的（例如 ATM 逾時被自動
	 *   取消、客人隔天還是去繳了）。
	 *
	 * 其他一律不碰。以前是「held／confirmed 以外都補鎖」，而訂單改「完成」也會走到這裡：
	 * 已標完成的預約被拿去補鎖（容量 1 時鎖不到，在訂單留下「客人已付款卻沒有時段」的
	 * 假警報）；管理者單獨取消過的那一筆會**復活、重新佔用時段**。
	 *
	 * @param array $booking          預約紀錄。
	 * @param int   $order_id         訂單 ID。
	 * @param bool  $relock_cancelled 見 confirm_by_order()。
	 * @return array
	 */
	protected static function confirm_or_relock( $booking, $order_id, $relock_cancelled = false ) {
		$result = array(
			'old_booking_id' => (int) $booking['id'],
			'booking_id'     => (int) $booking['id'],
			'ok'             => true,
			'relocked'       => false,
		);

		if ( in_array( $booking['status'], array( self::STATUS_HELD, self::STATUS_CONFIRMED ), true ) ) {
			if ( self::confirm( (int) $booking['id'], $order_id, (int) $booking['order_item_id'] ) ) {
				return $result;
			}
			// 讀到 held 之後、鎖住之前被釋放了（confirm() 回 false）：用最新狀態重新判斷。
			$booking = self::get( (int) $booking['id'] );
			if ( ! $booking ) {
				$result['ok'] = false;
				return $result;
			}
		}

		$relockable = self::STATUS_EXPIRED === $booking['status']
			|| ( $relock_cancelled && self::STATUS_CANCELLED === $booking['status'] );
		if ( ! $relockable ) {
			return $result;
		}

		$start_ts = self::local_ts( $booking['service_start'] );

		$new_id = self::create_hold(
			array(
				'product_id'        => $booking['product_id'],
				'plan_key'          => isset( $booking['plan_key'] ) ? (string) $booking['plan_key'] : '',
				'date_ymd'          => wp_date( 'Y-m-d', $start_ts ),
				'time_hm'           => wp_date( 'H:i', $start_ts ),
				// 客人當初有指定人員的話，補救也只嘗試同一位；沒指定的話讓系統
				// 重新挑選（原本那位不一定還有空，畢竟暫留是在事後才失效的）。
				'staff_id'          => ! empty( $booking['staff_requested'] ) ? (int) $booking['staff_id'] : 0,
				'customer_id'       => $booking['customer_id'],
				'customer_name'     => $booking['customer_name'],
				'customer_phone'    => $booking['customer_phone'],
				// 客人已經付款完成，這是系統自己把同一個時段找回來，不是新的預約
				// 請求：不該被「暫停接受預約／方案已停用」或「最少提前預約時間」
				// 這類只該擋新訂單的銷售政策卡住。
				'ignore_paused'     => true,
				'bypass_lead_time'  => true,
				'status'            => self::STATUS_CONFIRMED,
			)
		);

		if ( is_wp_error( $new_id ) ) {
			return array(
				'old_booking_id' => (int) $booking['id'],
				'booking_id'     => (int) $booking['id'],
				'ok'             => false,
				'relocked'       => false,
			);
		}

		self::link_to_order( $new_id, $order_id, (int) $booking['order_item_id'] );

		// 舊的那筆（expired）也要跟這張訂單脫鉤，否則它的 order_id 還留著，
		// 下次 handle_payment_complete() 再被觸發（例如 processing 之後又被
		// 標記 completed）時，get_by_order() 會把它跟新的那筆一起撈出來，
		// confirm_or_relock() 對一筆已經 expired 的舊紀錄重跑一次：容量恆為 1
		// 時一定補救失敗，在訂單留下一則客人其實沒事的假警報；容量 > 1 時則
		// 會再補鎖一次，變成重複佔用同一個名額。
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			UAPPT_Install::table( 'bookings' ),
			array(
				'order_id'      => null,
				'order_item_id' => null,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => (int) $booking['id'] ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);

		return array(
			'old_booking_id' => (int) $booking['id'],
			'booking_id'     => (int) $new_id,
			'ok'             => true,
			'relocked'       => true,
		);
	}

	/**
	 * 將購物車項目 key 補寫回預約紀錄（加入購物車動作完成、cart_item_key 產生後呼叫）。
	 *
	 * @param int    $booking_id 預約 ID。
	 * @param string $cart_item_key 購物車項目 key。
	 */
	public static function attach_cart_item_key( $booking_id, $cart_item_key ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			UAPPT_Install::table( 'bookings' ),
			array( 'cart_item_key' => sanitize_text_field( $cart_item_key ) ),
			array( 'id' => (int) $booking_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * 將預約與訂單/訂單項目建立關聯（結帳成立訂單時呼叫，尚不代表已付款確認）。
	 *
	 * **同時清掉 expires_at**：那個倒數是「購物車暫留」用的（客人把商品丟進購物車、
	 * 關掉分頁就跑掉，15 分鐘後要把時段還給別人）。訂單一旦成立，這筆就不再是
	 * 「可能被遺棄的購物車」，而是一筆真實的、店家看得到也管得到的訂單，不該再被
	 * 那個短倒數清掉——銀行轉帳（BACS）這類金流會把訂單設成「保留（on-hold）」等待
	 * 對帳，`woocommerce_payment_complete` / `processing` / `completed` 都不會觸發，
	 * 舊寫法會讓客人匯了款、時段卻在 15 分鐘後被 cron 標成逾時釋放（實際發生過）。
	 *
	 * 釋放改由訂單自己的生命週期負責：取消／付款失敗／退款會觸發
	 * UAPPT_Order::handle_release()。release_expired_holds() 的 SQL 有
	 * `expires_at IS NOT NULL` 條件，清成 NULL 之後自然就跳過這筆。
	 *
	 * **順便用訂單項目的實際金額覆蓋一次 `amount` 快照**：`create_hold()`
	 * 建立當下寫的是方案價 × 人數 + 人員加價，是「當時看起來會收多少」；
	 * 這裡才是真正成立訂單、金流走過折扣碼／稅之後的實際金額，報表要用的
	 * 是後者。找不到訂單項目（理論上不會發生，防禦性判斷）就保留原本的
	 * 快照，不要覆寫成 0——0 元的預約在報表上會被誤讀成「免費服務」。
	 *
	 * **順便從訂單補上客人的姓名、電話與會員 ID**（v2.29.2）：前台是在「加入
	 * 購物車」當下就建立預約的，那時候結帳表單還沒填，這三個欄位一律是空的。
	 * 詳見 `customer_fields_from_order()` 的說明——那不是美觀問題，是回訪管理
	 * 與客人去重鍵能不能運作的前提。
	 *
	 * @param int $booking_id 預約 ID。
	 * @param int $order_id 訂單 ID。
	 * @param int $order_item_id 訂單項目 ID。
	 */
	public static function link_to_order( $booking_id, $order_id, $order_item_id ) {
		global $wpdb;

		$fields  = array(
			'order_id'      => (int) $order_id,
			'order_item_id' => (int) $order_item_id,
			'expires_at'    => null,
			'updated_at'    => current_time( 'mysql' ),
		);
		$formats = array( '%d', '%d', '%s', '%s' );

		$order = wc_get_order( $order_id );
		$item  = $order ? $order->get_item( $order_item_id ) : null;
		if ( $item ) {
			$fields['amount'] = (float) $item->get_total();
			$formats[]        = '%f';
		}

		if ( $order ) {
			$booking = self::get( $booking_id );
			foreach ( self::customer_fields_from_order( $order, $booking ) as $key => $value ) {
				$fields[ $key ]  = $value;
				$formats[]       = ( 'customer_id' === $key ) ? '%d' : '%s';
			}
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			UAPPT_Install::table( 'bookings' ),
			$fields,
			array( 'id' => (int) $booking_id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * 從訂單補出預約該記下來的客人資料（姓名／電話／會員）。
	 *
	 * **為什麼需要這支**：前台是在「加入購物車」的當下就 `create_hold()`，那時候
	 * 結帳表單還沒填，姓名電話**根本還不存在**——所以前台建立的預約，這三個欄位
	 * 一律是空的。資料要等到結帳完成才有，而那正是 `link_to_order()` 被呼叫的時候。
	 *
	 * 不回填的後果比「報表上少兩欄」嚴重得多：
	 *
	 * - **回訪管理整份名單都是「未留姓名／沒有留電話」**，那一頁的用途就是打電話，
	 *   沒有電話等於整個功能報廢
	 * - **客人去重鍵會退化**：訪客沒有會員帳號、又沒有電話，`customer_key_sql()`
	 *   只能退回 `b:<預約id>`，於是**每一筆預約都被當成一位不同的客人**——客數
	 *   高估、回頭率永遠是 0
	 * - 預約列表搜尋姓名／電話找不到前台訂的預約；日曆與員工中心也顯示不出客人是誰
	 *
	 * ⚠️ **只填「原本是空的」欄位，不覆蓋既有值。** 後台手動建單時客服可能刻意
	 * 打了跟帳單不同的稱呼（「王太太」而不是帳單上的本名），那是更貼近現場的資訊，
	 * 不該被帳單資料蓋掉。這跟 `amount` 的規則刻意不同——錢以訂單為準，稱呼以
	 * 現場為準。
	 *
	 * @param WC_Order   $order   訂單。
	 * @param array|null $booking 目前的預約紀錄；null 時視為全部都空的。
	 * @return array 只含「需要更新」的欄位，全都有值就回傳空陣列。
	 */
	public static function customer_fields_from_order( $order, $booking = null ) {
		$fields = array();

		$current_name  = $booking ? trim( (string) $booking['customer_name'] ) : '';
		$current_phone = $booking ? trim( (string) $booking['customer_phone'] ) : '';
		$current_id    = $booking ? (int) $booking['customer_id'] : 0;

		if ( '' === $current_name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			if ( '' !== $name ) {
				$fields['customer_name'] = sanitize_text_field( $name );
			}
		}

		if ( '' === $current_phone ) {
			$phone = trim( (string) $order->get_billing_phone() );
			if ( '' !== $phone ) {
				$fields['customer_phone'] = sanitize_text_field( $phone );
			}
		}

		// 訪客在結帳時註冊帳號的情況：建立預約當下 customer_id 是 0，訂單上才有。
		if ( ! $current_id && $order->get_customer_id() ) {
			$fields['customer_id'] = (int) $order->get_customer_id();
		}

		return $fields;
	}

	/**
	 * 取得單筆預約。
	 *
	 * @param int $booking_id 預約 ID。
	 * @return array|null
	 */
	public static function get( $booking_id ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 在交易內鎖住一筆預約（SELECT … FOR UPDATE），回傳鎖住之後讀到的最新內容。
	 *
	 * ⚠️ **會改預約狀態或時段的寫入路徑，都要在交易內先鎖這一列、再判斷狀態**（v3.0.1）。
	 * 交易外先讀的那一份可能已經被另一個請求改掉：購物車移除跟逾時排程同時釋放同一筆，
	 * 兩邊都讀到 held、各扣一次佔用——第二次扣掉的可能是別人剛搶到的那一格。
	 *
	 * 鎖的順序一律是「預約列 → 時間格」。create_hold()／create_block() 是新增一列，
	 * 沒有既有的預約列可鎖，只鎖時間格；兩種順序不會交叉，同時進行的操作才不會互相
	 * 等成死結。
	 *
	 * @param int $booking_id 預約 ID。
	 * @return array|null
	 */
	protected static function lock_booking_row( $booking_id ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", (int) $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 鎖住之後讀到的這一筆，跟交易外先讀來計算的那一份，佔用的是不是同一批格子。
	 *
	 * reschedule()／reassign_staff() 在交易外就先用舊資料算好要還哪些格子；鎖住之後
	 * 這幾個欄位只要有一個變了（別人剛改期、換人、取消），那份計算就作廢，不能照做。
	 *
	 * @param array      $before 交易外讀的那一份。
	 * @param array|null $locked lock_booking_row() 的結果。
	 * @return bool
	 */
	protected static function same_slot( $before, $locked ) {
		if ( ! $locked ) {
			return false;
		}
		foreach ( array( 'status', 'staff_id', 'block_start', 'block_end', 'grid_interval', 'occupied_units' ) as $key ) {
			if ( (string) $before[ $key ] !== (string) $locked[ $key ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 取得一筆預約的顯示名稱（後台列表、日曆、提醒訊息、會員頁一律用這支）。
	 *
	 * 優先用預約紀錄自己存的 plan_name 快照，快照沒有才回頭問商品。理由跟
	 * 「時間格顆粒跟著紀錄走」是同一個：方案被改名或刪除之後，歷史紀錄仍然要
	 * 顯示客人當初買的是什麼，不能因為商品現在的設定變了就跟著改。
	 *
	 * @param array $booking 預約紀錄（bookings 資料表的一列）。
	 * @return string
	 */
	public static function get_booking_display_name( $booking ) {
		// 時段佔用沒有商品（product_id 是哨兵值 0），顯示的是它自己的備註。
		// 這個判斷放在最前面，確保任何呼叫端都不會拿 0 去查商品。
		if ( isset( $booking['kind'] ) && self::KIND_BLOCK === $booking['kind'] ) {
			$note = isset( $booking['note'] ) ? trim( (string) $booking['note'] ) : '';
			return '' !== $note ? $note : __( '時段佔用', 'ultimate-appointments' );
		}

		$product_id = isset( $booking['product_id'] ) ? (int) $booking['product_id'] : 0;
		$plan_key   = isset( $booking['plan_key'] ) ? (string) $booking['plan_key'] : '';
		$plan_name  = isset( $booking['plan_name'] ) ? trim( (string) $booking['plan_name'] ) : '';

		if ( '' === $plan_name ) {
			return UAPPT_Product::get_display_name( $product_id, $plan_key );
		}

		$product = wc_get_product( $product_id );
		$base    = $product ? $product->get_name() : get_the_title( $product_id );

		return $base . ' – ' . $plan_name;
	}

	/**
	 * 這位人員名下還有多少筆「尚未結束」的有效紀錄（held/confirmed，含客人預約
	 * 與時段佔用）。刪除人員前的保護檢查用——人員一旦被刪，這些紀錄的 staff_id
	 * 就變成指向不存在的人員，日曆會直接靜默略過（見 render_calendar_day_view()
	 * 依 staff_id 分組），而 slot_grid 裡對應的佔用格子永遠沒有人會去釋放。
	 *
	 * 用 service_end（不是 service_start）判斷「尚未結束」：一筆正在進行中的
	 * 服務（已開始、還沒結束）一樣不該讓人員被刪掉。
	 *
	 * @param int $staff_id 人員 ID。
	 * @return int
	 */
	public static function count_upcoming_for_staff( $staff_id ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE staff_id = %d AND status IN (%s, %s) AND service_end >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(int) $staff_id,
				self::STATUS_HELD,
				self::STATUS_CONFIRMED,
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * 取得某日「已確認、且尚未發送過提醒」的預約（提醒排程用）。
	 *
	 * reminder_sent_at IS NULL 是去重的關鍵：排程每 5 分鐘跑一次，沒有這個條件
	 * 會對同一位客人重複轟炸。
	 *
	 * @param string $date_ymd 日期 (Y-m-d)。
	 * @return array
	 */
	public static function get_bookings_needing_reminder( $date_ymd ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		// kind 條件不可省：時段佔用（訓練、休息、現場佔用）沒有客人，
		// 提醒不該寄給任何人。
		//
		// 除了已確認，也把「待付款」（held 但已經有訂單）一併納入——銀行轉帳
		// 這類金流常常拖到服務前一兩天才對帳完成，若只提醒已確認的客人，
		// 這批人反而完全收不到提醒。時段本身已經受「待付款訂單的時段保護」
		// 鎖住（見 link_to_order()），不會臨時消失，提醒內容一律共用同一份
		// 範本即可。純購物車暫留（沒有訂單）依然被排除在外——那從來不是
		// 一筆算數的預約。
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE kind = %s AND reminder_sent_at IS NULL AND ( status = %s OR ( status = %s AND order_id IS NOT NULL ) ) AND service_start >= %s AND service_start <= %s ORDER BY service_start ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::KIND_BOOKING,
				self::STATUS_CONFIRMED,
				self::STATUS_HELD,
				$date_ymd . ' 00:00:00',
				$date_ymd . ' 23:59:59'
			),
			ARRAY_A
		);
	}

	/**
	 * 取得「服務開始前 N 小時內、還沒發送過第二次提醒」的預約（服務前提醒排程用）。
	 *
	 * 跟 get_bookings_needing_reminder() 是相對／絕對兩種不同的時間窗：那支用
	 * 「日期＝明天」（一天一次的固定時間點觸發），這支用「距離服務開始還有多久」
	 * （每 5 分鐘的排程 tick 都要重新算一次窗口），所以不能共用同一個 SQL、
	 * 也不能共用同一個去重欄位——見 reminder2_sent_at 的說明。
	 *
	 * 狀態條件（已確認，或待付款）與 kind 排除時段佔用，都跟前一天提醒完全
	 * 一致，直接照抄那份判斷：見上面的註解。
	 *
	 * @param int $hours 服務開始前幾小時內算「該提醒」。
	 * @return array
	 */
	public static function get_bookings_needing_hour_reminder( $hours ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		$now_ts   = time();
		$until_ts = $now_ts + max( 0, (int) $hours ) * HOUR_IN_SECONDS;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE kind = %s AND reminder2_sent_at IS NULL AND ( status = %s OR ( status = %s AND order_id IS NOT NULL ) ) AND service_start >= %s AND service_start <= %s ORDER BY service_start ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::KIND_BOOKING,
				self::STATUS_CONFIRMED,
				self::STATUS_HELD,
				self::mysql_from_ts( $now_ts ),
				self::mysql_from_ts( $until_ts )
			),
			ARRAY_A
		);
	}

	/**
	 * 取得某日所有有效預約（店家的每日清單用，不理會是否提醒過）。
	 *
	 * @param string $date_ymd 日期 (Y-m-d)。
	 * @return array
	 */
	public static function get_bookings_for_date( $date_ymd ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		// 同樣排除時段佔用：店家的「明日預約清單」要的是客人，不是內部的訓練/休息。
		//
		// 「待付款」（held 但已經有訂單，例如銀行轉帳還在等對帳）也要一併列入：
		// 店家反而更需要在明日清單看到這幾筆，才知道要在客人到店前確認款項有沒有
		// 到，而不是漏掉這批人。純購物車暫留（沒有訂單）依然排除，那還不算數。
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE kind = %s AND ( status IN (%s, %s) OR ( status = %s AND order_id IS NOT NULL ) ) AND service_start >= %s AND service_start <= %s ORDER BY service_start ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::KIND_BOOKING,
				self::STATUS_CONFIRMED,
				self::STATUS_COMPLETED,
				self::STATUS_HELD,
				$date_ymd . ' 00:00:00',
				$date_ymd . ' 23:59:59'
			),
			ARRAY_A
		);
	}

	/**
	 * 取得某位客人最近一筆已確認/已完成的預約（用於前台「上次為您服務的人員」）。
	 *
	 * @param int $customer_id 會員 ID。
	 * @param int $exclude_staff_id 若這位人員不在候選清單中就沒有意義，可傳 0 略過此限制。
	 * @return array|null
	 */
	public static function get_last_completed_booking( $customer_id ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		if ( ! $customer_id ) {
			return null;
		}

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE customer_id = %d AND staff_id IS NOT NULL AND status IN (%s, %s) ORDER BY service_start DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(int) $customer_id,
				self::STATUS_CONFIRMED,
				self::STATUS_COMPLETED
			),
			ARRAY_A
		);
	}

	/**
	 * 標記某筆預約的提醒已發送。
	 *
	 * @param int $booking_id 預約 ID。
	 * @return bool
	 */
	public static function mark_reminder_sent( $booking_id ) {
		global $wpdb;

		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			UAPPT_Install::table( 'bookings' ),
			array(
				'reminder_sent_at' => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'id' => (int) $booking_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * 標記某筆預約的「服務前提醒」已發送。獨立於 mark_reminder_sent() 之外，
	 * 是因為 reminder_sent_at 是前一天那次固定時間提醒專用的去重欄位——一旦
	 * 混用同一個欄位，前一天的提醒送出後 reminder_sent_at 就不是 NULL 了，
	 * 服務前提醒的查詢條件會直接把這筆跳過，兩種提醒會變成只有先送的那個
	 * 生效，後送的永遠等不到。
	 *
	 * @param int $booking_id 預約 ID。
	 * @return bool
	 */
	public static function mark_hour_reminder_sent( $booking_id ) {
		global $wpdb;

		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			UAPPT_Install::table( 'bookings' ),
			array(
				'reminder2_sent_at' => current_time( 'mysql' ),
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => (int) $booking_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * 依訂單取得所有相關預約。
	 *
	 * @param int $order_id 訂單 ID。
	 * @return array
	 */
	public static function get_by_order( $order_id ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", (int) $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 後台查詢用：依篩選條件取得預約列表。
	 *
	 * @param array $filters staff_id, status, awaiting_payment（true 時取代 status，
	 *                       篩選「held 但已經有訂單」——見「待付款訂單的時段保護」）,
	 *                       assignment_state, kind（預設只回 booking，傳 'any' 代表全部）,
	 *                       date_from, date_to, search, paged, per_page, order('ASC'/'DESC', 預設 DESC).
	 * @return array{items:array, total:int}
	 */
	public static function query( $filters = array() ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		$params    = array();
		$where_sql = self::build_where( $filters, $params );

		$per_page = ! empty( $filters['per_page'] ) ? max( 1, (int) $filters['per_page'] ) : 20;
		$paged    = ! empty( $filters['paged'] ) ? max( 1, (int) $filters['paged'] ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total     = (int) ( empty( $params ) ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$order       = ( isset( $filters['order'] ) && 'ASC' === strtoupper( $filters['order'] ) ) ? 'ASC' : 'DESC';
		$list_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY service_start {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * 預約列表的「狀態快捷連結」用：在**目前的篩選條件底下**，每個狀態各有
	 * 幾筆，一次 `GROUP BY status` 查完。
	 *
	 * 「未到」被標記之後會從預設的「即將到來」檢視裡消失（那個檢視把
	 * `date_from` 設成今天，而未到的預約必定在過去），管理者會以為狀態沒有寫
	 * 進去。這支查詢就是為了在頁籤列上直接說出「未到 3」，讓東西跑到哪個檢視
	 * 去有跡可循。
	 *
	 * 刻意**忽略傳進來的 `status`／`awaiting_payment`**：這兩個是「使用者目前選
	 * 了哪一個狀態」，拿來算各狀態筆數會讓每一格都變成 0 或全部。其餘條件
	 * （人員、日期、搜尋字串）則要照樣套用，否則點進去的結果對不上數字。
	 *
	 * @param array $filters 同 query()，`status`／`awaiting_payment` 會被忽略。
	 * @return array 狀態代碼 => 筆數，另含衍生的 'awaiting_payment' 一項。
	 */
	public static function count_by_status( $filters = array() ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		unset( $filters['status'], $filters['awaiting_payment'] );

		$params    = array();
		$where_sql = self::build_where( $filters, $params );

		$sql = "SELECT status,
					COUNT(*) AS total,
					SUM( CASE WHEN order_id IS NOT NULL THEN 1 ELSE 0 END ) AS with_order
				FROM {$table} WHERE {$where_sql} GROUP BY status"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows = empty( $params )
			? $wpdb->get_results( $sql, ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$counts = array( 'awaiting_payment' => 0 );
		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
			// 待付款不是一個資料庫狀態，是 held + 有訂單的組合（見「待付款訂單的
			// 時段保護」）。用同一次查詢的 with_order 算出來，不另外跑第二次 SQL。
			if ( self::STATUS_HELD === $row['status'] ) {
				$counts['awaiting_payment'] = (int) $row['with_order'];
			}
		}

		return $counts;
	}

	/**
	 * 組出 query()／count_by_status() 共用的 WHERE 子句。
	 *
	 * 抽出來的唯一理由是「篩選條件只能有一份」：狀態計數必須跟點進去之後看到
	 * 的清單套用完全相同的人員／日期／搜尋條件，各寫一份遲早會對不上。
	 *
	 * @param array $filters 篩選條件，見 query()。
	 * @param array $params  由參照傳入，$wpdb->prepare() 要的參數會依序附加進來。
	 * @return string WHERE 子句（不含 WHERE 關鍵字）。
	 */
	protected static function build_where( $filters, array &$params ) {
		global $wpdb;

		$where = array( '1=1' );

		if ( ! empty( $filters['staff_id'] ) ) {
			$where[]  = 'staff_id = %d';
			$params[] = (int) $filters['staff_id'];
		}
		if ( ! empty( $filters['awaiting_payment'] ) ) {
			// 跟 status 互斥：待付款不是一個獨立的資料庫狀態（見「待付款訂單的
			// 時段保護」），是 held + 有訂單的組合，直接比對 status = held 撈不到。
			$where[]  = 'status = %s AND order_id IS NOT NULL';
			$params[] = self::STATUS_HELD;
		} elseif ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_text_field( $filters['status'] );
		}
		if ( ! empty( $filters['assignment_state'] ) ) {
			$where[]  = 'assignment_state = %s';
			$params[] = sanitize_text_field( $filters['assignment_state'] );
		}
		// kind：預設只回客人的預約。傳 'any' 才會把時段佔用一起帶出來（日檢視要，
		// 預約列表不要——那份清單是給客服看客人的，混進訓練/休息只是雜訊）。
		if ( ! isset( $filters['kind'] ) || 'any' !== $filters['kind'] ) {
			$where[]  = 'kind = %s';
			$params[] = ! empty( $filters['kind'] ) ? sanitize_text_field( $filters['kind'] ) : self::KIND_BOOKING;
		}
		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'service_start >= %s';
			$params[] = sanitize_text_field( $filters['date_from'] ) . ' 00:00:00';
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'service_start <= %s';
			$params[] = sanitize_text_field( $filters['date_to'] ) . ' 23:59:59';
		}
		if ( ! empty( $filters['search'] ) ) {
			$search = trim( sanitize_text_field( $filters['search'] ) );
			if ( '' !== $search ) {
				$like       = '%' . $wpdb->esc_like( $search ) . '%';
				$is_numeric = (bool) preg_match( '/^\d+$/', $search );

				if ( $is_numeric ) {
					// 純數字：也視為可能在查訂單編號或預約編號，一併比對。
					$where[]  = '(customer_name LIKE %s OR customer_phone LIKE %s OR order_id = %d OR id = %d)';
					$params[] = $like;
					$params[] = $like;
					$params[] = (int) $search;
					$params[] = (int) $search;
				} else {
					$where[]  = '(customer_name LIKE %s OR customer_phone LIKE %s)';
					$params[] = $like;
					$params[] = $like;
				}
			}
		}

		return implode( ' AND ', $where );
	}

	/**
	 * 「同一位客人」的去重鍵 SQL 片段。**所有客人相關的統計都必須用這一支。**
	 *
	 * 規則（優先序）：
	 *
	 * 1. 有 `customer_id`（綁定了會員）就用它
	 * 2. 否則用正規化過的電話——去掉 `-`、空白，`+886` 前綴換回 `0`
	 * 3. 兩者都沒有（純現場、只留姓名）則**每一筆各算一位**
	 *
	 * 第 3 條是刻意的：同名同姓不敢合併，寧可高估客數，也不要把兩位不同的客人
	 * 算成同一個人——前者只是數字保守，後者會讓店家打電話給錯的人。
	 *
	 * `+886` 換回 `0` 只處理最常見的那一種寫法，不是完整的號碼正規化。目標是
	 * 「同一個人用同一支電話留了兩次」不要被算成兩位，不是做電信等級的解析。
	 *
	 * ⚠️ **這支抽出來的理由**：v2.25.0 時這段 SQL 在 `get_report_stats()` 與
	 * `get_customer_breakdown()` 各有一份**一模一樣**的複製；報表第二版還會再
	 * 增加回店預約率、回訪週期、Top 客人、回訪管理等好幾個查詢，全部都要同一把
	 * 鑰匙。六份各自長歪的後果是同一個畫面上的客數互相對不起來，而那種 bug 要
	 * 對帳對很久才會發現。**不要再把它複製回去。**
	 *
	 * @param string $alias 資料表別名（子查詢裡有 JOIN 時要用），例如 'b'。留空＝不加前綴。
	 * @return string 可直接內嵌進 SQL 的運算式（不含任何使用者輸入，不需要 prepare）。
	 */
	public static function customer_key_sql( $alias = '' ) {
		$prefix = '' !== $alias ? $alias . '.' : '';

		return "COALESCE(
			CASE WHEN {$prefix}customer_id > 0 THEN CONCAT( 'c:', {$prefix}customer_id ) END,
			CASE WHEN TRIM( COALESCE( {$prefix}customer_phone, '' ) ) <> ''
				THEN CONCAT( 'p:', REPLACE( REPLACE( REPLACE( TRIM( {$prefix}customer_phone ), '-', '' ), ' ', '' ), '+886', '0' ) ) END,
			CONCAT( 'b:', {$prefix}id )
		)";
	}

	/**
	 * 報表用的聚合查詢，一次 GROUP BY 查完一組期間／人員／服務的所有數字。
	 *
	 * v2.25.0 取代原本只能依人員彙總的 `get_staff_report_stats()`（後來變成這支的
	 * 薄包裝，v3.0.3 因為沒有呼叫者刪掉了）。全外掛只有這裡一套報表 SQL。
	 *
	 * **三個統計口徑刻意不一致，這是有意的設計**（沿用 v2.12.0 定下的規則）：
	 *
	 * - **業績**納入 `confirmed`／`completed`／`no_show`——不管客人有沒有出現，
	 *   錢通常都已經收了。
	 * - **服務筆數**與**已服務分鐘數**（利用率的分子）只算 `confirmed`／
	 *   `completed`，**刻意不含 `no_show`**：那個時段雖然被佔用掉了，但沒有
	 *   真的提供服務，算進分子會高估產能實際被用掉多少。
	 * - **操作筆數**＝服務筆數 + 未到筆數（已經有最終結果的筆數），也是平均
	 *   客單價與未到率的分母。
	 *
	 * 只算「已經發生過」的預約（`service_start` 不晚於現在）——期間涵蓋到未來
	 * 時（例如選「這個月」但今天是月中），還沒發生的 `confirmed` 不該被當成
	 * 已經完成的業績。
	 *
	 * 「不重複客人」的去重鍵見 `customer_key_sql()`。
	 *
	 * @param array $args {
	 *     @type string $date_from 起始日期 (Y-m-d)。
	 *     @type string $date_to   結束日期 (Y-m-d)。
	 *     @type int    $staff_id  篩選人員；0 表示全部。
	 *     @type string $group_by  'day'／'week'／'month'／'staff'／'service'／'staff_service'／'weekday_hour'／'all'。
	 * }
	 * @return array gkey => 統計陣列（'all' 時 gkey 固定是 0）
	 */
	public static function get_report_stats( $args = array() ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		$date_from = isset( $args['date_from'] ) ? sanitize_text_field( $args['date_from'] ) : current_time( 'Y-m-01' );
		$date_to   = isset( $args['date_to'] ) ? sanitize_text_field( $args['date_to'] ) : current_time( 'Y-m-d' );
		$staff_id  = isset( $args['staff_id'] ) ? (int) $args['staff_id'] : 0;
		$group_by  = isset( $args['group_by'] ) ? $args['group_by'] : 'staff';

		// 分組欄位是**寫死的對照表**，不是把使用者給的字串接進 SQL——這幾個
		// 值最後會直接出現在 GROUP BY 裡，沒有辦法用 prepare() 參數化。
		$group_sql = array(
			'day'   => 'DATE( service_start )',
			// 週的鍵用「那一週的星期一」而不是 YEARWEEK()：日期字串本身就排得對、
			// 跨年時不會出現 202601 排在 202552 前面的問題，畫面上也直接看得懂。
			// MySQL 的 WEEKDAY() 是 0=星期一。
			'week'  => 'DATE_SUB( DATE( service_start ), INTERVAL WEEKDAY( service_start ) DAY )',
			'month' => "DATE_FORMAT( service_start, '%%Y-%%m' )",
			'staff' => 'staff_id',
			'service' => "CONCAT( product_id, ':', COALESCE( plan_key, '' ) )",
			// 人員 × 項目。分隔符號用 `|`，因為 plan_key 是 [a-z0-9_] 的隨機鍵、
			// 不會含 `|`，拆回來的時候不會切錯。
			'staff_service' => "CONCAT( staff_id, '|', product_id, ':', COALESCE( plan_key, '' ) )",
			// 星期 × 時段熱度。`DAYOFWEEK()` 是 1=星期日 … 7=星期六。
			// ⚠️ 用**開始時間**的那個小時分組：跨小時的服務（90 分鐘的從 10:30
			// 做到 12:00）只會算在 10 點那一格。要精確攤到每一格得把每筆拆成
			// 分鐘再分配，那對「哪個時段比較滿」這個問題來說是過度工程。
			'weekday_hour'  => "CONCAT( DAYOFWEEK( service_start ), '|', HOUR( service_start ) )",
			// 收款方式（v2.58.0）。分組鍵就是欄位本身，「未指定」是空字串，
			// 天然成為它自己的一組，不需要 COALESCE。
			'payment'       => 'payment_method',
			'all'   => '0',
		);
		$gkey = isset( $group_sql[ $group_by ] ) ? $group_sql[ $group_by ] : $group_sql['staff'];

		// 只撈「已經有最終結果」的預約。每一個統計欄位本來就只算這三種狀態，
		// 但把條件提到 WHERE 還多做一件事：**讓完全沒有資料的分組整個消失**。
		// 不然「服務項目」頁籤會列出一堆全部是 0 的列（那些商品這段期間只有
		// 取消／逾時的預約），看起來像壞掉。
		$where  = array( 'kind = %s', 'status IN (%s, %s, %s)', 'service_start >= %s', 'service_start <= %s', 'service_start <= %s' );
		$params = array(
			self::KIND_BOOKING,
			self::STATUS_CONFIRMED,
			self::STATUS_COMPLETED,
			self::STATUS_NO_SHOW,
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59',
			current_time( 'mysql' ),
		);
		if ( $staff_id ) {
			$where[]  = 'staff_id = %d';
			$params[] = $staff_id;
		}
		$where_sql = implode( ' AND ', $where );

		// 去重鍵。`+886` 換回 `0` 只處理最常見的那一種寫法，不是完整的號碼
		// 正規化——目標是「同一個人用同一支電話留了兩次」不要被算成兩位，
		// 不是做電信等級的號碼解析。
		$customer_key = self::customer_key_sql();

		$sql = "SELECT
				{$gkey} AS gkey,
				MIN( staff_id ) AS staff_id,
				MIN( product_id ) AS product_id,
				MIN( plan_key ) AS plan_key,
				MAX( plan_name ) AS plan_name,
				SUM( CASE WHEN status IN (%s, %s) THEN 1 ELSE 0 END ) AS service_count,
				SUM( CASE WHEN status = %s THEN 1 ELSE 0 END ) AS no_show_count,
				SUM( CASE WHEN status IN (%s, %s) THEN occupied_units ELSE 0 END ) AS person_count,
				SUM( CASE WHEN status IN (%s, %s, %s) THEN amount ELSE 0 END ) AS revenue,
				SUM( CASE WHEN status IN (%s, %s, %s) THEN amount * fee_rate / 100 ELSE 0 END ) AS fee,
				SUM( CASE WHEN status IN (%s, %s, %s) THEN staff_price_adjustment ELSE 0 END ) AS upcharge,
				SUM( CASE WHEN status IN (%s, %s, %s) AND no_charge_reason <> '' THEN 1 ELSE 0 END ) AS no_charge_count,
				SUM( CASE WHEN status IN (%s, %s) THEN TIMESTAMPDIFF( MINUTE, service_start, service_end ) * occupied_units ELSE 0 END ) AS busy_minutes,
				SUM( CASE WHEN status IN (%s, %s, %s) AND staff_requested = 1 THEN 1 ELSE 0 END ) AS requested_count,
				COUNT( DISTINCT CASE WHEN status IN (%s, %s, %s) THEN {$customer_key} END ) AS customer_count
			FROM {$table}
			WHERE {$where_sql}
			GROUP BY gkey"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// SELECT 子句的佔位符依出現順序排在前面，WHERE 的接在後面——
		// $wpdb->prepare() 只認位置不認名字，順序要跟 SQL 文字裡完全一致。
		$select_params = array(
			self::STATUS_CONFIRMED, self::STATUS_COMPLETED,
			self::STATUS_NO_SHOW,
			self::STATUS_CONFIRMED, self::STATUS_COMPLETED,
			self::STATUS_CONFIRMED, self::STATUS_COMPLETED, self::STATUS_NO_SHOW,
			// fee：跟 revenue 同一組狀態（錢通常已經收了，未到也算）。
			self::STATUS_CONFIRMED, self::STATUS_COMPLETED, self::STATUS_NO_SHOW,
			// upcharge：同上。指定加價是 amount 的**一部分**，不是額外收入，
			// 所以狀態條件必須跟 revenue 完全一致，不然「其中指定加價」會
			// 超過業績本身。
			self::STATUS_CONFIRMED, self::STATUS_COMPLETED, self::STATUS_NO_SHOW,
			// no_charge_count：同一組狀態，才會跟 op_count 是同一個母體。
			self::STATUS_CONFIRMED, self::STATUS_COMPLETED, self::STATUS_NO_SHOW,
			self::STATUS_CONFIRMED, self::STATUS_COMPLETED,
			self::STATUS_CONFIRMED, self::STATUS_COMPLETED, self::STATUS_NO_SHOW,
			self::STATUS_CONFIRMED, self::STATUS_COMPLETED, self::STATUS_NO_SHOW,
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( $sql, array_merge( $select_params, $params ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$stats = array();
		foreach ( (array) $rows as $row ) {
			$stats[ (string) $row['gkey'] ] = array(
				'gkey'            => (string) $row['gkey'],
				'staff_id'        => (int) $row['staff_id'],
				'product_id'      => (int) $row['product_id'],
				'plan_key'        => (string) $row['plan_key'],
				'plan_name'       => (string) $row['plan_name'],
				'service_count'   => (int) $row['service_count'],
				'no_show_count'   => (int) $row['no_show_count'],
				'person_count'    => (int) $row['person_count'],
				'customer_count'  => (int) $row['customer_count'],
				'requested_count' => (int) $row['requested_count'],
				'revenue'         => (float) $row['revenue'],
				'fee'             => (float) $row['fee'],
				'upcharge'        => (float) $row['upcharge'],
				'no_charge_count' => (int) $row['no_charge_count'],
				'busy_minutes'    => (float) $row['busy_minutes'],
			);
		}

		return $stats;
	}

	/**
	 * 這段期間的新客，多久之後回來第二次。
	 *
	 * 為什麼要跟整體的回訪週期分開看：整體週期是**熟客**撐出來的（他們本來
	 * 就會回來），中位數 2 天那種數字看起來很漂亮，但它回答不了留客真正的
	 * 問題——**第一次上門的人，有沒有第二次**。新客的首購→回購才是那道關卡。
	 *
	 * 「新客」的認定跟 get_customer_breakdown() 一致：在本站的第一筆預約就落
	 * 在這段期間內。第二次則**不限期間**——這個月的新客在下個月才回來也算，
	 * 不然月底進來的客人永遠會被判定成沒回訪。
	 *
	 * ⚠️ 因此「還沒回來」不等於「流失」：這段期間結束前才第一次上門的人，
	 * 本來就還沒有機會回來。呼叫端要把這件事講清楚，不要印成流失率。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員（只影響「哪些人算新客」的候選，不影響
	 *                          「第一次」的判定——那永遠以整間店為準）。
	 * @return array{total:int, repeated:int, pending:int, median:float, rate:float}
	 */
	public static function get_new_customer_repeat( $date_from, $date_to, $staff_id = 0 ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );
		$ckey  = self::customer_key_sql();

		$from_mysql = sanitize_text_field( $date_from ) . ' 00:00:00';
		$to_mysql   = sanitize_text_field( $date_to ) . ' 23:59:59';
		$now        = current_time( 'mysql' );

		// 基底：所有「已經有最終結果」的預約。⚠️ 這裡刻意**不帶人員條件**
		// ——「第一次上門」是對整間店而言的，老客人換一位服務人員不該被算成
		// 新客（跟 get_customer_breakdown() 同一個道理）。
		$base = "SELECT {$ckey} AS ckey, service_start, staff_id
			FROM {$table}
			WHERE kind = %s AND status IN (%s, %s, %s) AND service_start <= %s";

		// 兩次掃描：先算出每個人的第一次，再往後找最早的下一次。
		// 不用視窗函式（ROW_NUMBER）——那要 MySQL 8 / MariaDB 10.2 以上，
		// 而這個外掛不該對資料庫版本挑剔。
		$sql = "SELECT f.ckey, f.first_at, MIN( t.service_start ) AS second_at
			FROM ( SELECT ckey, MIN( service_start ) AS first_at FROM ( {$base} ) b GROUP BY ckey ) f
			LEFT JOIN ( {$base} ) t ON t.ckey = f.ckey AND t.service_start > f.first_at
			WHERE f.first_at >= %s AND f.first_at <= %s
			GROUP BY f.ckey, f.first_at"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$status_params = array(
			self::KIND_BOOKING,
			self::STATUS_CONFIRMED,
			self::STATUS_COMPLETED,
			self::STATUS_NO_SHOW,
			$now,
		);
		$params = array_merge( $status_params, $status_params, array( $from_mysql, $to_mysql ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$gaps  = array();
		$total = 0;
		foreach ( (array) $rows as $row ) {
			$total++;
			if ( empty( $row['second_at'] ) ) {
				continue;
			}
			$gaps[] = ( strtotime( $row['second_at'] ) - strtotime( $row['first_at'] ) ) / DAY_IN_SECONDS;
		}

		sort( $gaps );
		$count  = count( $gaps );
		$median = 0.0;
		if ( $count > 0 ) {
			$mid    = (int) floor( ( $count - 1 ) / 2 );
			$median = 0 === $count % 2 ? ( $gaps[ $mid ] + $gaps[ $mid + 1 ] ) / 2 : $gaps[ $mid ];
		}

		return array(
			'total'    => $total,
			'repeated' => $count,
			'pending'  => $total - $count,
			// 中位數不是平均：少數隔半年才回來的人會把平均拉爆（跟整體回訪
			// 週期同一個理由，見 get_visit_gap_stats()）。
			'median'   => round( $median, 1 ),
			'rate'     => $total > 0 ? $count / $total : 0.0,
		);
	}

	/**
	 * 整段期間的不重複客人數（只去重一次）。
	 *
	 * ⚠️ **不能把每一列的客數加起來。** 同一位客人這個月來了三天，逐日相加
	 * 會變成三位；按人員分組時，她找過兩位人員就變成兩位。實測同一段期間、
	 * 同一批資料：真值 51，逐日加總 186，逐人員加總 100，逐收款方式加總 120。
	 * 每一個都是「對的加法、錯的答案」。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @param int    $staff_id  篩選人員；0 代表全部。
	 * @return int
	 */
	public static function count_unique_customers( $date_from, $date_to, $staff_id = 0 ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );
		$ckey  = self::customer_key_sql();

		$where  = array( 'kind = %s', 'status IN (%s, %s, %s)', 'service_start >= %s', 'service_start <= %s', 'service_start <= %s' );
		$params = array(
			self::KIND_BOOKING,
			self::STATUS_CONFIRMED,
			self::STATUS_COMPLETED,
			self::STATUS_NO_SHOW,
			sanitize_text_field( $date_from ) . ' 00:00:00',
			sanitize_text_field( $date_to ) . ' 23:59:59',
			current_time( 'mysql' ),
		);
		if ( $staff_id ) {
			$where[]  = 'staff_id = %d';
			$params[] = (int) $staff_id;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT {$ckey} ) FROM {$table} WHERE " . implode( ' AND ', $where ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			)
		);
	}

	/**
	 * 每位人員、每個**日曆月**的業績與指定加價。
	 *
	 * 抽成級距是以「這個月做了多少」判定的，所以它的分母永遠是整個日曆月，
	 * 跟報表選了哪一段期間無關。這支因此會把傳進來的區間**往外擴成整月**：
	 * 選 9/10～9/20 也是回傳整個九月——只算區間內那 11 天會讓級距判定失真，
	 * 而失真的方向是「永遠偏低」（看得越短、越不可能達標）。
	 *
	 * ⚠️ 尚未結束的月份算出來的是**暫估值**，會隨著月底繼續累加。呼叫端要
	 * 在畫面上標示，不要讓人拿月中的數字去發薪水。
	 *
	 * @param string $date_from 起始日期（會被擴到當月 1 號）。
	 * @param string $date_to   結束日期（會被擴到當月最後一天）。
	 * @param int    $staff_id  篩選人員；0 代表全部。
	 * @return array 'YYYY-MM' => staff_id => ['revenue'=>float,'upcharge'=>float,'op_count'=>int]
	 */
	public static function get_monthly_staff_revenue( $date_from, $date_to, $staff_id = 0 ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		$from = substr( sanitize_text_field( $date_from ), 0, 7 ) . '-01';
		$last = substr( sanitize_text_field( $date_to ), 0, 7 ) . '-01';
		$to   = gmdate( 'Y-m-t', strtotime( $last ) );

		$where  = array( 'kind = %s', 'status IN (%s, %s, %s)', 'service_start >= %s', 'service_start <= %s', 'service_start <= %s', 'staff_id IS NOT NULL' );
		$params = array(
			self::KIND_BOOKING,
			self::STATUS_CONFIRMED,
			self::STATUS_COMPLETED,
			self::STATUS_NO_SHOW,
			$from . ' 00:00:00',
			$to . ' 23:59:59',
			current_time( 'mysql' ),
		);
		if ( $staff_id ) {
			$where[]  = 'staff_id = %d';
			$params[] = (int) $staff_id;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT( service_start, '%%Y-%%m' ) AS ym, staff_id,
					COALESCE( SUM( amount ), 0 ) AS revenue,
					COALESCE( SUM( staff_price_adjustment ), 0 ) AS upcharge,
					COUNT(*) AS op_count
				FROM {$table}
				WHERE " . implode( ' AND ', $where ) . "
				GROUP BY ym, staff_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['ym'] ][ (int) $row['staff_id'] ] = array(
				'revenue'  => (float) $row['revenue'],
				'upcharge' => (float) $row['upcharge'],
				'op_count' => (int) $row['op_count'],
			);
		}

		// 月份由新到舊——看抽成的人最關心的是最近那個月。
		krsort( $out );

		return $out;
	}

	/**
	 * 未收費／招待的原因清單。
	 *
	 * **刻意是固定清單，不做成可設定的**——跟收款方式與抽成級距不一樣：那兩者
	 * 是每一家店跟外部談出來的條件（費率、抽成），必須可調；這裡的分類是
	 * 「為什麼沒收錢」，各行各業其實大同小異，而且**報表要能跨店比較**。
	 * 真的有清單以外的情況，選「其他」再把細節寫在備註欄。
	 *
	 * ⚠️ 鍵值一旦上線就不要改：它會被寫進 bookings.no_charge_reason，改了
	 * 歷史資料就對不上標籤。要改文案只改右邊的顯示字串。
	 *
	 * @return array reason => 顯示標籤
	 */
	public static function no_charge_reasons() {
		return array(
			'comp'      => __( '招待', 'ultimate-appointments' ),
			'staff'     => __( '員工／親友', 'ultimate-appointments' ),
			'redo'      => __( '重做／補做', 'ultimate-appointments' ),
			'complaint' => __( '客訴補償', 'ultimate-appointments' ),
			'trial'     => __( '體驗／試做', 'ultimate-appointments' ),
			'training'  => __( '教學示範', 'ultimate-appointments' ),
			'other'     => __( '其他', 'ultimate-appointments' ),
		);
	}

	/**
	 * 未收費原因的顯示標籤。
	 *
	 * @param string $reason 原因鍵。
	 * @return string 空字串代表「正常收費」。
	 */
	public static function no_charge_label( $reason ) {
		$reasons = self::no_charge_reasons();
		if ( '' === $reason || ! isset( $reasons[ $reason ] ) ) {
			return '';
		}
		return $reasons[ $reason ];
	}

	/**
	 * 把表單送上來的原因收斂成合法值。
	 *
	 * 白名單比對——這個值會被寫進資料表、之後被報表拿去分組。
	 *
	 * @param mixed $raw 表單值。
	 * @return string
	 */
	public static function sanitize_no_charge_reason( $raw ) {
		$reason = sanitize_key( (string) $raw );
		return isset( self::no_charge_reasons()[ $reason ] ) ? $reason : '';
	}

	/**
	 * 標記／取消一筆預約的未收費原因。
	 *
	 * ⚠️ **這支不動金額。** 標記只是回答「為什麼沒收錢」，不是把金額改成 0
	 * ——改金額有它自己的稽核流程（會寫進內部備註與訂單備註，見
	 * UAPPT_Admin::handle_update_booking_amount()）。兩件事分開，才不會有人
	 * 在後台選一個原因就把已付款訂單的金額靜靜歸零。
	 *
	 * @param int    $booking_id 預約 ID。
	 * @param string $reason     原因鍵；空字串＝取消標記。
	 * @param string $note       對象／細節（例如「店長的妹妹」）。
	 * @return int 更新了幾筆。
	 */
	public static function set_no_charge( $booking_id, $reason, $note = '' ) {
		global $wpdb;

		$booking_id = (int) $booking_id;
		if ( $booking_id <= 0 ) {
			return 0;
		}

		$reason = self::sanitize_no_charge_reason( $reason );

		return (int) $wpdb->update(
			UAPPT_Install::table( 'bookings' ),
			array(
				'no_charge_reason' => $reason,
				// 取消標記時備註一起清掉：留著一句沒有原因的說明只會讓人困惑。
				'no_charge_note'   => '' !== $reason ? sanitize_text_field( $note ) : null,
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'id' => $booking_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * 這筆預約該不該收人員的指定加價。
	 *
	 * **只有客人主動指定那位人員時才收**（v2.62.0 修正）。
	 *
	 * 舊版是「只要那位人員被排到就加」——沒有看 staff_requested。於是客人在
	 * 前台選了「不指定（系統自動安排）」、系統剛好排到有加價的人員時，他會
	 * 被多收一筆錢，而且**購物車連「服務人員」那一行都不會出現**（那一行的
	 * 條件是 staff_requested，見 UAPPT_Cart::add_cart_item_data()）——等於多收
	 * 了錢卻沒有任何地方解釋。實測這個站的測試資料，本月的指定加價有 35%
	 * 來自「系統自動安排」。
	 *
	 * ⚠️ **只影響之後新建／改動的預約，不回頭改歷史資料。** 過去那些單客人
	 * 是真的付了那筆錢、訂單與發票都在，把報表改成「其實沒收」會讓報表跟
	 * 實際帳目對不起來。
	 *
	 * 規則收在這裡一處，是因為有三個地方會寫這個欄位（建立、改期、換人），
	 * 三份各自判斷遲早會漏掉一個——而漏掉的那一個不會報錯，只會讓某一條
	 * 路徑繼續多收錢。
	 *
	 * @param array $staff           人員資料（要有 price_adjustment）。
	 * @param bool  $staff_requested 客人有沒有主動指定這位人員。
	 * @return float 要記在這筆預約上的指定加價。
	 */
	public static function upcharge_for( $staff, $staff_requested ) {
		if ( ! $staff_requested || empty( $staff['price_adjustment'] ) ) {
			return 0.0;
		}
		return (float) $staff['price_adjustment'];
	}

	/**
	 * 接下來還排了多少（已確認、服務時間還沒到的預約）。
	 *
	 * 為什麼報表需要這個：上面每一個數字講的都是「已經發生的事」，但老闆看完
	 * 「這個月做了多少」，下一個問題必然是「那接下來呢」。這份資料一直都在
	 * 資料庫裡（未來的 confirmed 預約），只是從來沒有被report 用到。
	 *
	 * ⚠️ 它**不屬於任何一個統計區間**——區間是「已經過去的那一段」，這個是
	 * 「從現在往後」。所以呼叫端要把它跟其他數字視覺上分開，不能混在同一排
	 * 卡片裡假裝是同一件事。
	 *
	 * 只算 confirmed：held 是購物車暫留（隨時會過期）、completed 的未來預約
	 * 在語意上不該存在。金額用跟業績同一個欄位，沒有金額的（沒建訂單）算 0。
	 *
	 * @param int $days 往後看幾天。
	 * @return array{count:int, revenue:float, days:int, until:string}
	 */
	public static function get_scheduled_ahead( $days = 30 ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		$days  = max( 1, (int) $days );
		$now   = current_time( 'mysql' );
		$until = uappt_local_date( current_time( 'Y-m-d' ), '+' . $days . ' days' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, COALESCE( SUM( amount ), 0 ) AS revenue
				FROM {$table}
				WHERE kind = %s AND status = %s
					AND service_start > %s AND service_start <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::KIND_BOOKING,
				self::STATUS_CONFIRMED,
				$now,
				$until . ' 23:59:59'
			),
			ARRAY_A
		);

		return array(
			'count'   => $row ? (int) $row['n'] : 0,
			'revenue' => $row ? (float) $row['revenue'] : 0.0,
			'days'    => $days,
			'until'   => $until,
		);
	}

	/**
	 * 報表裡「可以逐列相加」的統計欄位。
	 *
	 * ⚠️ 抽成一份清單的理由：這串欄位原本在 build_*_report() 裡被抄了四份，
	 * 加一個新欄位就要記得四個地方都補——漏掉的那一份不會報錯，只會讓合計列
	 * 少加一欄，安靜地錯。
	 *
	 * **不包含 customer_count 以外的衍生欄位**（平均客單價、未到率、利用率）：
	 * 那些是比率，逐列相加沒有意義，各自的合計要用總分子除以總分母重算。
	 * customer_count 留在這裡是因為逐列表格的合計會被 build_overall_totals()
	 * 整個換掉（同一位客人來三天不能算三位，見 build_overview_report()）。
	 *
	 * @return array
	 */
	public static function summable_report_fields() {
		return array(
			'service_count',
			'no_show_count',
			'person_count',
			'customer_count',
			'requested_count',
			'revenue',
			'fee',
			'upcharge',
			'no_charge_count',
			'busy_minutes',
		);
	}

	/**
	 * 一組全是 0 的統計，供「這一格沒有資料」的地方使用。
	 *
	 * 抽出來是因為報表有七個頁籤、每一個都要在沒資料時填一份同樣形狀的陣列，
	 * 各自寫一份遲早會漏掉新加的欄位。
	 *
	 * @return array
	 */
	public static function empty_report_stats() {
		return array(
			'gkey'            => '',
			'staff_id'        => 0,
			'product_id'      => 0,
			'plan_key'        => '',
			'plan_name'       => '',
			'service_count'   => 0,
			'no_show_count'   => 0,
			'person_count'    => 0,
			'customer_count'  => 0,
			'requested_count' => 0,
			'revenue'         => 0.0,
			'fee'             => 0.0,
			'upcharge'        => 0.0,
			'no_charge_count' => 0,
			'busy_minutes'    => 0.0,
		);
	}

	/**
	 * 取消與未到的統計。
	 *
	 * ⚠️ **`expired` 絕對不能算進取消率。** 那是購物車暫留過期／待付款訂單逾時
	 * ——客人**根本沒買成**，屬於「結帳轉換率」的問題，不是「客人取消」。混在
	 * 一起會讓取消率憑空多出一倍，然後有人開始檢討一個不存在的問題。這一版把
	 * 它單獨列出來，就是為了讓兩件事永遠分得開。
	 *
	 * **取消時間用 `updated_at` 當代理。** 沒有專門的 `cancelled_at` 欄位，但
	 * `release()` 會在改狀態時更新 `updated_at`，而取消通常就是那筆預約的最後
	 * 一次異動。⚠️ 這是**代理不是事實**：如果取消之後又有人動過那筆資料，
	 * 前置時間就會失真。畫面上要寫明。
	 *
	 * 取消前置時間分得細是有意義的：當天取消補不到位，三天前取消還救得回來，
	 * 兩者的嚴重性差很多，混成一個「取消率」就看不出該不該調整取消政策。
	 *
	 * @param string $date_from 起始日期 (Y-m-d)。
	 * @param string $date_to   結束日期 (Y-m-d)。
	 * @param int    $staff_id  篩選人員；0 表示全部。
	 * @return array
	 */
	public static function get_cancellation_stats( $date_from, $date_to, $staff_id = 0 ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		$where  = array( 'kind = %s', 'service_start >= %s', 'service_start <= %s' );
		$params = array(
			self::KIND_BOOKING,
			sanitize_text_field( $date_from ) . ' 00:00:00',
			sanitize_text_field( $date_to ) . ' 23:59:59',
		);
		if ( $staff_id ) {
			$where[]  = 'staff_id = %d';
			$params[] = (int) $staff_id;
		}

		// 前置時間的分桶用 TIMESTAMPDIFF(HOUR, 取消時間, 服務時間)：正值＝服務前
		// 取消，負值＝服務時間都過了才被改成取消（那通常是事後補處理，不是客人
		// 主動取消）。
		$lead = 'TIMESTAMPDIFF( HOUR, updated_at, service_start )';

		$sql = "SELECT
				SUM( CASE WHEN status IN (%s, %s, %s) THEN 1 ELSE 0 END ) AS finalized,
				SUM( CASE WHEN status = %s THEN 1 ELSE 0 END ) AS cancelled,
				SUM( CASE WHEN status = %s THEN COALESCE( amount, 0 ) ELSE 0 END ) AS cancelled_amount,
				SUM( CASE WHEN status = %s THEN 1 ELSE 0 END ) AS no_show,
				SUM( CASE WHEN status = %s THEN COALESCE( amount, 0 ) ELSE 0 END ) AS no_show_amount,
				SUM( CASE WHEN status = %s THEN 1 ELSE 0 END ) AS expired,
				SUM( CASE WHEN status = %s AND {$lead} < 0 THEN 1 ELSE 0 END ) AS lead_after,
				SUM( CASE WHEN status = %s AND {$lead} >= 0 AND {$lead} < 24 THEN 1 ELSE 0 END ) AS lead_same_day,
				SUM( CASE WHEN status = %s AND {$lead} >= 24 AND {$lead} < 72 THEN 1 ELSE 0 END ) AS lead_1_3,
				SUM( CASE WHEN status = %s AND {$lead} >= 72 AND {$lead} < 168 THEN 1 ELSE 0 END ) AS lead_3_7,
				SUM( CASE WHEN status = %s AND {$lead} >= 168 THEN 1 ELSE 0 END ) AS lead_over_7
			FROM {$table}
			WHERE " . implode( ' AND ', $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$select_params = array(
			self::STATUS_CONFIRMED, self::STATUS_COMPLETED, self::STATUS_NO_SHOW,
			self::STATUS_CANCELLED,
			self::STATUS_CANCELLED,
			self::STATUS_NO_SHOW,
			self::STATUS_NO_SHOW,
			self::STATUS_EXPIRED,
			self::STATUS_CANCELLED, self::STATUS_CANCELLED, self::STATUS_CANCELLED,
			self::STATUS_CANCELLED, self::STATUS_CANCELLED,
		);

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( $sql, array_merge( $select_params, $params ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		// 查詢萬一失敗（語法錯、資料表不在）時回傳一組 0，不要再連鎖噴出
		// 一串 undefined index——那會把真正的錯誤訊息淹掉。
		if ( ! $row ) {
			$row = array_fill_keys(
				array( 'finalized', 'cancelled', 'cancelled_amount', 'no_show', 'no_show_amount', 'expired', 'lead_after', 'lead_same_day', 'lead_1_3', 'lead_3_7', 'lead_over_7' ),
				0
			);
		}

		$finalized = (int) $row['finalized'];
		$cancelled = (int) $row['cancelled'];
		$no_show   = (int) $row['no_show'];

		// 分母是「已結案 + 取消」——也就是所有真的排進去過的預約。`expired`
		// 不在分母裡：那些從來沒有成為一筆有效預約。
		$base = $finalized + $cancelled;

		return array(
			'finalized'        => $finalized,
			'cancelled'        => $cancelled,
			'cancelled_amount' => (float) $row['cancelled_amount'],
			'cancel_rate'      => $base > 0 ? $cancelled / $base : 0.0,
			'no_show'          => $no_show,
			'no_show_amount'   => (float) $row['no_show_amount'],
			'no_show_rate'     => $finalized > 0 ? $no_show / $finalized : 0.0,
			'expired'          => (int) $row['expired'],
			'lead'             => array(
				'after'    => (int) $row['lead_after'],
				'same_day' => (int) $row['lead_same_day'],
				'd1_3'     => (int) $row['lead_1_3'],
				'd3_7'     => (int) $row['lead_3_7'],
				'over_7'   => (int) $row['lead_over_7'],
			),
		);
	}

	/**
	 * 預約前置期：客人平均提前多久訂。
	 *
	 * 這個數字直接指導「開放預約天數」該設多少——如果九成的人都在一週內訂，
	 * 開放 90 天只是讓月曆變長；反過來如果有人會提前一個月訂，開放 14 天就會
	 * 擋掉生意。
	 *
	 * @param string $date_from 起始日期 (Y-m-d)。
	 * @param string $date_to   結束日期 (Y-m-d)。
	 * @param int    $staff_id  篩選人員；0 表示全部。
	 * @return array ['buckets' => array, 'median' => float, 'total' => int]
	 */
	public static function get_lead_time_buckets( $date_from, $date_to, $staff_id = 0 ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		$where  = array( 'kind = %s', 'status IN (%s, %s, %s)', 'service_start >= %s', 'service_start <= %s' );
		$params = array(
			self::KIND_BOOKING,
			self::STATUS_CONFIRMED,
			self::STATUS_COMPLETED,
			self::STATUS_NO_SHOW,
			sanitize_text_field( $date_from ) . ' 00:00:00',
			sanitize_text_field( $date_to ) . ' 23:59:59',
		);
		if ( $staff_id ) {
			$where[]  = 'staff_id = %d';
			$params[] = (int) $staff_id;
		}

		// 負的前置期＝後台補登（服務都做完了才建紀錄），歸進「當天」比較合理，
		// 用 GREATEST 夾到 0。
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT GREATEST( DATEDIFF( service_start, created_at ), 0 ) AS d, COUNT(*) AS n
				FROM {$table} WHERE " . implode( ' AND ', $where ) . '
				GROUP BY d ORDER BY d ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$params
			),
			ARRAY_A
		);

		$buckets = array(
			'same_day' => 0,
			'd1_3'     => 0,
			'd4_7'     => 0,
			'd8_14'    => 0,
			'over_14'  => 0,
		);
		$all   = array();
		$total = 0;

		foreach ( $rows as $row ) {
			$days = (int) $row['d'];
			$n    = (int) $row['n'];
			$total += $n;

			if ( 0 === $days ) {
				$buckets['same_day'] += $n;
			} elseif ( $days <= 3 ) {
				$buckets['d1_3'] += $n;
			} elseif ( $days <= 7 ) {
				$buckets['d4_7'] += $n;
			} elseif ( $days <= 14 ) {
				$buckets['d8_14'] += $n;
			} else {
				$buckets['over_14'] += $n;
			}

			// 中位數：把每一個天數重複 n 次攤平。資料量不大（一個期間最多幾千
			// 筆、天數種類更少），不值得為此寫累積計數的二分搜尋。
			$all = array_merge( $all, array_fill( 0, $n, $days ) );
		}

		$median = 0.0;
		if ( $all ) {
			sort( $all );
			$count  = count( $all );
			$middle = (int) floor( $count / 2 );
			$median = ( 0 === $count % 2 ) ? ( $all[ $middle - 1 ] + $all[ $middle ] ) / 2 : $all[ $middle ];
		}

		return array(
			'buckets' => $buckets,
			'median'  => $median,
			'total'   => $total,
		);
	}

	/**
	 * 預約來源：客人自己在前台訂的，還是櫃檯／電話幫他建的。
	 *
	 * 判斷依據是 `created_by`：前台流程沒有帶這個欄位（訪客根本沒有登入），
	 * 後台手動建單一律帶 `get_current_user_id()`。**不需要新欄位**。
	 *
	 * ⚠️ 已知的邊界：如果店家是用自己的管理員帳號登入前台幫客人下單，那筆會被
	 * 算成「前台自助」——因為它走的確實是前台流程。實務上很少見。
	 *
	 * @param string $date_from 起始日期 (Y-m-d)。
	 * @param string $date_to   結束日期 (Y-m-d)。
	 * @param int    $staff_id  篩選人員；0 表示全部。
	 * @return array ['online' => int, 'manual' => int, 'online_rate' => float]
	 */
	public static function get_source_breakdown( $date_from, $date_to, $staff_id = 0 ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		$where  = array( 'kind = %s', 'status IN (%s, %s, %s)', 'service_start >= %s', 'service_start <= %s' );
		$params = array(
			self::KIND_BOOKING,
			self::STATUS_CONFIRMED,
			self::STATUS_COMPLETED,
			self::STATUS_NO_SHOW,
			sanitize_text_field( $date_from ) . ' 00:00:00',
			sanitize_text_field( $date_to ) . ' 23:59:59',
		);
		if ( $staff_id ) {
			$where[]  = 'staff_id = %d';
			$params[] = (int) $staff_id;
		}

		// ⚠️ 欄位別名不能叫 `manual`：MySQL 8.0.31 起把 `MANUAL` 列為**保留字**
		// （同批的還有 `AUTO`、`PARALLEL`、`QUALIFY`、`TABLESAMPLE`），`AS manual`
		// 會直接噴語法錯誤。舊版 MySQL 不會，所以這種 bug 只在比較新的主機上
		// 才出現——別名一律加上用途後綴（`_count`）就不會踩到。
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT
					SUM( CASE WHEN created_by IS NULL OR created_by = 0 THEN 1 ELSE 0 END ) AS online_count,
					SUM( CASE WHEN created_by > 0 THEN 1 ELSE 0 END ) AS manual_count
				FROM {$table} WHERE " . implode( ' AND ', $where ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$params
			),
			ARRAY_A
		);

		$online = $row ? (int) $row['online_count'] : 0;
		$manual = $row ? (int) $row['manual_count'] : 0;
		$total  = $online + $manual;

		return array(
			'online'      => $online,
			'manual'      => $manual,
			'online_rate' => $total > 0 ? $online / $total : 0.0,
		);
	}

	/**
	 * 回店預約率：客人做完之後，有沒有再約下一次。
	 *
	 * **美業公認的第一領先指標。** 業績、客單價都是回頭看（已經發生的事），
	 * 這個是往前看——客人離店時有沒有約下一次，直接決定下一期的營收底線。
	 *
	 * 定義：
	 *
	 * - 分母：期間內**已完成**（`completed`）的預約筆數
	 * - 分子：其中「該客人在本次服務開始**之後** 7 天內，建立了一筆服務時間
	 *   更晚的預約」的筆數
	 *
	 * ⚠️ **條件是 `created_at`（何時訂的）不是 `service_start`（何時來）**，
	 * 這是刻意的：這個指標問的是「我們有沒有成功讓他當場／馬上約下一次」，
	 * 不是「他後來有沒有再出現」。副作用是**客人一次預約兩次**（第二筆的
	 * `created_at` 早於第一筆的服務時間）不會被算進去——那確實也是好事，但屬於
	 * 另一種行為，混在一起這個數字就沒辦法拿來檢討櫃檯的話術了。
	 *
	 * 另外給一個「當天內」的版本：客人離店前就約好下一次是最理想的狀態，
	 * 跟「回去想了三天才打電話來」在經營意義上不一樣。
	 *
	 * **實作刻意不用相關子查詢**（`EXISTS (... WHERE ckey = ckey)`）：去重鍵是
	 * 運算出來的欄位，用不到索引，逐列跑一次子查詢在資料量大時會很慘。改成
	 * 兩次查詢撈回來在 PHP 比對——一間店一年幾千到一萬筆，記憶體完全吃得下。
	 *
	 * @param string $date_from 起始日期 (Y-m-d)。
	 * @param string $date_to   結束日期 (Y-m-d)。
	 * @param int    $staff_id  篩選人員；0 表示全部。
	 * @return array ['completed','rebooked','rebooked_same_day','rate','rate_same_day']
	 */
	public static function get_rebooking_stats( $date_from, $date_to, $staff_id = 0 ) {
		global $wpdb;

		$table = UAPPT_Install::table( 'bookings' );
		$ckey  = self::customer_key_sql();
		$now   = current_time( 'mysql' );
		$from  = sanitize_text_field( $date_from ) . ' 00:00:00';
		$to    = sanitize_text_field( $date_to ) . ' 23:59:59';

		// A：期間內已完成的服務（分母）。
		$where  = array( 'kind = %s', 'status = %s', 'service_start >= %s', 'service_start <= %s', 'service_start <= %s' );
		$params = array( self::KIND_BOOKING, self::STATUS_COMPLETED, $from, $to, $now );
		if ( $staff_id ) {
			$where[]  = 'staff_id = %d';
			$params[] = (int) $staff_id;
		}

		$done = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT id, {$ckey} AS ckey, service_start FROM {$table} WHERE " . implode( ' AND ', $where ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$params
			),
			ARRAY_A
		);

		if ( ! $done ) {
			return array(
				'completed'         => 0,
				'rebooked'          => 0,
				'rebooked_same_day' => 0,
				'rate'              => 0.0,
				'rate_same_day'     => 0.0,
			);
		}

		// B：可能成為「下一次」的預約。**這裡不套人員篩選**：客人回來時換一位
		// 人員也是回來了，算在原本那位人員的回店率上才公平——是他讓客人願意
		// 再回這間店。
		$window_to = gmdate( 'Y-m-d H:i:s', strtotime( $to ) + 7 * DAY_IN_SECONDS );
		$next      = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT id, {$ckey} AS ckey, service_start, created_at FROM {$table}
				WHERE kind = %s AND status IN (%s, %s)
					AND created_at >= %s AND created_at <= %s
					AND service_start > %s",
				self::KIND_BOOKING,
				self::STATUS_CONFIRMED,
				self::STATUS_COMPLETED,
				$from,
				$window_to,
				$from
			),
			ARRAY_A
		);

		$by_customer = array();
		foreach ( $next as $row ) {
			$by_customer[ $row['ckey'] ][] = $row;
		}

		$rebooked = 0;
		$same_day = 0;

		foreach ( $done as $row ) {
			if ( empty( $by_customer[ $row['ckey'] ] ) ) {
				continue;
			}

			$service_ts = strtotime( $row['service_start'] );
			$hit        = false;
			$hit_today  = false;

			foreach ( $by_customer[ $row['ckey'] ] as $candidate ) {
				// 不能是自己；下一次的服務時間要更晚；而且要在這次服務**開始
				// 之後**才訂的。
				if ( (int) $candidate['id'] === (int) $row['id'] ) {
					continue;
				}
				if ( strtotime( $candidate['service_start'] ) <= $service_ts ) {
					continue;
				}

				$created_ts = strtotime( $candidate['created_at'] );
				if ( $created_ts <= $service_ts || $created_ts > $service_ts + 7 * DAY_IN_SECONDS ) {
					continue;
				}

				$hit = true;
				if ( substr( $candidate['created_at'], 0, 10 ) === substr( $row['service_start'], 0, 10 ) ) {
					$hit_today = true;
				}
			}

			if ( $hit ) {
				$rebooked++;
			}
			if ( $hit_today ) {
				$same_day++;
			}
		}

		$total = count( $done );

		return array(
			'completed'         => $total,
			'rebooked'          => $rebooked,
			'rebooked_same_day' => $same_day,
			'rate'              => $total > 0 ? $rebooked / $total : 0.0,
			'rate_same_day'     => $total > 0 ? $same_day / $total : 0.0,
		);
	}

	/**
	 * 回訪週期：同一位客人相鄰兩次到訪隔幾天。
	 *
	 * ⚠️ **取中位數不取平均。** 少數隔半年才回來一次的客人會把平均拉爆，算出來
	 * 的數字對排班與提醒完全沒有指導意義——美甲的補甲週期是 3–4 週，平均算成
	 * 兩個多月的話，提醒就會晚一個月才發。
	 *
	 * 統計的是「**後面那一次**落在這段期間內」的間隔：這樣期間換了數字就會跟著
	 * 換，而不是永遠回傳同一個全站歷史平均。
	 *
	 * ⚠️ **往前只看一年**。間隔超過一年的客人是流失客不是回頭客，把那種間隔算
	 * 進來會讓中位數失去意義；順便也把查詢的資料量框住。
	 *
	 * 一樣不用視窗函數（`LAG()`）——WP 最低支援的 MySQL 5.7 沒有。撈回來在 PHP
	 * 走一遍就好。
	 *
	 * @param string $date_from 起始日期 (Y-m-d)。
	 * @param string $date_to   結束日期 (Y-m-d)。
	 * @param int    $staff_id  篩選人員；0 表示全部。
	 * @return array ['median','average','samples']
	 */
	public static function get_visit_gaps( $date_from, $date_to, $staff_id = 0 ) {
		global $wpdb;

		$table   = UAPPT_Install::table( 'bookings' );
		$ckey    = self::customer_key_sql();
		$now     = current_time( 'mysql' );
		$from    = sanitize_text_field( $date_from ) . ' 00:00:00';
		$to      = sanitize_text_field( $date_to ) . ' 23:59:59';
		$lookback = gmdate( 'Y-m-d H:i:s', strtotime( $from ) - YEAR_IN_SECONDS );

		// 人員篩選套在整段撈取上：問的是「這位人員的客人多久回來一次」，
		// 所以「上一次」也要是同一位人員服務的，不然算出來的是店的節奏不是他的。
		$where  = array( 'kind = %s', 'status IN (%s, %s, %s)', 'service_start >= %s', 'service_start <= %s', 'service_start <= %s' );
		$params = array(
			self::KIND_BOOKING,
			self::STATUS_CONFIRMED,
			self::STATUS_COMPLETED,
			self::STATUS_NO_SHOW,
			$lookback,
			$to,
			$now,
		);
		if ( $staff_id ) {
			$where[]  = 'staff_id = %d';
			$params[] = (int) $staff_id;
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT {$ckey} AS ckey, service_start FROM {$table}
				WHERE " . implode( ' AND ', $where ) . '
				ORDER BY ckey ASC, service_start ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$params
			),
			ARRAY_A
		);

		$gaps      = array();
		$prev_key  = null;
		$prev_time = null;
		$from_ts   = strtotime( $from );

		foreach ( $rows as $row ) {
			$ts = strtotime( $row['service_start'] );

			if ( $row['ckey'] === $prev_key && null !== $prev_time ) {
				// 只算「後面那一次」落在期間內的間隔。
				if ( $ts >= $from_ts ) {
					$gaps[] = ( $ts - $prev_time ) / DAY_IN_SECONDS;
				}
			}

			$prev_key  = $row['ckey'];
			$prev_time = $ts;
		}

		if ( ! $gaps ) {
			return array(
				'median'  => 0.0,
				'average' => 0.0,
				'samples' => 0,
			);
		}

		sort( $gaps );
		$count  = count( $gaps );
		$middle = (int) floor( $count / 2 );
		$median = ( 0 === $count % 2 )
			? ( $gaps[ $middle - 1 ] + $gaps[ $middle ] ) / 2
			: $gaps[ $middle ];

		return array(
			'median'  => round( $median, 1 ),
			'average' => round( array_sum( $gaps ) / $count, 1 ),
			'samples' => $count,
		);
	}

	/**
	 * 消費最多的客人（Top 客人排行）。
	 *
	 * 只算服務消費（`bookings.amount`）——產品消費由呼叫端另外補上，因為那要
	 * 問 WooCommerce 訂單，不是這張表的事（見 `UAPPT_Customer::get_product_spend()`）。
	 *
	 * @param string $date_from 起始日期 (Y-m-d)。
	 * @param string $date_to   結束日期 (Y-m-d)。
	 * @param int    $staff_id  篩選人員；0 表示全部。
	 * @param int    $limit     取前幾名。
	 * @return array
	 */
	public static function get_top_customers( $date_from, $date_to, $staff_id = 0, $limit = 20 ) {
		global $wpdb;

		$table = UAPPT_Install::table( 'bookings' );
		$ckey  = self::customer_key_sql();
		$now   = current_time( 'mysql' );

		$where  = array( 'kind = %s', 'status IN (%s, %s, %s)', 'service_start >= %s', 'service_start <= %s', 'service_start <= %s' );
		$params = array(
			self::KIND_BOOKING,
			self::STATUS_CONFIRMED,
			self::STATUS_COMPLETED,
			self::STATUS_NO_SHOW,
			sanitize_text_field( $date_from ) . ' 00:00:00',
			sanitize_text_field( $date_to ) . ' 23:59:59',
			$now,
		);
		if ( $staff_id ) {
			$where[]  = 'staff_id = %d';
			$params[] = (int) $staff_id;
		}
		$params[] = max( 1, (int) $limit );

		// 「最後一次的姓名」跟回訪管理用同一招（GROUP_CONCAT + SUBSTRING_INDEX），
		// 理由見 UAPPT_Customer::build_lapsed_query()：MySQL 5.7 沒有視窗函數，
		// 而 GROUP_CONCAT 是從尾端截斷、我們取第一段，所以截斷不影響正確性。
		$latest = function ( $expr ) {
			return "SUBSTRING_INDEX( GROUP_CONCAT( {$expr} ORDER BY service_start DESC SEPARATOR 0x1f ), 0x1f, 1 )";
		};

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT
					{$ckey} AS ckey,
					MAX( customer_id ) AS customer_id,
					COUNT(*) AS visits,
					COALESCE( SUM( amount ), 0 ) AS service_spend,
					MAX( service_start ) AS last_visit,
					" . $latest( "NULLIF( TRIM( COALESCE( customer_name, '' ) ), '' )" ) . " AS customer_name,
					" . $latest( "NULLIF( TRIM( COALESCE( customer_phone, '' ) ), '' )" ) . " AS customer_phone
				FROM {$table}
				WHERE " . implode( ' AND ', $where ) . "
				GROUP BY ckey
				ORDER BY service_spend DESC, visits DESC
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$params
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * 新客與回頭客**各帶來多少業績**（不只是人數）。
	 *
	 * 為什麼需要這個：舊版只有「新客 16 位、回頭客 35 位」的人數。但老闆要
	 * 決定的是「行銷預算要放在拉新還是做回訪」，而那取決於**錢**——16 位新客
	 * 帶來 12 萬跟帶來 3 萬，結論完全相反。人數相同、金額差四倍是很常見的。
	 *
	 * 「新客」的定義跟 get_customer_breakdown() 完全一致：這位客人在本站的
	 * **第一筆**預約就落在這段期間內。⚠️ 篩選了特定人員時，「第一次」仍然
	 * 以整間店為準（子查詢不帶 staff 條件）——不然老客人換一位服務人員就會
	 * 被算成新客。
	 *
	 * @param string  起始日期。
	 * @param string    結束日期。
	 * @param int      篩選人員；0 代表全部。
	 * @return array{new:array{count:int,revenue:float}, returning:array{count:int,revenue:float}}
	 */
	public static function get_revenue_by_customer_type( $date_from, $date_to, $staff_id = 0 ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );
		$ckey  = self::customer_key_sql();

		$from_mysql = sanitize_text_field( $date_from ) . ' 00:00:00';
		$to_mysql   = sanitize_text_field( $date_to ) . ' 23:59:59';
		$staff_cond = $staff_id ? 'AND staff_id = %d' : '';

		$sql = "SELECT
				MIN( service_start ) AS first_at,
				SUM( CASE WHEN service_start >= %s AND service_start <= %s {$staff_cond} THEN 1 ELSE 0 END ) AS in_range,
				SUM( CASE WHEN service_start >= %s AND service_start <= %s {$staff_cond} THEN amount ELSE 0 END ) AS revenue
			FROM (
				SELECT {$ckey} AS ckey, service_start, staff_id, amount
				FROM {$table}
				WHERE kind = %s AND status IN (%s, %s, %s) AND service_start <= %s
			) t
			GROUP BY ckey
			HAVING in_range > 0"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$params = array( $from_mysql, $to_mysql );
		if ( $staff_id ) {
			$params[] = (int) $staff_id;
		}
		$params[] = $from_mysql;
		$params[] = $to_mysql;
		if ( $staff_id ) {
			$params[] = (int) $staff_id;
		}
		$params[] = self::KIND_BOOKING;
		$params[] = self::STATUS_CONFIRMED;
		$params[] = self::STATUS_COMPLETED;
		$params[] = self::STATUS_NO_SHOW;
		$params[] = current_time( 'mysql' );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array(
			'new'       => array( 'count' => 0, 'revenue' => 0.0 ),
			'returning' => array( 'count' => 0, 'revenue' => 0.0 ),
		);
		foreach ( (array) $rows as $row ) {
			$bucket = $row['first_at'] >= $from_mysql ? 'new' : 'returning';
			$out[ $bucket ]['count']++;
			$out[ $bucket ]['revenue'] += (float) $row['revenue'];
		}

		return $out;
	}

	/**
	 * 這段期間的客人裡，有幾位是第一次來、幾位是回頭客。
	 *
	 * 美業看回頭率比看總業績有意義得多，但這件事沒辦法跟上面那支聚合在同一次
	 * 查詢裡做：判斷「第一次」要看的是**這位客人在本站的所有歷史**，不是只有
	 * 這段期間。
	 *
	 * ⚠️ **篩選了特定人員時，「第一次」仍然是以整間店為準**（內層不套人員
	 * 篩選，只有「這段期間有沒有被這位人員服務過」才套）。不然「新客」會變成
	 * 「第一次給這位人員做」，那是另一個意思——老客人換一位美甲師會被算成新客，
	 * 回頭率就完全失真了。
	 *
	 * 去重鍵跟 `get_report_stats()` 完全一樣，兩邊不一致的話同一份報表上
	 * 「不重複客人」跟「新客 + 回頭客」會對不起來。
	 *
	 * @param string $date_from 起始日期 (Y-m-d)。
	 * @param string $date_to   結束日期 (Y-m-d)。
	 * @param int    $staff_id  篩選人員；0 表示全部。
	 * @return array ['new' => int, 'returning' => int]
	 */
	public static function get_customer_breakdown( $date_from, $date_to, $staff_id = 0 ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'bookings' );

		$customer_key = self::customer_key_sql();

		$from_mysql = sanitize_text_field( $date_from ) . ' 00:00:00';
		$to_mysql   = sanitize_text_field( $date_to ) . ' 23:59:59';
		$staff_cond = $staff_id ? 'AND staff_id = %d' : '';

		$sql = "SELECT
				MIN( service_start ) AS first_at,
				SUM( CASE WHEN service_start >= %s AND service_start <= %s {$staff_cond} THEN 1 ELSE 0 END ) AS in_range
			FROM (
				SELECT {$customer_key} AS ckey, service_start, staff_id
				FROM {$table}
				WHERE kind = %s AND status IN (%s, %s, %s) AND service_start <= %s
			) t
			GROUP BY ckey
			HAVING in_range > 0"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$params = array( $from_mysql, $to_mysql );
		if ( $staff_id ) {
			$params[] = (int) $staff_id;
		}
		$params[] = self::KIND_BOOKING;
		$params[] = self::STATUS_CONFIRMED;
		$params[] = self::STATUS_COMPLETED;
		$params[] = self::STATUS_NO_SHOW;
		$params[] = current_time( 'mysql' );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$new       = 0;
		$returning = 0;
		foreach ( (array) $rows as $row ) {
			if ( $row['first_at'] >= $from_mysql ) {
				$new++;
			} else {
				$returning++;
			}
		}

		return array(
			'new'       => $new,
			'returning' => $returning,
		);
	}

	/* ---------------------------------------------------------------------
	 * 內部工具方法
	 * ------------------------------------------------------------------- */

	/**
	 * 解析某個服務（商品/變化款設定）在指定條件下的候選人員清單。
	 *
	 * @param array $settings UAPPT_Product::get_booking_settings() 的回傳值，須含 'staff_ids'。
	 * @param int   $staff_id 指定的人員 ID，0 表示不指定（回傳所有候選人員）。
	 * @return array|WP_Error 人員資料陣列（依 sort_order 排序），或錯誤。
	 */
	protected static function resolve_candidate_staff( $settings, $staff_id = 0 ) {
		$allowed_ids = isset( $settings['staff_ids'] ) ? array_map( 'intval', (array) $settings['staff_ids'] ) : array();
		if ( empty( $allowed_ids ) ) {
			return new WP_Error( 'uappt_no_staff', __( '此服務尚未指定可服務的人員，請聯繫客服。', 'ultimate-appointments' ) );
		}

		if ( $staff_id ) {
			if ( ! in_array( (int) $staff_id, $allowed_ids, true ) ) {
				return new WP_Error( 'uappt_staff_not_allowed', __( '這位人員不提供此服務，請重新選擇。', 'ultimate-appointments' ) );
			}
			$staff = UAPPT_Staff::get( (int) $staff_id );
			if ( ! $staff || 'active' !== $staff['status'] ) {
				return new WP_Error( 'uappt_staff_unavailable', __( '這位人員目前無法預約，請重新選擇。', 'ultimate-appointments' ) );
			}
			return array( $staff );
		}

		$rows   = UAPPT_Staff::get_many( $allowed_ids );
		$active = array_values(
			array_filter(
				$rows,
				function ( $s ) {
					return 'active' === $s['status'];
				}
			)
		);

		usort(
			$active,
			function ( $a, $b ) {
				return $a['sort_order'] <=> $b['sort_order'];
			}
		);

		return $active;
	}

	/**
	 * 為每位候選人員計算「這個服務時段是否落在他的班表內」，並算出各自要鎖定
	 * 的時間格。回傳只包含真正符合班表的人員。
	 *
	 * @param array  $candidates       候選人員清單。
	 * @param int    $service_start_ts 服務開始時間戳。
	 * @param int    $service_end_ts   服務結束時間戳。
	 * @param string $date_ymd         使用者選擇的日期 (Y-m-d)，用來取得候選營業區間。
	 * @param int    $buf_pre          服務前緩衝（分鐘）。
	 * @param int    $buf_post         服務後緩衝（分鐘）。
	 * @return array staff_id => ['staff','interval','block_start_ts','block_end_ts','cells'].
	 */
	protected static function build_eligible_staff_cells( $candidates, $service_start_ts, $service_end_ts, $date_ymd, $buf_pre, $buf_post ) {
		$eligible = array();

		foreach ( $candidates as $staff ) {
			$window = self::resolve_booking_window_for_staff( $staff, $service_start_ts, $service_end_ts, $date_ymd );
			if ( ! $window ) {
				continue;
			}

			$interval       = UAPPT_Staff::get_slot_interval( $staff );
			$block_start_ts = $service_start_ts - $buf_pre * 60;
			$block_end_ts   = $service_end_ts + $buf_post * 60;
			$cells          = self::grid_cells( $block_start_ts, $block_end_ts, $interval );

			if ( empty( $cells ) ) {
				continue;
			}

			$eligible[ (int) $staff['id'] ] = array(
				'staff'          => $staff,
				'interval'       => $interval,
				'block_start_ts' => $block_start_ts,
				'block_end_ts'   => $block_end_ts,
				'cells'          => $cells,
			);
		}

		return $eligible;
	}

	/**
	 * 找出完整包住某個 [service_start_ts, service_end_ts) 的營業區間（單一人員）。
	 *
	 * @param array  $staff            人員資料。
	 * @param int    $service_start_ts 服務開始時間戳。
	 * @param int    $service_end_ts   服務結束時間戳。
	 * @param string $date_ymd         使用者選擇的日期 (Y-m-d)，用來取得候選營業區間。
	 * @return array|null 找到則回傳該區間（含 'owner_date'），找不到回傳 null。
	 */
	protected static function resolve_booking_window_for_staff( $staff, $service_start_ts, $service_end_ts, $date_ymd ) {
		foreach ( UAPPT_Staff::get_business_windows( $staff, $date_ymd ) as $window ) {
			if ( $service_start_ts >= $window['open_ts'] && $service_end_ts <= $window['close_ts'] ) {
				return $window;
			}
		}
		return null;
	}

	/**
	 * 確保 $eligible 內每位人員涉及的時間格資料列都存在（不存在則以 occupied=0
	 * 建立），避免後面的 SELECT ... FOR UPDATE 鎖不到不存在的列造成的競爭空隙。
	 *
	 * ⚠️ `INSERT … ON DUPLICATE KEY UPDATE` 遇到既有的列**本身就會上排他鎖**，所以
	 * 真正的上鎖順序是這裡決定的，不是 lock_cells()。這裡跟 lock_cells() 用同一個排序
	 * （staff_id、slot_start），防死結的排序才有效（v3.0.1 以前是照候選人員順序寫，
	 * 排序白做）。
	 *
	 * @param array $eligible build_eligible_staff_cells() 的回傳值。
	 * @param array $extra    另外要確保存在的格子：staff_id => cells（改期／換人的舊格子）。
	 */
	protected static function ensure_cells_exist( $eligible, $extra = array() ) {
		global $wpdb;
		$grid_table = UAPPT_Install::table( 'slot_grid' );

		$pairs = array();
		foreach ( $eligible as $staff_id => $info ) {
			foreach ( $info['cells'] as $cell ) {
				$pairs[ $staff_id . '|' . $cell ] = array( (int) $staff_id, $cell );
			}
		}
		foreach ( $extra as $staff_id => $cells ) {
			foreach ( (array) $cells as $cell ) {
				$pairs[ $staff_id . '|' . $cell ] = array( (int) $staff_id, $cell );
			}
		}

		usort(
			$pairs,
			function ( $a, $b ) {
				return $a[0] <=> $b[0] ?: strcmp( $a[1], $b[1] );
			}
		);

		foreach ( $pairs as $pair ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"INSERT INTO {$grid_table} (staff_id, slot_start, occupied) VALUES (%d, %s, 0) ON DUPLICATE KEY UPDATE id = id", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$pair[0],
					$pair[1]
				)
			);
		}
	}

	/**
	 * 鎖定（SELECT ... FOR UPDATE）$eligible 內所有人員的所有相關時間格，
	 * 依 (staff_id, slot_start) 固定排序後逐一鎖定，降低跟其他併發交易互相
	 * 等待造成死結的機率。
	 *
	 * @param array $eligible build_eligible_staff_cells() 的回傳值。
	 * @return array "staff_id|slot_start" => occupied(int)。
	 */
	protected static function lock_cells( $eligible ) {
		$pairs = array();
		foreach ( $eligible as $staff_id => $info ) {
			foreach ( $info['cells'] as $cell ) {
				$pairs[] = array( $staff_id, $cell );
			}
		}

		usort(
			$pairs,
			function ( $a, $b ) {
				return $a[0] <=> $b[0] ?: strcmp( $a[1], $b[1] );
			}
		);

		$map = array();
		global $wpdb;
		$grid_table = UAPPT_Install::table( 'slot_grid' );

		foreach ( $pairs as $pair ) {
			list( $staff_id, $cell ) = $pair;
			$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"SELECT occupied FROM {$grid_table} WHERE staff_id = %d AND slot_start = %s FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$staff_id,
					$cell
				),
				ARRAY_A
			);
			// 讀不到＝鎖不到（ensure_cells_exist() 剛建過，正常一定讀得到；讀不到就是
			// 鎖等待逾時或死結被回滾）。當成**滿的**：寧可回「時段剛被預約走」請客人
			// 重選，也不要把一格沒鎖住的當成空的賣出去。
			$map[ $staff_id . '|' . $cell ] = $row ? (int) $row['occupied'] : PHP_INT_MAX;
		}

		return $map;
	}

	/**
	 * 判斷某位人員的一批時間格是否還有名額（用於 lock_cells() 的結果）。
	 *
	 * `occupied` 是計數器不是旗標——只要有任何一格已經滿了（達到 $capacity），
	 * 這一整批就算不通過，因為一筆預約要成立，區塊裡每一格都要同時有空位。
	 *
	 * @param array $occupied_map lock_cells() 回傳的 map。
	 * @param int   $staff_id     人員 ID。
	 * @param array $cells        時間格清單。
	 * @param int   $capacity     這位人員同時可服務的人數上限。
	 * @param int   $units        這次要佔用幾個名額（一般預約是 1；時段佔用可能一次吃掉整個容量）。
	 * @return bool
	 */
	protected static function cells_are_free( $occupied_map, $staff_id, $cells, $capacity, $units = 1 ) {
		$capacity = max( 1, (int) $capacity );
		$units    = max( 1, (int) $units );
		foreach ( $cells as $cell ) {
			$key      = $staff_id . '|' . $cell;
			$occupied = (int) ( isset( $occupied_map[ $key ] ) ? $occupied_map[ $key ] : 0 );
			if ( $occupied + $units > $capacity ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 把某位人員的一批時間格佔用數加一或減一。
	 *
	 * 語意是「delta」不是「設成某個值」——`occupied` 是計數器，可能同時有多筆
	 * 預約共用同一格（容量 > 1），一筆預約的佔用/釋放永遠只影響自己的那一個單位，
	 * 不能覆寫其他預約留下的數字。`GREATEST(0, ...)` 是防禦性寫法，避免任何
	 * 意外的重複釋放讓計數器變成負數。
	 *
	 * @param int   $staff_id 人員 ID。
	 * @param array $cells    時間格清單。
	 * @param int   $delta    +1（佔用一個單位）或 -1（釋放一個單位）。
	 */
	protected static function set_cells_occupied( $staff_id, $cells, $delta ) {
		if ( empty( $cells ) ) {
			return;
		}
		global $wpdb;
		$grid_table   = UAPPT_Install::table( 'slot_grid' );
		$placeholders = implode( ',', array_fill( 0, count( $cells ), '%s' ) );
		$sql          = "UPDATE {$grid_table} SET occupied = GREATEST(0, occupied + %d) WHERE staff_id = %d AND slot_start IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( $sql, array_merge( array( (int) $delta, $staff_id ), $cells ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 判斷某位人員在 [block_start, block_end) 是否整段都還有名額（前台查詢可預約
	 * 時段用，讀取而非鎖定）。
	 *
	 * @param array $occupancy_map slot_start(mysql string) => occupied(int)。
	 * @param int   $block_start_ts 區塊開始 timestamp。
	 * @param int   $block_end_ts   區塊結束 timestamp（不含）。
	 * @param int   $interval       時間格顆粒（分鐘）。
	 * @param int   $capacity       這位人員同時可服務的人數上限。
	 * @return bool
	 */
	protected static function staff_is_free( $occupancy_map, $block_start_ts, $block_end_ts, $interval, $capacity ) {
		$capacity = max( 1, (int) $capacity );
		foreach ( self::grid_cells( $block_start_ts, $block_end_ts, $interval ) as $cell ) {
			if ( (int) ( isset( $occupancy_map[ $cell ] ) ? $occupancy_map[ $cell ] : 0 ) >= $capacity ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 這個區塊裡，某位人員還能再排幾筆這個時長的預約。
	 *
	 * 用「最擁擠的那一格」決定，不是加總或平均——一筆新預約要成立，區塊內每一格
	 * 都要同時有空位，所以名額上限被佔用最多的那一格卡住。用於「剩 X 位」的顯示，
	 * 不用於實際鎖定判斷（鎖定判斷一律用 cells_are_free()，那支在交易內對
	 * lock_cells() 鎖住的即時資料做判斷；這支讀的是查詢當下的快照，只做顯示用）。
	 *
	 * @param array $occupancy_map slot_start(mysql string) => occupied(int)。
	 * @param int   $block_start_ts 區塊開始 timestamp。
	 * @param int   $block_end_ts   區塊結束 timestamp（不含）。
	 * @param int   $interval       時間格顆粒（分鐘）。
	 * @param int   $capacity       這位人員同時可服務的人數上限。
	 * @return int
	 */
	protected static function remaining_capacity_in_block( $occupancy_map, $block_start_ts, $block_end_ts, $interval, $capacity ) {
		$capacity      = max( 1, (int) $capacity );
		$max_occupied  = 0;
		foreach ( self::grid_cells( $block_start_ts, $block_end_ts, $interval ) as $cell ) {
			$occupied     = (int) ( isset( $occupancy_map[ $cell ] ) ? $occupancy_map[ $cell ] : 0 );
			$max_occupied = max( $max_occupied, $occupied );
		}
		return max( 0, $capacity - $max_occupied );
	}

	/**
	 * 從一批「都有空」的候選人員中，挑出當日已排班數最少的一位（讓工作量平均），
	 * 同分時用 sort_order 決定。
	 *
	 * @param array  $staff_ids 候選人員 ID 清單。
	 * @param string $date_ymd  日期 (Y-m-d)。
	 * @return int
	 */
	protected static function pick_least_booked( $staff_ids, $date_ymd ) {
		if ( count( $staff_ids ) === 1 ) {
			return (int) $staff_ids[0];
		}

		global $wpdb;
		$table        = UAPPT_Install::table( 'bookings' );
		$placeholders = implode( ',', array_fill( 0, count( $staff_ids ), '%d' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT staff_id, COUNT(*) AS cnt FROM {$table} WHERE staff_id IN ({$placeholders}) AND status IN (%s, %s) AND service_start >= %s AND service_start <= %s GROUP BY staff_id", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				array_merge( $staff_ids, array( self::STATUS_HELD, self::STATUS_CONFIRMED, $date_ymd . ' 00:00:00', $date_ymd . ' 23:59:59' ) )
			),
			ARRAY_A
		);

		$count_map = array_fill_keys( $staff_ids, 0 );
		foreach ( $rows as $row ) {
			$count_map[ (int) $row['staff_id'] ] = (int) $row['cnt'];
		}

		$sort_map = array();
		foreach ( UAPPT_Staff::get_many( $staff_ids ) as $staff ) {
			$sort_map[ (int) $staff['id'] ] = (int) $staff['sort_order'];
		}

		$best = (int) $staff_ids[0];
		foreach ( $staff_ids as $sid ) {
			$sid = (int) $sid;
			if ( $count_map[ $sid ] < $count_map[ $best ]
				|| ( $count_map[ $sid ] === $count_map[ $best ] && ( isset( $sort_map[ $sid ] ) ? $sort_map[ $sid ] : 0 ) < ( isset( $sort_map[ $best ] ) ? $sort_map[ $best ] : 0 ) )
			) {
				$best = $sid;
			}
		}

		return $best;
	}

	/**
	 * 時段分類定義：由兩個分界時間切成三段，
	 * [00:00,分界1)、[分界1,分界2)、[分界2,24:00) 連續覆蓋一整天。
	 *
	 * 因為三段一定連續且蓋滿全天，任何時段必然落在其中一段，不會出現
	 * 「找不到分類」而被歸到錯誤頁籤的情況。
	 *
	 * v2.55.0 起讀的是全站設定（UAPPT_Product::segment_config()），不再吃人員
	 * 參數——理由見那支的說明：這裡本來就是在候選人員的迴圈之外算一次、然後
	 * 套用到所有人員的時段上，「某位人員的分類」從來沒有真正成立過。
	 *
	 * @return array 每個元素：['key','name','start_min','end_min']（分鐘為一天中的第幾分鐘，0-1440）。
	 */
	protected static function segment_definitions() {
		$config   = UAPPT_Product::segment_config();
		$defaults = UAPPT_Product::default_segment_config();

		$labels     = isset( $config['labels'] ) && is_array( $config['labels'] ) ? $config['labels'] : $defaults['labels'];
		$boundaries = isset( $config['boundaries'] ) && is_array( $config['boundaries'] ) ? $config['boundaries'] : $defaults['boundaries'];

		$first  = isset( $boundaries[0] ) ? self::time_to_minutes( $boundaries[0] ) : 720;
		$second = isset( $boundaries[1] ) ? self::time_to_minutes( $boundaries[1] ) : 1080;

		return array(
			array(
				'key'       => 'period_1',
				'name'      => isset( $labels[0] ) ? $labels[0] : $defaults['labels'][0],
				'start_min' => 0,
				'end_min'   => $first,
			),
			array(
				'key'       => 'period_2',
				'name'      => isset( $labels[1] ) ? $labels[1] : $defaults['labels'][1],
				'start_min' => $first,
				'end_min'   => $second,
			),
			array(
				'key'       => 'period_3',
				'name'      => isset( $labels[2] ) ? $labels[2] : $defaults['labels'][2],
				'start_min' => $second,
				'end_min'   => 1440,
			),
		);
	}

	/**
	 * 判斷某個「一天中第幾分鐘」的時刻屬於哪個分類。
	 *
	 * 現行的分類定義連續覆蓋整天，理論上一定找得到對應分類；最後的
	 * 「歸給最後一段」只是防禦性後備（例如分界時間資料異常時），正常情況不會觸發。
	 *
	 * @param array $segments 由 segment_definitions() 取得的分類定義。
	 * @param int   $minute_of_day 0-1439。
	 * @return string 分類 key；若沒有任何分類定義則回傳空字串。
	 */
	protected static function classify_segment( $segments, $minute_of_day ) {
		if ( empty( $segments ) ) {
			return '';
		}

		foreach ( $segments as $segment ) {
			if ( $minute_of_day >= $segment['start_min'] && $minute_of_day < $segment['end_min'] ) {
				return $segment['key'];
			}
		}

		$last = end( $segments );
		return $last['key'];
	}

	/**
	 * 將 "HH:mm" 轉為當天第幾分鐘（0-1439）。
	 *
	 * @param string $hm "HH:mm"。
	 * @return int
	 */
	protected static function time_to_minutes( $hm ) {
		$parts  = explode( ':', (string) $hm );
		$hour   = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$minute = isset( $parts[1] ) ? (int) $parts[1] : 0;
		return $hour * 60 + $minute;
	}

	/**
	 * fetch_occupancy_map() 的區間版：一次撈完整段日期的佔用格。
	 *
	 * 回傳的形狀跟單日版完全一樣（slot_start => occupied），所以
	 * staff_is_free()／remaining_capacity_in_block() 可以原封不動吃這份資料
	 * ——它們只是拿時間格 key 去查表，不在意這張表涵蓋幾天。
	 *
	 * 邊界跟單日版對齊：往前多一天、往後多兩天。單日版之所以這樣取，是因為
	 * 跨午夜的班會讓佔用格落在前後日；區間版只是把同一個緩衝套在整段區間的
	 * 兩端，不是另一套規則。
	 *
	 * @param int    $staff_id  人員 ID。
	 * @param string $date_from 起始日期 (Y-m-d)。
	 * @param string $date_to   結束日期 (Y-m-d)。
	 * @return array slot_start (MySQL datetime) => occupied。
	 */
	protected static function fetch_occupancy_map_range( $staff_id, $date_from, $date_to ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'slot_grid' );

		$from = self::mysql_from_ts( self::local_ts( $date_from . ' 00:00:00' ) - DAY_IN_SECONDS );
		$to   = self::mysql_from_ts( self::local_ts( $date_to . ' 00:00:00' ) + 2 * DAY_IN_SECONDS );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT slot_start, occupied FROM {$table} WHERE staff_id = %d AND slot_start >= %s AND slot_start < %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$staff_id,
				$from,
				$to
			),
			ARRAY_A
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row['slot_start'] ] = (int) $row['occupied'];
		}
		return $map;
	}

	/**
	 * 取出某人員、某日期附近（含前後一天緩衝）的時間格佔用地圖。
	 *
	 * @param int    $staff_id 人員 ID。
	 * @param string $date_ymd 日期 (Y-m-d)。
	 * @return array slot_start(mysql string) => occupied(int)。
	 */
	protected static function fetch_occupancy_map( $staff_id, $date_ymd ) {
		global $wpdb;
		$table = UAPPT_Install::table( 'slot_grid' );

		$from = self::mysql_from_ts( self::local_ts( $date_ymd . ' 00:00:00' ) - DAY_IN_SECONDS );
		$to   = self::mysql_from_ts( self::local_ts( $date_ymd . ' 00:00:00' ) + 2 * DAY_IN_SECONDS );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT slot_start, occupied FROM {$table} WHERE staff_id = %d AND slot_start >= %s AND slot_start < %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$staff_id,
				$from,
				$to
			),
			ARRAY_A
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row['slot_start'] ] = (int) $row['occupied'];
		}
		return $map;
	}

	/**
	 * 產生 [start, end) 範圍內、對齊每日 00:00 的時間格列表（MySQL datetime 字串）。
	 *
	 * @param int $start_ts 開始 timestamp。
	 * @param int $end_ts 結束 timestamp（不含）。
	 * @param int $interval 顆粒（分鐘）。
	 * @return array
	 */
	protected static function grid_cells( $start_ts, $end_ts, $interval ) {
		if ( $end_ts <= $start_ts ) {
			return array();
		}

		// 用 local_ts()（而非 strtotime()）解析午夜時刻，確保跟站台時區一致，
		// 不受 PHP 預設時區（通常是 UTC，可能與站台時區不同）影響。
		$day_start = self::local_ts( wp_date( 'Y-m-d', $start_ts ) . ' 00:00:00' );
		$offset    = ( $start_ts - $day_start ) / 60;
		$floor_off = floor( $offset / $interval ) * $interval;
		$cursor    = $day_start + $floor_off * 60;

		$cells = array();
		while ( $cursor < $end_ts ) {
			$cells[] = self::mysql_from_ts( $cursor );
			$cursor += $interval * 60;
		}
		return $cells;
	}

	/**
	 * 將站台本地時間字串（Y-m-d H:i:s）轉為 timestamp。
	 *
	 * @param string $local_datetime 本地時間字串。
	 * @return int
	 */
	protected static function local_ts( $local_datetime ) {
		$dt = date_create( $local_datetime, wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}

	/**
	 * 將 timestamp 轉為站台本地 MySQL datetime 字串。
	 *
	 * @param int $ts timestamp。
	 * @return string
	 */
	protected static function mysql_from_ts( $ts ) {
		return wp_date( 'Y-m-d H:i:s', $ts );
	}
}
