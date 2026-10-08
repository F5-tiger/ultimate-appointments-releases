<?php
/**
 * 店休（全店公休日）。
 *
 * **只關掉「可預約時段」，不碰 WooCommerce 的任何東西。** 門市公休時電商照常
 * 營業下單是這個外掛的常態情境（本站 20 個商品裡 14 個是零售），所以店休刻意
 * 不掛 `woocommerce_product_is_purchasable`，也不碰購物車與結帳——它只是讓
 * 那一天算不出營業區間而已。
 *
 * ⚠️ **跟商品的「暫停接受預約」（`_uappt_paused`）是兩回事，不要混用。**
 * 那個是**商品層級的銷售政策**（連商品都變成不可購買）；店休是**店層級的時間
 * 可用性**。一個回答「這項服務現在還賣不賣」，一個回答「這天店有沒有開」。
 *
 * 優先序（v2.79.0 定案）：
 *
 *     人員的單日調整  >  店休  >  人員的每週範本
 *
 * 店休**不是**最高優先，是刻意的：過年留一位師傅值班時，幫他設一筆「單日自訂
 * 時段」就能破例開工，不必為了一個人把整天的店休拿掉。這跟既有的優先序模型
 * 一致（逐日自訂一向蓋過每週範本）。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Shop_Closure {

	const OPTION = 'uappt_shop_closures';

	/**
	 * 清洗過的設定快取。
	 *
	 * is_closed() 會在逐日迴圈裡被呼叫（月曆一次 35～42 天、可用性查詢更密集），
	 * 每次都重新清洗一遍是白費力氣。option 本身是 autoload，讀取不花錢，
	 * 貴的是 sanitize()。
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * 預設值：沒有任何公休。
	 *
	 * @return array{weekdays:array, dates:array}
	 */
	public static function defaults() {
		return array(
			// UAPPT_Staff::WEEKDAY_KEYS 的子集合，例如 array( 'sun' )。
			'weekdays' => array(),
			// 每個元素 ['from'=>'Y-m-d','to'=>'Y-m-d','note'=>string]，單日的 from=to。
			'dates'    => array(),
		);
	}

	/**
	 * 取得設定（已清洗、已快取）。
	 *
	 * @return array
	 */
	public static function get() {
		if ( null === self::$cache ) {
			self::$cache = self::sanitize( get_option( self::OPTION, array() ) );
		}
		return self::$cache;
	}

	/**
	 * 清掉快取。改完設定要呼叫，不然同一個請求裡後續的判斷還是舊的。
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * 寫入設定。
	 *
	 * @param mixed $raw 來源（表單）。
	 * @return array 寫進去的那份（已清洗）。
	 */
	public static function save( $raw ) {
		$clean = self::sanitize( $raw );
		update_option( self::OPTION, $clean );
		self::flush_cache();
		return $clean;
	}

	/**
	 * 清洗成可信的結構。
	 *
	 * 日期區間一律正規化成 from <= to（填反了就對調，不要丟掉——使用者的意圖
	 * 很明確，退回去叫他重填只是刁難）。無法解析的日期整列丟掉。
	 *
	 * @param mixed $raw 來源。
	 * @return array
	 */
	public static function sanitize( $raw ) {
		$out = self::defaults();

		if ( ! is_array( $raw ) ) {
			return $out;
		}

		if ( isset( $raw['weekdays'] ) && is_array( $raw['weekdays'] ) ) {
			foreach ( $raw['weekdays'] as $key ) {
				$key = sanitize_key( $key );
				if ( in_array( $key, UAPPT_Staff::WEEKDAY_KEYS, true ) && ! in_array( $key, $out['weekdays'], true ) ) {
					$out['weekdays'][] = $key;
				}
			}
		}

		if ( isset( $raw['dates'] ) && is_array( $raw['dates'] ) ) {
			$seen = array();
			foreach ( $raw['dates'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$from = self::clean_date( isset( $row['from'] ) ? $row['from'] : '' );
				$to   = self::clean_date( isset( $row['to'] ) ? $row['to'] : '' );

				// 只填一邊視為單日。兩邊都空的是沒填，不是錯誤。
				if ( '' === $from && '' === $to ) {
					continue;
				}
				if ( '' === $from ) {
					$from = $to;
				}
				if ( '' === $to ) {
					$to = $from;
				}
				if ( $from > $to ) {
					$tmp  = $from;
					$from = $to;
					$to   = $tmp;
				}

				$key = $from . '|' . $to;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;

				$out['dates'][] = array(
					'from' => $from,
					'to'   => $to,
					'note' => isset( $row['note'] ) ? sanitize_text_field( $row['note'] ) : '',
				);
			}

			usort(
				$out['dates'],
				function ( $a, $b ) {
					return $a['from'] <=> $b['from'];
				}
			);
		}

		return $out;
	}

	/**
	 * 這一天全店公休嗎。
	 *
	 * @param string $date_ymd 日期 (Y-m-d)。
	 * @return bool
	 */
	public static function is_closed( $date_ymd ) {
		return '' !== self::reason( $date_ymd );
	}

	/**
	 * 這一天公休的原因；沒公休回空字串。
	 *
	 * 指定日期優先於每週固定——兩者都命中時，指定日期的備註（「春節」）比
	 * 「每週日」有資訊量。
	 *
	 * @param string $date_ymd 日期 (Y-m-d)。
	 * @return string
	 */
	public static function reason( $date_ymd ) {
		$date_ymd = (string) $date_ymd;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_ymd ) ) {
			return '';
		}

		$config = self::get();

		foreach ( $config['dates'] as $row ) {
			if ( $date_ymd >= $row['from'] && $date_ymd <= $row['to'] ) {
				return '' !== $row['note'] ? $row['note'] : __( '公休', 'ultimate-appointments' );
			}
		}

		if ( $config['weekdays'] ) {
			$key = UAPPT_Staff::WEEKDAY_KEYS[ uappt_local_weekday_w( $date_ymd ) ];
			if ( in_array( $key, $config['weekdays'], true ) ) {
				return sprintf(
					/* translators: %s: 星期幾的單字（日／一／二…） */
					__( '每週%s公休', 'ultimate-appointments' ),
					UAPPT_Staff::weekday_short_label( $key )
				);
			}
		}

		return '';
	}

	/**
	 * 一句話摘要，給設定頁與人員編輯頁顯示。
	 *
	 * @return string 沒有任何公休時回空字串。
	 */
	public static function summary() {
		$config = self::get();
		$parts  = array();

		if ( $config['weekdays'] ) {
			$labels = array();
			// 照 WEEKDAY_KEYS 的順序輸出，不是使用者勾選的順序。
			foreach ( UAPPT_Staff::WEEKDAY_KEYS as $key ) {
				if ( in_array( $key, $config['weekdays'], true ) ) {
					$labels[] = UAPPT_Staff::weekday_short_label( $key );
				}
			}
			$parts[] = sprintf(
				/* translators: %s: 星期清單 */
				__( '每週%s', 'ultimate-appointments' ),
				implode( '、', $labels )
			);
		}

		foreach ( $config['dates'] as $row ) {
			$span    = ( $row['from'] === $row['to'] ) ? $row['from'] : $row['from'] . '～' . $row['to'];
			$parts[] = '' !== $row['note'] ? $span . '（' . $row['note'] . '）' : $span;
		}

		return implode( '、', $parts );
	}

	/**
	 * 把日期字串收斂成 Y-m-d；看不懂就回空字串。
	 *
	 * @param mixed $raw 原始值。
	 * @return string
	 */
	protected static function clean_date( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) ) {
			return '';
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $raw : '';
	}
}
