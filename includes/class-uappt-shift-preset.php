<?php
/**
 * 班別（全店共用的具名上班時段）。
 *
 * 「早班 09:00–13:00」「晚班 18:00–22:00」這種店裡本來就有的講法，做成可以一鍵
 * 套用的選項。
 *
 * ⚠️ **它是「月曆直接排班」的前提，不是裝飾。** 沒有班別，在月曆上排一個月就是
 * 要打 60 個時間欄位，**比舊的 7 列每週表更慢**；有了班別才會變成「選早班、刷過
 * 去」。所以這是整個排班改版的第一階段，而不是做完月曆之後再補的便利功能。
 *
 * **全店共用一組，不逐人**（已與使用者確認 2026-10-02）：排班的人腦子裡就是
 * 「小明早班、小美晚班」，班別是**店的語言**；做成逐人就要維護 N 份同樣的東西。
 * 真的需要再加，加比拆容易。
 *
 * **不開表**，照 `UAPPT_Shop_Closure` 的樣式用單一 autoload option：一間店頂多十
 * 來個班別，而這個值在每一次月曆渲染都要讀，autoload 的 option 是記憶體讀取，
 * 開表就變成每次查一次 DB。真正貴的是 `sanitize()`，所以清洗後的結果另外用
 * static 快取。
 *
 * ⚠️ **班別不會被寫進 `staff_overrides`。** 那張表只存 `hours`；月曆要顯示
 * 「早班」時是拿當天的時段回來**反查**（`label_for_ranges()`）。理由：班別改了
 * 時間之後，既有的日子**不該**跟著變——那些是已經排定的事實。反查的代價是「改
 * 了班別的時間，舊日子不再顯示班別名」，而那正是正確的：它們的時間確實跟新的
 * 早班不一樣了。
 *
 * 也因為沒有任何東西引用班別，它**不需要穩定鍵**。服務方案的 `plan_key` 需要
 * （既有預約引用它，用陣列索引的話刪掉中間一列就會位移），班別沒有這個問題，
 * 陣列順序就夠了。
 *
 * 計畫與決策紀錄見 `docs/staff-schedule-v3-plan.md`（D1、D2）。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Shift_Preset {

	const OPTION = 'uappt_shift_presets';

	/**
	 * 一個班別最多幾段。
	 *
	 * 3 是跟每週班表與單日調整對齊的數字，不是另外挑的——班別要能套用到那兩張
	 * 表單的欄位上，比它們多出來的段數會被靜默丟掉（v2.81.0 踩過：單日表單只印
	 * 兩段，三段班的人點一下存檔就掉了深夜那段）。
	 */
	const MAX_RANGES = 3;

	/**
	 * 最多幾個班別。
	 *
	 * 純粹是防呆上限（表單列數固定，正常不可能超過），不是業務限制。
	 */
	const MAX_PRESETS = 20;

	/**
	 * 班別顏色的固定色盤（v2.99.0）。鍵是 CSS class 的一部分（`uappt-shift-c-{鍵}`），
	 * 實際色碼在 admin.css——這裡只決定「有哪幾種、叫什麼」。
	 *
	 * ⚠️ **固定色盤，不給自由調色。** 自由調色很容易挑到太淡、在格子上看不見的顏色；
	 * 八個顏色都是挑過的淡底＋深字組合，任何一個放在格子上字都讀得到。也沒有綠色：
	 * 「上班但對不上任何班別」本來就是淡綠，班別再用綠會分不出來。
	 *
	 * 見 docs/shift-ui-plan.md 的 D3。
	 */
	const COLORS = array( 'amber', 'sky', 'violet', 'rose', 'teal', 'orange', 'indigo', 'tan' );

	/**
	 * 顏色在編輯器上的名字（給螢幕閱讀器與滑鼠提示）。
	 *
	 * @return array 鍵 => 名稱
	 */
	public static function color_labels() {
		return array(
			'amber'  => __( '黃', 'ultimate-appointments' ),
			'sky'    => __( '天藍', 'ultimate-appointments' ),
			'violet' => __( '紫', 'ultimate-appointments' ),
			'rose'   => __( '粉紅', 'ultimate-appointments' ),
			'teal'   => __( '青綠', 'ultimate-appointments' ),
			'orange' => __( '橘', 'ultimate-appointments' ),
			'indigo' => __( '靛藍', 'ultimate-appointments' ),
			'tan'    => __( '咖啡', 'ultimate-appointments' ),
		);
	}

	/**
	 * 清洗過的設定快取。
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * 預設值：沒有任何班別。
	 *
	 * 刻意**不預填**「早班／晚班／全天」之類的範例值：那會替店家猜時間，而猜錯
	 * 的預設值比空白更難發現（存檔後就變成真的設定了）。空的時候由設定頁與排班
	 * 表單各自顯示提示。
	 *
	 * @return array
	 */
	public static function defaults() {
		return array();
	}

	/**
	 * 取得全部班別（已清洗、已快取）。
	 *
	 * @return array 每個元素 ['name' => string, 'ranges' => [[start, end], ...]]。
	 */
	public static function all() {
		if ( null === self::$cache ) {
			self::$cache = self::sanitize( get_option( self::OPTION, array() ) );
		}
		return self::$cache;
	}

	/**
	 * 清掉快取。改完設定要呼叫，不然同一個請求裡後續的讀取還是舊的。
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * 寫入設定。
	 *
	 * @param mixed $raw    來源（表單）。
	 * @param array $errors 清洗過程中講得出來的錯誤，會被附加進去（傳參考）。
	 * @return array 實際寫入的班別。
	 */
	public static function save( $raw, array &$errors = array() ) {
		$clean = self::sanitize( $raw, $errors );
		update_option( self::OPTION, $clean );
		self::flush_cache();
		return $clean;
	}

	/**
	 * 清洗班別設定。
	 *
	 * 時段的驗證**一律走 `UAPPT_Staff::sanitize_ranges()`**（「930」→「09:30」的
	 * 容錯、結束早於開始＝跨午夜的慣例、起訖相同擋錯）。那支從 v2.14.0 抽出來就
	 * 是為了不要有第二個時間解析器，班別是它的第四個呼叫端，不是例外。
	 *
	 * @param mixed $raw    來源。
	 * @param array $errors 錯誤訊息（傳參考）。
	 * @return array
	 */
	public static function sanitize( $raw, array &$errors = array() ) {
		$clean = array();

		if ( ! is_array( $raw ) ) {
			return $clean;
		}

		$row_index = 0;
		foreach ( $raw as $row ) {
			$row_index++;

			if ( ! is_array( $row ) || count( $clean ) >= self::MAX_PRESETS ) {
				continue;
			}

			$name = isset( $row['name'] ) ? trim( sanitize_text_field( (string) $row['name'] ) ) : '';

			// ⚠️ **兩種形狀都要看得懂。**
			//
			// 表單送上來的是 `hours` ＝ `[['start'=>..,'end'=>..], ..]`，而這支清洗
			// 完**存出去**的是 `ranges` ＝ `[[start, end], ..]`。`all()` 每次讀
			// option 都會再清洗一次（照 UAPPT_Shop_Closure 的樣式，防手動改過的
			// option 或舊格式），所以只認表單形狀的話會變成：
			//
			//     存檔當下看起來成功 → 下一次讀取整份班別消失
			//
			// 這種「寫得進去、讀不回來」的壞法不會在存檔那一刻報錯，實測才抓得到
			// （第一版就是這樣，驗證腳本的反查全部回傳空字串）。
			$raw_pairs = array();
			foreach ( array( 'hours', 'ranges' ) as $key ) {
				if ( isset( $row[ $key ] ) && is_array( $row[ $key ] ) ) {
					$raw_pairs = $row[ $key ];
					break;
				}
			}

			// sanitize_ranges() 吃的是 [[start, end], ..]，所以一律正規化成那個形狀
			// ——每個元素可能是 ['start'=>..,'end'=>..]（表單）或 [0=>..,1=>..]（存
			// 出去的）。在這裡轉，不要為了省這幾行去改那支共用方法的介面。
			$pairs = array();
			foreach ( $raw_pairs as $pair ) {
				if ( ! is_array( $pair ) ) {
					continue;
				}
				if ( isset( $pair['start'] ) || isset( $pair['end'] ) ) {
					$pairs[] = array(
						isset( $pair['start'] ) ? $pair['start'] : '',
						isset( $pair['end'] ) ? $pair['end'] : '',
					);
					continue;
				}
				$pairs[] = array(
					isset( $pair[0] ) ? $pair[0] : '',
					isset( $pair[1] ) ? $pair[1] : '',
				);
			}
			$pairs = array_slice( $pairs, 0, self::MAX_RANGES );

			$label = '' !== $name ? $name : sprintf(
				/* translators: %d: 班別在表格裡的列號 */
				__( '第 %d 組班別', 'ultimate-appointments' ),
				$row_index
			);

			$row_errors = array();
			$ranges     = UAPPT_Staff::sanitize_ranges( $pairs, $label, $row_errors );

			// 整列留空就是沒填，不是錯誤——比照公休日的「整列留空，儲存後那一列
			// 會消失」。這是表格永遠多印空白列的前提。
			if ( '' === $name && empty( $ranges ) && empty( $row_errors ) ) {
				continue;
			}

			$errors = array_merge( $errors, $row_errors );

			// ⚠️ 「只有名稱」或「只有時段」都是半個班別，套用不出任何東西。丟掉
			// 之前一定要講得出來，否則管理者會看到自己剛填的那一列**無聲消失**，
			// 而且會以為是存檔壞了。
			if ( '' === $name ) {
				$errors[] = sprintf(
					/* translators: %d: 班別在表格裡的列號 */
					__( '第 %d 組班別填了時段但沒有名稱，那一列沒有存。班別是用名字挑的，一定要取一個。', 'ultimate-appointments' ),
					$row_index
				);
				continue;
			}

			if ( empty( $ranges ) ) {
				if ( empty( $row_errors ) ) {
					$errors[] = sprintf(
						/* translators: %s: 班別名稱 */
						__( '班別「%s」沒有填任何時段，那一列沒有存。', 'ultimate-appointments' ),
						$name
					);
				}
				continue;
			}

			// 顏色：認得的就用；沒選（新的一列）或認不得（v2.99.0 以前的舊班別）就
			// 自動配一個還沒被用掉的——舊班別不用回頭一個一個設定就有顏色。
			$color = isset( $row['color'] ) ? sanitize_key( (string) $row['color'] ) : '';

			$clean[] = array(
				'name'   => $name,
				'ranges' => $ranges,
				'color'  => in_array( $color, self::COLORS, true ) ? $color : '',
			);
		}

		return self::fill_colors( $clean );
	}

	/**
	 * 沒有顏色的班別依序配一個還沒被用掉的顏色；八個都用掉了就從頭輪。
	 *
	 * 在清洗的最後一步做（不是存檔時）：`all()` 每次讀取都會再清洗一次，所以舊資料
	 * 不用遷移，讀出來就有顏色；下一次存檔就把配好的顏色寫進去。
	 *
	 * @param array $presets 清洗過的班別。
	 * @return array
	 */
	protected static function fill_colors( array $presets ) {
		$used = array();
		foreach ( $presets as $preset ) {
			if ( '' !== $preset['color'] ) {
				$used[ $preset['color'] ] = true;
			}
		}

		$i = 0;
		foreach ( $presets as $k => $preset ) {
			if ( '' !== $preset['color'] ) {
				continue;
			}
			$free = array_values( array_diff( self::COLORS, array_keys( $used ) ) );
			$pick = $free ? $free[0] : self::COLORS[ $i % count( self::COLORS ) ];
			$presets[ $k ]['color'] = $pick;
			$used[ $pick ]          = true;
			$i++;
		}

		return $presets;
	}

	/**
	 * 這組時段剛好是哪個班別的顏色；對不上任何班別回空字串。
	 *
	 * 跟 label_for_ranges() 同一條比對規則（排序過的時段鍵）——格子上印的班別名稱與
	 * 塗的顏色永遠是同一個班別。
	 *
	 * @param array $ranges [[開始, 結束], ...]
	 * @return string 色盤的鍵，或空字串。
	 */
	public static function color_for_ranges( $ranges ) {
		$key = self::ranges_key( $ranges );
		if ( '' === $key ) {
			return '';
		}

		foreach ( self::all() as $preset ) {
			if ( self::ranges_key( $preset['ranges'] ) === $key ) {
				return $preset['color'];
			}
		}

		return '';
	}

	/**
	 * 格子要加的 class：有班別顏色時是 `has-shift uappt-shift-c-{鍵}`，否則空字串。
	 *
	 * @param string $color 色盤的鍵。
	 * @return string
	 */
	public static function color_class( $color ) {
		return in_array( $color, self::COLORS, true ) ? 'has-shift uappt-shift-c-' . $color : '';
	}

	/**
	 * 有幾個班別。
	 *
	 * @return int
	 */
	public static function count() {
		return count( self::all() );
	}

	/**
	 * 把一組時段反查成班別名稱（見類別註解的 ⚠️）。
	 *
	 * @param array $ranges [[start, end], ...]。
	 * @return string 完全相符的班別名稱；沒有相符的回傳空字串。
	 */
	public static function label_for_ranges( $ranges ) {
		$key = self::ranges_key( $ranges );
		if ( '' === $key ) {
			return '';
		}

		foreach ( self::all() as $preset ) {
			if ( self::ranges_key( $preset['ranges'] ) === $key ) {
				return $preset['name'];
			}
		}

		return '';
	}

	/**
	 * 時段組合的比對鍵。
	 *
	 * **刻意先排序再組鍵**，所以「09-13 ＋ 14-18」跟「14-18 ＋ 09-13」會反查到同
	 * 一個班別：那兩者描述的是同一天的同一份班，順序只是輸入時的先後。不排序的話
	 * 使用者把兩段填反就突然認不出班別了，而畫面上看不出差別在哪。
	 *
	 * @param mixed $ranges [[start, end], ...]。
	 * @return string 空字串代表「沒有時段」，永遠不會跟任何班別相符。
	 */
	protected static function ranges_key( $ranges ) {
		if ( ! is_array( $ranges ) || empty( $ranges ) ) {
			return '';
		}

		$parts = array();
		foreach ( $ranges as $range ) {
			if ( ! is_array( $range ) || ! isset( $range[0], $range[1] ) ) {
				continue;
			}
			$parts[] = $range[0] . '-' . $range[1];
		}

		if ( empty( $parts ) ) {
			return '';
		}

		sort( $parts );
		return implode( '|', $parts );
	}

	/**
	 * 把時段組合印成人看的字串，例如「09:00–13:00、14:00–18:00」。
	 *
	 * @param mixed $ranges [[start, end], ...]。
	 * @return string
	 */
	public static function format_ranges( $ranges ) {
		if ( ! is_array( $ranges ) ) {
			return '';
		}

		$parts = array();
		foreach ( $ranges as $range ) {
			if ( ! is_array( $range ) || ! isset( $range[0], $range[1] ) ) {
				continue;
			}
			$parts[] = $range[0] . '–' . $range[1];
		}

		return implode( '、', $parts );
	}
}
