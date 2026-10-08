<?php
/**
 * 前台可用性查詢的共用邊界。
 *
 * **為什麼要有這一層**：`UAPPT_Booking` 只負責算「誰在什麼時候有空」，而
 * 「暫不接單」「方案已下架」「不開放客人指定人員」這些是**銷售政策**，
 * 照既有的分工（見 UAPPT_Ajax::get_slots() 的註解）屬於邊界的事，不進引擎。
 *
 * 問題是邊界現在不只一個：原本只有商品頁的 admin-ajax，v2.21.0 起前台預約
 * 精靈另外走 REST（見 docs/booking-wizard-plan.md）。同一套政策判斷與回應
 * 組裝如果兩邊各寫一份，遲早會走鐘——而走鐘的後果很具體：精靈列出一個
 * 已下架的方案，客人選完時間按下去才被 create_hold() 擋掉。
 *
 * 所以政策判斷與回應組裝都收在這裡，兩個邊界共用。各自保留的只有「怎麼把
 * 結果送出去」（wp_send_json vs WP_REST_Response）跟身分驗證方式。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Availability_Query {

	/**
	 * 這次查詢要不要套用「只給客人看」的銷售政策。
	 *
	 * 後台（手動建立預約、換人）跟前台共用這些查詢，但客服本來就需要能看到
	 * 完整名單、能幫客人排暫停接單的商品——那是店家自己的決定。所以有
	 * manage_woocommerce 的人一律不受這些政策限制。
	 *
	 * @return bool
	 */
	protected static function applies_customer_policy() {
		return ! current_user_can( 'manage_woocommerce' );
	}

	/**
	 * 把客人送來的 staff_id 換算成「實際要查的 staff_id」。
	 *
	 * 商品設定「不開放客人指定服務人員」時一律當作不指定（0），跟加入購物車
	 * 的把關一致——否則客人繞過前台 UI 直接打 API 就能指定人員。
	 *
	 * @param int $product_id 商品 ID。
	 * @param int $staff_id   客人指定的人員 ID。
	 * @return int
	 */
	public static function effective_staff_id( $product_id, $staff_id ) {
		$staff_id = (int) $staff_id;

		if ( ! $staff_id ) {
			return 0;
		}

		if ( self::applies_customer_policy() && ! UAPPT_Product::staff_choice_enabled( $product_id ) ) {
			return 0;
		}

		return $staff_id;
	}

	/**
	 * 客人能不能看到這個商品／方案的候選人員名單。
	 *
	 * @param int $product_id 商品 ID。
	 * @return bool
	 */
	public static function staff_list_allowed( $product_id ) {
		return ! self::applies_customer_policy() || UAPPT_Product::staff_choice_enabled( $product_id );
	}

	/**
	 * 這個商品／方案目前是不是「前台一律不給時段」。
	 *
	 * 兩種情況，前台的處理方式相同（回傳空時段）：
	 * 1. 商品暫停接受預約
	 * 2. 指定的方案已停用——沒有這一條的話，客人手上的舊分頁仍然查得到已下架
	 *    方案的真實時段，最後才被 create_hold() 擋，變成看得到卻訂不到
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $plan_key   方案鍵。
	 * @return bool
	 */
	public static function is_blocked( $product_id, $plan_key = '' ) {
		if ( ! self::applies_customer_policy() ) {
			return false;
		}

		if ( UAPPT_Product::is_paused( $product_id ) ) {
			return true;
		}

		if ( '' !== $plan_key && UAPPT_Product::is_plan_inactive( $product_id, $plan_key ) ) {
			return true;
		}

		return false;
	}

	/**
	 * 被政策擋下時的時段回應。
	 *
	 * 刻意只有四個鍵、跟 slots_payload() 的六個鍵不一致：這是 v2.21.0 重構前
	 * admin-ajax 就有的形狀，前台 JS 已經照著它寫。要統一形狀是另一件事，
	 * 不要順手在重構裡改掉。
	 *
	 * @return array
	 */
	public static function blocked_slots_payload() {
		return array(
			'slots'          => array(),
			'segments'       => array(),
			'show_remaining' => false,
			'next_available' => null,
		);
	}

	/**
	 * 組出某商品在某日期的完整時段回應。
	 *
	 * 呼叫端要自己先跑過 effective_staff_id() 與 is_blocked()——那兩個是政策，
	 * 這一支只管「算出來並組好」。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $date_ymd   日期 (Y-m-d)。
	 * @param string $plan_key   方案鍵。
	 * @param int    $staff_id   人員 ID；0 為不指定。
	 * @return array|WP_Error
	 */
	public static function slots_payload( $product_id, $date_ymd, $plan_key = '', $staff_id = 0 ) {
		$slots = UAPPT_Booking::get_available_slots( $product_id, $date_ymd, $plan_key, $staff_id );
		if ( is_wp_error( $slots ) ) {
			return $slots;
		}

		$slots = array_values( $slots );

		// 依分類定義的順序，只回傳「這天實際有時段」的分類，count 為 0 的前台不顯示頁籤。
		$segment_counts = array();
		foreach ( $slots as $slot ) {
			$key                    = $slot['segment'];
			$segment_counts[ $key ] = isset( $segment_counts[ $key ] ) ? $segment_counts[ $key ] + 1 : 1;
		}

		$segments = array();
		foreach ( UAPPT_Booking::get_segment_definitions() as $definition ) {
			if ( ! empty( $segment_counts[ $definition['key'] ] ) ) {
				$segments[] = array(
					'key'   => $definition['key'],
					'name'  => $definition['name'],
					'count' => $segment_counts[ $definition['key'] ],
				);
			}
		}

		// 這天額滿（沒有任何時段）時才往後找最近可預約日，平常不用多花這次查詢。
		$next_available = empty( $slots )
			? UAPPT_Booking::find_next_available_date( $product_id, $plan_key, $date_ymd )
			: null;

		$settings       = UAPPT_Product::get_booking_settings( $product_id, $plan_key );
		$show_remaining = self::should_show_remaining( $settings );
		$is_group       = $settings ? (bool) $settings['group_booking'] : false;

		// ⚠️ 「剩 X 位」設成「一律不顯示」時，數字不能只是在前端藏起來。
		// 設定頁對這個選項的說法是「不想讓客人看出人力規模」，但 payload 裡
		// 只要還留著 remaining，打開開發者工具、或直接打 /uappt/v1/slots 就看得到
		// ——那個設定等於沒有作用。要藏就在這裡就不要送出去。
		//
		// max_group_size 是**功能性**的（團體預約要靠它限制人數上限，見
		// frontend.js 的 data-max-group-size），不能跟著一起拿掉；但它本身也是
		// 「單一人員的剩餘名額」，所以非團體預約的商品根本用不到它的時候，
		// 一樣不要送——那種情況下它只是白白洩漏同一個資訊。
		if ( ! $show_remaining || ! $is_group ) {
			foreach ( $slots as $i => $slot ) {
				if ( ! $show_remaining ) {
					unset( $slots[ $i ]['remaining'] );
				}
				if ( ! $is_group ) {
					unset( $slots[ $i ]['max_group_size'] );
				}
			}
		}

		return array(
			'slots'                  => $slots,
			'segments'               => $segments,
			'show_remaining'         => $show_remaining,
			'next_available'         => $next_available,
			'group_booking'          => $is_group,
			'group_max_participants' => $settings ? (int) $settings['group_max_participants'] : 0,
		);
	}

	/**
	 * 「剩 X 位」要不要顯示。
	 *
	 * 預設的 auto 是「總名額 2 位以上才顯示」——名額只有 1 位的話，每個時段都
	 * 掛「剩 1 位」只是雜訊。「總名額」不能只算人數：同時可服務人數（capacity）
	 * 可以讓一位人員頂好幾個名額，例如一位容量 5 的瑜珈老師。人數判斷是容量
	 * 恆為 1 時的特例，用總容量在那種站台上結果相同。
	 *
	 * @param array|false $settings get_booking_settings() 的結果。
	 * @return bool
	 */
	protected static function should_show_remaining( $settings ) {
		$mode = UAPPT_Product::show_remaining_mode();

		if ( 'never' === $mode ) {
			return false;
		}
		if ( 'always' === $mode ) {
			return true;
		}

		$total_capacity = $settings
			? array_sum( wp_list_pluck( UAPPT_Staff::get_many( (array) $settings['staff_ids'] ), 'capacity' ) )
			: 0;

		return $total_capacity > 1;
	}

	/**
	 * 單一「服務」（商品＋方案）給前台顯示用的資料。
	 *
	 * 回傳 null 代表這個服務現在不該出現在客人面前：不可預約、暫停接單、
	 * 或方案已停用。精靈的服務清單直接靠這個 null 過濾，不必自己再判斷一次
	 * 政策——判斷散在兩個地方就是走鐘的開始。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $plan_key   方案鍵。
	 * @return array|null
	 */
	public static function service_payload( $product_id, $plan_key = '' ) {
		if ( self::is_blocked( $product_id, $plan_key ) ) {
			return null;
		}

		$settings = UAPPT_Product::get_booking_settings( $product_id, $plan_key );
		if ( ! $settings ) {
			return null;
		}

		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return null;
		}

		$plan_name = isset( $settings['plan_name'] ) ? (string) $settings['plan_name'] : '';

		return array(
			'product_id'       => (int) $product_id,
			'plan_key'         => (string) $plan_key,
			// 沒有方案的商品就用商品名稱當服務名稱。
			'name'             => '' !== $plan_name ? $plan_name : $product->get_name(),
			'product_name'     => $product->get_name(),
			'duration_minutes' => (int) $settings['duration_minutes'],
			'price'            => (float) $settings['price'],
			// wc_price() 回傳的是含標籤的 HTML，而且貨幣符號是 HTML entity
			// （NT$ 會變成 &#78;&#84;&#36;）。JSON 要給 JS 當純文字用，標籤跟
			// entity 都要拆掉，否則畫面上會直接出現「&#78;&#84;&#36;1,000」。
			'price_html'       => html_entity_decode( wp_strip_all_tags( wc_price( $settings['price'] ) ), ENT_QUOTES, 'UTF-8' ),
			// 精靈要知道這個服務能不能讓客人指定人員，才決定要不要顯示選人那一步。
			'staff_choice'     => self::staff_list_allowed( $product_id ),
		);
	}

	/**
	 * 某商品／方案的候選服務人員清單（已過濾停用、依排序）。
	 *
	 * 呼叫端要自己先跑過 staff_list_allowed()。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $plan_key   方案鍵。
	 * @return array 每個元素：['id','name','price_adjustment','is_last']。
	 */
	public static function staff_options( $product_id, $plan_key = '' ) {
		$settings = UAPPT_Product::get_booking_settings( $product_id, $plan_key );
		if ( ! $settings ) {
			return array();
		}

		$rows   = UAPPT_Staff::get_many( $settings['staff_ids'] );
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

		// 回頭客快速選：候選人員裡是不是有誰曾經為這位客人服務過（最近一筆）。
		$last_staff_id = 0;
		if ( is_user_logged_in() ) {
			$last = UAPPT_Booking::get_last_completed_booking( get_current_user_id() );
			if ( $last && ! empty( $last['staff_id'] ) ) {
				$last_staff_id = (int) $last['staff_id'];
			}
		}

		$list = array();
		foreach ( $active as $s ) {
			$list[] = array(
				'id'               => (int) $s['id'],
				'name'             => $s['name'],
				'price_adjustment' => (float) $s['price_adjustment'],
				'is_last'          => ( (int) $s['id'] === $last_staff_id ),
			);
		}

		return $list;
	}
}
