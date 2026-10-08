<?php
/**
 * 回訪管理：誰該打電話了。
 *
 * **這不是客戶資料庫，是一份今天要打的電話清單。** 定位上跟兩個既有的東西刻意
 * 錯開（見 docs/reports-v2-plan.md 的 D7）：
 *
 * | | `WooCommerce > 顧客` | 預約列表 | 回訪管理 |
 * | --- | --- | --- | --- |
 * | 單位 | 一位買家 | 一筆預約 | 一位**該聯絡的**客人 |
 * | 回答 | 買了多少 | 這筆預約怎麼了 | **誰該打電話了** |
 * | 時間基準 | **訂單日期** | 服務日期 | 服務日期 |
 * | 含無帳號客人 | ✗ | ✓ | ✓ |
 *
 * 所以這裡**不做**通訊錄式的客戶列表（跟 WooCommerce 顧客重複）、也不做客戶
 * 搜尋（預約列表的搜尋欄已經能查姓名／電話／訂單編號）。
 *
 * ⚠️ **這一頁的數字跟 `WooCommerce > 顧客` 本來就不會一樣**，而且不該一樣：
 * 那邊的 `date_last_active` 是**訂單日期**，客人 9/1 下單、9/20 才到店，那邊
 * 會認為他 9/1 活躍——用它判斷「多久沒來」會直接算錯，而那正是這份清單唯一的
 * 判斷依據。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Customer {

	/**
	 * 超過幾天沒來就列進「該聯絡」。預設 60 天：美甲 3–4 週的補甲週期、
	 * 做臉大約一個月，60 天已經是明顯脫離正常節奏了。畫面上可以改。
	 */
	const DEFAULT_LAPSED_DAYS = 60;

	/**
	 * 標記「已聯絡」之後先藏起來幾天。
	 *
	 * 沒有這個機制的話，打過電話的人隔天還在名單上，清單就廢了——這是整個功能
	 * 能不能真的被用起來的關鍵。30 天之後會**重新出現**也是刻意的：聯絡過但人
	 * 還是沒來，一個月後本來就該再跟進一次。
	 */
	const SNOOZE_DAYS = 30;

	/**
	 * 選單徽章的快取秒數。
	 *
	 * 這個數字要在**每一個後台頁面**載入時算出來（`register_menu()` 會呼叫），
	 * 而它的查詢是整張 bookings 的 GROUP BY，比耗材那個單純的索引查詢貴得多。
	 * 徽章不需要即時，快取一小時。
	 */
	const BADGE_CACHE_SECONDS = HOUR_IN_SECONDS;

	/**
	 * 徽章計數的 transient 名稱。
	 */
	const BADGE_TRANSIENT = 'uappt_lapsed_customer_count';

	/* ---------------------------------------------------------------------
	 * 該聯絡的客人
	 * ------------------------------------------------------------------- */

	/**
	 * 查「超過 N 天沒來」的客人。
	 *
	 * 一次查完，不在 PHP 端過濾——排除條件（已經約了下一次、最近聯絡過）如果放
	 * 在 PHP 做，分頁就會錯（撈 50 筆濾掉 20 筆，第二頁會跳號重複）。
	 *
	 * 三個排除條件：
	 *
	 * 1. `service_start <= NOW()`：還沒發生的預約不算「來過」
	 * 2. **已經有未來的已確認預約 → 整個排除**。他已經約好了，打電話給他只會
	 *    讓人覺得這間店狀況外。這一段**刻意不套人員篩選**：客人跟別位人員約了
	 *    也是約了
	 * 3. 最近 `SNOOZE_DAYS` 天內標記過「已聯絡」→ 暫時藏起來
	 *
	 * @param array $args {
	 *     @type int    $days              超過幾天沒來（預設 DEFAULT_LAPSED_DAYS）。
	 *     @type int    $staff_id          只看曾經被這位人員服務過的客人；0＝全部。
	 *     @type bool   $include_contacted true＝連最近聯絡過的也列出來（看自己打過誰）。
	 *     @type int    $paged             頁碼。
	 *     @type int    $per_page          每頁筆數。
	 * }
	 * @return array ['items' => array, 'total' => int]
	 */
	public static function get_lapsed( $args = array() ) {
		global $wpdb;

		$days     = isset( $args['days'] ) ? max( 1, (int) $args['days'] ) : self::DEFAULT_LAPSED_DAYS;
		$staff_id = isset( $args['staff_id'] ) ? (int) $args['staff_id'] : 0;
		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 50;
		$paged    = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
		$include  = ! empty( $args['include_contacted'] );

		list( $sql, $params ) = self::build_lapsed_query( $days, $staff_id, $include );

		$count_sql = "SELECT COUNT(*) FROM ( {$sql} ) AS counted";
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$offset    = ( $paged - 1 ) * $per_page;
		$page_sql  = "{$sql} ORDER BY t.last_visit ASC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( $page_sql, array_merge( $params, array( $per_page, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return array(
			'items' => self::decorate( $rows ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * 目前有幾位該聯絡（選單徽章用）。
	 *
	 * 快取一小時：這個數字在每一個後台頁面載入時都會被問一次，而它的查詢是整張
	 * bookings 的 GROUP BY。徽章不需要即時。
	 *
	 * @param bool $force 略過快取。
	 * @return int
	 */
	public static function count_lapsed( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::BADGE_TRANSIENT );
			if ( false !== $cached ) {
				return (int) $cached;
			}
		}

		global $wpdb;
		list( $sql, $params ) = self::build_lapsed_query( self::DEFAULT_LAPSED_DAYS, 0, false );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( {$sql} ) AS counted", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		set_transient( self::BADGE_TRANSIENT, $count, self::BADGE_CACHE_SECONDS );

		return $count;
	}

	/**
	 * 清掉徽章快取。標記已聯絡之後要呼叫，不然紅點上的數字會跟清單對不起來。
	 */
	public static function flush_badge_cache() {
		delete_transient( self::BADGE_TRANSIENT );
	}

	/**
	 * 組出「該聯絡的客人」查詢（`get_lapsed()` 與 `count_lapsed()` 共用）。
	 *
	 * 抽出來是為了讓**分頁的總數與實際列出來的資料必定同一套條件**——兩邊各寫
	 * 一份 WHERE 的話，遲早會出現「說有 30 筆、只列得出 24 筆」。
	 *
	 * @param int  $days              超過幾天沒來。
	 * @param int  $staff_id          人員篩選。
	 * @param bool $include_contacted 連最近聯絡過的也列出來。
	 * @return array [$sql, $params]
	 */
	protected static function build_lapsed_query( $days, $staff_id, $include_contacted ) {
		global $wpdb;

		$bookings = UAPPT_Install::table( 'bookings' );
		$contacts = UAPPT_Install::table( 'customer_contacts' );
		$ckey     = UAPPT_Booking::customer_key_sql();

		$now    = current_time( 'mysql' );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - $days * DAY_IN_SECONDS );
		$snooze = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - self::SNOOZE_DAYS * DAY_IN_SECONDS );

		// 「最後一次」的姓名／電話／服務項目。MySQL 5.7 沒有視窗函數（WP 的最低
		// 支援版本），所以用 GROUP_CONCAT 依時間倒序接起來、再取第一段。
		// GROUP_CONCAT 超過 group_concat_max_len 會從**尾端**截斷，而我們要的是
		// 第一段，所以截斷不影響正確性。分隔符號用 0x1f（ASCII 的單元分隔符），
		// 姓名或項目名稱裡不可能出現。
		$latest = function ( $expr ) {
			return "SUBSTRING_INDEX( GROUP_CONCAT( {$expr} ORDER BY service_start DESC SEPARATOR 0x1f ), 0x1f, 1 )";
		};

		$inner_where  = array( 'kind = %s', 'status IN (%s, %s, %s)', 'service_start <= %s' );
		$inner_params = array(
			UAPPT_Booking::KIND_BOOKING,
			UAPPT_Booking::STATUS_CONFIRMED,
			UAPPT_Booking::STATUS_COMPLETED,
			UAPPT_Booking::STATUS_NO_SHOW,
			$now,
		);
		if ( $staff_id ) {
			$inner_where[]  = 'staff_id = %d';
			$inner_params[] = $staff_id;
		}

		$sql = "SELECT t.*, c.contacted_at, c.note AS contact_note
			FROM (
				SELECT
					{$ckey} AS ckey,
					MAX( customer_id ) AS customer_id,
					COUNT(*) AS visits,
					MAX( service_start ) AS last_visit,
					MIN( service_start ) AS first_visit,
					COALESCE( SUM( amount ), 0 ) AS service_spend,
					" . $latest( "NULLIF( TRIM( COALESCE( customer_name, '' ) ), '' )" ) . " AS customer_name,
					" . $latest( "NULLIF( TRIM( COALESCE( customer_phone, '' ) ), '' )" ) . " AS customer_phone,
					" . $latest( "CONCAT( product_id, '|', COALESCE( plan_name, '' ) )" ) . " AS last_service
				FROM {$bookings}
				WHERE " . implode( ' AND ', $inner_where ) . "
				GROUP BY ckey
				HAVING last_visit <= %s
			) AS t
			LEFT JOIN (
				-- 已經約了下一次的就整個排除。**這裡刻意不套人員篩選**：客人跟
				-- 別位人員約了也是約了，打電話給他只會顯得這間店狀況外。
				SELECT {$ckey} AS ckey
				FROM {$bookings}
				WHERE kind = %s AND status = %s AND service_start > %s
				GROUP BY ckey
			) AS future ON future.ckey = t.ckey
			LEFT JOIN {$contacts} AS c ON c.customer_key = t.ckey
			WHERE future.ckey IS NULL"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$params = array_merge(
			$inner_params,
			array( $cutoff ),
			array( UAPPT_Booking::KIND_BOOKING, UAPPT_Booking::STATUS_CONFIRMED, $now )
		);

		if ( ! $include_contacted ) {
			$sql     .= ' AND ( c.contacted_at IS NULL OR c.contacted_at < %s )';
			$params[] = $snooze;
		}

		return array( $sql, $params );
	}

	/**
	 * 把查詢結果補上顯示要用的東西（服務項目名稱、產品消費）。
	 *
	 * 產品消費**一次查完整頁**（`wc_get_orders()` 的 `customer_id` 吃陣列），
	 * 不要每一列各查一次——實測單一會員要 40ms，一頁 50 位就是兩秒。
	 *
	 * @param array $rows 原始查詢結果。
	 * @return array
	 */
	protected static function decorate( $rows ) {
		$customer_ids = array();
		foreach ( $rows as $row ) {
			if ( (int) $row['customer_id'] > 0 ) {
				$customer_ids[] = (int) $row['customer_id'];
			}
		}
		$product_spend = self::get_product_spend( array_unique( $customer_ids ) );

		$out = array();
		foreach ( $rows as $row ) {
			$parts      = explode( '|', (string) $row['last_service'], 2 );
			$product_id = isset( $parts[0] ) ? (int) $parts[0] : 0;
			$plan_name  = isset( $parts[1] ) ? $parts[1] : '';

			$product = $product_id ? wc_get_product( $product_id ) : null;
			$label   = $product ? $product->get_name() : '';
			if ( '' !== $plan_name ) {
				$label = $label ? $label . ' › ' . $plan_name : $plan_name;
			}

			$cid  = (int) $row['customer_id'];
			$days = self::days_since( $row['last_visit'] );

			$out[] = array(
				'ckey'           => $row['ckey'],
				'customer_id'    => $cid,
				'customer_name'  => (string) $row['customer_name'],
				'customer_phone' => (string) $row['customer_phone'],
				'visits'         => (int) $row['visits'],
				'last_visit'     => $row['last_visit'],
				'first_visit'    => $row['first_visit'],
				'days_since'     => $days,
				'last_service'   => $label,
				'service_spend'  => (float) $row['service_spend'],
				'product_spend'  => isset( $product_spend[ $cid ] ) ? $product_spend[ $cid ] : 0.0,
				'contacted_at'   => $row['contacted_at'],
				'contact_note'   => (string) $row['contact_note'],
			);
		}

		return $out;
	}

	/**
	 * 距今幾天。
	 *
	 * @param string $mysql_datetime MySQL 格式的時間。
	 * @return int
	 */
	public static function days_since( $mysql_datetime ) {
		$then = date_create( $mysql_datetime, wp_timezone() );
		if ( ! $then ) {
			return 0;
		}
		return (int) floor( ( current_time( 'timestamp' ) - $then->getTimestamp() ) / DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
	}

	/* ---------------------------------------------------------------------
	 * 服務／產品消費的拆分
	 * ------------------------------------------------------------------- */

	/**
	 * 這些會員買了多少「非服務」的商品。
	 *
	 * ⚠️ **服務消費不走這裡**，走 `bookings.amount`（見 `get_lapsed()` 的
	 * `service_spend`）。理由：手動建單可以**不勾「建立訂單」**，那種預約在
	 * WooCommerce 裡完全不存在，只看訂單會整筆漏掉。所以兩邊的來源本來就不同：
	 *
	 * - 服務消費：預約紀錄，**不管有沒有訂單**
	 * - 產品消費：訂單項目，**只算已付款的訂單**（`wc_get_is_paid_statuses()`）
	 *
	 * 這個口徑差異要寫在畫面上，不然有人會拿兩個數字去跟訂單總額對，然後對不起來。
	 *
	 * 判斷一個訂單項目是不是服務，三層由可靠到寬鬆（見 reports-v2-plan.md 的 D8）：
	 * 預約紀錄反查 → `_uappt_booking_id` meta → 商品型別。這裡用後兩層就夠——
	 * 第一層要對每個項目查一次資料庫，而這裡只是要把服務項目排除掉，
	 * meta 的實測涵蓋率是 100%。
	 *
	 * @param int[] $customer_ids 會員 ID 清單。
	 * @return array customer_id => 產品消費金額
	 */
	public static function get_product_spend( $customer_ids ) {
		$customer_ids = array_values( array_filter( array_map( 'absint', (array) $customer_ids ) ) );
		if ( ! $customer_ids ) {
			return array();
		}

		// customer_id 吃陣列，所以整頁一次查完（實測單一會員 40ms，50 位逐一查
		// 要兩秒）。
		$orders = wc_get_orders(
			array(
				'customer_id' => $customer_ids,
				'limit'       => -1,
				'status'      => wc_get_is_paid_statuses(),
			)
		);

		$spend = array();
		foreach ( $orders as $order ) {
			$cid = (int) $order->get_customer_id();
			if ( ! isset( $spend[ $cid ] ) ) {
				$spend[ $cid ] = 0.0;
			}

			foreach ( $order->get_items() as $item ) {
				if ( self::is_service_item( $item ) ) {
					continue;
				}
				$spend[ $cid ] += (float) $item->get_total();
			}
		}

		return $spend;
	}

	/**
	 * 零售商品的銷售明細（賣了哪些、各幾件、各多少錢）。
	 *
	 * get_product_revenue() 只給一個總額，回答不了「哪個商品好賣」。資料一直
	 * 都在訂單裡，只是沒有人把它拆開過。
	 *
	 * 「零售」的定義跟 get_product_revenue() 一致：訂單項目**沒有**
	 * `_uappt_booking_id` 這個 meta 的，也就是非預約的一般商品。
	 *
	 * ⚠️ **時間軸跟其他報表不一樣。** 這裡以訂單的**建立日期**為準，而預約
	 * 報表全部是以**服務日期**為準。客人今天買了洗髮精、下週才來做臉，兩筆
	 * 會落在不同的期間裡。這是無解的——零售沒有「服務日期」可言——所以呼叫端
	 * 一定要在畫面上講清楚，不要讓人拿這兩個數字硬湊。
	 *
	 * ⚠️ 同理**不吃人員篩選**：商品賣給誰記在誰頭上目前沒有依據。
	 *
	 * @param string $date_from 起始日期。
	 * @param string $date_to   結束日期。
	 * @return array<int, array{product_id:int, name:string, qty:float, revenue:float}>
	 */
	public static function get_retail_breakdown( $date_from, $date_to ) {
		global $wpdb;

		// 先拿訂單 ID（wc_get_orders 對 HPOS 與舊版 posts 都通），再用訂單項目
		// 的兩張表撈明細——那兩張表在 HPOS 之下沒有變（跟 get_product_revenue()
		// 同一套作法）。
		$order_ids = wc_get_orders(
			array(
				'date_created' => sanitize_text_field( $date_from ) . '...' . sanitize_text_field( $date_to ),
				'status'       => wc_get_is_paid_statuses(),
				'limit'        => -1,
				'return'       => 'ids',
			)
		);

		if ( ! $order_ids ) {
			return array();
		}

		$items    = $wpdb->prefix . 'woocommerce_order_items';
		$itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$out      = array();

		foreach ( array_chunk( array_map( 'absint', $order_ids ), 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"SELECT
						COALESCE( CAST( pid.meta_value AS UNSIGNED ), 0 ) AS product_id,
						MAX( oi.order_item_name ) AS name,
						SUM( CAST( COALESCE( qty.meta_value, 1 ) AS DECIMAL(18,3) ) ) AS qty,
						SUM( CAST( total.meta_value AS DECIMAL(18,6) ) ) AS revenue
					FROM {$items} AS oi
					INNER JOIN {$itemmeta} AS total
						ON total.order_item_id = oi.order_item_id AND total.meta_key = '_line_total'
					LEFT JOIN {$itemmeta} AS qty
						ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
					LEFT JOIN {$itemmeta} AS pid
						ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
					LEFT JOIN {$itemmeta} AS booking
						ON booking.order_item_id = oi.order_item_id AND booking.meta_key = '_uappt_booking_id'
					WHERE oi.order_item_type = 'line_item'
						AND booking.meta_id IS NULL
						AND oi.order_id IN ({$placeholders})
					GROUP BY product_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$chunk
				),
				ARRAY_A
			);

			// 訂單數超過 500 張時會分批，同一個商品要跨批合併。
			foreach ( (array) $rows as $row ) {
				$pid = (int) $row['product_id'];
				if ( ! isset( $out[ $pid ] ) ) {
					$out[ $pid ] = array(
						'product_id' => $pid,
						'name'       => (string) $row['name'],
						'qty'        => 0.0,
						'revenue'    => 0.0,
					);
				}
				$out[ $pid ]['qty']     += (float) $row['qty'];
				$out[ $pid ]['revenue'] += (float) $row['revenue'];
			}
		}

		return array_values( $out );
	}

	/**
	 * 全店在這段期間賣了多少「非服務」的商品（零售對服務比要用）。
	 *
	 * ⚠️ **時間基準跟服務業績不一樣，這是刻意的也是沒辦法的**：
	 *
	 * - 服務業績以**服務日期**為準（`bookings.service_start`）
	 * - 產品業績以**下單日期**為準（訂單沒有「服務日期」這種東西）
	 *
	 * 客人 9/28 下單、10/2 才來做臉，那筆服務算在 10 月、順便買的洗面乳算在
	 * 9 月。實務上跨月的只有月底那幾天，但畫面上要寫明，不然有人會拿去跟
	 * WooCommerce 的營收報表對，然後對不起來。
	 *
	 * **實作分兩步**：先用 `wc_get_orders()` 拿期間內的訂單 ID（一次查詢、
	 * 而且 HPOS 與舊版 posts 都通），再用一句 SQL 把那些訂單的非服務項目加總。
	 * 訂單項目的兩張表（`woocommerce_order_items`／`order_itemmeta`）在 HPOS
	 * 之後**沒有改變**，所以這一段不需要分支。
	 *
	 * ID 清單分批（500 一組）丟進 `IN`：一年份的訂單可能好幾千張，一次塞完
	 * 會撞到 `max_allowed_packet`。
	 *
	 * @param string $date_from 起始日期 (Y-m-d)。
	 * @param string $date_to   結束日期 (Y-m-d)。
	 * @return float
	 */
	public static function get_product_revenue( $date_from, $date_to ) {
		global $wpdb;

		$order_ids = wc_get_orders(
			array(
				'date_created' => sanitize_text_field( $date_from ) . '...' . sanitize_text_field( $date_to ),
				'status'       => wc_get_is_paid_statuses(),
				'limit'        => -1,
				'return'       => 'ids',
			)
		);

		if ( ! $order_ids ) {
			return 0.0;
		}

		$items    = $wpdb->prefix . 'woocommerce_order_items';
		$itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$total    = 0.0;

		foreach ( array_chunk( array_map( 'absint', $order_ids ), 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// LEFT JOIN + IS NULL ＝ 「沒有 _uappt_booking_id 這個 meta 的項目」，
			// 也就是非預約的一般商品。
			$sum = $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"SELECT COALESCE( SUM( CAST( total.meta_value AS DECIMAL(18,6) ) ), 0 )
					FROM {$items} AS oi
					INNER JOIN {$itemmeta} AS total
						ON total.order_item_id = oi.order_item_id AND total.meta_key = '_line_total'
					LEFT JOIN {$itemmeta} AS booking
						ON booking.order_item_id = oi.order_item_id AND booking.meta_key = '_uappt_booking_id'
					WHERE oi.order_item_type = 'line_item'
						AND booking.meta_id IS NULL
						AND oi.order_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$chunk
				)
			);

			$total += (float) $sum;
		}

		return $total;
	}

	/**
	 * 這個訂單項目是預約服務嗎？
	 *
	 * @param WC_Order_Item $item 訂單項目。
	 * @return bool
	 */
	public static function is_service_item( $item ) {
		if ( $item->get_meta( '_uappt_booking_id' ) ) {
			return true;
		}

		$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;

		return $product && UAPPT_Product_Type::PRODUCT_TYPE === $product->get_type();
	}

	/* ---------------------------------------------------------------------
	 * 聯絡紀錄
	 * ------------------------------------------------------------------- */

	/**
	 * 標記「已聯絡」。
	 *
	 * 一個客人只留最後一次（`UNIQUE KEY customer_key` ＋ `ON DUPLICATE KEY
	 * UPDATE`）：完整的聯絡歷史目前沒有人要看，而多留一份就多一份要維護的東西。
	 *
	 * @param string $ckey 客人去重鍵。
	 * @param string $note 備註。
	 * @return bool
	 */
	public static function mark_contacted( $ckey, $note = '' ) {
		global $wpdb;

		$ckey = sanitize_text_field( $ckey );
		if ( '' === $ckey ) {
			return false;
		}

		$table = UAPPT_Install::table( 'customer_contacts' );
		$now   = current_time( 'mysql' );

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"INSERT INTO {$table} ( customer_key, contacted_at, note, created_by, created_at )
				VALUES ( %s, %s, %s, %d, %s )
				ON DUPLICATE KEY UPDATE
					contacted_at = VALUES( contacted_at ),
					note = VALUES( note ),
					created_by = VALUES( created_by )",
				$ckey,
				$now,
				sanitize_text_field( $note ),
				get_current_user_id(),
				$now
			)
		);

		// 徽章是快取的，不清掉的話紅點上的數字會跟清單對不起來。
		self::flush_badge_cache();

		return false !== $result;
	}

	/**
	 * 取消「已聯絡」的標記（標錯了要有路可以回頭）。
	 *
	 * @param string $ckey 客人去重鍵。
	 * @return bool
	 */
	public static function clear_contacted( $ckey ) {
		global $wpdb;

		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			UAPPT_Install::table( 'customer_contacts' ),
			array( 'customer_key' => sanitize_text_field( $ckey ) ),
			array( '%s' )
		);

		self::flush_badge_cache();

		return false !== $deleted;
	}

	/* ---------------------------------------------------------------------
	 * 顯示工具
	 * ------------------------------------------------------------------- */

	/**
	 * 把客人去重鍵拆回可讀的型別。
	 *
	 * @param string $ckey 'c:123'／'p:0912345678'／'b:456'。
	 * @return array ['type' => 'customer'|'phone'|'booking', 'value' => string]
	 */
	public static function parse_key( $ckey ) {
		$parts = explode( ':', (string) $ckey, 2 );
		$types = array(
			'c' => 'customer',
			'p' => 'phone',
			'b' => 'booking',
		);
		$type  = isset( $parts[0], $types[ $parts[0] ] ) ? $types[ $parts[0] ] : 'booking';

		return array(
			'type'  => $type,
			'value' => isset( $parts[1] ) ? $parts[1] : '',
		);
	}
}
