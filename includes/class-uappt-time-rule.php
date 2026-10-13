<?php
/**
 * 服務的可預約時段（v3.4.0）：固定場次／限定開始時段。
 *
 * 時段引擎原本只看「人員有沒有上班、有沒有空、放不放得下時長＋緩衝」，服務本身
 * 沒辦法說「我一天只有 10:00、14:00 兩場」或「我只在下午開始」。這裡定義那條
 * 限制的資料形狀、清洗方式，以及「某一天可以從幾點開始」的判斷。
 *
 * **它只會縮小可以開始的時間，不會放大**：場次還是要有人上班、有空才會開放，
 * 不會讓沒排班的時間冒出來。
 *
 * 資料形狀（商品 meta `_uappt_time_rule`；方案陣列裡的 `time_rule`，空字串＝沿用商品）：
 *
 *     array(
 *         'mode' => 'fixed',  // none | fixed | range
 *         'rows' => array(
 *             array( 'days' => array( 1, 2, 3, 4, 5 ), 'times' => array( '19:00' ) ),        // fixed
 *             array( 'days' => array( 0, 6 ), 'from' => '13:00', 'to' => '17:00' ),         // range
 *         ),
 *     )
 *
 * `days` 用 PHP `w` 的編號（0＝星期日）。同一天被好幾列勾到就取聯集；沒有任何
 * 一列勾到的星期＝那天不開放這項服務。
 *
 * 設計與決策見 docs/wizard-and-time-rules-plan.md D2。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Time_Rule {

	const META = '_uappt_time_rule';

	const MODE_NONE    = 'none';
	const MODE_FIXED   = 'fixed';
	const MODE_RANGE   = 'range';
	const MODE_INHERIT = 'inherit';

	/**
	 * 這次存檔有幾組規則選了「固定場次／限定開始時段」，卻沒有任何一列有效，
	 * 被改回不限制。存檔後的通知用（UAPPT_Product::add_save_notice_args()）。
	 *
	 * @var int
	 */
	public static $cleared = 0;

	/**
	 * 「不限制」。
	 *
	 * @return array
	 */
	public static function none() {
		return array(
			'mode' => self::MODE_NONE,
			'rows' => array(),
		);
	}

	/**
	 * 把任何來源的規則正規化。讀取端一律先過這支，不信任 meta 存的形狀。
	 *
	 * 沒有任何有效列的「固定場次／限定開始時段」一律當成不限制：一組空規則如果
	 * 照字面解釋成「每天都不開放」，管理者只是忘了填時間，整項服務就會在前台
	 * 無聲消失。
	 *
	 * @param mixed $raw 規則。
	 * @return array
	 */
	public static function normalize( $raw ) {
		if ( ! is_array( $raw ) || ! isset( $raw['mode'] ) || ! in_array( $raw['mode'], array( self::MODE_FIXED, self::MODE_RANGE ), true ) ) {
			return self::none();
		}

		$rows = array();
		foreach ( (array) ( isset( $raw['rows'] ) ? $raw['rows'] : array() ) as $row ) {
			$row = self::normalize_row( $row, $raw['mode'] );
			if ( $row ) {
				$rows[] = $row;
			}
		}

		if ( ! $rows ) {
			return self::none();
		}

		return array(
			'mode' => $raw['mode'],
			'rows' => $rows,
		);
	}

	/**
	 * 正規化一列；無效（沒勾星期、沒有時間、最早晚於最晚）回 null。
	 *
	 * @param mixed  $row  一列。
	 * @param string $mode fixed | range。
	 * @return array|null
	 */
	protected static function normalize_row( $row, $mode ) {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$days = array();
		foreach ( (array) ( isset( $row['days'] ) ? $row['days'] : array() ) as $day ) {
			if ( is_numeric( $day ) && (int) $day >= 0 && (int) $day <= 6 ) {
				$days[ (int) $day ] = true;
			}
		}
		$days = array_keys( $days );
		sort( $days );

		if ( ! $days ) {
			return null;
		}

		if ( self::MODE_FIXED === $mode ) {
			$raw_times = isset( $row['times'] ) ? $row['times'] : '';
			$times     = self::parse_times( is_array( $raw_times ) ? implode( ',', $raw_times ) : (string) $raw_times );
			if ( ! $times ) {
				return null;
			}
			return array(
				'days'  => $days,
				'times' => $times,
			);
		}

		$from = self::hm( isset( $row['from'] ) ? $row['from'] : '' );
		$to   = self::hm( isset( $row['to'] ) ? $row['to'] : '' );

		// 最早晚於最晚當成無效，不解讀成跨午夜：跨午夜的那一段開始時間屬於隔天，
		// 套用的卻是「前一天」那一列的星期，結果會讓人看不懂。真的有這種需求時
		// 拆成兩列（週五 22:00–23:59、週六 00:00–02:00）就好。
		if ( '' === $from || '' === $to || $from > $to ) {
			return null;
		}

		return array(
			'days' => $days,
			'from' => $from,
			'to'   => $to,
		);
	}

	/**
	 * 一個時間字串正規化成 HH:MM；不是合法時間回空字串。
	 *
	 * 接受「9:00」「09:00」與全形冒號——管理者用中文輸入法打時間時常常是全形。
	 *
	 * @param mixed $raw 時間。
	 * @return string
	 */
	public static function hm( $raw ) {
		if ( ! preg_match( '/^\s*(\d{1,2})\s*[:：]\s*(\d{2})\s*$/u', (string) $raw, $m ) ) {
			return '';
		}
		$hour   = (int) $m[1];
		$minute = (int) $m[2];
		if ( $hour > 23 || $minute > 59 ) {
			return '';
		}
		return sprintf( '%02d:%02d', $hour, $minute );
	}

	/**
	 * 「10:00, 14:00」這種字串拆成排序、去重後的時間陣列。
	 *
	 * 逗號、頓號、分號、空白（含全形）都可以分隔：同一格要讓人隨手打，不要因為
	 * 用了頓號就存不進去。
	 *
	 * @param string $str     字串。
	 * @param array  $invalid 傳參考：看不懂的片段（給呼叫端回報用）。
	 * @return array
	 */
	public static function parse_times( $str, &$invalid = null ) {
		$invalid = array();
		$times   = array();

		foreach ( preg_split( '/[\s,，、;；]+/u', trim( (string) $str ), -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			$hm = self::hm( $token );
			if ( '' === $hm ) {
				$invalid[] = $token;
				continue;
			}
			$times[ $hm ] = true;
		}

		$times = array_keys( $times );
		sort( $times );
		return $times;
	}

	/**
	 * 清洗後台送來的規則。
	 *
	 * 只保留目前模式用得到的欄位：每一列同時有「場次」跟「最早／最晚」兩組輸入框
	 * （切換模式時資料不會不見），但存下來的只有選定那一種。
	 *
	 * @param mixed $raw           $_POST 裡的規則（已 wp_unslash）。
	 * @param bool  $allow_inherit 方案用：允許「沿用商品」。
	 * @return array|string 方案選「沿用商品」時回空字串。
	 */
	public static function sanitize( $raw, $allow_inherit = false ) {
		$raw  = is_array( $raw ) ? $raw : array();
		$mode = isset( $raw['mode'] ) ? sanitize_key( (string) $raw['mode'] ) : '';

		if ( $allow_inherit && ( '' === $mode || self::MODE_INHERIT === $mode ) ) {
			return '';
		}

		if ( ! in_array( $mode, array( self::MODE_FIXED, self::MODE_RANGE ), true ) ) {
			return self::none();
		}

		$rule = self::normalize(
			array(
				'mode' => $mode,
				'rows' => isset( $raw['rows'] ) ? $raw['rows'] : array(),
			)
		);

		if ( self::MODE_NONE === $rule['mode'] ) {
			++self::$cleared;
		}

		return $rule;
	}

	/**
	 * 某一天的限制。
	 *
	 * @param array  $rule     正規化過的規則。
	 * @param string $date_ymd 日期 (Y-m-d)。
	 * @return array|null null＝不限制；否則是 `array( 'fixed' => array( 'HH:MM', … ) )`
	 *                    或 `array( 'ranges' => array( array( 開始分鐘, 結束分鐘 ), … ) )`。
	 *                    裡面的陣列是空的＝這天不開放這項服務。
	 */
	public static function for_day( $rule, $date_ymd ) {
		if ( ! is_array( $rule ) || empty( $rule['rows'] ) || ! in_array( $rule['mode'], array( self::MODE_FIXED, self::MODE_RANGE ), true ) ) {
			return null;
		}

		// 用中午而不是午夜：星期只跟日期有關，避開任何跟午夜有關的時區邊界。
		$date = date_create( $date_ymd . ' 12:00:00', wp_timezone() );
		if ( ! $date ) {
			return null;
		}
		$weekday = (int) $date->format( 'w' );

		if ( self::MODE_FIXED === $rule['mode'] ) {
			$times = array();
			foreach ( $rule['rows'] as $row ) {
				if ( in_array( $weekday, $row['days'], true ) ) {
					foreach ( $row['times'] as $hm ) {
						$times[ $hm ] = true;
					}
				}
			}
			$times = array_keys( $times );
			sort( $times );
			return array( 'fixed' => $times );
		}

		$ranges = array();
		foreach ( $rule['rows'] as $row ) {
			if ( in_array( $weekday, $row['days'], true ) ) {
				$ranges[] = array( self::minutes( $row['from'] ), self::minutes( $row['to'] ) );
			}
		}
		return array( 'ranges' => $ranges );
	}

	/**
	 * 這個時間能不能開始（create_hold() 的防線用）。
	 *
	 * @param array  $rule     正規化過的規則。
	 * @param string $date_ymd 日期 (Y-m-d)。
	 * @param string $time_hm  開始時間 (H:i)。
	 * @return bool
	 */
	public static function allows( $rule, $date_ymd, $time_hm ) {
		$day = self::for_day( $rule, $date_ymd );
		if ( null === $day ) {
			return true;
		}
		if ( isset( $day['fixed'] ) ) {
			return in_array( self::hm( $time_hm ), $day['fixed'], true );
		}
		return self::in_ranges( self::minutes( $time_hm ), $day['ranges'] );
	}

	/**
	 * 某個開始時間（一天中的第幾分鐘）有沒有落在任一段範圍內（含頭尾）。
	 *
	 * @param int   $minute 一天中的第幾分鐘。
	 * @param array $ranges for_day() 的 ranges。
	 * @return bool
	 */
	public static function in_ranges( $minute, $ranges ) {
		foreach ( $ranges as $range ) {
			if ( $minute >= $range[0] && $minute <= $range[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * HH:MM 轉成一天中的第幾分鐘。
	 *
	 * @param string $hm 時間。
	 * @return int
	 */
	public static function minutes( $hm ) {
		$parts = explode( ':', (string) $hm );
		return (int) $parts[0] * 60 + ( isset( $parts[1] ) ? (int) $parts[1] : 0 );
	}

	// --- 後台編輯器 --------------------------------------------------------

	/**
	 * 星期的顯示名稱，索引同 PHP 的 `w`。
	 *
	 * @return array
	 */
	protected static function weekday_labels() {
		return array(
			__( '日', 'ultimate-appointments' ),
			__( '一', 'ultimate-appointments' ),
			__( '二', 'ultimate-appointments' ),
			__( '三', 'ultimate-appointments' ),
			__( '四', 'ultimate-appointments' ),
			__( '五', 'ultimate-appointments' ),
			__( '六', 'ultimate-appointments' ),
		);
	}

	/**
	 * 輸出編輯器：模式＋規則列。
	 *
	 * 互動（切換模式、新增／刪除列、時間格式整理、送出前檢查）由
	 * assets/js/admin-time-rule.js 處理；JS 沒載入時欄位照樣能填、能存，
	 * 只是三種模式的欄位會同時出現。
	 *
	 * @param string       $name          欄位名稱前綴，例如 `_uappt_time_rule`。
	 * @param array|string $rule          規則；方案的空字串＝沿用商品。
	 * @param bool         $allow_inherit 方案用：多一個「沿用商品」。
	 * @return string
	 */
	public static function render_editor( $name, $rule, $allow_inherit = false ) {
		if ( $allow_inherit && ! is_array( $rule ) ) {
			$mode = self::MODE_INHERIT;
			$rows = array();
		} else {
			$rule = self::normalize( $rule );
			$mode = $rule['mode'];
			$rows = $rule['rows'];
		}

		// 至少放一列空白列：切到「固定場次」時不必先按「新增一列」才有地方填。
		if ( ! $rows ) {
			$rows = array( self::blank_row() );
		}

		$modes = array();
		if ( $allow_inherit ) {
			$modes[ self::MODE_INHERIT ] = __( '沿用商品設定', 'ultimate-appointments' );
		}
		$modes[ self::MODE_NONE ]  = __( '不限制', 'ultimate-appointments' );
		$modes[ self::MODE_FIXED ] = __( '固定場次', 'ultimate-appointments' );
		$modes[ self::MODE_RANGE ] = __( '限定開始時段', 'ultimate-appointments' );

		$help = array(
			self::MODE_INHERIT => __( '跟商品「可預約時段」的設定一樣。', 'ultimate-appointments' ),
			self::MODE_NONE    => __( '服務人員上班時間內的每個空檔都可以預約。', 'ultimate-appointments' ),
			self::MODE_FIXED   => __( '只開放列出的開始時間，例如一天只有 10:00、14:00 兩場。還是要有人上班、有空才會開放；沒有勾到的星期不開放這項服務。', 'ultimate-appointments' ),
			self::MODE_RANGE   => __( '只在這段時間內開始，例如下午才做的療程；範圍內照服務人員的時段間隔排。沒有勾到的星期不開放這項服務。', 'ultimate-appointments' ),
		);

		$html  = '<div class="uappt-time-rule" data-name="' . esc_attr( $name ) . '" data-mode="' . esc_attr( $mode ) . '" data-next="' . esc_attr( count( $rows ) ) . '">';
		$html .= '<div class="uappt-time-rule-modes">';
		foreach ( $modes as $value => $label ) {
			$html .= '<label class="uappt-time-rule-mode"><input type="radio" name="' . esc_attr( $name . '[mode]' ) . '" value="' . esc_attr( $value ) . '"' . checked( $mode, $value, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		$html .= '</div>';

		foreach ( $help as $value => $text ) {
			if ( isset( $modes[ $value ] ) ) {
				$html .= '<p class="description uappt-time-rule-help" data-for="' . esc_attr( $value ) . '">' . esc_html( $text ) . '</p>';
			}
		}

		$html .= '<div class="uappt-time-rule-body">';
		$html .= '<div class="uappt-time-rule-rows">';
		foreach ( array_values( $rows ) as $i => $row ) {
			$html .= self::render_row( $name, (string) $i, $row );
		}
		$html .= '</div>';
		$html .= '<button type="button" class="button-link uappt-time-rule-add">' . esc_html__( '＋ 新增一列（不同星期用不同時間）', 'ultimate-appointments' ) . '</button>';
		$html .= '</div>';

		$html .= '</div>';

		return $html;
	}

	/**
	 * 一列：星期＋時間。
	 *
	 * 「場次」與「最早／最晚」兩組輸入框都輸出，由 data-mode 決定顯示哪一組：
	 * 管理者在兩種模式間切換比較時，填到一半的資料不會不見。存檔時只留選定的那組
	 * （見 sanitize()）。
	 *
	 * @param string $name  欄位名稱前綴。
	 * @param string $index 列的索引（範本用 `__ROW__`）。
	 * @param array  $row   列資料。
	 * @return string
	 */
	public static function render_row( $name, $index, $row ) {
		$base  = $name . '[rows][' . $index . ']';
		$days  = isset( $row['days'] ) ? array_map( 'intval', (array) $row['days'] ) : array();
		$times = isset( $row['times'] ) ? implode( '、', (array) $row['times'] ) : '';
		$from  = isset( $row['from'] ) ? $row['from'] : '';
		$to    = isset( $row['to'] ) ? $row['to'] : '';

		$html  = '<div class="uappt-time-rule-row">';
		$html .= '<span class="uappt-weekdays" role="group" aria-label="' . esc_attr__( '適用的星期', 'ultimate-appointments' ) . '">';
		foreach ( self::weekday_labels() as $day => $label ) {
			$html .= '<label class="uappt-weekday"><input type="checkbox" name="' . esc_attr( $base . '[days][]' ) . '" value="' . esc_attr( $day ) . '"' . checked( in_array( $day, $days, true ), true, false ) . '><span>' . esc_html( $label ) . '</span></label>';
		}
		$html .= '</span>';

		$html .= '<span class="uappt-time-rule-fixed">';
		$html .= '<input type="text" class="uappt-time-rule-times" name="' . esc_attr( $base . '[times]' ) . '" value="' . esc_attr( $times ) . '" placeholder="' . esc_attr__( '例如 10:00、14:00', 'ultimate-appointments' ) . '" autocomplete="off" aria-label="' . esc_attr__( '開始時間', 'ultimate-appointments' ) . '">';
		$html .= '</span>';

		$html .= '<span class="uappt-time-rule-range">';
		$html .= '<label>' . esc_html__( '最早', 'ultimate-appointments' ) . ' <input type="time" class="uappt-time-rule-from" name="' . esc_attr( $base . '[from]' ) . '" value="' . esc_attr( $from ) . '"></label>';
		$html .= '<label>' . esc_html__( '最晚', 'ultimate-appointments' ) . ' <input type="time" class="uappt-time-rule-to" name="' . esc_attr( $base . '[to]' ) . '" value="' . esc_attr( $to ) . '"></label>';
		$html .= '<span class="uappt-time-rule-suffix">' . esc_html__( '開始', 'ultimate-appointments' ) . '</span>';
		$html .= '</span>';

		$html .= '<button type="button" class="uappt-time-rule-remove" aria-label="' . esc_attr__( '刪除這一列', 'ultimate-appointments' ) . '" title="' . esc_attr__( '刪除這一列', 'ultimate-appointments' ) . '">×</button>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * 新列的預設值：七天全勾。最常見的情況是「每天都一樣」，只有要分平日／假日時
	 * 才需要去取消勾選。
	 *
	 * @return array
	 */
	public static function blank_row() {
		return array(
			'days'  => array( 0, 1, 2, 3, 4, 5, 6 ),
			'times' => array(),
			'from'  => '',
			'to'    => '',
		);
	}

	/**
	 * 新增列用的範本（`__NAME__`、`__ROW__` 由 JS 換掉）。整頁只印一次。
	 *
	 * 放在 `<script type="text/template">` 裡才不會被表單一起送出。
	 *
	 * @return string
	 */
	public static function render_row_template() {
		return '<script type="text/template" id="uappt-time-rule-row-template">' . self::render_row( '__NAME__', '__ROW__', self::blank_row() ) . '</script>';
	}
}
