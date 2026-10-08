<?php
/**
 * 前台預約精靈的 REST 端點（`/wp-json/uappt/v1/`）。
 *
 * **全部唯讀**。精靈最後送出預約走的是 WooCommerce 原生的加入購物車表單，
 * 不是這裡——所以沒有任何 POST 端點，`UAPPT_Cart::validate_and_hold()` 的守門
 * 邏輯一個字都不用改，也不必在這裡另外做一套防濫用（見
 * docs/booking-wizard-plan.md 的路線 B）。
 *
 * **權限一律開放（`__return_true`）**：這些資料原本就已經透過
 * `wp_ajax_nopriv_uappt_get_slots` / `uappt_get_staff_options` 公開給未登入的
 * 訪客，商品頁本來就要用。這裡沒有多暴露任何東西，只是換一個入口。真正的
 * 銷售政策把關（暫停接單、方案停用、不開放指定人員）在
 * UAPPT_Availability_Query，兩個入口共用同一份。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Rest {

	const NAMESPACE_V1 = 'uappt/v1';

	/**
	 * 掛上路由註冊。
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * 註冊所有路由。
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/staff',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_staff' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'product_id' => array( 'type' => 'integer', 'default' => 0 ),
					'plan_key'   => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/services',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_services' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'staff_id'   => array( 'type' => 'integer', 'default' => 0 ),
					'product_id' => array( 'type' => 'integer', 'default' => 0 ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/days',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_days' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'product_id' => array( 'type' => 'integer', 'required' => true ),
					'plan_key'   => array( 'type' => 'string', 'default' => '' ),
					'staff_id'   => array( 'type' => 'integer', 'default' => 0 ),
					'from'       => array( 'type' => 'string', 'default' => '' ),
					'to'         => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/slots',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_slots' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'product_id' => array( 'type' => 'integer', 'required' => true ),
					'date'       => array( 'type' => 'string', 'required' => true ),
					'plan_key'   => array( 'type' => 'string', 'default' => '' ),
					'staff_id'   => array( 'type' => 'integer', 'default' => 0 ),
				),
			)
		);
	}

	/**
	 * GET /staff
	 *
	 * 帶 product_id：該服務的候選人員（精靈「先選項目」模式的第二步）。
	 * 不帶：所有至少能做一項服務、且該服務開放客人指定人員的人員
	 *       （精靈「先選人」模式的第一步）。
	 *
	 * 為什麼不帶 product_id 時還要看「開放指定人員」：如果所有服務都關掉了
	 * 指定人員，先選人這條路走到第二步會是空的。與其讓客人白選一步，不如
	 * 一開始就回空陣列，讓精靈自己退回「先選項目」模式。
	 *
	 * @param WP_REST_Request $request 請求。
	 * @return WP_REST_Response
	 */
	public static function get_staff( $request ) {
		$product_id = (int) $request->get_param( 'product_id' );
		$plan_key   = sanitize_key( (string) $request->get_param( 'plan_key' ) );

		if ( $product_id ) {
			if ( ! UAPPT_Availability_Query::staff_list_allowed( $product_id ) ) {
				return rest_ensure_response( array( 'staff' => array() ) );
			}
			$list = UAPPT_Availability_Query::staff_options( $product_id, $plan_key );
			return rest_ensure_response( array( 'staff' => self::with_photos( $list ) ) );
		}

		$staff_ids = array();
		foreach ( UAPPT_Service_Index::get() as $staff_id => $entries ) {
			foreach ( $entries as $entry ) {
				if ( UAPPT_Availability_Query::staff_list_allowed( $entry['product_id'] )
					&& null !== UAPPT_Availability_Query::service_payload( $entry['product_id'], $entry['plan_key'] )
				) {
					$staff_ids[] = (int) $staff_id;
					break;
				}
			}
		}

		$rows = UAPPT_Staff::get_many( $staff_ids );
		$rows = array_values(
			array_filter(
				$rows,
				function ( $s ) {
					return 'active' === $s['status'];
				}
			)
		);
		usort(
			$rows,
			function ( $a, $b ) {
				return $a['sort_order'] <=> $b['sort_order'];
			}
		);

		$list = array();
		foreach ( $rows as $s ) {
			$list[] = array(
				'id'               => (int) $s['id'],
				'name'             => $s['name'],
				'price_adjustment' => (float) $s['price_adjustment'],
				'is_last'          => false,
				'photo_url'        => UAPPT_Staff::get_photo_url( $s ),
			);
		}

		return rest_ensure_response( array( 'staff' => $list ) );
	}

	/**
	 * 幫 staff_options() 的結果補上照片網址。
	 *
	 * 刻意不直接加進 UAPPT_Availability_Query::staff_options()：那支同時餵給
	 * 商品頁的 admin-ajax，而商品頁的人員下拉用不到照片，不需要為它多撈一次
	 * 附件網址。
	 *
	 * @param array $list staff_options() 的結果。
	 * @return array
	 */
	protected static function with_photos( $list ) {
		if ( empty( $list ) ) {
			return $list;
		}

		$rows = array();
		foreach ( UAPPT_Staff::get_many( wp_list_pluck( $list, 'id' ) ) as $row ) {
			$rows[ (int) $row['id'] ] = $row;
		}

		foreach ( $list as &$item ) {
			$item['photo_url'] = isset( $rows[ $item['id'] ] )
				? UAPPT_Staff::get_photo_url( $rows[ $item['id'] ] )
				: '';
		}
		unset( $item );

		return $list;
	}

	/**
	 * GET /services
	 *
	 * 帶 staff_id：這位人員能做的服務（精靈「先選人」模式的第二步，吃反查索引）。
	 * 不帶：全部可預約的服務。
	 *
	 * @param WP_REST_Request $request 請求。
	 * @return WP_REST_Response
	 */
	public static function get_services( $request ) {
		$staff_id   = (int) $request->get_param( 'staff_id' );
		$product_id = (int) $request->get_param( 'product_id' );

		$entries = $staff_id
			? UAPPT_Service_Index::get_services_for_staff( $staff_id )
			: self::all_index_entries();

		// 限定單一商品：商品頁用精靈模式時要用到——那一頁只該出現這個商品的
		// 服務方案，但方案有兩個以上時仍然要讓客人選。
		if ( $product_id ) {
			$entries = array_values(
				array_filter(
					$entries,
					function ( $entry ) use ( $product_id ) {
						return (int) $entry['product_id'] === $product_id;
					}
				)
			);
		}

		$services = array();
		$seen     = array();

		foreach ( $entries as $entry ) {
			// 不帶 staff_id 時，同一個服務會被多位人員各列一次，要去重。
			$dedupe_key = $entry['product_id'] . '|' . $entry['plan_key'];
			if ( isset( $seen[ $dedupe_key ] ) ) {
				continue;
			}
			$seen[ $dedupe_key ] = true;

			$payload = UAPPT_Availability_Query::service_payload( $entry['product_id'], $entry['plan_key'] );
			if ( null !== $payload ) {
				$services[] = $payload;
			}
		}

		return rest_ensure_response( array( 'services' => $services ) );
	}

	/**
	 * 反查索引裡的全部對應，攤平成一維。
	 *
	 * @return array
	 */
	protected static function all_index_entries() {
		$all = array();
		foreach ( UAPPT_Service_Index::get() as $entries ) {
			foreach ( $entries as $entry ) {
				$all[] = $entry;
			}
		}
		return $all;
	}

	/**
	 * GET /days
	 *
	 * 日期列用：一次回傳整段區間每天的狀態，不要逐天打 /slots。
	 *
	 * @param WP_REST_Request $request 請求。
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_days( $request ) {
		$product_id = (int) $request->get_param( 'product_id' );
		$plan_key   = sanitize_key( (string) $request->get_param( 'plan_key' ) );
		$staff_id   = UAPPT_Availability_Query::effective_staff_id( $product_id, (int) $request->get_param( 'staff_id' ) );

		$from = sanitize_text_field( (string) $request->get_param( 'from' ) );
		$to   = sanitize_text_field( (string) $request->get_param( 'to' ) );

		if ( '' === $from ) {
			$from = current_time( 'Y-m-d' );
		}
		if ( '' === $to ) {
			$to = uappt_local_date( $from, '+13 days' );
		}

		// 暫停接單／方案停用時，整段區間一律當作沒有位——跟 /slots 回空時段
		// 是同一個政策，不能只擋其中一個入口。
		if ( UAPPT_Availability_Query::is_blocked( $product_id, $plan_key ) ) {
			$days   = array();
			$cursor = $from;
			while ( '' !== $cursor && $cursor <= $to ) {
				$days[ $cursor ] = 'closed';
				$cursor          = uappt_local_date( $cursor, '+1 day' );
			}
			return rest_ensure_response( array( 'days' => $days ) );
		}

		$days = UAPPT_Booking::get_day_availability( $product_id, $from, $to, $plan_key, $staff_id );

		if ( is_wp_error( $days ) ) {
			return new WP_Error( $days->get_error_code(), $days->get_error_message(), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'days' => $days ) );
	}

	/**
	 * GET /slots
	 *
	 * 回應形狀跟商品頁的 admin-ajax 完全一致（同一支 slots_payload()）。
	 *
	 * @param WP_REST_Request $request 請求。
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_slots( $request ) {
		$product_id = (int) $request->get_param( 'product_id' );
		$plan_key   = sanitize_key( (string) $request->get_param( 'plan_key' ) );
		$date_ymd   = sanitize_text_field( (string) $request->get_param( 'date' ) );
		$staff_id   = UAPPT_Availability_Query::effective_staff_id( $product_id, (int) $request->get_param( 'staff_id' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_ymd ) ) {
			return new WP_Error( 'uappt_invalid_date', __( '日期格式錯誤。', 'ultimate-appointments' ), array( 'status' => 400 ) );
		}

		if ( UAPPT_Availability_Query::is_blocked( $product_id, $plan_key ) ) {
			return rest_ensure_response( UAPPT_Availability_Query::blocked_slots_payload() );
		}

		$payload = UAPPT_Availability_Query::slots_payload( $product_id, $date_ymd, $plan_key, $staff_id );

		if ( is_wp_error( $payload ) ) {
			return new WP_Error( $payload->get_error_code(), $payload->get_error_message(), array( 'status' => 400 ) );
		}

		return rest_ensure_response( $payload );
	}
}
