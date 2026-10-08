<?php
/**
 * 收款方式與金流手續費（v2.58.0）。
 *
 * 為什麼需要這個類別：報表原本只知道「收了多少」，不知道「怎麼收的」。
 * 手動建單走 `wc_create_order()` 完全沒有設定付款方式，現場收的現金與刷卡
 * 在資料庫裡長得一模一樣；線上訂單雖然有 gateway，但 WooCommerce 核心
 * **不保存金流手續費**——綠界外掛雖然有 `PaymentTypeChargeFee` 那一欄，
 * 實測這個站 97 筆已付款交易全部是 0，不能當唯一來源。
 *
 * 所以手續費一律用「金額 × 合約費率」自己算。這不是估算：特約商店的費率是
 * 談定的固定值，只要收款方式的顆粒度對得上合約（一般刷卡與分期各自一列），
 * 算出來就是對帳單上的數字。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 收款方式設定與費率計算。
 */
class UAPPT_Payment {

	/**
	 * 收款方式清單的 option key。
	 *
	 * 整間店共用一份，跟時段分類同一個道理（見 UAPPT_Product::SEGMENTS_OPTION）：
	 * 費率是「店跟銀行談的合約」，不是某位人員或某個商品的屬性。
	 */
	const METHODS_OPTION = 'uappt_payment_methods';

	/**
	 * 未指定收款方式的預留 slug。
	 *
	 * 空字串而不是 'unknown'：資料表欄位的預設值就是空字串，舊資料（這個功能
	 * 上線前的所有預約）天然落在這一格，不需要任何 migration。
	 */
	const UNSPECIFIED = '';

	/**
	 * slug 的最大長度，對齊 `bookings.payment_method` 欄位寬度。
	 */
	const SLUG_MAX_LENGTH = 32;

	/**
	 * 預設的四種收款方式。
	 *
	 * ⚠️ **費率一律預設 0，不猜。** 每一家店跟收單行談的費率都不一樣，填一個
	 * 「常見值」進去只會讓沒注意到的人拿著錯的數字去對帳——0 至少是明顯的
	 * 「還沒設定」，報表上也會據實顯示手續費為 0。
	 *
	 * 分期刻意不放進預設：期數組合（3／6／12 期）每家收單行不同，而且費率差距
	 * 很大，列一組假的反而誤導。設定頁的說明會提醒自行新增。
	 *
	 * @return array<int, array{slug:string, label:string, rate:float, gateway:string}>
	 */
	public static function default_methods() {
		return array(
			array(
				'slug'    => 'cash',
				'label'   => __( '現金', 'ultimate-appointments' ),
				'rate'    => 0.0,
				'gateway' => '',
			),
			array(
				'slug'    => 'card',
				'label'   => __( '刷卡', 'ultimate-appointments' ),
				'rate'    => 0.0,
				'gateway' => '',
			),
			array(
				'slug'    => 'transfer',
				'label'   => __( '轉帳', 'ultimate-appointments' ),
				'rate'    => 0.0,
				'gateway' => '',
			),
			array(
				'slug'    => 'mobile',
				'label'   => __( '行動支付', 'ultimate-appointments' ),
				'rate'    => 0.0,
				'gateway' => '',
			),
		);
	}

	/**
	 * 目前生效的收款方式清單（已清洗）。
	 *
	 * @return array<int, array{slug:string, label:string, rate:float, gateway:string}>
	 */
	public static function methods() {
		$stored = get_option( self::METHODS_OPTION, null );

		// 從來沒存過 → 預設四項。存過但被清空（管理者把所有列都刪掉）→ 尊重
		// 那個決定，回傳空陣列，不要「好心」把預設值塞回去：那會讓刪除變成
		// 刪不掉，而且下次存檔又會把它寫死進資料庫。
		if ( ! is_array( $stored ) ) {
			return self::default_methods();
		}

		return self::sanitize_methods( $stored );
	}

	/**
	 * 正規化收款方式清單。
	 *
	 * @param mixed      $raw    來源資料（option 或表單）。
	 * @param array|null $errors by-ref：清洗過程中發現的問題（給設定頁顯示）。
	 * @return array<int, array{slug:string, label:string, rate:float, gateway:string}>
	 */
	public static function sanitize_methods( $raw, array &$errors = null ) {
		if ( null === $errors ) {
			$errors = array();
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();
		$seen  = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$label = isset( $row['label'] ) ? trim( sanitize_text_field( (string) $row['label'] ) ) : '';

			// 沒有名稱的列直接丟掉，而且**不報錯**：設定頁永遠會多渲染一列空白
			// 供新增使用，那一列每次存檔都會走到這裡，報錯會變成每存一次就跳
			// 一個警告。跟 UAPPT_Product 處理方案列的作法一致。
			if ( '' === $label ) {
				continue;
			}

			$slug = isset( $row['slug'] ) ? sanitize_key( (string) $row['slug'] ) : '';
			if ( '' === $slug ) {
				$slug = self::slug_from_label( $label );
			}
			$slug = substr( $slug, 0, self::SLUG_MAX_LENGTH );

			// slug 撞名：後面那一列改用帶序號的 slug，不要直接覆蓋前面那一列。
			// slug 是已經寫進 bookings.payment_method 的歷史資料的鍵，覆蓋會讓
			// 兩種收款方式的歷史紀錄混在一起，而且無法還原。
			$base = $slug;
			$i    = 2;
			while ( isset( $seen[ $slug ] ) ) {
				$suffix = '-' . $i;
				$slug   = substr( $base, 0, self::SLUG_MAX_LENGTH - strlen( $suffix ) ) . $suffix;
				$i++;
			}
			$seen[ $slug ] = true;

			$rate = isset( $row['rate'] ) ? (float) $row['rate'] : 0.0;
			if ( $rate < 0 || $rate > 100 ) {
				$errors[] = sprintf(
					/* translators: %s: 收款方式名稱 */
					__( '「%s」的費率必須介於 0 與 100 之間，已改回 0。', 'ultimate-appointments' ),
					$label
				);
				$rate = 0.0;
			}
			// 費率是百分比（填 2 代表 2%），保留到小數第四位——對齊資料表的
			// DECIMAL(7,4)，超過那個精度存進去也會被截斷，不如在這裡就講清楚。
			$rate = round( $rate, 4 );

			$clean[] = array(
				'slug'    => $slug,
				'label'   => $label,
				'rate'    => $rate,
				'gateway' => isset( $row['gateway'] ) ? sanitize_text_field( (string) $row['gateway'] ) : '',
			);
		}

		return $clean;
	}

	/**
	 * 由名稱產生 slug。
	 *
	 * 中文名稱經過 sanitize_key() 之後會變成空字串（它只留 a-z0-9_-），所以
	 * 退回一個雜湊後綴的固定前綴。難看但穩定，而且管理者看不到 slug。
	 *
	 * @param string $label 名稱。
	 * @return string
	 */
	protected static function slug_from_label( $label ) {
		$slug = sanitize_key( $label );
		if ( '' !== $slug ) {
			return $slug;
		}
		return 'pm-' . substr( md5( $label ), 0, 8 );
	}

	/**
	 * 取得單一收款方式。
	 *
	 * @param string $slug slug。
	 * @return array|null
	 */
	public static function get( $slug ) {
		foreach ( self::methods() as $method ) {
			if ( $method['slug'] === $slug ) {
				return $method;
			}
		}
		return null;
	}

	/**
	 * 顯示用的名稱。
	 *
	 * 找不到對應的設定列時（管理者把那一列刪了，但歷史預約還帶著那個 slug）
	 * **不能回傳空字串**——報表上會出現一整排沒有標籤的列。回傳原始 slug 並
	 * 標注已刪除，至少看得出是哪一種。
	 *
	 * @param string $slug slug。
	 * @return string
	 */
	public static function label( $slug ) {
		if ( self::UNSPECIFIED === $slug ) {
			return __( '未指定', 'ultimate-appointments' );
		}

		$method = self::get( $slug );
		if ( $method ) {
			return $method['label'];
		}

		return sprintf(
			/* translators: %s: 已經從設定中刪除的收款方式代碼 */
			__( '%s（已刪除）', 'ultimate-appointments' ),
			$slug
		);
	}

	/**
	 * 把表單送上來的收款方式 slug 清洗成「確定存在於設定裡」的值。
	 *
	 * 白名單比對而不是只做 sanitize_key()：這個值會被寫進資料表、之後被報表
	 * 拿去分組。放行任意字串等於讓任何人在報表上憑空長出一個分類。
	 *
	 * 認不得的一律退回「未指定」，不要退回清單第一項——把一筆不明的收款
	 * 靜靜算成現金，比誠實標示「未指定」糟糕得多。
	 *
	 * @param mixed $raw 表單值。
	 * @return string
	 */
	public static function sanitize_slug( $raw ) {
		$slug = sanitize_key( (string) $raw );
		if ( '' === $slug ) {
			return self::UNSPECIFIED;
		}

		foreach ( self::methods() as $method ) {
			if ( $method['slug'] === $slug ) {
				return $slug;
			}
		}

		return self::UNSPECIFIED;
	}

	/**
	 * 某個收款方式目前的費率（百分比）。
	 *
	 * ⚠️ 這是**目前設定**的費率，只用在「還沒有快照的舊資料」上。已經有快照的
	 * 預約一律讀 `bookings.fee_rate`，理由見 snapshot_rate()。
	 *
	 * @param string $slug slug。
	 * @return float
	 */
	public static function current_rate( $slug ) {
		$method = self::get( $slug );
		return $method ? (float) $method['rate'] : 0.0;
	}

	/**
	 * 把一筆訂單的收款方式與當下的費率寫進它底下所有預約。
	 *
	 * **為什麼費率要存快照而不是每次重算**：費率很少調整，但正因為少，調整的
	 * 那一天沒有人會記得它會影響什麼。如果報表每次都用「現行費率」重算，調完
	 * 費率之後上個月、去年的報表數字會無聲地跟著變，跟已經印出去、已經拿去
	 * 開會的那一份對不上。WooCommerce 把稅額存在訂單上而不是每次用現行稅率
	 * 重算，也是同一個道理。
	 *
	 * **為什麼只存費率、不存金額**：金額是會改的（後台的「修改金額」功能）。
	 * 存費率、報表端再乘上當下的金額，改金額就會自動流過去；存算好的手續費
	 * 則會在改金額之後變成一個對不上的孤兒數字。
	 *
	 * @param int    $order_id 訂單 ID。
	 * @param string $slug     收款方式 slug。
	 * @return int 更新了幾筆預約。
	 */
	public static function snapshot_rate( $order_id, $slug ) {
		global $wpdb;

		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return 0;
		}

		$slug = self::UNSPECIFIED === $slug ? self::UNSPECIFIED : sanitize_key( $slug );
		$rate = self::current_rate( $slug );

		return (int) $wpdb->update(
			UAPPT_Install::table( 'bookings' ),
			array(
				'payment_method' => $slug,
				'fee_rate'       => $rate,
			),
			array( 'order_id' => $order_id ),
			array( '%s', '%f' ),
			array( '%d' )
		);
	}

	/**
	 * 把收款方式套到一筆預約上——有訂單就連同訂單裡的其他預約一起。
	 *
	 * 為什麼有訂單時要一起改：一張訂單就是一次收款，同一次收款不可能一半刷卡
	 * 一半付現。只改被點進去的那一筆，會讓同一張訂單的兩筆預約在報表上落到
	 * 兩個收款方式，金額對得起來但佔比是錯的。
	 *
	 * @param array  $booking 預約資料（要有 id，order_id 可有可無）。
	 * @param string $slug    收款方式 slug。
	 * @return int 更新了幾筆預約。
	 */
	public static function apply( array $booking, $slug ) {
		$order_id = isset( $booking['order_id'] ) ? (int) $booking['order_id'] : 0;
		if ( $order_id > 0 ) {
			return self::snapshot_rate( $order_id, $slug );
		}

		global $wpdb;

		$booking_id = isset( $booking['id'] ) ? (int) $booking['id'] : 0;
		if ( $booking_id <= 0 ) {
			return 0;
		}

		$slug = self::UNSPECIFIED === $slug ? self::UNSPECIFIED : sanitize_key( $slug );

		return (int) $wpdb->update(
			UAPPT_Install::table( 'bookings' ),
			array(
				'payment_method' => $slug,
				'fee_rate'       => self::current_rate( $slug ),
			),
			array( 'id' => $booking_id ),
			array( '%s', '%f' ),
			array( '%d' )
		);
	}

	/**
	 * 從訂單的金流 gateway 推出我們的收款方式 slug。
	 *
	 * 對應關係存在設定列的 `gateway` 欄位上。對不到就回傳「未指定」——刻意
	 * 不猜：猜錯會讓一筆線上刷卡被算成現金、手續費憑空消失，而「未指定」在
	 * 報表上看得見，管理者會去把它設定好。
	 *
	 * @param WC_Order $order 訂單。
	 * @return string slug。
	 */
	public static function resolve_from_gateway( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return self::UNSPECIFIED;
		}

		$gateway = (string) $order->get_payment_method();
		if ( '' === $gateway ) {
			return self::UNSPECIFIED;
		}

		foreach ( self::methods() as $method ) {
			if ( '' !== $method['gateway'] && $method['gateway'] === $gateway ) {
				return $method['slug'];
			}
		}

		return self::UNSPECIFIED;
	}

	/**
	 * 訂單付款完成時，依 gateway 對應關係補上收款方式。
	 *
	 * ⚠️ **只填「還沒填的」**（`payment_method = ''`）。付款狀態會變動不只一次
	 * （processing → completed 各觸發一次，金流重送通知也會），無條件覆寫會把
	 * 管理者在後台手動改過的收款方式洗掉，而且是在他完全看不到的時候。
	 *
	 * 對不到 gateway 就整批跳過，不要寫入空值——那會白白產生一次沒有意義的
	 * UPDATE，而欄位本來就已經是空的。
	 *
	 * @param int $order_id 訂單 ID。
	 * @return int 更新了幾筆預約。
	 */
	public static function fill_from_gateway( $order_id ) {
		global $wpdb;

		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return 0;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return 0;
		}

		$slug = self::resolve_from_gateway( $order );
		if ( self::UNSPECIFIED === $slug ) {
			return 0;
		}

		$table = UAPPT_Install::table( 'bookings' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET payment_method = %s, fee_rate = %f WHERE order_id = %d AND payment_method = ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$slug,
				self::current_rate( $slug ),
				$order_id
			)
		);
	}

	/**
	 * 目前註冊的 WooCommerce 金流清單（設定頁的下拉選單用）。
	 *
	 * 用 payment_gateways()->payment_gateways() 而不是 get_available_payment_gateways()：
	 * 後者只回傳「這個購物車當下可用」的金流，在後台沒有購物車的情境下會是空的。
	 *
	 * @return array<string, string> gateway id => 標題。
	 */
	public static function available_gateways( $enabled_only = false ) {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return array();
		}

		$out = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			if ( $enabled_only && 'yes' !== $gateway->enabled ) {
				continue;
			}

			$title = $gateway->get_title();
			if ( '' === trim( (string) $title ) ) {
				$title = $gateway->get_method_title();
			}
			$out[ $id ] = '' !== trim( (string) $title ) ? $title : $id;
		}

		return $out;
	}

	/**
	 * 已啟用、但還沒有對應到任何收款方式的 gateway。
	 *
	 * 設定頁拿它顯示提示。這是純讀取，不會自己補列——自動寫入設定是那種
	 * 「使用者沒按儲存、資料卻變了」的行為，很難追。
	 *
	 * ⚠️ **只看已啟用的**。綠界一家就註冊了 13 個 gateway，加上核心的轉帳／
	 * 支票／貨到付款，全部列出來會是 16 個名字的一整段警告——而其中絕大多數
	 * 根本沒開，永遠不會有訂單走那條路。沒開的金流不是「漏設定」，是「用不到」。
	 *
	 * @return array<string, string> gateway id => 標題。
	 */
	public static function unmapped_gateways() {
		$mapped = array();
		foreach ( self::methods() as $method ) {
			if ( '' !== $method['gateway'] ) {
				$mapped[ $method['gateway'] ] = true;
			}
		}

		$out = array();
		foreach ( self::available_gateways( true ) as $id => $title ) {
			if ( ! isset( $mapped[ $id ] ) ) {
				$out[ $id ] = $title;
			}
		}

		return $out;
	}

	/**
	 * 這間店有沒有設定過任何非零費率。
	 *
	 * 報表拿它決定要不要顯示手續費欄位：全部都是 0 的時候多出三欄零，只是
	 * 讓表格更難讀。
	 *
	 * @return bool
	 */
	public static function has_fees() {
		foreach ( self::methods() as $method ) {
			if ( (float) $method['rate'] > 0 ) {
				return true;
			}
		}
		return false;
	}
}
